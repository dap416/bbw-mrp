<?php
/* ============================================================
   SHOPIFY ORDERS — nightly line-level import.

   Shopify is the MASTER for sales. This pulls orders and their
   line items into shop_orders + shop_order_lines.

   Same design as includes/qb_expenses.php: every night re-pull a
   rolling 90-day window, upsert it, and soft-delete anything in
   that window we didn't see. The missing rows ARE the delete
   signal, and a failed run self-heals because the next run redoes
   the identical window with nothing to reconcile.

   Keyed on Shopify's updated_at rather than created_at, because
   orders get edited and refunded long after they are placed.

   Ingest only. Nothing is filtered, bundled, relabelled or rolled
   up on the way in — test and cancelled orders are stored with
   their flags set, and `channel` holds the raw sourceName. What to
   exclude is a query concern; deciding it here would bake one
   reading of the data into every future consumer.

   Uses the existing shopify_graphql() client (which already retries
   THROTTLED with backoff). There is no HTTP or auth code here.
   ============================================================ */

require_once __DIR__ . '/fns.php';
require_once __DIR__ . '/shopify.php';

/** How far back each nightly run re-pulls. */
const SHOP_ORDERS_WINDOW_DAYS = 90;

/**
 * Orders per page. Deliberately not 250: cost is charged for the whole nested
 * shape, so 250 orders x 100 line items x refunds blows the query-cost ceiling
 * and spends the run getting throttled. 50 pages cleanly.
 */
const SHOP_ORDERS_PAGE = 50;

/** Runaway guard only — a real backfill is a few hundred pages. */
const SHOP_ORDERS_MAX_PAGES = 2000;

/* ---- schema ------------------------------------------------------------ */

function ensure_shop_orders_tables($db) {
	$db->exec("CREATE TABLE IF NOT EXISTS shop_orders (
		id                BIGINT AUTO_INCREMENT PRIMARY KEY,
		shop_order_id     VARCHAR(40)  NOT NULL,   -- Shopify GID numeric part
		name              VARCHAR(40)  NULL,       -- '#13988'
		created_at        DATETIME     NULL,
		processed_at      DATETIME     NULL,
		updated_at_shop   DATETIME     NULL,
		customer_id       VARCHAR(40)  NULL,
		customer_name     VARCHAR(200) NULL,
		email             VARCHAR(200) NULL,
		channel           VARCHAR(80)  NULL,       -- raw sourceName, unmapped
		location_id       VARCHAR(40)  NULL,
		location_name     VARCHAR(160) NULL,
		financial_status  VARCHAR(30)  NULL,       -- paid | pending | refunded | partially_refunded
		fulfillment_status VARCHAR(30) NULL,
		currency          VARCHAR(8)   NULL,
		subtotal          DECIMAL(14,2) NOT NULL DEFAULT 0,
		discounts         DECIMAL(14,2) NOT NULL DEFAULT 0,
		shipping          DECIMAL(14,2) NOT NULL DEFAULT 0,
		tax               DECIMAL(14,2) NOT NULL DEFAULT 0,
		total             DECIMAL(14,2) NOT NULL DEFAULT 0,
		refunded          DECIMAL(14,2) NOT NULL DEFAULT 0,
		net               DECIMAL(14,2) NOT NULL DEFAULT 0,   -- total - refunded
		test              TINYINT NOT NULL DEFAULT 0,
		cancelled_at      DATETIME NULL,
		deleted_at        DATETIME NULL,
		raw_json          LONGTEXT NULL,
		synced_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY uq_order (shop_order_id),
		KEY idx_created (created_at),
		KEY idx_customer (customer_name),
		KEY idx_channel (channel)
	) ENGINE=InnoDB");

	$db->exec("CREATE TABLE IF NOT EXISTS shop_order_lines (
		id             BIGINT AUTO_INCREMENT PRIMARY KEY,
		order_id       BIGINT NOT NULL,
		line_id        VARCHAR(40) NULL,
		sku            VARCHAR(80)  NULL,
		product_id     VARCHAR(40)  NULL,
		variant_id     VARCHAR(40)  NULL,
		title          VARCHAR(300) NULL,
		variant_title  VARCHAR(200) NULL,
		qty            INT NOT NULL DEFAULT 0,
		qty_refunded   INT NOT NULL DEFAULT 0,
		unit_price     DECIMAL(14,4) NOT NULL DEFAULT 0,
		discount       DECIMAL(14,2) NOT NULL DEFAULT 0,
		line_total     DECIMAL(14,2) NOT NULL DEFAULT 0,
		CONSTRAINT fk_shopline FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
		KEY idx_order (order_id),
		KEY idx_sku (sku)
	) ENGINE=InnoDB");
}

/* ---- helpers ----------------------------------------------------------- */

/** 'gid://shopify/Order/12345' -> '12345'. Non-GIDs pass through unchanged. */
function shop_gid_num($gid) {
	$g = (string)$gid;
	if ($g === '') return null;
	$tail = strrchr($g, '/');
	$num  = $tail !== false ? substr($tail, 1) : $g;
	return $num !== '' ? $num : null;
}

/** Shopify returns ISO8601; store plain DATETIME like the QB importer does. */
function shop_dt($v) {
	$v = trim((string)$v);
	if ($v === '') return null;
	$ts = strtotime($v);
	return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function shop_money($set) {
	return round((float)($set['shopMoney']['amount'] ?? 0), 2);
}

/* ---- query ------------------------------------------------------------- */

/**
 * The order query. $locationField is spliced in so the caller can retry without
 * it — Order.retailLocation needs read_locations and a recent API version, and
 * the rest of the import is worth having without it (same fallback the demand
 * report uses, includes/shopify.php).
 */
function shop_orders_query($locationField = 'retailLocation { id name }') {
	return '
	query($cursor: String, $q: String!) {
	  orders(first: ' . SHOP_ORDERS_PAGE . ', after: $cursor, query: $q, sortKey: UPDATED_AT) {
	    pageInfo { hasNextPage endCursor }
	    edges { node {
	      id
	      name
	      createdAt
	      processedAt
	      updatedAt
	      cancelledAt
	      test
	      email
	      sourceName
	      currencyCode
	      displayFinancialStatus
	      displayFulfillmentStatus
	      customer { id displayName }
	      ' . $locationField . '
	      subtotalPriceSet { shopMoney { amount } }
	      totalDiscountsSet { shopMoney { amount } }
	      totalShippingPriceSet { shopMoney { amount } }
	      totalTaxSet { shopMoney { amount } }
	      currentTotalPriceSet { shopMoney { amount } }
	      totalRefundedSet { shopMoney { amount } }
	      lineItems(first: 100) {
	        pageInfo { hasNextPage }
	        edges { node {
	          id
	          sku
	          title
	          variantTitle
	          quantity
	          product { id }
	          variant { id }
	          originalUnitPriceSet { shopMoney { amount } }
	          totalDiscountSet { shopMoney { amount } }
	          discountedTotalSet { shopMoney { amount } }
	        } }
	      }
	      refunds(first: 20) {
	        refundLineItems(first: 100) {
	          edges { node { quantity lineItem { id } } }
	        }
	      }
	    } }
	  }
	}';
}

/* ---- upsert ------------------------------------------------------------ */

/**
 * Write one order (header + lines) in a single transaction. Lines are deleted
 * and re-inserted rather than matched, because Shopify reissues line ids when an
 * order is edited. Returns 'insert' or 'update'.
 */
function shop_order_upsert($db, array $n) {
	$orderId = shop_gid_num($n['id'] ?? '');
	if ($orderId === null) return null;

	$total    = shop_money($n['currentTotalPriceSet'] ?? []);
	$refunded = shop_money($n['totalRefundedSet'] ?? []);

	$vals = [
		'name'            => $n['name'] ?? null,
		'created_at'      => shop_dt($n['createdAt'] ?? ''),
		'processed_at'    => shop_dt($n['processedAt'] ?? ''),
		'updated_at_shop' => shop_dt($n['updatedAt'] ?? ''),
		'customer_id'     => shop_gid_num($n['customer']['id'] ?? ''),
		'customer_name'   => $n['customer']['displayName'] ?? null,
		'email'           => ($n['email'] ?? '') !== '' ? (string)$n['email'] : null,
		'channel'         => ($n['sourceName'] ?? '') !== '' ? (string)$n['sourceName'] : null,
		'location_id'     => shop_gid_num($n['retailLocation']['id'] ?? ''),
		'location_name'   => $n['retailLocation']['name'] ?? null,
		'financial_status'   => $n['displayFinancialStatus'] ?? null,
		'fulfillment_status' => $n['displayFulfillmentStatus'] ?? null,
		'currency'        => $n['currencyCode'] ?? null,
		'subtotal'        => shop_money($n['subtotalPriceSet'] ?? []),
		'discounts'       => shop_money($n['totalDiscountsSet'] ?? []),
		'shipping'        => shop_money($n['totalShippingPriceSet'] ?? []),
		'tax'             => shop_money($n['totalTaxSet'] ?? []),
		'total'           => $total,
		'refunded'        => $refunded,
		'net'             => round($total - $refunded, 2),
		'test'            => !empty($n['test']) ? 1 : 0,
		'cancelled_at'    => shop_dt($n['cancelledAt'] ?? ''),
		'raw_json'        => json_encode($n),
	];

	$sel = $db->prepare("SELECT id FROM shop_orders WHERE shop_order_id = ?");
	$sel->execute([$orderId]);
	$existingId = $sel->fetchColumn();

	// Refunded units per line id, summed across every refund on the order.
	$refundedQty = [];
	foreach (($n['refunds'] ?? []) as $ref) {
		foreach (($ref['refundLineItems']['edges'] ?? []) as $re) {
			$lid = (string)($re['node']['lineItem']['id'] ?? '');
			if ($lid === '') continue;
			$refundedQty[$lid] = ($refundedQty[$lid] ?? 0) + (int)($re['node']['quantity'] ?? 0);
		}
	}

	$db->beginTransaction();
	try {
		if ($existingId) {
			$rowId = (int)$existingId;
			$db->prepare("UPDATE shop_orders SET
					name=?, created_at=?, processed_at=?, updated_at_shop=?, customer_id=?, customer_name=?,
					email=?, channel=?, location_id=?, location_name=?, financial_status=?, fulfillment_status=?,
					currency=?, subtotal=?, discounts=?, shipping=?, tax=?, total=?, refunded=?, net=?,
					test=?, cancelled_at=?, raw_json=?, deleted_at=NULL, synced_at=NOW()
				WHERE id = ?")
			   ->execute(array_merge(array_values($vals), [$rowId]));
			$action = 'update';
		} else {
			$db->prepare("INSERT INTO shop_orders
					(shop_order_id, name, created_at, processed_at, updated_at_shop, customer_id, customer_name,
					 email, channel, location_id, location_name, financial_status, fulfillment_status,
					 currency, subtotal, discounts, shipping, tax, total, refunded, net,
					 test, cancelled_at, raw_json, deleted_at, synced_at)
				VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NOW())
				ON DUPLICATE KEY UPDATE
					name=VALUES(name), created_at=VALUES(created_at), processed_at=VALUES(processed_at),
					updated_at_shop=VALUES(updated_at_shop), customer_id=VALUES(customer_id),
					customer_name=VALUES(customer_name), email=VALUES(email), channel=VALUES(channel),
					location_id=VALUES(location_id), location_name=VALUES(location_name),
					financial_status=VALUES(financial_status), fulfillment_status=VALUES(fulfillment_status),
					currency=VALUES(currency), subtotal=VALUES(subtotal), discounts=VALUES(discounts),
					shipping=VALUES(shipping), tax=VALUES(tax), total=VALUES(total), refunded=VALUES(refunded),
					net=VALUES(net), test=VALUES(test), cancelled_at=VALUES(cancelled_at),
					raw_json=VALUES(raw_json), deleted_at=NULL, synced_at=NOW()")
			   ->execute(array_merge([$orderId], array_values($vals)));
			$rowId = (int)$db->lastInsertId();
			if (!$rowId) { $sel->execute([$orderId]); $rowId = (int)$sel->fetchColumn(); }
			$action = 'insert';
		}

		$db->prepare("DELETE FROM shop_order_lines WHERE order_id = ?")->execute([$rowId]);
		$insL = $db->prepare("INSERT INTO shop_order_lines
			(order_id, line_id, sku, product_id, variant_id, title, variant_title,
			 qty, qty_refunded, unit_price, discount, line_total)
			VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

		$lines = 0;
		foreach (($n['lineItems']['edges'] ?? []) as $le) {
			$li  = $le['node'];
			$lid = (string)($li['id'] ?? '');
			$insL->execute([
				$rowId,
				shop_gid_num($lid),
				($li['sku'] ?? '') !== '' ? (string)$li['sku'] : null,
				shop_gid_num($li['product']['id'] ?? ''),
				shop_gid_num($li['variant']['id'] ?? ''),
				$li['title'] ?? null,
				($li['variantTitle'] ?? '') !== '' ? (string)$li['variantTitle'] : null,
				(int)($li['quantity'] ?? 0),
				(int)($refundedQty[$lid] ?? 0),
				round((float)($li['originalUnitPriceSet']['shopMoney']['amount'] ?? 0), 4),
				shop_money($li['totalDiscountSet'] ?? []),
				shop_money($li['discountedTotalSet'] ?? []),
			]);
			$lines++;
		}

		$db->commit();
		return ['action' => $action, 'lines' => $lines];
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		throw $e;
	}
}

/* ---- sync -------------------------------------------------------------- */

/**
 * The nightly job. Empty table -> pull all history once; every run after ->
 * rolling 90 days on Shopify's updated_at.
 *
 * Returns ['error'=>, 'window'=>, 'fetched'=>, 'inserted'=>, 'updated'=>,
 *          'deleted'=>, 'lines'=>, 'line_truncated'=>, 'secs'=>].
 */
function shop_orders_sync($db, $windowDays = SHOP_ORDERS_WINDOW_DAYS) {
	$t0  = microtime(true);
	$out = ['error' => null, 'window' => '', 'fetched' => 0, 'inserted' => 0,
	        'updated' => 0, 'deleted' => 0, 'lines' => 0, 'line_truncated' => 0, 'secs' => 0.0];

	if (!shopify_is_configured()) {
		$out['error']  = 'Shopify is not configured.';
		$out['window'] = 'skipped';
		$out['secs']   = round(microtime(true) - $t0, 1);
		return $out;
	}

	ensure_shop_orders_tables($db);

	// The table's emptiness is the flag — no stored state, no cursor to resume.
	$isEmpty = (int)$db->query("SELECT COUNT(*) FROM shop_orders")->fetchColumn() === 0;

	// The API window is one day wider than the sweep window on purpose. Shopify
	// evaluates `updated_at:` in the SHOP's timezone while created_at lands here as
	// a converted timestamp, so an order sitting exactly on the boundary could fall
	// out of the fetch while still being in sweep scope — and get soft-deleted while
	// alive. A day of slack costs one extra page and removes that whole class of bug.
	$sweepCutoff = $isEmpty ? null : date('Y-m-d', strtotime('-' . (int)$windowDays . ' days'));
	$fetchCutoff = $isEmpty ? null : date('Y-m-d', strtotime('-' . ((int)$windowDays + 1) . ' days'));
	$q = $fetchCutoff ? "updated_at:>=$fetchCutoff" : "";
	$out['window'] = $sweepCutoff ? ($sweepCutoff . ' .. today (by updated_at)') : 'all history (first run)';

	$query = shop_orders_query();
	$seen = []; $cursor = null; $pages = 0; $hasNext = false;

	do {
		$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);

		// Order.retailLocation needs read_locations; without it the rest of the
		// import is still worth having, so retry once without that field.
		if (!empty($res['error']) && stripos($res['error'], 'retailLocation') !== false) {
			$query = shop_orders_query('');
			$res   = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
		}
		if (!empty($res['error'])) {                      // ABORT — never let a fetch failure reach the sweep
			$out['error'] = $res['error'];
			$out['secs']  = round(microtime(true) - $t0, 1);
			return $out;
		}
		$o = $res['data']['orders'] ?? null;
		if ($o === null) {
			$out['error'] = 'Malformed Shopify orders response.';
			$out['secs']  = round(microtime(true) - $t0, 1);
			return $out;
		}

		foreach (($o['edges'] ?? []) as $oe) {
			$n = $oe['node'];
			try {
				$r = shop_order_upsert($db, $n);
				if (!$r) continue;
				if ($r['action'] === 'insert') $out['inserted']++; else $out['updated']++;
				$out['lines'] += $r['lines'];
				if (!empty($n['lineItems']['pageInfo']['hasNextPage'])) $out['line_truncated']++;
				$seen[] = shop_gid_num($n['id'] ?? '');
			} catch (Throwable $e) {
				error_log('[Shopify] orders: upsert failed for ' . ($n['id'] ?? '?') . ': ' . $e->getMessage());
				$out['error'] = 'One or more orders failed to save: ' . $e->getMessage();
			}
			$out['fetched']++;
		}

		$cursor  = $o['pageInfo']['endCursor']  ?? null;
		$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
		$pages++;
	} while ($hasNext && $pages < SHOP_ORDERS_MAX_PAGES);

	if ($hasNext) {
		// Say so rather than letting a capped run read as a complete one.
		$out['error'] = 'Stopped at the page cap (' . SHOP_ORDERS_MAX_PAGES . ') with more orders available.';
	}

	// Soft-delete sweep, same three guards as the expense import: a row-level
	// failure leaves $seen incomplete, zero orders back is far likelier to be a bad
	// day at Shopify than an emptied store, and the first run has no cutoff to
	// scope to.
	if (!$out['error'] && $out['fetched'] > 0 && $sweepCutoff !== null) {
		$out['deleted'] = shop_orders_sweep($db, $sweepCutoff, array_filter($seen));
	}

	$out['secs'] = round(microtime(true) - $t0, 1);
	return $out;
}

/**
 * Mark orders created inside the window that Shopify didn't return. Scoped to
 * created_at >= the sweep cutoff — without that, a 90-day pull would flag every
 * historical order as deleted.
 *
 * Diffs in PHP rather than emitting one enormous NOT IN, so a full-history
 * backfill can't build an unbounded statement.
 */
function shop_orders_sweep($db, $cutoff, array $seen) {
	$s = $db->prepare("SELECT shop_order_id FROM shop_orders
		WHERE deleted_at IS NULL AND created_at >= ?");
	$s->execute([$cutoff]);
	$have = $s->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

	$missing = array_values(array_diff($have, $seen));
	if (!$missing) return 0;

	$n = 0;
	foreach (array_chunk($missing, 500) as $chunk) {
		$in = implode(',', array_fill(0, count($chunk), '?'));
		$u  = $db->prepare("UPDATE shop_orders SET deleted_at = NOW()
			WHERE deleted_at IS NULL AND created_at >= ? AND shop_order_id IN ($in)");
		$u->execute(array_merge([$cutoff], $chunk));
		$n += $u->rowCount();
	}
	return $n;
}
