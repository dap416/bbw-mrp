<?php
/** Discard a staged physical-count batch without changing inventory. */
	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('inventory'), 'You do not have permission to discard a physical count.');

	header('Content-Type: application/json');

	$db      = db_connect();
	$batchId = (int)($_POST['batch_id'] ?? 0);
	if ($batchId <= 0) { echo json_encode(['error' => 'Missing batch id.']); exit; }

	phys_inv_ensure_tables($db);

	$bs = $db->prepare("SELECT status FROM phys_inv_batches WHERE id = ?");
	$bs->execute([$batchId]);
	$row = $bs->fetch();
	if (!$row) { echo json_encode(['error' => 'Count report not found.']); exit; }
	if ($row['status'] === 'applied') { echo json_encode(['error' => 'Already applied — cannot discard.']); exit; }

	$db->prepare("UPDATE phys_inv_batches SET status = 'discarded' WHERE id = ?")->execute([$batchId]);
	echo json_encode(['ok' => true]);
