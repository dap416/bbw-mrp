<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error'=>'denied']); exit; }

	header('Content-Type: application/json');

	$db = db_connect();
	$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
	if ($id <= 0) { echo json_encode(['error' => 'Bad id']); exit; }

	$row = $db->query("SELECT id, title, messages FROM research_chats WHERE id = " . $id)->fetch();
	if (!$row) { echo json_encode(['error' => 'Chat not found.']); exit; }

	echo json_encode([
		'chat_id'  => (int)$row['id'],
		'title'    => $row['title'],
		'messages' => chat_display_messages(json_decode($row['messages'] ?: '[]', true) ?: []),
	]);
