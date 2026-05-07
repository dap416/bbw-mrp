<?php

	require_once(__DIR__."/../../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$update = $dbLink->query("UPDATE `products` SET `name` = '$name' WHERE `id` = '$prodid'");