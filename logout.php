<?php

	require_once(__DIR__."/includes/fns.php");
	$_SESSION = [];
	session_destroy();
	header('Location: /login.php');
	exit;
