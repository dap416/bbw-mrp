<?php
/** Save a tradeshow's all-in cost (owner only), then recompute ROI. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');

if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Private.']); exit; }

$db = db_connect();
charles_ensure_tables($db);

$show = trim((string)($_POST['show'] ?? ''));
$cost = round((float)($_POST['cost'] ?? 0), 2);
if ($show === '') { echo json_encode(['error' => 'Missing show.']); exit; }

try {
	$db->prepare("INSERT INTO charles_show_costs (show_name, cost) VALUES (?, ?)
	              ON DUPLICATE KEY UPDATE cost = VALUES(cost), updated_at = NOW()")->execute([$show, $cost]);
	charles_tradeshow_roi($db, true);   // recompute ROI now (reuses cached show sales — fast)
	try { $db->exec("DELETE FROM data_cache WHERE ckey = 'charles_brief'"); } catch (Throwable $e) {}
	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	http_response_code(500); echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
