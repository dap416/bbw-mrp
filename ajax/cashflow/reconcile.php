<?php
/**
 * Reconcile Today — the single "set my real position" action for the current month.
 * Updates actual bank (and credit/LOC) balances as of today, and records which of the
 * month's planned cash-in / cash-out lines have already happened (so they drop out of
 * the forecast and are never double-counted). Admin / master only.
 *
 * POST:
 *   ym        YYYY-MM (defaults to the current month)
 *   balances  JSON array of { id, balance } — rows in cash_balances to set (as_of = today)
 *   received  JSON array of cash-IN line keys already received this month
 *   paid      JSON array of cash-OUT line keys already paid this month
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true) && !is_owner()) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$ym = trim($_POST['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');

$balances = json_decode($_POST['balances'] ?? '[]', true);
$received = json_decode($_POST['received'] ?? '[]', true);
$paid     = json_decode($_POST['paid'] ?? '[]', true);
if (!is_array($balances)) $balances = [];
if (!is_array($received)) $received = [];
if (!is_array($paid))     $paid     = [];

$db = db_connect();
ensure_cash_balances_table($db);
ensure_cashin_received_table($db);
ensure_cashout_paid_table($db);

try {
	$db->beginTransaction();

	// 1) Set actual balances as of today.
	$upd = $db->prepare("UPDATE cash_balances SET balance = ?, as_of = CURDATE() WHERE id = ?");
	foreach ($balances as $b) {
		$id = (int)($b['id'] ?? 0);
		if ($id <= 0 || !isset($b['balance']) || $b['balance'] === '') continue;
		$upd->execute([(float)$b['balance'], $id]);
	}

	// 2) Replace this month's reconciled cash-IN set.
	$db->prepare("DELETE FROM cashin_received WHERE ym = ?")->execute([$ym]);
	if ($received) {
		$insIn = $db->prepare("INSERT INTO cashin_received (ym, line_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()");
		foreach ($received as $k) { $k = trim((string)$k); if ($k !== '') $insIn->execute([$ym, $k]); }
	}

	// 3) Replace this month's reconciled cash-OUT set.
	$db->prepare("DELETE FROM cashout_paid WHERE ym = ?")->execute([$ym]);
	if ($paid) {
		$insOut = $db->prepare("INSERT INTO cashout_paid (ym, line_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()");
		foreach ($paid as $k) { $k = trim((string)$k); if ($k !== '') $insOut->execute([$ym, $k]); }
	}

	$db->commit();
	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500);
	echo json_encode(['error' => 'Reconcile failed: ' . $e->getMessage()]);
}
