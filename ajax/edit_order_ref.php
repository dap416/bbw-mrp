<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();

	$db     = db_connect();
	$record = (int)$_POST['record'];
	$ref    = trim($_POST['editref'] ?? '');

	$stmt = $db->prepare("UPDATE `orders` SET `orderref` = ? WHERE `id` = ?");
	$stmt->execute([$ref, $record]);

	echo 'ok';
