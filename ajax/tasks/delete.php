<?php
/** Delete a task. */
	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	header('Content-Type: application/json');

	$db = db_connect();
	tasks_ensure_table($db);

	$id = (int)($_POST['id'] ?? 0);
	if ($id <= 0) { echo json_encode(['error' => 'Missing task id.']); exit; }

	$db->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);
	echo json_encode(['ok' => true]);
