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

		// Derived totals.
		$out['ar_total']  = $out['invoices']['total'];                       // owed to you (QBO)
		$out['ap_total']  = $out['bills']['total'] + $out['pos']['total'];   // owed by you (bills + POs)
		$out['net_quick'] = $out['cash']['total'] + $out['ar_total'] - $out['ap_total'];

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
		$running = $out['cash']['total'];
		foreach ($queue as &$q) {
			$running -= $q['amount'];
			$q['running'] = $running;
		}
		unset($q);
		$out['queue'] = $queue;

		return $out;
	}
