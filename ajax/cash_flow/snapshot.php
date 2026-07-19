<?php
/**
 * Capture an opening-balance snapshot for a month (the month-grained model).
 * Manual/seed use now; the automated monthly API-pull cron will call this on
 * the last night of each month later. Admin/master only.
 */
require_once(__DIR__ . "/../../includes/cash_flow.php");
require_login();
header('Content-Type: application/json');
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo json_encode(['error' => 'DB connection failed.']); exit; }

$ym     = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['ym'] ?? '')) ? $_POST['ym'] : cf_horizon_start();
$source = in_array($_POST['source'] ?? '', ['cron', 'seed', 'manual'], true) ? $_POST['source'] : 'manual';

try {
	$acc = cf_capture_snapshot($db, $ym, $source);
	echo json_encode(['ok' => true, 'snap_ym' => $ym, 'cash_total' => $acc['start_cash'], 'credit_total' => $acc['credit_used']]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
