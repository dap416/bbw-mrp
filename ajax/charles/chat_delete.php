<?php
/** Delete one saved Charles chat (owner only). */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');
if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Private.']); exit; }
$db = db_connect();
charles_ensure_tables($db);
$id = (int)($_POST['id'] ?? 0);
if ($id > 0) $db->prepare("DELETE FROM charles_chats WHERE id = ?")->execute([$id]);
echo json_encode(['ok' => true]);
