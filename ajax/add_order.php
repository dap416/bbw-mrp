<?php

	require_once(__DIR__."/../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$now = date("Y-m-d H:i:s");

	

	$partInfo = $dbLink->query("SELECT `cost`,`qoh` FROM `parts` WHERE `id` = '$partid'")->fetch();
	$partCost = $partInfo['cost'];
	$partQty = $partInfo['qoh'];
	echo "Part cost is $partCost";
	$orderVal = $partCost * $qty;

	$userId = $_SESSION['user_id'] ?? null;
	$addTrans = $dbLink->query("INSERT INTO `trans` (`partid`,`type`,`date`,`postref`,`qty`,`old`,`new`,`user_id`) VALUES ('$partid','ORDER','$now','$refnum','$qty','$partQty','$partQty','$userId')");

	$addOrder = $dbLink->query("INSERT INTO `orders` (`partid`,`qty`,`ordval`,`orderdate`,`orderref`) VALUES ('$partid','$qty','$orderVal','$now','$refnum')");