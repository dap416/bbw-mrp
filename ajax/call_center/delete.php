<?php
/** Remove a call ticket (and its callback task, if one is still outstanding). */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('call_center'), 'You do not have permission to delete calls.');
header('Content-Type: application/json');

$db = db_connect();
if (!$db) { echo json_encode(['error' => 'Database connection failed.']); exit; }
call_center_ensure_tables($db);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['error' => 'Missing ticket id.']); exit; }

try {
	$s = $db->prepare("SELECT callback_task_id FROM call_tickets WHERE id = ?");
	$s->execute([$id]);
	$row = $s->fetch();
	if (!$row) { echo json_encode(['error' => 'That ticket no longer exists.']); exit; }

	$taskId = (int)($row['callback_task_id'] ?? 0);
	if ($taskId > 0) $db->prepare("DELETE FROM tasks WHERE id = ? AND task_type = 'callback'")->execute([$taskId]);
	$db->prepare("DELETE FROM call_tickets WHERE id = ?")->execute([$id]);

	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Could not delete: ' . $e->getMessage()]);
}
