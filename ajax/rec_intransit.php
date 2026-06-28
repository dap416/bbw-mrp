<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('build'), 'You do not have permission to package/build.');

	$dbLink = $mysqli = db_connect();

	$now = date("Y-m-d H:i:s");

	$recInTransit = $dbLink->query("UPDATE `intransit` SET `recdate` = '$now' WHERE `builddate` <> '0000-00-00 00:00:00' AND `recdate` = '0000-00-00 00:00:00'");