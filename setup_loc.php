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

// One line of credit — the QuickBooks LOC, $85,000 ceiling.
$CEILINGS = [
	['name' => 'QuickBooks', 'ceiling' => 85000.0],
];
// The two loan draws on the QuickBooks LOC.
$loans = [
	['label' => 'LOC Loan — due the 8th',  'loc' => 'QuickBooks', 'balance' => 30000.00, 'payment' => 2752.00, 'due' => 8,  'note' => 'due the 8th monthly · paid from bank (cash out)'],
	['label' => 'LOC Loan — due the 13th', 'loc' => 'QuickBooks', 'balance' => 33935.00, 'payment' => 5959.00, 'due' => 13, 'note' => 'due the 13th monthly · paid from bank (cash out)'],
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

$ceilTotal = array_sum(array_map(fn($c) => $c['ceiling'], $CEILINGS));
$drawn = array_sum(array_map(fn($l) => $l['balance'], $loans));
$avail = $ceilTotal - $drawn;
$payMo = array_sum(array_map(fn($l) => $l['payment'], $loans));

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-3">Lines of Credit — set up ✓</h2>
<div class="card" style="max-width:640px;"><div class="card-body">
	<p>Removed <?php echo (int)$removed; ?> old LOC row(s) and set up <strong><?php echo count($CEILINGS) === 1 ? 'one line of credit' : count($CEILINGS) . ' separate lines of credit'; ?></strong>:
		<?php echo implode(', ', array_map(fn($c) => htmlspecialchars($c['name']) . ' $' . number_format($c['ceiling']), $CEILINGS)); ?>.
		The two loans below draw on the QuickBooks LOC.</p>
	<table class="table table-sm align-middle">
		<thead><tr><th>Loan</th><th class="text-end">Balance</th><th class="text-end">Monthly payment</th></tr></thead>
		<tbody>
		<?php foreach ($out as $l): ?>
			<tr><td><?php echo htmlspecialchars($l['label']); ?><br><span class="text-muted small"><?php echo htmlspecialchars($l['note']); ?></span></td>
			<td class="text-end">$<?php echo number_format($l['balance'], 2); ?></td>
			<td class="text-end">$<?php echo number_format($l['payment'], 2); ?></td></tr>
		<?php endforeach; ?>
		<tr class="fw-bold" style="border-top:2px solid #dee2e6;"><td>Total drawn</td><td class="text-end">$<?php echo number_format($drawn, 2); ?></td><td class="text-end">$<?php echo number_format($payMo, 2); ?>/mo</td></tr>
		<tr><td>Available to draw</td><td class="text-end fw-bold" style="color:#2ca01c;">$<?php echo number_format($avail, 2); ?></td><td></td></tr>
	</tbody></table>
	<p class="text-muted small mb-0">Each LOC tracks its own available-to-draw (ceiling − its loan balances). The monthly payments are real cash out of the bank and each loan pays off after its remaining payments. You can edit balances (and which LOC a loan is on) on the Cash Flow page, and each LOC's ceiling under Planning settings.</p>
	<a href="/charles.php" class="btn btn-sm btn-primary mt-2">Open Charles</a>
	<a href="/cashflow.php" class="btn btn-sm btn-light mt-2">Open Cash Flow</a>
</div></div>
<?php require_once(__DIR__."/includes/footer.php"); ?>
