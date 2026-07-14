<?php
/** Orders for one Shopify customer — loaded when the agent picks who is calling. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_login();
if (!has_access('call_center')) { http_response_code(403); echo json_encode(['error' => 'No access to the Call Center.']); exit; }
header('Content-Type: application/json');

$cid = trim((string)($_POST['customer_id'] ?? ''));
if ($cid === '') { echo json_encode(['ok' => true, 'orders' => []]); exit; }

try {
	$r = shopify_customer_orders($cid);
	if (!empty($r['error'])) { echo json_encode(['ok' => true, 'orders' => [], 'note' => $r['error']]); exit; }
	echo json_encode(['ok' => true, 'orders' => $r['orders']]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Could not load orders: ' . $e->getMessage()]);
}
