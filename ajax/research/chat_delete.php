<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	if (!has_access('research')) { http_response_code(403); echo 'You do not have access to Research.'; exit; }

	$db = db_connect();
	$id = (int)($_POST['id'] ?? 0);
	if ($id <= 0) { echo 'error'; exit; }

	$stmt = $db->prepare("DELETE FROM research_chats WHERE id = ?");
	$stmt->execute([$id]);
	echo 'ok';
