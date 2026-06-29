<?php
/**
 * Intuit redirects here after the user grants access. Validates the CSRF
 * state, then exchanges the authorization code for tokens and stores the
 * company (realm) id. Admin/master only.
 */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/quickbooks.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); exit('Admins only.'); }

// User declined, or Intuit returned an error.
if (!empty($_GET['error'])) {
	header('Location: /integrations.php?qb=denied');
	exit;
}

$code    = $_GET['code']    ?? '';
$realmId = $_GET['realmId'] ?? '';
$state   = $_GET['state']   ?? '';

$expected = $_SESSION['qb_oauth_state'] ?? '';
unset($_SESSION['qb_oauth_state']);

if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
	header('Location: /integrations.php?qb=badstate');
	exit;
}
if ($code === '' || $realmId === '') {
	header('Location: /integrations.php?qb=missing');
	exit;
}

$res = qb_exchange_code($code, $realmId);
if (!empty($res['error'])) {
	header('Location: /integrations.php?qb=error&msg=' . urlencode($res['error']));
	exit;
}

header('Location: /integrations.php?qb=connected');
exit;
