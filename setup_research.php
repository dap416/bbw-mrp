<?php
/**
 * One-time migration for the Research page.
 * Adds a shopify_sku column to the products table so each MRP product
 * can be linked to its Shopify variant. Run once via browser, then
 * delete or restrict access.
 */
require_once(__DIR__.'/includes/fns.php');
require_login();
$db  = db_connect();
$log = [];

function run($db, $sql, &$log, $desc) {
    try { $db->exec($sql); $log[] = "✓ $desc"; }
    catch (PDOException $e) { $log[] = "⚠ $desc — " . $e->getMessage(); }
}

// Add shopify_sku to products (errors harmlessly if it already exists)
run($db,
    "ALTER TABLE products ADD COLUMN shopify_sku VARCHAR(100) DEFAULT NULL",
    $log, "Add shopify_sku column to products");

// Add annual_goal to products (errors harmlessly if it already exists)
run($db,
    "ALTER TABLE products ADD COLUMN annual_goal INT NOT NULL DEFAULT 0",
    $log, "Add annual_goal column to products");

// Key-value settings table (stores Shopify credentials entered in the app)
run($db,
    "CREATE TABLE IF NOT EXISTS settings (
        skey       VARCHAR(64) NOT NULL PRIMARY KEY,
        sval       TEXT,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
    $log, "Create settings table");

?><!doctype html><html><head><title>Research Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>Research Setup Results</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">If the column already existed the warning above is expected and safe. Setup complete — delete or restrict access to this file.</p>
<a href="/research.php" class="btn btn-primary btn-sm">Go to Research</a>
</body></html>
