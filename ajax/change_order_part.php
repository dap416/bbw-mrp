<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_manage_orders(), 'Only master admins can edit orders. You can still receive shipments and add notes.');

	$db = db_connect();

	$record  = (int)($_POST['record']  ?? 0);
	$newPart = (int)($_POST['newpart'] ?? 0);
	if ($record <= 0 || $newPart <= 0) { echo 'error'; exit; }

	$order = $db->query("SELECT * FROM `orders` WHERE `id` = $record")->fetch();
	if (!$order) { echo 'error'; exit; }

	$oldPart = (int)$order['partid'];
	if ($oldPart === $newPart) { echo 'same'; exit; }

	$oldP = $db->query("SELECT `partno`,`qoh` FROM `parts` WHERE `id` = $oldPart")->fetch();
	$newP = $db->query("SELECT `partno`,`cost`,`qoh` FROM `parts` WHERE `id` = $newPart")->fetch();
	if (!$newP) { echo 'error'; exit; }

	$qty    = (int)$order['qty'];
	$recqty = (int)$order['recqty'];
	$ref    = (string)$order['orderref'];
	$now    = date('Y-m-d H:i:s');
	$userId = $_SESSION['user_id'] ?? null;
	$oldNo  = $oldP['partno'] ?? ('#'.$oldPart);
	$newNo  = $newP['partno'];
	$newVal = (float)$newP['cost'] * $qty;

	try {
		$db->beginTransaction();

		// 1. Re-point the order to the correct part + recompute its value.
		$db->prepare("UPDATE `orders` SET `partid` = ?, `ordval` = ? WHERE `id` = ?")
		   ->execute([$newPart, $newVal, $record]);

		// 2. If stock was already received, move it from the old part to the new
		//    part for each receipt (per its warehouse), keeping on-hand correct.
		if ($recqty > 0) {
			foreach ($db->query("SELECT `qty`,`warehouse_id` FROM `ordpost` WHERE `ordid` = $record") as $op) {
				$q  = (int)$op['qty'];
				$wh = (int)($op['warehouse_id'] ?? 0);
				if ($q <= 0) continue;
				if ($wh) {
					wh_adjust($db, $oldPart, $wh, -$q);
					wh_adjust($db, $newPart, $wh,  $q);
				} else {
					$db->exec("UPDATE `parts` SET `qoh` = `qoh` - $q WHERE `id` = $oldPart");
					$db->exec("UPDATE `parts` SET `qoh` = `qoh` + $q WHERE `id` = $newPart");
				}
			}
			// Move the receipt/adjust history rows to the new part too.
			$db->prepare("UPDATE `trans` SET `partid` = ?,
			                `adjreason` = TRIM(CONCAT(COALESCE(`adjreason`,''), ' [order part corrected ', ?, '→', ?, ']'))
			              WHERE `ordid` = ? AND `type` IN ('POST','POSTUNDO','ADJORD')")
			   ->execute([$newPart, $oldNo, $newNo, $record]);
		}

		// 3. Mark the original ORDER entry on the OLD part as corrected.
		$db->prepare("UPDATE `trans` SET `adjreason` = ?
		              WHERE `partid` = ? AND `type` = 'ORDER' AND `postref` = ?
		              ORDER BY `id` DESC LIMIT 1")
		   ->execute(['Corrected → moved to '.$newNo.' (Order '.$ref.')', $oldPart, $ref]);

		// 4. Record a fresh ORDER entry on the NEW (correct) part.
		$newQoh = (int)$newP['qoh'];
		$db->prepare("INSERT INTO `trans` (`partid`,`type`,`adjreason`,`date`,`ordid`,`postref`,`qty`,`old`,`new`,`user_id`)
		              VALUES (?,?,?,?,?,?,?,?,?,?)")
		   ->execute([$newPart, 'ORDER', 'Corrected from '.$oldNo.' (Order '.$ref.')', $now, $record, $ref, $qty, $newQoh, $newQoh, $userId]);

		$db->commit();
		echo 'ok';
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		http_response_code(500);
		echo 'Change failed: ' . $e->getMessage();
	}
