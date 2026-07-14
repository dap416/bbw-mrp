<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/planning.php");
	require_once(__DIR__."/../../includes/anthropic.php");
	require_login();

	if (!has_access('research')) { http_response_code(403); echo json_encode(['error'=>'You do not have access to Research.']); exit; }

	header('Content-Type: application/json');

	/** Group a raw-material part number into a shared-parts category. */
	function shared_category($partno) {
		$p = strtoupper(trim((string)$partno));
		if (strpos($p, 'CS')    === 0) return 'Camshafts';
		if (strpos($p, 'RD')    === 0) return 'Rods';
		if (strpos($p, 'PLATE') === 0 || strpos($p, 'PL') === 0) return 'Plates';
		if (strpos($p, 'CDA')   === 0 || strpos($p, 'CD') === 0) return 'Packaging Cards';
		if (strpos($p, 'MC')    === 0 || strpos($p, 'PKG') === 0) return 'Packaging';
		return 'Other';
	}

	/** Short season label for table headers. */
	function season_short_label($key) {
		$map = ['jul_sep' => 'Jul–Sep', 'oct_dec' => 'Oct–Dec', 'jan_mar' => 'Jan–Mar'];
		return $map[$key] ?? $key;
	}

	$wantAI = !empty($_POST['ai']);     // include the written AI action plan
	$fresh  = !empty($_POST['fresh']);  // force a recompute, ignore cache

	// Bump when the payload shape changes so old caches auto-invalidate.
	$SEASON_SCHEMA = 18;

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

	// On a forced Refresh, drop cached LIVE Shopify inventory so "Have" re-pulls from
	// Shopify (otherwise stock is up to 3h stale even after clicking Refresh).
	if ($fresh) {
		try { $db->exec("DELETE FROM data_cache WHERE ckey = 'season_fp_loc' OR ckey LIKE 'season_loc_%' OR ckey LIKE 'season_src_%'"); } catch (Throwable $e) {}
	}

	// Refresh Best Stock Levels from current build history + omit before reading them —
	// this used to run when the Raw Materials Stock Order page loaded (now merged here).
	// Guarded include: bsl_calc.php only redirects when hit directly, not when included.
	try { require(__DIR__."/../bsl_calc.php"); } catch (Throwable $e) { /* keep going with existing BSLs */ }

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
			'animator_demand' => 0, 'animator_in_stock' => 0, 'animator_to_build' => 0, 'animator_items' => [],
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

	// ── Per-animator build + shared raw materials drained CUMULATIVELY ─────────
	// Shared parts (rods, plates, packaging) draw from one pool that is depleted
	// across all three seasons in order, so we can show what remains after each
	// season and detect when a part runs out (driving order-by dates below).
	$animBuild = [];
	foreach ($animators as $i => $a) {
		$stock = max(0, (int)($a['in_stock'] ?? 0));
		foreach ($seasons as $s) {
			$d = (int)($a['prior_year_sales'][$s['key']] ?? 0);
			$ship = min($stock, $d); $entering = $stock; $stock -= $ship;
			$animBuild[$i][$s['key']] = ['build' => $d - $ship, 'entering' => $entering, 'demand' => $d];
		}
	}

	$partMeta = []; $pool = [];
	foreach ($raws as $rm) { $partMeta[$rm['part']] = $rm; $pool[$rm['part']] = (int)$rm['on_hand'] + (int)$rm['on_order']; }

	$seasonUsage = [];
	foreach ($seasons as $s) {
		$enteringPool = $pool;             // pool before this season's builds
		$usage = [];
		foreach ($animators as $i => $a) {
			$b = $animBuild[$i][$s['key']]['build'];
			if ($b <= 0) continue;
			foreach (($a['bom'] ?? []) as $bl) {
				$usage[$bl['part']] = ($usage[$bl['part']] ?? 0) + $b * (int)$bl['qty_per_unit'];
			}
		}
		$seasonUsage[$s['key']] = $usage;

		// Per-SKU build list: to-build, FP on hand, and how many of THIS animator
		// could be built from the pool entering this season (raw-limited, alone).
		$items = [];
		foreach ($animators as $i => $a) {
			$info = $animBuild[$i][$s['key']];
			if ($info['demand'] <= 0 && $info['build'] <= 0) continue;
			$cap = null; $limit = null; $bomDetail = [];
			foreach (($a['bom'] ?? []) as $bl) {
				$q = (int)$bl['qty_per_unit']; if ($q <= 0) continue;
				$poolEnter = (int)($enteringPool[$bl['part']] ?? 0);
				$can  = intdiv(max(0, $poolEnter), $q);
				$desc = $partMeta[$bl['part']]['description'] ?? '';
				$bomDetail[] = ['part' => $bl['part'], 'desc' => $desc, 'per_unit' => $q, 'pool' => $poolEnter, 'can_make' => $can];
				if ($cap === null || $can < $cap) { $cap = $can; $limit = ['part' => $bl['part'], 'desc' => $desc, 'per_unit' => $q, 'pool' => $poolEnter]; }
			}
			$items[] = [
				'sku' => $a['sku'] ?: '(no SKU)', 'demand' => $info['demand'],
				'have' => $info['entering'], 'to_build' => $info['build'], 'buildable' => $cap,
				'limit' => $limit, 'bom' => $bomDetail, 'is_amazon' => !empty($a['is_amazon']),
				'sources' => $a['demand_sources'][$s['key']] ?? demand_sources_empty(),
			];
		}
		// Merge a base animator and its [Amazon] twin (same SKU) into ONE display line, with a
		// regular/Amazon split for the drill-down. The raw-material/card math above already used
		// the separate entries (base = CD cards, twin = CDA cards), so ordering is unaffected.
		$merged = [];
		foreach ($items as $it) {
			$sku = $it['sku'];
			if (!isset($merged[$sku])) {
				$merged[$sku] = ['sku' => $sku, 'demand' => 0, 'have' => 0, 'to_build' => 0,
					'buildable' => null, 'limit' => null, 'bom' => [], 'sources' => demand_sources_empty(),
					'regular' => ['demand' => 0, 'have' => 0, 'to_build' => 0],
					'amazon'  => ['demand' => 0, 'have' => 0, 'to_build' => 0], 'has_amazon' => false];
			}
			$portion = !empty($it['is_amazon']) ? 'amazon' : 'regular';
			foreach (['demand', 'have', 'to_build'] as $k) { $merged[$sku][$portion][$k] += (int)$it[$k]; $merged[$sku][$k] += (int)$it[$k]; }
			$merged[$sku]['sources'] = merge_demand_sources($merged[$sku]['sources'], $it['sources']);
			if (!empty($it['is_amazon'])) $merged[$sku]['has_amazon'] = true;
			else { $merged[$sku]['bom'] = $it['bom']; $merged[$sku]['buildable'] = $it['buildable']; $merged[$sku]['limit'] = $it['limit']; }
		}
		$items = array_values($merged);
		usort($items, fn($x, $y) => $y['to_build'] <=> $x['to_build']);
		$summary[$s['key']]['animator_items'] = $items;

		// Deplete the shared pool so the next season's entering pool is cumulative.
		foreach ($usage as $part => $u) {
			$pool[$part] = (int)($pool[$part] ?? 0) - (int)$u;
		}
	}

	// ── Shared parts, grouped by category, drawn down across the seasons ──────
	$seasonShorts = [];
	foreach ($seasons as $s) $seasonShorts[] = season_short_label($s['key']);

	$sharedByPart = [];
	foreach ($partMeta as $part => $rm) {
		$usedAny = false;
		foreach ($seasons as $s) if ((int)($seasonUsage[$s['key']][$part] ?? 0) > 0) { $usedAny = true; break; }
		if (!$usedAny) continue;
		$start = (int)$rm['on_hand'] + (int)$rm['on_order'];
		$run = $start; $cells = [];
		foreach ($seasons as $s) {
			$u = (int)($seasonUsage[$s['key']][$part] ?? 0);
			$run -= $u;
			$cells[] = ['used' => $u, 'remaining' => $run];
		}
		$sharedByPart[] = ['part' => $part, 'category' => shared_category($part), 'start' => $start, 'cells' => $cells];
	}

	// ── Raw-material orders: cumulative shortfall + order-by date ──────────────
	$rawOrders = []; $rawTotalCost = 0.0;
	foreach ($partMeta as $part => $rm) {
		$startAvail = (int)$rm['on_hand'] + (int)$rm['on_order'];
		$cum = 0; $total = 0; $firstNegStart = null; $bySeason = [];
		foreach ($seasons as $s) {
			$u = (int)($seasonUsage[$s['key']][$part] ?? 0);
			$cum += $u; $total += $u; $bySeason[$s['key']] = $u;
			if ($firstNegStart === null && $cum > $startAvail) $firstNegStart = $s['start'];
		}
		$short = max(0, $total - $startAvail);
		if ($short <= 0) continue;
		$moq   = max(1, (int)$rm['moq']);
		$order = (int)(ceil($short / $moq) * $moq);
		$cost  = $order * (float)$rm['unit_cost'];
		$rawTotalCost += $cost;
		$lead  = (int)$rm['lead_time_days'];
		$orderByTs = $firstNegStart ? strtotime("-$lead days", strtotime($firstNegStart)) : null;
		$rawOrders[] = [
			'part' => $part, 'description' => $rm['description'], 'manufacturer' => $rm['manufacturer'],
			'total_usage' => $total, 'by_season' => $bySeason, 'on_hand' => (int)$rm['on_hand'], 'on_order' => (int)$rm['on_order'],
			'short' => $short, 'order_qty' => $order, 'moq' => $moq,
			'by_date' => $orderByTs ? date('M j, Y', $orderByTs) : null,
			'by_past' => $orderByTs ? ($orderByTs < time()) : false,
			'by_ts' => $orderByTs ?: null,
			'lead_time_days' => $lead, 'unit_cost' => (float)$rm['unit_cost'], 'cost' => $cost,
			'bsl' => (int)($rm['base_stock_level'] ?? 0), 'demand_12mo' => (int)($rm['demand_12mo'] ?? 0),
			'demand_6mo' => (int)($rm['demand_6mo'] ?? 0), 'omit' => (int)($rm['omit'] ?? 0),
			'part_id' => (int)($rm['part_id'] ?? 0),
		];
	}
	usort($rawOrders, function ($a, $b) {
		if ($a['by_past'] !== $b['by_past']) return $a['by_past'] ? -1 : 1;
		return strcasecmp($a['manufacturer'], $b['manufacturer']);
	});

	// ── Finished-goods orders: same cumulative-shortfall + order-by logic as raw ──
	// Finished goods are imported (no build), so "available" is current stock; demand
	// draws it down across the seasons. MOQ + lead time come from the group supply terms.
	$fgOrders = []; $fgTotalCost = 0.0;
	foreach ($data['finished_goods'] as $f) {
		$startAvail = (int)$f['in_stock'];
		$cum = 0; $total = 0; $firstNegStart = null; $bySeason = [];
		foreach ($seasons as $s) {
			$u = (int)($f['prior_year_sales'][$s['key']] ?? 0);
			$cum += $u; $total += $u; $bySeason[$s['key']] = $u;
			if ($firstNegStart === null && $cum > $startAvail) $firstNegStart = $s['start'];
		}
		$short = max(0, $total - $startAvail);
		if ($short <= 0) continue;
		$moq   = max(1, (int)$f['moq']);
		$order = (int)(ceil($short / $moq) * $moq);
		$lead  = (int)$f['lead_time_days'];
		$cost  = $order * (float)$f['unit_cost'];
		$fgTotalCost += $cost;
		$orderByTs = $firstNegStart ? strtotime("-$lead days", strtotime($firstNegStart)) : null;
		$fgOrders[] = [
			'sku' => $f['sku'], 'product' => $f['product'], 'group' => $f['group'],
			'total_usage' => $total, 'by_season' => $bySeason, 'in_stock' => $startAvail,
			'short' => $short, 'order_qty' => $order, 'moq' => $moq,
			'moq_set' => !empty($f['moq_set']), 'lead_set' => !empty($f['lead_set']),
			'by_date' => $orderByTs ? date('M j, Y', $orderByTs) : null,
			'by_past' => $orderByTs ? ($orderByTs < time()) : false,
			'by_ts' => $orderByTs ?: null,
			'lead_time_days' => $lead, 'unit_cost' => (float)$f['unit_cost'], 'cost' => $cost,
		];
	}

	// ── Unified "Need to Order": raw + finished, urgency-tagged and sorted ────────
	// urgency: now = order-by already reached/overdue; soon = within 30 days; later = beyond.
	$soonTs = time() + 30 * 86400;
	$needToOrder = [];
	foreach ($rawOrders as $r) {
		$urg = $r['by_past'] ? 'now' : (($r['by_ts'] && $r['by_ts'] <= $soonTs) ? 'soon' : 'later');
		$needToOrder[] = [
			'type' => 'raw', 'name' => $r['part'], 'description' => $r['description'], 'supplier' => $r['manufacturer'],
			'category' => shared_category($r['part']),
			'group' => null, 'have' => $r['on_hand'] + $r['on_order'], 'need' => $r['total_usage'], 'by_season' => $r['by_season'], 'short' => $r['short'],
			'order_qty' => $r['order_qty'], 'moq' => (int)$r['moq'],
			'moq_known' => true, 'by_date' => $r['by_date'], 'by_past' => $r['by_past'], 'by_ts' => $r['by_ts'],
			'lead_time_days' => $r['lead_time_days'], 'unit_cost' => $r['unit_cost'], 'cost' => $r['cost'], 'urgency' => $urg,
		];
	}
	foreach ($fgOrders as $o) {
		$urg = $o['by_past'] ? 'now' : (($o['by_ts'] && $o['by_ts'] <= $soonTs) ? 'soon' : 'later');
		$needToOrder[] = [
			'type' => 'finished', 'name' => $o['sku'], 'description' => $o['product'], 'supplier' => $o['group'],
			'category' => $o['group'],
			'group' => $o['group'], 'have' => $o['in_stock'], 'need' => $o['total_usage'], 'by_season' => $o['by_season'], 'short' => $o['short'],
			'order_qty' => $o['order_qty'], 'moq' => $o['moq'], 'moq_known' => !empty($o['moq_set']),
			'by_date' => $o['by_date'], 'by_past' => $o['by_past'], 'by_ts' => $o['by_ts'],
			'lead_time_days' => $o['lead_time_days'], 'unit_cost' => $o['unit_cost'], 'cost' => $o['cost'], 'urgency' => $urg,
		];
	}
	// Sort: overdue/soonest order-by first (null timestamps last), then biggest cost.
	usort($needToOrder, function ($a, $b) {
		$at = $a['by_ts'] ?? PHP_INT_MAX; $bt = $b['by_ts'] ?? PHP_INT_MAX;
		if ($at !== $bt) return $at <=> $bt;
		return $b['cost'] <=> $a['cost'];
	});
	$needTotalCost = $rawTotalCost + $fgTotalCost;

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

	// ── Full raw-material stock reference (every relevant part) ────────────────
	// Carries over the reference data from the old Raw Materials Stock Order page:
	// on-hand, on-order, BSL target, 6/12-mo build demand, MOQ, cost, and omit.
	$rawAll = [];
	foreach ($raws as $rm) {
		$oh = (int)$rm['on_hand']; $oo = (int)$rm['on_order']; $bsl = (int)($rm['base_stock_level'] ?? 0);
		$d12 = (int)($rm['demand_12mo'] ?? 0); $d6 = (int)($rm['demand_6mo'] ?? 0); $omit = (int)($rm['omit'] ?? 0);
		if ($oh <= 0 && $oo <= 0 && $bsl <= 0 && $d12 <= 0 && $omit <= 0) continue;   // drop dead parts
		$moq = max(1, (int)$rm['moq']);
		$toOrderRaw = $bsl - $oh - $oo;
		$toOrder = $toOrderRaw > 0 ? (int)(ceil($toOrderRaw / $moq) * $moq) : 0;
		$rawAll[] = [
			'part' => $rm['part'], 'description' => $rm['description'], 'manufacturer' => $rm['manufacturer'],
			'category' => shared_category($rm['part']), 'part_id' => (int)($rm['part_id'] ?? 0),
			'on_hand' => $oh, 'on_order' => $oo, 'bsl' => $bsl, 'demand_12mo' => $d12, 'demand_6mo' => $d6,
			'moq' => $moq, 'omit' => $omit, 'to_order' => $toOrder, 'unit_cost' => (float)$rm['unit_cost'],
			'lead_time_days' => (int)($rm['lead_time_days'] ?? 45),
			'animator_component' => !empty($rm['animator_component']),
		];
	}
	usort($rawAll, fn($a, $b) => strcmp($a['category'], $b['category']) ?: strcmp($a['part'], $b['part']));

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
		'summary'         => array_values($summary),
		'shared_by_part'  => $sharedByPart,
		'season_shorts'   => $seasonShorts,
		'raw_orders'      => $rawOrders,
		'raw_total_cost'  => $rawTotalCost,
		'raw_all'         => $rawAll,
		'fg_orders'       => $fgOrders,
		'fg_total_cost'   => $fgTotalCost,
		'need_to_order'   => $needToOrder,
		'need_total_cost' => $needTotalCost,
		'fg_groups'       => $data['finished_good_groups'] ?? [],
		'charts'          => ['labels' => $labels, 'animators' => $anim, 'finished_goods' => $fg],
		'tradeshow'       => $data['tradeshow_prior_year_jul_aug'],
		'report'          => $report,
		'report_note'     => $reportNote,
		'data_warning'    => $dataWarning,
		'schema'          => $SEASON_SCHEMA,
		'computed_at'     => date('M j, Y g:i A'),
	]);

	// Cache the deterministic (non-AI) result so re-opening the page is instant.
	if (!$wantAI) {
		try {
			setting_set($db, 'season_cache', $payload);
			setting_set($db, 'season_cache_at', (string)time());
		} catch (Throwable $e) { /* best effort */ }
	}

	echo $payload;
