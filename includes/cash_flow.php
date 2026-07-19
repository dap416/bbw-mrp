<?php
/* ============================================================
   CASH FLOW — forecaster engine (the NEW module).
   Distinct from includes/cashflow.php (the old "Cash Management"
   engine), but REUSES its loaders for balances/QBO/Shopify.

   Month-grained snapshot model: month-0 opening balances come
   from the current month's cf_snapshots row when present, else
   fall back to the live cash_balances (so it works pre-snapshot).

   Ported from the design prototype (Cash Flow.dc.html), with the
   real business rules layered in (docs win over the prototype):
     - per-facility interest = bal * apr/12, added ONTO balance,
       NOT a cash-out.
     - sales tax MODEL B (tax-inclusive): taxable channels
       (Online+Shows) / (1+rate), Wholesale exempt -> net cash-in.
     - real avalanche + affordability math.
   ============================================================ */

require_once __DIR__ . '/cashflow.php';   // build_cashflow_data, load_manual_balances, cf_income/revenue, settings, knobs
require_once __DIR__ . '/shopify.php';    // setting_get / setting_set

/* ---- formatting -------------------------------------------------------- */

/** Money like the prototype: whole dollars, thousands sep, U+2212 minus. */
if (!function_exists('cf_money')) {
	function cf_money($n) {
		$neg = $n < -0.5;
		$s = '$' . number_format(round(abs((float)$n)));
		return $neg ? "\xE2\x88\x92" . $s : $s;   // − (U+2212)
	}
}
/** Em-dash placeholder for empty/zero cells. */
if (!function_exists('cf_dash')) { function cf_dash() { return "\xE2\x80\x94"; } }

/* ---- month helpers ----------------------------------------------------- */

/** First calendar month of the rolling horizon = the current month, 'YYYY-MM'. */
function cf_horizon_start() { return date('Y-m'); }

/** Add $n months to a 'YYYY-MM'. */
function cf_add_months($ym, $n) {
	$ts = strtotime($ym . '-01 +' . (int)$n . ' month');
	return date('Y-m', $ts);
}
/** Whole months from $a to $b (both 'YYYY-MM'); can be negative. */
function cf_month_diff($a, $b) {
	[$ay, $am] = array_map('intval', explode('-', $a));
	[$by, $bm] = array_map('intval', explode('-', $b));
	return ($by - $ay) * 12 + ($bm - $am);
}
/** ['name'=>'JUL','year'=>"'26",'ym'=>'2026-07']. */
function cf_month_label($ym) {
	$ts = strtotime($ym . '-01');
	return ['name' => strtoupper(date('M', $ts)), 'year' => "'" . date('y', $ts), 'ym' => $ym];
}
/** 12 month-column descriptors for the horizon. */
function cf_month_cols($start, $count = 12) {
	$out = [];
	for ($i = 0; $i < $count; $i++) $out[] = cf_month_label(cf_add_months($start, $i));
	return $out;
}
/** Same month, prior year — for the actuals panels. */
function cf_month_label_prior($ym) { return cf_month_label(cf_add_months($ym, -12)); }

/** Is this facility the Shopify Capital payout LOC (payback = % of sales, no APR)? */
function cf_is_payout_facility($text) {
	$t = strtolower((string)$text);
	return $t !== '' && (strpos($t, 'shopify') !== false || strpos($t, 'shop pay') !== false);
}

/* ---- schema ------------------------------------------------------------ */

function cf_ensure_tables($db) {
	// Editable forecast records: the owner-managed cash-in / cash-out plan.
	$db->exec("CREATE TABLE IF NOT EXISTS cf_records (
		id          INT AUTO_INCREMENT PRIMARY KEY,
		rtype       VARCHAR(12)  NOT NULL DEFAULT 'operating',  -- income | operating | purchase
		sub         VARCHAR(40)  NULL,                          -- income: Online|Shows|Wholesale ; else category
		amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
		description VARCHAR(200) NULL,
		note        VARCHAR(255) NULL,
		recurrence  VARCHAR(10)  NOT NULL DEFAULT 'once',       -- once | monthly | quarterly | annual
		start_ym    VARCHAR(7)   NOT NULL,                      -- YYYY-MM the record starts posting
		pay         VARCHAR(120) NOT NULL DEFAULT 'cash',       -- expenses: 'cash' or a facility label
		active      TINYINT      NOT NULL DEFAULT 1,
		updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		user_id     INT          NULL
	) ENGINE=InnoDB");

	// Frozen month-opening balance snapshots (the month-grained model).
	$db->exec("CREATE TABLE IF NOT EXISTS cf_snapshots (
		snap_ym      VARCHAR(7)   PRIMARY KEY,                  -- the month this snapshot OPENS
		captured_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		source       VARCHAR(12)  NOT NULL DEFAULT 'manual',    -- cron | seed | manual
		cash_total   DECIMAL(14,2) NOT NULL DEFAULT 0,
		credit_total DECIMAL(14,2) NOT NULL DEFAULT 0,
		data         LONGTEXT     NULL,                         -- legacy JSON blob (unused; detail now normalized below)
		note         VARCHAR(255) NULL
	) ENGINE=InnoDB");

	// Normalized per-account balance history. One row per account per day
	// (cf_balance_daily) and per opening month (cf_balance_monthly). These are
	// the durable, queryable record; cash_balances holds only current state.
	$db->exec("CREATE TABLE IF NOT EXISTS cf_balance_daily (
		snap_date    DATE         NOT NULL,
		account_id   INT          NOT NULL,                     -- cash_balances.id (stable key)
		label        VARCHAR(120) NULL,
		acct_type    VARCHAR(12)  NOT NULL DEFAULT 'bank',      -- bank | credit | loc
		balance      DECIMAL(14,2) NOT NULL DEFAULT 0,
		credit_limit DECIMAL(14,2) NULL,
		apr          DECIMAL(6,2) NULL,
		payout       TINYINT      NOT NULL DEFAULT 0,
		source       VARCHAR(12)  NOT NULL DEFAULT 'manual',    -- qb | manual | seed | cron
		captured_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (snap_date, account_id)
	) ENGINE=InnoDB");
	$db->exec("CREATE TABLE IF NOT EXISTS cf_balance_monthly (
		snap_ym      VARCHAR(7)   NOT NULL,                     -- the month this opening belongs to
		account_id   INT          NOT NULL,
		label        VARCHAR(120) NULL,
		acct_type    VARCHAR(12)  NOT NULL DEFAULT 'bank',
		balance      DECIMAL(14,2) NOT NULL DEFAULT 0,
		credit_limit DECIMAL(14,2) NULL,
		apr          DECIMAL(6,2) NULL,
		payout       TINYINT      NOT NULL DEFAULT 0,
		source       VARCHAR(12)  NOT NULL DEFAULT 'manual',
		captured_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (snap_ym, account_id)
	) ENGINE=InnoDB");
}

/* ---- knobs (new setting + reused ones) -------------------------------- */

/** Avg sales-tax % applied to taxable revenue (Model B tax-inclusive). Default 8. */
function cf_avg_sales_tax_pct($db) {
	try { $v = setting_get($db, 'avg_sales_tax_pct'); if ($v !== null && $v !== '') return max(0.0, (float)$v); }
	catch (Throwable $e) {}
	return 8.0;
}

/* ---- accounts ---------------------------------------------------------- */

/** Match a LOC row to its facility ceiling from load_manual_balances()'s loc_facilities. */
function cf_match_ceiling($facilities, $locRow) {
	$ln = strtolower(trim((string)($locRow['loc_name'] ?? '')));
	foreach ($facilities as $f) {
		if ($ln !== '' && strtolower($f['name']) === $ln) return (float)$f['ceiling'];
	}
	// fall back to this row's own credit_limit if the facility isn't matched
	return $locRow['limit'] !== null ? (float)$locRow['limit'] : 0.0;
}

/**
 * LIVE account set from cash_balances (what the Accounts view edits).
 * Returns banks[], cards[], locs[] plus start_cash / credit_limit / credit_used / shopify_loan.
 */
function cf_live_accounts($db) {
	$m       = load_manual_balances($db);
	$minPct  = card_min_pct($db);
	$minFloor= card_min_floor($db);
	$payoutPct = shopify_loan_pct($db);

	$banks = [];
	foreach (($m['bank'] ?? []) as $b) {
		$banks[] = ['id' => (int)$b['id'], 'label' => $b['label'], 'balance' => (float)$b['balance'], 'as_of' => $b['as_of'], 'qb_id' => $b['qb_id'] ?? ''];
	}

	$cards = []; $locs = []; $shopLoan = 0.0;
	foreach (($m['credit'] ?? []) as $c) {
		if (($c['type'] ?? '') === 'loc') {
			$payout  = cf_is_payout_facility($c['label'] . ' ' . ($c['loc_name'] ?? '') . ' ' . ($c['note'] ?? ''));
			$ceiling = cf_match_ceiling($m['loc_facilities'] ?? [], $c);
			if ($payout) $shopLoan += (float)$c['balance'];
			$locs[] = [
				'id' => (int)$c['id'], 'label' => $c['label'], 'drawn' => (float)$c['balance'],
				'ceiling' => $ceiling, 'apr' => $c['apr'], 'payment' => (float)($c['payment'] ?? 0),
				'due_day' => $c['due_day'], 'payout' => $payout,
				'payout_pct' => $payout ? $payoutPct : null,
				'planned' => (float)($c['payment'] ?? 0), 'as_of' => $c['as_of'], 'qb_id' => $c['qb_id'] ?? '',
			];
		} else {
			$cards[] = [
				'id' => (int)$c['id'], 'label' => $c['label'], 'balance' => (float)$c['balance'],
				'limit' => $c['limit'] !== null ? (float)$c['limit'] : null, 'apr' => $c['apr'],
				'min_pct' => $minPct, 'min_floor' => $minFloor,
				'planned' => (float)($c['payment'] ?? 0), 'as_of' => $c['as_of'], 'qb_id' => $c['qb_id'] ?? '',
			];
		}
	}

	$creditLimit = (float)($m['card_limit_total'] ?? 0) + (float)($m['loc_limit'] ?? 0);
	$creditUsed  = (float)($m['card_total'] ?? 0) + (float)($m['loc_total'] ?? 0);

	return [
		'banks' => $banks, 'cards' => $cards, 'locs' => $locs,
		'start_cash' => (float)($m['bank_total'] ?? 0),
		'credit_limit' => $creditLimit, 'credit_used' => $creditUsed,
		'shopify_loan' => $shopLoan,
		'credit_available' => max(0.0, $creditLimit - $creditUsed),
		'source' => 'live',
	];
}

/** QuickBooks accounts available to link (for the "auto-sync this account" picker). */
function cf_qb_account_options($db) {
	$out = [];
	try {
		if (!function_exists('cf_accounts')) return $out;
		$r = cf_accounts($db);
		foreach (($r['accounts'] ?? []) as $a) {
			$type = $a['AccountType'] ?? ''; $sub = $a['AccountSubType'] ?? '';
			$keep = in_array($type, ['Bank', 'Credit Card', 'Long Term Liability', 'Other Current Liability'], true)
				|| stripos($sub, 'LineOfCredit') !== false;
			if (!$keep) continue;
			$out[] = ['id' => (string)($a['Id'] ?? ''), 'name' => $a['Name'] ?? '', 'type' => $type,
				'balance' => (float)($a['CurrentBalance'] ?? 0)];
		}
	} catch (Throwable $e) {}
	return $out;
}

/**
 * OPENING account set for the projection's month 0. Prefer the current
 * month's frozen snapshot; fall back to live balances when none exists.
 */
function cf_opening_accounts($db, $ym = null) {
	$ym  = $ym ?: cf_horizon_start();
	$acc = cf_live_accounts($db);   // current metadata (APR, limits, PLANNED payments) + current balances

	// Overlay the frozen opening BALANCES for this month (if captured). The plan
	// (planned payments) stays current; only the opening balances freeze.
	$frozen = [];
	try {
		cf_ensure_tables($db);
		$s = $db->prepare("SELECT account_id, balance FROM cf_balance_monthly WHERE snap_ym = ?");
		$s->execute([$ym]);
		foreach ($s as $r) $frozen[(int)$r['account_id']] = (float)$r['balance'];
	} catch (Throwable $e) {}

	if ($frozen) {
		$sc = 0.0; $used = 0.0; $shop = 0.0;
		foreach ($acc['banks'] as &$b) { if (isset($frozen[$b['id']])) $b['balance'] = $frozen[$b['id']]; $sc += $b['balance']; } unset($b);
		foreach ($acc['cards'] as &$c) { if (isset($frozen[$c['id']])) $c['balance'] = $frozen[$c['id']]; $used += $c['balance']; } unset($c);
		foreach ($acc['locs']  as &$l) { if (isset($frozen[$l['id']])) $l['drawn'] = $frozen[$l['id']]; $used += $l['drawn']; if (!empty($l['payout'])) $shop += $l['drawn']; } unset($l);
		$acc['start_cash']       = $sc;
		$acc['credit_used']      = $used;
		$acc['shopify_loan']     = $shop;
		$acc['credit_available'] = max(0.0, $acc['credit_limit'] - $used);
		$acc['source']  = 'snapshot';
		$acc['snap_ym'] = $ym;
	}
	return $acc;
}

/** Flatten an accounts struct to per-account rows for the snapshot tables. */
function cf_flatten_accounts($acc) {
	$out = [];
	foreach ($acc['banks'] as $b) $out[] = ['account_id' => $b['id'], 'label' => $b['label'], 'acct_type' => 'bank',   'balance' => $b['balance'], 'credit_limit' => null,          'apr' => null,      'payout' => 0];
	foreach ($acc['cards'] as $c) $out[] = ['account_id' => $c['id'], 'label' => $c['label'], 'acct_type' => 'credit', 'balance' => $c['balance'], 'credit_limit' => $c['limit'],   'apr' => $c['apr'], 'payout' => 0];
	foreach ($acc['locs']  as $l) $out[] = ['account_id' => $l['id'], 'label' => $l['label'], 'acct_type' => 'loc',    'balance' => $l['drawn'],   'credit_limit' => $l['ceiling'], 'apr' => $l['apr'], 'payout' => !empty($l['payout']) ? 1 : 0];
	return $out;
}

/* ---- records ----------------------------------------------------------- */

/** Load records grouped by type. */
function cf_load_records($db) {
	$out = ['income' => [], 'operating' => [], 'purchase' => []];
	try {
		cf_ensure_tables($db);
		foreach ($db->query("SELECT * FROM cf_records WHERE active = 1 ORDER BY start_ym, id") as $r) {
			$t = in_array($r['rtype'], ['income', 'operating', 'purchase'], true) ? $r['rtype'] : 'operating';
			$out[$t][] = [
				'id' => (int)$r['id'], 'rtype' => $t, 'sub' => $r['sub'],
				'amount' => (float)$r['amount'], 'description' => $r['description'], 'note' => $r['note'],
				'recurrence' => $r['recurrence'], 'start_ym' => $r['start_ym'], 'pay' => $r['pay'] ?: 'cash',
			];
		}
	} catch (Throwable $e) {}
	return $out;
}

/** Does a record post in horizon month index $i? (once/monthly/quarterly/annual) */
function cf_posts($rec, $i, $horizonStart) {
	$cellYm = cf_add_months($horizonStart, $i);
	if ($cellYm < $rec['start_ym']) return false;
	$d = cf_month_diff($rec['start_ym'], $cellYm);   // >= 0
	switch ($rec['recurrence']) {
		case 'monthly':   return true;
		case 'quarterly': return $d % 3 === 0;
		case 'annual':    return $d % 12 === 0;
		default:          return $d === 0;            // once
	}
}

/** Sum all records of a type posting in month $i. */
function cf_sum_row($records, $type, $i, $horizonStart) {
	$s = 0.0;
	foreach (($records[$type] ?? []) as $r) if (cf_posts($r, $i, $horizonStart)) $s += (float)$r['amount'];
	return $s;
}

/** Sum income records of one channel (sub) posting in month $i. */
function cf_chan_month($records, $chan, $i, $horizonStart) {
	$s = 0.0;
	foreach (($records['income'] ?? []) as $r) if ($r['sub'] === $chan && cf_posts($r, $i, $horizonStart)) $s += (float)$r['amount'];
	return $s;
}

/**
 * MODEL B sales tax. Income figures are tax-INCLUSIVE for taxable channels
 * (Online + Shows); Wholesale is exempt. Net-to-us = taxable/(1+t) + exempt.
 * Isolated here so switching to Model A later is a one-function change.
 * Returns ['net','gross','taxable','exempt','collected','online','shows','wholesale'].
 */
function cf_income_month($records, $i, $growth, $taxPct, $horizonStart) {
	$g = 1 + ((float)$growth) / 100.0;
	$t = ((float)$taxPct) / 100.0;
	$online = 0.0; $shows = 0.0; $whole = 0.0; $other = 0.0;
	foreach (($records['income'] ?? []) as $r) {
		if (!cf_posts($r, $i, $horizonStart)) continue;
		$a = (float)$r['amount'] * $g;
		switch ($r['sub']) {
			case 'Shows':     $shows += $a; break;
			case 'Wholesale': $whole += $a; break;
			case 'Online':    $online += $a; break;
			default:          $other += $a; break;   // untagged income = taxable by default
		}
	}
	$taxable = $online + $shows + $other;
	$exempt  = $whole;
	$net     = ($t > 0 ? $taxable / (1 + $t) : $taxable) + $exempt;
	$gross   = $taxable + $exempt;
	return [
		'net' => $net, 'gross' => $gross, 'taxable' => $taxable, 'exempt' => $exempt,
		'collected' => $gross - $net,
		'online' => $online, 'shows' => $shows, 'wholesale' => $whole,
	];
}

/* ---- debt helpers ------------------------------------------------------ */

/** Sum of planned monthly payments across cards + non-payout LOCs. */
function cf_planned_total($accounts) {
	$s = 0.0;
	foreach ($accounts['cards'] as $c) $s += (float)($c['planned'] ?? 0);
	foreach ($accounts['locs'] as $l) if (empty($l['payout'])) $s += (float)($l['planned'] ?? 0);
	return $s;
}

/** Card minimum = max(balance*min_pct/100, min_floor). */
function cf_card_min($card) {
	return max(round(((float)$card['balance']) * ((float)$card['min_pct']) / 100.0), (float)$card['min_floor']);
}

/**
 * Debts in avalanche order (highest APR first). Each: label, balance, apr,
 * min, planned, focus (the single highest-APR non-payout target), is_payout.
 */
function cf_debts($accounts) {
	$arr = [];
	foreach ($accounts['cards'] as $c) {
		$arr[] = ['key' => 'card_' . $c['id'], 'id' => $c['id'], 'group' => 'cards', 'label' => $c['label'],
			'balance' => (float)$c['balance'], 'apr' => $c['apr'] !== null ? (float)$c['apr'] : null,
			'min' => cf_card_min($c), 'planned' => (float)($c['planned'] ?? 0), 'is_payout' => false, 'payout_pct' => null];
	}
	foreach ($accounts['locs'] as $l) {
		$arr[] = ['key' => 'loc_' . $l['id'], 'id' => $l['id'], 'group' => 'locs', 'label' => $l['label'],
			'balance' => (float)$l['drawn'], 'apr' => (!$l['payout'] && $l['apr'] !== null) ? (float)$l['apr'] : null,
			'min' => $l['payout'] ? null : (float)($l['payment'] ?? 0),
			'planned' => $l['payout'] ? null : (float)($l['planned'] ?? 0),
			'is_payout' => !empty($l['payout']), 'payout_pct' => $l['payout_pct'] ?? null];
	}
	usort($arr, function ($a, $b) {
		$aa = $a['apr'] === null ? -1 : $a['apr'];
		$bb = $b['apr'] === null ? -1 : $b['apr'];
		return $bb <=> $aa;
	});
	$fk = null; $fa = -1;
	foreach ($arr as $d) { if (!$d['is_payout'] && $d['apr'] !== null && $d['apr'] > $fa) { $fa = $d['apr']; $fk = $d['key']; } }
	foreach ($arr as &$d) $d['focus'] = ($d['key'] === $fk);
	unset($d);
	return $arr;
}

/** Avalanche suggestion: every non-payout debt gets its min; focus gets the remainder. */
function cf_suggest_map($accounts, $availDebt) {
	$debts = array_values(array_filter(cf_debts($accounts), fn($d) => !$d['is_payout']));
	$minSum = 0.0; foreach ($debts as $d) $minSum += (float)($d['min'] ?? 0);
	$map = [];
	foreach ($debts as $d) {
		$map[$d['key']] = $d['focus']
			? max((float)($d['min'] ?? 0), round(((float)$availDebt) - ($minSum - (float)($d['min'] ?? 0))))
			: (float)($d['min'] ?? 0);
	}
	return $map;
}

/* ---- the projection ---------------------------------------------------- */

/**
 * The 12-month per-facility snapshot projection. Faithful port of the
 * prototype's computeWith(), with Model-B tax and Shopify payback on GROSS.
 * $opts: horizon_start, growth, buffer, tax_pct, shop_pct, tax_setaside[12], debt_target.
 * Returns 12 row arrays.
 */
function cf_compute($accounts, $records, $opts) {
	$hs      = $opts['horizon_start'];
	$growth  = $opts['growth'] ?? 0;
	$buffer  = (float)($opts['buffer'] ?? 0);
	$taxPct  = (float)($opts['tax_pct'] ?? 0);
	$shopPct = (float)($opts['shop_pct'] ?? 25) / 100.0;
	$setAside= $opts['tax_setaside'] ?? array_fill(0, 12, 0.0);
	$target  = $opts['debt_target'] ?? null;   // null => use planned total (scale 1)

	$cash = (float)$accounts['start_cash'];
	$shop = (float)$accounts['shopify_loan'];
	$lim  = (float)$accounts['credit_limit'];

	// per-facility state: cards + non-payout LOCs each track bal/apr/planned
	$fac = [];
	foreach ($accounts['cards'] as $c) $fac[$c['label']] = ['bal' => (float)$c['balance'], 'apr' => ($c['apr'] !== null ? (float)$c['apr'] : 0) / 100.0, 'planned' => (float)($c['planned'] ?? 0)];
	foreach ($accounts['locs'] as $l) if (empty($l['payout'])) $fac[$l['label']] = ['bal' => (float)$l['drawn'], 'apr' => ($l['apr'] !== null ? (float)$l['apr'] : 0) / 100.0, 'planned' => (float)($l['planned'] ?? 0)];
	$other = 0.0;   // charges routed to the payout facility (no APR interest)

	$plannedTotal = cf_planned_total($accounts) ?: 1.0;
	$scale = ($target === null) ? 1.0 : ((float)$target / $plannedTotal);

	$rows = [];
	for ($i = 0; $i < 12; $i++) {
		$income = cf_income_month($records, $i, $growth, $taxPct, $hs);
		$incNet   = $income['net'];
		$incGross = $income['gross'];
		$op  = cf_sum_row($records, 'operating', $i, $hs);
		$pur = cf_sum_row($records, 'purchase', $i, $hs);
		$tax = (float)($setAside[$i] ?? 0);

		// route each expense: cash hits the bank; card/LOC raises that facility's balance
		$expCash = 0.0;
		foreach (['operating', 'purchase'] as $k) {
			foreach (($records[$k] ?? []) as $r) {
				if (!cf_posts($r, $i, $hs)) continue;
				$a = (float)$r['amount'];
				$pay = $r['pay'] ?? 'cash';
				if ($pay === 'cash') $expCash += $a;
				elseif (isset($fac[$pay])) $fac[$pay]['bal'] += $a;
				else $other += $a;
			}
		}

		$shopPay = min(round($incGross * $shopPct), $shop);

		// interest accrues per facility onto its balance (NOT a cash-out); planned pays it down
		$interest = 0.0; $dpApplied = 0.0;
		foreach ($fac as $key => $f) {
			$intr = round($f['bal'] * $f['apr'] / 12.0);
			$interest += $intr;
			$fac[$key]['bal'] += $intr;
			$pay = max(0.0, min($fac[$key]['bal'], round($f['planned'] * $scale)));
			$fac[$key]['bal'] -= $pay;
			$dpApplied += $pay;
		}

		$startCashM = $cash;
		$cashOut = $expCash + $tax + $shopPay + $dpApplied;
		$net = $incNet - $cashOut;
		$cash = $startCashM + $net;
		$shop = max(0.0, $shop - $shopPay);

		$credit = $other + $shop;
		foreach ($fac as $f) $credit += $f['bal'];
		$avail = $lim - $credit;

		$rows[] = [
			'i' => $i, 'ym' => cf_add_months($hs, $i),
			'inc' => $incNet, 'inc_gross' => $incGross, 'tax_collected' => $income['collected'],
			'online' => $income['online'], 'shows' => $income['shows'], 'wholesale' => $income['wholesale'],
			'op' => $op, 'pur' => $pur, 'tax' => $tax, 'shopPay' => $shopPay, 'dp' => $dpApplied,
			'interest' => $interest, 'cashOut' => $cashOut, 'net' => $net,
			'endCash' => $cash, 'endCredit' => $credit, 'avail' => $avail, 'liquid' => $cash + $avail,
			'cashRisk' => $cash < $buffer, 'creditTight' => $avail < 18000,
		];
	}
	return $rows;
}

/**
 * Affordability: the steady monthly debt payment that keeps ending cash at or
 * above the buffer every month (real math — min over months of headroom/(i+1)).
 */
function cf_afford_calc($accounts, $records, $opts) {
	$buffer = (float)($opts['buffer'] ?? 0);
	$zero = $opts; $zero['debt_target'] = 0.0;            // project with no planned debt payments
	$rows = cf_compute($accounts, $records, $zero);
	$P = INF; $tight = 0;
	foreach ($rows as $i => $r) {
		$cap = ($r['endCash'] - $buffer) / ($i + 1);
		if ($cap < $P) { $P = $cap; $tight = $i; }
	}
	$P = max(0.0, round($P));
	return ['amount' => $P, 'tight' => $tight, 'buffer' => $buffer, 'current' => cf_planned_total($accounts)];
}

/* ---- balance sync + snapshots ----------------------------------------- */

/** Current QuickBooks account balances keyed by QB account Id (from the cache). */
function cf_qb_balances($db) {
	$map = [];
	try {
		if (function_exists('cf_accounts')) {
			$r = cf_accounts($db);   // serves the nightly-synced cache
			foreach (($r['accounts'] ?? []) as $a) {
				$id = (string)($a['Id'] ?? '');
				if ($id !== '') $map[$id] = (float)($a['CurrentBalance'] ?? 0);
			}
		}
	} catch (Throwable $e) {}
	return $map;
}

/**
 * Refresh cash_balances.balance from QuickBooks for every account linked by
 * qb_account_id (auto-synced accounts). Unlinked accounts (e.g. Shopify
 * Capital) are left untouched — they stay manual. Returns the # updated.
 */
function cf_upsert_qb_balances($db) {
	$map = cf_qb_balances($db);
	if (!$map) return 0;
	$n = 0;
	$upd = $db->prepare("UPDATE cash_balances SET balance = ?, updated_at = NOW() WHERE id = ?");
	try {
		foreach ($db->query("SELECT id, acct_type, qb_account_id FROM cash_balances WHERE qb_account_id IS NOT NULL AND qb_account_id <> ''") as $r) {
			$qid = (string)$r['qb_account_id'];
			if (!isset($map[$qid])) continue;
			$bal = $map[$qid];
			// This module stores credit/LOC balances as a positive "amount owed";
			// QuickBooks can report liabilities with the opposite sign, so normalize.
			if (in_array($r['acct_type'], ['credit', 'loc'], true)) $bal = abs($bal);
			$upd->execute([$bal, (int)$r['id']]);
			$n++;
		}
	} catch (Throwable $e) {}
	return $n;
}

/**
 * Capture balances: pull QB into cash_balances, write today's DAILY snapshot,
 * and (when $freezeMonth) freeze this month's OPENING snapshot + header.
 *
 * The monthly opening is WRITE-ONCE: once a month is frozen it is NOT
 * overwritten (the whole point of the month-grained model — the baseline you
 * forecast from must not drift). Pass $force = true only for a deliberate
 * "re-freeze" correction. The daily snapshot always refreshes for the day.
 * Called nightly by cron/cashflow_sync.php and manually by the snapshot button.
 */
function cf_capture_balances($db, $freezeMonth = false, $source = 'cron', $force = false) {
	cf_ensure_tables($db);
	$updated = cf_upsert_qb_balances($db);      // QB -> cash_balances (auto-synced accounts)
	$acc  = cf_live_accounts($db);              // now current
	$rows = cf_flatten_accounts($acc);
	$today = date('Y-m-d');
	$ym    = cf_horizon_start();

	$insD = $db->prepare("INSERT INTO cf_balance_daily
		(snap_date, account_id, label, acct_type, balance, credit_limit, apr, payout, source, captured_at)
		VALUES (?,?,?,?,?,?,?,?,?,NOW())
		ON DUPLICATE KEY UPDATE label=VALUES(label), acct_type=VALUES(acct_type), balance=VALUES(balance),
			credit_limit=VALUES(credit_limit), apr=VALUES(apr), payout=VALUES(payout), source=VALUES(source), captured_at=NOW()");
	foreach ($rows as $x) $insD->execute([$today, $x['account_id'], $x['label'], $x['acct_type'], $x['balance'], $x['credit_limit'], $x['apr'], $x['payout'], $source]);

	$froze = null; $alreadyFrozen = false;
	if ($freezeMonth && !$force && cf_month_has_opening($db, $ym)) {
		$alreadyFrozen = true;   // opening already set this month — leave it intact
	} elseif ($freezeMonth) {
		$insM = $db->prepare("INSERT INTO cf_balance_monthly
			(snap_ym, account_id, label, acct_type, balance, credit_limit, apr, payout, source, captured_at)
			VALUES (?,?,?,?,?,?,?,?,?,NOW())
			ON DUPLICATE KEY UPDATE label=VALUES(label), acct_type=VALUES(acct_type), balance=VALUES(balance),
				credit_limit=VALUES(credit_limit), apr=VALUES(apr), payout=VALUES(payout), source=VALUES(source), captured_at=NOW()");
		foreach ($rows as $x) $insM->execute([$ym, $x['account_id'], $x['label'], $x['acct_type'], $x['balance'], $x['credit_limit'], $x['apr'], $x['payout'], $source]);
		$db->prepare("INSERT INTO cf_snapshots (snap_ym, captured_at, source, cash_total, credit_total)
			VALUES (?, NOW(), ?, ?, ?)
			ON DUPLICATE KEY UPDATE captured_at=NOW(), source=VALUES(source), cash_total=VALUES(cash_total), credit_total=VALUES(credit_total)")
			->execute([$ym, $source, $acc['start_cash'], $acc['credit_used']]);
		$froze = $ym;
	}
	return ['accounts' => $acc, 'qb_updated' => $updated, 'froze' => $froze, 'already_frozen' => $alreadyFrozen];
}

/** Does this month already have a frozen opening? */
function cf_month_has_opening($db, $ym) {
	try {
		cf_ensure_tables($db);
		$s = $db->prepare("SELECT COUNT(*) AS c FROM cf_balance_monthly WHERE snap_ym = ?"); $s->execute([$ym]);
		return (int)$s->fetch()['c'] > 0;
	} catch (Throwable $e) { return false; }
}

/** Header row for the current month's snapshot (for the UI "opening as of" label). */
function cf_current_snapshot($db) {
	try {
		cf_ensure_tables($db);
		$s = $db->prepare("SELECT snap_ym, captured_at, source, cash_total, credit_total FROM cf_snapshots WHERE snap_ym = ?");
		$s->execute([cf_horizon_start()]);
		$r = $s->fetch();
		return $r ?: null;
	} catch (Throwable $e) { return null; }
}

/* ---- seed -------------------------------------------------------------- */

/**
 * Seed cf_records once (only when empty) from real data so the forecast opens
 * populated: income from prior-year QBO/Shopify monthly baseline, operating
 * from recurring expenses, purchases from scheduled FP imports.
 */
function cf_seed_records_if_empty($db) {
	try {
		cf_ensure_tables($db);
		$n = (int)$db->query("SELECT COUNT(*) AS c FROM cf_records")->fetch()['c'];
		if ($n > 0) return;
	} catch (Throwable $e) { return; }

	$hs  = cf_horizon_start();
	$ins = $db->prepare("INSERT INTO cf_records (rtype, sub, amount, description, recurrence, start_ym, pay) VALUES (?,?,?,?,?,?,?)");

	// Income — prior-year same-month baseline (QBO cash-basis preferred, else Shopify net sales).
	try {
		$qb   = function_exists('cf_income')  ? cf_income($db)  : ['by_month' => []];
		$shop = function_exists('cf_revenue') ? cf_revenue($db) : ['by_month' => []];
		for ($i = 0; $i < 12; $i++) {
			$ym    = cf_add_months($hs, $i);
			$prior = cf_add_months($ym, -12);
			$val   = $qb['by_month'][$prior] ?? ($shop['by_month'][$prior] ?? null);
			if ($val !== null && (float)$val > 0) {
				$ins->execute(['income', 'Online', round((float)$val, 2), 'Prior-year baseline', 'once', $ym, 'cash']);
			}
		}
	} catch (Throwable $e) {}

	// Operating — active recurring expenses (monthly, from this month).
	try {
		$rec = load_recurring_expenses($db);
		foreach (($rec['items'] ?? []) as $it) {
			$ins->execute(['operating', $it['category'] ?: null, round((float)$it['amount'], 2), $it['label'], 'monthly', $hs, 'cash']);
		}
	} catch (Throwable $e) {}

	// Purchases — scheduled finished-product imports (one-time in their order month).
	try {
		foreach (load_fp_purchases($db) as $p) {
			$ym = (!empty($p['order_ym']) && $p['order_ym'] >= $hs) ? $p['order_ym'] : $hs;
			$ins->execute(['purchase', 'FP import', round((float)$p['total'], 2), $p['item'], 'once', $ym, $p['card_label'] ?: 'cash']);
		}
	} catch (Throwable $e) {}
}
