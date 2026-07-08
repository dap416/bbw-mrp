<?php
/** Per-show (Shopify location) sales for a date range — exact "what to bring"
 *  pull lists. Attributes by location_id (no read_locations scope needed). */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/shopify.php");
require_login();
header('Content-Type: application/json');

if (!has_access('build') && !has_access('research') && !has_access('inventory')) { http_response_code(403); echo json_encode(['error' => 'No access.']); exit; }
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify is not connected.']); exit; }

// Animator SKUs (products with a bill of materials) — used to bucket the sold items.
$db = db_connect();
$animatorSkus = [];
try {
	foreach ($db->query("SELECT DISTINCT p.shopify_sku AS sku FROM products p JOIN build b ON b.prodid = p.id WHERE p.shopify_sku IS NOT NULL AND p.shopify_sku <> ''") as $r) {
		$animatorSkus[strtoupper(trim($r['sku']))] = true;
	}
} catch (Throwable $e) {}

/** Bucket a sold item into the owner's categories — Shopify productType first, then
 *  animator-SKU match, then keywords on the title/SKU. */
function ts_category($ptype, $sku, $title, $animatorSkus) {
	$pt = strtolower(trim((string)$ptype));
	$s  = strtolower($sku . ' ' . $title);
	if ($pt !== '') {
		if (strpos($pt, 'wing') !== false) return 'WINGZ';
		if (strpos($pt, 'batter') !== false) return 'Batteries';
		if (strpos($pt, 'hat') !== false || strpos($pt, 'cap') !== false || strpos($pt, 'apparel') !== false) return 'Hats';
		if (strpos($pt, 'bag') !== false || strpos($pt, 'case') !== false) return 'Bags & Cases';
		if (strpos($pt, 'accessor') !== false) return 'Accessories';
		if (strpos($pt, 'animator') !== false || strpos($pt, 'decoy') !== false || strpos($pt, 'motion') !== false) return 'Animators';
	}
	if ($sku !== '' && isset($animatorSkus[strtoupper(trim($sku))])) return 'Animators';
	if (preg_match('/wing/', $s)) return 'WINGZ';
	if (preg_match('/batter|\b(aa|aaa|9v)\b/', $s)) return 'Batteries';
	if (preg_match('/\b(hat|cap|beanie|trucker|snapback|visor)\b/', $s)) return 'Hats';
	if (preg_match('/\b(bag|case|clam|tote|sack|sleeve)\b/', $s)) return 'Bags & Cases';
	if (preg_match('/\b(accessor|remote|charger|cord|stake|mount|clip|decal|sticker|lanyard|patch|koozie|glove|strap|hook|magnet)\b/', $s)) return 'Accessories';
	if ($pt !== '') return ucwords($pt);
	return 'Other';
}

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
	$byItem = []; $byDate = []; $total = 0; $rev = 0.0; $orders = 0; $err = null;
	foreach ($ids as $id) {
		$r = shopify_show_sales($id, $from, $to);
		if (!empty($r['error'])) { $err = $r['error']; continue; }
		// EVERYTHING sold at the show (incl. items with no SKU; clearance excluded upstream).
		foreach (($r['by_item'] ?? []) as $it) {
			$k = $it['sku'] !== '' ? $it['sku'] : ('~' . strtolower($it['title']));
			if (!isset($byItem[$k])) $byItem[$k] = ['sku' => $it['sku'], 'title' => $it['title'], 'units' => 0, 'ptype' => $it['ptype'] ?? ''];
			if (empty($byItem[$k]['ptype']) && !empty($it['ptype'])) $byItem[$k]['ptype'] = $it['ptype'];
			$byItem[$k]['units'] += (int)$it['units'];
		}
		foreach (($r['by_date'] ?? []) as $d => $u) $byDate[$d] = ($byDate[$d] ?? 0) + $u;
		$total  += $r['total_units'] ?? 0;
		$rev    += $r['revenue'] ?? 0;
		$orders += $r['orders'] ?? 0;
	}

	// Only surface shows that actually had sales in this window (keeps it focused).
	if ($total <= 0 && !$err) continue;

	ksort($byDate);
	uasort($byItem, fn($a, $b) => $b['units'] <=> $a['units']);
	$dates = array_keys($byDate);
	$items = array_values($byItem);
	foreach ($items as &$it) { $it['category'] = ts_category($it['ptype'] ?? '', $it['sku'], $it['title'], $animatorSkus); }
	unset($it);
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
