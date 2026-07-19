<?php
/** Delete a cash-flow forecast record. Admin/master only. */
require_once(__DIR__ . "/../../includes/fns.php");
require_login();
header('Content-Type: application/json');
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['error' => 'Missing id.']); exit; }
try {
	$db->prepare("DELETE FROM cf_records WHERE id = ?")->execute([$id]);
	echo json_encode(['ok' => true]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
