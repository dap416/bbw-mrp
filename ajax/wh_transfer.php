<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();

	$db     = db_connect();
	$now    = date("Y-m-d H:i:s");
	$userId = $_SESSION['user_id'] ?? null;

	$partId = (int)($_POST['partid']  ?? 0);
	$fromId = (int)($_POST['from_id'] ?? 0);
	$toId   = (int)($_POST['to_id']   ?? 0);
	$qty    = (int)($_POST['qty']     ?? 0);

	if (!$partId || !$fromId || !$toId || $qty <= 0 || $fromId === $toId) {
		echo 'Invalid parameters'; exit;
	}

	// Verify from-warehouse has enough qty
	$fromQty = wh_get_qty($db, $partId, $fromId);
	if ($qty > $fromQty) {
		echo 'Insufficient quantity in source warehouse (' . $fromQty . ' available)'; exit;
	}

	$toQty = wh_get_qty($db, $partId, $toId);

	$fromName = $db->query("SELECT name FROM warehouses WHERE id = $fromId")->fetch()['name'] ?? "Warehouse $fromId";
	$toName   = $db->query("SELECT name FROM warehouses WHERE id = $toId")->fetch()['name']   ?? "Warehouse $toId";

	// Deduct from source — MINUS transaction
	$fromNew = $fromQty - $qty;
	wh_set($db, $partId, $fromId, $fromNew);
	$stmt = $db->prepare("INSERT INTO trans (partid, type, adjreason, date, qty, `old`, `new`, user_id, warehouse_id)
	                      VALUES (?, 'ADJUST', ?, ?, ?, ?, ?, ?, ?)");
	$stmt->execute([$partId, "Transfer to $toName", $now, -$qty, $fromQty, $fromNew, $userId, $fromId]);

	// Add to destination — PLUS transaction
	$toNew = $toQty + $qty;
	wh_set($db, $partId, $toId, $toNew);
	$stmt->execute([$partId, "Transfer from $fromName", $now, $qty, $toQty, $toNew, $userId, $toId]);

	// Resync parts.qoh (wh_set already does this, but do one final sync to be sure)
	$db->exec("UPDATE parts SET qoh = (
	    SELECT COALESCE(SUM(qty), 0) FROM part_warehouse_qty WHERE part_id = $partId
	) WHERE id = $partId");

	echo 'ok';
