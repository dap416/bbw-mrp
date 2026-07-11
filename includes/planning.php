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
			SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.`desc`, p.qoh
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

			// How many more we can build right now from raw materials on hand,
			// and which part runs out first (the constraint).
			$buildableNow = null; $limitPart = null;
			foreach ($bom as $b) {
				$need = (int)$b['qty'];
				if ($need <= 0) continue;
				$can = intdiv((int)$b['qoh'], $need);
				if ($buildableNow === null || $can < $buildableNow) { $buildableNow = $can; $limitPart = $b['partno']; }
			}

			$row = [
				'product'        => $p['name'],
				'sku'            => $sku,
				'is_animator'    => !empty($bom),     // only animators have raw materials/BOM
				'shopify_in_stock' => ($sku !== '' && isset($shopSkus[$sku])) ? (int)$shopSkus[$sku]['qty'] : null,
				'units_sold_last_year_window' => ($sku !== '' && isset($salesBySku[$sku])) ? (int)$salesBySku[$sku] : 0,
				'buildable_now_from_raw' => $buildableNow,   // null = no BOM (not an animator)
				'limiting_part'  => $limitPart,
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
	/**
	 * Finished-good supply terms (MOQ, lead time, unit cost) keyed by GROUP — a family of
	 * finished goods that share a source, cost, and working/lead time (e.g. all WINGZ, all
	 * Bags & Cases). Finished goods are imported, so unlike raw parts they carry no MOQ/lead
	 * time in the parts table; the "Need to Order" timing needs this.
	 */
	function ensure_fg_supply_table($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS fg_supply (
			grp        VARCHAR(60) PRIMARY KEY,
			moq        INT NOT NULL DEFAULT 0,
			lead_days  INT NOT NULL DEFAULT 0,
			unit_cost  DECIMAL(12,2) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");
	}
	/** Known imported-goods terms supplied by the owner. The group editor (fg_supply rows) overrides these. */
	function fg_group_defaults() {
		return [
			'WINGZ'        => ['moq' => 250, 'lead_days' => 70, 'unit_cost' => 6.00],
			'Bags & Cases' => ['moq' => 0,   'lead_days' => 0,  'unit_cost' => 11.00],
		];
	}
	function load_fg_supply($db) {
		$out = fg_group_defaults();   // seed with known defaults; DB rows below take precedence
		try { ensure_fg_supply_table($db); foreach ($db->query("SELECT grp, moq, lead_days, unit_cost FROM fg_supply") as $r) {
			$out[$r['grp']] = ['moq' => (int)$r['moq'], 'lead_days' => (int)$r['lead_days'], 'unit_cost' => (float)$r['unit_cost']];
		} } catch (Throwable $e) {}
		return $out;
	}
	/** Group a finished good (by SKU + title) into a supply family with shared terms. */
	function fg_group($sku, $title) {
		$t = strtolower(trim($sku . ' ' . $title));
		if (strpos($t, 'wing') !== false)                                   return 'WINGZ';
		if (strpos($t, 'case') !== false || strpos($t, 'bag') !== false)    return 'Bags & Cases';
		if (strpos($t, 'batter') !== false)                                 return 'Batteries';
		if (strpos($t, 'hat') !== false || strpos($t, 'beanie') !== false)  return 'Hats';
		if (strpos($t, 'remote') !== false || strpos($t, 'charger') !== false || strpos($t, 'charge') !== false
		    || strpos($t, 'stake') !== false || strpos($t, 'cord') !== false || strpos($t, 'plug') !== false) return 'Accessories';
		return 'Other';
	}

	/** An "[Amazon]" twin product — the Amazon-customer (TJ Stumpf) variant of an animator,
	 *  built to order with the CDA packaging card. It shares its base animator's Shopify SKU. */
	function is_amazon_product($name) { return stripos((string)$name, '[amazon]') !== false; }

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
		$priorSales   = [];   // season key => [sku => units]
		$priorAmazon  = [];   // season key => [sku => units sold to the Amazon/CDA customer]
		$priorChannel = [];   // season key => [channel => units]
		$priorOregon  = [];   // season key => [sku => units FULFILLED from the Oregon Warehouse]
		$shopErr = null;
		$ttl = $shopReady ? inventory_cache_ttl($db) : 0;
		foreach ($seasons as $s) {
			if (!$shopReady) { $priorSales[$s['key']] = []; $priorAmazon[$s['key']] = []; $priorChannel[$s['key']] = []; $priorOregon[$s['key']] = []; continue; }
			$ps = date('Y-m-d', strtotime('-1 year', strtotime($s['start'])));
			$pe = date('Y-m-d', strtotime('-1 year', strtotime($s['end'])));
			$r  = shopify_sales_in_range($ps, $pe);
			if (!empty($r['error'])) { $shopErr = $r['error']; $priorSales[$s['key']] = []; $priorAmazon[$s['key']] = []; $priorChannel[$s['key']] = []; }
			else { $priorSales[$s['key']] = $r['by_sku'] ?? []; $priorAmazon[$s['key']] = $r['by_sku_amazon'] ?? []; $priorChannel[$s['key']] = $r['by_channel'] ?? []; }
			// Oregon vs rest split by fulfillment location (cached — heavy order scan).
			$loc = shopify_cache_remember($db, 'season_loc_'.$ps.'_'.$pe, $ttl, fn() => shopify_sales_by_location($ps, $pe))['data'];
			$priorOregon[$s['key']] = (is_array($loc) && empty($loc['error'])) ? ($loc['by_sku_oregon'] ?? []) : [];
			$seasons[array_search($s, $seasons, true)]['prior_window'] = "$ps to $pe";
		}

		// Current finished-product inventory split by location (Oregon vs rest).
		$fpLoc = $shopReady
			? (shopify_cache_remember($db, 'season_fp_loc', $ttl, fn() => shopify_fp_by_location())['data']['skus'] ?? [])
			: [];

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
			$isAmz = is_amazon_product($p['name']);
			$sku = $hasSku ? ($p['shopify_sku'] ?? '') : '';
			if ($sku !== '') $animatorSkus[$sku] = true;
			// A base animator and its [Amazon] twin share one Shopify SKU. Split that SKU's
			// demand so it is NOT counted twice: the [Amazon] twin carries only the Amazon
			// (TJ Stumpf) units (built to order with a CDA card, no stock held); the base
			// carries all the retail units (CD card, drawing from Shopify stock).
			$perSeason = []; $perSeasonAmazon = []; $perSeasonOregon = [];
			foreach ($seasons as $s) {
				$tot = ($sku !== '') ? (int)($priorSales[$s['key']][$sku] ?? 0) : 0;
				$amz = ($sku !== '') ? (int)($priorAmazon[$s['key']][$sku] ?? 0) : 0;
				if ($isAmz) {
					$perSeason[$s['key']]       = $amz;
					$perSeasonAmazon[$s['key']] = $amz;
					$perSeasonOregon[$s['key']] = 0;
				} else {
					$perSeason[$s['key']]       = max(0, $tot - $amz);
					$perSeasonAmazon[$s['key']] = 0;
					$perSeasonOregon[$s['key']] = ($sku !== '') ? (int)($priorOregon[$s['key']][$sku] ?? 0) : 0;
				}
			}
			$fpO = (!$isAmz && $sku !== '' && isset($fpLoc[$sku])) ? (int)$fpLoc[$sku]['oregon'] : null;
			$animators[] = [
				'product'   => $p['name'],
				'sku'       => $sku,
				'is_amazon' => $isAmz,
				'in_stock'  => $isAmz ? 0 : (($sku !== '' && isset($shopSkus[$sku])) ? (int)$shopSkus[$sku]['qty'] : null),
				'in_stock_oregon'   => $fpO,              // finished units currently AT the Oregon Warehouse (null = unknown)
				'prior_year_sales'  => $perSeason,        // retail units for the base; Amazon (TJ Stumpf) units for the [Amazon] twin
				'prior_year_oregon' => $perSeasonOregon,  // subset FULFILLED from the Oregon Warehouse
				'prior_year_amazon' => $perSeasonAmazon,  // Amazon-customer units → CDA card (lives on the [Amazon] twin)
				'bom' => array_map(fn($b) => ['part' => $b['partno'], 'qty_per_unit' => (int)$b['qty']], $bom),
			];
		}

		// ── Raw materials — EVERY part in inventory (not just BOM components) ──
		// Cards (CD-/CDA-), packaging, plates, rods, cams, etc. are all included
		// so the assistant can see on-hand for anything, with a flag marking which
		// are direct animator BOM components.
		// Trailing build demand per part (6 & 12 months) — reference the old Raw Materials
		// Stock Order page showed. BUILD trans store consumption as negative qty, so abs().
		$dem12 = []; $dem6 = [];
		$t12 = date('Y-m-d H:i:s', strtotime('12 months ago'));
		$t6  = date('Y-m-d H:i:s', strtotime('6 months ago'));
		try { foreach ($db->query("SELECT partid, SUM(qty) AS d FROM trans WHERE type='BUILD' AND date > '$t12' GROUP BY partid") as $r) $dem12[$r['partid']] = abs((int)$r['d']); } catch (Throwable $e) {}
		try { foreach ($db->query("SELECT partid, SUM(qty) AS d FROM trans WHERE type='BUILD' AND date > '$t6'  GROUP BY partid") as $r) $dem6[$r['partid']]  = abs((int)$r['d']); } catch (Throwable $e) {}

		$rawMaterials = [];
		foreach ($db->query("
			SELECT p.partno, p.`desc`, p.qoh, p.bsl, p.imoq, p.lead_time, p.cost, p.supplier, p.id, p.omit,
			       m.name AS mfg_name
			FROM parts p LEFT JOIN manufacturers m ON m.id = p.manufacturer
			ORDER BY p.partno ASC
		") as $pt) {
			$rawMaterials[] = [
				'part'              => $pt['partno'],
				'description'       => $pt['desc'],
				'manufacturer'      => $pt['mfg_name'] ?: ($pt['supplier'] ?: 'Unknown'),
				'on_hand'           => (int)$pt['qoh'],
				'on_order'          => (int)($onOrder[$pt['id']] ?? 0),
				'base_stock_level'  => (int)$pt['bsl'],
				'moq'               => (int)$pt['imoq'],
				'lead_time_days'    => (int)($pt['lead_time'] ?? 45),
				'unit_cost'         => (float)$pt['cost'],
				'animator_component' => isset($partIds[$pt['id']]),
				'part_id'           => (int)$pt['id'],
				'omit'              => (int)($pt['omit'] ?? 0),
				'demand_12mo'       => (int)($dem12[$pt['id']] ?? 0),
				'demand_6mo'        => (int)($dem6[$pt['id']] ?? 0),
			];
		}

		// ── Non-animator finished goods (cases, wings, etc. — no BOM) ─────────
		// Built from Shopify SKUs that sold, excluding animator SKUs and POD apparel.
		$fgSupply = load_fg_supply($db);   // per-GROUP MOQ / lead time / unit cost (imported goods)
		$fgSkus = [];
		foreach ($priorSales as $skuMap) foreach ($skuMap as $sku => $q) $fgSkus[$sku] = true;
		$finishedGoods = [];
		foreach (array_keys($fgSkus) as $sku) {
			if (isset($animatorSkus[$sku])) continue;          // handled as animator
			if (!isset($shopSkus[$sku])) continue;             // unknown / discontinued
			$stock = (int)$shopSkus[$sku]['qty'];
			if ($stock >= 9999) continue;                      // print-on-demand
			$perSeason = []; $perSeasonOregon = [];
			$any = 0;
			foreach ($seasons as $s) { $v = (int)($priorSales[$s['key']][$sku] ?? 0); $perSeason[$s['key']] = $v; $any += $v; $perSeasonOregon[$s['key']] = (int)($priorOregon[$s['key']][$sku] ?? 0); }
			if ($any <= 0) continue;
			$grp = fg_group($sku, $shopSkus[$sku]['product_title'] . ' ' . $shopSkus[$sku]['variant_title']);
			$sup = $fgSupply[$grp] ?? null;
			$finishedGoods[] = [
				'sku'       => $sku,
				'product'   => $shopSkus[$sku]['product_title'] . ' — ' . $shopSkus[$sku]['variant_title'],
				'group'     => $grp,
				'in_stock'  => $stock,
				'in_stock_oregon'   => isset($fpLoc[$sku]) ? (int)$fpLoc[$sku]['oregon'] : null,
				'prior_year_sales'  => $perSeason,
				'prior_year_oregon' => $perSeasonOregon,
				// Supply terms inherited from the group (imported goods share source/cost/lead).
				'moq'            => $sup && (int)$sup['moq'] > 0 ? (int)$sup['moq'] : 1,
				'moq_set'        => $sup && (int)$sup['moq'] > 0,
				'lead_time_days' => $sup && (int)$sup['lead_days'] > 0 ? (int)$sup['lead_days'] : 90,
				'lead_set'       => $sup && (int)$sup['lead_days'] > 0,
				'unit_cost'      => $sup ? (float)$sup['unit_cost'] : 0.0,
			];
		}
		// Sort finished goods by total prior-year demand desc
		usort($finishedGoods, function($a, $b) {
			return array_sum($b['prior_year_sales']) <=> array_sum($a['prior_year_sales']);
		});

		// Distinct finished-good groups present + their shared supply terms (for the group editor).
		$fgGroups = [];
		foreach ($finishedGoods as $fg) {
			$g = $fg['group'];
			if (!isset($fgGroups[$g])) {
				$sup = $fgSupply[$g] ?? null;
				$fgGroups[$g] = ['group' => $g, 'count' => 0,
					'moq' => $sup ? (int)$sup['moq'] : 0, 'lead_days' => $sup ? (int)$sup['lead_days'] : 0,
					'unit_cost' => $sup ? (float)$sup['unit_cost'] : 0.0, 'set' => $sup && (int)$sup['moq'] > 0];
			}
			$fgGroups[$g]['count']++;
		}
		ksort($fgGroups);

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

		// ── Open Shopify wholesale / draft orders (customer POs) with line items ──
		$wholesaleOrders = [];
		if ($shopReady) { try { $wo = shopify_open_wholesale_orders(50); if (empty($wo['error'])) $wholesaleOrders = $wo['orders']; } catch (Throwable $e) {} }

		$oregonAnyUnits = 0; foreach ($priorOregon as $m) $oregonAnyUnits += array_sum($m);
		$oregonStatus = !$shopReady ? 'unavailable (Shopify not connected)'
			: (shopify_oregon_location_id() === '' ? 'Oregon Warehouse location not found in Shopify — set the oregon_location_id setting'
			: ($oregonAnyUnits > 0 ? 'loaded' : 'loaded, but no prior-year units were fulfilled from Oregon in these windows'));

		return [
			'meta' => [
				'today'             => $today,
				'baseline'          => 'prior-year same window, no growth applied',
				'shopify_connected' => $shopReady,
				'shopify_error'     => $shopErr,
				'wholesale_orders_note' => 'open_wholesale_orders lists your CURRENT open Shopify draft/wholesale orders (customer POs not yet completed), each with name (order number), customer, created date, status, total, unit count, and line items (sku, title, qty). Use these as concrete demand when the user asks about a specific order or PO: match by name or customer, treat the line items as units to fulfill, net each SKU against in_stock (animators/finished_goods), convert any animator shortfall to raw materials via its bom, and respect MOQ/lead times. This is OPEN drafts only - completed/closed orders are not listed.',
				'oregon_split_status' => $oregonStatus,
				'note'              => 'Only animator products have raw materials (BOMs) in the MRP; everything else is ordered as finished goods. moq = round up to a multiple. lead_time_days = order-to-delivery. on_order = already-placed raw POs not yet received.',
				'amazon_twin_note'  => 'Some animators have an "[Amazon]" twin product that SHARES the same Shopify SKU (is_amazon=true). The twin is the Amazon-customer (' . shopify_amazon_customer() . ') variant, built to order with the CDA packaging card. To avoid double-counting a shared SKU, demand is split: the [Amazon] twin\'s prior_year_sales holds ONLY the Amazon-customer units (in_stock is 0 — always built fresh, never held in stock), and the base animator holds all remaining retail units. So the base BOM consumes CD- cards and the [Amazon] BOM consumes CDA- cards, and the two together sum to that SKU\'s full sales exactly once.',
				'parts_coverage'    => 'raw_materials lists EVERY part in MRP inventory with on_hand, on_order, base_stock_level, moq, lead_time_days, unit_cost. animator_component=true marks a direct BOM component of an animator. Packaging cards (CD-* and Amazon CDA-*), plates, rods and packaging are all included even when they are not BOM components — their demand tracks the animator they package (matchable by part-number brand code, e.g. LDA→CD-LD/CDA-LD; AXLA→CD-AX-L/CDA-AX-L; AXRA→CD-AX-R/CDA-AX-R; KMA→CD-KM/CDA-KM). NOTE: on_order is a total quantity, not a list of POs with ETAs.',
				'packaging_card_rule' => 'Each animator uses ONE packaging card per unit built. Units sold to the Amazon customer (' . shopify_amazon_customer() . ') OR on any order tagged "' . shopify_amazon_tag() . '" in Shopify use the Amazon CDA-<brand> card; ALL other units use the regular CD-<brand> card. Per animator: prior_year_amazon = units needing a CDA card; (prior_year_sales − prior_year_amazon) = units needing a CD card. So CDA-<brand> demand = that animator\'s prior_year_amazon; CD-<brand> demand = prior_year_sales − prior_year_amazon. Match the CD/CDA card to the animator by brand code in the part number.',
				'sales_coverage'    => 'prior_year_sales counts EVERY Shopify order in the window (line-item quantities) EXCEPT cancelled orders. This INCLUDES: online/web, point-of-sale (POS/tradeshows), completed/paid draft orders, and Collective/wholesale. Native Shopify bundles are exploded into their component SKUs. It EXCLUDES: open/un-completed draft orders (no sale yet) and anything not recorded in Shopify (e.g. an off-platform Amazon or wholesale PO). sales_by_channel below shows the actual channel mix so you can confirm coverage. Seasons are quarter-granular (jul_sep, oct_dec, jan_mar) — a sub-quarter date range maps to whole quarters.',
				'location_split'    => 'You CAN answer Oregon-vs-Arkansas questions. For each animator and finished good: prior_year_oregon = the subset of prior_year_sales that was FULFILLED from the Oregon Warehouse Shopify location (the rest — Arkansas + POS/tradeshows + online shipped elsewhere — is prior_year_sales − prior_year_oregon). in_stock_oregon = finished units currently sitting AT the Oregon Warehouse (null = the SKU was not found in Shopify location inventory). Use these for "how much do I need in Oregon" / "what to ship to Oregon" questions: Oregon demand for a SKU = prior_year_oregon for the season(s) the date spans; units short at Oregon = max(0, Oregon demand − in_stock_oregon). To express that shortfall as RAW MATERIALS to ship/build, multiply the short units by the animator\'s bom qty_per_unit (and one packaging card per unit). NOTE: prior_year_oregon is fulfillment-location based, not the customer\'s shipping state; it is the best available proxy for "Oregon orders". oregon_split_status reports if this data loaded.',
			],
			'open_wholesale_orders' => $wholesaleOrders,
			'sales_by_channel'  => $priorChannel,
			'seasons'        => $seasons,
			'animators'      => $animators,
			'raw_materials'  => $rawMaterials,
			'finished_goods' => $finishedGoods,
			'finished_good_groups' => array_values($fgGroups),
			'tradeshow_prior_year_jul_aug' => $tradeshow,
		];
	}

	/**
	 * Finished-product (animator) BUILD recommendations so Shopify inventory
	 * never runs out. Demand = prior-year same-window retail (+ open wholesale
	 * drafts for the non-Oregon view); covered = Shopify FP stock + in-pipeline
	 * builds. recommend = max(0, demand - covered). Shared by ajax/build/recommend.php
	 * and the dashboard briefing. $whId = 0 → all warehouses (not Oregon-specific).
	 */
	function fp_build_plan($db, $until, $whId = 0) {
		if (!shopify_is_configured()) return ['error' => 'Shopify is not connected.', 'meta' => [], 'rows' => []];
		$today = date('Y-m-d');
		$ts    = strtotime($until);
		if (!$ts) return ['error' => 'Bad target date.', 'meta' => [], 'rows' => []];
		$until = date('Y-m-d', $ts);
		$windowDays = max(1, (int)round(($ts - strtotime($today)) / 86400));

		$whRow    = $whId ? $db->query("SELECT name FROM warehouses WHERE id = " . (int)$whId)->fetch() : null;
		$whName   = $whRow['name'] ?? '';
		$isOregon = stripos($whName, 'oregon') !== false;

		$lyStart = date('Y-m-d', strtotime('-1 year', strtotime($today)));
		$lyEnd   = date('Y-m-d', strtotime('-1 year', $ts));

		$sales = shopify_cache_remember($db, 'rec_sales_'.$lyStart.'_'.$lyEnd, inventory_cache_ttl($db), fn() => shopify_sales_by_location($lyStart, $lyEnd))['data'];
		if (!empty($sales['error'])) return ['error' => $sales['error'], 'meta' => [], 'rows' => []];
		$retailBySku = $isOregon ? ($sales['by_sku_oregon'] ?? []) : ($sales['by_sku_rest'] ?? []);

		// Demand now uses Shopify "committed" (already-sold, unfulfilled) rather than open
		// draft orders; committed comes from the finished-product location data below.
		$draftErr = null; $draftOrders = 0;

		$fpLoc   = shopify_cache_remember($db, 'rec_fp', inventory_cache_ttl($db), fn() => shopify_fp_by_location())['data'];
		$fpBySku = $fpLoc['skus'] ?? [];

		$hasSku   = column_exists($db, 'products', 'shopify_sku');
		$cols     = "id, name" . ($hasSku ? ", shopify_sku" : "");
		$products = $db->query("SELECT $cols FROM products ORDER BY name ASC")->fetchAll();

		$bomByProd = [];
		foreach ($db->query("SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.qoh
		                     FROM build b JOIN parts p ON p.id = b.partid") as $bl) {
			$bomByProd[$bl['prodid']][] = $bl;
		}

		$pipelineByProd = [];
		$pipeWh = $whId ? " AND warehouse_id = " . (int)$whId : "";
		foreach ($db->query("SELECT prodid, SUM(qty) AS v FROM intransit
		                     WHERE recdate = '0000-00-00 00:00:00' $pipeWh GROUP BY prodid") as $r) {
			$pipelineByProd[$r['prodid']] = max(0, (int)$r['v']);
		}

		$rows = [];
		foreach ($products as $p) {
			$bom = $bomByProd[$p['id']] ?? [];
			if (empty($bom)) continue;                        // only animators (have raw-material BOM)
			if (is_amazon_product($p['name'])) continue;      // [Amazon] twins are built to order for a PO, not recommended for stock
			$sku = $hasSku ? trim((string)($p['shopify_sku'] ?? '')) : '';

			$retail    = $sku !== '' ? (int)($retailBySku[$sku] ?? 0) : 0;
			$fp        = ($sku !== '' && isset($fpBySku[$sku])) ? $fpBySku[$sku] : [];
			$available = (int)($isOregon ? ($fp['oregon'] ?? 0) : ($fp['rest'] ?? 0));            // sellable (on_hand - committed); may be negative
			$committed = (int)($isOregon ? ($fp['oregon_committed'] ?? 0) : ($fp['rest_committed'] ?? 0));
			$onHand    = (int)($isOregon ? ($fp['oregon_on_hand'] ?? 0) : ($fp['rest_on_hand'] ?? 0));
			$demand    = $retail + $committed;                                                    // last-year sales + already-committed
			$fpStock   = $available;                                                              // kept = available for existing consumers
			$pipeline  = (int)($pipelineByProd[$p['id']] ?? 0);
			$draft     = 0;

			$recommend = max(0, $demand - $onHand - $pipeline);

			$buildable = null; $limitPart = null;
			foreach ($bom as $b) {
				$need = (int)$b['qty']; if ($need <= 0) continue;
				$onhand = $whId ? (int)wh_get_qty($db, (int)$b['partid'], $whId) : (int)$b['qoh'];
				$can    = intdiv(max(0, $onhand), $need);
				if ($buildable === null || $can < $buildable) { $buildable = $can; $limitPart = $b['partno']; }
			}
			$buildable = $buildable === null ? 0 : $buildable;

			if ($demand <= 0 && $recommend <= 0) continue;

			$rows[] = [
				'prodid'     => (int)$p['id'],
				'product'    => $p['name'],
				'sku'        => $sku,
				'retail'     => $retail,
				'committed'  => $committed,
				'draft'      => $draft,
				'demand'     => $demand,
				'fp_stock'   => $fpStock,
				'fp_on_hand' => $onHand,
				'pipeline'   => $pipeline,
				'recommend'  => $recommend,
				'buildable'  => $buildable,
				'limit_part' => $limitPart,
				'short'      => max(0, $recommend - $buildable),
			];
		}
		usort($rows, fn($a, $b) => $b['recommend'] <=> $a['recommend']);

		return [
			'error' => null,
			'meta'  => [
				'today'        => $today,
				'until'        => $until,
				'window_days'  => $windowDays,
				'prior_window' => "$lyStart to $lyEnd",
				'warehouse'    => $whName ?: 'All',
				'is_oregon'    => $isOregon,
				'scope'        => (empty($sales['oregon_location'])
					? '⚠ The Shopify app is missing the "read_locations" permission, so it cannot split by warehouse yet — add that scope (Dev Dashboard → Versions), reinstall, and Save on Integrations. '
					: '') . ($isOregon
					? 'Oregon Warehouse: only orders fulfilled from Oregon.'
					: ($whName ? $whName . ': everything NOT fulfilled from Oregon (incl. POS/tradeshows + open wholesale drafts).' : 'All warehouses.')),
				'draft_orders' => $draftOrders,
				'draft_error'  => $draftErr,
				'oregon_location' => $sales['oregon_location'] ?? '',
			],
			'rows'  => $rows,
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


	/**
	 * Live "what to build & pack" prep for one or more named tradeshows.
	 * For the given show names, sums last year's per-SKU units sold at those
	 * shows (each show is a Shopify POS location), then cross-references current
	 * finished-product stock + buildability from fp_build_plan(). Cached Shopify
	 * calls keep it cheap on repeat loads.
	 *
	 * @param array  $showNames  show names as stored on the task (match tradeshow_locations names)
	 * @param string $refDate    reference date (task due date, else today) — anchors the prior-year window
	 * @return array ['error'=>null|str,'shows'=>[names],'window'=>[since,until],'rows'=>[...]]
	 */
	function fp_show_prep($db, array $showNames, $refDate = null) {
		if (!shopify_is_configured()) return ['error' => 'Shopify is not connected.', 'shows' => $showNames, 'window' => [], 'rows' => []];
		$refTs = $refDate ? strtotime($refDate) : time();
		if (!$refTs) $refTs = time();

		// Prior-year window centered on the reference date (±90d captures the show,
		// which only sells while it is running).
		$since = date('Y-m-d', strtotime('-1 year -90 days', $refTs));
		$until = date('Y-m-d', strtotime('-1 year +90 days', $refTs));

		$wanted = [];
		foreach ($showNames as $n) { $k = strtolower(trim((string)$n)); if ($k !== '') $wanted[$k] = true; }
		if (empty($wanted)) return ['error' => null, 'shows' => [], 'window' => [$since, $until], 'rows' => []];
		$ttl = inventory_cache_ttl($db);

		$bySku = []; $titles = []; $matched = []; $err = null;
		foreach (tradeshow_locations() as $loc) {
			$key = strtolower(trim((string)($loc['name'] ?? '')));
			if (!isset($wanted[$key])) continue;
			$matched[] = $loc['name'];
			$ids = isset($loc['ids']) ? $loc['ids'] : (isset($loc['id']) ? [$loc['id']] : []);
			foreach ($ids as $id) {
				$r = shopify_cache_remember($db, "showsales_{$id}_{$since}_{$until}", $ttl, fn() => shopify_show_sales($id, $since, $until))['data'];
				if (!empty($r['error'])) { $err = $r['error']; continue; }
				foreach (($r['by_sku'] ?? []) as $sku => $u) $bySku[$sku] = ($bySku[$sku] ?? 0) + (int)$u;
				foreach (($r['titles'] ?? []) as $sku => $t) if (empty($titles[$sku])) $titles[$sku] = $t;
			}
		}

		// Current finished-product stock + buildability, indexed by SKU.
		$planBySku = [];
		try {
			$plan = fp_build_plan($db, date('Y-m-d', $refTs));
			if (empty($plan['error'])) foreach ($plan['rows'] as $pr) { if (!empty($pr['sku'])) $planBySku[$pr['sku']] = $pr; }
		} catch (Throwable $e) {}

		$rows = [];
		foreach ($bySku as $sku => $soldLy) {
			if ($soldLy <= 0) continue;
			$pr        = $planBySku[$sku] ?? null;
			$onHand    = $pr ? (int)$pr['fp_stock'] : null;    // null = unknown (SKU not in build plan)
			$buildPack = $onHand === null ? (int)$soldLy : max(0, (int)$soldLy - $onHand);
			$canNow    = $pr ? (int)$pr['buildable'] : null;
			$shortRaw  = ($canNow === null) ? null : max(0, $buildPack - $canNow);
			$rows[] = [
				'product'        => $pr['product'] ?? ($titles[$sku] ?? $sku),
				'sku'            => $sku,
				'sold_last_year' => (int)$soldLy,
				'on_hand'        => $onHand,
				'build_pack'     => $buildPack,
				'can_build_now'  => $canNow,
				'short_raw'      => $shortRaw,
				'limit_part'     => $pr['limit_part'] ?? null,
			];
		}
		usort($rows, fn($a, $b) => $b['build_pack'] <=> $a['build_pack']);

		return ['error' => $err, 'shows' => $matched ?: $showNames, 'window' => [$since, $until], 'rows' => $rows];
	}

	/**
	 * Per-product demand COMPONENTS for the interactive Recommend panel — no drafts.
	 * Returns each animator's demand broken into: online sales, per-tradeshow sales,
	 * Shopify committed units, plus on-hand, pipeline and buildable-now. The browser
	 * sums these under a filter state (exclude shows / online-only / drop committed)
	 * to compute Demand and Build = max(0, demand - on_hand - pipeline).
	 */
	function fp_demand_components($db, $until, $whId = 0) {
		if (!shopify_is_configured()) return ['error' => 'Shopify is not connected.', 'meta' => [], 'rows' => []];
		$today = date('Y-m-d');
		$ts    = strtotime($until);
		if (!$ts) return ['error' => 'Bad target date.', 'meta' => [], 'rows' => []];
		$until = date('Y-m-d', $ts);
		$windowDays = max(1, (int)round(($ts - strtotime($today)) / 86400));

		$whRow    = $whId ? $db->query("SELECT name FROM warehouses WHERE id = " . (int)$whId)->fetch() : null;
		$whName   = $whRow['name'] ?? '';
		$isOregon = stripos($whName, 'oregon') !== false;

		$lyStart = date('Y-m-d', strtotime('-1 year', strtotime($today)));
		$lyEnd   = date('Y-m-d', strtotime('-1 year', $ts));
		$ttl     = inventory_cache_ttl($db);

		// Prior-year retail (all channels) bucketed by warehouse.
		$sales = shopify_cache_remember($db, 'rec_sales_'.$lyStart.'_'.$lyEnd, $ttl, fn() => shopify_sales_by_location($lyStart, $lyEnd))['data'];
		if (!empty($sales['error'])) return ['error' => $sales['error'], 'meta' => [], 'rows' => []];
		$retailBySku = $isOregon ? ($sales['by_sku_oregon'] ?? []) : ($sales['by_sku_rest'] ?? []);

		// Large one-off POs (fulfilled, >= threshold) in the same window last year —
		// isolated so a PO that recurs this year (as committed) isn't double-counted.
		$largePoMin = 2000;
		$large      = shopify_cache_remember($db, 'rec_largepo_'.$lyStart.'_'.$lyEnd.'_'.$largePoMin, $ttl, fn() => shopify_large_orders($lyStart, $lyEnd, $largePoMin))['data'];
		$largeBySku = $isOregon ? ($large['by_sku_oregon'] ?? []) : ($large['by_sku_rest'] ?? []);

		// Per-show sales for the prior window: one call per show (returns all SKUs).
		$showsBySku = []; $showNames = [];
		foreach (tradeshow_locations() as $loc) {
			$name = trim((string)($loc['name'] ?? '')); if ($name === '') continue;
			$ids  = isset($loc['ids']) ? $loc['ids'] : (isset($loc['id']) ? [$loc['id']] : []);
			$any  = false;
			foreach ($ids as $lid) {
				$r = shopify_cache_remember($db, "showsales_{$lid}_{$lyStart}_{$lyEnd}", $ttl, fn() => shopify_show_sales($lid, $lyStart, $lyEnd))['data'];
				foreach (($r['by_sku'] ?? []) as $sku => $u) {
					$u = (int)$u; if ($u <= 0) continue;
					$showsBySku[$sku][$name] = ($showsBySku[$sku][$name] ?? 0) + $u; $any = true;
				}
			}
			if ($any) $showNames[$name] = true;
		}

		// Finished-product committed / on-hand by SKU (bucketed).
		$fpLoc   = shopify_cache_remember($db, 'rec_fp', $ttl, fn() => shopify_fp_by_location())['data'];
		$fpBySku = $fpLoc['skus'] ?? [];

		$hasSku   = column_exists($db, 'products', 'shopify_sku');
		$cols     = "id, name" . ($hasSku ? ", shopify_sku" : "");
		$products = $db->query("SELECT $cols FROM products ORDER BY name ASC")->fetchAll();

		$bomByProd = [];
		foreach ($db->query("SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.qoh FROM build b JOIN parts p ON p.id = b.partid") as $bl) {
			$bomByProd[$bl['prodid']][] = $bl;
		}

		$pipelineByProd = [];
		$pipeWh = $whId ? " AND warehouse_id = " . (int)$whId : "";
		foreach ($db->query("SELECT prodid, SUM(qty) AS v FROM intransit WHERE recdate = '0000-00-00 00:00:00' $pipeWh GROUP BY prodid") as $r) {
			$pipelineByProd[$r['prodid']] = max(0, (int)$r['v']);
		}

		$rows = [];
		foreach ($products as $p) {
			$bom = $bomByProd[$p['id']] ?? [];
			if (empty($bom)) continue;
			if (is_amazon_product($p['name'])) continue;      // [Amazon] twins share the base SKU — skip so demand isn't doubled
			$sku = $hasSku ? trim((string)($p['shopify_sku'] ?? '')) : '';

			$retail    = $sku !== '' ? (int)($retailBySku[$sku] ?? 0) : 0;
			$largePo   = $sku !== '' ? (int)($largeBySku[$sku] ?? 0) : 0;
			$shows     = ($sku !== '' && isset($showsBySku[$sku])) ? $showsBySku[$sku] : [];
			$showTotal = array_sum($shows);
			$online    = max(0, $retail - $showTotal - $largePo);

			$fp        = ($sku !== '' && isset($fpBySku[$sku])) ? $fpBySku[$sku] : [];
			$committed = (int)($isOregon ? ($fp['oregon_committed'] ?? 0) : ($fp['rest_committed'] ?? 0));
			$onHand    = (int)($isOregon ? ($fp['oregon_on_hand'] ?? 0) : ($fp['rest_on_hand'] ?? 0));
			$pipeline  = (int)($pipelineByProd[$p['id']] ?? 0);

			$buildable = null; $limitPart = null;
			foreach ($bom as $b) {
				$need = (int)$b['qty']; if ($need <= 0) continue;
				$onh  = $whId ? (int)wh_get_qty($db, (int)$b['partid'], $whId) : (int)$b['qoh'];
				$can  = intdiv(max(0, $onh), $need);
				if ($buildable === null || $can < $buildable) { $buildable = $can; $limitPart = $b['partno']; }
			}
			$buildable = $buildable === null ? 0 : $buildable;

			if ($retail <= 0 && $committed <= 0) continue;   // no demand signal at all

			$rows[] = [
				'prodid' => (int)$p['id'], 'product' => $p['name'], 'sku' => $sku,
				'online' => $online, 'shows' => (object)$shows, 'large_po' => $largePo,
				'committed' => $committed, 'on_hand' => $onHand, 'pipeline' => $pipeline,
				'buildable' => $buildable, 'limit_part' => $limitPart,
			];
		}

		return [
			'error' => null,
			'meta'  => [
				'today' => $today, 'until' => $until, 'window_days' => $windowDays,
				'prior_window' => "$lyStart to $lyEnd",
				'warehouse' => $whName ?: 'All',
				'shows' => array_keys($showNames),
				'large_po_orders' => $large['orders'] ?? [],
				'large_po_threshold' => $largePoMin,
			],
			'rows' => $rows,
		];
	}
