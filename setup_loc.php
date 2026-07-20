<?php
/**
 * One-time cleanup of the Line of Credit: one facility ($85,000 limit) with two
 * loan draws, each with its balance + fixed monthly payment. Replaces any existing
 * LOC rows in cash_balances with these two, and sets the shared facility limit.
 * Idempotent — safe to re-run. Admin/master only.
 */
require_once(__DIR__."/includes/fns.php");
require_once(__DIR__."/includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true) && !is_owner()) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
ensure_cash_balances_table($db);

// One revolving line of credit — the QuickBooks LOC, $85,000 ceiling. (The Shopify
// Capital loan below is a term loan, NOT a facility you draw on, so it has no ceiling.)
$CEILINGS = [
	['name' => 'QuickBooks', 'ceiling' => 85000.0],
];
// Loans/debts tracked here. Each row with 'loc' matching a $CEILINGS name draws on that
// facility; a row whose 'loc' has no ceiling (Shopify Capital) is a standalone term loan —
// it counts toward "Credit / LOC Owed" but never against a line's available-to-draw.
$loans = [
	['label' => 'LOC Loan — due the 8th',  'loc' => 'QuickBooks',      'balance' => 30000.00, 'payment' => 2752.00, 'due' => 8,    'note' => 'due the 8th monthly · paid from bank (cash out)'],
	['label' => 'LOC Loan — due the 13th', 'loc' => 'QuickBooks',      'balance' => 33935.00, 'payment' => 5959.00, 'due' => 13,   'note' => 'due the 13th monthly · paid from bank (cash out)'],
	['label' => 'Shopify Capital loan',    'loc' => 'Shopify Capital', 'balance' => 48498.00, 'payment' => 0.0,     'due' => null, 'note' => 'term loan · repaid automatically at 25% of Shopify sales (no fixed monthly payment) · ~$34,062 originally advanced'],
];

$out = [];
try {
	$db->beginTransaction();
	// Remove any prior LOC rows so we don't double-count draws.
	$removed = $db->exec("DELETE FROM cash_balances WHERE acct_type = 'loc'");
	$ins = $db->prepare("INSERT INTO cash_balances (label, acct_type, balance, credit_limit, monthly_payment, apr, as_of, note, loc_name, due_day, user_id)
	                     VALUES (?, 'loc', ?, NULL, ?, NULL, CURDATE(), ?, ?, ?, ?)");
	foreach ($loans as $l) {
		$ins->execute([$l['label'], $l['balance'], $l['payment'], $l['note'], $l['loc'], $l['due'] ?? null, $_SESSION['user_id'] ?? null]);
		$out[] = $l;
	}
	setting_set($db, 'loc_ceilings', json_encode($CEILINGS));
	$db->commit();
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500); echo 'Setup failed: ' . htmlspecialchars($e->getMessage()); exit;
}

$ceilNames = array_map(fn($c) => $c['name'], $CEILINGS);
$ceilTotal = array_sum(array_map(fn($c) => $c['ceiling'], $CEILINGS));
// Only loans drawn on a ceilinged facility count against available-to-draw; term loans don't.
$lineDrawn = array_sum(array_map(fn($l) => in_array($l['loc'], $ceilNames, true) ? $l['balance'] : 0, $loans));
$drawn = array_sum(array_map(fn($l) => $l['balance'], $loans));   // all loan/LOC debt
$avail = $ceilTotal - $lineDrawn;
$payMo = array_sum(array_map(fn($l) => $l['payment'], $loans));

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-3">Lines of Credit — set up ✓</h2>
<div class="card" style="max-width:640px;"><div class="card-body">
	<p>Removed <?php echo (int)$removed; ?> old LOC row(s) and set up <strong><?php echo count($CEILINGS) === 1 ? 'one line of credit' : count($CEILINGS) . ' separate lines of credit'; ?></strong>:
		<?php echo implode(', ', array_map(fn($c) => htmlspecialchars($c['name']) . ' $' . number_format($c['ceiling']), $CEILINGS)); ?>.
		The QuickBooks loans below draw on that LOC; the Shopify Capital loan is a standalone term loan (no draw ceiling).</p>
	<table class="table table-sm align-middle">
		<thead><tr><th>Loan</th><th class="text-end">Balance</th><th class="text-end">Monthly payment</th></tr></thead>
		<tbody>
		<?php foreach ($out as $l): ?>
			<tr><td><?php echo htmlspecialchars($l['label']); ?><br><span class="text-muted small"><?php echo htmlspecialchars($l['note']); ?></span></td>
			<td class="text-end">$<?php echo number_format($l['balance'], 2); ?></td>
			<td class="text-end">$<?php echo number_format($l['payment'], 2); ?></td></tr>
		<?php endforeach; ?>
		<tr class="fw-bold" style="border-top:2px solid #dee2e6;"><td>Total owed (all loans)</td><td class="text-end">$<?php echo number_format($drawn, 2); ?></td><td class="text-end">$<?php echo number_format($payMo, 2); ?>/mo</td></tr>
		<tr><td>QuickBooks LOC — available to draw</td><td class="text-end fw-bold" style="color:#2ca01c;">$<?php echo number_format($avail, 2); ?></td><td></td></tr>
	</tbody></table>
	<p class="text-muted small mb-0">Available-to-draw counts only loans on a ceilinged facility (QuickBooks LOC = ceiling − its draws); the Shopify Capital term loan adds to what you owe but never against a line's room. Monthly payments are real cash out of the bank; the Shopify loan instead repays at 25% of sales. You can edit balances on the Cash Management page, and each LOC's ceiling under Planning settings.</p>
	<a href="/charles.php" class="btn btn-sm btn-primary mt-2">Open Charles</a>
	<a href="/cashflow.php" class="btn btn-sm btn-light mt-2">Open Cash Flow</a>
</div></div>
<?php require_once(__DIR__."/includes/footer.php"); ?>
