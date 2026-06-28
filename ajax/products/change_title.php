<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to perform this action.');

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$update = $dbLink->query("UPDATE `products` SET `name` = '$name' WHERE `id` = '$prodid'");