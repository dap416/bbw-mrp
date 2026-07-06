<?php
/**
 * Cash-flow AI assistant. Sends a compact snapshot of the whole cash-flow page
 * to Claude, which answers and — when the user wants a change — proposes it as a
 * fenced ```json {"actions":[...]} block. Nothing is applied here; the browser
 * shows the proposed actions for approval, then calls apply.php.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_once(__DIR__."/../../includes/anthropic.php");
require_login();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }
if (!anthropic_is_configured()) { echo json_encode(['error' => 'No Anthropic API key configured (Integrations page).']); exit; }

$db = db_connect();

// ── Conversation so far (client-held) ──
$messages = json_decode($_POST['messages'] ?? '[]', true) ?: [];
$clean = [];
foreach ($messages as $m) {
	$r = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
	$c = is_string($m['content'] ?? '') ? trim($m['content']) : '';
	if ($c !== '') $clean[] = ['role' => $r, 'content' => $c];
}
if (empty($clean)) { echo json_encode(['error' => 'Empty message.']); exit; }

// ── Snapshot of the current cash-flow state ──
$data     = build_cashflow_data($db);
$forecast = build_cashflow_forecast($db, $data, 12, 0);
$events   = load_cash_events($db);
$md       = build_month_blocks($db, $data, $forecast, $events);

$balances = [];
foreach ($data['manual']['bank'] as $a)   $balances[] = ['label' => $a['label'], 'type' => 'bank', 'balance' => $a['balance'], 'as_of' => $a['as_of'], 'days_old' => $a['days_old']];
foreach ($data['manual']['credit'] as $a) {
	$avail = $a['limit'] !== null ? round((float)$a['limit'] - (float)$a['balance'], 2) : null;
	$balances[] = ['label' => $a['label'], 'type' => $a['type'] === 'loc' ? 'loc' : 'credit_card', 'balance' => $a['balance'],
	               'apr' => $a['apr'], 'min_payment' => $a['payment'], 'limit' => $a['limit'], 'available' => $avail, 'as_of' => $a['as_of']];
}

$months = [];
foreach ($md['blocks'] as $b) {
	$cards = []; foreach ($b['card_payments'] as $cp) $cards[] = $cp['label'] . '=' . round($cp['amount']) . ($cp['is_target'] ? '(focus)' : '');
	$outItems = []; foreach ($b['cash_out'] as $o) $outItems[] = $o['label'] . '=' . round($o['amount']);
	$inItems  = []; foreach ($b['cash_in'] as $o)  $inItems[]  = $o['label'] . '=' . round($o['amount']);
	$months[] = [
		'ym' => $b['ym'], 'label' => $b['label'], 'is_past' => $b['is_past'],
		'suggested' => round((float)$b['suggested']), 'actual_proj' => $b['actual_proj'], 'actual_income' => $b['actual_income'],
		'income_using' => $b['income_source'], 'in_total' => round($b['in_total']), 'out_total' => round($b['out_total']),
		'net' => round($b['net']), 'end_cash' => $b['end_cash'] === null ? null : round($b['end_cash']),
		'cash_in_items' => $inItems, 'cash_out_items' => $outItems, 'card_payments' => $cards, 'tax_reserve' => round($b['tax_reserve']),
	];
}
$evList = [];   foreach ($events['all'] as $e) $evList[] = ['id' => $e['id'], 'etype' => $e['etype'], 'label' => $e['label'], 'amount' => $e['amount'], 'ym' => $e['ym'], 'week' => $e['week'], 'paidby' => $e['paidby'] ?? 'cash', 'paid' => (int)($e['paid'] ?? 0)];
$arList = [];   foreach (($data['ar']['items'] ?? []) as $a) $arList[] = ['order' => $a['name'], 'customer' => $a['customer'], 'amount' => $a['amount'], 'expected' => $a['expected'] ?? null];
$poList = [];   foreach (($data['pos']['items'] ?? []) as $p) $poList[] = ['ref' => $p['ref'], 'supplier' => $p['supplier'], 'part' => $p['part'], 'balance' => $p['balance'], 'pay_by' => $p['pay_by'] ?? null];
$billList = []; foreach (($data['bills']['items'] ?? []) as $bb) $billList[] = ['vendor' => $bb['vendor'], 'balance' => $bb['balance'], 'due' => $bb['due']];
$recur = load_recurring_expenses($db);

$ctx = [
	'today' => date('Y-m-d'), 'current_month' => date('Y-m'),
	'cash_on_hand' => round($data['eff_cash']),
	'settings' => ['shopify_loan_pct' => shopify_loan_pct($db), 'cash_buffer' => cash_buffer($db), 'tax_monthly' => tax_monthly($db)],
	'cards_paid_months' => cardpay_done_months($db),
	'expenses_paid_months' => expenses_done_months($db),
	'balances' => $balances,
	'recurring_expenses' => $recur['items'],
	'unpaid_pos' => $poList,
	'quickbooks_bills' => $billList,
	'receivables' => $arList,
	'po_to_card_plan' => $md['po_card_plan'],
	'manual_events' => $evList,
	'months' => $months,
];
$context = json_encode($ctx, JSON_UNESCAPED_SLASHES);

$SYSTEM =
"You are the cash-flow assistant for Blue Bird Waterfowl, embedded in the Cash Flow page. You help the owner reason about and adjust a rolling monthly cash-flow plan, and you can PROPOSE changes to the page's data (the user approves them before they apply).

How the model works:
- Each month has three income tiers; the one actually used is income_using: 'income' (actual income, highest) > 'projection' (actual projection) > 'suggested' (auto baseline from last year).
- cash_on_hand is the manually-entered bank balance (the owner updates it on the 1st). The forecast's running cash starts from it at the current month.
- Card debt is paid by avalanche: minimums on all cards, all spare cash above cash_buffer to the highest-APR card. If a month is in cards_paid_months, its card payments are skipped (already made & reflected in balances).
- Each month's cash out is itemized in cash_out_items (recurring expenses, taxes — a monthly reserve paid each quarter, the Shopify loan = % of sales, and bills/POs due). If a month is in expenses_paid_months, those operating expenses (recurring, loan, tax) are excluded so they aren't double-counted (the owner already paid them).
- ACCOUNTS: balances has type 'bank', 'credit_card', or 'loc'. A 'credit_card' is a real credit card you can charge purchases to. A 'loc' is a line of credit / loan (e.g. Intuit Loan, Shopify Line Of Credit) — you CANNOT charge purchases to it. To use an LOC you DRAW it as cash: that increases the bank balance and creates/increases a loan; it is never a place to put a PO.
- PURCHASE ORDERS: every PO is paid with a REAL CREDIT CARD (type 'credit_card'), never cash, never an LOC. po_to_card_plan shows which card each unpaid PO should go on (highest available headroom / lowest APR). When suggesting how to pay a PO, ALWAYS pick a credit_card from balances — use 'available' (limit minus balance) to confirm headroom. Never suggest paying a PO from cash or routing it to an LOC.

When the user asks you to change something, do BOTH:
1. Briefly explain what you'll change and why.
2. Output a fenced json block (```json ... ```) with an \"actions\" array. Do NOT say the change is done — it is only a proposal until the user approves it.

Allowed actions (use exact field names; amounts are plain numbers):
- {\"type\":\"mark_cards_paid\",\"ym\":\"YYYY-MM\",\"why\":\"...\"}  // card payments already made that month
- {\"type\":\"unmark_cards_paid\",\"ym\":\"YYYY-MM\",\"why\":\"...\"}
- {\"type\":\"mark_expenses_paid\",\"ym\":\"YYYY-MM\",\"why\":\"...\"}  // recurring expenses, tax set-aside & Shopify loan already paid that month — excludes them from the forecast
- {\"type\":\"unmark_expenses_paid\",\"ym\":\"YYYY-MM\",\"why\":\"...\"}
- {\"type\":\"set_month_actual\",\"ym\":\"YYYY-MM\",\"field\":\"income\"|\"projection\",\"value\":12345|null,\"why\":\"...\"}
- {\"type\":\"update_balance\",\"label\":\"exact account name from balances\",\"balance\":1234,\"as_of\":\"YYYY-MM-DD\",\"apr\":24.99,\"min\":150,\"why\":\"...\"}  // as_of/apr/min optional
- {\"type\":\"add_event\",\"etype\":\"in\"|\"out\",\"label\":\"...\",\"amount\":1234,\"ym\":\"YYYY-MM\",\"week\":1,\"paidby\":\"cash\"|\"card\",\"why\":\"...\"}  // use 'in' to model drawing cash from an LOC. For an 'out' event, paidby='card' means it goes on a credit card (e.g. an FP purchase order from China) — tracked but NOT counted as cash out; paidby='cash' (default) reduces the bank balance.
- {\"type\":\"delete_event\",\"id\":12,\"why\":\"...\"}
- {\"type\":\"set_setting\",\"key\":\"shopify_loan_pct\"|\"cash_buffer\"|\"tax_monthly\",\"value\":30000,\"why\":\"...\"}
- {\"type\":\"set_receivable_date\",\"order\":\"#13989\",\"date\":\"YYYY-MM-DD\"|null,\"why\":\"...\"}
- {\"type\":\"add_recurring_expense\",\"label\":\"...\",\"amount\":1234,\"why\":\"...\"}

Rules: only propose actions that match the user's intent; never invent account names — use the exact labels in the snapshot. POs go on real credit cards only. If you don't need to change anything, just answer (no json block). Be concise and concrete with numbers. Today is in the snapshot.";

$memFile = __DIR__ . '/../../includes/cashflow_ai_memory.md';
$memory = is_readable($memFile) ? trim((string)file_get_contents($memFile)) : '';
if ($memory !== '') $SYSTEM .= "\n\n=== CASH FLOW REFERENCE (maintained by the owner; authoritative) ===\n" . $memory;

$system = $SYSTEM . "\n\nCurrent cash-flow snapshot (JSON):\n" . $context;
$res = anthropic_chat($system, $clean, 2000);
if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }

$text = $res['text'];
$actions = [];
if (preg_match('/```json\s*(\{.*?\})\s*```/s', $text, $mm)) {
	$j = json_decode($mm[1], true);
	if (is_array($j) && !empty($j['actions']) && is_array($j['actions'])) $actions = $j['actions'];
	$text = trim(str_replace($mm[0], '', $text));
}

// ── Persist the conversation (saved & resumable) ──
$chatId = (int)($_POST['chat_id'] ?? 0);
$title  = 'Chat';
foreach ($clean as $m) { if ($m['role'] === 'user') { $title = mb_substr(trim($m['content']), 0, 60); break; } }
$full = $clean;
$full[] = ['role' => 'assistant', 'content' => $text];
try {
	$db->exec("CREATE TABLE IF NOT EXISTS cashflow_chats (
		id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT 'Chat',
		messages LONGTEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	if ($chatId > 0) {
		$db->prepare("UPDATE cashflow_chats SET messages=?, updated_at=NOW() WHERE id=?")->execute([json_encode($full), $chatId]);
	} else {
		$db->prepare("INSERT INTO cashflow_chats (title, messages) VALUES (?,?)")->execute([$title, json_encode($full)]);
		$chatId = (int)$db->lastInsertId();
	}
} catch (Throwable $e) { /* persistence best-effort */ }

echo json_encode(['reply' => $text, 'actions' => $actions, 'chat_id' => $chatId, 'title' => $title]);
