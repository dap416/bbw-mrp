<?php

	require_once(__DIR__."/../../includes/fns.php");
	$dbLink = db_connect();

	$record   = $_POST['record'];
	$name     = $_POST['name'];
	$username = $_POST['username'];
	$role     = $_POST['role'];
	$active   = $_POST['active'];

	$check = $dbLink->query("SELECT COUNT(*) AS `cnt` FROM `users` WHERE `username` = '$username' AND `id` != '$record'")->fetch();
	if ($check['cnt'] > 0) {
		echo 'That username is already taken.';
		exit;
	}

	$dbLink->query("UPDATE `users` SET `name` = '$name', `username` = '$username', `role` = '$role', `active` = '$active' WHERE `id` = '$record'");
	echo 'ok';
