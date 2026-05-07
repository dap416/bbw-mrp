<?php

	require_once(__DIR__."/../../includes/fns.php");
	$dbLink = db_connect();

	$record = $_POST['record'];
	$access = json_decode($_POST['access'], true);

	$orders        = (int)$access['access_orders'];
	$inventory     = (int)$access['access_inventory'];
	$products      = (int)$access['access_products'];
	$build         = (int)$access['access_build'];
	$manufacturers = (int)$access['access_manufacturers'];

	$dbLink->query("UPDATE `users` SET `access_orders` = '$orders', `access_inventory` = '$inventory', `access_products` = '$products', `access_build` = '$build', `access_manufacturers` = '$manufacturers' WHERE `id` = '$record'");
	echo 'ok';
