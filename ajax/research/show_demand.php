<?php
/**
 * Per-show prior-year POS units across the three seasons, so the owner can see how much
 * demand each tradeshow contributes before deciding whether to include it. Cached per
 * show + window (shared with the demand engine). Read-only. Research access.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/planning.php");
require_once(__DIR__."/../../includes/shopify.php");
require_login();
if (!has_access('research')) { http_response_code(403); echo json_encode(['error' => 'No access.']); exit; }
header('Content-Type: application/json');

$db = db_connect();
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify not connected.', 'shows' => []]); exit; }

$ttl = inventory_cache_ttl($db);
$y = (int)date('Y');
// Prior-year windows matching the season dataset (Jul–Sep, Oct–Dec, Jan–Mar).
$windows = [
	[date('Y-m-d', strtotime("-1 year", strtotime("$y-07-01"))),     date('Y-m-d', strtotime("-1 year", strtotime("$y-09-30")))],
	[date('Y-m-d', strtotime("-1 year", strtotime("$y-10-01"))),     date('Y-m-d', strtotime("-1 year", strtotime("$y-12-31")))],
	[date('Y-m-d', strtotime("-1 year", strtotime(($y+1)."-01-01"))), date('Y-m-d', strtotime("-1 year", strtotime(($y+1)."-03-31")))],
];
$excluded = demand_excluded_shows($db);

$out = [];
foreach (tradeshow_locations() as $show) {
	$total = 0;
	foreach ($windows as $w) {
		$ss = show_sales_by_sku($db, $show, $w[0], $w[1], $ttl);
		$total += array_sum($ss);
	}
	$out[] = ['name' => $show['name'], 'units' => (int)$total, 'excluded' => in_array($show['name'], $excluded, true)];
}
usort($out, fn($a, $b) => $b['units'] <=> $a['units']);

echo json_encode(['shows' => $out]);
