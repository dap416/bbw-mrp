<?php
/**
 * One-time warehouse migration script.
 * Run once via browser, then delete or restrict access.
 */
require_once(__DIR__.'/includes/fns.php');
require_login();
$db = db_connect();
$log = [];

function run($db, $sql, &$log, $desc) {
    try { $db->exec($sql); $log[] = "✓ $desc"; }
    catch (PDOException $e) { $log[] = "⚠ $desc — " . $e->getMessage(); }
}

// 1. warehouses table
run($db, "CREATE TABLE IF NOT EXISTS warehouses (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(100) NOT NULL,
    location  VARCHAR(255) DEFAULT NULL,
    active    TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB", $log, "Create warehouses table");

// 2. part_warehouse_qty table
run($db, "CREATE TABLE IF NOT EXISTS part_warehouse_qty (
    part_id      INT NOT NULL,
    warehouse_id INT NOT NULL,
    qty          INT NOT NULL DEFAULT 0,
    PRIMARY KEY (part_id, warehouse_id),
    FOREIGN KEY (part_id)      REFERENCES parts(id)       ON DELETE CASCADE,
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB", $log, "Create part_warehouse_qty table");

// 3. Seed Main Warehouse
$existing = $db->query("SELECT COUNT(*) AS c FROM warehouses")->fetch()['c'];
if ($existing == 0) {
    run($db, "INSERT INTO warehouses (id, name, location) VALUES (1, 'Main Warehouse', NULL)", $log, "Seed Main Warehouse");
} else {
    $log[] = "— Warehouses already seeded (skipped)";
}

// 4. Add warehouse_id columns
foreach ([
    "ALTER TABLE orders     ADD COLUMN warehouse_id INT DEFAULT NULL" => "Add warehouse_id to orders",
    "ALTER TABLE ordpost    ADD COLUMN warehouse_id INT DEFAULT NULL" => "Add warehouse_id to ordpost",
    "ALTER TABLE trans      ADD COLUMN warehouse_id INT DEFAULT NULL" => "Add warehouse_id to trans",
    "ALTER TABLE intransit  ADD COLUMN warehouse_id INT DEFAULT NULL" => "Add warehouse_id to intransit",
] as $sql => $desc) {
    run($db, $sql, $log, $desc);
}

// 5. Migrate existing parts.qoh → part_warehouse_qty (warehouse_id=1)
$parts = $db->query("SELECT id, qoh FROM parts")->fetchAll();
$migrated = 0;
foreach ($parts as $p) {
    $existing = $db->query("SELECT COUNT(*) AS c FROM part_warehouse_qty WHERE part_id = {$p['id']} AND warehouse_id = 1")->fetch()['c'];
    if ($existing == 0) {
        $db->exec("INSERT INTO part_warehouse_qty (part_id, warehouse_id, qty) VALUES ({$p['id']}, 1, {$p['qoh']})");
        $migrated++;
    }
}
$log[] = "✓ Migrated $migrated part(s) to Main Warehouse";

?><!doctype html><html><head><title>Warehouse Setup</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-4">
<h4>Warehouse Setup Results</h4>
<ul class="list-group mt-3">
<?php foreach ($log as $l): ?>
<li class="list-group-item"><?php echo htmlspecialchars($l); ?></li>
<?php endforeach; ?>
</ul>
<p class="mt-3 text-muted">Setup complete. Delete or restrict access to this file.</p>
<a href="/warehouses.php" class="btn btn-primary btn-sm">Go to Warehouse Manager</a>
</body></html>
