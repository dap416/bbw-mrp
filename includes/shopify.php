<?php

	require_once(__DIR__."/fns.php");

	/**
	 * Lightweight Shopify Admin API (GraphQL) client.
	 * Credentials live in includes/config.local.php under the 'shopify' key.
	 * See includes/config.local.example.php for setup instructions.
	 */

	/** Read a value from the key-value settings table (safe if table is missing). */
	function setting_get($db, $key, $default = null) {
		try {
			$stmt = $db->prepare("SELECT sval FROM settings WHERE skey = ?");
			$stmt->execute([$key]);
			$r = $stmt->fetch();
			return ($r && $r['sval'] !== null) ? $r['sval'] : $default;
		} catch (Throwable $e) { return $default; }
	}

	/** Upsert a value into the settings table. */
	function setting_set($db, $key, $val) {
		$stmt = $db->prepare("INSERT INTO settings (skey, sval, updated_at) VALUES (?, ?, NOW())
		                      ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_at = NOW()");
		$stmt->execute([$key, $val]);
	}

	/**
	 * Effective Shopify credentials. Values saved in the app (settings table)
	 * take precedence; anything in config.local.php is used as a fallback.
	 * Cached per request so repeated API calls don't re-query the DB.
	 */
	function shopify_config() {
		static $cfg = null;
		if ($cfg !== null) return $cfg;

		$cfg = app_config('shopify') ?: [];
		try {
			$db = db_connect();
			if ($db) {
				$map = [
					'shopify_domain'        => 'domain',
					'shopify_api_version'   => 'api_version',
					'shopify_client_id'     => 'client_id',
					'shopify_client_secret' => 'client_secret',
					'shopify_token'         => 'token',          // legacy/static or cached
					'shopify_token_expires' => 'token_expires',  // cache expiry (unix)
				];
				foreach ($map as $key => $field) {
					$val = setting_get($db, $key);
					if ($val !== null && $val !== '') $cfg[$field] = $val;
				}
			}
		} catch (Throwable $e) { /* settings table not migrated yet — use file config */ }

		return $cfg;
	}

	/** True when we have a domain plus either Client ID/secret or a static token. */
	function shopify_is_configured() {
		$c = shopify_config();
		if (empty($c['domain']) || strpos($c['domain'], 'your-store') !== false) return false;

		$hasClient = !empty($c['client_id']) && !empty($c['client_secret'])
			&& strpos((string)$c['client_secret'], 'CHANGE_ME') === false;
		$hasStatic = !empty($c['token']) && strpos((string)$c['token'], 'CHANGE_ME') === false;

		return $hasClient || $hasStatic;
	}

	/**
	 * Exchange Client ID/secret for a short-lived Admin API token
	 * (Dev Dashboard "client credentials" grant). Returns
	 * ['token' => ..., 'expires' => unix] or ['error' => ...].
	 */
	function shopify_fetch_token($c) {
		$url  = "https://{$c['domain']}/admin/oauth/access_token";
		$post = http_build_query([
			'grant_type'    => 'client_credentials',
			'client_id'     => $c['client_id'],
			'client_secret' => $c['client_secret'],
		]);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $post,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
			CURLOPT_TIMEOUT        => 30,
		]);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($body === false) return ['error' => 'Could not reach Shopify: ' . $err];

		$j = json_decode($body, true);
		if ($code === 401 || $code === 403) {
			return ['error' => 'Shopify rejected the Client ID/secret (HTTP ' . $code . '). Check the credentials, and make sure the app is installed on this store and in the same organization.'];
		}
		if (!is_array($j) || empty($j['access_token'])) {
			$msg = (is_array($j) && !empty($j['error_description'])) ? $j['error_description']
				 : ((is_array($j) && !empty($j['error'])) ? $j['error'] : 'Unexpected token response (HTTP ' . $code . ').');
			return ['error' => 'Could not get an access token: ' . $msg];
		}

		return ['token' => $j['access_token'], 'expires' => time() + (int)($j['expires_in'] ?? 86399)];
	}

	/**
	 * Resolve a usable Admin API access token. Prefers a cached client-credentials
	 * token, refreshing it when expired; falls back to a static token. Returns
	 * ['token' => string] or ['error' => string].
	 */
	function shopify_access_token() {
		static $resolved = null;
		if ($resolved !== null) return $resolved;

		$c = shopify_config();

		// Dev Dashboard apps: client credentials grant.
		if (!empty($c['client_id']) && !empty($c['client_secret']) && !empty($c['domain'])) {
			$cached = $c['token'] ?? '';
			$exp    = (int)($c['token_expires'] ?? 0);
			if ($cached !== '' && $exp > time() + 120) {       // 2-min safety buffer
				return $resolved = ['token' => $cached];
			}

			$fetched = shopify_fetch_token($c);
			if (!empty($fetched['error'])) return $resolved = ['error' => $fetched['error']];

			try {
				$db = db_connect();
				if ($db) {
					setting_set($db, 'shopify_token', $fetched['token']);
					setting_set($db, 'shopify_token_expires', (string)$fetched['expires']);
				}
			} catch (Throwable $e) { /* cache write best-effort */ }

			return $resolved = ['token' => $fetched['token']];
		}

		// Legacy static token (admin-created custom apps).
		if (!empty($c['token'])) return $resolved = ['token' => $c['token']];

		return $resolved = ['error' => 'Shopify is not configured.'];
	}

	/**
	 * Run a GraphQL query against the Admin API.
	 * Returns the decoded JSON on success, or ['error' => '...'] on failure.
	 * Retries automatically when Shopify throttles the request.
	 */
	function shopify_graphql($query, $variables = []) {
		$c = shopify_config();
		if (empty($c['domain'])) {
			return ['error' => 'Shopify is not configured. Add your store domain and credentials on the Integrations page.'];
		}

		$auth = shopify_access_token();
		if (!empty($auth['error'])) return ['error' => $auth['error']];
		$token = $auth['token'];

		$version = $c['api_version'] ?? '2025-01';
		$url     = "https://{$c['domain']}/admin/api/{$version}/graphql.json";
		$payload = json_encode(['query' => $query, 'variables' => (object)$variables]);

		$attempts = 0;
		while (true) {
			$attempts++;

			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'X-Shopify-Access-Token: ' . $token,
				],
				CURLOPT_TIMEOUT        => 30,
			]);
			$body     = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlErr  = curl_error($ch);
			curl_close($ch);

			if ($body === false) {
				return ['error' => 'Could not reach Shopify: ' . $curlErr];
			}
			if ($httpCode === 401) {
				return ['error' => 'Shopify rejected the credentials (HTTP 401). Check the Client ID/secret and that the app is installed on this store.'];
			}
			if ($httpCode === 403) {
				return ['error' => 'Shopify denied access (HTTP 403). Add the read_products and read_inventory scopes to the app (Versions tab), then reinstall it.'];
			}

			$json = json_decode($body, true);
			if (!is_array($json)) {
				return ['error' => 'Unexpected response from Shopify (HTTP ' . $httpCode . ').'];
			}

			if (!empty($json['errors'])) {
				$throttled = false;
				foreach ($json['errors'] as $e) {
					if (($e['extensions']['code'] ?? '') === 'THROTTLED') { $throttled = true; break; }
				}
				if ($throttled && $attempts < 5) {
					usleep(1500000); // back off 1.5s and retry
					continue;
				}
				$msg = $json['errors'][0]['message'] ?? 'GraphQL error';
				return ['error' => 'Shopify GraphQL error: ' . $msg];
			}

			return $json;
		}
	}

	/**
	 * Quick connectivity check. Returns ['ok' => bool, 'name' => string, 'error' => string].
	 */
	function shopify_test_connection() {
		$res = shopify_graphql('{ shop { name myshopifyDomain } }');
		if (!empty($res['error'])) {
			return ['ok' => false, 'name' => '', 'error' => $res['error']];
		}
		$shop = $res['data']['shop'] ?? null;
		if (!$shop) {
			return ['ok' => false, 'name' => '', 'error' => 'Connected, but no shop data returned.'];
		}
		return ['ok' => true, 'name' => $shop['name'] ?? '', 'error' => ''];
	}

	/**
	 * Fetch every product variant with a SKU and its on-hand quantity.
	 * Returns ['error' => null|string, 'skus' => [ SKU => [...] ]].
	 * SKU is the matching key between Shopify and the MRP products table.
	 */
	function shopify_fetch_variants() {
		$query = '
		query($cursor: String) {
		  products(first: 40, after: $cursor) {
		    pageInfo { hasNextPage endCursor }
		    edges {
		      node {
		        id
		        title
		        status
		        variants(first: 100) {
		          edges { node { id title sku inventoryQuantity } }
		        }
		      }
		    }
		  }
		}';

		$bySku  = [];
		$cursor = null;
		$pages  = 0;

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor]);
			if (!empty($res['error'])) {
				return ['error' => $res['error'], 'skus' => []];
			}

			$products = $res['data']['products'] ?? null;
			if ($products === null) {
				return ['error' => 'Malformed Shopify response.', 'skus' => []];
			}

			foreach ($products['edges'] as $pe) {
				$p = $pe['node'];
				foreach ($p['variants']['edges'] as $ve) {
					$v   = $ve['node'];
					$sku = trim((string)($v['sku'] ?? ''));
					if ($sku === '') continue;
					$bySku[$sku] = [
						'sku'           => $sku,
						'product_title' => $p['title'],
						'variant_title' => $v['title'],
						'qty'           => (int)($v['inventoryQuantity'] ?? 0),
						'status'        => $p['status'],
					];
				}
			}

			$cursor  = $products['pageInfo']['endCursor']  ?? null;
			$hasNext = $products['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 20);

		return ['error' => null, 'skus' => $bySku];
	}

	/**
	 * Map of bundle variants to their component SKUs/quantities, so a bundle
	 * sale can be attributed to the real products it contains. Keyed by the
	 * bundle's variant GID. Cached in settings for 24h (definitions rarely
	 * change). Returns [variantId => [['sku'=>..., 'qty'=>...], ...]].
	 */
	function shopify_bundle_map() {
		static $map = null;
		if ($map !== null) return $map;

		$db = null;
		try {
			$db = db_connect();
			if ($db) {
				$cached = setting_get($db, 'shopify_bundle_map');
				$at     = (int)setting_get($db, 'shopify_bundle_map_at', 0);
				if ($cached && (time() - $at) < 86400) {
					$dec = json_decode($cached, true);
					if (is_array($dec)) return $map = $dec;
				}
			}
		} catch (Throwable $e) { /* no cache */ }

		$query = '
		query($cursor: String) {
		  products(first: 50, after: $cursor) {
		    pageInfo { hasNextPage endCursor }
		    edges { node { variants(first: 10) { edges { node {
		      id
		      productVariantComponents(first: 25) { edges { node { quantity productVariant { sku } } } }
		    } } } } }
		  }
		}';

		$out = []; $cursor = null; $pages = 0;
		do {
			$res = shopify_graphql($query, ['cursor' => $cursor]);
			if (!empty($res['error'])) break;
			$p = $res['data']['products'] ?? null;
			if ($p === null) break;
			foreach ($p['edges'] as $pe) {
				foreach ($pe['node']['variants']['edges'] as $ve) {
					$v = $ve['node'];
					$comps = $v['productVariantComponents']['edges'] ?? [];
					if (empty($comps)) continue;
					$list = [];
					foreach ($comps as $ce) {
						$cs = trim((string)($ce['node']['productVariant']['sku'] ?? ''));
						$cq = (int)($ce['node']['quantity'] ?? 0);
						if ($cs !== '' && $cq > 0) $list[] = ['sku' => $cs, 'qty' => $cq];
					}
					if (!empty($list)) $out[$v['id']] = $list;
				}
			}
			$cursor  = $p['pageInfo']['endCursor']  ?? null;
			$hasNext = $p['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 12);

		try {
			if ($db) {
				setting_set($db, 'shopify_bundle_map', json_encode($out));
				setting_set($db, 'shopify_bundle_map_at', (string)time());
			}
		} catch (Throwable $e) { /* best effort */ }

		return $map = $out;
	}

	/**
	 * Aggregate units sold per SKU between two dates (ISO YYYY-MM-DD).
	 * Also tallies units by sales channel so the planner can separate online,
	 * point-of-sale (tradeshows), and wholesale demand. Returns
	 * ['error'=>..., 'by_sku'=>[sku=>qty], 'by_channel'=>[chan=>qty],
	 *  'orders'=>int, 'truncated'=>bool].
	 */
	function shopify_sales_in_range($since, $until) {
		$query = '
		query($cursor: String, $q: String!) {
		  orders(first: 100, after: $cursor, query: $q, sortKey: CREATED_AT) {
		    pageInfo { hasNextPage endCursor }
		    edges {
		      node {
		        createdAt
		        sourceName
		        cancelledAt
		        tags
		        customer { displayName }
		        lineItems(first: 30) {
		          edges { node { quantity sku variant { id } } }
		        }
		      }
		    }
		  }
		}';

		$q = "created_at:>=$since AND created_at:<=$until";
		$bySku = []; $bySkuAmazon = []; $byChannel = []; $cursor = null; $pages = 0; $orders = 0;
		$bundles = shopify_bundle_map();
		$amazonCust = strtoupper(shopify_amazon_customer());
		$amazonTag  = strtolower(shopify_amazon_tag());

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'by_sku' => [], 'by_channel' => []];
			$o = $res['data']['orders'] ?? null;
			if ($o === null) return ['error' => 'Malformed Shopify orders response.', 'by_sku' => [], 'by_channel' => []];

			foreach ($o['edges'] as $oe) {
				$n = $oe['node'];
				if (!empty($n['cancelledAt'])) continue;
				$orders++;
				$chan = shopify_channel_label($n['sourceName'] ?? '');
				$tags = array_map(fn($t) => strtolower(trim($t)), $n['tags'] ?? []);
				$isAmazon = ($amazonCust !== '' && strtoupper(trim((string)($n['customer']['displayName'] ?? ''))) === $amazonCust)
				         || ($amazonTag !== '' && in_array($amazonTag, $tags, true));
				foreach ($n['lineItems']['edges'] as $le) {
					$li  = $le['node'];
					$sku = trim((string)($li['sku'] ?? ''));
					$qty = (int)($li['quantity'] ?? 0);
					if ($qty <= 0) continue;

					// Bundle (no SKU but known components) → attribute to each component
					$vid = $li['variant']['id'] ?? '';
					if ($sku === '' && $vid && isset($bundles[$vid])) {
						foreach ($bundles[$vid] as $c) {
							$add = $qty * (int)$c['qty'];
							$bySku[$c['sku']]      = ($bySku[$c['sku']] ?? 0) + $add;
							$byChannel[$chan]      = ($byChannel[$chan] ?? 0) + $add;
							if ($isAmazon) $bySkuAmazon[$c['sku']] = ($bySkuAmazon[$c['sku']] ?? 0) + $add;
						}
						continue;
					}
					if ($sku === '') continue;
					$bySku[$sku]      = ($bySku[$sku] ?? 0) + $qty;
					$byChannel[$chan] = ($byChannel[$chan] ?? 0) + $qty;
					if ($isAmazon) $bySkuAmazon[$sku] = ($bySkuAmazon[$sku] ?? 0) + $qty;
				}
			}

			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		return [
			'error'         => null,
			'by_sku'        => $bySku,
			'by_sku_amazon' => $bySkuAmazon,   // subset sold to the Amazon/CDA customer (e.g. TJ STUMPF)
			'by_channel'    => $byChannel,
			'orders'        => $orders,
			'truncated'     => ($hasNext && $pages >= 40),
		];
	}

	/**
	 * Net sales (after discounts, EXCLUDING tax & shipping) in a date range,
	 * grouped by calendar month, by order CREATED date. Returns
	 * ['error'=>..., 'total'=>float, 'by_month'=>['YYYY-MM'=>amount]].
	 * Excludes cancelled orders. NOTE: this is a SALES figure on the order date —
	 * it is NOT cash received (a Net-60 order shows here on the order date, not
	 * when paid). For actual cash, compare against qb_monthly_income().
	 */
	function shopify_revenue_in_range($since, $until) {
		$query = '
		query($cursor: String, $q: String!) {
		  orders(first: 100, after: $cursor, query: $q, sortKey: CREATED_AT) {
		    pageInfo { hasNextPage endCursor }
		    edges { node {
		      createdAt
		      cancelledAt
		      currentSubtotalPriceSet { shopMoney { amount } }
		    } }
		  }
		}';

		$q = "created_at:>=$since AND created_at:<=$until";
		$total = 0.0; $byMonth = []; $cursor = null; $pages = 0; $orders = 0;

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'total' => 0, 'by_month' => []];
			$o = $res['data']['orders'] ?? null;
			if ($o === null) return ['error' => 'Malformed Shopify orders response.', 'total' => 0, 'by_month' => []];

			foreach ($o['edges'] as $oe) {
				$n = $oe['node'];
				if (!empty($n['cancelledAt'])) continue;
				$amt = (float)($n['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0);
				$ym  = substr((string)($n['createdAt'] ?? ''), 0, 7);
				$total += $amt;
				if ($ym !== '') $byMonth[$ym] = ($byMonth[$ym] ?? 0) + $amt;
				$orders++;
			}

			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		return ['error' => null, 'total' => $total, 'by_month' => $byMonth, 'orders' => $orders, 'truncated' => ($hasNext && $pages >= 40)];
	}

	/**
	 * Money owed to you on Shopify right now (accounts receivable):
	 *   - open / invoice-sent DRAFT orders (quotes/invoices not yet paid), and
	 *   - ORDERS with an unpaid balance (pending / partially paid / authorized).
	 * A draft becomes a real order once converted, so the two buckets don't
	 * overlap (completed drafts are excluded). Returns
	 * ['error'=>..., 'total'=>float, 'items'=>[ {name,customer,amount,date,type} ]].
	 *
	 * IMPORTANT: this is NOT income — it's expected future cash. It is shown
	 * separately and must never be added into the cash-in projection (which is
	 * cash-basis QuickBooks income), or receipts would be counted twice.
	 */
	function shopify_open_receivables() {
		$items = []; $total = 0.0;

		// ── Open draft orders (unpaid invoices/quotes) ──
		$dq = '
		query($cursor: String) {
		  draftOrders(first: 100, after: $cursor, query: "status:open OR status:invoice_sent") {
		    pageInfo { hasNextPage endCursor }
		    edges { node { name createdAt status totalPriceSet { shopMoney { amount } } customer { displayName } } }
		  }
		}';
		$cursor = null; $pages = 0;
		do {
			$res = shopify_graphql($dq, ['cursor' => $cursor]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'total' => 0, 'items' => []];
			$d = $res['data']['draftOrders'] ?? null;
			if ($d === null) break;
			foreach ($d['edges'] as $e) {
				$n = $e['node'];
				if (($n['status'] ?? '') === 'COMPLETED') continue;
				$amt = (float)($n['totalPriceSet']['shopMoney']['amount'] ?? 0);
				if ($amt <= 0) continue;
				$items[] = ['name' => $n['name'] ?? '', 'customer' => $n['customer']['displayName'] ?? 'Customer',
				            'amount' => $amt, 'date' => $n['createdAt'] ?? '', 'type' => 'Draft order'];
				$total += $amt;
			}
			$cursor  = $d['pageInfo']['endCursor']  ?? null;
			$hasNext = $d['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 20);

		// ── Orders with an outstanding (unpaid) balance ──
		$oq = '
		query($cursor: String) {
		  orders(first: 100, after: $cursor, query: "financial_status:pending OR financial_status:partially_paid OR financial_status:authorized") {
		    pageInfo { hasNextPage endCursor }
		    edges { node { name createdAt cancelledAt customer { displayName } totalOutstandingSet { shopMoney { amount } } } }
		  }
		}';
		$cursor = null; $pages = 0;
		do {
			$res = shopify_graphql($oq, ['cursor' => $cursor]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'total' => 0, 'items' => []];
			$o = $res['data']['orders'] ?? null;
			if ($o === null) break;
			foreach ($o['edges'] as $e) {
				$n = $e['node'];
				if (!empty($n['cancelledAt'])) continue;
				$amt = (float)($n['totalOutstandingSet']['shopMoney']['amount'] ?? 0);
				if ($amt <= 0) continue;
				$items[] = ['name' => $n['name'] ?? '', 'customer' => $n['customer']['displayName'] ?? 'Customer',
				            'amount' => $amt, 'date' => $n['createdAt'] ?? '', 'type' => 'Unpaid order'];
				$total += $amt;
			}
			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 20);

		// Soonest first by date.
		usort($items, fn($a, $b) => strcmp($a['date'], $b['date']));
		return ['error' => null, 'total' => $total, 'items' => $items];
	}

	/**
	 * Committed demand from CURRENTLY OPEN draft orders (active "POs"), by SKU.
	 * Only counts drafts whose total units >= $minUnits (filters out tiny ones),
	 * and only open / invoice-sent drafts (completed drafts have become orders and
	 * already show up in sales history). Returns ['error'=>..., 'by_sku'=>[sku=>units], 'orders'=>n].
	 */
	function shopify_open_draft_demand($minUnits = 10) {
		$q = '
		query($cursor: String) {
		  draftOrders(first: 100, after: $cursor, query: "status:open OR status:invoice_sent") {
		    pageInfo { hasNextPage endCursor }
		    edges { node { status lineItems(first: 100) { edges { node { quantity sku } } } } }
		  }
		}';
		$bySku = []; $cursor = null; $pages = 0; $orders = 0;
		do {
			$res = shopify_graphql($q, ['cursor' => $cursor]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'by_sku' => [], 'orders' => 0];
			$d = $res['data']['draftOrders'] ?? null;
			if ($d === null) break;
			foreach ($d['edges'] as $e) {
				$n = $e['node'];
				if (($n['status'] ?? '') === 'COMPLETED') continue;
				$lines = []; $totalUnits = 0;
				foreach (($n['lineItems']['edges'] ?? []) as $le) {
					$li  = $le['node'];
					$sku = trim((string)($li['sku'] ?? ''));
					$qty = (int)($li['quantity'] ?? 0);
					if ($qty <= 0) continue;
					$lines[] = [$sku, $qty];
					$totalUnits += $qty;
				}
				if ($totalUnits < $minUnits) continue;       // below the wholesale threshold
				foreach ($lines as $l) { if ($l[0] === '') continue; $bySku[$l[0]] = ($bySku[$l[0]] ?? 0) + $l[1]; }
				$orders++;
			}
			$cursor  = $d['pageInfo']['endCursor']  ?? null;
			$hasNext = $d['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 20);

		return ['error' => null, 'by_sku' => $bySku, 'orders' => $orders];
	}

	/**
	 * The Oregon Warehouse Shopify location id (GID). Found by name match
	 * ("oregon"), overridable via the `oregon_location_id` setting. Cached.
	 * Used to split demand/stock: Oregon vs everything-else (Arkansas).
	 */
	function shopify_oregon_location_id() {
		static $id = null;
		if ($id !== null) return $id;
		$id = '';
		try { $db = db_connect(); if ($db) { $v = setting_get($db, 'oregon_location_id'); if ($v) { return $id = $v; } } } catch (Throwable $e) {}
		$res = shopify_graphql('query { locations(first: 50) { edges { node { id name } } } }');
		if (empty($res['error'])) {
			foreach (($res['data']['locations']['edges'] ?? []) as $e) {
				if (stripos($e['node']['name'] ?? '', 'oregon') !== false) { $id = $e['node']['id'] ?? ''; break; }
			}
		}
		return $id;
	}

	/**
	 * Prior-window units sold per SKU, SPLIT by fulfilling warehouse:
	 *   by_sku_oregon = orders fulfilled from the Oregon Warehouse location
	 *   by_sku_rest   = EVERYTHING ELSE (Arkansas + all POS/tradeshow + online
	 *                   shipped elsewhere + unfulfilled)
	 * Mirrors shopify_sales_in_range (excludes cancelled, explodes bundles) but
	 * buckets by the order's fulfillment location.
	 */
	function shopify_sales_by_location($since, $until) {
		$oregonId = shopify_oregon_location_id();
		$query = '
		query($cursor: String, $q: String!) {
		  orders(first: 100, after: $cursor, query: $q, sortKey: CREATED_AT) {
		    pageInfo { hasNextPage endCursor }
		    edges { node {
		      cancelledAt
		      fulfillments(first: 10) { location { id } }
		      lineItems(first: 50) { edges { node { quantity sku variant { id } } } }
		    } }
		  }
		}';
		$q = "created_at:>=$since AND created_at:<=$until";
		$oregon = []; $rest = []; $cursor = null; $pages = 0; $orders = 0;
		$bundles = shopify_bundle_map();

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'by_sku_oregon' => [], 'by_sku_rest' => []];
			$o = $res['data']['orders'] ?? null;
			if ($o === null) return ['error' => 'Malformed Shopify orders response.', 'by_sku_oregon' => [], 'by_sku_rest' => []];

			foreach ($o['edges'] as $oe) {
				$n = $oe['node'];
				if (!empty($n['cancelledAt'])) continue;
				$orders++;

				$isOregon = false;
				if ($oregonId !== '') {
					foreach (($n['fulfillments'] ?? []) as $f) {
						if (($f['location']['id'] ?? '') === $oregonId) { $isOregon = true; break; }
					}
				}

				// This order's SKU units (bundle-exploded).
				$orderSku = [];
				foreach ($n['lineItems']['edges'] as $le) {
					$li  = $le['node'];
					$sku = trim((string)($li['sku'] ?? ''));
					$qty = (int)($li['quantity'] ?? 0);
					if ($qty <= 0) continue;
					$vid = $li['variant']['id'] ?? '';
					if ($sku === '' && $vid && isset($bundles[$vid])) {
						foreach ($bundles[$vid] as $c) $orderSku[$c['sku']] = ($orderSku[$c['sku']] ?? 0) + $qty * (int)$c['qty'];
						continue;
					}
					if ($sku === '') continue;
					$orderSku[$sku] = ($orderSku[$sku] ?? 0) + $qty;
				}
				foreach ($orderSku as $sku => $qv) {
					if ($isOregon) $oregon[$sku] = ($oregon[$sku] ?? 0) + $qv;
					else           $rest[$sku]   = ($rest[$sku]   ?? 0) + $qv;
				}
			}

			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		return ['error' => null, 'by_sku_oregon' => $oregon, 'by_sku_rest' => $rest,
		        'orders' => $orders, 'oregon_location' => $oregonId, 'truncated' => ($hasNext && $pages >= 40)];
	}

	/**
	 * Finished-product available inventory per SKU, split by location:
	 *   [sku => ['oregon' => qty at Oregon Warehouse, 'rest' => qty everywhere else]]
	 * Negative (oversold) values are preserved so a backorder increases build need.
	 */
	function shopify_fp_by_location() {
		$oregonId = shopify_oregon_location_id();
		$query = '
		query($cursor: String) {
		  productVariants(first: 50, after: $cursor) {
		    pageInfo { hasNextPage endCursor }
		    edges { node { sku inventoryItem { inventoryLevels(first: 20) {
		      edges { node { location { id } quantities(names: ["available"]) { quantity } } }
		    } } } }
		  }
		}';
		$bySku = []; $cursor = null; $pages = 0;
		do {
			$res = shopify_graphql($query, ['cursor' => $cursor]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'skus' => []];
			$pv = $res['data']['productVariants'] ?? null;
			if ($pv === null) return ['error' => 'Malformed Shopify response.', 'skus' => []];

			foreach ($pv['edges'] as $ve) {
				$v   = $ve['node'];
				$sku = trim((string)($v['sku'] ?? ''));
				if ($sku === '') continue;
				$o = 0; $r = 0;
				foreach (($v['inventoryItem']['inventoryLevels']['edges'] ?? []) as $le) {
					$lvl   = $le['node'];
					$lid   = $lvl['location']['id'] ?? '';
					$avail = 0;
					foreach (($lvl['quantities'] ?? []) as $qn) $avail = (int)($qn['quantity'] ?? 0);
					if ($oregonId !== '' && $lid === $oregonId) $o += $avail; else $r += $avail;
				}
				$bySku[$sku] = ['oregon' => $o, 'rest' => $r];
			}

			$cursor  = $pv['pageInfo']['endCursor']  ?? null;
			$hasNext = $pv['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		return ['error' => null, 'skus' => $bySku, 'oregon_location' => $oregonId];
	}

	/**
	 * Categorize a finished product for the warehouse-stock display, or return
	 * NULL to exclude it (apparel, gift cards). Uses Shopify productType, with
	 * sensible fallbacks for products that have no type.
	 */
	function shopify_fp_category($title, $productType) {
		// Exclude apparel + gift cards entirely (not relevant to FP building).
		if (preg_match('/\b(tee|t-?shirt|shirt|hoodie|sweatshirt|crewneck|hat|cap|beanie|apparel|gift\s*card)\b/i', $title)) return null;
		$pt = trim((string)$productType);
		if ($pt !== '') return $pt;
		// No product type — derive from the title.
		if (stripos($title, 'wings')   !== false) return 'Replacement Wings';
		if (stripos($title, 'bundle')  !== false) return 'Bundles';
		if (stripos($title, 'animator')!== false) return 'Animators (Other)';
		return 'Other';
	}

	/**
	 * Live Shopify finished-product inventory grouped by LOCATION then CATEGORY.
	 * Requires the read_locations scope. Apparel/gift cards excluded; bundle
	 * variants (no SKU) skipped. Returns:
	 *   ['error'=>..., 'locations'=>[ ['id','name','total'] ... ordered ],
	 *    'data'=>[ locId => [ ['category','subtotal','items'=>[{sku,title,qty}]] ... ] ]]
	 */
	function shopify_inventory_by_location() {
		$query = '
		query($cursor: String) {
		  products(first: 20, after: $cursor, query: "status:active") {
		    pageInfo { hasNextPage endCursor }
		    edges { node {
		      title productType
		      variants(first: 50) { edges { node {
		        sku title
		        inventoryItem { inventoryLevels(first: 20) { edges { node {
		          location { id name } quantities(names: ["available"]) { quantity }
		        } } } }
		      } } }
		    } }
		  }
		}';

		$locName = [];          // locId => name
		$raw     = [];          // locId => category => [items]
		$cursor  = null; $pages = 0;

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'locations' => [], 'data' => []];
			$pr = $res['data']['products'] ?? null;
			if ($pr === null) return ['error' => 'Malformed Shopify response.', 'locations' => [], 'data' => []];

			foreach ($pr['edges'] as $pe) {
				$p   = $pe['node'];
				$cat = shopify_fp_category($p['title'] ?? '', $p['productType'] ?? '');
				if ($cat === null) continue;                      // apparel / gift card

				foreach ($p['variants']['edges'] as $ve) {
					$v   = $ve['node'];
					$sku = trim((string)($v['sku'] ?? ''));
					if ($sku === '') continue;                    // skip bundle/untracked variants
					$title = $p['title'] ?? '';
					$vt = trim((string)($v['title'] ?? ''));
					if ($vt !== '' && strcasecmp($vt, 'Default Title') !== 0) $title .= ' — ' . $vt;

					foreach (($v['inventoryItem']['inventoryLevels']['edges'] ?? []) as $le) {
						$lvl  = $le['node'];
						$lid  = $lvl['location']['id']   ?? '';
						$lnm  = $lvl['location']['name'] ?? '';
						if ($lid === '') continue;
						$qty  = 0;
						foreach (($lvl['quantities'] ?? []) as $qn) $qty = (int)($qn['quantity'] ?? 0);
						$locName[$lid] = $lnm;
						$raw[$lid][$cat][] = ['sku' => $sku, 'title' => $title, 'qty' => $qty];
					}
				}
			}

			$cursor  = $pr['pageInfo']['endCursor']  ?? null;
			$hasNext = $pr['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		// Order categories: animators first, then accessories/parts/wings, etc.
		$catRank = function($c) {
			$order = ['Animators for Avian X', 'Animators for Lucky Duck', 'Animators for Mojo',
			          'Animators (Other)', 'Replacement Wings', 'Replacement Parts',
			          'Animator Accessories', 'Bundles', 'Other'];
			foreach ($order as $i => $o) if (stripos($c, $o) === 0) return $i;
			return 50;
		};

		$data = [];
		foreach ($raw as $lid => $cats) {
			uksort($cats, fn($a, $b) => $catRank($a) <=> $catRank($b) ?: strcmp($a, $b));
			$blocks = [];
			foreach ($cats as $cat => $items) {
				usort($items, fn($a, $b) => strcmp($a['sku'], $b['sku']));
				$subtotal = array_sum(array_map(fn($i) => $i['qty'], $items));
				$blocks[] = ['category' => $cat, 'subtotal' => $subtotal, 'items' => $items];
			}
			$data[$lid] = $blocks;
		}

		// Order locations: Oregon Warehouse, Arkansas Warehouse, then others A→Z.
		$locs = [];
		foreach ($locName as $lid => $nm) {
			$total = 0;
			foreach (($data[$lid] ?? []) as $b) $total += $b['subtotal'];
			$locs[] = ['id' => $lid, 'name' => $nm, 'total' => $total];
		}
		usort($locs, function($a, $b) {
			$rank = function($n) {
				if (stripos($n, 'oregon')   !== false) return 0;
				if (stripos($n, 'arkansas') !== false) return 1;
				return 2;
			};
			$ra = $rank($a['name']); $rb = $rank($b['name']);
			return $ra <=> $rb ?: strcmp($a['name'], $b['name']);
		});

		return ['error' => null, 'locations' => $locs, 'data' => $data];
	}

	/**
	 * ALL Shopify locations including DEACTIVATED ones. Critical: shows get
	 * deactivated after they happen (and recurring shows get a new location each
	 * year), so the default `locations` query silently omits them. Requires the
	 * read_locations scope. Returns ['error'=>..., 'locations'=>[['id'(numeric),'name','active']]].
	 */
	function shopify_all_locations() {
		$res = shopify_graphql('query { locations(first: 100, includeInactive: true) { edges { node { id name isActive } } } }');
		if (!empty($res['error'])) return ['error' => $res['error'], 'locations' => []];
		$out = [];
		foreach (($res['data']['locations']['edges'] ?? []) as $e) {
			$n   = $e['node'];
			$gid = (string)($n['id'] ?? '');
			$num = preg_replace('/\D/', '', strrchr($gid, '/') ?: '');
			if ($num === '') continue;
			$out[] = ['id' => $num, 'name' => trim((string)($n['name'] ?? '')), 'active' => !empty($n['isActive'])];
		}
		return ['error' => null, 'locations' => $out];
	}

	/**
	 * Tradeshow / event POS locations grouped by show name: [['name'=>, 'ids'=>[...]]].
	 * Auto-discovered from ALL Shopify locations (incl. deactivated), excluding
	 * warehouses + the HQ address, and merging duplicate per-year locations of the
	 * same show. This is what prevents future "missing show" gaps. Falls back to a
	 * hardcoded full list when the app can't read locations (no read_locations
	 * scope yet). Overridable via the `tradeshow_locations` setting.
	 */
	function tradeshow_locations() {
		try {
			$db = db_connect();
			if ($db) { $v = setting_get($db, 'tradeshow_locations'); if ($v) { $j = json_decode($v, true); if (is_array($j) && $j) return $j; } }
		} catch (Throwable $e) {}

		// Preferred: discover dynamically so new/deactivated shows are never missed.
		$dyn = shopify_all_locations();
		if (empty($dyn['error']) && !empty($dyn['locations'])) {
			$groups = [];
			foreach ($dyn['locations'] as $loc) {
				$name = $loc['name'];
				if ($name === '') continue;
				if (preg_match('/warehouse|jack london|\bdrive\b/i', $name)) continue; // skip warehouses + HQ
				$key = strtolower(preg_replace('/\s+/', ' ', trim($name)));
				if (!isset($groups[$key])) $groups[$key] = ['name' => $name, 'ids' => []];
				$groups[$key]['ids'][] = $loc['id'];
			}
			if ($groups) return array_values($groups);
		}

		// Fallback (no read_locations scope): full known show list incl. deactivated.
		return [
			['name' => 'Squadfest',                       'ids' => ['106768269591', '87162716439']],
			['name' => 'Delta Waterfowl - OKC',           'ids' => ['84464828695']],
			['name' => 'DUX Waterfowl Expo',              'ids' => ['106768400663']],
			['name' => 'Game Fair (MN)',                  'ids' => ['87163011351']],
			['name' => 'Waterfowl Expo OshKosh',          'ids' => ['87163109655']],
			['name' => 'ISE Sacramento',                  'ids' => ['111770501399', '94683988247']],
			['name' => 'Easton Waterfowl Festival',       'ids' => ['101718294807']],
			['name' => 'Fort Worth Hunters Extravaganza', 'ids' => ['87162913047']],
			['name' => 'GAOS Harrisburg',                 'ids' => ['95259722007']],
			['name' => 'Houston Hunters Extravaganza',    'ids' => ['87162814743']],
			['name' => 'Las Vegas Convention Center',     'ids' => ['93992648983']],
			['name' => 'NorCal Sportsman Show',           'ids' => ['92907929879']],
			['name' => 'NWTF Nashville',                  'ids' => ['95661916439']],
			['name' => 'Reno Tradeshow',                  'ids' => ['104532902167']],
		];
	}

	/**
	 * Exact units sold at one show (Shopify location) in a date range, by SKU and
	 * by day. Filters orders by location_id (works without read_locations),
	 * excludes cancelled, explodes bundles. Returns
	 * ['error'=>..., 'by_sku'=>[sku=>units desc], 'titles'=>[sku=>title],
	 *  'by_date'=>[YYYY-MM-DD=>units], 'total_units'=>, 'revenue'=>, 'orders'=>].
	 */
	function shopify_show_sales($locationId, $since, $until) {
		$query = '
		query($cursor: String, $q: String!) {
		  orders(first: 100, after: $cursor, query: $q, sortKey: CREATED_AT) {
		    pageInfo { hasNextPage endCursor }
		    edges { node {
		      createdAt cancelledAt
		      currentSubtotalPriceSet { shopMoney { amount } }
		      lineItems(first: 50) { edges { node { quantity sku title variant { id } } } }
		    } }
		  }
		}';
		$q = "location_id:$locationId created_at:>=$since created_at:<=$until";
		$bySku = []; $titles = []; $byDate = []; $totalUnits = 0; $revenue = 0.0;
		$cursor = null; $pages = 0; $orders = 0;
		$bundles = shopify_bundle_map();

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'by_sku' => [], 'by_date' => []];
			$o = $res['data']['orders'] ?? null;
			if ($o === null) return ['error' => 'Malformed Shopify orders response.', 'by_sku' => [], 'by_date' => []];

			foreach ($o['edges'] as $oe) {
				$n = $oe['node'];
				if (!empty($n['cancelledAt'])) continue;
				$orders++;
				$date = substr((string)($n['createdAt'] ?? ''), 0, 10);
				$revenue += (float)($n['currentSubtotalPriceSet']['shopMoney']['amount'] ?? 0);

				foreach ($n['lineItems']['edges'] as $le) {
					$li  = $le['node'];
					$sku = trim((string)($li['sku'] ?? ''));
					$qty = (int)($li['quantity'] ?? 0);
					if ($qty <= 0) continue;
					$vid = $li['variant']['id'] ?? '';
					if ($sku === '' && $vid && isset($bundles[$vid])) {
						foreach ($bundles[$vid] as $c) {
							$add = $qty * (int)$c['qty'];
							$bySku[$c['sku']] = ($bySku[$c['sku']] ?? 0) + $add;
							$byDate[$date]    = ($byDate[$date] ?? 0) + $add;
							$totalUnits      += $add;
						}
						continue;
					}
					if ($sku === '') continue;
					$bySku[$sku]  = ($bySku[$sku] ?? 0) + $qty;
					$byDate[$date]= ($byDate[$date] ?? 0) + $qty;
					$totalUnits  += $qty;
					if (!isset($titles[$sku])) $titles[$sku] = trim((string)($li['title'] ?? ''));
				}
			}

			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		arsort($bySku);
		ksort($byDate);
		return ['error' => null, 'by_sku' => $bySku, 'titles' => $titles, 'by_date' => $byDate,
		        'total_units' => $totalUnits, 'revenue' => $revenue, 'orders' => $orders];
	}

	/**
	 * Time-based cache: return the cached value if it was refreshed within
	 * $ttlSeconds, otherwise fetch live via $fn, cache it, and return it. On a
	 * live error, serves the last good (stale) value if one exists. Pass
	 * $ttlSeconds = 0 to force a live pull (manual Refresh). Stores in data_cache.
	 * Returns ['data'=>..., 'cached'=>bool, 'updated_at'=>str|null, 'stale'=>bool].
	 */
	function shopify_cache_remember($db, $key, $ttlSeconds, callable $fn) {
		$cachedVal = null; $cachedAt = null;
		try {
			$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
			$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$key]);
			$row = $s->fetch();
			if ($row && $row['cval'] !== null) { $cachedVal = json_decode($row['cval'], true); $cachedAt = $row['updated_at']; }
		} catch (Throwable $e) {}

		if ($ttlSeconds > 0 && $cachedVal !== null && $cachedAt && (time() - strtotime($cachedAt)) < $ttlSeconds) {
			return ['data' => $cachedVal, 'cached' => true, 'updated_at' => $cachedAt, 'stale' => false];
		}

		$live = $fn();
		$err  = is_array($live) ? ($live['error'] ?? null) : null;
		if ($err) {
			if ($cachedVal !== null) return ['data' => $cachedVal, 'cached' => true, 'updated_at' => $cachedAt, 'stale' => true];
			return ['data' => $live, 'cached' => false, 'updated_at' => null, 'stale' => false];
		}
		try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$key, json_encode($live)]); } catch (Throwable $e) {}
		return ['data' => $live, 'cached' => false, 'updated_at' => date('Y-m-d H:i:s'), 'stale' => false];
	}

	/** How long (seconds) live inventory may be reused before re-pulling. Default 3h. */
	function inventory_cache_ttl($db) {
		try { $h = (float)setting_get($db, 'inventory_cache_hours', 3); if ($h > 0) return (int)round($h * 3600); }
		catch (Throwable $e) {}
		return 3 * 3600;
	}

	/** The customer whose orders use Amazon (CDA) packaging cards. Configurable. */
	function shopify_amazon_customer() {
		static $name = null;
		if ($name !== null) return $name;
		$name = 'TJ STUMPF';
		try {
			$db = db_connect();
			if ($db) { $v = setting_get($db, 'amazon_customer'); if ($v !== null && trim($v) !== '') $name = trim($v); }
		} catch (Throwable $e) { /* default */ }
		return $name;
	}

	/** Order tag that also marks an order as Amazon (CDA cards). Configurable. */
	function shopify_amazon_tag() {
		static $tag = null;
		if ($tag !== null) return $tag;
		$tag = 'Amazon';
		try {
			$db = db_connect();
			if ($db) { $v = setting_get($db, 'amazon_tag'); if ($v !== null && trim($v) !== '') $tag = trim($v); }
		} catch (Throwable $e) { /* default */ }
		return $tag;
	}

	/**
	 * Point-of-sale units per day in a window (for tradeshow-spike detection).
	 * Returns ['error'=>..., 'by_date'=>[YYYY-MM-DD => units], 'total'=>int].
	 */
	function shopify_pos_by_date($since, $until) {
		$query = '
		query($cursor: String, $q: String!) {
		  orders(first: 100, after: $cursor, query: $q, sortKey: CREATED_AT) {
		    pageInfo { hasNextPage endCursor }
		    edges { node {
		      createdAt
		      sourceName
		      cancelledAt
		      lineItems(first: 30) { edges { node { quantity } } }
		    } }
		  }
		}';

		$q = "created_at:>=$since AND created_at:<=$until";
		$byDate = []; $total = 0; $cursor = null; $pages = 0;

		do {
			$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
			if (!empty($res['error'])) return ['error' => $res['error'], 'by_date' => [], 'total' => 0];
			$o = $res['data']['orders'] ?? null;
			if ($o === null) return ['error' => 'Malformed Shopify response.', 'by_date' => [], 'total' => 0];

			foreach ($o['edges'] as $oe) {
				$n = $oe['node'];
				if (!empty($n['cancelledAt'])) continue;
				if (shopify_channel_label($n['sourceName'] ?? '') !== 'pos') continue;
				$date = substr($n['createdAt'] ?? '', 0, 10);
				$qty = 0;
				foreach ($n['lineItems']['edges'] as $le) $qty += (int)($le['node']['quantity'] ?? 0);
				if ($qty <= 0) continue;
				$byDate[$date] = ($byDate[$date] ?? 0) + $qty;
				$total += $qty;
			}

			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 20);

		return ['error' => null, 'by_date' => $byDate, 'total' => $total];
	}

	/** Map a Shopify sourceName to a friendly channel label. */
	function shopify_channel_label($source) {
		$s = strtolower($source);
		if ($s === 'pos' || strpos($s, 'point') !== false) return 'pos';
		if ($s === 'web') return 'online';
		if (strpos($s, 'draft') !== false) return 'draft/wholesale';
		if (strpos($s, 'collective') !== false) return 'collective/wholesale';
		return $source !== '' ? $source : 'other';
	}
