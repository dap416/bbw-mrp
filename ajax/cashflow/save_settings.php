<?php
/** Save cash-flow settings (currently the Shopify loan %). Admin/master only. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php"); // setting_set
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo 'Database connection failed.'; exit; }

try {
	$db->exec("CREATE TABLE IF NOT EXISTS settings (skey VARCHAR(64) PRIMARY KEY, sval TEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	if (isset($_POST['shopify_loan_pct'])) {
		$pct = max(0.0, min(100.0, (float)$_POST['shopify_loan_pct']));
		setting_set($db, 'shopify_loan_pct', (string)$pct);
	}
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
