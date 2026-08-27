<?php
/** Save a month's actual projection or actual income override. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'Admins only.'; exit; }

$db    = db_connect();
$ym    = trim($_POST['ym'] ?? '');
// Whitelist: never interpolate user input into the column name.
$fields = ['income' => 'actual_income', 'projection' => 'actual_projection',
           'mtd' => 'sales_mtd', 'rest' => 'sales_rest'];
$field = $fields[$_POST['field'] ?? ''] ?? 'actual_projection';
$raw   = trim($_POST['value'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $ym)) { echo 'error: bad month'; exit; }
$val = ($raw === '') ? null : round((float)str_replace([',', '$'], '', $raw), 2);
if ($val !== null && $val < 0) { echo 'error: cannot be negative'; exit; }

// The in-progress sales figures carry their own as-of date so the page can tell you when
// they have gone stale — a month-old "sales so far" is worse than none.
$stampAsOf = in_array($field, ['sales_mtd', 'sales_rest'], true);

try {
	ensure_month_actuals_table($db);
	// Upsert just this field; keep the other columns intact.
	$cols = "(ym, `$field`, updated_at" . ($stampAsOf ? ", sales_asof" : "") . ")";
	$vals = "(?,?,NOW()" . ($stampAsOf ? ", CURDATE()" : "") . ")";
	$upd  = "`$field` = VALUES(`$field`), updated_at=NOW()" . ($stampAsOf ? ", sales_asof = CURDATE()" : "");
	$db->prepare("INSERT INTO cash_month_actuals $cols VALUES $vals ON DUPLICATE KEY UPDATE $upd")
	   ->execute([$ym, $val]);
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Save failed: ' . $e->getMessage();
}
