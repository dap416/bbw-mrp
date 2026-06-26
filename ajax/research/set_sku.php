<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_login();

	$db  = db_connect();
	$id  = (int)($_POST['id'] ?? 0);
	$sku = trim($_POST['sku'] ?? '');

	if ($id <= 0) { echo 'error'; exit; }

	$stmt = $db->prepare("UPDATE `products` SET `shopify_sku` = ? WHERE `id` = ?");
	$stmt->execute([$sku === '' ? null : $sku, $id]);

	echo 'ok';
