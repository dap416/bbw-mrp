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

// The two loan draws against the one LOC.
$loans = [
	['label' => 'LOC Loan — $65,000 draw', 'balance' => 39314.00, 'payment' => 5959.09, 'note' => '7 payments left · due the 13th monthly · paid from bank (cash out)'],
	['label' => 'LOC Loan — $25,000 draw', 'balance' => 6679.00,  'payment' => 2293.61, 'note' => '3 payments left · due the 26th monthly · paid from bank (cash out)'],
];
$LOC_LIMIT = 85000.0;

$out = [];
try {
	$db->beginTransaction();
	// Remove any prior LOC rows so we don't double-count draws.
	$removed = $db->exec("DELETE FROM cash_balances WHERE acct_type = 'loc'");
	$ins = $db->prepare("INSERT INTO cash_balances (label, acct_type, balance, credit_limit, monthly_payment, apr, as_of, note, user_id)
	                     VALUES (?, 'loc', ?, NULL, ?, NULL, CURDATE(), ?, ?)");
	foreach ($loans as $l) {
		$ins->execute([$l['label'], $l['balance'], $l['payment'], $l['note'], $_SESSION['user_id'] ?? null]);
		$out[] = $l;
	}
	setting_set($db, 'loc_limit', (string)$LOC_LIMIT);
	$db->commit();
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500); echo 'Setup failed: ' . htmlspecialchars($e->getMessage()); exit;
}

$drawn = array_sum(array_map(fn($l) => $l['balance'], $loans));
$avail = $LOC_LIMIT - $drawn;
$payMo = array_sum(array_map(fn($l) => $l['payment'], $loans));

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-3">Line of Credit — cleaned up ✓</h2>
<div class="card" style="max-width:640px;"><div class="card-body">
	<p>Removed <?php echo (int)$removed; ?> old LOC row(s) and set up <strong>one line of credit</strong> with a <strong>$<?php echo number_format($LOC_LIMIT); ?></strong> limit and two loans:</p>
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
	<p class="text-muted small mb-0">The monthly payments are treated as real cash out of the bank and each loan pays off after its remaining payments. This now flows through the Cash Flow page and Charles. You can adjust balances anytime on the Cash Flow page, and the LOC limit under Planning settings.</p>
	<a href="/charles.php" class="btn btn-sm btn-primary mt-2">Open Charles</a>
	<a href="/cashflow.php" class="btn btn-sm btn-light mt-2">Open Cash Flow</a>
</div></div>
<?php require_once(__DIR__."/includes/footer.php"); ?>
