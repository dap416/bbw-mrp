<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to perform this action.');

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$remove = $dbLink->query("DELETE FROM `build` WHERE `id` = '$buildid'");
