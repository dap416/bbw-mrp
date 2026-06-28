<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to perform this action.');
	require_login();

	$db  = db_connect();
	$id  = (int)$_POST['id'];
	$qty = (int)$_POST['qty'];

	$stmt = $db->prepare("UPDATE `intransit` SET `qty` = ? WHERE `id` = ? AND `orddate` = '0000-00-00 00:00:00'");
	$stmt->execute([$qty, $id]);

	echo 'ok';
