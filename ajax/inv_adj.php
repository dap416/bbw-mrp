<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('inventory'), 'You do not have permission to edit inventory.');

	$db = db_connect();
	$now = date("Y-m-d H:i:s");

	$partId      = (int)($_POST['record']       ?? 0);
	$newQty      = (int)($_POST['qty']          ?? 0);
	$reason      = $_POST['reason']             ?? '';
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);

	if (!$partId) { echo 'error'; exit; }

	// Get current warehouse-specific qty (or total if no warehouse selected)
	if ($warehouseId) {
		$currentQty = wh_get_qty($db, $partId, $warehouseId);
	} else {
		$currentQty = (int)$db->query("SELECT `qoh` FROM `parts` WHERE `id` = '$partId'")->fetch()['qoh'];
	}

	$diff = $newQty - $currentQty;

	// Apply adjustment
	if ($warehouseId) {
		wh_set($db, $partId, $warehouseId, $newQty);
	} else {
		$db->exec("UPDATE `parts` SET `qoh` = '$newQty' WHERE `id` = '$partId'");
	}

	// Transaction
	$userId = $_SESSION['user_id'] ?? null;
	$stmt = $db->prepare("INSERT INTO `trans` (`partid`,`type`,`adjreason`,`date`,`qty`,`old`,`new`,`user_id`,`warehouse_id`)
	                      VALUES (?,?,?,?,?,?,?,?,?)");
	$stmt->execute([$partId,'ADJUST',$reason,$now,$diff,$currentQty,$newQty,$userId,$warehouseId?:null]);
