<?php
/**
 * Remove a packaged (built, not-yet-received) FP product from the Ready to Ship
 * list. Like undo_build it restores the deducted raw materials, but instead of
 * sending the order back to pending it DELETES the order entirely.
 *
 * Grouped by product (mirrors the Ready to Ship list), so it affects every
 * built-not-received intransit row for this product in the given warehouse.
 * Inventory restore mirrors finalize.php (buildqty per build line) — see
 * remove_order.php for the rationale.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to package/build.');

$db     = db_connect();
$now    = date("Y-m-d H:i:s");
$userId = $_SESSION['user_id'] ?? null;
$prodId = (int)($_POST['prodid'] ?? 0);
$whId   = (int)($_POST['warehouse_id'] ?? 0);

if (!$prodId) { echo 'error: missing product'; exit; }

$whCond = $whId ? " AND warehouse_id = $whId" : "";
$rows = $db->query("SELECT id, buildqty FROM `intransit`
                    WHERE prodid = $prodId AND buildqty > 0
                      AND recdate = '0000-00-00 00:00:00' $whCond")->fetchAll();
if (empty($rows)) { echo 'nothing_to_remove'; exit; }

$totalBuilt = 0;
foreach ($rows as $r) $totalBuilt += (int)$r['buildqty'];
$ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));

$prodRow  = $db->query("SELECT `name` FROM `products` WHERE `id` = " . $prodId)->fetch();
$prodName = $prodRow['name'] ?? ('Product #' . $prodId);

try {
	$db->beginTransaction();

	if ($totalBuilt > 0) {
		$lines = $db->query("SELECT b.partid FROM `build` b WHERE b.prodid = " . $prodId)->fetchAll();
		$restoreByPart = [];
		foreach ($lines as $bl) {
			$pid = (int)$bl['partid'];
			$restoreByPart[$pid] = ($restoreByPart[$pid] ?? 0) + $totalBuilt; // mirror finalize
		}
		foreach ($restoreByPart as $pid => $restore) {
			if ($restore <= 0) continue;
			$current = $whId
				? wh_get_qty($db, $pid, $whId)
				: (int)$db->query("SELECT `qoh` FROM `parts` WHERE `id` = $pid")->fetch()['qoh'];
			$new = $current + $restore;

			if ($whId) { wh_adjust($db, $pid, $whId, $restore); }
			else       { $db->exec("UPDATE `parts` SET `qoh` = `qoh` + $restore WHERE `id` = $pid"); }

			$note = "Inventory added back — removed packaged FP order: {$prodName} ({$totalBuilt} unit" . ($totalBuilt == 1 ? '' : 's') . ")";
			$stmt = $db->prepare("INSERT INTO `trans` (`partid`,`type`,`adjreason`,`date`,`qty`,`old`,`new`,`user_id`,`warehouse_id`)
			                      VALUES (?, 'BUILDUNDO', ?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute([$pid, $note, $now, $restore, $current, $new, $userId, $whId ?: null]);
		}
	}

	// Drop any open picks for these orders, then delete the orders.
	$db->exec("DELETE FROM `picks` WHERE `ordid` IN ($ids) AND `closedate` = '0000-00-00 00:00:00'");
	$db->exec("DELETE FROM `intransit` WHERE `id` IN ($ids)");

	$db->commit();
	echo 'ok';
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500);
	echo 'error: ' . $e->getMessage();
}
