<?php
/**
 * Apply a previously-staged physical-count batch to inventory.
 * This is the step that ACTUALLY changes warehouse quantities.
 */
	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('inventory'), 'You do not have permission to confirm a physical count.');

	header('Content-Type: application/json');

	$db      = db_connect();
	$now     = date("Y-m-d H:i:s");
	$userId  = $_SESSION['user_id'] ?? null;
	$userName= $_SESSION['user_name'] ?? '';
	$batchId = (int)($_POST['batch_id'] ?? 0);

	if ($batchId <= 0) { echo json_encode(['error' => 'Missing batch id.']); exit; }

	phys_inv_ensure_tables($db);

	$bs = $db->prepare("SELECT * FROM phys_inv_batches WHERE id = ?");
	$bs->execute([$batchId]);
	$batch = $bs->fetch();
	if (!$batch)                         { echo json_encode(['error' => 'Count report not found.']); exit; }
	if ($batch['status'] === 'applied')  { echo json_encode(['error' => 'This count was already confirmed and applied.']); exit; }
	if ($batch['status'] === 'discarded'){ echo json_encode(['error' => 'This count was discarded.']); exit; }

	$warehouseId = (int)$batch['warehouse_id'];

	$items = $db->prepare("SELECT * FROM phys_inv_batch_items WHERE batch_id = ?");
	$items->execute([$batchId]);

	$adjusted = 0;
	foreach ($items->fetchAll() as $it) {
		$partId     = (int)$it['part_id'];
		$countedQty = (int)$it['counted'];

		// Re-read the CURRENT qty at apply time (it may have changed since the
		// count was staged) so the ADJUST transaction is accurate.
		$currentQty = wh_get_qty($db, $partId, $warehouseId);
		if ($countedQty === $currentQty) continue;

		wh_set($db, $partId, $warehouseId, $countedQty);
		$diff = $countedQty - $currentQty;

		$stmt = $db->prepare("INSERT INTO trans (partid, type, adjreason, date, qty, `old`, `new`, user_id, warehouse_id)
		                      VALUES (?, 'ADJUST', 'Physical Count Completed', ?, ?, ?, ?, ?, ?)");
		$stmt->execute([$partId, $now, $diff, $currentQty, $countedQty, $userId, $warehouseId]);
		$adjusted++;
	}

	$us = $db->prepare("UPDATE phys_inv_batches
	                    SET status = 'applied', applied_at = ?, applied_by = ?, applied_by_name = ?, adjusted_parts = ?
	                    WHERE id = ?");
	$us->execute([$now, $userId, $userName, $adjusted, $batchId]);

	echo json_encode(['ok' => true, 'adjusted' => $adjusted]);
