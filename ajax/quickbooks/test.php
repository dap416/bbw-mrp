<?php
/** Quick QuickBooks connectivity check for the Integrations page. Admin/master only. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/quickbooks.php");
require_login();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'denied']); exit; }

echo json_encode(qb_company_info());
