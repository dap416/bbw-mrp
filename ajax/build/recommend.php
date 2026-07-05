<?php
/**
 * Recommend a build/order to cover demand until a chosen date. Returns per-product
 * demand COMPONENTS (online, per-tradeshow, committed, on-hand, pipeline, buildable);
 * the browser (js/recommend.js) computes Demand and Build under a filter state and
 * the AI chat (recommend_adjust.php) adjusts it.
 *
 * Demand (per animator) = last year's SAME-window sales (online + POS/tradeshows)
 *   + Shopify committed units (already sold, awaiting fulfillment).
 * Recommended build = max(0, demand - finished-product on-hand - in-pipeline).
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_once(__DIR__."/../../includes/planning.php"); // column_exists()
require_login();
header('Content-Type: application/json');

if (!has_access('build') && !has_access('orders')) { http_response_code(403); echo json_encode(['error' => 'No access to Packaging or Orders.']); exit; }
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify is not connected — connect it on the Integrations page to project demand.']); exit; }

$db    = db_connect();
$today = date('Y-m-d');
$until = trim($_POST['until'] ?? '');
$ts    = strtotime($until);
if (!$ts || date('Y-m-d', $ts) <= $today) { echo json_encode(['error' => 'Pick a target date in the future.']); exit; }

$whId = (int)($_POST['warehouse_id'] ?? 0);

// Per-product demand components — the browser computes Demand/Build under a filter
// state (exclude shows / online-only / drop committed) and the AI chat adjusts it.
$data = fp_demand_components($db, date('Y-m-d', $ts), $whId);
if (!empty($data['error'])) { echo json_encode(['error' => 'Shopify lookup failed: ' . $data['error']]); exit; }

echo json_encode(['error' => null, 'meta' => $data['meta'], 'rows' => $data['rows']]);
