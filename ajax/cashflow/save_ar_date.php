<?php
/** Set/clear the expected-payment date for a Shopify receivable. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db  = db_connect();
$key = trim($_POST['order_key'] ?? '');
$date= trim($_POST['date'] ?? '');
if ($key === '') { echo 'error: missing order'; exit; }
if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { echo 'error: bad date'; exit; }

try {
	ensure_ar_schedule_table($db);
	if ($date === '') {
		$db->prepare("DELETE FROM ar_schedule WHERE order_key = ?")->execute([$key]);
	} else {
		$db->prepare("INSERT INTO ar_schedule (order_key, expected_date, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE expected_date=VALUES(expected_date), updated_at=NOW()")
		   ->execute([$key, $date]);
	}
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
