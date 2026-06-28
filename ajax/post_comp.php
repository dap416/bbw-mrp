<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to perform this action.');

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$now = date("Y-m-d H:i:s");

	$partInfo = $dbLink->query("SELECT `cost`,`qoh` FROM `parts` WHERE `id` = '$partid'")->fetch();
	$partQty = $partInfo['qoh'];

	$newQoh = $partQty + $qty;

	$userId = $_SESSION['user_id'] ?? null;
	$add = $dbLink->query("INSERT INTO `trans` (`partid`,`type`,`date`,`postref`,`qty`,`old`,`new`,`user_id`) VALUES ('$partid','POST','$now','$refnum','$qty','$partQty','$newQoh','$userId')");

	$adjustQOH = adjust_qty($partid,'post',$qty);

