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
			'invoices'=> ['items' => [], 'total' => 0.0, 'error' => null],     // QBO AR
			'pos'     => ['items' => [], 'total' => 0.0],                       // MRP unpaid POs
		];

		if (qb_is_connected()) {
			// Company name (also acts as a connectivity check).
			$ci = qb_query("SELECT * FROM CompanyInfo");
			if (empty($ci['error'])) $out['qb_company'] = $ci['CompanyInfo'][0]['CompanyName'] ?? '';

			// Accounts — split into cash vs credit/LOC liabilities.
			$acc = qb_query("SELECT * FROM Account WHERE Active = true");
			if (!empty($acc['error'])) {
				$out['cash']['error']   = $acc['error'];
				$out['credit']['error'] = $acc['error'];
			} else {
				foreach (($acc['Account'] ?? []) as $a) {
					$type = $a['AccountType']    ?? '';
					$sub  = $a['AccountSubType'] ?? '';
					$bal  = (float)($a['CurrentBalance'] ?? 0);
					$name = $a['Name'] ?? '';

					if ($type === 'Bank') {
						$out['cash']['accounts'][] = ['name' => $name, 'balance' => $bal];
						$out['cash']['total'] += $bal;
					} elseif ($type === 'Credit Card') {
						$out['credit']['accounts'][] = ['name' => $name, 'balance' => $bal, 'kind' => 'Credit Card'];
						$out['credit']['total'] += $bal;
					} elseif (stripos($sub, 'LineOfCredit') !== false
						   || $type === 'Long Term Liability'
						   || ($type === 'Other Current Liability' && (stripos($sub, 'Loan') !== false || stripos($sub, 'LineOfCredit') !== false))) {
						$out['credit']['accounts'][] = ['name' => $name, 'balance' => $bal, 'kind' => 'Line of Credit / Loan'];
						$out['credit']['total'] += $bal;
					}
				}
			}

			// Open bills (money you owe vendors, with due dates).
			$bills = qb_query("SELECT * FROM Bill WHERE Balance > '0' ORDERBY DueDate ASC MAXRESULTS 200");
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

			// Open invoices (money owed to you, from QuickBooks).
			$inv = qb_query("SELECT * FROM Invoice WHERE Balance > '0' ORDERBY DueDate ASC MAXRESULTS 200");
			if (!empty($inv['error'])) {
				$out['invoices']['error'] = $inv['error'];
			} else {
				foreach (($inv['Invoice'] ?? []) as $iv) {
					$bal = (float)($iv['Balance'] ?? 0);
					if ($bal <= 0) continue;
					$out['invoices']['items'][] = [
						'customer' => $iv['CustomerRef']['name'] ?? 'Customer',
						'balance'  => $bal,
						'due'      => $iv['DueDate'] ?? '',
						'date'     => $iv['TxnDate'] ?? '',
					];
					$out['invoices']['total'] += $bal;
				}
			}
		}

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
		$out['ar_total']  = $out['invoices']['total'];                       // owed to you (QBO)
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
		// Add the column for tables created before it existed (ignore duplicate).
		try { $db->exec("ALTER TABLE cash_balances ADD COLUMN monthly_payment DECIMAL(12,2) NULL"); }
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
			'oldest_asof' => null,
		];
		try {
			ensure_cash_balances_table($db);
			foreach ($db->query("SELECT * FROM cash_balances ORDER BY acct_type, label") as $r) {
				$row = [
					'id'      => (int)$r['id'],
					'label'   => $r['label'],
					'balance' => (float)$r['balance'],
					'limit'   => $r['credit_limit'] !== null ? (float)$r['credit_limit'] : null,
					'payment' => isset($r['monthly_payment']) && $r['monthly_payment'] !== null ? (float)$r['monthly_payment'] : 0.0,
					'as_of'   => $r['as_of'],
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
	function cashflow_sales_projection($db, $months = 12, $startTs = null, $growthPct = 0.0) {
		$out = [];
		$start = $startTs ?: strtotime(date('Y-m-01'));
		for ($i = 0; $i < $months; $i++) {
			$ts  = strtotime("+$i month", $start);
			$out[] = ['label' => date('M Y', $ts), 'ym' => date('Y-m', $ts), 'month' => (int)date('n', $ts), 'projected' => null];
		}
		if (!function_exists('shopify_revenue_in_range') || !shopify_is_configured()) return $out;

		// One call: prior-year revenue by month across the whole horizon window.
		try {
			$priorFrom = date('Y-m-01', strtotime($out[0]['ym'] . '-01 -1 year'));
			$priorTo   = date('Y-m-t',  strtotime("+" . ($months - 1) . " month", strtotime($priorFrom)));
			$rev = shopify_revenue_in_range($priorFrom, $priorTo);
			if (empty($rev['error'])) {
				$mult = 1 + ($growthPct / 100.0);
				foreach ($out as &$m) {
					$priorYm = date('Y-m', strtotime($m['ym'] . '-01 -1 year'));
					$m['projected'] = round((float)($rev['by_month'][$priorYm] ?? 0) * $mult, 2);
				}
				unset($m);
			}
		} catch (Throwable $e) {}
		return $out;
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
		$proj     = cashflow_sales_projection($db, $months, strtotime(date('Y-m-01')), $growthPct);
		$recur    = load_recurring_expenses($db);
		$recurMo  = $recur['total'];

		// "Both": if no recurring items entered, fall back to a QuickBooks estimate.
		$qbEstimate = null;
		if ($recurMo <= 0 && function_exists('qb_monthly_expense_estimate') && qb_is_connected()) {
			$est = qb_monthly_expense_estimate(3);
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
			'recur_total'  => $recur['total'],
			'recur_items'  => $recur['items'],
			'qb_estimate'  => $qbEstimate,
			'debt_pay_mo'  => $debtPayMo,
			'start_cash'   => $data['eff_cash'],
			'start_debt'   => $debtBalance,
			'growth_pct'   => $growthPct,
		];
	}
