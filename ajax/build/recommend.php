<?php
/**
 * Recommend a packaging order to fulfill demand until a chosen date.
 *
 * Demand (units, per animator product) =
 *    projected retail sales  (last year's SAME calendar window — includes online,
 *                             POS/tradeshow, and completed/paid draft orders)
 *  + committed draft orders  (current OPEN drafts >= 10 units = active wholesale POs)
 *
 * Supply already covered =
 *    Shopify finished-product stock  +  in-pipeline (intransit orders not yet received)
 *
 * Recommended build = max(0, demand - covered). We also check current raw-material
 * stock to show how many can actually be built now and the limiting part.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_once(__DIR__."/../../includes/planning.php"); // column_exists()
require_login();
header('Content-Type: application/json');

if (!has_access('build')) { http_response_code(403); echo json_encode(['error' => 'No access to Packaging.']); exit; }
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify is not connected — connect it on the Integrations page to project demand.']); exit; }

$db    = db_connect();
$today = date('Y-m-d');
$until = trim($_POST['until'] ?? '');
$ts    = strtotime($until);
if (!$ts || date('Y-m-d', $ts) <= $today) { echo json_encode(['error' => 'Pick a target date in the future.']); exit; }
$until = date('Y-m-d', $ts);
$windowDays = max(1, (int)round(($ts - strtotime($today)) / 86400));

// Prior-year equivalent window (same calendar span, one year earlier).
$lyStart = date('Y-m-d', strtotime('-1 year', strtotime($today)));
$lyEnd   = date('Y-m-d', strtotime('-1 year', $ts));

// ── Demand + supply signals from Shopify ──
$sales = shopify_sales_in_range($lyStart, $lyEnd);
if (!empty($sales['error'])) { echo json_encode(['error' => 'Shopify sales lookup failed: ' . $sales['error']]); exit; }
$retailBySku = $sales['by_sku'] ?? [];

$drafts = shopify_open_draft_demand(10);
$draftBySku = empty($drafts['error']) ? ($drafts['by_sku'] ?? []) : [];
$draftErr   = $drafts['error'] ?? null;

$variants = shopify_fetch_variants();
$shopSkus = $variants['skus'] ?? [];

// ── Products with a BOM = animators we build ──
$hasSku   = column_exists($db, 'products', 'shopify_sku');
$cols     = "id, name" . ($hasSku ? ", shopify_sku" : "");
$products = $db->query("SELECT $cols FROM products ORDER BY name ASC")->fetchAll();

$bomByProd = [];
foreach ($db->query("SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.qoh
                     FROM build b JOIN parts p ON p.id = b.partid") as $bl) {
	$bomByProd[$bl['prodid']][] = $bl;
}

// In-pipeline finished product per product = intransit orders not yet received.
$pipelineByProd = [];
foreach ($db->query("SELECT prodid, SUM(qty) AS v FROM intransit
                     WHERE recdate = '0000-00-00 00:00:00' GROUP BY prodid") as $r) {
	$pipelineByProd[$r['prodid']] = max(0, (int)$r['v']);
}

$rows = [];
foreach ($products as $p) {
	$bom = $bomByProd[$p['id']] ?? [];
	if (empty($bom)) continue;                       // only animators (have raw-material BOM)
	$sku = $hasSku ? trim((string)($p['shopify_sku'] ?? '')) : '';

	$retail   = $sku !== '' ? (int)($retailBySku[$sku] ?? 0) : 0;
	$draft    = $sku !== '' ? (int)($draftBySku[$sku]  ?? 0) : 0;
	$demand   = $retail + $draft;

	$fpStock  = ($sku !== '' && isset($shopSkus[$sku])) ? (int)$shopSkus[$sku]['qty'] : 0;
	$pipeline = (int)($pipelineByProd[$p['id']] ?? 0);
	$covered  = $fpStock + $pipeline;

	$recommend = max(0, $demand - $covered);

	// Buildable-now from raw materials (and the limiting part).
	$buildable = null; $limitPart = null;
	foreach ($bom as $b) {
		$need = (int)$b['qty']; if ($need <= 0) continue;
		$can  = intdiv((int)$b['qoh'], $need);
		if ($buildable === null || $can < $buildable) { $buildable = $can; $limitPart = $b['partno']; }
	}
	$buildable = $buildable === null ? 0 : $buildable;

	if ($demand <= 0 && $recommend <= 0) continue;   // nothing interesting to show

	$rows[] = [
		'product'    => $p['name'],
		'sku'        => $sku,
		'retail'     => $retail,
		'draft'      => $draft,
		'demand'     => $demand,
		'fp_stock'   => $fpStock,
		'pipeline'   => $pipeline,
		'recommend'  => $recommend,
		'buildable'  => $buildable,
		'limit_part' => $limitPart,
		'short'      => max(0, $recommend - $buildable),  // can't build this many from raw stock now
	];
}

// Most-needed first.
usort($rows, fn($a, $b) => $b['recommend'] <=> $a['recommend']);

echo json_encode([
	'error'  => null,
	'meta'   => [
		'today'        => $today,
		'until'        => $until,
		'window_days'  => $windowDays,
		'prior_window' => "$lyStart to $lyEnd",
		'draft_orders' => $drafts['orders'] ?? 0,
		'draft_error'  => $draftErr,
		'channels'     => $sales['by_channel'] ?? [],
	],
	'rows'   => $rows,
]);
