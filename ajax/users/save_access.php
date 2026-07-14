<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	header('Content-Type: application/json');

	// Only master admins manage user permissions.
	if (($_SESSION['user_role'] ?? '') !== 'master') {
		http_response_code(403);
		echo json_encode(['ok' => false, 'error' => 'Only master admins can change permissions.']);
		exit;
	}

	$db = db_connect();
	if (!$db) { echo json_encode(['ok' => false, 'error' => 'Database connection failed.']); exit; }

	$record = (int)($_POST['record'] ?? 0);
	$access = json_decode($_POST['access'] ?? '[]', true) ?: [];
	if ($record <= 0) { echo json_encode(['ok' => false, 'error' => 'Missing user id.']); exit; }

	// Self-heal: make sure the permission columns exist even if the one-time
	// migration (setup_user_perms.php) was never run on this server. Without
	// this, the UPDATE below would throw a 500 and the save would silently fail.
	// Columns are driven by permission_areas()/permission_flags() — a new area needs no edit here.
	$levelCols = array_keys(permission_areas());
	$flagCols  = array_keys(permission_flags());
	foreach (array_merge($levelCols, $flagCols) as $col) {
		try { $db->exec("ALTER TABLE `users` ADD COLUMN `$col` TINYINT NOT NULL DEFAULT 0"); }
		catch (Throwable $e) { /* duplicate column = already there, fine */ }
	}

	// Levels clamped to 0..2; action flags 0/1.
	$lvl  = function($k) use ($access) { $v = (int)($access[$k] ?? 0); return $v < 0 ? 0 : ($v > 2 ? 2 : $v); };
	$flag = fn($k) => !empty($access[$k]) ? 1 : 0;

	try {
		$sets = []; $vals = [];
		foreach ($levelCols as $col) { $sets[] = "`$col` = ?"; $vals[] = $lvl($col); }
		foreach ($flagCols  as $col) { $sets[] = "`$col` = ?"; $vals[] = $flag($col); }
		$vals[] = $record;
		$db->prepare("UPDATE `users` SET " . implode(', ', $sets) . " WHERE `id` = ?")->execute($vals);
	} catch (Throwable $e) {
		echo json_encode(['ok' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
		exit;
	}

	// Read the row back so the UI can prove what's now stored in the database.
	$cols = implode(', ', array_map(fn($c) => "`$c`", array_merge($levelCols, $flagCols)));
	$row = $db->prepare("SELECT `name`, $cols FROM `users` WHERE `id` = ?");
	$row->execute([$record]);
	$saved = $row->fetch() ?: [];

	// If the master just edited THEIR OWN permissions, refresh their live session
	// so the change is immediately operational without re-login.
	if ($record === (int)($_SESSION['user_id'] ?? 0)) {
		foreach (array_merge($levelCols, $flagCols) as $col) {
			$_SESSION['user_access'][$col] = (int)($saved[$col] ?? 0);
		}
	}

	echo json_encode(['ok' => true, 'saved' => $saved, 'self' => $record === (int)($_SESSION['user_id'] ?? 0)]);
