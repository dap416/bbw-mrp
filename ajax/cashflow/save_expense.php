<?php
/** Add or update a recurring monthly expense. Admin/master only. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo 'Database connection failed.'; exit; }

$id       = (int)($_POST['id'] ?? 0);
$label    = trim($_POST['label'] ?? '');
$amount   = round((float)($_POST['amount'] ?? 0), 2);
$category = trim($_POST['category'] ?? '');

if ($label === '') { echo 'Please enter an expense name.'; exit; }

try {
	ensure_cash_expenses_table($db);
	if ($id > 0) {
		$db->prepare("UPDATE cash_expenses SET label=?, amount=?, category=?, updated_at=NOW(), user_id=? WHERE id=?")
		   ->execute([$label, $amount, $category, $_SESSION['user_id'] ?? null, $id]);
	} else {
		$db->prepare("INSERT INTO cash_expenses (label, amount, category, user_id) VALUES (?,?,?,?)")
		   ->execute([$label, $amount, $category, $_SESSION['user_id'] ?? null]);
	}
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
