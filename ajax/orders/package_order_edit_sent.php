<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to perform this action.');
	require_login();

	$db  = db_connect();
	$id  = (int)$_POST['id'];
	$qty = (int)$_POST['qty'];

	if (!$id || $qty <= 0) { echo 'error'; exit; }

	// Only allow editing sent orders that have not yet been built
	$stmt = $db->prepare("UPDATE `intransit` SET `qty` = ? WHERE `id` = ? AND `orddate` != '0000-00-00 00:00:00' AND `buildqty` = 0");
	$stmt->execute([$qty, $id]);

	echo 'ok';
