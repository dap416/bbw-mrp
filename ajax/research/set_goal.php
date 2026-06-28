<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('research'), 'You do not have permission to edit Research data.');

	$db   = db_connect();
	$id   = (int)($_POST['id']   ?? 0);
	$goal = (int)($_POST['goal'] ?? 0);

	if ($id <= 0)   { echo 'error'; exit; }
	if ($goal < 0)  { $goal = 0; }

	$stmt = $db->prepare("UPDATE `products` SET `annual_goal` = ? WHERE `id` = ?");
	$stmt->execute([$goal, $id]);

	echo 'ok';
