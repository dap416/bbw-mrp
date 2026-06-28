<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to edit orders.');

	$db = db_connect();

	$record = (int)($_POST['record'] ?? 0);
	$dateIn = trim($_POST['orderdate'] ?? '');
	if ($record <= 0 || $dateIn === '') { echo 'error'; exit; }

	// Must be a valid date and NOT in the future (backdating is allowed).
	$ts = strtotime($dateIn);
	if ($ts === false) { echo 'Invalid date.'; exit; }
	$d = date('Y-m-d', $ts);
	if ($d > date('Y-m-d')) { echo 'Order date cannot be in the future.'; exit; }

	$order = $db->query("SELECT `partid`,`orderref` FROM `orders` WHERE `id` = $record")->fetch();
	if (!$order) { echo 'error'; exit; }
	$partId = (int)$order['partid'];
	$ref    = (string)$order['orderref'];
	$newDateTime = $d . ' 12:00:00';

	try {
		$db->beginTransaction();

		$db->prepare("UPDATE `orders` SET `orderdate` = ? WHERE `id` = ?")->execute([$newDateTime, $record]);

		// Keep the placed-order history entry's date aligned (best-effort match).
		if ($ref !== '') {
			$db->prepare("UPDATE `trans` SET `date` = ?
			              WHERE `partid` = ? AND `type` = 'ORDER' AND `postref` = ?
			              ORDER BY `id` DESC LIMIT 1")
			   ->execute([$newDateTime, $partId, $ref]);
		}

		$db->commit();
		echo 'ok';
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		http_response_code(500);
		echo 'Change failed: ' . $e->getMessage();
	}
