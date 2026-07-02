<?php
/**
 * Preview a COMBINED build/pack order from one or more selected tradeshows.
 * The Tradeshow Planner sums the chosen shows' per-SKU units client-side and
 * posts them here; we map each SKU to a finished product that has a bill of
 * materials, and compute how many can be built right now from raw stock in the
 * chosen warehouse. Returns rows for a confirm-before-create preview; the actual
 * orders are created via ajax/build/create_orders.php.
 *
 * Input: skus = JSON { "SKU": units, ... }, warehouse_id.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to create build/pack orders.');
header('Content-Type: application/json');

$db   = db_connect();
$whId = (int)($_POST['warehouse_id'] ?? 0);
$skus = json_decode($_POST['skus'] ?? '{}', true);
if (!is_array($skus) || empty($skus)) { echo json_encode(['ok' => false, 'error' => 'No shows selected.']); exit; }

// Normalize incoming sku => units.
$want = [];
foreach ($skus as $sku => $u) {
	$sku = trim((string)$sku); $u = (int)$u;
	if ($sku === '' || $u <= 0) continue;
	$want[$sku] = ($want[$sku] ?? 0) + $u;
}
if (empty($want)) { echo json_encode(['ok' => false, 'error' => 'The selected shows have no unit sales to build from.']); exit; }

// Finished-product SKU → product (only products that carry a build BOM).
$prodBySku = [];
try {
	foreach ($db->query("SELECT pr.id, pr.name, pr.shopify_sku FROM products pr WHERE pr.shopify_sku IS NOT NULL AND pr.shopify_sku <> ''") as $pr) {
		$prodBySku[strtolower(trim($pr['shopify_sku']))] = $pr;
	}
} catch (Throwable $e) { /* no shopify_sku column → everything is unmapped */ }

$bomProds = [];
foreach ($db->query("SELECT DISTINCT prodid FROM build") as $b) { $bomProds[(int)$b['prodid']] = true; }

$rows = []; $unmapped = [];
foreach ($want as $sku => $units) {
	$pr = $prodBySku[strtolower($sku)] ?? null;
	if (!$pr || empty($bomProds[(int)$pr['id']])) { $unmapped[] = ['sku' => $sku, 'units' => (int)$units]; continue; }
	$pid = (int)$pr['id'];

	// Buildable now = min over the BOM of floor(raw on-hand / per-unit need),
	// using the chosen warehouse's raw stock (mirrors fp_build_plan / build.php).
	$buildable = null; $limitPart = null;
	foreach ($db->query("SELECT b.qty AS need, b.partid, p.partno, p.qoh FROM build b JOIN parts p ON p.id = b.partid WHERE b.prodid = $pid") as $bl) {
		$need   = max(1, (int)$bl['need']);
		$onhand = $whId ? (int)wh_get_qty($db, (int)$bl['partid'], $whId) : (int)$bl['qoh'];
		$can    = intdiv(max(0, $onhand), $need);
		if ($buildable === null || $can < $buildable) { $buildable = $can; $limitPart = $bl['partno']; }
	}
	$buildable = $buildable === null ? 0 : $buildable;

	$rows[] = [
		'prodid'     => $pid,
		'product'    => $pr['name'],
		'sku'        => $sku,
		'bring'      => (int)$units,
		'buildable'  => $buildable,
		'short'      => max(0, (int)$units - $buildable),
		'limit_part' => $limitPart,
	];
}

usort($rows, fn($a, $b) => $b['bring'] <=> $a['bring']);
echo json_encode(['ok' => true, 'rows' => $rows, 'unmapped' => $unmapped]);
