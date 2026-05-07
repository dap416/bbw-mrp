<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	if (!has_access('manufacturers')) { echo 'Access denied.'; exit; }

	$dbLink = db_connect();

	$name           = trim($_POST['name'] ?? '');
	$contact_person = trim($_POST['contact_person'] ?? '');
	$email          = trim($_POST['email'] ?? '');
	$phone          = trim($_POST['phone'] ?? '');
	$address1       = trim($_POST['address1'] ?? '');
	$address2       = trim($_POST['address2'] ?? '');
	$city           = trim($_POST['city'] ?? '');
	$state_province = trim($_POST['state_province'] ?? '');
	$postal_code    = trim($_POST['postal_code'] ?? '');
	$country        = trim($_POST['country'] ?? '');

	if (!$name) {
		echo 'Manufacturer name is required.';
		exit;
	}

	$check = $dbLink->prepare("SELECT COUNT(*) AS cnt FROM `manufacturers` WHERE `name` = ?");
	$check->execute([$name]);
	if ($check->fetch()['cnt'] > 0) {
		echo 'A manufacturer with that name already exists.';
		exit;
	}

	$stmt = $dbLink->prepare("INSERT INTO `manufacturers`
		(`name`,`contact_person`,`email`,`phone`,`address1`,`address2`,`city`,`state_province`,`postal_code`,`country`)
		VALUES (?,?,?,?,?,?,?,?,?,?)");
	$stmt->execute([$name,$contact_person,$email,$phone,$address1,$address2,$city,$state_province,$postal_code,$country]);

	echo 'ok';
