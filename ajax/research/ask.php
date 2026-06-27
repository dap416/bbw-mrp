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
- Demand baseline: use last-year sales over the same window unless told otherwise (no growth). Add demand from upcoming planning events (large POs and tradeshows) before the target date; note their dates/products. Tradeshow/POS spikes are extraordinary — call them out separately.
- Finished product: needed-to-build = max(0, projected demand - current Shopify in-stock).
- Animators specifically: each animator product gives shopify_in_stock (how many are already made), buildable_now_from_raw (how many MORE you can build from raw materials on hand right now), and limiting_part (the part that runs out first). So for an animator: you can ship up to in_stock + buildable_now_from_raw without ordering anything; if demand exceeds that, you must order raw materials. When asked 'how many of each animator to build', give a per-product line: have X made, can build Y more from stock, so build Z (= demand - in_stock, capped by raw unless you order more), and name the limiting part.
- Raw materials: explode each animator's needed-to-build through its BOM, sum per part, then to-order = max(0, requirement - on_hand - on_order), ROUNDED UP to a whole multiple of that part's MOQ.
- Order-BY date: parts have lead_time_days (order-to-delivery). The latest you can order and still have a part in time = need_date minus lead_time_days. Give an explicit 'order by <date>' for any part you're short on, and flag if that date is already in the past (too late / order immediately).
- Only animators have raw materials; everything else (cases, wings, etc.) is ordered as a finished item (order qty = demand - in_stock).
- Be concrete and numeric. Give a clear per-animator build list, then a raw-material order list (part, qty rounded to MOQ, order-by date, est cost = qty x unit_cost) and a total. State assumptions briefly. If data is missing (Shopify not connected, no sales history, no BOM), say so rather than inventing numbers.

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
