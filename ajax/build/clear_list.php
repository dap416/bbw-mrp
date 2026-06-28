<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('build'), 'You do not have permission to package/build.');

	$dbLink = $mysqli = db_connect();

	$clear = $dbLink->query("DELETE FROM `picks` WHERE `closedate` = '0000-00-00 00:00:00'");
