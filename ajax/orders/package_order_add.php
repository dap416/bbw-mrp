<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to perform this action.');

	$db = db_connect();
	$now = date("Y-m-d H:i:s");

	$prodid      = (int)($_POST['prodid']       ?? 0);
	$qty         = (int)($_POST['qty']          ?? 0);
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
	$source      = trim((string)($_POST['source'] ?? ''));
	$until       = trim((string)($_POST['until']  ?? ''));
	$untilVal    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) ? $until : null;

	if (!$prodid || $qty <= 0) exit;

	$sourceNote = intransit_source_note($source, $untilVal);
	intransit_source_ensure($db);

	$stmt = $db->prepare("INSERT INTO `intransit` (`prodid`,`qty`,`adddate`,`warehouse_id`,`source_note`,`source_until`) VALUES (?,?,?,?,?,?)");
	$stmt->execute([$prodid, $qty, $now, $warehouseId ?: null, $sourceNote, $untilVal]);
