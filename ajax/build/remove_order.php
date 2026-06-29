<?php
/**
 * Remove (cancel) a packaging / FP order from the Packaging page.
 *
 * Inventory rule (mirrors finalize.php, which is the ONLY place raw materials
 * are deducted): materials are removed from stock only when an order is
 * finalized/built, which sets intransit.buildqty > 0. So:
 *   - buildqty > 0  → some units were already packaged; add their raw materials
 *                     back to stock and leave a note on each part.
 *   - buildqty == 0 → nothing was deducted; just delete the order, no inventory
 *                     change.
 *
 * finalize.php deducts $pickQty per build line (it does not multiply by the BOM
 * per-unit qty), so to keep inventory exactly balanced we restore the same way:
 * buildqty per build line.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to package/build.');

$db      = db_connect();
$now     = date("Y-m-d H:i:s");
$userId  = $_SESSION['user_id'] ?? null;
$orderId = (int)($_POST['orderid'] ?? 0);

if (!$orderId) { echo 'error: missing order'; exit; }

$order = $db->query("SELECT * FROM `intransit` WHERE `id` = " . $orderId)->fetch();
if (!$order) { echo 'error: order not found'; exit; }

$prodId   = (int)$order['prodid'];
$buildqty = (int)$order['buildqty'];
$whId     = (int)($order['warehouse_id'] ?? 0);
$prodRow  = $db->query("SELECT `name` FROM `products` WHERE `id` = " . $prodId)->fetch();
$prodName = $prodRow['name'] ?? ('Product #' . $prodId);

try {
	$db->beginTransaction();

	// Restore raw materials ONLY if some were already deducted (built).
	if ($buildqty > 0) {
		$lines = $db->query("SELECT b.partid, pa.partno, pa.`desc`
		                     FROM `build` b JOIN `parts` pa ON pa.id = b.partid
		                     WHERE b.prodid = " . $prodId)->fetchAll();

		$restoreByPart = [];
		foreach ($lines as $bl) {
			$pid = (int)$bl['partid'];
			$restoreByPart[$pid] = ($restoreByPart[$pid] ?? 0) + $buildqty; // mirror finalize
		}

		foreach ($restoreByPart as $pid => $restore) {
			if ($restore <= 0) continue;
			$current = $whId
				? wh_get_qty($db, $pid, $whId)
				: (int)$db->query("SELECT `qoh` FROM `parts` WHERE `id` = $pid")->fetch()['qoh'];
			$new = $current + $restore;

			if ($whId) { wh_adjust($db, $pid, $whId, $restore); }
			else       { $db->exec("UPDATE `parts` SET `qoh` = `qoh` + $restore WHERE `id` = $pid"); }

			$note = "Inventory added back — cancelled build FP order: {$prodName} ({$buildqty} unit" . ($buildqty == 1 ? '' : 's') . ")";
			$stmt = $db->prepare("INSERT INTO `trans` (`partid`,`type`,`adjreason`,`date`,`qty`,`old`,`new`,`user_id`,`warehouse_id`)
			                      VALUES (?, 'BUILDUNDO', ?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute([$pid, $note, $now, $restore, $current, $new, $userId, $whId ?: null]);
		}
	}

	// Open (unfinalized) picks for this order never touched inventory — drop them.
	$db->prepare("DELETE FROM `picks` WHERE `ordid` = ? AND `closedate` = '0000-00-00 00:00:00'")->execute([$orderId]);

	// Delete the packaging order itself.
	$db->prepare("DELETE FROM `intransit` WHERE `id` = ?")->execute([$orderId]);

	$db->commit();
	echo 'ok';
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500);
	echo 'error: ' . $e->getMessage();
}
