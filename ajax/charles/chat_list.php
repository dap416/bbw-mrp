<?php
/** List saved Charles chats (owner only). */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');
if (!is_owner()) { http_response_code(403); echo json_encode(['chats' => []]); exit; }
$db = db_connect();
charles_ensure_tables($db);
$chats = [];
foreach ($db->query("SELECT id, title, updated_at FROM charles_chats ORDER BY updated_at DESC LIMIT 50") as $r) {
	$chats[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'updated_at' => $r['updated_at']];
}
echo json_encode(['chats' => $chats]);
