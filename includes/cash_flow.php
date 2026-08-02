<?php
/* ============================================================
   CASH FLOW — forecaster engine (the NEW module).
   Distinct from includes/cashflow.php (the old "Cash Management"
   engine), but REUSES its loaders for balances/QBO/Shopify.

   Month-0 opening balances are the LIVE cash_balances. The old
   month-grained freeze (cf_snapshots / cf_balance_monthly) is
   gone: it locked the baseline to QuickBooks balances, which are
   not reliable enough to build a month on. Daily balance history
   (cf_balance_daily) is still captured.

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

	// Per-account balance history, one row per account per day. The durable,
	// queryable record; cash_balances holds only current state.
	//
	// The month-opening freeze this used to sit beside (cf_snapshots,
	// cf_balance_monthly) has been removed — it baselined the forecast on
	// QuickBooks balances that aren't reliable enough to lock a month to. Those
	// two tables are no longer created or read; existing ones are left in place
	// rather than dropped, so nothing already captured is destroyed.
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
}

/* ---- knobs (new setting + reused ones) -------------------------------- */

/** Avg sales-tax % applied to taxable revenue (Model B tax-inclusive). Default 8. */
function cf_avg_sales_tax_pct($db) {
	try { $v = setting_get($db, 'avg_sales_tax_pct'); if ($v !== null && $v !== '') return max(0.0, (float)$v); }
	catch (Throwable $e) {}
	return 8.0;
}

/* ---- accounts ---------------------------------------------------------- */

/**
 * Set (or add) a facility's ceiling in the SHARED loc_ceilings setting. The ceiling is a
 * property of the line of credit (facility), not of an individual loan drawing on it — two
 * loans on one $85k line share the one $85k. Editing it here updates Cash Management too.
 */
function cf_set_facility_ceiling($db, $name, $ceiling) {
	$name = trim((string)$name); if ($name === '') return;
	$ceiling = max(0.0, (float)$ceiling);
	$list = [];
	$raw = setting_get($db, 'loc_ceilings');
	if ($raw) { $dec = json_decode($raw, true); if (is_array($dec)) $list = $dec; }
	$found = false;
	foreach ($list as &$c) {
		if (isset($c['name']) && strcasecmp(trim((string)$c['name']), $name) === 0) { $c['ceiling'] = $ceiling; $found = true; break; }
	}
	unset($c);
	if (!$found) $list[] = ['name' => $name, 'ceiling' => $ceiling];
	setting_set($db, 'loc_ceilings', json_encode(array_values($list)));
}

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
/**
 * Split credit headroom into the two kinds that must never be added together
 * behind one label: LOC room — drawable as cash into the bank — and card room,
 * which is purchasing power only. Both floored at 0 per facility so an overdrawn
 * line reads as no room rather than as negative room quietly funding another.
 *
 * A LOC ceiling belongs to its FACILITY and is counted ONCE across every loan
 * drawing on it. Deriving it from the live loans (rather than from the raw
 * loc_ceilings setting) keeps it immune to stale or duplicated facility entries.
 */
function cf_room_split($cards, $locs) {
	$cardLimit = 0.0; $cardUsed = 0.0;
	foreach ($cards as $c) { $cardLimit += (float)($c['limit'] ?? 0); $cardUsed += (float)$c['balance']; }

	$facs = [];
	foreach ($locs as $l) {
		$fk = (trim((string)($l['facility'] ?? '')) !== '') ? strtolower(trim($l['facility'])) : ('#' . $l['id']);
		if (!isset($facs[$fk])) $facs[$fk] = ['ceiling' => 0.0, 'drawn' => 0.0];
		$facs[$fk]['ceiling'] = max($facs[$fk]['ceiling'], (float)$l['ceiling']);
		$facs[$fk]['drawn']  += (float)$l['drawn'];
	}
	$locLimit = 0.0; $locDrawn = 0.0; $locRoom = 0.0;
	foreach ($facs as $f) { $locLimit += $f['ceiling']; $locDrawn += $f['drawn']; $locRoom += max(0.0, $f['ceiling'] - $f['drawn']); }

	return [
		'card' => max(0.0, $cardLimit - $cardUsed), 'card_limit' => $cardLimit, 'card_used' => $cardUsed,
		'loc'  => $locRoom,                         'loc_limit'  => $locLimit,  'loc_drawn' => $locDrawn,
	];
}

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
				'ceiling' => $ceiling, 'facility' => trim((string)($c['loc_name'] ?? '')), 'apr' => $c['apr'], 'payment' => (float)($c['payment'] ?? 0),
				'due_day' => $c['due_day'], 'payout' => $payout,
				'payout_pct' => $payout ? $payoutPct : null,
				'planned' => (float)($c['payment'] ?? 0), 'as_of' => $c['as_of'], 'qb_id' => $c['qb_id'] ?? '',
			];
		} else {
			$cards[] = [
				'id' => (int)$c['id'], 'label' => $c['label'], 'balance' => (float)$c['balance'],
				'limit' => $c['limit'] !== null ? (float)$c['limit'] : null, 'apr' => $c['apr'],
				// Per-card override when set, otherwise the global card_min_pct setting.
				// Issuers do not all use the same formula, and at a high APR the real
				// minimum can exceed a flat 4% of balance.
				'min_pct' => $c['min_pct'] !== null ? (float)$c['min_pct'] : $minPct,
				'min_pct_own' => $c['min_pct'] !== null ? (float)$c['min_pct'] : null,
				'min_pct_default' => $minPct,
				'min_floor' => $minFloor,
				'planned' => (float)($c['payment'] ?? 0), 'as_of' => $c['as_of'], 'qb_id' => $c['qb_id'] ?? '',
			];
		}
	}

	$room        = cf_room_split($cards, $locs);
	$cardRoom    = $room['card'];
	$locRoom     = $room['loc'];
	$creditLimit = $room['card_limit'] + $room['loc_limit'];
	$creditUsed  = (float)($m['card_total'] ?? 0) + (float)($m['loc_total'] ?? 0);

	return [
		'banks' => $banks, 'cards' => $cards, 'locs' => $locs,
		'start_cash' => (float)($m['bank_total'] ?? 0),
		'credit_limit' => $creditLimit, 'credit_used' => $creditUsed,
		'shopify_loan' => $shopLoan,
		// Two different kinds of headroom, kept apart everywhere they're shown: a LOC
		// can be drawn as cash into the bank, a credit card can only buy things. Only
		// 'credit_available' (their sum) belongs in a combined total.
		'loc_available'  => $locRoom,
		'card_available' => $cardRoom,
		'credit_available' => $cardRoom + $locRoom,
		// The same split applied to what's OWED. Revolving card balances and
		// amortizing LOC/loan draws get paid down differently, so the two are worth
		// reading apart; together they are exactly 'credit_used'.
		'card_debt' => $room['card_used'],
		'loan_debt' => $room['loc_drawn'],
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
	// The month-opening freeze is gone: it baselined the forecast on QuickBooks
	// balances, and those are not trustworthy enough to lock a month to. The
	// projection now always opens from the current live balances, which are the
	// ones the Accounts view shows and a human maintains.
	return cf_live_accounts($db);
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
/**
 * A record points at a pay method that is not a routable facility. Log it once
 * per run so a mis-tagged record is discoverable instead of silently absorbed.
 */
function cf_log_unroutable($rec, $pay) {
	static $seen = [];
	$key = ($rec['id'] ?? '?') . '|' . $pay;
	if (isset($seen[$key])) return;
	$seen[$key] = true;
	error_log('[cash_flow] record #' . ($rec['id'] ?? '?') . ' "' . ($rec['description'] ?? '') . '"'
		. ' has unroutable pay method "' . $pay . '" — treated as cash.');
}

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
 * $opts: horizon_start, growth, buffer, tax_pct, shop_pct, debt_target.
 * Returns 12 row arrays.
 */
function cf_compute($accounts, $records, $opts) {
	$hs      = $opts['horizon_start'];
	$growth  = $opts['growth'] ?? 0;
	$buffer  = (float)($opts['buffer'] ?? 0);
	$taxPct  = (float)($opts['tax_pct'] ?? 0);
	$shopPct = (float)($opts['shop_pct'] ?? 25) / 100.0;
	$target  = $opts['debt_target'] ?? null;   // null => use planned total (scale 1)
	// Monthly debt budget for the snowball. When set, every facility pays its
	// minimum and ONE target facility absorbs the rest; when the target clears, the
	// target re-acquires and the same budget rolls onto the next one. Recomputed
	// every month from that month's balances — the whole point is that it cascades.
	// Null => fall back to each facility's own 'planned' figure.
	$debtBudget = isset($opts['debt_budget']) ? (float)$opts['debt_budget'] : null;

	$cash = (float)$accounts['start_cash'];
	$shop = (float)$accounts['shopify_loan'];
	$lim  = (float)$accounts['credit_limit'];

	// per-facility state: cards + non-payout LOCs each track bal/apr/planned
	$fac = [];
	// 'chg' collects this month's charges separately from 'bal' so interest can be
	// taken on the average balance rather than on a balance that already absorbed them.
	// 'min_pct'/'min_floor' are carried so the snowball can recompute a card's minimum
	// from its CURRENT balance each month rather than freezing month-0's figure.
	foreach ($accounts['cards'] as $c) $fac[$c['label']] = [
		'bal' => (float)$c['balance'], 'apr' => ($c['apr'] !== null ? (float)$c['apr'] : 0) / 100.0,
		'planned' => (float)($c['planned'] ?? 0), 'chg' => 0.0, 'is_card' => true,
		'min_pct' => (float)($c['min_pct'] ?? 0), 'min_floor' => (float)($c['min_floor'] ?? 0)];
	foreach ($accounts['locs'] as $l) if (empty($l['payout'])) $fac[$l['label']] = [
		'bal' => (float)$l['drawn'], 'apr' => ($l['apr'] !== null ? (float)$l['apr'] : 0) / 100.0,
		'planned' => (float)($l['planned'] ?? 0), 'chg' => 0.0, 'is_card' => false,
		'min_pct' => 0.0, 'min_floor' => 0.0];
	$other = 0.0;   // charges routed to the payout facility (no APR interest)

	// Scale denominator must match the base actually being paid, or a debt_target
	// would scale against a total the forecast never uses.
	$plannedTotal = ($debtBudget !== null) ? ($debtBudget ?: 1.0) : (cf_planned_total($accounts) ?: 1.0);
	$scale = ($target === null) ? 1.0 : ((float)$target / $plannedTotal);

	// Available credit = card room (netted) + per-LOC-facility room, each floored at 0 — the same
	// basis the Accounts view uses. A payout term loan (Shopify Capital, repaid from sales) is NOT a
	// revolving line, so it never counts toward available room. LOC loans are grouped by facility so
	// two loans on one line count that line's ceiling once, not twice.
	$cardLimTotal = 0.0; foreach ($accounts['cards'] as $c) $cardLimTotal += (float)($c['limit'] ?? 0);
	$locGroups = [];
	foreach ($accounts['locs'] as $l) {
		if (!empty($l['payout'])) continue;
		$fk = (($l['facility'] ?? '') !== '') ? strtolower($l['facility']) : ('#' . $l['label']);
		if (!isset($locGroups[$fk])) $locGroups[$fk] = ['ceiling' => (float)$l['ceiling'], 'labels' => []];
		$locGroups[$fk]['ceiling'] = max($locGroups[$fk]['ceiling'], (float)$l['ceiling']);
		$locGroups[$fk]['labels'][] = $l['label'];
	}

	$rows = [];
	for ($i = 0; $i < 12; $i++) {
		$income = cf_income_month($records, $i, $growth, $taxPct, $hs);
		$incGross = $income['gross'];        // what actually hits the bank — customers pay tax to us
		$reserve  = $income['collected'];    // the tax portion of it: held, not ours, owed to the state
		// Operating and Purchases report the FULL planned spend — every record that
		// posts this month, however it is paid — because that is the plan you
		// entered and it must stay visible. Reporting only the cash half made
		// card-funded spend vanish from the row entirely: Sep/Oct/Nov 2026 showed
		// Purchases 0 against $7,000 / $10,300 / $12,800 of planned digital
		// marketing.
		//
		// The block still foots, via $onCredit: the credit-funded portion is
		// subtracted on its own line, because it is spend but not CASH out this
		// month. It raises the facility balance instead, and surfaces under
		// Position as higher Credit used, more interest, and less available credit.
		//
		//     Operating + Purchases − Charged to credit
		//       + Shopify payback + Debt paydown  ==  Total cash out
		$op = 0.0; $pur = 0.0; $onCredit = 0.0;

		// route each expense: cash hits the bank; card/LOC raises that facility's balance
		$expCash = 0.0;
		foreach (['operating', 'purchase'] as $k) {
			foreach (($records[$k] ?? []) as $r) {
				if (!cf_posts($r, $i, $hs)) continue;
				$a = (float)$r['amount'];
				$pay = $r['pay'] ?? 'cash';
				// Unroutable pay methods fall back to CASH, never to a silent bucket.
				// $fac holds cards + non-payout LOCs only, so a record charged to a
				// payout facility (Shopify Capital) — or to a card that was since
				// renamed or deleted — used to land in $other, which counts toward
				// credit used but never accrues interest and is never paid down: the
				// charge would sit there forever, unpayable and invisible. Treating it
				// as cash is the conservative reading (the money did leave) and it
				// shows up on the ending-cash line instead of disappearing.
				$isCash = ($pay === 'cash');
				if ($isCash) $expCash += $a;
				elseif (isset($fac[$pay])) { $fac[$pay]['chg'] += $a; $onCredit += $a; }
				else { $expCash += $a; $isCash = true; cf_log_unroutable($r, $pay); }
				if ($k === 'operating') $op += $a; else $pur += $a;
			}
		}

		$shopPay = min(round($incGross * $shopPct), $shop);

		// Interest accrues per facility onto its balance (NOT a cash-out); the planned
		// payment pays it down. Order matters and used to be wrong:
		//
		//   was:  charges -> interest on (opening + ALL charges) -> payment
		//   now:  charges -> payment -> interest on the AVERAGE balance
		//
		// The old order charged a full month of interest on money that had already
		// been repaid, and gave every charge a full month regardless of when in the
		// month it landed. Both push the same way, and the model ran ~76% above the
		// business's actual interest.
		//
		// Charges and the payment are treated as landing mid-month, so the balance
		// carried for the month averages opening + (charges - payment)/2. That is the
		// standard average-daily-balance approximation without modelling posting
		// dates we do not have. No grace-period carve-out: every facility here
		// carries a balance, so new purchases accrue from the posting date anyway.
		// ---- snowball allocation for THIS month -------------------------------
		// Every facility pays its minimum; one target absorbs whatever the budget
		// leaves over. Minimums are recomputed from this month's balances, and the
		// target is re-picked each month, so when a facility clears, its minimum
		// stops consuming budget and the surplus rolls onto the next target
		// automatically. That cascade is the entire point: allocating once from
		// month-0 balances and reusing it froze the plan and the card line only ever
		// went up.
		$planFor = [];
		if ($debtBudget !== null) {
			$mins = []; $minSum = 0.0;
			foreach ($fac as $key => $f) {
				$owed = max(0.0, (float)$f['bal'] + (float)$f['chg']);
				if ($owed <= 0) { $mins[$key] = 0.0; continue; }
				$m = $f['is_card']
					? max(round($owed * (float)$f['min_pct'] / 100.0), (float)$f['min_floor'])
					: (float)$f['planned'];
				$mins[$key] = min($owed, max(0.0, $m));   // never pay more than is owed
				$minSum += $mins[$key];
			}
			// Target = the costliest facility still carrying a balance.
			$tKey = null; $tApr = -1.0;
			foreach ($fac as $key => $f) {
				if (($mins[$key] ?? 0.0) <= 0 && max(0.0, (float)$f['bal'] + (float)$f['chg']) <= 0) continue;
				if ((float)$f['apr'] > $tApr) { $tApr = (float)$f['apr']; $tKey = $key; }
			}
			foreach ($fac as $key => $f) {
				$owed = max(0.0, (float)$f['bal'] + (float)$f['chg']);
				$pay  = $mins[$key] ?? 0.0;
				if ($key === $tKey) {
					// surplus = budget less everyone else's minimums
					$pay = max($pay, $debtBudget - ($minSum - $pay));
				}
				$planFor[$key] = min($owed, max(0.0, $pay));
			}
		}

		$interest = 0.0; $dpApplied = 0.0;
		foreach ($fac as $key => $f) {
			$open = (float)$f['bal'];
			$chg  = (float)$f['chg'];
			$base = ($debtBudget !== null) ? ($planFor[$key] ?? 0.0) : (float)$f['planned'];
			$pay  = max(0.0, min($open + $chg, round($base * $scale)));
			$avg  = max(0.0, $open + ($chg - $pay) / 2.0);
			$intr = round($avg * $f['apr'] / 12.0);
			$interest += $intr;
			$fac[$key]['bal'] = $open + $chg - $pay + $intr;
			$fac[$key]['chg'] = 0.0;
			$dpApplied += $pay;
		}

		// Sales tax is money we hold, not money we earn. Income comes in GROSS (the
		// customer really does hand us the tax), so the reserve is taken back out
		// explicitly on its way to ending cash rather than being quietly netted off
		// the income line. Ending cash is unchanged by this — gross minus the
		// reserve is the old net — but the mechanism is now visible instead of
		// buried in cf_income_month().
		$startCashM = $cash;
		$cashOut = $expCash + $shopPay + $dpApplied;
		$net  = $incGross - $cashOut;          // cash moving through the bank this month
		$cash = $startCashM + $net - $reserve; // what's actually ours at month end
		$shop = max(0.0, $shop - $shopPay);

		$credit = $other + $shop;
		foreach ($fac as $f) $credit += $f['bal'];

		// The LOC/loan slice of that credit, cards excluded: every non-payout line,
		// plus the payout term loan and anything charged against it. endCredit minus
		// this is exactly the card balance.
		$locBal = $other + $shop;
		foreach ($accounts['locs'] as $l) if (empty($l['payout'])) $locBal += (float)($fac[$l['label']]['bal'] ?? 0);
		// Floored, per-facility available credit (consistent with the Accounts view).
		$cardBalM = 0.0; foreach ($accounts['cards'] as $c) $cardBalM += (float)($fac[$c['label']]['bal'] ?? 0);
		$availC = max(0.0, $cardLimTotal - $cardBalM);
		$availL = 0.0;
		foreach ($locGroups as $g) { $gb = 0.0; foreach ($g['labels'] as $lb) $gb += (float)($fac[$lb]['bal'] ?? 0); $availL += max(0.0, $g['ceiling'] - $gb); }
		$avail = $availC + $availL;

		$rows[] = [
			'i' => $i, 'ym' => cf_add_months($hs, $i),
			'inc' => $incGross, 'inc_gross' => $incGross, 'inc_net' => $incGross - $reserve,
			'tax_collected' => $reserve,
			'online' => $income['online'], 'shows' => $income['shows'], 'wholesale' => $income['wholesale'],
			'op' => $op, 'pur' => $pur, 'onCredit' => $onCredit, 'shopPay' => $shopPay, 'dp' => $dpApplied,
			'interest' => $interest, 'cashOut' => $cashOut, 'net' => $net,
			'endCash' => $cash, 'endCredit' => $credit, 'endLoc' => $locBal, 'endCard' => $cardBalM,
			'availLoc' => $availL, 'availCard' => $availC, 'avail' => $avail, 'liquid' => $cash + $avail,
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
	// Stamp as_of to today so a synced account shows when its balance was refreshed.
	$upd = $db->prepare("UPDATE cash_balances SET balance = ?, as_of = CURDATE(), updated_at = NOW() WHERE id = ?");
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
 * Capture balances: pull QB into cash_balances and write today's DAILY history
 * row per account. Called nightly by cron/cashflow_sync.php.
 *
 * The month-opening freeze that used to live here is gone — it locked the
 * forecast's baseline to QuickBooks balances, which aren't reliable enough to
 * build a month on. Daily history stays: it's a running record, not a baseline
 * anything depends on.
 */
function cf_capture_balances($db, $source = 'cron') {
	cf_ensure_tables($db);
	$updated = cf_upsert_qb_balances($db);      // QB -> cash_balances (auto-synced accounts)
	$acc  = cf_live_accounts($db);              // now current
	$rows = cf_flatten_accounts($acc);
	$today = date('Y-m-d');

	$insD = $db->prepare("INSERT INTO cf_balance_daily
		(snap_date, account_id, label, acct_type, balance, credit_limit, apr, payout, source, captured_at)
		VALUES (?,?,?,?,?,?,?,?,?,NOW())
		ON DUPLICATE KEY UPDATE label=VALUES(label), acct_type=VALUES(acct_type), balance=VALUES(balance),
			credit_limit=VALUES(credit_limit), apr=VALUES(apr), payout=VALUES(payout), source=VALUES(source), captured_at=NOW()");
	foreach ($rows as $x) $insD->execute([$today, $x['account_id'], $x['label'], $x['acct_type'], $x['balance'], $x['credit_limit'], $x['apr'], $x['payout'], $source]);

	return ['accounts' => $acc, 'qb_updated' => $updated];
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
