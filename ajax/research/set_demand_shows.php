<?php
/** Save which tradeshows are EXCLUDED from demand (owner picks which shows to include). */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('research'), 'You do not have permission to edit Research settings.');
header('Content-Type: application/json');

$excluded = json_decode($_POST['excluded'] ?? '[]', true);
if (!is_array($excluded)) $excluded = [];
$excluded = array_values(array_unique(array_filter(array_map(fn($x) => trim((string)$x), $excluded))));

$db = db_connect();
try {
	setting_set($db, 'demand_excluded_shows', json_encode($excluded));
	setting_set($db, 'season_cache_at', '0');   // force the season report to recompute with the new demand
	echo json_encode(['ok' => true, 'excluded' => $excluded]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
