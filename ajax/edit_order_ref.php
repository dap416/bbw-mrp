<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_manage_orders(), 'Only master admins can edit orders. You can still receive shipments and add notes.');

	$db     = db_connect();
	$record = (int)$_POST['record'];
	$ref    = trim($_POST['editref'] ?? '');

	$stmt = $db->prepare("UPDATE `orders` SET `orderref` = ? WHERE `id` = ?");
	$stmt->execute([$ref, $record]);

	echo 'ok';
