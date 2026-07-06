<?php
/**
 * Turn Charles's approved plan into TASKS assigned to George. No money moves and no
 * books change here — the financial actions ride along in task_meta and are applied
 * only when George marks the task complete (see ajax/tasks/toggle.php hook). OWNER ONLY.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');

if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Charles is private to the owner.']); exit; }

$db = db_connect();
tasks_ensure_table($db);

$tasks = json_decode($_POST['tasks'] ?? '[]', true);
if (!is_array($tasks) || empty($tasks)) { echo json_encode(['error' => 'Nothing to add.']); exit; }

$uid  = (int)($_SESSION['user_id'] ?? 0) ?: null;
$name = $_SESSION['user_name'] ?? '';
$created = 0; $titles = [];

try {
	$stmt = $db->prepare("INSERT INTO tasks (title, notes, due_date, assigned_to, assigned_to_name, created_by, created_by_name, task_type, task_meta)
	                      VALUES (?,?,?,?,?,?,?, 'charles', ?)");
	foreach ($tasks as $t) {
		$title = trim((string)($t['title'] ?? ''));
		if ($title === '') continue;
		$why   = trim((string)($t['why'] ?? ''));
		$due   = (isset($t['due']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$t['due'])) ? $t['due'] : null;
		$acts  = (isset($t['actions']) && is_array($t['actions'])) ? $t['actions'] : [];
		$meta  = json_encode(['why' => $why, 'actions' => $acts]);
		$stmt->execute([$title, $why, $due, $uid, $name, $uid, $name, $meta]);
		$created++; $titles[] = $title;
	}
	if ($created) { briefing_touch($db); charles_memory_append($db, 'assigned', 'Tasks assigned to George: ' . implode('; ', $titles)); }
} catch (Throwable $e) {
	http_response_code(500); echo json_encode(['error' => 'Could not create tasks: ' . $e->getMessage()]); exit;
}

echo json_encode(['ok' => true, 'created' => $created]);
