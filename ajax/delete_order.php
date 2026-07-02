<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_manage_orders(), 'Only master admins can delete orders.');

	$dbLink = db_connect();

	$record = $_POST['record'];

	$check = $dbLink->query("SELECT COUNT(*) AS `cnt` FROM `ordpost` WHERE `ordid` = '$record'")->fetch();

	if($check['cnt'] > 0) {
		echo 'blocked';
	} else {
		$order = $dbLink->query("SELECT `partid` FROM `orders` WHERE `id` = '$record'")->fetch();
		$partId = $order['partid'];
		$currQoh = $dbLink->query("SELECT `qoh` FROM `parts` WHERE `id` = '$partId'")->fetch()['qoh'];
		$now = date("Y-m-d H:i:s");
		$userId = $_SESSION['user_id'] ?? null;
		$dbLink->query("INSERT INTO `trans` (`partid`,`type`,`date`,`ordid`,`old`,`new`,`user_id`) VALUES ('$partId','ORDERDELETE','$now','$record','$currQoh','$currQoh','$userId')");
		$dbLink->query("DELETE FROM `orders` WHERE `id` = '$record'");
		echo 'ok';
	}
