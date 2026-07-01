<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to edit orders.');

	$db = db_connect();

	$record  = (int)($_POST['record'] ?? 0);
	$payref  = trim($_POST['payref'] ?? '');
	$payfull = !empty($_POST['payfull']);
	$now     = date("Y-m-d H:i:s");

	if ($record <= 0) { echo 'error'; exit; }

	$order = $db->query("SELECT `ordval`,`paidamt` FROM `orders` WHERE `id` = $record")->fetch();
	if (!$order) { echo 'error'; exit; }

	$ordVal = (float)$order['ordval'];
	$paid   = (float)$order['paidamt'];

	if ($payfull) {
		// Exact remaining balance (covers any earlier partial payment).
		$amt = round($ordVal - $paid, 2);
		if ($amt <= 0) { echo 'paid'; exit; }   // already paid in full
	} else {
		$amt = round((float)($_POST['payamt'] ?? 0), 2);
		if ($amt <= 0) { echo 'error'; exit; }
	}

	$db->prepare("INSERT INTO `payments` (`date`,`ordid`,`amount`,`ref`) VALUES (?,?,?,?)")
	   ->execute([$now, $record, $amt, $payref]);

	$db->prepare("UPDATE `orders` SET `paidamt` = ? WHERE `id` = ?")
	   ->execute([round($paid + $amt, 2), $record]);

	briefing_touch($db);   // a payment is a notable event → refresh dashboard welcome

	echo 'ok';
