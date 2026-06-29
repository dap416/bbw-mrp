<?php
/** Disconnect QuickBooks (wipe tokens + realm). Admin/master only. */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/quickbooks.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); exit('Admins only.'); }

qb_disconnect();
header('Location: /integrations.php?qb=disconnected');
exit;
