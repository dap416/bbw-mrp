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

	/**
	 * Permission level for an area: 0 = none, 1 = view, 2 = edit.
	 * Admin/master always 2 (full). Orders is treated as editable when the
	 * user has either order action flag, even at view level.
	 */
	function access_level($area) {
		$role = $_SESSION['user_role'] ?? '';
		if ($role === 'admin' || $role === 'master') return 2;
		return (int)($_SESSION['user_access']['access_' . $area] ?? 0);
	}

	/** View gate — can the user open/see this area's page? */
	function has_access($page) {
		$role = $_SESSION['user_role'] ?? '';
		if ($page === 'users') return $role === 'master';
		if ($role === 'admin' || $role === 'master') return true;
		if ($page === 'orders') {
			return access_level('orders') >= 1 || can_do('orders.create') || can_do('orders.receive');
		}
		return access_level($page) >= 1;
	}

	/** Write gate — can the user change things in this area? */
	function can_edit($area) {
		$role = $_SESSION['user_role'] ?? '';
		if ($role === 'admin' || $role === 'master') return true;
		return access_level($area) >= 2;
	}

	/** Specific action gate (e.g. 'orders.create', 'orders.receive'). */
	function can_do($action) {
		$role = $_SESSION['user_role'] ?? '';
		if ($role === 'admin' || $role === 'master') return true;
		switch ($action) {
			case 'orders.create':
				return access_level('orders') >= 2 || !empty($_SESSION['user_access']['access_orders_create']);
			case 'orders.receive':
				return access_level('orders') >= 2 || !empty($_SESSION['user_access']['access_orders_receive']);
		}
		return false;
	}

	/**
	 * Sidebar menu items that a master can show/hide per user (the "Menu View").
	 * key => label. Keys are stable identifiers used in header.php gates and in
	 * the users.php editor — do not rename without updating both.
	 */
	function menu_items() {
		return [
			'dashboard'          => 'Dashboard',
			'orders'             => 'Orders',
			'inventory'          => 'Inventory',
			'warehouse_stock'    => 'Warehouse Stock',
			'tradeshows'         => 'Tradeshow Planner',
			'build'              => 'Packaging',
			'products'           => 'Products',
			'physical_inventory' => 'Physical Inventory',
			'manufacturers'      => 'Manufacturers',
			'tasks'              => 'Task List',
			'cashflow'           => 'Cash Flow',
			'research'           => 'Research',
		];
	}

	/**
	 * Should this sidebar item appear for the current user? Menu View is a
	 * display preference layered on top of the permission gates — hiding an
	 * item never grants or revokes actual page access. Default: visible.
	 */
	function menu_visible($key) {
		$hidden = $_SESSION['user_menu_hidden'] ?? [];
		return !in_array($key, (array)$hidden, true);
	}

	/** Block an AJAX mutation the user isn't allowed to perform. */
	function require_can($cond, $msg = 'You do not have permission to perform this action.') {
		if (!$cond) { http_response_code(403); echo $msg; exit; }
	}

	/**
	 * Convert stored chat messages to a lightweight form for the browser:
	 * array content (with base64 file blocks) collapses to its text, and the
	 * `_files` display metadata (name/kind) is preserved. Keeps multi-MB file
	 * data out of the JSON sent back to the page.
	 */
	function chat_display_messages($messages) {
		$out = [];
		foreach ($messages as $m) {
			$content = $m['content'] ?? '';
			if (is_array($content)) {
				$text = '';
				foreach ($content as $b) { if (($b['type'] ?? '') === 'text') $text .= $b['text']; }
				$content = $text;
			}
			$row = ['role' => $m['role'] ?? 'user', 'content' => $content];
			if (!empty($m['_files'])) $row['_files'] = $m['_files'];
			$out[] = $row;
		}
		return $out;
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
			// This app was built for MySQL's legacy permissive mode and stores
			// '0000-00-00 00:00:00' zero-dates throughout. Clear sql_mode so a
			// server running in strict / NO_ZERO_DATE mode does not reject those
			// values (which otherwise throws "Incorrect DATETIME value" fatals).
			$pdo->exec("SET SESSION sql_mode = ''");
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

	/**
	 * Physical-inventory staging tables. A submitted count is saved here as a
	 * "pending" batch (report) and does NOT touch inventory until confirmed.
	 */
	function phys_inv_ensure_tables($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS phys_inv_batches (
			id             INT AUTO_INCREMENT PRIMARY KEY,
			warehouse_id   INT NOT NULL,
			warehouse_name VARCHAR(190) NULL,
			user_id        INT NULL,
			user_name      VARCHAR(190) NULL,
			status         VARCHAR(12) NOT NULL DEFAULT 'pending',  -- pending | applied | discarded
			total_parts    INT NOT NULL DEFAULT 0,
			variance_parts INT NOT NULL DEFAULT 0,
			created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			applied_at     DATETIME NULL,
			applied_by     INT NULL,
			applied_by_name VARCHAR(190) NULL,
			adjusted_parts INT NULL,
			INDEX (status), INDEX (warehouse_id)
		) ENGINE=InnoDB");
		$db->exec("CREATE TABLE IF NOT EXISTS phys_inv_batch_items (
			id           INT AUTO_INCREMENT PRIMARY KEY,
			batch_id     INT NOT NULL,
			part_id      INT NOT NULL,
			partno       VARCHAR(190) NULL,
			pdesc        VARCHAR(255) NULL,
			qoh_at_count INT NOT NULL DEFAULT 0,
			counted      INT NOT NULL DEFAULT 0,
			diff         INT NOT NULL DEFAULT 0,
			INDEX (batch_id)
		) ENGINE=InnoDB");
	}

	/**
	 * Simple business task list (Microsoft To-Do style): a title, optional due
	 * date, and a completed flag. Shared by tasks.php, the ajax endpoints, and
	 * the dashboard morning briefing.
	 */
	function tasks_ensure_table($db) {
		$db->exec("CREATE TABLE IF NOT EXISTS tasks (
			id           INT AUTO_INCREMENT PRIMARY KEY,
			title        VARCHAR(255) NOT NULL,
			notes        TEXT NULL,
			due_date     DATE NULL,
			completed    TINYINT NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			created_by   INT NULL,
			created_by_name VARCHAR(190) NULL,
			assigned_to      INT NULL,
			assigned_to_name VARCHAR(190) NULL,
			task_type    VARCHAR(24) NOT NULL DEFAULT 'general',
			task_meta    TEXT NULL,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			INDEX (completed), INDEX (due_date), INDEX (assigned_to)
		) ENGINE=InnoDB");
		// Self-heal new columns on existing installs (idempotent).
		foreach ([
			"ALTER TABLE tasks ADD COLUMN assigned_to INT NULL",
			"ALTER TABLE tasks ADD COLUMN assigned_to_name VARCHAR(190) NULL",
			"ALTER TABLE tasks ADD COLUMN task_type VARCHAR(24) NOT NULL DEFAULT 'general'",
			"ALTER TABLE tasks ADD COLUMN task_meta TEXT NULL",
		] as $sql) { try { $db->exec($sql); } catch (Throwable $e) {} }
	}

	/** Active users, for assignment dropdowns. */
	function active_users_list($db) {
		try { return $db->query("SELECT id, name, username FROM users WHERE active = 1 ORDER BY name ASC")->fetchAll(); }
		catch (Throwable $e) { return []; }
	}

	/**
	 * Mark the dashboard AI briefing as stale so it regenerates on the next
	 * dashboard load. Call this on important events (task completed, payment
	 * made, delivery received) so the welcome message reflects them instead of
	 * waiting for the weekly refresh.
	 */
	function briefing_touch($db = null) {
		try {
			if ($db === null) $db = db_connect();
			$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
			$db->prepare("INSERT INTO data_cache (ckey, cval, updated_at) VALUES ('briefing_dirty', ?, NOW())
			              ON DUPLICATE KEY UPDATE cval = VALUES(cval), updated_at = NOW()")
			   ->execute([date('Y-m-d H:i:s')]);
		} catch (Throwable $e) {}
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