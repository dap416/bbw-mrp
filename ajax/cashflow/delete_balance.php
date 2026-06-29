<?php
/** Delete a manually-entered account balance. Admin/master only. */
require_once(__DIR__."/../../includes/fns.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo 'error'; exit; }

try {
	$db->prepare("DELETE FROM cash_balances WHERE id = ?")->execute([$id]);
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Delete failed: ' . $e->getMessage();
}
