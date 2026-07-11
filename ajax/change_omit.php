<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to perform this action.');

	$dbLink = $mysqli = db_connect();

	$amount = max(0, (int)($_POST['amount'] ?? 0));
	$record = (int)($_POST['record'] ?? 0);

	if ($record > 0) {
		$stmt = $dbLink->prepare("UPDATE `parts` SET `omit` = ? WHERE `id` = ?");
		$stmt->execute([$amount, $record]);
	}
	echo 'ok';