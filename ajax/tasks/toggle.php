<?php
/** Mark a task complete / incomplete. */
	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	header('Content-Type: application/json');

	$db = db_connect();
	tasks_ensure_table($db);

	$id   = (int)($_POST['id'] ?? 0);
	$done = (int)($_POST['completed'] ?? 0) ? 1 : 0;
	if ($id <= 0) { echo json_encode(['error' => 'Missing task id.']); exit; }

	if ($done) $db->prepare("UPDATE tasks SET completed = 1, completed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$id]);
	else       $db->prepare("UPDATE tasks SET completed = 0, completed_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);

	echo json_encode(['ok' => true]);
