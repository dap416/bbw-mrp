<?php
/** Live Shopify finished-product inventory by warehouse/category. Refreshed on
 *  each call (no cache) so it's current whenever the page is opened. */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/shopify.php");
require_login();
header('Content-Type: application/json');

if (!has_access('inventory') && !has_access('build')) { http_response_code(403); echo json_encode(['error' => 'No access.']); exit; }
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify is not connected — set it up on the Integrations page.']); exit; }

$db    = db_connect();
$fresh = !empty($_POST['fresh']);              // manual Refresh forces a live pull
$ttl   = $fresh ? 0 : inventory_cache_ttl($db); // otherwise reuse if pulled within a few hours

$r   = shopify_cache_remember($db, 'inv_by_location', $ttl, fn() => shopify_inventory_by_location());
$out = $r['data'];
$out['cached']     = $r['cached'];
$out['updated_at'] = $r['updated_at'];
$out['stale']      = $r['stale'];
echo json_encode($out);
