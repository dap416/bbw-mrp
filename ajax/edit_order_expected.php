<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to edit orders.');

	$db = db_connect();

	$record = (int)($_POST['record'] ?? 0);
	$dateIn = trim($_POST['expected'] ?? '');
	if ($record <= 0) { echo 'error'; exit; }

	// Ensure the column exists even if setup_order_expected.php was never run.
	try { $db->exec("ALTER TABLE `orders` ADD COLUMN `expected_date` DATE NULL"); } catch (Throwable $e) {}

	// Empty input clears the expected date (back to "TBD").
	$val = null;
	if ($dateIn !== '') {
		$ts = strtotime($dateIn);
		if ($ts === false) { echo 'Invalid date.'; exit; }
		$val = date('Y-m-d', $ts);
	}

	$order = $db->query("SELECT `id` FROM `orders` WHERE `id` = $record")->fetch();
	if (!$order) { echo 'error'; exit; }

	try {
		$db->prepare("UPDATE `orders` SET `expected_date` = ? WHERE `id` = ?")->execute([$val, $record]);
		echo 'ok';
	} catch (Throwable $e) {
		http_response_code(500);
		echo 'Change failed: ' . $e->getMessage();
	}
