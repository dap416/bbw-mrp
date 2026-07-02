<?php
/**
 * Archive a completed order. Allowed only when the order is paid in full
 * (paidamt >= ordval) AND something has been received (recqty > 0). If the
 * received quantity differs from the quantity ordered, the overage/shortage is
 * recorded (an order note + a part-history trans row) so the discrepancy is
 * captured before the order leaves the active list.
 */
require_once(__DIR__."/../includes/fns.php");
require_login();
require_can(can_manage_orders(), 'Only master admins can archive orders.');

$db     = db_connect();
$record = (int)($_POST['record'] ?? 0);
$now    = date("Y-m-d H:i:s");

if ($record <= 0) { echo 'error'; exit; }

$order = $db->query("SELECT * FROM `orders` WHERE `id` = $record")->fetch();
if (!$order) { echo 'error'; exit; }

if ((int)($order['archived'] ?? 0) === 1) { echo 'already'; exit; }

$ordVal = (float)$order['ordval'];
$paid   = (float)$order['paidamt'];
$qty    = (int)$order['qty'];
$recQty = (int)$order['recqty'];

// Re-validate the gate server-side.
if ($recQty <= 0)               { echo 'Order has no received stock yet.'; exit; }
if ($paid + 0.005 < $ordVal)    { echo 'Order is not paid in full.'; exit; }

$diff = $recQty - $qty; // + overage, - shortage, 0 exact

// Record any discrepancy as an order note (so it shows in the order history).
if ($diff !== 0) {
    $word = $diff > 0 ? 'OVERAGE' : 'SHORTAGE';
    $msg  = "Archived: received {$recQty} of {$qty} ordered — {$word} of " . abs($diff) . " unit(s).";
    try {
        $db->prepare("INSERT INTO `notes` (`date`,`ordid`,`note`) VALUES (?,?,?)")
           ->execute([$now, $record, $msg]);
    } catch (Throwable $e) {}

    // Also log it in the part's transaction history (no inventory change — the
    // received amounts already adjusted on-hand; this is a record of the gap).
    try {
        $part = $db->query("SELECT `partid` FROM `orders` WHERE `id` = $record")->fetch();
        $pid  = (int)($part['partid'] ?? 0);
        if ($pid > 0) {
            $qoh = (int)($db->query("SELECT `qoh` FROM `parts` WHERE `id` = $pid")->fetch()['qoh'] ?? 0);
            $db->prepare("INSERT INTO `trans` (`partid`,`type`,`date`,`ordid`,`postref`,`qty`,`old`,`new`,`user_id`)
                          VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$pid, 'ARCHIVE', $now, $record, $word, $diff, $qoh, $qoh, $_SESSION['user_id'] ?? null]);
        }
    } catch (Throwable $e) {}
}

// Mark received-complete (in case it was an over/short receipt that never hit
// the exact-match postdate) and archive.
$set = "`archived` = 1, `archived_date` = '$now'";
if (($order['postdate'] ?? '0000-00-00 00:00:00') === '0000-00-00 00:00:00') {
    $set .= ", `postdate` = '$now'";
}
$db->exec("UPDATE `orders` SET $set WHERE `id` = $record");

echo 'ok';
