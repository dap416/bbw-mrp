<?php
/**
 * One-time migration: upgrade the user permission model from page-level on/off
 * to per-area levels (0=None, 1=View, 2=Edit) plus Orders create/receive flags.
 * Existing users with access (old value 1 = full) are bumped to Edit(2) so no
 * one loses access. Safe to re-run: column adds are idempotent and the data
 * migration only runs once (guarded by a settings flag). Master only.
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

$areas = ['access_orders','access_inventory','access_products','access_build','access_manufacturers','access_research'];

// 1. Ensure every area column exists (TINYINT level). Duplicates error harmlessly.
foreach ($areas as $col) {
    run($db, "ALTER TABLE `users` ADD COLUMN `$col` TINYINT NOT NULL DEFAULT 0", $log, "Ensure column $col");
}
run($db, "ALTER TABLE `users` ADD COLUMN `access_orders_create`  TINYINT NOT NULL DEFAULT 0", $log, "Ensure column access_orders_create");
run($db, "ALTER TABLE `users` ADD COLUMN `access_orders_receive` TINYINT NOT NULL DEFAULT 0", $log, "Ensure column access_orders_receive");

// 2. One-time data migration (old 1 = full access → Edit(2); grant create+receive).
$already = false;
try { $already = (setting_get($db, 'perms_migrated') === '1'); } catch (Throwable $e) {}

if ($already) {
    $log[] = "— Data migration already applied (skipped). Columns ensured above.";
} else {
    foreach ($areas as $col) {
        run($db, "UPDATE `users` SET `$col` = 2 WHERE `$col` = 1", $log, "Upgrade existing $col (full) → Edit");
    }
    run($db, "UPDATE `users` SET `access_orders_create` = 1, `access_orders_receive` = 1 WHERE `access_orders` >= 2",
        $log, "Grant create + receive to existing Orders users");
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS settings (skey VARCHAR(64) PRIMARY KEY, sval TEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
        setting_set($db, 'perms_migrated', '1');
        $log[] = "✓ Marked data migration complete";
    } catch (Throwable $e) { $log[] = "⚠ Could not set perms_migrated flag — " . $e->getMessage(); }
}

?><!doctype html><html><head><title>Permissions Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>User Permissions Migration</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">"Duplicate column" warnings are expected and safe. Existing users keep full (Edit) access. Set finer permissions in the Users page.</p>
<a href="/users.php" class="btn btn-primary btn-sm">Go to Users</a>
</body></html>
