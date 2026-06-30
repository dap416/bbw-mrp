<?php
/** List saved Cash Flow Assistant chats. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_login();
header('Content-Type: application/json');
$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'denied']); exit; }

$db = db_connect();
$chats = [];
try {
	$db->exec("CREATE TABLE IF NOT EXISTS cashflow_chats (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT 'Chat', messages LONGTEXT, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	foreach ($db->query("SELECT id, title, updated_at FROM cashflow_chats ORDER BY updated_at DESC LIMIT 50") as $r) {
		$chats[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'updated_at' => $r['updated_at']];
	}
} catch (Throwable $e) {}
echo json_encode(['chats' => $chats]);
