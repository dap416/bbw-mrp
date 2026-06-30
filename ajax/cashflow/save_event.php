<?php
/** Add or update a manual cash-in / cash-out event (month + week). Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo 'Database connection failed.'; exit; }

$id     = (int)($_POST['id'] ?? 0);
$etype  = ($_POST['etype'] ?? 'out') === 'in' ? 'in' : 'out';
$label  = trim($_POST['label'] ?? '');
$amount = round((float)($_POST['amount'] ?? 0), 2);
$ym     = trim($_POST['ym'] ?? '');
$week   = max(1, min(4, (int)($_POST['week'] ?? 1)));

if ($label === '')                       { echo 'Please enter what the event is.'; exit; }
if ($amount <= 0)                        { echo 'Enter an amount greater than 0.'; exit; }
if (!preg_match('/^\d{4}-\d{2}$/', $ym))  { echo 'Pick a month.'; exit; }

try {
	ensure_cash_events_table($db);
	if ($id > 0) {
		$db->prepare("UPDATE cash_events SET etype=?, label=?, amount=?, ym=?, week=?, updated_at=NOW(), user_id=? WHERE id=?")
		   ->execute([$etype, $label, $amount, $ym, $week, $_SESSION['user_id'] ?? null, $id]);
	} else {
		$db->prepare("INSERT INTO cash_events (etype, label, amount, ym, week, user_id) VALUES (?,?,?,?,?,?)")
		   ->execute([$etype, $label, $amount, $ym, $week, $_SESSION['user_id'] ?? null]);
	}
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
