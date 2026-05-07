<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();

	$db  = db_connect();
	$id  = (int)$_POST['id'];
	$eta = $_POST['eta'] ?? '';

	// Validate date format
	$d = DateTime::createFromFormat('Y-m-d', $eta);
	if (!$d || $d->format('Y-m-d') !== $eta) {
		echo 'error';
		exit;
	}

	$stmt = $db->prepare("UPDATE `orders` SET `eta` = ? WHERE `id` = ?");
	$stmt->execute([$eta, $id]);

	echo 'ok';
