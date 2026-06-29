<?php

	require_once(__DIR__."/fns.php");
	require_once(__DIR__."/shopify.php"); // setting_get / setting_set live here

	/**
	 * QuickBooks Online (Accounting API) OAuth2 client.
	 * Credentials + tokens are stored in the `settings` key-value table:
	 *   qb_client_id, qb_client_secret, qb_environment (sandbox|production),
	 *   qb_realm_id, qb_access_token, qb_access_expires (unix),
	 *   qb_refresh_token, qb_refresh_expires (unix).
	 *
	 * Flow: user clicks Connect -> /quickbooks/connect.php -> Intuit consent ->
	 * /quickbooks/callback.php exchanges the auth code for tokens. Access tokens
	 * last ~1h and are auto-refreshed with the ~100-day refresh token.
	 */

	const QB_AUTHORIZE_URL = 'https://appcenter.intuit.com/connect/oauth2';
	const QB_TOKEN_URL     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
	const QB_SCOPE         = 'com.intuit.quickbooks.accounting';
	const QB_MINOR_VERSION = '70';

	/**
	 * Log a QuickBooks error to the server log for troubleshooting. Includes the
	 * Intuit transaction id (intuit_tid) when present — Intuit support uses it to
	 * trace a specific request.
	 */
	function qb_log($context, $message, $tid = '') {
		$line = '[QuickBooks] ' . $context . ': ' . $message;
		if ($tid !== '') $line .= ' (intuit_tid: ' . $tid . ')';
		error_log($line);
	}

	/** Pull the intuit_tid value out of a raw response-header string. */
	function qb_extract_tid($headers) {
		if (preg_match('/^intuit_tid:\s*(.+)$/im', (string)$headers, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	/** All QB settings in one cached read. */
	function qb_settings() {
		static $s = null;
		if ($s !== null) return $s;
		$s = [];
		try {
			$db = db_connect();
			if ($db) {
				foreach ([
					'qb_client_id','qb_client_secret','qb_environment','qb_realm_id',
					'qb_access_token','qb_access_expires','qb_refresh_token','qb_refresh_expires',
				] as $k) {
					$s[$k] = (string)setting_get($db, $k, '');
				}
			}
		} catch (Throwable $e) { /* settings table missing — treat as unconfigured */ }
		if (($s['qb_environment'] ?? '') === '') $s['qb_environment'] = 'production';
		return $s;
	}

	/** Forget the per-request cache (after writing tokens). */
	function qb_reset_cache() { /* qb_settings uses a static; re-read by new request */ }

	function qb_is_configured() {
		$s = qb_settings();
		return !empty($s['qb_client_id']) && !empty($s['qb_client_secret']);
	}

	function qb_is_connected() {
		$s = qb_settings();
		return !empty($s['qb_refresh_token']) && !empty($s['qb_realm_id']);
	}

	function qb_environment() {
		$s = qb_settings();
		return ($s['qb_environment'] === 'sandbox') ? 'sandbox' : 'production';
	}

	/** API base differs per environment; OAuth endpoints do not. */
	function qb_api_base() {
		return qb_environment() === 'sandbox'
			? 'https://sandbox-quickbooks.api.intuit.com'
			: 'https://quickbooks.api.intuit.com';
	}

	/**
	 * The redirect URI must EXACTLY match what's registered in the Intuit app.
	 * Pinned to the canonical https domain so it never varies with how the page
	 * was reached (IP vs domain, http vs https). Overridable via the
	 * `qb_redirect_uri` setting if the domain ever changes.
	 */
	function qb_redirect_uri() {
		$s = qb_settings();
		$override = trim((string)($s['qb_redirect_uri'] ?? ''));
		if ($override !== '') return $override;
		return 'https://mrp.bbwmanager.com/quickbooks/callback.php';
	}

	/** Build the Intuit consent URL the user is sent to. */
	function qb_authorize_url($state) {
		$s = qb_settings();
		return QB_AUTHORIZE_URL . '?' . http_build_query([
			'client_id'     => $s['qb_client_id'],
			'response_type' => 'code',
			'scope'         => QB_SCOPE,
			'redirect_uri'  => qb_redirect_uri(),
			'state'         => $state,
		]);
	}

	/** POST to the token endpoint (Basic auth = client_id:client_secret). */
	function qb_token_request(array $form) {
		$s    = qb_settings();
		$auth = base64_encode($s['qb_client_id'] . ':' . $s['qb_client_secret']);

		$ch = curl_init(QB_TOKEN_URL);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($form),
			CURLOPT_HTTPHEADER     => [
				'Authorization: Basic ' . $auth,
				'Content-Type: application/x-www-form-urlencoded',
				'Accept: application/json',
			],
			CURLOPT_HEADER         => true,   // capture response headers for intuit_tid
			CURLOPT_TIMEOUT        => 30,
		]);
		$raw         = curl_exec($ch);
		$code        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$err         = curl_error($ch);
		curl_close($ch);

		if ($raw === false) { qb_log('token', $err); return ['error' => 'Could not reach Intuit: ' . $err]; }
		$headers = substr($raw, 0, $headerSize);
		$body    = substr($raw, $headerSize);
		$tid     = qb_extract_tid($headers);

		$j = json_decode($body, true);
		if ($code >= 400 || !is_array($j) || empty($j['access_token'])) {
			$msg = is_array($j) ? ($j['error_description'] ?? $j['error'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
			qb_log('token', $msg, $tid);
			return ['error' => 'QuickBooks token request failed: ' . $msg];
		}
		return ['data' => $j];
	}

	/** Persist a token response from Intuit into settings. */
	function qb_store_tokens(array $j) {
		$db = db_connect();
		if (!$db) return;
		setting_set($db, 'qb_access_token',   $j['access_token']);
		setting_set($db, 'qb_access_expires', (string)(time() + (int)($j['expires_in'] ?? 3600)));
		if (!empty($j['refresh_token'])) {
			setting_set($db, 'qb_refresh_token',   $j['refresh_token']);
			setting_set($db, 'qb_refresh_expires', (string)(time() + (int)($j['x_refresh_token_expires_in'] ?? 8640000)));
		}
	}

	/** Exchange the auth code from the callback for tokens; store realm id. */
	function qb_exchange_code($code, $realmId) {
		$res = qb_token_request([
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => qb_redirect_uri(),
		]);
		if (!empty($res['error'])) return $res;
		qb_store_tokens($res['data']);
		$db = db_connect();
		if ($db) setting_set($db, 'qb_realm_id', (string)$realmId);
		return ['ok' => true];
	}

	/** Refresh the access token using the stored refresh token. */
	function qb_refresh() {
		$s = qb_settings();
		if (empty($s['qb_refresh_token'])) return ['error' => 'QuickBooks is not connected.'];
		$res = qb_token_request([
			'grant_type'    => 'refresh_token',
			'refresh_token' => $s['qb_refresh_token'],
		]);
		if (!empty($res['error'])) return $res;
		qb_store_tokens($res['data']);
		return ['ok' => true, 'token' => $res['data']['access_token']];
	}

	/** Return a valid access token, refreshing if it's expired/near expiry. */
	function qb_access_token() {
		$s = qb_settings();
		if (empty($s['qb_refresh_token'])) return ['error' => 'QuickBooks is not connected.'];

		$token = $s['qb_access_token'];
		$exp   = (int)$s['qb_access_expires'];
		if ($token !== '' && $exp > time() + 120) return ['token' => $token]; // 2-min buffer

		$r = qb_refresh();
		if (!empty($r['error'])) return ['error' => $r['error']];
		return ['token' => $r['token']];
	}

	/**
	 * Run a QBO SQL query (https://developer.intuit.com/.../data-queries).
	 * e.g. qb_query("SELECT * FROM Account WHERE AccountType = 'Bank'")
	 * Returns the decoded QueryResponse array, or ['error' => '...'].
	 */
	function qb_query($sql) {
		$s = qb_settings();
		if (empty($s['qb_realm_id'])) return ['error' => 'QuickBooks is not connected.'];
		$auth = qb_access_token();
		if (!empty($auth['error'])) return ['error' => $auth['error']];

		$url = qb_api_base() . '/v3/company/' . rawurlencode($s['qb_realm_id'])
			 . '/query?query=' . rawurlencode($sql) . '&minorversion=' . QB_MINOR_VERSION;

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $auth['token'],
				'Accept: application/json',
			],
			CURLOPT_HEADER         => true,   // capture response headers for intuit_tid
			CURLOPT_TIMEOUT        => 30,
		]);
		$raw        = curl_exec($ch);
		$code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$err        = curl_error($ch);
		curl_close($ch);

		if ($raw === false) { qb_log('query', $err); return ['error' => 'Could not reach QuickBooks: ' . $err]; }
		$headers = substr($raw, 0, $headerSize);
		$body    = substr($raw, $headerSize);
		$tid     = qb_extract_tid($headers);

		$j = json_decode($body, true);
		if ($code === 401) {
			qb_log('query', 'HTTP 401 (token rejected) — ' . $sql, $tid);
			return ['error' => 'QuickBooks rejected the token (401). Try reconnecting.'];
		}
		if ($code >= 400 || !is_array($j)) {
			$msg = is_array($j) ? ($j['Fault']['Error'][0]['Message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
			qb_log('query', $msg . ' — ' . $sql, $tid);
			return ['error' => 'QuickBooks query failed: ' . $msg];
		}
		return $j['QueryResponse'] ?? [];
	}

	/** Run a QuickBooks report (e.g. ProfitAndLoss). Returns decoded JSON or ['error'=>...]. */
	function qb_report($name, $params = []) {
		$s = qb_settings();
		if (empty($s['qb_realm_id'])) return ['error' => 'QuickBooks is not connected.'];
		$auth = qb_access_token();
		if (!empty($auth['error'])) return ['error' => $auth['error']];

		$url = qb_api_base() . '/v3/company/' . rawurlencode($s['qb_realm_id'])
			 . '/reports/' . rawurlencode($name) . '?minorversion=' . QB_MINOR_VERSION;
		foreach ($params as $k => $v) $url .= '&' . urlencode($k) . '=' . urlencode($v);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $auth['token'], 'Accept: application/json'],
			CURLOPT_HEADER         => true,
			CURLOPT_TIMEOUT        => 30,
		]);
		$raw        = curl_exec($ch);
		$code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$err        = curl_error($ch);
		curl_close($ch);

		if ($raw === false) { qb_log('report', $err); return ['error' => 'Could not reach QuickBooks: ' . $err]; }
		$tid  = qb_extract_tid(substr($raw, 0, $headerSize));
		$j    = json_decode(substr($raw, $headerSize), true);
		if ($code >= 400 || !is_array($j)) {
			$msg = is_array($j) ? ($j['Fault']['Error'][0]['Message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;
			qb_log('report', $name . ': ' . $msg, $tid);
			return ['error' => 'QuickBooks report failed: ' . $msg];
		}
		return $j;
	}

	/**
	 * Estimate average monthly expenses from the Profit & Loss report over the
	 * trailing N whole months. Best-effort: returns ['monthly'=>float] (0 if it
	 * can't be parsed) plus ['total','months'].
	 */
	function qb_monthly_expense_estimate($monthsBack = 3) {
		$end   = date('Y-m-d', strtotime('last day of previous month'));
		$start = date('Y-m-01', strtotime("first day of -$monthsBack month"));
		$r = qb_report('ProfitAndLoss', ['start_date' => $start, 'end_date' => $end]);
		if (!empty($r['error'])) return ['error' => $r['error'], 'monthly' => 0.0, 'total' => 0.0, 'months' => $monthsBack];

		// Walk the report rows to find the "Expenses" section total.
		$total = 0.0;
		$walk = function($rows) use (&$walk, &$total) {
			foreach (($rows ?? []) as $row) {
				$grp  = $row['group'] ?? '';
				$hdr  = $row['Header']['ColData'][0]['value'] ?? '';
				if ($grp === 'Expenses' || strcasecmp($hdr, 'Expenses') === 0) {
					$sum = $row['Summary']['ColData'] ?? [];
					$val = end($sum)['value'] ?? '';
					if ($val !== '') { $total = (float)str_replace([',','$'], '', $val); return; }
				}
				if (!empty($row['Rows']['Row'])) $walk($row['Rows']['Row']);
			}
		};
		try { $walk($r['Rows']['Row'] ?? []); } catch (Throwable $e) {}

		$months  = max(1, $monthsBack);
		return ['error' => null, 'total' => $total, 'months' => $months, 'monthly' => round($total / $months, 2)];
	}

	/**
	 * Actual income per month from the Profit & Loss report.
	 * Cash basis by default, so revenue lands in the month the money was received
	 * (correctly handling Net-30/60 terms). Returns
	 * ['error'=>..., 'by_month'=>['YYYY-MM'=>amount], 'total'=>float].
	 */
	function qb_monthly_income($from, $to, $cashBasis = true) {
		$params = ['start_date' => $from, 'end_date' => $to, 'summarize_column_by' => 'Month'];
		if ($cashBasis) $params['accounting_method'] = 'Cash';
		$r = qb_report('ProfitAndLoss', $params);
		if (!empty($r['error'])) return ['error' => $r['error'], 'by_month' => [], 'total' => 0.0];

		$byMonth = []; $total = 0.0;
		try {
			// Column index -> YYYY-MM (from each month column's StartDate metadata).
			$colYm = [];
			foreach (($r['Columns']['Column'] ?? []) as $i => $c) {
				foreach (($c['MetaData'] ?? []) as $md) {
					if (($md['Name'] ?? '') === 'StartDate' && !empty($md['Value'])) $colYm[$i] = substr($md['Value'], 0, 7);
				}
			}
			// Find the "Income" section's summary row (its per-month totals).
			$find = function($rows) use (&$find) {
				foreach (($rows ?? []) as $row) {
					$grp = $row['group'] ?? '';
					$hdr = $row['Header']['ColData'][0]['value'] ?? '';
					if ($grp === 'Income' || strcasecmp($hdr, 'Income') === 0) return $row['Summary']['ColData'] ?? null;
					if (!empty($row['Rows']['Row'])) { $f = $find($row['Rows']['Row']); if ($f) return $f; }
				}
				return null;
			};
			$sum = $find($r['Rows']['Row'] ?? []);
			if ($sum) {
				foreach ($sum as $i => $cd) {
					if (!isset($colYm[$i])) continue;
					$v = (float)str_replace([',', '$'], '', $cd['value'] ?? '0');
					$byMonth[$colYm[$i]] = $v;
					$total += $v;
				}
			}
		} catch (Throwable $e) { /* leave what we parsed */ }

		return ['error' => null, 'by_month' => $byMonth, 'total' => $total];
	}

	/** Quick connectivity check — returns ['ok'=>bool,'name'=>string,'error'=>string]. */
	function qb_company_info() {
		$s = qb_settings();
		if (empty($s['qb_realm_id'])) return ['ok' => false, 'name' => '', 'error' => 'Not connected.'];
		$r = qb_query('SELECT * FROM CompanyInfo');
		if (!empty($r['error'])) return ['ok' => false, 'name' => '', 'error' => $r['error']];
		$name = $r['CompanyInfo'][0]['CompanyName'] ?? '';
		return ['ok' => true, 'name' => $name, 'error' => ''];
	}

	/** Disconnect — wipe tokens and realm (keeps client id/secret + environment). */
	function qb_disconnect() {
		$db = db_connect();
		if (!$db) return;
		foreach (['qb_realm_id','qb_access_token','qb_access_expires','qb_refresh_token','qb_refresh_expires'] as $k) {
			setting_set($db, $k, '');
		}
	}
