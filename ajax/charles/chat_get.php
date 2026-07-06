<?php
/** Load one saved Charles chat (owner only). */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');
if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Private.']); exit; }
$db = db_connect();
charles_ensure_tables($db);
$id = (int)($_POST['id'] ?? 0);
$r = $db->prepare("SELECT id, messages FROM charles_chats WHERE id = ?");
$r->execute([$id]);
$row = $r->fetch();
if (!$row) { echo json_encode(['error' => 'Not found.']); exit; }
$msgs = json_decode($row['messages'] ?? '[]', true) ?: [];
echo json_encode(['id' => (int)$row['id'], 'messages' => $msgs]);
