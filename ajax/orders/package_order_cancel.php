<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	$db = db_connect();
	$id = (int)$_POST['id'];

	if (!$id) { echo 'error'; exit; }

	// Only allow cancelling sent orders that have not yet been built
	$stmt = $db->prepare("DELETE FROM `intransit` WHERE `id` = ? AND `orddate` != '0000-00-00 00:00:00' AND `buildqty` = 0");
	$stmt->execute([$id]);

	echo 'ok';
