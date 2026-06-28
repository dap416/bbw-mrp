<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/planning.php");
	require_once(__DIR__."/../../includes/anthropic.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error'=>'denied']); exit; }

	header('Content-Type: application/json');

	// Detect a request that blew past the server's POST size limit (big upload).
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
		echo json_encode(['error' => 'Upload too large for the server limit. Try a smaller file.']);
		exit;
	}

	if (!anthropic_is_configured()) {
		echo json_encode(['error' => 'No Anthropic API key configured. Add one on the Integrations page.']);
		exit;
	}

	$db = db_connect();
	$db->exec("CREATE TABLE IF NOT EXISTS research_chats (
		id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT 'Chat',
		context LONGTEXT, messages LONGTEXT,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

	$chatId   = (int)($_POST['id'] ?? 0);
	$question = trim($_POST['question'] ?? '');

	// ── Build this turn's user content (text + attachments) ───────────────────
	$content = [];
	if ($question !== '') $content[] = ['type' => 'text', 'text' => $question];

	$allowed  = ['application/pdf' => 'document', 'image/png' => 'image', 'image/jpeg' => 'image', 'image/gif' => 'image', 'image/webp' => 'image'];
	$maxBytes = 10 * 1024 * 1024;
	$displayFiles = [];

	if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
		$f = $_FILES['files'];
		$n = count($f['name']);
		for ($i = 0; $i < $n && $i < 4; $i++) {
			if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) continue;
			$name = $f['name'][$i]; $tmp = $f['tmp_name'][$i]; $size = (int)$f['size'][$i];
			if ($size > $maxBytes) { echo json_encode(['error' => 'File "'.$name.'" is too large (max 10MB).']); exit; }
			$mime = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: $f['type'][$i]) : $f['type'][$i];
			if (!isset($allowed[$mime])) { echo json_encode(['error' => 'Unsupported file "'.$name.'". Use PDF or an image (PNG/JPG/GIF/WebP).']); exit; }
			$data = base64_encode(file_get_contents($tmp));
			$kind = $allowed[$mime];
			$content[] = ($kind === 'document')
				? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]]
				: ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];
			$displayFiles[] = ['name' => $name, 'kind' => $kind];
		}
	}

	if (empty($content)) { echo json_encode(['error' => 'Please enter a question or attach a file.']); exit; }

	$SYSTEM_BASE =
"You are the demand-planning assistant for Blue Bird Waterfowl / THE ANIMATOR, a small US manufacturer of waterfowl motion-decoy conversion kits (Animators, BUILT from raw materials) plus accessories it resells as finished goods (cases, wings, plates).

You are given a JSON snapshot of the business below, covering three upcoming seasons (Jul–Sep, Oct–Dec, Jan–Mar) with prior-year unit sales per SKU. Today's date is meta.today. The user may attach files (e.g. a purchase-order PDF or a screenshot) — read them and use their contents.

Key rules:
- When the user mentions a date/deadline, map it to the season(s) it spans (no growth baseline unless told otherwise).
- Animators are BUILT: needed-to-build = max(0, demand − in_stock). Raw materials have moq (round orders UP) and lead_time_days (order-by = need_date − lead_time; flag if past).
- Non-animators (cases, wings) are ORDERED as finished items: order = max(0, demand − in_stock).
- If you read an attached PO/file, EXTRACT the key details (parts/SKUs, quantities, dates, totals) into your answer so they're captured for the rest of the conversation — later follow-ups may not include the file again.
- Be concrete and numeric; short Markdown. If data is missing, say so.";

	$newChat = false;
	if ($chatId > 0) {
		$row = $db->query("SELECT * FROM research_chats WHERE id = " . (int)$chatId)->fetch();
		if (!$row) { echo json_encode(['error' => 'Chat not found.']); exit; }
		$context  = (string)$row['context'];
		$messages = json_decode($row['messages'] ?: '[]', true) ?: [];
		$title    = $row['title'];
	} else {
		$newChat = true;
		try {
			$ctx = build_season_dataset($db);
			$evts = [];
			try { foreach ($db->query("SELECT type, name, event_date, end_date, repeats, details FROM planning_events ORDER BY event_date ASC") as $ev) $evts[] = $ev; }
			catch (Throwable $e) {}
			$ctx['planning_events'] = $evts;
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Could not build planning data: ' . $e->getMessage()]);
			exit;
		}
		$context  = json_encode($ctx, JSON_UNESCAPED_SLASHES);
		$messages = [];
		$title = trim(preg_replace('/\s+/', ' ', $question));
		if ($title === '' && !empty($displayFiles)) $title = $displayFiles[0]['name'];
		if ($title === '') $title = 'Chat';
		if (mb_strlen($title) > 48) $title = mb_substr($title, 0, 47) . '…';
		$stmt = $db->prepare("INSERT INTO research_chats (title, context, messages) VALUES (?, ?, '[]')");
		$stmt->execute([$title, $context]);
		$chatId = (int)$db->lastInsertId();
	}

	// Append this turn (full content, with any file blocks + display metadata).
	$userMsg = (count($content) === 1 && ($content[0]['type'] ?? '') === 'text')
		? ['role' => 'user', 'content' => $question]
		: ['role' => 'user', 'content' => $content, '_files' => $displayFiles];
	$messages[] = $userMsg;

	// Build the API messages: send file blocks ONLY in the latest turn; older
	// turns collapse to their text + a placeholder so we don't re-bill the PDF.
	$lastIdx = count($messages) - 1;
	$apiMessages = [];
	foreach ($messages as $idx => $m) {
		$c = $m['content'];
		if (is_array($c) && $idx !== $lastIdx) {
			$text = '';
			foreach ($c as $b) { if (($b['type'] ?? '') === 'text') $text .= $b['text']; }
			$names = [];
			foreach (($m['_files'] ?? []) as $df) $names[] = '[attached: ' . $df['name'] . ']';
			$c = trim($text . ' ' . implode(' ', $names));
		}
		$apiMessages[] = ['role' => $m['role'], 'content' => $c];
	}

	$system = $SYSTEM_BASE . "\n\nBusiness snapshot (JSON):\n" . $context;
	$res = anthropic_chat($system, $apiMessages, 3000);

	if (!empty($res['error'])) {
		if ($newChat) { $db->exec("DELETE FROM research_chats WHERE id = " . (int)$chatId); }
		echo json_encode(['error' => $res['error']]);
		exit;
	}

	$messages[] = ['role' => 'assistant', 'content' => $res['text']];

	$db->prepare("UPDATE research_chats SET messages = ?, updated_at = NOW() WHERE id = ?")
	   ->execute([json_encode($messages, JSON_UNESCAPED_SLASHES), $chatId]);

	echo json_encode(['chat_id' => $chatId, 'title' => $title, 'messages' => chat_display_messages($messages)]);
