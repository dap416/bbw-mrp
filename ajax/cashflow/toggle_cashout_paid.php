<?php
/** Tick / untick a monthly cash-out line as already paid (per ym + line key). Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
ensure_cashout_paid_table($db);

$ym   = trim($_POST['ym'] ?? '');
$key  = trim($_POST['line_key'] ?? '');
$paid = !empty($_POST['paid']);
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || $key === '') { echo json_encode(['error' => 'Bad request.']); exit; }

try {
	if ($paid) $db->prepare("INSERT INTO cashout_paid (ym, line_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()")->execute([$ym, $key]);
	else       $db->prepare("DELETE FROM cashout_paid WHERE ym = ? AND line_key = ?")->execute([$ym, $key]);
	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	http_response_code(500); echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
