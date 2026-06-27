<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error'=>'denied']); exit; }

	header('Content-Type: application/json');

	$db = db_connect();
	$chats = [];
	try {
		foreach ($db->query("SELECT id, title, updated_at FROM research_chats ORDER BY updated_at DESC LIMIT 50") as $r) {
			$chats[] = ['id' => (int)$r['id'], 'title' => $r['title'],
			            'updated_at' => date('M j, g:i A', strtotime($r['updated_at']))];
		}
	} catch (Throwable $e) { /* table not created yet */ }

	echo json_encode(['chats' => $chats]);
