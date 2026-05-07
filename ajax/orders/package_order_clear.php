<?php

	require_once(__DIR__."/../../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	$clear = $dbLink->query("DELETE FROM `intransit` WHERE `orddate` = '0000-00-00 00:00:00'");