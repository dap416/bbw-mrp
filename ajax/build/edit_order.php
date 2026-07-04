<?php
/**
 * Edit the ordered quantity of a finished-product stock (packaging) order from
 * the Packaging page. A reason is REQUIRED and every change is audit-logged in
 * intransit_edits. Cannot reduce the order below what has already been built
 * (buildqty) — undo those builds first.
 * Input: orderid, qty, reason.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to edit packaging orders.');

$db      = db_connect();
$orderId = (int)($_POST['orderid'] ?? 0);
$qty     = (int)($_POST['qty'] ?? 0);
$reason  = trim((string)($_POST['reason'] ?? ''));

if (!$orderId)      { echo 'error: missing order'; exit; }
if ($qty < 1)       { echo 'error: quantity must be at least 1'; exit; }
if ($reason === '') { echo 'error: a reason is required for this change'; exit; }

$order = $db->query("SELECT `id`, `prodid`, `qty`, `buildqty` FROM `intransit` WHERE `id` = " . $orderId)->fetch();
if (!$order) { echo 'error: order not found'; exit; }

$built  = (int)$order['buildqty'];
$oldQty = (int)$order['qty'];
if ($qty < $built) { echo 'error: cannot set below the ' . $built . ' already built — undo those builds first'; exit; }
if ($qty === $oldQty) { echo 'ok'; exit; }   // nothing changed

try {
	$db->prepare("UPDATE `intransit` SET `qty` = ? WHERE `id` = ?")->execute([$qty, $orderId]);
	intransit_log_edit($db, $orderId, (int)$order['prodid'], 'qty', $oldQty, $qty, $reason);
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'error: ' . $e->getMessage();
}
