<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_do('orders.create'), 'You do not have permission to create orders.');

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$now = date("Y-m-d H:i:s");

	

	$partid = (int)($_POST['partid'] ?? 0);
	$qty    = (int)($_POST['qty'] ?? 0);
	$refnum = trim($_POST['refnum'] ?? '');
	$payBy  = (isset($_POST['pay_by']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['pay_by'])) ? $_POST['pay_by'] : '';

	if ($partid <= 0 || $qty <= 0) { echo 'error: missing part or quantity'; exit; }
	if ($payBy === '') { echo 'error: a valid pay-by date is required (this PO is a card charge that hits cash flow on that date)'; exit; }

	// POs carry a pay-by date (card charge due) used by the Cash Flow forecast.
	try { $dbLink->exec("ALTER TABLE `orders` ADD COLUMN `pay_by` DATE NULL"); } catch (Throwable $e) {}

	$partInfo = $dbLink->query("SELECT `cost`,`qoh` FROM `parts` WHERE `id` = '$partid'")->fetch();
	$partCost = (float)($partInfo['cost'] ?? 0);
	$partQty  = (int)($partInfo['qoh'] ?? 0);
	$orderVal = $partCost * $qty;

	$userId = $_SESSION['user_id'] ?? null;
	$dbLink->prepare("INSERT INTO `trans` (`partid`,`type`,`date`,`postref`,`qty`,`old`,`new`,`user_id`) VALUES (?,'ORDER',?,?,?,?,?,?)")
	       ->execute([$partid, $now, $refnum, $qty, $partQty, $partQty, $userId]);

	$dbLink->prepare("INSERT INTO `orders` (`partid`,`qty`,`ordval`,`orderdate`,`orderref`,`pay_by`) VALUES (?,?,?,?,?,?)")
	       ->execute([$partid, $qty, $orderVal, $now, $refnum, $payBy]);

	echo 'ok';