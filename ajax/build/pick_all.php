<?php
/**
 * "Pick All" on the Packaging page — add the full remaining qty of one or more
 * packaging orders to the open pick list in a single push.
 *
 * POST orderid=<id>        → just that order
 * POST warehouse_id=<id>   → every pending order in that warehouse
 *
 * Qty added per order = (qty - buildqty) - qty already on the OPEN pick list,
 * so pressing it twice (or after picking part by hand) never over-picks.
 * No inventory change — materials only leave stock at finalize.php.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to package/build.');

$db      = db_connect();
$now     = date("Y-m-d H:i:s");
$orderId = (int)($_POST['orderid'] ?? 0);
$whId    = (int)($_POST['warehouse_id'] ?? 0);

if (!$orderId && !$whId) { echo 'error: missing order or warehouse'; exit; }

$where = $orderId ? "i.id = $orderId" : "i.warehouse_id = $whId";
$orders = $db->query("
	SELECT i.id, i.prodid, i.qty - i.buildqty -
	       COALESCE((SELECT SUM(pk.qty) FROM picks pk
	                 WHERE pk.ordid = i.id AND pk.closedate = '0000-00-00 00:00:00'), 0) AS topick
	FROM intransit i
	WHERE $where
	  AND i.orddate <> '0000-00-00 00:00:00'
	  AND i.qty > i.buildqty
")->fetchAll();

$ins   = $db->prepare("INSERT INTO `picks` (`ordid`,`prodid`,`qty`,`opendate`) VALUES (?,?,?,?)");
$added = 0;
try {
	$db->beginTransaction();
	foreach ($orders as $o) {
		$q = (int)$o['topick'];
		if ($q < 1) continue;
		$ins->execute([(int)$o['id'], (int)$o['prodid'], $q, $now]);
		$added++;
	}
	$db->commit();
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	echo 'error: ' . $e->getMessage();
	exit;
}

echo 'ok:' . $added;
