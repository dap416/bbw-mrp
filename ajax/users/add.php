<?php

	require_once(__DIR__."/../../includes/fns.php");
	$dbLink = db_connect();

	$username = strtolower(trim($_POST['username']));
	$role     = $_POST['role'];
	$password = password_hash($_POST['password'], PASSWORD_BCRYPT);

	// Validate email
	if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
		echo 'Please enter a valid email address.';
		exit;
	}

	// Capitalize first letter of each word in name
	$name = implode(' ', array_map(function($word) {
		return ucfirst(strtolower($word));
	}, explode(' ', trim($_POST['name']))));

	$check = $dbLink->query("SELECT COUNT(*) AS `cnt` FROM `users` WHERE `username` = '$username'")->fetch();
	if ($check['cnt'] > 0) {
		echo 'That email address is already registered.';
		exit;
	}

	$dbLink->query("INSERT INTO `users` (`name`,`username`,`password`,`role`) VALUES ('$name','$username','$password','$role')");
	echo 'ok';
