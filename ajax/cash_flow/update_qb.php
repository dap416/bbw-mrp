<?php
/**
 * Pull the latest account balances from QuickBooks into cash_balances (auto-synced accounts only),
 * bypassing the nightly cache. Use right after refreshing your card feeds inside QuickBooks so the
 * app sees today's numbers without waiting for the overnight sync. Only accounts linked by
 * qb_account_id are touched; manual accounts (e.g. Shopify Capital) are left alone. Admin/master.
 */
require_once(__DIR__ . "/../../includes/cash_flow.php");
require_login();
header('Content-Type: application/json');
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo json_encode(['error' => 'DB connection failed.']); exit; }
if (!qb_is_connected()) { echo json_encode(['error' => "QuickBooks isn't connected. Reconnect it on the Integrations page."]); exit; }

try {
	$fresh = cf_accounts($db, true);              // force a live pull from QuickBooks (bypass the nightly cache)
	if (!empty($fresh['error'])) { echo json_encode(['error' => 'QuickBooks: ' . $fresh['error']]); exit; }
	$updated = cf_upsert_qb_balances($db);        // push those balances onto the linked (auto-synced) accounts
	echo json_encode(['ok' => true, 'updated' => $updated]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
