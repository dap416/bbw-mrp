<?php
/**
 * Preview a COMBINED build/pack order from one or more selected tradeshows.
 * The Tradeshow Planner sums the chosen shows' per-SKU units client-side and
 * posts them here. For each SKU that maps to a finished product with a bill of
 * materials we work out:
 *   demand = combined units those shows sold last year  (min 10)
 *   bring  = pull from finished product already on hand  = min(demand, fp_on_hand)
 *   build  = make the rest                               = demand - bring
 * so BRING + BUILD always equals DEMAND. Only BUILD becomes a packaging order.
 * "buildable" = how many of the build we can make now from raw stock in the
 * chosen warehouse. Orders are created via ajax/build/create_orders.php.
 *
 * Input: skus = JSON { "SKU": units, ... }, warehouse_id.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to create build/pack orders.');
header('Content-Type: application/json');

$db   = db_connect();
$whId = (int)($_POST['warehouse_id'] ?? 0);
$skus = json_decode($_POST['skus'] ?? '{}', true);
if (!is_array($skus) || empty($skus)) { echo json_encode(['ok' => false, 'error' => 'No shows selected.']); exit; }

$DEMAND_MIN = 10; // never plan for fewer than this per product

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

// Finished-product ON HAND per SKU (oregon + rest = total made & available).
// Cached Shopify call, shared with the build planner; falls back to 0 on error.
$fpBySkuLc = []; $fpError = null;
try {
	if (shopify_is_configured()) {
		$fpLoc = shopify_cache_remember($db, 'rec_fp', inventory_cache_ttl($db), fn() => shopify_fp_by_location())['data'];
		foreach (($fpLoc['skus'] ?? []) as $k => $v) {
			$fpBySkuLc[strtolower(trim((string)$k))] = (int)($v['oregon'] ?? 0) + (int)($v['rest'] ?? 0);
		}
	} else { $fpError = 'Shopify is not connected — assuming 0 finished product on hand.'; }
} catch (Throwable $e) { $fpError = 'Could not read finished-product stock — assuming 0 on hand.'; }

$rows = []; $unmapped = [];
foreach ($want as $sku => $units) {
	$pr = $prodBySku[strtolower($sku)] ?? null;
	if (!$pr || empty($bomProds[(int)$pr['id']])) { $unmapped[] = ['sku' => $sku, 'units' => (int)$units]; continue; }
	$pid = (int)$pr['id'];

	$demand  = max($DEMAND_MIN, (int)$units);               // combined sold last year, min 10
	$onHand  = max(0, (int)($fpBySkuLc[strtolower($sku)] ?? 0));
	$bring   = min($demand, $onHand);                       // pull from finished stock
	$build   = $demand - $bring;                            // make the rest (bring + build = demand)

	// Buildable now = min over the BOM of floor(raw on-hand / per-unit need),
	// using the chosen warehouse's raw stock (mirrors fp_build_plan / build.php).
	$buildable = null; $limitPart = null;
	foreach ($db->query("SELECT b.qty AS need, b.partid, p.partno, p.qoh FROM build b JOIN parts p ON p.id = b.partid WHERE b.prodid = $pid") as $bl) {
		$need   = max(1, (int)$bl['need']);
		$onhandRaw = $whId ? (int)wh_get_qty($db, (int)$bl['partid'], $whId) : (int)$bl['qoh'];
		$can    = intdiv(max(0, $onhandRaw), $need);
		if ($buildable === null || $can < $buildable) { $buildable = $can; $limitPart = $bl['partno']; }
	}
	$buildable = $buildable === null ? 0 : $buildable;

	$rows[] = [
		'prodid'     => $pid,
		'product'    => $pr['name'],
		'sku'        => $sku,
		'demand'     => $demand,
		'on_hand'    => $onHand,
		'bring'      => $bring,
		'build'      => $build,
		'buildable'  => $buildable,
		'short'      => max(0, $build - $buildable),
		'limit_part' => $limitPart,
	];
}

usort($rows, fn($a, $b) => $b['build'] <=> $a['build']);
echo json_encode(['ok' => true, 'rows' => $rows, 'unmapped' => $unmapped, 'fp_note' => $fpError]);
