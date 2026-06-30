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
	$ids = isset($loc['ids']) ? $loc['ids'] : (isset($loc['id']) ? [$loc['id']] : []);

	// A show can span multiple Shopify location ids (e.g. a new one each year);
	// merge them so the show is complete regardless of which year's id holds the data.
	$bySku = []; $titles = []; $byDate = []; $total = 0; $rev = 0.0; $orders = 0; $err = null;
	foreach ($ids as $id) {
		$r = shopify_show_sales($id, $from, $to);
		if (!empty($r['error'])) { $err = $r['error']; continue; }
		foreach (($r['by_sku'] ?? []) as $sku => $u) $bySku[$sku] = ($bySku[$sku] ?? 0) + $u;
		foreach (($r['titles'] ?? []) as $sku => $t) if (empty($titles[$sku])) $titles[$sku] = $t;
		foreach (($r['by_date'] ?? []) as $d => $u) $byDate[$d] = ($byDate[$d] ?? 0) + $u;
		$total  += $r['total_units'] ?? 0;
		$rev    += $r['revenue'] ?? 0;
		$orders += $r['orders'] ?? 0;
	}

	// Only surface shows that actually had sales in this window (keeps it focused).
	if ($total <= 0 && !$err) continue;

	arsort($bySku);
	ksort($byDate);
	$dates = array_keys($byDate);
	$items = [];
	foreach ($bySku as $sku => $u) $items[] = ['sku' => $sku, 'title' => $titles[$sku] ?? '', 'units' => $u];
	$byDateArr = [];
	foreach ($byDate as $d => $u) $byDateArr[] = ['date' => $d, 'units' => $u];

	$shows[] = [
		'name'        => $loc['name'],
		'error'       => $err,
		'total_units' => $total,
		'revenue'     => round($rev, 2),
		'orders'      => $orders,
		'start'       => $dates ? reset($dates) : '9999-99-99',
		'end'         => $dates ? end($dates) : '',
		'items'       => $items,
		'by_date'     => $byDateArr,
	];
}

// Chronological by show date (earliest first).
usort($shows, fn($a, $b) => strcmp($a['start'], $b['start']) ?: ($b['total_units'] <=> $a['total_units']));

echo json_encode(['error' => null, 'from' => $from, 'to' => $to, 'shows' => $shows]);
