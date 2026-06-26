<?php

	require_once(__DIR__."/fns.php");
	require_once(__DIR__."/shopify.php");

	/**
	 * Assemble a compact planning snapshot for the demand-planning assistant.
	 * Pre-aggregates everything so the model reasons over totals rather than
	 * thousands of raw rows. Returns a plain array (json_encode in the caller).
	 *
	 * $targetDate: ISO 'YYYY-MM-DD' horizon to plan to (e.g. season end).
	 */
	function build_planning_context($db, $targetDate) {
		$today = date('Y-m-d');
		$ts    = strtotime($targetDate) ?: strtotime('+90 days');
		$targetDate = date('Y-m-d', $ts);
		$windowDays = max(1, (int)round(($ts - strtotime($today)) / 86400));

		// Last-year comparison window: same calendar span, one year earlier.
		$lyStart = date('Y-m-d', strtotime('-1 year', strtotime($today)));
		$lyEnd   = date('Y-m-d', strtotime('-1 year', $ts));

		// ── Live Shopify state ────────────────────────────────────────────────
		$variants = shopify_is_configured() ? shopify_fetch_variants() : ['skus' => [], 'error' => 'not configured'];
		$shopSkus = $variants['skus'] ?? [];

		$sales = shopify_is_configured() ? shopify_sales_in_range($lyStart, $lyEnd) : ['by_sku' => [], 'by_channel' => [], 'error' => 'not configured'];
		$salesBySku = $sales['by_sku'] ?? [];

		// ── Products + BOM ────────────────────────────────────────────────────
		$hasSku  = column_exists($db, 'products', 'shopify_sku');
		$hasGoal = column_exists($db, 'products', 'annual_goal');
		$cols = "id, name" . ($hasSku ? ", shopify_sku" : "") . ($hasGoal ? ", annual_goal" : "");
		$products = $db->query("SELECT $cols FROM products ORDER BY name ASC")->fetchAll();

		$bomByProd = [];
		$partIds   = [];
		foreach ($db->query("
			SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.`desc`
			FROM build b JOIN parts p ON p.id = b.partid
			ORDER BY b.prodid ASC
		") as $bl) {
			$bomByProd[$bl['prodid']][] = $bl;
			$partIds[$bl['partid']] = true;
		}

		// On-order per part (open POs) — avoids double-ordering
		$onOrder = [];
		foreach ($db->query("
			SELECT partid, SUM(qty - recqty) AS v
			FROM orders WHERE (qty - recqty) > 0 GROUP BY partid
		") as $r) {
			$onOrder[$r['partid']] = max(0, (int)$r['v']);
		}

		// ── Finished products block ───────────────────────────────────────────
		$fp = [];
		foreach ($products as $p) {
			$sku  = $hasSku ? ($p['shopify_sku'] ?? '') : '';
			$bom  = $bomByProd[$p['id']] ?? [];
			// Only include products we build or that are mapped to Shopify
			if ($sku === '' && empty($bom)) continue;

			$row = [
				'product'        => $p['name'],
				'sku'            => $sku,
				'annual_goal'    => $hasGoal ? (int)($p['annual_goal'] ?? 0) : 0,
				'shopify_in_stock' => ($sku !== '' && isset($shopSkus[$sku])) ? (int)$shopSkus[$sku]['qty'] : null,
				'units_sold_last_year_window' => ($sku !== '' && isset($salesBySku[$sku])) ? (int)$salesBySku[$sku] : 0,
				'bom' => array_map(fn($b) => ['part' => $b['partno'], 'qty_per_unit' => (int)$b['qty']], $bom),
			];
			$fp[] = $row;
		}

		// ── Raw materials block (parts used in the above BOMs) ────────────────
		$rm = [];
		if (!empty($partIds)) {
			$inList = implode(',', array_map('intval', array_keys($partIds)));
			foreach ($db->query("
				SELECT id, partno, `desc`, qoh, bsl, imoq, lead_time, cost, supplier
				FROM parts WHERE id IN ($inList) ORDER BY partno ASC
			") as $pt) {
				$rm[] = [
					'part'          => $pt['partno'],
					'description'   => $pt['desc'],
					'on_hand'       => (int)$pt['qoh'],
					'base_stock_level' => (int)$pt['bsl'],
					'moq'           => (int)$pt['imoq'],
					'lead_time_days' => (int)($pt['lead_time'] ?? 45),
					'on_order'      => (int)($onOrder[$pt['id']] ?? 0),
					'unit_cost'     => (float)$pt['cost'],
					'supplier'      => $pt['supplier'],
				];
			}
		}

		// ── Planning events (POs + tradeshows) ────────────────────────────────
		$events = [];
		try {
			foreach ($db->query("SELECT type, name, event_date, end_date, repeats, details FROM planning_events ORDER BY event_date ASC") as $ev) {
				$events[] = [
					'type'    => $ev['type'],
					'name'    => $ev['name'],
					'date'    => $ev['event_date'],
					'end_date'=> $ev['end_date'],
					'repeats_yearly' => (bool)$ev['repeats'],
					'details' => $ev['details'],
				];
			}
		} catch (Throwable $e) { /* table may not exist yet */ }

		return [
			'meta' => [
				'today'        => $today,
				'target_date'  => $targetDate,
				'window_days'  => $windowDays,
				'comparison'   => "last year same window ($lyStart to $lyEnd)",
				'shopify_connected'   => shopify_is_configured(),
				'sales_truncated'     => !empty($sales['truncated']),
				'notes' => 'Quantities are units. moq = minimum order qty (round order up to a multiple). lead_time_days = order-to-delivery; order early enough that stock arrives before it is needed. on_order = already-placed raw-material POs not yet received (do not re-order these).',
			],
			'sales_last_year_by_channel' => $sales['by_channel'] ?? [],
			'finished_products' => $fp,
			'raw_materials'     => $rm,
			'planning_events'   => $events,
		];
	}

	/**
	 * Build the seasonal readiness dataset: three fixed windows
	 * (Jul-Sep, Oct-Dec, Jan-Mar) with prior-year sales per SKU, current FP
	 * stock, animator BOMs + raw materials, non-animator finished goods, and
	 * Jul/Aug tradeshow (POS) spikes. Used by the Season Readiness report.
	 */
	function build_season_dataset($db) {
		$today = date('Y-m-d');
		$y = (int)date('Y');
		// Upcoming seasons relative to "now" (assumes now is before Q4 of year y).
		$seasons = [
			['key' => 'jul_sep', 'label' => 'July–September (pre-season)',  'start' => "$y-07-01",       'end' => "$y-09-30"],
			['key' => 'oct_dec', 'label' => 'October–December (duck season)', 'start' => "$y-10-01",     'end' => "$y-12-31"],
			['key' => 'jan_mar', 'label' => 'January–March (' . ($y+1) . ', slow close)', 'start' => ($y+1)."-01-01", 'end' => ($y+1)."-03-31"],
		];

		$shopReady = shopify_is_configured();
		$variants  = $shopReady ? shopify_fetch_variants() : ['skus' => []];
		$shopSkus  = $variants['skus'] ?? [];

		// Prior-year sales per window (one year earlier, same calendar span)
		$priorSales = [];   // season key => [sku => units]
		$shopErr = null;
		foreach ($seasons as $s) {
			if (!$shopReady) { $priorSales[$s['key']] = []; continue; }
			$ps = date('Y-m-d', strtotime('-1 year', strtotime($s['start'])));
			$pe = date('Y-m-d', strtotime('-1 year', strtotime($s['end'])));
			$r  = shopify_sales_in_range($ps, $pe);
			if (!empty($r['error'])) { $shopErr = $r['error']; $priorSales[$s['key']] = []; }
			else $priorSales[$s['key']] = $r['by_sku'] ?? [];
			$seasons[array_search($s, $seasons, true)]['prior_window'] = "$ps to $pe";
		}

		// ── Products + BOM (animators = products with a BOM) ──────────────────
		$hasSku = column_exists($db, 'products', 'shopify_sku');
		$cols = "id, name" . ($hasSku ? ", shopify_sku" : "");
		$products = $db->query("SELECT $cols FROM products ORDER BY name ASC")->fetchAll();

		$bomByProd = [];
		$partIds = [];
		foreach ($db->query("
			SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.`desc`
			FROM build b JOIN parts p ON p.id = b.partid
		") as $bl) {
			$bomByProd[$bl['prodid']][] = $bl;
			$partIds[$bl['partid']] = true;
		}

		// On-order per part
		$onOrder = [];
		foreach ($db->query("SELECT partid, SUM(qty - recqty) AS v FROM orders WHERE (qty - recqty) > 0 GROUP BY partid") as $r) {
			$onOrder[$r['partid']] = max(0, (int)$r['v']);
		}

		// ── Animator products ─────────────────────────────────────────────────
		$animators = [];
		$animatorSkus = [];
		foreach ($products as $p) {
			$bom = $bomByProd[$p['id']] ?? [];
			if (empty($bom)) continue; // only products with raw materials
			$sku = $hasSku ? ($p['shopify_sku'] ?? '') : '';
			if ($sku !== '') $animatorSkus[$sku] = true;
			$perSeason = [];
			foreach ($seasons as $s) {
				$perSeason[$s['key']] = ($sku !== '') ? (int)($priorSales[$s['key']][$sku] ?? 0) : 0;
			}
			$animators[] = [
				'product'   => $p['name'],
				'sku'       => $sku,
				'in_stock'  => ($sku !== '' && isset($shopSkus[$sku])) ? (int)$shopSkus[$sku]['qty'] : null,
				'prior_year_sales' => $perSeason,
				'bom' => array_map(fn($b) => ['part' => $b['partno'], 'qty_per_unit' => (int)$b['qty']], $bom),
			];
		}

		// ── Raw materials (parts used by animators) with manufacturer ─────────
		$rawMaterials = [];
		if (!empty($partIds)) {
			$inList = implode(',', array_map('intval', array_keys($partIds)));
			foreach ($db->query("
				SELECT p.partno, p.`desc`, p.qoh, p.bsl, p.imoq, p.lead_time, p.cost, p.supplier, p.id,
				       m.name AS mfg_name
				FROM parts p LEFT JOIN manufacturers m ON m.id = p.manufacturer
				WHERE p.id IN ($inList) ORDER BY p.partno ASC
			") as $pt) {
				$rawMaterials[] = [
					'part'           => $pt['partno'],
					'description'    => $pt['desc'],
					'manufacturer'   => $pt['mfg_name'] ?: ($pt['supplier'] ?: 'Unknown'),
					'on_hand'        => (int)$pt['qoh'],
					'on_order'       => (int)($onOrder[$pt['id']] ?? 0),
					'moq'            => (int)$pt['imoq'],
					'lead_time_days' => (int)($pt['lead_time'] ?? 45),
					'unit_cost'      => (float)$pt['cost'],
				];
			}
		}

		// ── Non-animator finished goods (cases, wings, etc. — no BOM) ─────────
		// Built from Shopify SKUs that sold, excluding animator SKUs and POD apparel.
		$fgSkus = [];
		foreach ($priorSales as $skuMap) foreach ($skuMap as $sku => $q) $fgSkus[$sku] = true;
		$finishedGoods = [];
		foreach (array_keys($fgSkus) as $sku) {
			if (isset($animatorSkus[$sku])) continue;          // handled as animator
			if (!isset($shopSkus[$sku])) continue;             // unknown / discontinued
			$stock = (int)$shopSkus[$sku]['qty'];
			if ($stock >= 9999) continue;                      // print-on-demand
			$perSeason = [];
			$any = 0;
			foreach ($seasons as $s) { $v = (int)($priorSales[$s['key']][$sku] ?? 0); $perSeason[$s['key']] = $v; $any += $v; }
			if ($any <= 0) continue;
			$finishedGoods[] = [
				'sku'       => $sku,
				'product'   => $shopSkus[$sku]['product_title'] . ' — ' . $shopSkus[$sku]['variant_title'],
				'in_stock'  => $stock,
				'prior_year_sales' => $perSeason,
			];
		}
		// Sort finished goods by total prior-year demand desc
		usort($finishedGoods, function($a, $b) {
			return array_sum($b['prior_year_sales']) <=> array_sum($a['prior_year_sales']);
		});

		// ── Tradeshow / POS spikes in prior-year Jul–Aug ──────────────────────
		$tradeshow = ['total' => 0, 'top_days' => []];
		if ($shopReady) {
			$pos = shopify_pos_by_date(date('Y-m-d', strtotime("-1 year", strtotime("$y-07-01"))),
			                           date('Y-m-d', strtotime("-1 year", strtotime("$y-08-31"))));
			if (empty($pos['error'])) {
				arsort($pos['by_date']);
				$top = array_slice($pos['by_date'], 0, 6, true);
				$tradeshow['total'] = $pos['total'];
				foreach ($top as $d => $u) $tradeshow['top_days'][] = ['date' => $d, 'units' => $u];
			}
		}

		return [
			'meta' => [
				'today'             => $today,
				'baseline'          => 'prior-year same window, no growth applied',
				'shopify_connected' => $shopReady,
				'shopify_error'     => $shopErr,
				'note'              => 'Only animator products have raw materials (BOMs) in the MRP; everything else is ordered as finished goods. moq = round up to a multiple. lead_time_days = order-to-delivery. on_order = already-placed raw POs not yet received.',
			],
			'seasons'        => $seasons,
			'animators'      => $animators,
			'raw_materials'  => $rawMaterials,
			'finished_goods' => $finishedGoods,
			'tradeshow_prior_year_jul_aug' => $tradeshow,
		];
	}

	/** True if $table has $col. Cached per request. */
	function column_exists($db, $table, $col) {
		static $cache = [];
		$key = "$table.$col";
		if (isset($cache[$key])) return $cache[$key];
		try {
			$db->query("SELECT `$col` FROM `$table` LIMIT 1");
			return $cache[$key] = true;
		} catch (Throwable $e) { return $cache[$key] = false; }
	}
