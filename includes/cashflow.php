<?php

	require_once(__DIR__."/fns.php");
	require_once(__DIR__."/quickbooks.php");

	/**
	 * Assemble the cash-flow picture from three sources:
	 *   - QuickBooks Online: bank/cash balances, credit-card & line-of-credit
	 *     balances, open bills (AP), open invoices (AR).
	 *   - The MRP itself: unpaid purchase-order balances (what you owe suppliers).
	 *   - (Shopify AR is added in a later pass.)
	 *
	 * Every external call is wrapped so a single failure degrades gracefully and
	 * the page still renders the parts that did load.
	 */
	function build_cashflow_data($db) {
		$out = [
			'qb_connected' => qb_is_connected(),
			'qb_company'   => '',
			'cash'    => ['accounts' => [], 'total' => 0.0, 'error' => null],
			'credit'  => ['accounts' => [], 'total' => 0.0, 'error' => null], // credit cards + lines of credit
			'bills'   => ['items' => [], 'total' => 0.0, 'error' => null],     // QBO AP
			'ar'      => ['items' => [], 'total' => 0.0, 'error' => null],     // Shopify receivables (owed to you)
			'pos'     => ['items' => [], 'total' => 0.0],                       // MRP unpaid POs
			'qb_accounts' => [],                                                // picker for manual balances
		];

		if (qb_is_connected()) {
			// Company name (from cache).
			$out['qb_company'] = cf_company($db);

			// Accounts — split into cash vs credit/LOC liabilities (from cache).
			$accW = cf_accounts($db);
			$acc  = ['error' => $accW['error'], 'Account' => $accW['accounts']];
			if (!empty($acc['error'])) {
				$out['cash']['error']   = $acc['error'];
				$out['credit']['error'] = $acc['error'];
			} else {
				foreach (($acc['Account'] ?? []) as $a) {
					$type = $a['AccountType']    ?? '';
					$sub  = $a['AccountSubType'] ?? '';
					$bal  = (float)($a['CurrentBalance'] ?? 0);
					$name = $a['Name'] ?? '';

					$qid = (string)($a['Id'] ?? '');
					if ($type === 'Bank') {
						$out['cash']['accounts'][] = ['name' => $name, 'balance' => $bal];
						$out['cash']['total'] += $bal;
						$out['qb_accounts'][] = ['id' => $qid, 'name' => $name, 'type' => 'bank', 'balance' => $bal];
					} elseif ($type === 'Credit Card') {
						$out['credit']['accounts'][] = ['name' => $name, 'balance' => $bal, 'kind' => 'Credit Card'];
						$out['credit']['total'] += $bal;
						$out['qb_accounts'][] = ['id' => $qid, 'name' => $name, 'type' => 'credit', 'balance' => $bal];
					} elseif (stripos($sub, 'LineOfCredit') !== false
						   || $type === 'Long Term Liability'
						   || ($type === 'Other Current Liability' && (stripos($sub, 'Loan') !== false || stripos($sub, 'LineOfCredit') !== false))) {
						$out['credit']['accounts'][] = ['name' => $name, 'balance' => $bal, 'kind' => 'Line of Credit / Loan'];
						$out['credit']['total'] += $bal;
						$out['qb_accounts'][] = ['id' => $qid, 'name' => $name, 'type' => 'loc', 'balance' => $bal];
					}
				}
			}

			// Open bills (money you owe vendors, with due dates) — from cache.
			$billW = cf_bills($db);
			$bills = ['error' => $billW['error'], 'Bill' => $billW['bills']];
			if (!empty($bills['error'])) {
				$out['bills']['error'] = $bills['error'];
			} else {
				foreach (($bills['Bill'] ?? []) as $b) {
					$bal = (float)($b['Balance'] ?? 0);
					if ($bal <= 0) continue;
					$out['bills']['items'][] = [
						'vendor' => $b['VendorRef']['name'] ?? 'Vendor',
						'balance'=> $bal,
						'due'    => $b['DueDate'] ?? '',
						'date'   => $b['TxnDate'] ?? '',
						'source' => 'QuickBooks bill',
					];
					$out['bills']['total'] += $bal;
				}
			}

		}

		// Money owed to YOU = open / unpaid Shopify orders (NOT income — this is
		// expected future cash, shown separately and never added to the forecast).
		$arR = cf_ar($db);
		if (!empty($arR['error'])) { $out['ar']['error'] = $arR['error']; }
		else { $out['ar']['items'] = $arR['items']; $out['ar']['total'] = $arR['total']; }

		// MRP unpaid purchase orders (what you still owe suppliers on placed POs).
		try {
			$sql = "SELECT o.id, o.orderref, o.ordval, o.paidamt, o.orderdate,
			               p.partno, p.desc AS pdesc, m.name AS supplier
			        FROM `orders` o
			        LEFT JOIN `parts` p ON p.id = o.partid
			        LEFT JOIN `manufacturers` m ON m.id = p.manufacturer
			        WHERE o.paidamt < o.ordval
			        ORDER BY o.orderdate ASC";
			foreach ($db->query($sql) as $r) {
				$bal = (float)$r['ordval'] - (float)$r['paidamt'];
				if ($bal <= 0.005) continue;
				$out['pos']['items'][] = [
					'ref'     => $r['orderref'],
					'supplier'=> $r['supplier'] ?: '—',
					'part'    => trim(($r['partno'] ?? '') . ' ' . ($r['pdesc'] ?? '')),
					'balance' => $bal,
					'date'    => $r['orderdate'],
				];
				$out['pos']['total'] += $bal;
			}
		} catch (Throwable $e) { /* orders table issue — leave POs empty */ }

		// Manually-entered balances (authoritative when QuickBooks is stale).
		$out['manual'] = load_manual_balances($db);

		// Effective balances: prefer manual entries when present, else QuickBooks.
		$out['eff_cash']      = !empty($out['manual']['bank'])   ? $out['manual']['bank_total']   : $out['cash']['total'];
		$out['eff_credit']    = !empty($out['manual']['credit']) ? $out['manual']['credit_total'] : $out['credit']['total'];
		$out['cash_source']   = !empty($out['manual']['bank'])   ? 'manual' : 'quickbooks';
		$out['credit_source'] = !empty($out['manual']['credit']) ? 'manual' : 'quickbooks';

		// Derived totals.
		$out['ar_total']  = $out['ar']['total'];                             // owed to you (Shopify open/unpaid orders)
		$out['ap_total']  = $out['bills']['total'] + $out['pos']['total'];   // owed by you (bills + POs)
		$out['net_quick'] = $out['eff_cash'] + $out['ar_total'] - $out['ap_total'];

		// Pay-planner queue: every obligation with a due date, soonest first.
		// POs have no due date in the MRP, so they sort to the end as "no date".
		$queue = [];
		foreach ($out['bills']['items'] as $b) {
			$queue[] = ['what' => $b['vendor'], 'detail' => $b['source'], 'amount' => $b['balance'], 'due' => $b['due']];
		}
		foreach ($out['pos']['items'] as $p) {
			$queue[] = ['what' => $p['supplier'], 'detail' => 'PO ' . $p['ref'] . ' · ' . $p['part'], 'amount' => $p['balance'], 'due' => ''];
		}
		usort($queue, function($a, $b) {
			if ($a['due'] === '' && $b['due'] === '') return 0;
			if ($a['due'] === '') return 1;   // no-date items last
			if ($b['due'] === '') return -1;
			return strcmp($a['due'], $b['due']);
		});
		// Running cash position after paying each obligation in order.
		$running = $out['eff_cash'];
		foreach ($queue as &$q) {
			$running -= $q['amount'];
			$q['running'] = $running;
		}
		unset($q);
		$out['queue'] = $queue;

		return $out;
	}

	/** Ensure the manual-balances table exists (and has the debt-payment column). */
	function ensure_cash_balances_table($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS cash_balances (
			id            INT AUTO_INCREMENT PRIMARY KEY,
			label         VARCHAR(120) NOT NULL,
			acct_type     VARCHAR(12)  NOT NULL DEFAULT 'bank',  -- bank | credit | loc
			balance       DECIMAL(12,2) NOT NULL DEFAULT 0,
			credit_limit  DECIMAL(12,2) NULL,
			monthly_payment DECIMAL(12,2) NULL,
			as_of         DATE NULL,
			note          VARCHAR(255) NULL,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id       INT NULL
		) ENGINE=InnoDB");
		// Add columns for tables created before they existed (ignore duplicates).
		try { $db->exec("ALTER TABLE cash_balances ADD COLUMN monthly_payment DECIMAL(12,2) NULL"); }
		catch (Throwable $e) { /* already there */ }
		try { $db->exec("ALTER TABLE cash_balances ADD COLUMN qb_account_id VARCHAR(40) NULL"); }
		catch (Throwable $e) { /* already there */ }
		try { $db->exec("ALTER TABLE cash_balances ADD COLUMN apr DECIMAL(5,2) NULL"); }
		catch (Throwable $e) { /* already there */ }
	}

	/**
	 * Manually-entered account balances, each with a "date of accuracy" (as_of).
	 * Bank accounts are cash assets; credit/loc are debts (with optional limit so
	 * we can show available credit). These are authoritative for the cash position
	 * because QuickBooks balances can lag reality.
	 */
	function load_manual_balances($db) {
		$res = [
			'bank' => [], 'credit' => [],
			'bank_total' => 0.0, 'credit_total' => 0.0,
			'credit_limit_total' => 0.0, 'credit_available' => 0.0,
			'oldest_asof' => null, 'due_count' => 0, 'update_days' => 7,
		];
		try {
			ensure_cash_balances_table($db);
			$updDays   = balance_update_days($db);
			$res['due_count']   = 0;
			$res['update_days'] = $updDays;
			foreach ($db->query("SELECT * FROM cash_balances ORDER BY acct_type, label") as $r) {
				$daysOld = (!empty($r['as_of']) && $r['as_of'] !== '0000-00-00')
					? (int)floor((strtotime(date('Y-m-d')) - strtotime($r['as_of'])) / 86400) : null;
				$due = ($daysOld === null || $daysOld >= $updDays);
				if ($due) $res['due_count']++;
				$row = [
					'id'      => (int)$r['id'],
					'label'   => $r['label'],
					'balance' => (float)$r['balance'],
					'limit'   => $r['credit_limit'] !== null ? (float)$r['credit_limit'] : null,
					'payment' => isset($r['monthly_payment']) && $r['monthly_payment'] !== null ? (float)$r['monthly_payment'] : 0.0,
					'apr'     => isset($r['apr']) && $r['apr'] !== null ? (float)$r['apr'] : null,
					'qb_id'   => $r['qb_account_id'] ?? '',
					'as_of'   => $r['as_of'],
					'days_old'=> $daysOld,
					'due'     => $due,
					'note'    => $r['note'],
					'type'    => $r['acct_type'],
				];
				if ($r['acct_type'] === 'bank') {
					$res['bank'][] = $row;
					$res['bank_total'] += $row['balance'];
				} else {
					$row['kind'] = $r['acct_type'] === 'loc' ? 'Line of Credit' : 'Credit Card';
					$res['credit'][] = $row;
					$res['credit_total'] += $row['balance'];
					if ($row['limit'] !== null) $res['credit_limit_total'] += $row['limit'];
				}
				if (!empty($r['as_of']) && ($res['oldest_asof'] === null || $r['as_of'] < $res['oldest_asof'])) {
					$res['oldest_asof'] = $r['as_of'];
				}
			}
			$res['credit_available'] = $res['credit_limit_total'] - $res['credit_total'];
		} catch (Throwable $e) { /* table issue — return empty */ }
		return $res;
	}

	/**
	 * Projected monthly sales for the next 12 months, derived from last year's
	 * Shopify sales in the same calendar month (a simple prior-year baseline).
	 * Returns [ ['label'=>'Jul 2026','ym'=>'2026-07','projected'=>1234.56], ... ].
	 * Pulls dollar totals per month from the `orders`+Shopify history if available;
	 * falls back to an empty projection when Shopify isn't connected.
	 */
	/**
	 * Build the forward sales projection AND a prior-year reconciliation.
	 * Projects each month from the prior-year SAME month, preferring QuickBooks
	 * actual income (cash basis = money truly received, handles Net-60) and
	 * falling back to Shopify net sales when QB has no figure. Also returns a
	 * month-by-month Shopify-vs-QuickBooks comparison for the prior year so the
	 * user can confirm the numbers.
	 */
	function cashflow_projection($db, $months = 12, $growthPct = 0.0) {
		$start = strtotime(date('Y-m-01'));
		$rows  = [];
		for ($i = 0; $i < $months; $i++) {
			$ts = strtotime("+$i month", $start);
			$rows[] = ['label' => date('M Y', $ts), 'ym' => date('Y-m', $ts), 'projected' => 0.0,
			           'prior_shopify' => null, 'prior_qb' => null, 'basis' => 'none'];
		}

		$priorFrom = date('Y-m-01', strtotime($rows[0]['ym'] . '-01 -1 year'));
		$priorTo   = date('Y-m-t',  strtotime("+" . ($months - 1) . " month", strtotime($priorFrom)));

		// Served from the nightly cache (wide window covers any rolling prior year).
		$shop = cf_revenue($db);
		$qb   = cf_income($db);

		$mult = 1 + ($growthPct / 100.0);
		foreach ($rows as &$m) {
			$pYm  = date('Y-m', strtotime($m['ym'] . '-01 -1 year'));
			$sVal = isset($shop['by_month'][$pYm]) ? (float)$shop['by_month'][$pYm] : null;
			$qVal = isset($qb['by_month'][$pYm])   ? (float)$qb['by_month'][$pYm]   : null;
			$m['prior_shopify'] = $sVal;
			$m['prior_qb']      = $qVal;
			// Prefer QuickBooks actual income (true cash); fall back to Shopify.
			if ($qVal !== null && $qVal != 0) { $m['projected'] = round($qVal * $mult, 2); $m['basis'] = 'qb'; }
			elseif ($sVal !== null)           { $m['projected'] = round($sVal * $mult, 2); $m['basis'] = 'shopify'; }
		}
		unset($m);

		// Prior-year reconciliation table (Shopify sales vs QB income, by month).
		$reconcile = [];
		for ($i = 0; $i < $months; $i++) {
			$ym = date('Y-m', strtotime("+$i month", strtotime($priorFrom)));
			$s  = isset($shop['by_month'][$ym]) ? (float)$shop['by_month'][$ym] : null;
			$q  = isset($qb['by_month'][$ym])   ? (float)$qb['by_month'][$ym]   : null;
			$reconcile[] = ['label' => date('M Y', strtotime($ym . '-01')), 'shopify' => $s, 'qb' => $q,
			                'diff' => ($s !== null && $q !== null) ? $s - $q : null];
		}

		return ['rows' => $rows, 'reconcile' => $reconcile,
		        'shop_error' => $shop['error'] ?? null, 'qb_error' => $qb['error'] ?? null];
	}

	/** Recurring monthly expenses entered by the user (the editable "cash out" list). */
	function ensure_cash_expenses_table($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS cash_expenses (
			id         INT AUTO_INCREMENT PRIMARY KEY,
			label      VARCHAR(120) NOT NULL,
			amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
			category   VARCHAR(60) NULL,
			active     TINYINT NOT NULL DEFAULT 1,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id    INT NULL
		) ENGINE=InnoDB");
	}

	function load_recurring_expenses($db) {
		$res = ['items' => [], 'total' => 0.0];
		try {
			ensure_cash_expenses_table($db);
			foreach ($db->query("SELECT * FROM cash_expenses WHERE active = 1 ORDER BY category, label") as $r) {
				$amt = (float)$r['amount'];
				$res['items'][] = ['id' => (int)$r['id'], 'label' => $r['label'], 'amount' => $amt, 'category' => $r['category']];
				$res['total'] += $amt;
			}
		} catch (Throwable $e) {}
		return $res;
	}

	/**
	 * Build the rolling 12-month forecast.
	 *   Cash in   = projected sales (Shopify prior-year, optional growth %).
	 *   Cash out  = recurring expenses (or QuickBooks estimate if none entered)
	 *               + planned debt payments + bills/POs due that month.
	 *   Running   = ending cash carried forward; debt shrinks by payments.
	 */
	function build_cashflow_forecast($db, $data, $months = 12, $growthPct = 0.0) {
		$projection = cashflow_projection($db, $months, $growthPct);
		$proj       = $projection['rows'];
		$recur      = load_recurring_expenses($db);
		$recurMo    = $recur['total'];

		// "Both": if no recurring items entered, fall back to a QuickBooks estimate.
		$qbEstimate = null;
		if ($recurMo <= 0) {
			$est = cf_expense($db);
			if (empty($est['error']) && $est['monthly'] > 0) { $qbEstimate = $est['monthly']; $recurMo = $est['monthly']; }
		}

		// Planned monthly debt payment (sum across credit/LOC accounts).
		$debtBalance = $data['manual']['credit_total'];
		$debtPayMo   = 0.0;
		foreach ($data['manual']['credit'] as $c) { $debtPayMo += (float)($c['payment'] ?? 0); }

		// Map bills + POs into the month they're due (POs with no due date → month 1).
		$dueByYm = [];
		foreach ($data['bills']['items'] as $b) {
			$ym = $b['due'] ? substr($b['due'], 0, 7) : date('Y-m');
			$dueByYm[$ym] = ($dueByYm[$ym] ?? 0) + $b['balance'];
		}
		$firstYm = $proj[0]['ym'] ?? date('Y-m');
		foreach ($data['pos']['items'] as $p) {
			$dueByYm[$firstYm] = ($dueByYm[$firstYm] ?? 0) + $p['balance'];
		}

		$rows  = [];
		$cash  = $data['eff_cash'];
		$debt  = $debtBalance;
		foreach ($proj as $m) {
			$ym       = $m['ym'];
			$income   = (float)($m['projected'] ?? 0);
			$onetime  = (float)($dueByYm[$ym] ?? 0);
			$pay      = min($debtPayMo, $debt);          // don't overpay the balance
			$out      = $recurMo + $onetime + $pay;
			$net      = $income - $out;
			$cash    += $net;
			$debt     = max(0, $debt - $pay);

			$rows[] = [
				'label'      => $m['label'],
				'ym'         => $ym,
				'income'     => $income,
				'basis'      => $m['basis'],
				'recurring'  => $recurMo,
				'onetime'    => $onetime,
				'debt_pay'   => $pay,
				'cash_out'   => $out,
				'net'        => $net,
				'end_cash'   => $cash,
				'end_debt'   => $debt,
			];
		}

		return [
			'rows'         => $rows,
			'reconcile'    => $projection['reconcile'],
			'shop_error'   => $projection['shop_error'],
			'qb_error'     => $projection['qb_error'],
			'recur_total'  => $recur['total'],
			'recur_items'  => $recur['items'],
			'qb_estimate'  => $qbEstimate,
			'debt_pay_mo'  => $debtPayMo,
			'start_cash'   => $data['eff_cash'],
			'start_debt'   => $debtBalance,
			'growth_pct'   => $growthPct,
		];
	}

	// ── QuickBooks / Shopify nightly cache ────────────────────────────────────
	// The Cash Flow page reads these from a local table (fast). A nightly cron
	// (and a manual "Refresh now") calls cashflow_sync() to repopulate them. Each
	// wrapper serves cache by default, fetches live when the cache is empty or
	// $fresh, and falls back to stale cache if a live fetch errors.

	function cf_cache_ensure($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS data_cache (
			ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");
	}
	function cf_cache_set($db, $k, $v) {
		try { cf_cache_ensure($db);
			$db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")
			   ->execute([$k, json_encode($v)]);
		} catch (Throwable $e) {}
	}
	function cf_cache_get($db, $k, $default = null) {
		try { cf_cache_ensure($db);
			$s = $db->prepare("SELECT cval FROM data_cache WHERE ckey = ?"); $s->execute([$k]);
			$r = $s->fetch();
			if ($r && $r['cval'] !== null) { $d = json_decode($r['cval'], true); return $d === null ? $default : $d; }
		} catch (Throwable $e) {}
		return $default;
	}
	function cf_synced_at($db) { return cf_cache_get($db, '__synced_at', null); }

	function cf_company($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'qb_company', null); if ($c !== null) return $c; }
		if (!qb_is_connected()) return (string)cf_cache_get($db, 'qb_company', '');
		$r = qb_query("SELECT * FROM CompanyInfo");
		if (!empty($r['error'])) return (string)cf_cache_get($db, 'qb_company', '');
		$name = $r['CompanyInfo'][0]['CompanyName'] ?? '';
		cf_cache_set($db, 'qb_company', $name);
		return $name;
	}
	function cf_accounts($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'qb_accounts', null); if ($c !== null) return ['error' => null, 'accounts' => $c, 'cached' => true]; }
		if (!qb_is_connected()) { $c = cf_cache_get($db, 'qb_accounts', null); return ['error' => $c === null ? 'QuickBooks is not connected.' : null, 'accounts' => $c ?? [], 'cached' => $c !== null]; }
		$r = qb_query("SELECT * FROM Account WHERE Active = true");
		if (!empty($r['error'])) { $c = cf_cache_get($db, 'qb_accounts', null); return ['error' => $c !== null ? null : $r['error'], 'accounts' => $c ?? [], 'cached' => $c !== null]; }
		$a = $r['Account'] ?? []; cf_cache_set($db, 'qb_accounts', $a);
		return ['error' => null, 'accounts' => $a, 'cached' => false];
	}
	function cf_bills($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'qb_bills', null); if ($c !== null) return ['error' => null, 'bills' => $c, 'cached' => true]; }
		if (!qb_is_connected()) { $c = cf_cache_get($db, 'qb_bills', null); return ['error' => $c === null ? 'QuickBooks is not connected.' : null, 'bills' => $c ?? [], 'cached' => $c !== null]; }
		$r = qb_query("SELECT * FROM Bill WHERE Balance > '0' ORDERBY DueDate ASC MAXRESULTS 200");
		if (!empty($r['error'])) { $c = cf_cache_get($db, 'qb_bills', null); return ['error' => $c !== null ? null : $r['error'], 'bills' => $c ?? [], 'cached' => $c !== null]; }
		$b = $r['Bill'] ?? []; cf_cache_set($db, 'qb_bills', $b);
		return ['error' => null, 'bills' => $b, 'cached' => false];
	}
	function cf_income($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'qb_income', null); if ($c !== null) return ['error' => null, 'by_month' => $c]; }
		if (!function_exists('qb_monthly_income') || !qb_is_connected()) { $c = cf_cache_get($db, 'qb_income', null); return ['error' => $c === null ? 'not connected' : null, 'by_month' => $c ?? []]; }
		$r = qb_monthly_income(date('Y-m-01', strtotime('-25 month')), date('Y-m-d'), true);
		if (!empty($r['error'])) { $c = cf_cache_get($db, 'qb_income', null); return ['error' => $c !== null ? null : $r['error'], 'by_month' => $c ?? []]; }
		cf_cache_set($db, 'qb_income', $r['by_month'] ?? []);
		return ['error' => null, 'by_month' => $r['by_month'] ?? []];
	}
	function cf_expense($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'qb_expense', null); if ($c !== null) return ['error' => null, 'monthly' => (float)$c]; }
		if (!function_exists('qb_monthly_expense_estimate') || !qb_is_connected()) { $c = cf_cache_get($db, 'qb_expense', null); return ['error' => $c === null ? 'not connected' : null, 'monthly' => (float)($c ?? 0)]; }
		$r = qb_monthly_expense_estimate(3);
		if (!empty($r['error'])) { $c = cf_cache_get($db, 'qb_expense', null); return ['error' => $c !== null ? null : $r['error'], 'monthly' => (float)($c ?? 0)]; }
		cf_cache_set($db, 'qb_expense', $r['monthly'] ?? 0);
		return ['error' => null, 'monthly' => (float)($r['monthly'] ?? 0)];
	}
	function cf_revenue($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'shop_revenue', null); if ($c !== null) return ['error' => null, 'by_month' => $c]; }
		if (!function_exists('shopify_revenue_in_range') || !shopify_is_configured()) { $c = cf_cache_get($db, 'shop_revenue', null); return ['error' => $c === null ? 'not connected' : null, 'by_month' => $c ?? []]; }
		try { $r = shopify_revenue_in_range(date('Y-m-01', strtotime('-25 month')), date('Y-m-d')); }
		catch (Throwable $e) { $c = cf_cache_get($db, 'shop_revenue', null); return ['error' => $c !== null ? null : $e->getMessage(), 'by_month' => $c ?? []]; }
		if (!empty($r['error'])) { $c = cf_cache_get($db, 'shop_revenue', null); return ['error' => $c !== null ? null : $r['error'], 'by_month' => $c ?? []]; }
		cf_cache_set($db, 'shop_revenue', $r['by_month'] ?? []);
		return ['error' => null, 'by_month' => $r['by_month'] ?? []];
	}
	function cf_ar($db, $fresh = false) {
		if (!$fresh) { $c = cf_cache_get($db, 'shop_ar', null); if ($c !== null) return $c; }
		if (!function_exists('shopify_open_receivables') || !shopify_is_configured()) { $c = cf_cache_get($db, 'shop_ar', null); return $c ?? ['error' => 'Shopify is not connected.', 'items' => [], 'total' => 0]; }
		try { $r = shopify_open_receivables(); }
		catch (Throwable $e) { $c = cf_cache_get($db, 'shop_ar', null); return $c ?? ['error' => $e->getMessage(), 'items' => [], 'total' => 0]; }
		if (!empty($r['error'])) { $c = cf_cache_get($db, 'shop_ar', null); return $c ?? ['error' => $r['error'], 'items' => [], 'total' => 0]; }
		$v = ['error' => null, 'items' => $r['items'] ?? [], 'total' => $r['total'] ?? 0];
		cf_cache_set($db, 'shop_ar', $v);
		return $v;
	}

	/** Refresh every cached dataset (QuickBooks + Shopify) the Cash Flow page uses. */
	function cashflow_sync($db) {
		$log = [];
		$steps = [
			'QuickBooks company'   => fn() => cf_company($db, true),
			'QuickBooks accounts'  => fn() => cf_accounts($db, true),
			'QuickBooks bills'     => fn() => cf_bills($db, true),
			'QuickBooks income'    => fn() => cf_income($db, true),
			'QuickBooks expenses'  => fn() => cf_expense($db, true),
			'Shopify receivables'  => fn() => cf_ar($db, true),
			'Shopify revenue'      => fn() => cf_revenue($db, true),
		];
		foreach ($steps as $name => $fn) {
			try { $r = $fn(); $err = is_array($r) ? ($r['error'] ?? null) : null; $log[] = ($err ? '⚠ ' : '✓ ') . $name . ($err ? ' — ' . $err : ''); }
			catch (Throwable $e) { $log[] = '⚠ ' . $name . ' — ' . $e->getMessage(); }
		}
		cf_cache_set($db, '__synced_at', date('Y-m-d H:i:s'));
		return $log;
	}

	/** How many days before a manual account balance is "due" for its formal update. Default 7. */
	function balance_update_days($db) {
		try { $v = setting_get($db, 'balance_update_days'); if ($v !== null && $v !== '') return max(1, (int)$v); }
		catch (Throwable $e) {}
		return 7;
	}

	/** Shopify Capital loan repayment as a % of sales (cash out). Default 25%. */
	function shopify_loan_pct($db) {
		try { $v = setting_get($db, 'shopify_loan_pct'); if ($v !== null && $v !== '') return max(0.0, (float)$v); }
		catch (Throwable $e) {}
		return 25.0;
	}

	/** Minimum cash to keep in the bank all year (extra above this goes to debt). Default $30k. */
	function cash_buffer($db) {
		try { $v = setting_get($db, 'cash_buffer'); if ($v !== null && $v !== '') return max(0.0, (float)$v); }
		catch (Throwable $e) {}
		return 30000.0;
	}

	/** Monthly amount set aside for taxes (accrues, paid at quarter end). Default 0. */
	function tax_monthly($db) {
		try { $v = setting_get($db, 'tax_monthly'); if ($v !== null && $v !== '') return max(0.0, (float)$v); }
		catch (Throwable $e) {}
		return 0.0;
	}

	/**
	 * Raw-material POs always go on credit cards — recommend which card for each.
	 * Assigns each upcoming order (from the reorder list) to the LOWEST-APR card
	 * that still has available credit (cheapest to carry while the avalanche pays
	 * the highest-APR cards down first). Returns [['part','cost','card','note']].
	 */
	function build_po_card_plan($db, $data) {
		$cards = [];
		foreach (($data['manual']['credit'] ?? []) as $c) {
			$avail = ($c['limit'] !== null) ? max(0.0, (float)$c['limit'] - (float)$c['balance']) : INF;
			$cards[] = ['label' => $c['label'], 'apr' => $c['apr'], 'avail' => $avail];
		}
		// Lowest APR first (nulls last); tiebreak most available.
		usort($cards, function($a, $b) {
			$aa = $a['apr'] === null ? INF : $a['apr']; $bb = $b['apr'] === null ? INF : $b['apr'];
			return $aa <=> $bb ?: $b['avail'] <=> $a['avail'];
		});

		$plan = [];
		foreach (cashflow_reorder_suggestions($db, 8) as $po) {
			$cost = (float)$po['cost'];
			$assigned = null;
			foreach ($cards as &$cd) {
				if ($cd['avail'] >= $cost) { $assigned = $cd['label']; $cd['avail'] -= $cost; break; }
			}
			unset($cd);
			$plan[] = [
				'part' => $po['part'], 'order' => $po['order'], 'cost' => $cost,
				'card' => $assigned,
				'note' => $assigned ? '' : 'No single card has enough open credit — split it or pay a card down first.',
			];
		}
		return $plan;
	}

	/** Manual cash-in / cash-out events placed in a specific month + week. */
	function ensure_cash_events_table($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS cash_events (
			id         INT AUTO_INCREMENT PRIMARY KEY,
			etype      VARCHAR(3)  NOT NULL DEFAULT 'out',  -- in | out
			label      VARCHAR(160) NOT NULL,
			amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
			ym         VARCHAR(7) NOT NULL,                 -- YYYY-MM
			week       TINYINT NOT NULL DEFAULT 1,          -- 1..4
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id    INT NULL
		) ENGINE=InnoDB");
	}

	function load_cash_events($db) {
		$res = ['by_ym' => [], 'all' => [], 'labels_in' => [], 'labels_out' => []];
		try {
			ensure_cash_events_table($db);
			$li = []; $lo = [];
			foreach ($db->query("SELECT * FROM cash_events ORDER BY ym, week, id") as $r) {
				$ev = ['id' => (int)$r['id'], 'etype' => $r['etype'] === 'in' ? 'in' : 'out',
				       'label' => $r['label'], 'amount' => (float)$r['amount'],
				       'ym' => $r['ym'], 'week' => max(1, min(4, (int)$r['week']))];
				$res['all'][] = $ev;
				$res['by_ym'][$ev['ym']][$ev['etype']][] = $ev;
				if ($ev['etype'] === 'in') $li[$ev['label']] = true; else $lo[$ev['label']] = true;
			}
			$res['labels_in']  = array_keys($li);
			$res['labels_out'] = array_keys($lo);
		} catch (Throwable $e) {}
		return $res;
	}

	/**
	 * Raw materials that are below their stock level and should be ordered, with
	 * MOQ-rounded order qty and lead time. Light DB-only heuristic (no Shopify),
	 * used for near-month "order raw materials" advice.
	 */
	function cashflow_reorder_suggestions($db, $limit = 6) {
		$onOrder = [];
		try { foreach ($db->query("SELECT partid, SUM(qty - recqty) AS v FROM orders WHERE (qty - recqty) > 0 GROUP BY partid") as $r) $onOrder[$r['partid']] = max(0, (int)$r['v']); }
		catch (Throwable $e) {}
		$out = [];
		try {
			foreach ($db->query("SELECT id, partno, `desc`, qoh, bsl, imoq, lead_time, cost FROM parts WHERE bsl > 0") as $p) {
				$need = (int)$p['bsl'] - (int)$p['qoh'] - (int)($onOrder[$p['id']] ?? 0);
				if ($need <= 0) continue;
				$moq   = max(1, (int)$p['imoq']);
				$order = (int)(ceil($need / $moq) * $moq);
				$out[] = ['part' => $p['partno'], 'desc' => $p['desc'], 'order' => $order,
				          'lead_days' => (int)($p['lead_time'] ?? 45), 'cost' => round($order * (float)$p['cost'], 2), 'need' => $need];
			}
		} catch (Throwable $e) {}
		usort($out, fn($a, $b) => $b['need'] <=> $a['need']);
		return array_slice($out, 0, $limit);
	}

	/**
	 * Assemble 12 rolling month blocks (current month → +11). Each block merges
	 * automatic flows (projected sales, the Shopify-loan % cash-out, recurring
	 * expenses, planned debt payments, bills/POs due) with the user's manual
	 * cash-in/out events, and produces per-month totals, running cash, and advice.
	 */
	function build_month_blocks($db, $data, $forecast, $events) {
		$loanPct = shopify_loan_pct($db);
		$buffer  = cash_buffer($db);
		$taxMo   = tax_monthly($db);

		// Card balances we simulate paying down (avalanche = highest APR first).
		$cards = [];
		foreach (($data['manual']['credit'] ?? []) as $c) {
			$cards[] = ['label' => $c['label'], 'apr' => $c['apr'],
			            'bal' => max(0.0, (float)$c['balance']), 'min' => max(0.0, (float)($c['payment'] ?? 0))];
		}
		// Priority order for the avalanche: highest APR first (nulls last).
		$order = array_keys($cards);
		usort($order, function($a, $b) use ($cards) {
			$aa = $cards[$a]['apr'] === null ? -1 : $cards[$a]['apr'];
			$bb = $cards[$b]['apr'] === null ? -1 : $cards[$b]['apr'];
			return $bb <=> $aa ?: $cards[$b]['bal'] <=> $cards[$a]['bal'];
		});

		$reorder = cashflow_reorder_suggestions($db);
		$cash    = (float)$data['eff_cash'];
		$reserve = 0.0;                 // tax reserve, accrues monthly, paid at quarter end
		$blocks  = [];

		foreach (($forecast['rows'] ?? []) as $i => $row) {
			$ym  = $row['ym'];
			$mon = (int)date('n', strtotime($ym . '-01'));
			$in = []; $out = [];

			// ── Cash in ──
			if ($row['income'] > 0) $in[] = ['label' => 'Projected sales (' . ($row['basis'] === 'qb' ? 'QB income' : 'Shopify') . ')', 'amount' => (float)$row['income'], 'week' => 0, 'source' => 'auto'];
			foreach (($events['by_ym'][$ym]['in'] ?? []) as $e) $in[] = ['label' => $e['label'], 'amount' => $e['amount'], 'week' => $e['week'], 'source' => 'manual', 'id' => $e['id']];
			$inTotal = array_sum(array_map(fn($x) => $x['amount'], $in));

			// ── Cash out BEFORE card payments ──
			$loan    = round((float)$row['income'] * $loanPct / 100, 2);
			$taxSet  = $taxMo;
			if ($loan > 0)              $out[] = ['label' => 'Shopify Capital (' . rtrim(rtrim(number_format($loanPct, 2), '0'), '.') . '% of sales)', 'amount' => $loan, 'week' => 0, 'source' => 'auto'];
			if ($row['recurring'] > 0)  $out[] = ['label' => 'Recurring expenses', 'amount' => (float)$row['recurring'], 'week' => 0, 'source' => 'auto'];
			if ($row['onetime'] > 0)    $out[] = ['label' => 'Bills & POs due', 'amount' => (float)$row['onetime'], 'week' => 0, 'source' => 'auto'];
			if ($taxSet > 0)            $out[] = ['label' => 'Tax reserve set-aside', 'amount' => $taxSet, 'week' => 0, 'source' => 'auto'];
			foreach (($events['by_ym'][$ym]['out'] ?? []) as $e) $out[] = ['label' => $e['label'], 'amount' => $e['amount'], 'week' => $e['week'], 'source' => 'manual', 'id' => $e['id']];

			$outBeforeCards = array_sum(array_map(fn($x) => $x['amount'], $out));
			$cashBeforeCards = $cash + $inTotal - $outBeforeCards;

			// ── Tax reserve: accrue monthly, pay (from reserve) at quarter end ──
			$reserve += $taxSet;
			$taxPayment = 0.0;
			if (in_array($mon, [3, 6, 9, 12], true) && $reserve > 0) { $taxPayment = $reserve; $reserve = 0.0; }

			// ── Debt avalanche: minimums on all, extra (above buffer) to highest APR ──
			$pay = array_fill(0, count($cards), 0.0);
			$minTotal = 0.0;
			foreach ($cards as $k => $c) { $m = min($c['min'], $c['bal']); $pay[$k] = $m; $minTotal += $m; }
			$extraPool = max(0.0, $cashBeforeCards - $buffer - $minTotal);
			$targetIdx = null;
			foreach ($order as $k) {
				if ($extraPool <= 0) break;
				$remaining = $cards[$k]['bal'] - $pay[$k];
				if ($remaining <= 0) continue;
				$add = min($extraPool, $remaining);
				$pay[$k] += $add; $extraPool -= $add;
				if ($targetIdx === null) $targetIdx = $k;
			}
			$cardPayments = []; $cardTotal = 0.0;
			foreach ($cards as $k => &$c) {
				if ($pay[$k] <= 0 && $c['bal'] <= 0) continue;
				$c['bal'] = max(0.0, $c['bal'] - $pay[$k]);
				$cardTotal += $pay[$k];
				if ($pay[$k] > 0) $cardPayments[] = ['label' => $c['label'], 'apr' => $c['apr'], 'amount' => round($pay[$k], 2), 'is_target' => ($k === $targetIdx), 'paid_off' => ($c['bal'] <= 0.005)];
			}
			unset($c);
			if ($cardTotal > 0) $out[] = ['label' => 'Card payments (avalanche)', 'amount' => round($cardTotal, 2), 'week' => 0, 'source' => 'auto'];

			$outTotal = $outBeforeCards + $cardTotal;
			$net      = $inTotal - $outTotal;
			$cash     = $cashBeforeCards - $cardTotal;
			$endDebt  = array_sum(array_map(fn($c) => $c['bal'], $cards));

			// ── Advice ──
			$advice = [];
			if ($cash < 0)            $advice[] = ['kind' => 'warn', 'text' => 'Cash goes negative (ending $' . number_format($cash, 0) . '). Reduce card extra or pull income forward.'];
			elseif ($cash < $buffer)  $advice[] = ['kind' => 'warn', 'text' => 'Ends below your $' . number_format($buffer, 0) . ' buffer ($' . number_format($cash, 0) . '). Paying minimums only this month.'];
			if ($targetIdx !== null && $pay[$targetIdx] > $cards[$targetIdx]['min']) {
				$t = $cards[$targetIdx];
				$advice[] = ['kind' => 'good', 'text' => 'Focus debt paydown on ' . $t['label'] . ($t['apr'] !== null ? ' (' . rtrim(rtrim(number_format($t['apr'], 2), '0'), '.') . '% APR)' : '') . ' — pay $' . number_format($pay[$targetIdx], 0) . '; minimums on the rest.'];
			}
			foreach ($cardPayments as $cp) if ($cp['paid_off']) $advice[] = ['kind' => 'good', 'text' => '🎉 ' . $cp['label'] . ' paid off this month.'];
			if ($taxPayment > 0)      $advice[] = ['kind' => 'info', 'text' => 'Quarterly taxes ~$' . number_format($taxPayment, 0) . ' due — covered by the tax reserve you set aside.'];
			if ($i === 0) {
				$poPlan = build_po_card_plan($db, $data);
				if (!empty($poPlan)) {
					$lines = [];
					foreach (array_slice($poPlan, 0, 4) as $p) $lines[] = $p['part'] . ' $' . number_format($p['cost'], 0) . ($p['card'] ? ' → ' . $p['card'] : ' → (needs credit room)');
					$advice[] = ['kind' => 'info', 'text' => 'Raw-material POs to place on cards: ' . implode('; ', $lines) . '.'];
				}
			}

			$blocks[] = [
				'ym' => $ym, 'label' => $row['label'],
				'cash_in' => $in, 'cash_out' => $out,
				'in_total' => $inTotal, 'out_total' => $outTotal, 'net' => $net,
				'end_cash' => $cash, 'end_debt' => $endDebt,
				'card_payments' => $cardPayments,
				'tax_setaside' => $taxSet, 'tax_payment' => $taxPayment, 'tax_reserve' => $reserve,
				'advice' => $advice,
			];
		}

		return ['blocks' => $blocks, 'loan_pct' => $loanPct, 'start_cash' => (float)$data['eff_cash'],
		        'buffer' => $buffer, 'tax_monthly' => $taxMo, 'po_card_plan' => build_po_card_plan($db, $data)];
	}
