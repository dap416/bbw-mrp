<?php

	require_once(__DIR__."/../../includes/fns.php");
	$dbLink = db_connect();

	$record   = $_POST['record'];
	$password = password_hash($_POST['password'], PASSWORD_BCRYPT);

	$dbLink->query("UPDATE `users` SET `password` = '$password' WHERE `id` = '$record'");
	echo 'ok';
