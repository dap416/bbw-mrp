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
		        lineItems(first: 30) {
		          edges { node { quantity sku variant { id } } }
		        }
		      }
		    }
		  }
		}';

		$q = "created_at:>=$since AND created_at:<=$until";
		$bySku = []; $byChannel = []; $cursor = null; $pages = 0; $orders = 0;
		$bundles = shopify_bundle_map();

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
							$bySku[$c['sku']] = ($bySku[$c['sku']] ?? 0) + $add;
							$byChannel[$chan] = ($byChannel[$chan] ?? 0) + $add;
						}
						continue;
					}
					if ($sku === '') continue;
					$bySku[$sku]      = ($bySku[$sku] ?? 0) + $qty;
					$byChannel[$chan] = ($byChannel[$chan] ?? 0) + $qty;
				}
			}

			$cursor  = $o['pageInfo']['endCursor']  ?? null;
			$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
			$pages++;
		} while ($hasNext && $pages < 40);

		return [
			'error'      => null,
			'by_sku'     => $bySku,
			'by_channel' => $byChannel,
			'orders'     => $orders,
			'truncated'  => ($hasNext && $pages >= 40),
		];
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
