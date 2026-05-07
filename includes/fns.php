<?php

	// Enforce Pacific Time for all PHP date/time functions
	date_default_timezone_set('America/Los_Angeles');

	// Start session once
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}

	// Load local secrets (DB creds, dev login). See config.local.example.php.
	$GLOBALS['APP_CONFIG'] = require __DIR__ . '/config.local.php';

	function app_config($path = null) {
		$cfg = $GLOBALS['APP_CONFIG'] ?? [];
		if ($path === null) return $cfg;
		foreach (explode('.', $path) as $key) {
			if (!is_array($cfg) || !array_key_exists($key, $cfg)) return null;
			$cfg = $cfg[$key];
		}
		return $cfg;
	}

	function require_login() {
		if (!isset($_SESSION['user_id'])) {
			header('Location: /login.php');
			exit;
		}
	}

	function has_access($page) {
		$role = $_SESSION['user_role'] ?? '';
		if ($page === 'users') return $role === 'master';
		if ($role === 'admin' || $role === 'master') return true;
		return !empty($_SESSION['user_access']['access_' . $page]);
	}

	function deny_access() {
		echo '<div class="alert alert-warning mt-3" style="max-width:520px;">
			<h5 class="fw-bold mb-1">Access Denied</h5>
			<p class="mb-0 text-muted">You do not have permission to view this page. Please contact your administrator.</p>
		</div>';
		require_once __DIR__ . '/footer.php';
		exit;
	}

	function db_connect() {
		try {
			$db   = app_config('db');
			$dsn  = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
			$pdo  = new PDO(
				$dsn,
				$db['user'],
				$db['pass'],
				[
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
				]
			);
			// Sync MySQL session timezone to Pacific Time (handles DST)
			$offsetSec = (new DateTimeZone('America/Los_Angeles'))->getOffset(new DateTime('now', new DateTimeZone('UTC')));
			$sign      = $offsetSec >= 0 ? '+' : '-';
			$abs       = abs($offsetSec);
			$tzOffset  = sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), ($abs % 3600) / 60);
			$pdo->exec("SET time_zone = '$tzOffset'");
			return $pdo;
		} catch (PDOException $e) {
			return false;
		}
	}
	// ── WAREHOUSE HELPERS ─────────────────────────────────────────────────────

	/** Return all active warehouses ordered by name. */
	function get_warehouses($db) {
		return $db->query("SELECT * FROM warehouses WHERE active = 1 ORDER BY name ASC")->fetchAll();
	}

	/** Get the qty for one part in one warehouse. */
	function wh_get_qty($db, $partId, $warehouseId) {
		$r = $db->query("SELECT qty FROM part_warehouse_qty WHERE part_id = $partId AND warehouse_id = $warehouseId")->fetch();
		return (int)($r['qty'] ?? 0);
	}

	/**
	 * Add a delta (positive or negative) to a warehouse's qty for a part,
	 * then resync parts.qoh to the sum of all warehouses.
	 */
	function wh_adjust($db, $partId, $warehouseId, $delta) {
		$db->exec("INSERT INTO part_warehouse_qty (part_id, warehouse_id, qty)
		           VALUES ($partId, $warehouseId, $delta)
		           ON DUPLICATE KEY UPDATE qty = qty + $delta");
		$db->exec("UPDATE parts SET qoh = (
		    SELECT COALESCE(SUM(qty), 0) FROM part_warehouse_qty WHERE part_id = $partId
		) WHERE id = $partId");
	}

	/**
	 * Set an absolute qty for a warehouse, then resync parts.qoh.
	 * Used for manual inventory count corrections.
	 */
	function wh_set($db, $partId, $warehouseId, $newQty) {
		$db->exec("INSERT INTO part_warehouse_qty (part_id, warehouse_id, qty)
		           VALUES ($partId, $warehouseId, $newQty)
		           ON DUPLICATE KEY UPDATE qty = $newQty");
		$db->exec("UPDATE parts SET qoh = (
		    SELECT COALESCE(SUM(qty), 0) FROM part_warehouse_qty WHERE part_id = $partId
		) WHERE id = $partId");
	}

	// ── LEGACY QTY HELPER (still used by some callers) ────────────────────────

	function adjust_qty($partId,$type,$qty) {
		
		$dbLink = db_connect();
		
		if($type == "post") {
			
			$adjQty = $qty;
			$typeName = "POST";
			
		} else if($type == "build") {
			
			$adjQty = $qty * -1;
			$typeName = "BUILD";
			
		} else if($type == "plus") {
			
			$adjQty = $qty;
			$typeName = "PLUS ADJ";
			
		} else if($type == "minus") {
			
			$adjQty = $qty * -1;
			$typeName = "MINUS ADJ";
			
		}
		
		$currentQOH = $dbLink->query("SELECT `qoh` FROM `parts` WHERE `id` = '$partId'")->fetch();
		$currentQOH = $currentQOH['qoh'];
		
		$newQOH = $currentQOH + $adjQty;
		
		$update = $dbLink->query("UPDATE `parts` SET `qoh` = '$newQOH' WHERE `id` = '$partId'");
		
	}

	// RECALCULATE BSL FOR ALL PARTS
	




?>