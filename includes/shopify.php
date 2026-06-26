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
				$d = setting_get($db, 'shopify_domain');
				$t = setting_get($db, 'shopify_token');
				$v = setting_get($db, 'shopify_api_version');
				if ($d !== null && $d !== '') $cfg['domain']      = $d;
				if ($t !== null && $t !== '') $cfg['token']       = $t;
				if ($v !== null && $v !== '') $cfg['api_version'] = $v;
			}
		} catch (Throwable $e) { /* settings table not migrated yet — use file config */ }

		return $cfg;
	}

	/** True only when a real domain + token have been filled in. */
	function shopify_is_configured() {
		$c = shopify_config();
		return !empty($c['domain'])
			&& !empty($c['token'])
			&& strpos($c['token'], 'CHANGE_ME') === false
			&& strpos($c['domain'], 'your-store') === false;
	}

	/**
	 * Run a GraphQL query against the Admin API.
	 * Returns the decoded JSON on success, or ['error' => '...'] on failure.
	 * Retries automatically when Shopify throttles the request.
	 */
	function shopify_graphql($query, $variables = []) {
		$c = shopify_config();
		if (empty($c['domain']) || empty($c['token'])) {
			return ['error' => 'Shopify is not configured. Add a "shopify" block to includes/config.local.php.'];
		}

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
					'X-Shopify-Access-Token: ' . $c['token'],
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
				return ['error' => 'Shopify rejected the credentials (HTTP 401). The token is invalid for this store — make sure you pasted the Admin API access token (starts with shpat_, not the API key or secret) and that the store domain matches the store that token belongs to.'];
			}
			if ($httpCode === 403) {
				return ['error' => 'Shopify accepted the token but denied access (HTTP 403). Add the read_products and read_inventory scopes to the app, then reinstall it and use the new token.'];
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
