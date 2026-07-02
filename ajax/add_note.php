<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('orders') || can_do('orders.receive'), 'You do not have permission to add order notes.');

	$db = db_connect();

	$record = (int)($_POST['record'] ?? 0);
	$note   = trim((string)($_POST['note'] ?? ''));
	$now    = date("Y-m-d H:i:s");

	if ($record <= 0 || $note === '') { echo ''; exit; }

	$db->prepare("INSERT INTO `notes` (`date`,`ordid`,`note`) VALUES (?,?,?)")->execute([$now, $record, $note]);
	echo date("m/d/y", strtotime($now)) . " - " . htmlspecialchars($note);
