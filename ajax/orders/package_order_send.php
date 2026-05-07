<?php

	require_once(__DIR__."/../../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	$now = date("Y-m-d H:i:s");

	extract($_POST);

	$sendOrder = $dbLink->query("UPDATE `intransit` SET `orddate` = '$now' WHERE `orddate` = '0000-00-00 00:00:00'");