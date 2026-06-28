<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('build'), 'You do not have permission to package/build.');

	$dbLink = db_connect();

	extract($_POST);

	$now = date("Y-m-d H:i:s");

	$insert = $dbLink->query("INSERT INTO `picks` (`ordid`,`prodid`,`qty`,`opendate`) VALUES ('$orderid','$prodid','$qty','$now')");

	

	

	