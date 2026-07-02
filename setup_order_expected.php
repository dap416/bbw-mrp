<?php
/**
 * One-time migration: add an "Expected Arrival" date to orders.
 * Adds orders.expected_date so standard users (e.g. packagers) can see when
 * an order is due to arrive without seeing any financials. Idempotent — safe
 * to run more than once. Admin/master only.
 */
require_once(__DIR__.'/includes/fns.php');
require_login();
if (!in_array($_SESSION['user_role'] ?? '', ['admin','master'], true)) { http_response_code(403); exit('Admins only.'); }

$db  = db_connect();
$log = [];
function run($db, $sql, &$log, $desc) {
    try { $db->exec($sql); $log[] = "✓ $desc"; }
    catch (Throwable $e) { $log[] = "⚠ $desc — " . $e->getMessage(); }
}

run($db, "ALTER TABLE `orders` ADD COLUMN `expected_date` DATE NULL", $log, "Add expected_date (Expected Arrival) to orders");

?><!doctype html><html><head><title>Expected Arrival Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>Expected Arrival Setup Results</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">A "Duplicate column" warning means it already ran — that's fine. Orders without a date entered show "TBD". Delete or restrict access to this file.</p>
<a href="/orders.php" class="btn btn-primary btn-sm">Go to Orders</a>
</body></html>
