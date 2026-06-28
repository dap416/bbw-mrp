<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to perform this action.');

	$db = db_connect();
	$now = date("Y-m-d H:i:s");

	$prodid      = (int)($_POST['prodid']       ?? 0);
	$qty         = (int)($_POST['qty']          ?? 0);
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);

	if (!$prodid || $qty <= 0) exit;

	$stmt = $db->prepare("INSERT INTO `intransit` (`prodid`,`qty`,`adddate`,`warehouse_id`) VALUES (?,?,?,?)");
	$stmt->execute([$prodid, $qty, $now, $warehouseId ?: null]);
