<?php
/** Manually refresh the QuickBooks + Shopify cache used by Cash Flow. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo json_encode(['error' => 'Database connection failed.']); exit; }

$log = cashflow_sync($db);
echo json_encode(['ok' => true, 'log' => $log, 'synced_at' => cf_synced_at($db)]);
