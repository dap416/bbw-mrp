<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	$db          = db_connect();
	$now         = date("Y-m-d H:i:s");
	$userId      = $_SESSION['user_id'] ?? null;
	$prodId      = (int)($_POST['prodid']       ?? 0);
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);

	if (!$prodId) { echo 'error'; exit; }

	// 1. Find all intransit records for this product that are built but not yet received
	$whCondition = $warehouseId ? "AND warehouse_id = $warehouseId" : "";
	$intransitRows = $db->query("
		SELECT id, buildqty FROM intransit
		WHERE prodid = $prodId
		  AND buildqty > 0
		  AND recdate = '0000-00-00 00:00:00'
		  $whCondition
	")->fetchAll();

	if (empty($intransitRows)) { echo 'nothing_to_undo'; exit; }

	$intransitIds = implode(',', array_column($intransitRows, 'id'));

	// 2. Find the pickids that closed these intransit orders
	$pickRows = $db->query("
		SELECT DISTINCT pickid FROM picks
		WHERE ordid IN ($intransitIds)
		  AND pickid != ''
		  AND closedate != '0000-00-00 00:00:00'
	")->fetchAll();

	if (!empty($pickRows)) {
		$pickIds = array_map(fn($r) => "'".$r['pickid']."'", $pickRows);
		$pickIdList = implode(',', $pickIds);

		// 3. Find all BUILD trans records for those picks and reverse each one
		$buildTrans = $db->query("
			SELECT * FROM trans
			WHERE type = 'BUILD' AND buildid IN ($pickIdList)
		")->fetchAll();

		foreach ($buildTrans as $t) {
			$partId      = (int)$t['partid'];
			$deductedQty = abs((int)$t['qty']); // was negative — we add it back
			$whId        = (int)($t['warehouse_id'] ?? $warehouseId);

			// Add qty back to warehouse
			$currentQty = $whId ? wh_get_qty($db, $partId, $whId) : (int)$db->query("SELECT qoh FROM parts WHERE id = $partId")->fetch()['qoh'];
			$newQty     = $currentQty + $deductedQty;

			if ($whId) {
				wh_adjust($db, $partId, $whId, $deductedQty);
			} else {
				$db->exec("UPDATE parts SET qoh = qoh + $deductedQty WHERE id = $partId");
			}

			// BUILDUNDO transaction
			$stmt = $db->prepare("INSERT INTO trans (partid, type, adjreason, date, buildid, qty, `old`, `new`, user_id, warehouse_id)
			                      VALUES (?, 'BUILDUNDO', ?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute([$partId, "Build Reversed ({$t['buildid']})", $now, $t['buildid'], $deductedQty, $currentQty, $newQty, $userId, $whId ?: null]);
		}
	}

	// 4. Reset intransit records — clear buildqty and builddate
	$db->exec("UPDATE intransit SET buildqty = 0, builddate = '0000-00-00 00:00:00' WHERE id IN ($intransitIds)");

	echo 'ok';
