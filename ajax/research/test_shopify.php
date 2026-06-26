<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/shopify.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Access denied']); exit; }

	header('Content-Type: application/json');

	if (!shopify_is_configured()) {
		echo json_encode(['ok' => false, 'error' => 'Store domain and token are required.']);
		exit;
	}

	echo json_encode(shopify_test_connection());
