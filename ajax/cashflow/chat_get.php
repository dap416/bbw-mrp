<?php
/** Load a saved Cash Flow Assistant chat's messages. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_login();
header('Content-Type: application/json');
$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'denied']); exit; }

$db = db_connect();
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['error' => 'bad id']); exit; }

try {
	$r = $db->prepare("SELECT title, messages FROM cashflow_chats WHERE id = ?");
	$r->execute([$id]); $row = $r->fetch();
	if (!$row) { echo json_encode(['error' => 'not found']); exit; }
	echo json_encode(['id' => $id, 'title' => $row['title'], 'messages' => json_decode($row['messages'] ?: '[]', true) ?: []]);
} catch (Throwable $e) {
	echo json_encode(['error' => $e->getMessage()]);
}
