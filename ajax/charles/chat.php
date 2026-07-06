<?php
/**
 * Talk to Charles — the conversational AI CPA. Full financial+MRP snapshot + durable
 * memory in the (cached) system prompt; returns his reply plus any proposed task plan
 * (a fenced ```json {"tasks":[...]}``` block). OWNER ONLY.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');

if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Charles is private to the owner.']); exit; }
if (!anthropic_is_configured()) { echo json_encode(['error' => 'AI is not configured — add an Anthropic key on Integrations.']); exit; }

$db = db_connect();
charles_ensure_tables($db);

$msgsIn = json_decode($_POST['messages'] ?? '[]', true);
if (!is_array($msgsIn) || empty($msgsIn)) { echo json_encode(['error' => 'No message.']); exit; }
$clean = [];
foreach ($msgsIn as $m) {
	$role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
	$content = trim((string)($m['content'] ?? ''));
	if ($content === '') continue;
	if (!empty($clean) && $clean[count($clean) - 1]['role'] === $role) { $clean[count($clean) - 1]['content'] .= "\n\n" . $content; continue; }
	$clean[] = ['role' => $role, 'content' => $content];
}
if (empty($clean) || $clean[0]['role'] !== 'user') { echo json_encode(['error' => 'Bad message sequence.']); exit; }

$snap   = charles_snapshot($db);
$system = charles_system_prompt($db, $snap);

$res = anthropic_chat($system, $clean, 3500);
if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }

$text  = trim((string)$res['text']);
$tasks = [];
if (preg_match('/```json\s*(\{.*?\})\s*```/s', $text, $mm)) {
	$j = json_decode($mm[1], true);
	if (is_array($j) && !empty($j['tasks']) && is_array($j['tasks'])) $tasks = $j['tasks'];
	$text = trim(preg_replace('/```json\s*\{.*?\}\s*```/s', '', $text)); // hide the raw JSON from the reply
}

// Persist the conversation (resumable history).
$chatId = (int)($_POST['chat_id'] ?? 0);
$full   = array_merge($clean, [['role' => 'assistant', 'content' => $text]]);
$title  = substr(trim($clean[0]['content']), 0, 60);
try {
	if ($chatId > 0) {
		$db->prepare("UPDATE charles_chats SET messages = ?, updated_at = NOW() WHERE id = ?")->execute([json_encode($full), $chatId]);
	} else {
		$db->prepare("INSERT INTO charles_chats (title, messages) VALUES (?, ?)")->execute([$title, json_encode($full)]);
		$chatId = (int)$db->lastInsertId();
	}
} catch (Throwable $e) {}

// Remember any plan Charles proposed (durable memory).
if ($tasks) {
	$titles = array_map(fn($t) => (string)($t['title'] ?? ''), $tasks);
	charles_memory_append($db, 'proposed', 'Charles proposed: ' . implode('; ', array_filter($titles)));
}

echo json_encode(['reply' => $text, 'tasks' => $tasks, 'chat_id' => $chatId, 'title' => $title]);
