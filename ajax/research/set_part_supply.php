<?php
/**
 * Update a raw part's supply term (MOQ or lead time) from the Research reference table.
 * field = 'moq' -> parts.imoq ; field = 'lead' -> parts.lead_time. Products edit permission.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('products'), 'You do not have permission to edit part supply terms.');
header('Content-Type: application/json');

$id    = (int)($_POST['id'] ?? 0);
$field = trim($_POST['field'] ?? '');
$value = max(0, (int)($_POST['value'] ?? 0));
$col   = $field === 'moq' ? 'imoq' : ($field === 'lead' ? 'lead_time' : null);
if ($id <= 0 || $col === null) { echo json_encode(['error' => 'Bad request.']); exit; }

$db = db_connect();
try {
	$db->prepare("UPDATE `parts` SET `$col` = ? WHERE `id` = ?")->execute([$value, $id]);
	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
