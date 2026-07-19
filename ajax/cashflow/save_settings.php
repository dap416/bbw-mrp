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
	if (isset($_POST['cash_buffer'])) {
		setting_set($db, 'cash_buffer', (string)max(0.0, (float)$_POST['cash_buffer']));
	}
	if (isset($_POST['tax_monthly'])) {
		setting_set($db, 'tax_monthly', (string)max(0.0, (float)$_POST['tax_monthly']));
	}
	if (isset($_POST['loc_limit'])) {
		setting_set($db, 'loc_limit', (string)max(0.0, (float)$_POST['loc_limit']));
	}
	if (isset($_POST['loc_ceilings'])) {
		$j = json_decode($_POST['loc_ceilings'], true);
		$clean = [];
		if (is_array($j)) foreach ($j as $c) {
			$name = trim((string)($c['name'] ?? ''));
			$ceil = max(0.0, (float)($c['ceiling'] ?? 0));
			if ($name !== '') $clean[] = ['name' => $name, 'ceiling' => $ceil];
		}
		setting_set($db, 'loc_ceilings', json_encode($clean));
	}
	if (isset($_POST['card_min_pct'])) {
		setting_set($db, 'card_min_pct', (string)max(0.0, min(100.0, (float)$_POST['card_min_pct'])));
	}
	if (isset($_POST['card_min_floor'])) {
		setting_set($db, 'card_min_floor', (string)max(0.0, (float)$_POST['card_min_floor']));
	}
	// New Cash Flow module knobs.
	if (isset($_POST['avg_sales_tax_pct'])) {
		setting_set($db, 'avg_sales_tax_pct', (string)max(0.0, min(100.0, (float)$_POST['avg_sales_tax_pct'])));
	}
	if (isset($_POST['cf_avail_debt'])) {
		setting_set($db, 'cf_avail_debt', (string)max(0.0, (float)$_POST['cf_avail_debt']));
	}
	if (isset($_POST['cashflow_hide_before'])) {
		$v = trim($_POST['cashflow_hide_before']);
		if ($v === '' || $v === 'reset')          { setting_set($db, 'cashflow_hide_before', ''); }
		elseif (preg_match('/^\d{4}-\d{2}$/', $v)) { setting_set($db, 'cashflow_hide_before', date('Y-m', strtotime($v . '-01 +1 month'))); }
	}
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
