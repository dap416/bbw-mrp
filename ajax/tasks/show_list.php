<?php
/** Tradeshow names for the task "Tradeshow build & pack" picker. Lazy-loaded
 *  when that task type is selected so the (possibly slow) Shopify location
 *  discovery isn't run on every Task List page load. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_login();
header('Content-Type: application/json');

if (!shopify_is_configured()) {
	echo json_encode(['ok' => true, 'shows' => [], 'note' => 'Shopify is not connected — connect it on Integrations to pick shows.']);
	exit;
}

$shows = [];
try {
	foreach (tradeshow_locations() as $loc) {
		$name = trim((string)($loc['name'] ?? ''));
		if ($name !== '') $shows[] = ['name' => $name];
	}
} catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'shows' => []]);
	exit;
}

echo json_encode(['ok' => true, 'shows' => $shows]);
