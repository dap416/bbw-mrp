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

if (!has_access('build') && !has_access('orders')) { http_response_code(403); echo json_encode(['error' => 'No access to Packaging or Orders.']); exit; }
if (!shopify_is_configured()) { echo json_encode(['error' => 'Shopify is not connected — connect it on the Integrations page to project demand.']); exit; }

$db    = db_connect();
$today = date('Y-m-d');
$until = trim($_POST['until'] ?? '');
$ts    = strtotime($until);
if (!$ts || date('Y-m-d', $ts) <= $today) { echo json_encode(['error' => 'Pick a target date in the future.']); exit; }

$whId = (int)($_POST['warehouse_id'] ?? 0);

// Shared computation (also used by the dashboard briefing).
$plan = fp_build_plan($db, date('Y-m-d', $ts), $whId);
if (!empty($plan['error'])) { echo json_encode(['error' => 'Shopify sales lookup failed: ' . $plan['error']]); exit; }

echo json_encode(['error' => null, 'meta' => $plan['meta'], 'rows' => $plan['rows']]);
