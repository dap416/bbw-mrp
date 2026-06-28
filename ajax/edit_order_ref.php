<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to edit orders.');

	$db     = db_connect();
	$record = (int)$_POST['record'];
	$ref    = trim($_POST['editref'] ?? '');

	$stmt = $db->prepare("UPDATE `orders` SET `orderref` = ? WHERE `id` = ?");
	$stmt->execute([$ref, $record]);

	echo 'ok';
