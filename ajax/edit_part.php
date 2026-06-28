<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to edit products/parts.');

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$lead_time    = isset($lead_time) ? (int)$lead_time : 45;
	$manufacturer = isset($manufacturer) ? (int)$manufacturer : 0;
	$editPart = $dbLink->query("UPDATE `parts` SET `mfgpartno` = '$sku', `partno` = '$sku', `desc` = '$desc', `cost` = '$cost', `imoq` = '$imoq', `lead_time` = '$lead_time', `manufacturer` = '$manufacturer' WHERE `id` = '$record'");