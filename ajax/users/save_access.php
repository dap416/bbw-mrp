<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	// Only master admins manage user permissions.
	require_can(($_SESSION['user_role'] ?? '') === 'master', 'Only master admins can change permissions.');

	$db = db_connect();

	$record = (int)($_POST['record'] ?? 0);
	$access = json_decode($_POST['access'] ?? '[]', true) ?: [];
	if ($record <= 0) { echo 'error'; exit; }

	// Levels clamped to 0..2; action flags 0/1
	$lvl = function($k) use ($access) { $v = (int)($access[$k] ?? 0); return $v < 0 ? 0 : ($v > 2 ? 2 : $v); };
	$flag = fn($k) => !empty($access[$k]) ? 1 : 0;

	$stmt = $db->prepare(
		"UPDATE `users` SET
			`access_orders` = ?, `access_inventory` = ?, `access_products` = ?,
			`access_build` = ?, `access_manufacturers` = ?, `access_research` = ?,
			`access_orders_create` = ?, `access_orders_receive` = ?
		 WHERE `id` = ?");
	$stmt->execute([
		$lvl('access_orders'), $lvl('access_inventory'), $lvl('access_products'),
		$lvl('access_build'), $lvl('access_manufacturers'), $lvl('access_research'),
		$flag('access_orders_create'), $flag('access_orders_receive'),
		$record,
	]);

	echo 'ok';
