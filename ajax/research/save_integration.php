<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/shopify.php");
	require_login();

	// Admin/master only — these are credentials.
	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'denied'; exit; }

	$db = db_connect();

	$domain  = trim($_POST['domain']      ?? '');
	$version  = trim($_POST['api_version'] ?? '');
	$token   = trim($_POST['token']       ?? '');

	// Normalize domain — accept a pasted URL and reduce to the host.
	$domain = preg_replace('#^https?://#i', '', $domain);
	$domain = rtrim($domain, '/');

	setting_set($db, 'shopify_domain', $domain);
	setting_set($db, 'shopify_api_version', $version !== '' ? $version : '2025-01');

	// Only overwrite the token when a new one is actually entered, so saving
	// the form without re-typing it doesn't wipe the stored token.
	if ($token !== '') {
		setting_set($db, 'shopify_token', $token);
	}

	echo 'ok';
