<?php
/**
 * One-time migration: add per-user "Menu View" preferences.
 * Adds users.menu_hidden (JSON array of sidebar menu keys to hide for that user).
 * Idempotent — safe to run more than once. Master only.
 */
require_once(__DIR__.'/includes/fns.php');
require_login();
if (($_SESSION['user_role'] ?? '') !== 'master') { http_response_code(403); exit('Master only.'); }

$db  = db_connect();
$log = [];
function run($db, $sql, &$log, $desc) {
    try { $db->exec($sql); $log[] = "✓ $desc"; }
    catch (Throwable $e) { $log[] = "⚠ $desc — " . $e->getMessage(); }
}

run($db, "ALTER TABLE `users` ADD COLUMN `menu_hidden` TEXT DEFAULT NULL", $log, "Add menu_hidden to users");

?><!doctype html><html><head><title>Menu View Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>Menu View Migration</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">A "Duplicate column" warning is expected if this already ran — that's fine. All users default to seeing every menu item; hide items per user from the Users page.</p>
<a href="/users.php" class="btn btn-primary btn-sm">Go to Users</a>
</body></html>
