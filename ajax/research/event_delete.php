<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('research'), 'You do not have permission to delete planning events.');

	$db = db_connect();
	$id = (int)($_POST['id'] ?? 0);
	if ($id <= 0) { echo 'error'; exit; }

	$stmt = $db->prepare("DELETE FROM planning_events WHERE id = ?");
	$stmt->execute([$id]);
	echo 'ok';
