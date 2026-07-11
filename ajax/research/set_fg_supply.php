<?php
/**
 * Save the supply terms (MOQ, lead time, unit cost) for a finished-goods GROUP —
 * a family of imported goods (e.g. all WINGZ) that share one source, cost, and lead
 * time. Used by the Need-to-Order timing. Research edit permission required.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/planning.php");
require_login();
require_can(can_edit('research'), 'You do not have permission to edit Research settings.');
header('Content-Type: application/json');

$grp   = trim($_POST['group'] ?? '');
$moq   = max(0, (int)($_POST['moq'] ?? 0));
$lead  = max(0, (int)($_POST['lead_days'] ?? 0));
$cost  = max(0.0, (float)($_POST['unit_cost'] ?? 0));
if ($grp === '') { echo json_encode(['error' => 'Missing group.']); exit; }

$db = db_connect();
ensure_fg_supply_table($db);

try {
	$db->prepare("INSERT INTO fg_supply (grp, moq, lead_days, unit_cost) VALUES (?, ?, ?, ?)
	              ON DUPLICATE KEY UPDATE moq = VALUES(moq), lead_days = VALUES(lead_days), unit_cost = VALUES(unit_cost), updated_at = NOW()")
	   ->execute([$grp, $moq, $lead, $cost]);
	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
