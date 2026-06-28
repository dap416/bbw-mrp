<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to delete parts.');
	$dbLink = db_connect();

	$record = $_POST['record'];

	// Check if this part is used in any product build
	$buildCheck = $dbLink->query("SELECT COUNT(*) AS `cnt` FROM `build` WHERE `partid` = '$record'")->fetch();
	if ($buildCheck['cnt'] > 0) {
		echo 'in_build';
		exit;
	}

	$dbLink->query("DELETE FROM `trans`  WHERE `partid` = '$record'");
	$dbLink->query("DELETE FROM `orders` WHERE `partid` = '$record'");
	$dbLink->query("DELETE FROM `parts`  WHERE `id`     = '$record'");

	echo 'ok';
