<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('inventory'), 'You do not have permission to submit a physical count.');

	$db          = db_connect();
	$now         = date("Y-m-d H:i:s");
	$userId      = $_SESSION['user_id'] ?? null;
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
	$counts      = $_POST['counts'] ?? [];   // [part_id => counted_qty]

	if (!$warehouseId || empty($counts)) { echo json_encode(['error' => 'Invalid submission.']); exit; }

	$adjusted = 0;

	foreach ($counts as $partId => $countedQty) {
		$partId     = (int)$partId;
		$countedQty = (int)$countedQty;

		$currentQty = wh_get_qty($db, $partId, $warehouseId);

		if ($countedQty === $currentQty) continue; // no change needed

		// Apply new warehouse qty
		wh_set($db, $partId, $warehouseId, $countedQty);

		$diff = $countedQty - $currentQty;

		// Log ADJUST transaction
		$stmt = $db->prepare("INSERT INTO trans (partid, type, adjreason, date, qty, `old`, `new`, user_id, warehouse_id)
		                      VALUES (?, 'ADJUST', 'Physical Count Completed', ?, ?, ?, ?, ?, ?)");
		$stmt->execute([$partId, $now, $diff, $currentQty, $countedQty, $userId, $warehouseId]);

		$adjusted++;
	}

	echo json_encode(['ok' => true, 'adjusted' => $adjusted]);
