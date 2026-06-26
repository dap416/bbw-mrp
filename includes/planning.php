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
