<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/planning.php");
	require_once(__DIR__."/../../includes/anthropic.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Access denied'; exit; }

	header('Content-Type: application/json');

	if (!anthropic_is_configured()) {
		echo json_encode(['error' => 'No Anthropic API key configured. Add one on the Integrations page.']);
		exit;
	}

	$question   = trim($_POST['question'] ?? '');
	$targetDate = trim($_POST['target_date'] ?? '');
	if ($question === '') { echo json_encode(['error' => 'Please enter a question.']); exit; }
	if ($targetDate === '') $targetDate = date('Y-m-d', strtotime('+90 days'));

	try {
		$db  = db_connect();
		$ctx = build_planning_context($db, $targetDate);
	} catch (Throwable $e) {
		echo json_encode(['error' => 'Could not build planning data: ' . $e->getMessage()]);
		exit;
	}

	$system =
"You are the demand-planning assistant for Blue Bird Waterfowl / THE ANIMATOR, a small US manufacturer of waterfowl motion-decoy conversion kits and related parts. You help the owner decide how much raw material and finished product to order to meet demand through a target date.

You are given a JSON snapshot of the business: finished products (with their Shopify in-stock counts, annual goals, last-year sales over the planning window, and bill-of-materials), raw materials (on-hand, base stock level, MOQ, lead time in days, on-order, unit cost, supplier), sales by channel, and known planning events (large purchase orders and tradeshows, some of which repeat yearly).

How to reason:
- Demand baseline: use last-year sales over the same window unless told otherwise. Add demand from upcoming planning events (large POs and tradeshows) that fall before the target date; note their dates and the products involved. Tradeshow/POS spikes are extraordinary — call them out separately.
- Finished product: needed-to-build = max(0, projected demand - current Shopify in-stock). Honor annual goals if the question references them.
- Raw materials: explode each product's needed-to-build through its BOM, sum per part across all products, then compute to-order = max(0, requirement - on_hand - on_order). ALWAYS round each order up to a whole multiple of that part's MOQ. Respect lead time: if the part's lead_time_days means it won't arrive before it's needed, flag that ordering must happen now (or that it's already too late).
- Be concrete and numeric. Show a short parts order list (part, qty to order rounded to MOQ, est cost = qty x unit_cost) and a total. State your assumptions briefly. If data is missing (e.g. Shopify not connected, no sales history, no BOM), say so rather than inventing numbers.

Format the answer in clear Markdown with short sections and a table for the order list. Keep it focused and practical for a busy owner.";

	$userText =
		"Question: " . $question . "\n\n" .
		"Planning target date: " . $targetDate . "\n\n" .
		"Business snapshot (JSON):\n" .
		json_encode($ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	$res = anthropic_message($system, $userText, 4096);
	if (!empty($res['error'])) {
		echo json_encode(['error' => $res['error']]);
		exit;
	}

	echo json_encode(['answer' => $res['text']]);
