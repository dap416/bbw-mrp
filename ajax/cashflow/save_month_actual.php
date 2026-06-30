<?php
/** Save a month's actual projection or actual income override. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db    = db_connect();
$ym    = trim($_POST['ym'] ?? '');
$field = ($_POST['field'] ?? '') === 'income' ? 'actual_income' : 'actual_projection';
$raw   = trim($_POST['value'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { echo 'error: bad month'; exit; }
$val = ($raw === '') ? null : round((float)str_replace([',', '$'], '', $raw), 2);

try {
	ensure_month_actuals_table($db);
	// Upsert just this field; keep the other column intact.
	$db->prepare("INSERT INTO cash_month_actuals (ym, `$field`, updated_at) VALUES (?,?,NOW())
	              ON DUPLICATE KEY UPDATE `$field` = VALUES(`$field`), updated_at=NOW()")
	   ->execute([$ym, $val]);
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
