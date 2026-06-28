<?php
/**
 * CSV export of archived orders (read-only). Same access gate as the
 * Archived Orders page. Streams a CSV download; no HTML layout.
 */
require_once(__DIR__."/../includes/fns.php");
require_login();
if (!has_access('orders')) deny_access();

$db = db_connect();

// Bail cleanly if archiving hasn't been set up yet.
$hasArchive = false;
try { $hasArchive = $db->query("SHOW COLUMNS FROM `orders` LIKE 'archived'")->rowCount() > 0; }
catch (Throwable $e) {}
if (!$hasArchive) { http_response_code(400); exit('Order archiving is not set up yet.'); }

$partsById = [];
foreach ($db->query("SELECT * FROM `parts`") as $r) { $partsById[$r['id']] = $r; }

$rows = $db->query("SELECT * FROM `orders` WHERE `archived` = 1 ORDER BY `archived_date` DESC, `id` DESC");

$filename = 'archived_orders_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents correctly

fputcsv($out, ['Part #', 'Description', 'Order Ref', 'Ordered', 'Received', 'Discrepancy', 'Order Date', 'Archived Date', 'Order Value', 'Paid']);

while ($o = $rows->fetch()) {
	$p    = $partsById[$o['partid']] ?? [];
	$qty  = (int)$o['qty'];
	$rec  = (int)$o['recqty'];
	$diff = $rec - $qty;
	$disc = $diff === 0 ? 'Exact' : ($diff > 0 ? 'Overage +' . $diff : 'Shortage ' . $diff);
	$od   = (!empty($o['orderdate'])    && $o['orderdate']    !== '0000-00-00 00:00:00') ? date('Y-m-d', strtotime($o['orderdate']))    : '';
	$ad   = (!empty($o['archived_date']) && $o['archived_date'] !== '0000-00-00 00:00:00') ? date('Y-m-d', strtotime($o['archived_date'])) : '';

	fputcsv($out, [
		$p['partno'] ?? '',
		$p['desc']   ?? '',
		$o['orderref'],
		$qty,
		$rec,
		$disc,
		$od,
		$ad,
		$o['ordval'],
		$o['paidamt'],
	]);
}

fclose($out);
exit;
