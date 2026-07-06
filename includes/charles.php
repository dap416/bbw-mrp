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
			'min' => ($c['payment'] !== null && $c['payment'] !== '') ? (float)$c['payment'] : null,
			'available' => $lim !== null ? max(0.0, $lim - (float)$c['balance']) : null,
		];
		if (($c['type'] ?? '') === 'loc') $locs[] = $row; else $cards[] = $row;
	}
	$cardDebt  = array_sum(array_map(fn($x) => $x['balance'], $cards));
	$locDebt   = array_sum(array_map(fn($x) => $x['balance'], $locs));
	$cardAvail = array_sum(array_map(fn($x) => (float)($x['available'] ?? 0), $cards));
	$locAvail  = array_sum(array_map(fn($x) => (float)($x['available'] ?? 0), $locs));

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
			'card_payments' => array_map(fn($c) => ['label' => $c['label'], 'amount' => round($c['amount']), 'apr' => $c['apr'], 'balance' => round($c['balance'])], $b['card_payments'] ?? []),
		];
	}

	$fc = [];
	foreach (($forecast['rows'] ?? []) as $r) {
		$fc[] = ['ym' => $r['ym'], 'label' => $r['label'], 'income' => round($r['income'] ?? 0), 'cash_out' => round($r['cash_out'] ?? 0), 'is_past' => !empty($r['is_past'])];
	}

	// Data gaps Charles should flag / ask for (expanded in later phases).
	$gaps = [];
	if (empty($data['qb_connected'])) $gaps[] = 'QuickBooks is not connected — connect it on Integrations so I can see your real P&L, Balance Sheet, bills and full expense history.';
	if (empty($cards) && empty($locs)) $gaps[] = 'No credit cards or line of credit are on file — add them (with APR + limit) on the Cash Flow page so I can plan financing and payoff.';

	return [
		'today' => date('Y-m-d'),
		'company' => $data['qb_company'] ?? '',
		'qb_connected' => (bool)($data['qb_connected'] ?? false),
		'cash_in_bank' => round((float)$data['eff_cash']),
		'cards' => $cards, 'locs' => $locs,
		'card_debt' => round($cardDebt), 'loc_debt' => round($locDebt),
		'card_available' => round($cardAvail), 'loc_available' => round($locAvail),
		'ar_total' => round((float)($data['ar_total'] ?? 0)),
		'ap_total' => round((float)($data['ap_total'] ?? 0)),
		'net_position' => round((float)($data['net_quick'] ?? 0)),
		'bills' => $data['bills']['items'] ?? [],
		'pos' => $data['pos']['items'] ?? [],
		'ar' => $data['ar']['items'] ?? [],
		'settings' => ['loan_pct' => shopify_loan_pct($db), 'cash_buffer' => $buffer, 'tax_monthly' => tax_monthly($db)],
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
