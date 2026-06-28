<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to perform this action.');

	$dbLink = $mysqli = db_connect();

	$clear = $dbLink->query("DELETE FROM `intransit` WHERE `orddate` = '0000-00-00 00:00:00'");