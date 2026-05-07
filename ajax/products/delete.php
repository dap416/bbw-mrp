<?php

	require_once(__DIR__."/../../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$delete = $dbLink->query("DELETE FROM `products` WHERE `id` = '$prodid'");

	$remove = $dbLink->query("DELETE FROM `build` WHERE `prodid` = '$prodid'");
