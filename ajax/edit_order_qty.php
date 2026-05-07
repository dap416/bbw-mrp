<?php

	require_once(__DIR__."/../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$now = date("Y-m-d H:i:s");

	$orderInfo = $dbLink->query("SELECT * FROM `orders` WHERE `id` = '$record'")->fetch();
	$orderPart = $orderInfo['partid'];
	$orderQty = $orderInfo['qty'];
	$ordVal = $orderInfo['ordval'];
	$partVal = $ordVal / $orderQty;
	$newOrderVal = $partVal * $editqty;

	$update = $dbLink->query("UPDATE `orders` SET `qty` = '$editqty', `ordval` = '$newOrderVal' WHERE `id` = '$record'");

	// ADD NOTE
	$note = "SYSTEM: Order QTY changed from $orderQty to $editqty";
	$dbLink->query("INSERT INTO `notes` (`date`,`ordid`,`note`) VALUES ('$now','$record','$note')");
	echo date("m/d/y", strtotime($now)) . " - " . $note;

	// ADD TRANSACTION RECORD

	$adjQty = $editqty - $orderQty;

	$userId = $_SESSION['user_id'] ?? null;
	$addTrans = $dbLink->query("INSERT INTO `trans` (`partid`,`type`,`date`,`ordid`,`qty`,`old`,`new`,`user_id`) VALUES ('$orderPart','ADJORD','$now','$record','$adjQty','$orderQty','$editqty','$userId')");