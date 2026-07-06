<?php
/** Toggle the 'paid' flag on a credit-card cash event (already on the card balance). Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo 'Database connection failed.'; exit; }

$id   = (int)($_POST['id'] ?? 0);
$paid = !empty($_POST['paid']) ? 1 : 0;
if ($id <= 0) { echo 'Bad id.'; exit; }

try {
	ensure_cash_events_table($db);
	$db->prepare("UPDATE cash_events SET paid=?, updated_at=NOW() WHERE id=?")->execute([$paid, $id]);
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
