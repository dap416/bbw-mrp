<?php
/** Per-show (Shopify location) sales for a date range — exact "what to bring"
 *  pull lists. Attributes by location_id (no read_locations scope needed). */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/shopify.php");
require_login();
header('Content-Type: application/json');

if (!has_access('build') && !has_access('research') && !has_access('inventory')) { http_response_code(403); echo json_encode(['error' => 'No access.']); exit; }
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify is not connected.']); exit; }

$from = trim($_POST['from'] ?? '');
$to   = trim($_POST['to'] ?? '');
if (!strtotime($from) || !strtotime($to)) { echo json_encode(['error' => 'Pick a valid date range.']); exit; }
$from = date('Y-m-d', strtotime($from));
$to   = date('Y-m-d', strtotime($to));

$shows = [];
foreach (tradeshow_locations() as $loc) {
	$r = shopify_show_sales($loc['id'], $from, $to);
	$items = [];
	if (empty($r['error'])) {
		foreach (($r['by_sku'] ?? []) as $sku => $units) {
			$items[] = ['sku' => $sku, 'title' => $r['titles'][$sku] ?? '', 'units' => $units];
		}
	}
	$byDate = [];
	foreach (($r['by_date'] ?? []) as $d => $u) $byDate[] = ['date' => $d, 'units' => $u];

	$shows[] = [
		'name'        => $loc['name'],
		'error'       => $r['error'] ?? null,
		'total_units' => $r['total_units'] ?? 0,
		'revenue'     => round($r['revenue'] ?? 0, 2),
		'orders'      => $r['orders'] ?? 0,
		'items'       => $items,
		'by_date'     => $byDate,
	];
}

echo json_encode(['error' => null, 'from' => $from, 'to' => $to, 'shows' => $shows]);
