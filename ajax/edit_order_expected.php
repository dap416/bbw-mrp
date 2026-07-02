<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_manage_orders(), 'Only master admins can edit orders. You can still receive shipments and add notes.');

	$db = db_connect();

	$record = (int)($_POST['record'] ?? 0);
	$dateIn = trim($_POST['expected'] ?? '');
	if ($record <= 0) { echo 'error'; exit; }

	// Writes the shared `orders.eta` column (same field the dashboard uses), so
	// the Orders page and dashboard always agree. Self-heal in case it's missing.
	try { $db->exec("ALTER TABLE `orders` ADD COLUMN `eta` DATE NULL"); } catch (Throwable $e) {}

	// Empty input clears the date (back to "TBD").
	$val = null;
	if ($dateIn !== '') {
		$ts = strtotime($dateIn);
		if ($ts === false) { echo 'Invalid date.'; exit; }
		$val = date('Y-m-d', $ts);
	}

	$order = $db->query("SELECT `id` FROM `orders` WHERE `id` = $record")->fetch();
	if (!$order) { echo 'error'; exit; }

	try {
		$db->prepare("UPDATE `orders` SET `eta` = ? WHERE `id` = ?")->execute([$val, $record]);
		echo 'ok';
	} catch (Throwable $e) {
		http_response_code(500);
		echo 'Change failed: ' . $e->getMessage();
	}
