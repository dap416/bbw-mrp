<?php
/**
 * Set (or clear) the "Build By" due date on a packaging / FP order.
 * Input: orderid, date (YYYY-MM-DD, or empty to clear).
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to edit packaging orders.');

$db      = db_connect();
$orderId = (int)($_POST['orderid'] ?? 0);
$date    = trim($_POST['date'] ?? '');

if (!$orderId) { echo 'error: missing order'; exit; }

$due = null;
if ($date !== '') {
	$d = DateTime::createFromFormat('Y-m-d', $date);
	if (!$d || $d->format('Y-m-d') !== $date) { echo 'error: invalid date'; exit; }
	$due = $date;
}

try {
	$stmt = $db->prepare("UPDATE `intransit` SET `duedate` = ? WHERE `id` = ?");
	$stmt->execute([$due, $orderId]);
	echo 'ok';
} catch (Throwable $e) {
	http_response_code(500);
	echo 'error: ' . $e->getMessage();
}
