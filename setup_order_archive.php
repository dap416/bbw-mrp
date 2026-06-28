<?php
/**
 * One-time migration: add order archiving.
 *   - `archived`      TINYINT  — 0 = active (shown on Open Orders), 1 = archived.
 *   - `archived_date` DATETIME — when it was archived.
 *
 * Orders now stay on the Open Orders list until they are explicitly archived
 * (paid in full + received). To avoid flooding the list with the entire order
 * history, every ALREADY-completed order (fully received) is marked archived on
 * first run. Safe to re-run: column adds are idempotent and the back-fill is
 * guarded by a settings flag. Master only.
 */
require_once(__DIR__.'/includes/fns.php');
require_once(__DIR__.'/includes/shopify.php'); // setting_get/set
require_login();
if (($_SESSION['user_role'] ?? '') !== 'master') { http_response_code(403); exit('Master only.'); }

$db  = db_connect();
$log = [];
function run($db, $sql, &$log, $desc) {
    try { $db->exec($sql); $log[] = "✓ $desc"; }
    catch (Throwable $e) { $log[] = "⚠ $desc — " . $e->getMessage(); }
}

// 1. Columns (duplicate-column errors are harmless on re-run).
run($db, "ALTER TABLE `orders` ADD COLUMN `archived` TINYINT NOT NULL DEFAULT 0", $log, "Ensure column archived");
run($db, "ALTER TABLE `orders` ADD COLUMN `archived_date` DATETIME NULL", $log, "Ensure column archived_date");

// 2. One-time back-fill: archive everything that's already complete so the
//    Open Orders list doesn't suddenly show the full order history.
$already = false;
try { $already = (setting_get($db, 'orders_archive_migrated') === '1'); } catch (Throwable $e) {}

if ($already) {
    $log[] = "— Back-fill already applied (skipped). Columns ensured above.";
} else {
    run($db,
        "UPDATE `orders` SET `archived` = 1, `archived_date` = NOW()
         WHERE `archived` = 0 AND (`postdate` <> '0000-00-00 00:00:00' OR `recqty` >= `qty`)",
        $log, "Archive existing completed orders (kept off the active list)");
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS settings (skey VARCHAR(64) PRIMARY KEY, sval TEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
        setting_set($db, 'orders_archive_migrated', '1');
        $log[] = "✓ Marked back-fill complete";
    } catch (Throwable $e) { $log[] = "⚠ Could not set orders_archive_migrated flag — " . $e->getMessage(); }
}

?><!doctype html><html><head><title>Order Archive Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>Order Archiving Migration</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">"Duplicate column" warnings are expected and safe. Open Orders now shows every order until it is archived; completed past orders were archived so the list isn't flooded.</p>
<a href="/orders.php" class="btn btn-primary btn-sm">Go to Orders</a>
</body></html>
