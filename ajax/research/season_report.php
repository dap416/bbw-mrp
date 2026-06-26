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

	try {
		$db = db_connect();
		$data = build_season_dataset($db);
	} catch (Throwable $e) {
		echo json_encode(['error' => 'Could not build data: ' . $e->getMessage()]);
		exit;
	}

	$system =
"You are the demand-planning analyst for Blue Bird Waterfowl / THE ANIMATOR, a small US manufacturer of waterfowl motion-decoy conversion kits (Animators) plus accessories it resells (protective cases, replacement wings, splash plates, etc.).

You produce a SEASON READINESS REPORT with EXACTLY THREE sections, in this order:
1. July–September (pre-season ramp; includes summer tradeshows)
2. October–December (Q4 — the main duck-season rush; be most rigorous here)
3. January–March (slow season as duck season closes)

Baseline demand for each section = the prior-year sales for that same calendar window (provided per SKU). Apply NO growth percentage. The data JSON gives, per season: each animator product's prior-year unit sales, current Shopify stock, and bill-of-materials; the shared raw materials (on-hand, on-order, MOQ, lead time in days, unit cost, manufacturer); non-animator finished goods (cases, wings, plates) with prior-year sales and current stock; and last year's July–August point-of-sale (tradeshow) spike days.

Rules:
- ONLY animator products have raw materials. For animators: units_to_build for a season = max(0, prior-year demand − projected on-hand finished stock entering that season). Explode units_to_build through each BOM, sum per part across all animators, and keep a RUNNING raw-material inventory across the three seasons in order (start = on_hand + on_order). When a part would run short before a season, recommend a purchase order = the shortfall ROUNDED UP to a whole multiple of that part's MOQ. Respect lead time: if lead_time_days means an order placed today won't arrive before that season starts, say so explicitly (order now / already tight). Group raw-material POs BY MANUFACTURER.
- Everything that is NOT an animator (cases, wings, etc.) has no raw materials — recommend ordering the FINISHED item directly: order qty for a season = max(0, prior-year sales − stock on hand entering the season). Deplete stock across seasons in order. Give an explicit list ('order N x <product/sku>').
- For July–September, factor in the tradeshow POS spikes: call out the spike dates and roughly how much extra POS demand to expect, and make sure stock is staged before them.
- Each section MUST include: a one-line readiness verdict (Ready / Tight / Short), the key numbers, Suggested Actions (bullets), and Suggested Purchase Orders (a Markdown table). End the whole report with a 'Order Now' summary: the POs that must be placed today because of lead time, with estimated total cost.
- Be concrete and numeric. If Shopify isn't connected or a product has no prior-year sales, say so rather than inventing numbers. Use clear Markdown headings and tables.";

	$userText =
		"Produce the three-section Season Readiness Report.\n\n" .
		"Business data (JSON):\n" .
		json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

	$res = anthropic_message($system, $userText, 8000);
	if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }

	// Chart series: prior-year units per season, split animators vs finished goods.
	$labels = []; $anim = []; $fg = [];
	foreach ($data['seasons'] as $s) {
		$labels[] = $s['label'];
		$a = 0; foreach ($data['animators'] as $p) $a += (int)($p['prior_year_sales'][$s['key']] ?? 0);
		$g = 0; foreach ($data['finished_goods'] as $p) $g += (int)($p['prior_year_sales'][$s['key']] ?? 0);
		$anim[] = $a; $fg[] = $g;
	}

	echo json_encode([
		'report'  => $res['text'],
		'charts'  => ['labels' => $labels, 'animators' => $anim, 'finished_goods' => $fg],
		'tradeshow' => $data['tradeshow_prior_year_jul_aug'],
	]);
