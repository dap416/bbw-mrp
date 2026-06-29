<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('products'), 'You do not have permission to edit part fields (SKU, cost, MOQ, etc.).');

	$dbLink = db_connect();

	$record       = (int)($_POST['record'] ?? 0);
	$sku          = trim($_POST['sku']  ?? '');
	$desc         = trim($_POST['desc'] ?? '');
	$cost         = (float)($_POST['cost'] ?? 0);
	$imoq         = (int)($_POST['imoq'] ?? 0);
	$lead_time    = isset($_POST['lead_time'])    ? (int)$_POST['lead_time']    : 45;
	$manufacturer = isset($_POST['manufacturer']) ? (int)$_POST['manufacturer'] : 0;

	if ($record <= 0) { echo 'error: missing part id'; exit; }

	try {
		$stmt = $dbLink->prepare(
			"UPDATE `parts` SET `mfgpartno` = ?, `partno` = ?, `desc` = ?, `cost` = ?, `imoq` = ?, `lead_time` = ?, `manufacturer` = ? WHERE `id` = ?");
		$stmt->execute([$sku, $sku, $desc, $cost, $imoq, $lead_time, $manufacturer, $record]);
	} catch (Throwable $e) {
		http_response_code(500);
		echo 'error: ' . $e->getMessage();
		exit;
	}

	echo 'ok';
