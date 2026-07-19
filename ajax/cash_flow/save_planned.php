<?php
/** Set a facility's planned monthly payment (cash_balances.monthly_payment). Admin/master. */
require_once(__DIR__ . "/../../includes/fns.php");
require_login();
header('Content-Type: application/json');
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
$id      = (int)($_POST['id'] ?? 0);
$planned = max(0.0, (float)($_POST['planned'] ?? 0));
if ($id <= 0) { echo json_encode(['error' => 'Missing id.']); exit; }
try {
	$db->prepare("UPDATE cash_balances SET monthly_payment = ?, updated_at = NOW() WHERE id = ?")->execute([$planned, $id]);
	echo json_encode(['ok' => true]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
