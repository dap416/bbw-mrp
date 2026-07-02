<?php
/**
 * One-time migration: add a "Build By" due date to packaging orders.
 * Adds intransit.duedate so builders know the deadline for each FP order.
 * Idempotent — safe to run more than once. Run once via browser, then delete/restrict.
 */
require_once(__DIR__.'/includes/fns.php');
require_login();
if (!has_access('build')) { http_response_code(403); exit('Forbidden'); }

$db  = db_connect();
$log = [];

function run($db, $sql, &$log, $desc) {
    try { $db->exec($sql); $log[] = "✓ $desc"; }
    catch (PDOException $e) { $log[] = "⚠ $desc — " . $e->getMessage(); }
}

run($db, "ALTER TABLE intransit ADD COLUMN duedate DATE DEFAULT NULL", $log, "Add duedate (Build By) to intransit");

?><!doctype html><html><head><title>Build-By Date Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>Build-By Date Setup Results</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">If the column already existed you'll see a warning — that's fine. Delete or restrict access to this file.</p>
<a href="/build.php" class="btn btn-primary btn-sm">Go to Packaging</a>
</body></html>
