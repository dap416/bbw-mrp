<?php
/** Delete a saved Cash Flow Assistant chat. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_login();
$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'denied'; exit; }

$db = db_connect();
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo 'error'; exit; }
try { $db->prepare("DELETE FROM cashflow_chats WHERE id = ?")->execute([$id]); echo 'ok'; }
catch (Throwable $e) { http_response_code(500); echo 'error'; }
