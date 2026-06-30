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
foreach ($data['manual']['bank'] as $a)   $balances[] = ['label' => $a['label'], 'type' => 'bank',   'balance' => $a['balance'], 'as_of' => $a['as_of'], 'days_old' => $a['days_old']];
foreach ($data['manual']['credit'] as $a) $balances[] = ['label' => $a['label'], 'type' => $a['type'], 'balance' => $a['balance'], 'apr' => $a['apr'], 'min' => $a['payment'], 'limit' => $a['limit'], 'as_of' => $a['as_of'], 'days_old' => $a['days_old']];

$months = [];
foreach ($md['blocks'] as $b) {
	$cards = [];
	foreach ($b['card_payments'] as $cp) $cards[] = $cp['label'] . '=' . round($cp['amount']) . ($cp['is_target'] ? '(focus)' : '');
	$months[] = [
		'ym' => $b['ym'], 'label' => $b['label'], 'is_past' => $b['is_past'],
		'suggested' => round((float)$b['suggested']), 'actual_proj' => $b['actual_proj'], 'actual_income' => $b['actual_income'],
		'income_using' => $b['income_source'], 'in' => round($b['in_total']), 'out' => round($b['out_total']),
		'net' => round($b['net']), 'end_cash' => $b['end_cash'] === null ? null : round($b['end_cash']),
		'card_payments' => $cards, 'tax_reserve' => round($b['tax_reserve']),
	];
}
$evList = [];
foreach ($events['all'] as $e) $evList[] = ['id' => $e['id'], 'etype' => $e['etype'], 'label' => $e['label'], 'amount' => $e['amount'], 'ym' => $e['ym'], 'week' => $e['week']];
$arList = [];
foreach (($data['ar']['items'] ?? []) as $a) $arList[] = ['order' => $a['name'], 'customer' => $a['customer'], 'amount' => $a['amount'], 'expected' => $a['expected'] ?? null];

$ctx = [
	'today' => date('Y-m-d'), 'current_month' => date('Y-m'),
	'cash_on_hand' => round($data['eff_cash']),
	'settings' => ['shopify_loan_pct' => shopify_loan_pct($db), 'cash_buffer' => cash_buffer($db), 'tax_monthly' => tax_monthly($db)],
	'cards_paid_months' => cardpay_done_months($db),
	'balances' => $balances,
	'receivables' => $arList,
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
- POs are credit-card charges with a pay-by date; recurring expenses, taxes (a monthly reserve paid each quarter), and the Shopify loan (% of sales) are cash out.

When the user asks you to change something, do BOTH:
1. Briefly explain what you'll change and why.
2. Output a fenced json block (```json ... ```) with an \"actions\" array. Do NOT say the change is done — it is only a proposal until the user approves it.

Allowed actions (use exact field names; amounts are plain numbers):
- {\"type\":\"mark_cards_paid\",\"ym\":\"YYYY-MM\",\"why\":\"...\"}  // card payments already made that month
- {\"type\":\"unmark_cards_paid\",\"ym\":\"YYYY-MM\",\"why\":\"...\"}
- {\"type\":\"set_month_actual\",\"ym\":\"YYYY-MM\",\"field\":\"income\"|\"projection\",\"value\":12345|null,\"why\":\"...\"}
- {\"type\":\"update_balance\",\"label\":\"exact account name from balances\",\"balance\":1234,\"as_of\":\"YYYY-MM-DD\",\"apr\":24.99,\"min\":150,\"why\":\"...\"}  // as_of/apr/min optional
- {\"type\":\"add_event\",\"etype\":\"in\"|\"out\",\"label\":\"...\",\"amount\":1234,\"ym\":\"YYYY-MM\",\"week\":1,\"why\":\"...\"}
- {\"type\":\"delete_event\",\"id\":12,\"why\":\"...\"}
- {\"type\":\"set_setting\",\"key\":\"shopify_loan_pct\"|\"cash_buffer\"|\"tax_monthly\",\"value\":30000,\"why\":\"...\"}
- {\"type\":\"set_receivable_date\",\"order\":\"#13989\",\"date\":\"YYYY-MM-DD\"|null,\"why\":\"...\"}
- {\"type\":\"add_recurring_expense\",\"label\":\"...\",\"amount\":1234,\"why\":\"...\"}

Rules: only propose actions that match the user's intent; never invent account names — use the exact labels in the snapshot. If you don't need to change anything, just answer (no json block). Be concise and concrete with numbers. Today is in the snapshot.";

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

echo json_encode(['reply' => $text, 'actions' => $actions]);
