<?php
/**
 * Call Center lookup: "who is calling?"
 * One box, one endpoint. If the term looks like an order number we resolve the order
 * (and the customer on it); otherwise we search customers by name / email / phone.
 * Either way the agent gets back everything needed to fill the ticket without retyping.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_login();
if (!has_access('call_center')) { http_response_code(403); echo json_encode(['error' => 'No access to the Call Center.']); exit; }
header('Content-Type: application/json');

$term = trim((string)($_POST['term'] ?? ''));
if ($term === '') { echo json_encode(['ok' => true, 'customers' => [], 'orders' => []]); exit; }

if (!shopify_is_configured()) {
	echo json_encode(['ok' => true, 'customers' => [], 'orders' => [],
		'note' => 'Shopify is not connected, so customer and order lookup is unavailable. You can still write the ticket by hand.']);
	exit;
}

try {
	// A bare number (or #number) is almost always an order number being read down the phone.
	$looksLikeOrder = (bool)preg_match('/^#?\d{3,}$/', $term);

	$orders = []; $customers = []; $note = null;

	if ($looksLikeOrder) {
		$r = shopify_order_lookup($term);
		if (!empty($r['error'])) $note = $r['error']; else $orders = $r['orders'];
	}

	// Always try the customer search too (a numeric term could be a phone number), and
	// fall back to it when the order number found nothing.
	$c = shopify_customer_search($term);
	if (!empty($c['error'])) { if (!$note) $note = $c['error']; }
	else $customers = $c['customers'];

	if (!$orders && !$customers && !$note) {
		$note = 'No customer or order matched "' . $term . '". You can still fill the ticket in by hand below.';
	}

	echo json_encode(['ok' => true, 'customers' => $customers, 'orders' => $orders, 'note' => $note]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Lookup failed: ' . $e->getMessage()]);
}
