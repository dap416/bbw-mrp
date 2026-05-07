<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	if (!has_access('manufacturers')) { echo 'Access denied.'; exit; }

	$dbLink = db_connect();

	$record = (int)($_POST['record'] ?? 0);
	if (!$record) { echo 'Invalid request.'; exit; }

	// Unlink any parts referencing this manufacturer before deleting
	$stmt = $dbLink->prepare("UPDATE `parts` SET `manufacturer` = 0 WHERE `manufacturer` = ?");
	$stmt->execute([$record]);

	$del = $dbLink->prepare("DELETE FROM `manufacturers` WHERE `id` = ?");
	$del->execute([$record]);

	echo 'ok';
