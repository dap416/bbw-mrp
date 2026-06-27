<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/planning.php");
	require_once(__DIR__."/../../includes/anthropic.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error'=>'denied']); exit; }

	header('Content-Type: application/json');

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
	if ($question === '') { echo json_encode(['error' => 'Please enter a question.']); exit; }

	$SYSTEM_BASE =
"You are the demand-planning assistant for Blue Bird Waterfowl / THE ANIMATOR, a small US manufacturer of waterfowl motion-decoy conversion kits (Animators, which are BUILT from raw materials) plus accessories it resells as finished goods (cases, wings, plates).

You are given a JSON snapshot of the business below. The snapshot covers three upcoming seasons — July–September, October–December, and January–March — and for each it gives prior-year unit sales per SKU. Today's date is in meta.today. Key rules:
- When the user mentions a date, deadline, or period, map it to the season(s) it spans and use those seasons' prior-year sales as the demand baseline (no growth unless told otherwise). If a date is finer than a season, say you're answering at season granularity.
- Animators are BUILT. Each animator gives its SKU, current shopify_in_stock (already made), prior_year_sales per season, and bom (parts + qty_per_unit). needed-to-build = max(0, demand − in_stock). Raw materials list on_hand, on_order, moq (round orders UP to a multiple), lead_time_days (order-by = need_date − lead_time; flag if already past), unit_cost and manufacturer. You can compute how many more of an animator can be built from raw on hand as the min over its BOM of floor(part on_hand / qty_per_unit).
- Shared raw parts (rods, plates, packaging, cards) are used across multiple animators — account for the combined draw.
- Everything that is NOT an animator (cases, wings, plates sold as finished goods) is ORDERED as a finished item, not built: order = max(0, demand − in_stock).
- Be concrete and numeric; use short Markdown. If data is missing (e.g. Shopify not connected, no sales history), say so rather than inventing numbers. Keep follow-up answers focused on what was asked.";

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
			$ctx = build_season_dataset($db);   // three-season prior-year sales + inventory + BOM + raw
			$evts = [];
			try {
				foreach ($db->query("SELECT type, name, event_date, end_date, repeats, details FROM planning_events ORDER BY event_date ASC") as $ev) $evts[] = $ev;
			} catch (Throwable $e) { /* table may not exist */ }
			$ctx['planning_events'] = $evts;
		} catch (Throwable $e) {
			echo json_encode(['error' => 'Could not build planning data: ' . $e->getMessage()]);
			exit;
		}
		$context  = json_encode($ctx, JSON_UNESCAPED_SLASHES);
		$messages = [];
		// Small title from the first question
		$title = trim(preg_replace('/\s+/', ' ', $question));
		if (mb_strlen($title) > 48) $title = mb_substr($title, 0, 47) . '…';

		$stmt = $db->prepare("INSERT INTO research_chats (title, context, messages) VALUES (?, ?, '[]')");
		$stmt->execute([$title, $context]);
		$chatId = (int)$db->lastInsertId();
	}

	$messages[] = ['role' => 'user', 'content' => $question];

	$system = $SYSTEM_BASE . "\n\nBusiness snapshot (JSON):\n" . $context;
	$res = anthropic_chat($system, $messages, 3000);

	if (!empty($res['error'])) {
		// A brand-new chat that failed on its first answer shouldn't linger empty.
		if ($newChat) { $db->exec("DELETE FROM research_chats WHERE id = " . (int)$chatId); }
		echo json_encode(['error' => $res['error']]);
		exit;
	}

	$messages[] = ['role' => 'assistant', 'content' => $res['text']];

	$stmt = $db->prepare("UPDATE research_chats SET messages = ?, updated_at = NOW() WHERE id = ?");
	$stmt->execute([json_encode($messages, JSON_UNESCAPED_SLASHES), $chatId]);

	echo json_encode(['chat_id' => $chatId, 'title' => $title, 'messages' => $messages]);
