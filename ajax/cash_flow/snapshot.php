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

$source = in_array($_POST['source'] ?? '', ['cron', 'seed', 'manual'], true) ? $_POST['source'] : 'manual';
$force  = !empty($_POST['force']);   // deliberate re-freeze of an already-frozen month

try {
	// Freezes the current month's opening (pulls QB first). Write-once unless $force.
	$res = cf_capture_balances($db, true, $source, $force);
	echo json_encode(['ok' => true, 'snap_ym' => $res['froze'], 'already_frozen' => $res['already_frozen'],
		'qb_updated' => $res['qb_updated'],
		'cash_total' => $res['accounts']['start_cash'], 'credit_total' => $res['accounts']['credit_used']]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
