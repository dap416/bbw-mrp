<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('build'), 'You do not have permission to package/build.');

	$db = db_connect();
	$now = date("Y-m-d H:i:s");
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);

	// Random pick ID
	$pickId = substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 10);

	$pendingPicks = $db->query("SELECT * FROM `picks` WHERE `closedate` = '0000-00-00 00:00:00'")->fetchAll();

	$compArray = [];

	foreach ($pendingPicks as $line) {
		$prodId   = $line['prodid'];
		$pickQty  = (int)$line['qty'];
		$orderId  = $line['ordid'];

		$buildLines = $db->query("SELECT b.*, p.partno, p.`desc` FROM `build` b JOIN `parts` p ON p.id = b.partid WHERE b.prodid = '$prodId' ORDER BY b.partid ASC")->fetchAll();

		foreach ($buildLines as $bl) {
			$partId = $bl['partid'];
			$compArray[$partId]['partid'] = $partId;
			$compArray[$partId]['partno'] = $bl['partno'];
			$compArray[$partId]['desc']   = $bl['desc'];
			$compArray[$partId]['qty']    = ($compArray[$partId]['qty'] ?? 0) + $pickQty;
		}

		// Update intransit
		$db->exec("UPDATE `intransit` SET `buildqty` = (`buildqty` + $pickQty), `builddate` = '$now' WHERE `id` = '$orderId'");
	}

	// Close picks
	$db->exec("UPDATE `picks` SET `closedate` = '$now', `pickid` = '$pickId' WHERE `pickid` = ''");

	// Deduct parts from inventory
	$userId = $_SESSION['user_id'] ?? null;
	foreach ($compArray as $part) {
		$partId  = $part['partid'];
		$partQty = (int)$part['qty'];

		// Get current qty (warehouse-specific or total)
		if ($warehouseId) {
			$currentQoh = wh_get_qty($db, $partId, $warehouseId);
		} else {
			$currentQoh = (int)$db->query("SELECT `qoh` FROM `parts` WHERE `id` = '$partId'")->fetch()['qoh'];
		}
		$newQoh = $currentQoh - $partQty;

		// Deduct
		if ($warehouseId) {
			wh_adjust($db, $partId, $warehouseId, -$partQty);
		} else {
			$db->exec("UPDATE `parts` SET `qoh` = (`qoh` - $partQty) WHERE `id` = '$partId'");
		}

		// Transaction
		$transQty = $partQty * -1;
		$stmt = $db->prepare("INSERT INTO `trans` (`partid`,`type`,`date`,`buildid`,`qty`,`old`,`new`,`user_id`,`warehouse_id`)
		                      VALUES (?,?,?,?,?,?,?,?,?)");
		$stmt->execute([$partId,'BUILD',$now,$pickId,$transQty,$currentQoh,$newQoh,$userId,$warehouseId?:null]);
	}
