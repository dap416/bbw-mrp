<?php

	require_once(__DIR__."/../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	extract($_POST);

	$add = $dbLink->query("INSERT INTO `products` (`name`) VALUES ('$name')");

