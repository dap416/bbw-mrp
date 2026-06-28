<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_do('orders.receive'), 'You do not have permission to receive orders.');

	$db = db_connect();

	$record      = $_POST['record']       ?? '';
	$recamt      = (int)($_POST['recamt'] ?? 0);
	$recref      = $_POST['recref']       ?? '';
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
	$now         = date("Y-m-d H:i:s");

	if (!$record || $recamt <= 0) { echo 'error'; exit; }

	$orderInfo = $db->query("SELECT * FROM `orders` WHERE `id` = '$record'")->fetch();
	if (!$orderInfo) { echo 'error'; exit; }

	$orderPart = $orderInfo['partid'];
	$ordRec    = (int)$orderInfo['recqty'];
	$totalOrd  = (int)$orderInfo['qty'];

	$partQty   = $db->query("SELECT `qoh` FROM `parts` WHERE `id` = '$orderPart'")->fetch()['qoh'];
	$newQoh    = $partQty + $recamt;

	$userId = $_SESSION['user_id'] ?? null;

	// Transaction record
	$stmt = $db->prepare("INSERT INTO `trans` (`partid`,`type`,`date`,`ordid`,`postref`,`qty`,`old`,`new`,`user_id`,`warehouse_id`)
	                      VALUES (?,?,?,?,?,?,?,?,?,?)");
	$stmt->execute([$orderPart,'POST',$now,$record,$recref,$recamt,$partQty,$newQoh,$userId,$warehouseId?:null]);

	// Ordpost record
	$stmt = $db->prepare("INSERT INTO `ordpost` (`date`,`ordid`,`qty`,`ref`,`warehouse_id`) VALUES (?,?,?,?,?)");
	$stmt->execute([$now,$record,$recamt,$recref,$warehouseId?:null]);

	// Update order recqty
	$newRecQty = $ordRec + $recamt;
	$db->exec("UPDATE `orders` SET `recqty` = '$newRecQty' WHERE `id` = '$record'");
	if ($totalOrd == $newRecQty) {
		$db->exec("UPDATE `orders` SET `postdate` = '$now' WHERE `id` = '$record'");
	}

	// Adjust warehouse + resync parts.qoh
	if ($warehouseId) {
		wh_adjust($db, $orderPart, $warehouseId, $recamt);
	} else {
		adjust_qty($orderPart, 'post', $recamt);
	}
