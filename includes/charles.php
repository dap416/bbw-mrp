<?php
/**
 * Charles — the AI CPA / business planner. Shared library: assembles the full
 * financial + operational snapshot Charles reasons over, and (in later phases)
 * his system prompt, durable memory, and task-driven action applier.
 */
require_once(__DIR__.'/fns.php');
require_once(__DIR__.'/shopify.php');
require_once(__DIR__.'/quickbooks.php');
require_once(__DIR__.'/cashflow.php');
require_once(__DIR__.'/planning.php');
require_once(__DIR__.'/anthropic.php');

/**
 * The complete picture: cash, cards, the line of credit (with APRs/limits),
 * receivables/payables, QuickBooks connection, inventory valuation, build needs,
 * 12-month cash-flow projection, and where the money gets tight — plus a list of
 * data Charles cannot see yet.
 */
function charles_snapshot($db) {
	$data     = build_cashflow_data($db);
	$forecast = build_cashflow_forecast($db, $data, 12, 0.0);
	$recur    = load_recurring_expenses($db);
	$events   = load_cash_events($db);
	$monthData= build_month_blocks($db, $data, $forecast, $events);
	$blocks   = $monthData['blocks'];

	// Split manual balances into bank / credit cards / line(s) of credit.
	$man = $data['manual'] ?? [];
	$cards = []; $locs = [];
	foreach (($man['credit'] ?? []) as $c) {
		$lim = ($c['limit'] !== null && $c['limit'] !== '') ? (float)$c['limit'] : null;
		$row = [
			'label' => $c['label'], 'balance' => (float)$c['balance'],
			'limit' => $lim, 'apr' => ($c['apr'] !== null && $c['apr'] !== '') ? (float)$c['apr'] : null,
			'monthly_payment' => ($c['payment'] !== null && $c['payment'] !== '') ? (float)$c['payment'] : null,
			'note' => $c['note'] ?? '',
			'available' => $lim !== null ? max(0.0, $lim - (float)$c['balance']) : null,
		];
		if (($c['type'] ?? '') === 'loc') $locs[] = $row; else $cards[] = $row;
	}
	// Facility-based figures: the LOC has ONE shared limit across its loan draws.
	$cardDebt   = (float)($man['card_total']     ?? array_sum(array_map(fn($x) => $x['balance'], $cards)));
	$locDebt    = (float)($man['loc_total']      ?? array_sum(array_map(fn($x) => $x['balance'], $locs)));
	$cardAvail  = (float)($man['card_available'] ?? 0);
	$locAvail   = (float)($man['loc_available']  ?? 0);
	$locLimitV  = (float)($man['loc_limit']      ?? 0);
	$locPayment = (float)($man['loc_payment']    ?? 0);

	// Inventory valuation (mirrors includes/header.php).
	$ohVal   = (float)($db->query("SELECT SUM(`qoh`*`cost`) v FROM `parts`")->fetch()['v'] ?? 0);
	$ooVal   = (float)($db->query("SELECT SUM(`ordval` - (`recqty`/`qty`*`ordval`)) v FROM `orders` WHERE (`qty`-`recqty` > 0)")->fetch()['v'] ?? 0);
	$notPaid = (float)($db->query("SELECT SUM(`ordval` - `paidamt`) v FROM `orders` WHERE `paidamt` < `ordval` AND `qty` > `recqty`")->fetch()['v'] ?? 0);
	$bnr = $ooVal - $notPaid; $bookVal = $ohVal + $bnr;

	// Build recommendations (next 90 days) — what to build and whether raw stock allows it.
	$plan = fp_build_plan($db, date('Y-m-d', strtotime('+90 days')), 0);

	// Runway: the first projected month the bank dips below the safety buffer, and the low point.
	$buffer = (float)cash_buffer($db);
	$runwayMonths = null; $lowPoint = null; $i = 0;
	foreach ($blocks as $b) {
		if ($b['end_cash'] === null) continue;
		if ($lowPoint === null || $b['end_cash'] < $lowPoint['end_cash']) $lowPoint = ['ym' => $b['ym'], 'label' => $b['label'], 'end_cash' => round($b['end_cash'])];
		if ($runwayMonths === null && $b['end_cash'] < $buffer) $runwayMonths = $i;
		$i++;
	}

	// Compact 12-month blocks for charts + the AI.
	$months = [];
	foreach ($blocks as $b) {
		$months[] = [
			'ym' => $b['ym'], 'label' => $b['label'],
			'in' => round($b['in_total']), 'out' => round($b['out_total']), 'net' => round($b['net']),
			'end_cash' => $b['end_cash'] === null ? null : round($b['end_cash']),
			'end_debt' => round($b['end_debt']),
			'credit_out' => round($b['credit_out_total'] ?? 0),
			'tax_reserve' => round($b['tax_reserve'] ?? 0),
			'card_payments' => array_map(fn($c) => ['label' => $c['label'], 'amount' => round($c['amount']), 'apr' => $c['apr'], 'balance' => round($c['balance']), 'type' => $c['type'] ?? 'credit'], $b['card_payments'] ?? []),
		];
	}

	$fc = [];
	foreach (($forecast['rows'] ?? []) as $r) {
		$fc[] = ['ym' => $r['ym'], 'label' => $r['label'], 'income' => round($r['income'] ?? 0), 'cash_out' => round($r['cash_out'] ?? 0), 'is_past' => !empty($r['is_past'])];
	}

	// Data gaps Charles should flag / ask for (expanded in later phases).
	// Deep QuickBooks (weekly-cached; read cache only here so the page renders fast —
	// the briefing endpoint refreshes it under its spinner).
	$deep = charles_qb_deep($db, false);
	$yoy  = charles_expense_yoy($deep);
	$roi  = charles_tradeshow_roi($db, false);

	// Upcoming credit-card charges (manual credit-out events not yet paid). These do NOT
	// reduce bank cash — they land on a card — but they ARE money committed, so they must
	// be visible alongside bills + POs or "what I owe" looks too low.
	$creditOutUpcoming = 0.0; $creditOutItems = [];
	foreach (($events['all'] ?? []) as $e) {
		if (($e['etype'] ?? '') === 'out' && ($e['paidby'] ?? 'cash') === 'card' && empty($e['paid'])) {
			$creditOutUpcoming += (float)$e['amount'];
			$creditOutItems[] = ['label' => $e['label'], 'amount' => round((float)$e['amount']), 'ym' => $e['ym']];
		}
	}

	$gaps = [];
	if (empty($data['qb_connected'])) $gaps[] = 'QuickBooks is not connected — connect it on Integrations so I can see your real P&L, Balance Sheet, bills and full expense history.';
	if (empty($cards) && empty($locs)) $gaps[] = 'No credit cards or line of credit are on file — add them (with APR + limit) on the Cash Flow page so I can plan financing and payoff.';
	if (!empty($data['qb_connected']) && empty($deep['years'])) $gaps[] = 'I haven\'t pulled your full P&L/expense history yet — it loads with the briefing (or hit Re-analyze).';
	if (!empty($roi['missing_costs'])) {
		if (!empty($roi['qb_expense_total'])) $gaps[] = 'QuickBooks shows about $' . number_format((float)$roi['qb_expense_total']) . ' in tradeshow expense (' . $roi['qb_expense_year'] . '), but the account names don\'t map to specific shows — so I use the total for an overall ROI. Rename your show expense accounts after the shows (or enter per-show costs below) for exact show-by-show ROI.';
		else $gaps[] = 'I can\'t find tradeshow costs in QuickBooks yet — name your show expense accounts after the shows (e.g. "Tradeshow – Delta"), or enter each show\'s cost below, so I can score ROI.';
	}

	return [
		'today' => date('Y-m-d'),
		'company' => $data['qb_company'] ?? '',
		'qb_connected' => (bool)($data['qb_connected'] ?? false),
		'cash_in_bank' => round((float)$data['eff_cash']),
		'cards' => $cards, 'locs' => $locs,
		'card_debt' => round($cardDebt), 'loc_debt' => round($locDebt),
		'card_available' => round($cardAvail), 'loc_available' => round($locAvail),
		'loc_limit' => round($locLimitV), 'loc_monthly_payment' => round($locPayment, 2),
		'loc_note' => 'The line of credit is ONE facility (limit = loc_limit) with these loan draws (locs[]). loc_available = loc_limit − total loan balances. The monthly loan payments are ACTUAL CASH OUT of the bank; each loan pays off after its remaining payments and then that outflow stops and the LOC frees up.',
		'ar_total' => round((float)($data['ar_total'] ?? 0)),
		'ap_total' => round((float)($data['ap_total'] ?? 0)),
		'ap_total_note' => 'ap_total = supplier bills + open MRP purchase orders, modeled as CASH owed (open POs draw down bank cash; the MRP does NOT tag a PO as paid-by-card). It excludes upcoming_card_charges below.',
		'open_po_total' => round((float)($data['pos']['total'] ?? 0)),
		'upcoming_card_charges' => round($creditOutUpcoming),
		'upcoming_card_charge_items' => $creditOutItems,
		'total_committed_all' => round((float)($data['ap_total'] ?? 0) + $creditOutUpcoming),
		'obligations_note' => 'What is owed sits in three buckets, do not conflate them: (1) ap_total = bills + open MRP POs (cash owed). (2) upcoming_card_charges = planned credit-out events that hit a CARD, not bank cash. (3) card_debt + loc_debt = balances already on the cards/LOC. If the owner says raw-material POs go on a credit card, then those PO balances should be treated as card charges (they grow a card balance, not drain cash) — flag this and advise accordingly.',
		'bills' => $data['bills']['items'] ?? [],
		'pos' => $data['pos']['items'] ?? [],
		'ar' => $data['ar']['items'] ?? [],
		'settings' => ['loan_pct' => shopify_loan_pct($db), 'cash_buffer' => $buffer, 'tax_monthly' => tax_monthly($db), 'card_min_pct' => card_min_pct($db), 'card_min_floor' => card_min_floor($db)],
		'card_min_note' => 'Credit-card minimum payments are auto-calculated as card_min_pct% of each card\'s CURRENT balance (floor card_min_floor), recomputed as balances change — the owner does not enter them. The per-month card_payments amounts already reflect this. LOC loans instead use their fixed scheduled payment.',
		'valuations' => ['inventory_on_hand' => round($ohVal), 'on_order' => round($ooVal), 'unpaid_po' => round($notPaid), 'billed_not_received' => round($bnr), 'book_value' => round($bookVal)],
		'build_plan' => ['meta' => $plan['meta'] ?? [], 'rows' => $plan['rows'] ?? []],
		'reorder' => cashflow_reorder_suggestions($db, 8),
		'po_card_plan' => $monthData['po_card_plan'] ?? [],
		'months' => $months,
		'forecast' => $fc,
		'recurring' => $recur,
		'runway_months' => $runwayMonths,
		'low_point' => $lowPoint,
		'synced_at' => cf_synced_at($db),
		'qb_deep' => $deep,
		'expense_yoy' => $yoy,
		'tradeshow_roi' => $roi,
		'data_gaps' => $gaps,
	];
}

/** Self-healing tables for Charles: saved chats, durable memory, per-show costs. */
function charles_ensure_tables($db) {
	$db->exec("CREATE TABLE IF NOT EXISTS charles_chats (
		id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL DEFAULT 'Chat',
		messages LONGTEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	$db->exec("CREATE TABLE IF NOT EXISTS charles_memory (
		id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(24) NOT NULL DEFAULT 'note',
		content TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		INDEX (created_at)) ENGINE=InnoDB");
	$db->exec("CREATE TABLE IF NOT EXISTS charles_show_costs (
		id INT AUTO_INCREMENT PRIMARY KEY, show_name VARCHAR(190) NOT NULL UNIQUE,
		cost DECIMAL(12,2) NOT NULL DEFAULT 0, note VARCHAR(255) NULL,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
}

/** Durable memory as a plain-text block (most recent last), bounded for the prompt. */
function charles_memory_load($db, $limit = 120) {
	try {
		charles_ensure_tables($db);
		$rows = $db->query("SELECT kind, content, created_at FROM charles_memory ORDER BY id DESC LIMIT " . (int)$limit)->fetchAll();
		$rows = array_reverse($rows);
		$out = [];
		foreach ($rows as $r) $out[] = '- [' . date('Y-m-d', strtotime($r['created_at'])) . '] (' . $r['kind'] . ') ' . $r['content'];
		return implode("\n", $out);
	} catch (Throwable $e) { return ''; }
}

/** Record a durable memory entry (a decision, action taken, or fact worth keeping). */
function charles_memory_append($db, $kind, $content) {
	$content = trim((string)$content);
	if ($content === '') return;
	try {
		charles_ensure_tables($db);
		$db->prepare("INSERT INTO charles_memory (kind, content) VALUES (?, ?)")->execute([substr($kind, 0, 24), $content]);
	} catch (Throwable $e) {}
}

/** The full system prompt: persona/rules + durable memory + the live snapshot JSON. */
function charles_system_prompt($db, $snapshot) {
	$persona = @file_get_contents(__DIR__ . '/charles_memory.md') ?: 'You are Charles, a CPA advising the owner in plain English.';
	$mem = charles_memory_load($db);
	return $persona
		. "\n\n## Durable memory — everything we've decided and done (oldest first)\n" . ($mem !== '' ? $mem : '(nothing recorded yet)')
		. "\n\n## Live snapshot — today's numbers (authoritative; cite these)\n" . json_encode($snapshot, JSON_UNESCAPED_SLASHES);
}

/**
 * Apply ONE book-update action to the cash-flow model. Called when George marks a
 * Charles-created task complete (i.e., he's actually done the real-world move). Never
 * called by the AI directly. Returns a short human result string.
 */
function charles_apply_action($db, $a) {
	$type = $a['type'] ?? '';
	$uid  = (int)($_SESSION['user_id'] ?? 0) ?: null;
	switch ($type) {
		case 'update_balance': {
			$label = trim((string)($a['label'] ?? ''));
			if ($label === '') return 'skip update_balance: no label';
			ensure_cash_balances_table($db);
			$sets = ['balance = ?', 'as_of = CURDATE()', 'updated_at = NOW()'];
			$vals = [round((float)($a['balance'] ?? 0), 2)];
			if (isset($a['apr']) && $a['apr'] !== null && $a['apr'] !== '') { $sets[] = 'apr = ?'; $vals[] = (float)$a['apr']; }
			if (isset($a['min']) && $a['min'] !== null && $a['min'] !== '') { $sets[] = 'monthly_payment = ?'; $vals[] = (float)$a['min']; }
			$vals[] = $label;
			$st = $db->prepare("UPDATE cash_balances SET " . implode(', ', $sets) . " WHERE label = ?");
			$st->execute($vals);
			return $st->rowCount() ? "Updated balance for '$label'" : "No account named '$label'";
		}
		case 'add_cash_event': {
			$etype  = (($a['etype'] ?? 'out') === 'in') ? 'in' : 'out';
			$label  = trim((string)($a['label'] ?? ''));
			$amount = round((float)($a['amount'] ?? 0), 2);
			$ym     = trim((string)($a['ym'] ?? date('Y-m')));
			if ($label === '' || $amount <= 0 || !preg_match('/^\d{4}-\d{2}$/', $ym)) return 'skip add_cash_event: bad fields';
			$paidby = ($etype === 'out' && ($a['paidby'] ?? 'cash') === 'card') ? 'card' : 'cash';
			ensure_cash_events_table($db);
			$db->prepare("INSERT INTO cash_events (etype,label,amount,ym,week,paidby,user_id) VALUES (?,?,?,?,?,?,?)")
			   ->execute([$etype, $label, $amount, $ym, 1, $paidby, $uid]);
			return "Added $etype '$label' \$$amount to $ym" . ($paidby === 'card' ? ' (on card)' : '');
		}
		case 'set_setting': {
			$key = $a['key'] ?? '';
			if (!in_array($key, ['cash_buffer', 'tax_monthly', 'shopify_loan_pct'], true)) return 'skip set_setting: bad key';
			setting_set($db, $key, (string)($a['value'] ?? 0));
			return "Set $key = " . $a['value'];
		}
		case 'add_recurring_expense': {
			$label  = trim((string)($a['label'] ?? ''));
			$amount = round((float)($a['amount'] ?? 0), 2);
			if ($label === '' || $amount <= 0) return 'skip add_recurring_expense: bad fields';
			ensure_cash_expenses_table($db);
			$db->prepare("INSERT INTO cash_expenses (label, amount, active) VALUES (?,?,1)")->execute([$label, $amount]);
			return "Added recurring expense '$label' \$$amount/mo";
		}
		case 'note':
			return 'Noted (no book change)';
		default:
			return "skip: unknown action '$type'";
	}
}

// ── Deep QuickBooks (weekly): multi-year P&L, expenses by category, Balance Sheet,
//    Aged Payables/Receivables. Cached 7 days; the briefing refreshes it if stale. ──

function charles_qb_num($v) {
	if (is_numeric($v)) return (float)$v;
	$s = preg_replace('/[^0-9.\-]/', '', (string)$v);
	return $s === '' || $s === '-' ? 0.0 : (float)$s;
}
function charles_qb_last_amt($colData) {
	if (!is_array($colData) || !count($colData)) return 0.0;
	$c = $colData[count($colData) - 1];
	return charles_qb_num($c['value'] ?? 0);
}
/** Collect leaf Data rows (account => amount) recursively. */
function charles_qb_leaves($rows, &$flat) {
	foreach ((array)$rows as $r) {
		if (($r['type'] ?? '') === 'Data' && isset($r['ColData'])) {
			$name = $r['ColData'][0]['value'] ?? '';
			if ($name !== '') $flat[$name] = ($flat[$name] ?? 0) + charles_qb_last_amt($r['ColData']);
		}
		if (isset($r['Rows']['Row'])) charles_qb_leaves($r['Rows']['Row'], $flat);
	}
}
/** Collect every section Summary total (name => amount) recursively. */
function charles_qb_summaries($rows, &$out) {
	foreach ((array)$rows as $r) {
		if (isset($r['Summary']['ColData'][0]['value'])) $out[$r['Summary']['ColData'][0]['value']] = charles_qb_last_amt($r['Summary']['ColData']);
		if (isset($r['Rows']['Row'])) charles_qb_summaries($r['Rows']['Row'], $out);
	}
}
/** Parse a single-period ProfitAndLoss into income / expenses-by-category / totals. */
function charles_pl_parse($report) {
	$out = ['income' => 0.0, 'expenses' => [], 'expense_total' => 0.0, 'net' => 0.0];
	if (!is_array($report) || empty($report['Rows']['Row'])) return $out;
	foreach ($report['Rows']['Row'] as $sec) {
		$group = $sec['group'] ?? '';
		$sum = isset($sec['Summary']['ColData']) ? charles_qb_last_amt($sec['Summary']['ColData']) : 0.0;
		if ($group === 'Income') $out['income'] = $sum;
		elseif ($group === 'Expenses') { $out['expense_total'] = $sum; $flat = []; if (!empty($sec['Rows']['Row'])) charles_qb_leaves($sec['Rows']['Row'], $flat); arsort($flat); $out['expenses'] = $flat; }
		elseif ($group === 'NetIncome') $out['net'] = $sum;
	}
	return $out;
}

/**
 * Deep QB pull, cached 7 days. $refreshIfStale=true (used by the briefing) pulls live
 * when the cache is missing or older than a week; otherwise returns whatever is cached.
 */
function charles_qb_deep($db, $refreshIfStale = false) {
	$key = 'charles_qb_deep';
	$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	$cached = null; $ageDays = null;
	try {
		$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$key]);
		if ($row = $s->fetch()) { $cached = json_decode($row['cval'], true); $ageDays = (time() - strtotime($row['updated_at'])) / 86400; }
	} catch (Throwable $e) {}

	if ($cached !== null && $ageDays !== null && $ageDays < 7) return $cached;              // fresh
	if (!$refreshIfStale) return $cached ?? ['connected' => null, 'loaded' => false];        // don't block a page render

	if (!qb_is_connected()) { $res = ['connected' => false, 'as_of' => date('Y-m-d H:i:s')]; }
	else {
		$thisYear = (int)date('Y');
		$byYear = [];
		foreach ([$thisYear - 2, $thisYear - 1, $thisYear] as $y) {
			$end = ($y === $thisYear) ? date('Y-m-d') : "$y-12-31";
			$rep = qb_report('ProfitAndLoss', ['start_date' => "$y-01-01", 'end_date' => $end, 'accounting_method' => 'Accrual']);
			$byYear[$y] = !empty($rep['error']) ? ['error' => $rep['error']] : charles_pl_parse($rep);
		}
		$bs = qb_report('BalanceSheet', ['end_date' => date('Y-m-d'), 'accounting_method' => 'Accrual']);
		$bsSum = []; if (empty($bs['error']) && !empty($bs['Rows']['Row'])) charles_qb_summaries($bs['Rows']['Row'], $bsSum);
		$ap = qb_report('AgedPayables', ['report_date' => date('Y-m-d')]);
		$ar = qb_report('AgedReceivables', ['report_date' => date('Y-m-d')]);
		$apSum = []; if (empty($ap['error']) && !empty($ap['Rows']['Row'])) charles_qb_summaries($ap['Rows']['Row'], $apSum);
		$arSum = []; if (empty($ar['error']) && !empty($ar['Rows']['Row'])) charles_qb_summaries($ar['Rows']['Row'], $arSum);

		// New-activity signal: did this year's expense total move since last pull?
		$prevCur = $cached['years'][$thisYear]['expense_total'] ?? null;
		$curNow  = $byYear[$thisYear]['expense_total'] ?? null;
		$newActivity = ($prevCur !== null && $curNow !== null && abs((float)$curNow - (float)$prevCur) > 1.0);

		$res = [
			'connected' => true, 'as_of' => date('Y-m-d H:i:s'),
			'years' => $byYear,
			'balance_sheet' => $bsSum,
			'aged_payables' => $apSum, 'aged_receivables' => $arSum,
			'new_activity_since_last' => $newActivity,
			'prev_current_year_expense' => $prevCur,
		];
	}
	try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$key, json_encode($res)]); } catch (Throwable $e) {}
	return $res;
}

/**
 * Detect tradeshow-related expense categories in the deep QB P&L and total them
 * (per year + most-recent-year), matching a category to a show when its name
 * contains the show name. Lets Charles use real QuickBooks show costs, not guesses.
 */
function charles_qb_tradeshow_costs($deep, $showNames) {
	$out = ['categories' => [], 'per_show' => [], 'total' => 0.0, 'year_used' => null, 'total_by_year' => []];
	if (empty($deep['years'])) return $out;
	$years = array_keys($deep['years']); sort($years);
	$rx = '/trade\s*show|tradeshow|booth|expo\b|convention|festival|show\s*fee/i';
	$catByYear = []; $totByYear = [];
	foreach ($years as $y) {
		foreach (($deep['years'][$y]['expenses'] ?? []) as $cat => $amt) {
			if (preg_match($rx, (string)$cat)) { $catByYear[$cat][$y] = round($amt); $totByYear[$y] = ($totByYear[$y] ?? 0) + round($amt); }
		}
	}
	if (empty($catByYear)) return $out;
	$yearUsed = null;
	for ($k = count($years) - 1; $k >= 0; $k--) { if (!empty($totByYear[$years[$k]])) { $yearUsed = $years[$k]; break; } }
	foreach ($catByYear as $cat => $byY) $out['categories'][] = ['name' => $cat, 'by_year' => $byY];
	$out['total_by_year'] = $totByYear;
	$out['year_used'] = $yearUsed;
	$out['total'] = $yearUsed !== null ? (float)($totByYear[$yearUsed] ?? 0) : 0.0;
	foreach ($catByYear as $cat => $byY) {
		$amt = $yearUsed !== null ? (float)($byY[$yearUsed] ?? 0) : 0;
		if ($amt <= 0) foreach ($byY as $a) $amt = max($amt, (float)$a);
		foreach ($showNames as $sn) { if ($sn !== '' && stripos($cat, $sn) !== false) $out['per_show'][strtolower($sn)] = ($out['per_show'][strtolower($sn)] ?? 0) + $amt; }
	}
	return $out;
}

/**
 * Tradeshow ROI: last season's revenue at each show (Shopify POS location) vs the
 * show's cost — taken from QuickBooks tradeshow expense categories, with an optional
 * manual per-show override (charles_show_costs). Cached ~24h; the briefing refreshes
 * it. Flags any show with ROI under 1.0, and gives an overall ROI from the QB total.
 */
function charles_tradeshow_roi($db, $refreshIfStale = false) {
	$key = 'charles_tradeshow_roi';
	$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	$cached = null; $ageH = null;
	try {
		$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$key]);
		if ($row = $s->fetch()) { $cached = json_decode($row['cval'], true); $ageH = (time() - strtotime($row['updated_at'])) / 3600; }
	} catch (Throwable $e) {}
	if ($cached !== null && $ageH !== null && $ageH < 24) return $cached;
	if (!$refreshIfStale) return $cached ?? ['rows' => [], 'loaded' => false];

	if (!shopify_is_configured()) { $res = ['rows' => [], 'loaded' => true, 'error' => 'Shopify not connected']; }
	else {
		charles_ensure_tables($db);
		$manual = [];
		try { foreach ($db->query("SELECT show_name, cost FROM charles_show_costs") as $r) $manual[strtolower(trim($r['show_name']))] = (float)$r['cost']; } catch (Throwable $e) {}
		$since = date('Y-m-d', strtotime('-14 months'));
		$until = date('Y-m-d');
		$ttl = inventory_cache_ttl($db);
		$rows = [];
		foreach (tradeshow_locations() as $loc) {
			$name = trim((string)($loc['name'] ?? '')); if ($name === '') continue;
			$ids = isset($loc['ids']) ? $loc['ids'] : (isset($loc['id']) ? [$loc['id']] : []);
			$rev = 0.0; $units = 0;
			foreach ($ids as $lid) {
				$r = shopify_cache_remember($db, "charlesroi_{$lid}_{$since}_{$until}", $ttl, fn() => shopify_show_sales($lid, $since, $until))['data'];
				$rev += (float)($r['revenue'] ?? 0); $units += (int)($r['total_units'] ?? 0);
			}
			if ($rev <= 0 && $units <= 0) continue;
			$rows[] = ['show' => $name, 'revenue' => round($rev), 'units' => $units, 'cost' => null, 'roi' => null, 'cost_source' => null];
		}

		// Costs: prefer a manual override, else the matching QuickBooks tradeshow expense.
		$qb = charles_qb_tradeshow_costs(charles_qb_deep($db, false), array_map(fn($x) => $x['show'], $rows));
		$missing = 0;
		foreach ($rows as &$row) {
			$sl = strtolower($row['show']);
			if (isset($manual[$sl])) { $row['cost'] = round($manual[$sl]); $row['cost_source'] = 'manual'; }
			elseif (isset($qb['per_show'][$sl])) { $row['cost'] = round($qb['per_show'][$sl]); $row['cost_source'] = 'quickbooks'; }
			if ($row['cost'] !== null && $row['cost'] > 0) $row['roi'] = round($row['revenue'] / $row['cost'], 2);
			else $missing++;
		}
		unset($row);
		usort($rows, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

		$totalRev   = array_sum(array_map(fn($x) => $x['revenue'], $rows));
		$overallRoi = ($qb['total'] > 0) ? round($totalRev / $qb['total'], 2) : null;

		$res = [
			'rows' => $rows, 'loaded' => true, 'window' => "$since to $until", 'missing_costs' => $missing,
			'qb_expense_total' => round($qb['total']), 'qb_expense_year' => $qb['year_used'],
			'qb_expense_categories' => $qb['categories'],
			'overall_revenue' => round($totalRev), 'overall_roi' => $overallRoi,
		];
	}
	try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$key, json_encode($res)]); } catch (Throwable $e) {}
	return $res;
}

/** Compact expense year-over-year table for the chart + AI: top categories across years. */
function charles_expense_yoy($deep) {
	if (empty($deep['years'])) return ['years' => [], 'categories' => []];
	$years = array_keys($deep['years']); sort($years);
	$totByCat = [];
	foreach ($years as $y) foreach (($deep['years'][$y]['expenses'] ?? []) as $cat => $amt) $totByCat[$cat] = ($totByCat[$cat] ?? 0) + $amt;
	arsort($totByCat);
	$top = array_slice(array_keys($totByCat), 0, 10);
	$cats = [];
	foreach ($top as $cat) {
		$vals = [];
		foreach ($years as $y) $vals[$y] = round($deep['years'][$y]['expenses'][$cat] ?? 0);
		$cats[] = ['category' => $cat, 'by_year' => $vals];
	}
	return ['years' => $years, 'categories' => $cats,
	        'income_by_year' => array_map(fn($y) => round($deep['years'][$y]['income'] ?? 0), array_combine($years, $years)),
	        'expense_by_year' => array_map(fn($y) => round($deep['years'][$y]['expense_total'] ?? 0), array_combine($years, $years)),
	        'net_by_year' => array_map(fn($y) => round($deep['years'][$y]['net'] ?? 0), array_combine($years, $years))];
}
