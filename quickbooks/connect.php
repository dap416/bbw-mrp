<?php
/**
 * Start the QuickBooks Online OAuth2 connection: generate a CSRF state,
 * stash it in the session, and redirect the user to Intuit's consent screen.
 * Admin/master only.
 */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/quickbooks.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); exit('Admins only.'); }

if (!qb_is_configured()) {
	header('Location: /integrations.php?qb=notconfigured');
	exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['qb_oauth_state'] = $state;

header('Location: ' . qb_authorize_url($state));
exit;
