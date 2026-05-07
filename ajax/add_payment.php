<?php

	require_once(__DIR__."/../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$now = date("Y-m-d H:i:s");

	$orderInfo = $dbLink->query("SELECT * FROM `orders` WHERE `id` = '$record'")->fetch();
	$ordPayAmt = $orderInfo['paidamt'];

	$addPayment = $dbLink->query("INSERT INTO `payments` (`date`,`ordid`,`amount`,`ref`) VALUES ('$now','$record','$payamt','$payref')");

	// ADJUST ORDER RECORD
	$newPayAmt = $ordPayAmt + $payamt;
	$updatePayAmt = $dbLink->query("UPDATE `orders` SET `paidamt` = '$newPayAmt' WHERE `id` = '$record'");