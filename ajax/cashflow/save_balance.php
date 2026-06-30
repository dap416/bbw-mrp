<?php
/** Add or update a manually-entered account balance. Admin/master only. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo 'Database connection failed.'; exit; }

$id      = (int)($_POST['id'] ?? 0);
$label   = trim($_POST['label'] ?? '');
$type    = ($_POST['acct_type'] ?? 'bank');
$type    = in_array($type, ['bank','credit','loc'], true) ? $type : 'bank';
$balance = round((float)($_POST['balance'] ?? 0), 2);
$limit   = trim($_POST['credit_limit'] ?? '');
$limit   = ($limit === '' || $type === 'bank') ? null : round((float)$limit, 2);
$payment = trim($_POST['monthly_payment'] ?? '');
$payment = ($payment === '' || $type === 'bank') ? null : round((float)$payment, 2);
$apr     = trim($_POST['apr'] ?? '');
$apr     = ($apr === '' || $type === 'bank') ? null : round((float)$apr, 2);
$asOf    = trim($_POST['as_of'] ?? '');
$note    = trim($_POST['note'] ?? '');
$qbId    = trim($_POST['qb_account_id'] ?? '');

if ($label === '') { echo 'Please enter an account name.'; exit; }
if ($asOf !== '' && strtotime($asOf) === false) { echo 'Invalid date.'; exit; }
if ($asOf === '') $asOf = date('Y-m-d');

try {
	ensure_cash_balances_table($db);
	if ($id > 0) {
		$db->prepare("UPDATE cash_balances SET label=?, acct_type=?, balance=?, credit_limit=?, monthly_payment=?, apr=?, qb_account_id=?, as_of=?, note=?, updated_at=NOW(), user_id=? WHERE id=?")
		   ->execute([$label, $type, $balance, $limit, $payment, $apr, $qbId, $asOf, $note, $_SESSION['user_id'] ?? null, $id]);
	} else {
		$db->prepare("INSERT INTO cash_balances (label, acct_type, balance, credit_limit, monthly_payment, apr, qb_account_id, as_of, note, user_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
		   ->execute([$label, $type, $balance, $limit, $payment, $apr, $qbId, $asOf, $note, $_SESSION['user_id'] ?? null]);
	}
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
