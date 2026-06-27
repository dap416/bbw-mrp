<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/planning.php");
	require_once(__DIR__."/../../includes/anthropic.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error'=>'denied']); exit; }

	header('Content-Type: application/json');

	$wantAI = !empty($_POST['ai']);     // include the written AI action plan
	$fresh  = !empty($_POST['fresh']);  // force a recompute, ignore cache

	// Bump when the payload shape changes so old caches auto-invalidate.
	$SEASON_SCHEMA = 5;

	$db = db_connect();

	// Serve a recently-cached deterministic result instantly (auto-load on open).
	if (!$wantAI && !$fresh) {
		try {
			$cached = setting_get($db, 'season_cache');
			$at     = (int)setting_get($db, 'season_cache_at', 0);
			if ($cached && (time() - $at) < 3 * 3600) {
				$dec = json_decode($cached, true);
				if (is_array($dec) && (int)($dec['schema'] ?? 0) === $SEASON_SCHEMA) { echo $cached; exit; }
			}
		} catch (Throwable $e) { /* no cache */ }
	}

	try {
		$data = build_season_dataset($db);
	} catch (Throwable $e) {
		echo json_encode(['error' => 'Could not build data: ' . $e->getMessage()]);
		exit;
	}

	if (empty($data['meta']['shopify_connected'])) {
		echo json_encode(['error' => 'Connect Shopify first (Integrations page) so prior-year sales can be read.']);
		exit;
	}
	if (!empty($data['meta']['shopify_error'])) {
		echo json_encode(['error' => 'Shopify error: ' . $data['meta']['shopify_error']]);
		exit;
	}

	$seasons   = $data['seasons'];
	$animators = $data['animators'];
	$fgoods    = $data['finished_goods'];
	$raws      = $data['raw_materials'];

	// ── Deterministic, time-phased readiness ──────────────────────────────────
	// FP stock is consumed quarter by quarter; the remaining demand each quarter
	// is what must be built (animators) or re-ordered (finished goods). Build
	// needs are exploded through BOMs into a single raw-material order list.
	$summary  = [];   // per season key
	$rawNeed  = [];   // partno => total units of that part needed across the year
	foreach ($seasons as $s) {
		$summary[$s['key']] = [
			'key' => $s['key'], 'label' => $s['label'], 'prior_window' => $s['prior_window'] ?? '',
			'animator_demand' => 0, 'animator_in_stock' => 0, 'animator_to_build' => 0,
			'fg_demand' => 0, 'fg_in_stock' => 0, 'fg_to_order' => 0, 'fg_items' => [],
		];
	}

	foreach ($animators as $a) {
		$stock = max(0, (int)($a['in_stock'] ?? 0));
		foreach ($seasons as $s) {
			$d   = (int)($a['prior_year_sales'][$s['key']] ?? 0);
			$summary[$s['key']]['animator_demand']   += $d;
			$summary[$s['key']]['animator_in_stock'] += $stock; // entering stock snapshot
			$ship  = min($stock, $d); $stock -= $ship;
			$build = $d - $ship;
			$summary[$s['key']]['animator_to_build'] += $build;
			if ($build > 0) {
				foreach (($a['bom'] ?? []) as $b) {
					$rawNeed[$b['part']] = ($rawNeed[$b['part']] ?? 0) + $build * (int)$b['qty_per_unit'];
				}
			}
		}
	}

	foreach ($fgoods as $g) {
		$stock = max(0, (int)($g['in_stock'] ?? 0));
		foreach ($seasons as $s) {
			$d = (int)($g['prior_year_sales'][$s['key']] ?? 0);
			$summary[$s['key']]['fg_demand']   += $d;
			$summary[$s['key']]['fg_in_stock'] += $stock;
			$entering = $stock;
			$ship  = min($stock, $d); $stock -= $ship;
			$order = $d - $ship;
			$summary[$s['key']]['fg_to_order'] += $order;
			if ($order > 0) {
				$summary[$s['key']]['fg_items'][] = [
					'sku' => $g['sku'], 'product' => $g['product'], 'order' => $order,
					'have' => $entering, 'need' => $d,   // Shopify stock vs prior-year demand
				];
			}
		}
	}

	// Verdict per season
	foreach ($summary as $k => &$row) {
		usort($row['fg_items'], fn($x, $y) => $y['order'] <=> $x['order']);
		$row['fg_items'] = array_slice($row['fg_items'], 0, 10);
		$demand = $row['animator_demand'] + $row['fg_demand'];
		$short  = $row['animator_to_build'] + $row['fg_to_order'];
		$cov = $demand > 0 ? ($demand - $short) / $demand : 1;
		$row['status'] = $cov >= 0.99 ? 'ready' : ($cov >= 0.75 ? 'tight' : 'short');
	}
	unset($row);

	// ── Per-animator build plan by season (with shared-part accounting) ───────
	// For each season: how many of each animator to build, and a per-SKU
	// drill-down showing the finished product and every raw part as
	// need / have / committed-by-other-builds-this-season / remaining-after.
	$partInfo = [];
	foreach ($raws as $rm) $partInfo[$rm['part']] = ['desc' => $rm['description'], 'qoh' => (int)$rm['on_hand']];

	// Per-animator time-phased build + entering finished stock
	$animBuild = [];
	foreach ($animators as $i => $a) {
		$stock = max(0, (int)($a['in_stock'] ?? 0));
		foreach ($seasons as $s) {
			$d = (int)($a['prior_year_sales'][$s['key']] ?? 0);
			$ship = min($stock, $d); $entering = $stock; $stock -= $ship;
			$animBuild[$i][$s['key']] = ['build' => $d - $ship, 'entering' => $entering, 'demand' => $d];
		}
	}

	// Total raw units each season's full build commits, per part
	$seasonCommit = [];
	foreach ($seasons as $s) {
		foreach ($animators as $i => $a) {
			$b = $animBuild[$i][$s['key']]['build'];
			if ($b <= 0) continue;
			foreach (($a['bom'] ?? []) as $bl) {
				$seasonCommit[$s['key']][$bl['part']] = ($seasonCommit[$s['key']][$bl['part']] ?? 0) + $b * (int)$bl['qty_per_unit'];
			}
		}
	}

	// Leaner payload — the browser recomputes need/committed/remaining live as the
	// user edits build quantities. Send the ingredients: per-animator demand,
	// entering stock, suggested build, BOM (part + qty/unit); plus a global part
	// map (description + current on-hand).
	$partsOut = [];
	foreach ($raws as $rm) $partsOut[$rm['part']] = ['desc' => $rm['description'], 'have' => (int)$rm['on_hand']];

	$buildPlan = [];
	foreach ($seasons as $s) {
		$list = [];
		foreach ($animators as $i => $a) {
			$info = $animBuild[$i][$s['key']];
			if ($info['demand'] <= 0 && $info['build'] <= 0) continue;
			$bom = [];
			foreach (($a['bom'] ?? []) as $bl) $bom[] = ['part' => $bl['part'], 'qty_per_unit' => (int)$bl['qty_per_unit']];
			$list[] = [
				'sku' => $a['sku'] ?: '(no SKU)', 'product' => $a['product'],
				'demand' => $info['demand'], 'entering' => $info['entering'],
				'suggested_build' => $info['build'], 'bom' => $bom,
			];
		}
		usort($list, fn($x, $y) => $y['suggested_build'] <=> $x['suggested_build']);
		$buildPlan[] = ['key' => $s['key'], 'label' => $s['label'], 'animators' => $list];
	}

	// ── Raw-material order list (whole horizon), rounded to MOQ ───────────────
	$rawOrders = []; $rawTotalCost = 0.0;
	foreach ($raws as $rm) {
		$need  = (int)($rawNeed[$rm['part']] ?? 0);
		$avail = (int)$rm['on_hand'] + (int)$rm['on_order'];
		$short = max(0, $need - $avail);
		if ($short <= 0) continue;
		$moq   = max(1, (int)$rm['moq']);
		$order = (int)(ceil($short / $moq) * $moq);
		$cost  = $order * (float)$rm['unit_cost'];
		$rawTotalCost += $cost;
		$rawOrders[] = [
			'part' => $rm['part'], 'description' => $rm['description'], 'manufacturer' => $rm['manufacturer'],
			'need' => $need, 'on_hand' => (int)$rm['on_hand'], 'on_order' => (int)$rm['on_order'],
			'short' => $short, 'order_qty' => $order, 'lead_time_days' => (int)$rm['lead_time_days'],
			'unit_cost' => (float)$rm['unit_cost'], 'cost' => $cost,
		];
	}
	usort($rawOrders, fn($a, $b) => strcasecmp($a['manufacturer'], $b['manufacturer']) ?: ($b['cost'] <=> $a['cost']));

	// Chart series: prior-year units per season (animators vs other)
	$labels = []; $anim = []; $fg = []; $totalDemand = 0;
	foreach ($seasons as $s) {
		$labels[] = $s['label'];
		$anim[] = $summary[$s['key']]['animator_demand'];
		$fg[]   = $summary[$s['key']]['fg_demand'];
		$totalDemand += $summary[$s['key']]['animator_demand'] + $summary[$s['key']]['fg_demand'];
	}

	// If prior-year demand is entirely empty, the app almost certainly lacks the
	// read_all_orders scope (Shopify only returns the last 60 days otherwise).
	$dataWarning = null;
	if ($totalDemand === 0) {
		$dataWarning = 'Prior-year sales came back empty. Shopify only returns the last 60 days of orders unless your app has the "read_all_orders" scope. Add read_all_orders to the app (alongside read_orders), reinstall it, then click Save on the Shopify card in Integrations and Refresh here.';
	}

	// ── Optional AI narrative (detailed actions / lead-time timing) ────────────
	$report = null; $reportNote = null;
	if ($wantAI && anthropic_is_configured()) {
		$system =
"You are the demand-planning analyst for Blue Bird Waterfowl / THE ANIMATOR. You are given a JSON snapshot plus a pre-computed, time-phased readiness summary for three seasons (Jul–Sep, Oct–Dec, Jan–Mar) compared to prior-year sales with no growth. The deterministic numbers (units to build, units to order, raw-material order quantities already rounded to MOQ) are authoritative — do NOT recompute or contradict them. Your job is the JUDGMENT layer: for each season give a short readiness narrative and concrete Suggested Actions, focusing on LEAD TIME — which raw-material POs must be placed NOW so parts arrive before they're needed, grouped by manufacturer. Then list the finished goods (cases, wings, etc.) to order. Call out the Jul–Aug tradeshow POS spikes. End with an 'Order Now' list (the lead-time-critical POs) and the estimated total. Be concise, concrete, Markdown with small tables.";

		$userText = "Readiness summary + raw orders (authoritative):\n" .
			json_encode(['summary' => array_values($summary), 'raw_orders' => $rawOrders, 'raw_total_cost' => $rawTotalCost,
			             'tradeshow' => $data['tradeshow_prior_year_jul_aug']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) .
			"\n\nFull data snapshot:\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		$res = anthropic_message($system, $userText, 6000);
		if (!empty($res['error'])) $reportNote = $res['error'];
		else $report = $res['text'];
	} elseif ($wantAI) {
		$reportNote = 'No Anthropic API key configured. Add one on the Integrations page for the detailed action plan.';
	}

	$payload = json_encode([
		'summary'        => array_values($summary),
		'build_plan'     => $buildPlan,
		'parts'          => $partsOut,
		'raw_orders'     => $rawOrders,
		'raw_total_cost' => $rawTotalCost,
		'charts'         => ['labels' => $labels, 'animators' => $anim, 'finished_goods' => $fg],
		'tradeshow'      => $data['tradeshow_prior_year_jul_aug'],
		'report'         => $report,
		'report_note'    => $reportNote,
		'data_warning'   => $dataWarning,
		'schema'         => $SEASON_SCHEMA,
		'computed_at'    => date('M j, Y g:i A'),
	]);

	// Cache the deterministic (non-AI) result so re-opening the page is instant.
	if (!$wantAI) {
		try {
			setting_set($db, 'season_cache', $payload);
			setting_set($db, 'season_cache_at', (string)time());
		} catch (Throwable $e) { /* best effort */ }
	}

	echo $payload;
