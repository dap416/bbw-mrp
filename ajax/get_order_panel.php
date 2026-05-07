<?php

	require_once(__DIR__."/../includes/fns.php");

	$db      = db_connect();
	$orderId = $_POST['record'];

	$notes = $db->query("SELECT * FROM `notes` WHERE `ordid` = '$orderId' ORDER BY `date` DESC");
	$noteHtml = '';
	while ($note = $notes->fetch()) {
		$noteHtml .= '<div>'.date("m/d/y", strtotime($note['date'])).' — '.htmlspecialchars($note['note']).'</div>';
	}

	$payments = $db->query("SELECT * FROM `payments` WHERE `ordid` = '$orderId' ORDER BY `date` DESC");
	$payHtml = '';
	while ($payment = $payments->fetch()) {
		$payHtml .= '<div>'.date("m/d/y", strtotime($payment['date'])).' — $'.$payment['amount'].' — '.$payment['ref'].'</div>';
	}

	$received = $db->query("
		SELECT op.*, w.name AS wh_name
		FROM `ordpost` op
		LEFT JOIN `warehouses` w ON w.id = op.warehouse_id
		WHERE op.`ordid` = '$orderId'
		ORDER BY op.`date` DESC
	");
	$shipHtml = '';
	while ($posting = $received->fetch()) {
		$whLabel = $posting['wh_name'] ? ' — <em class="text-muted">'.$posting['wh_name'].'</em>' : '';
		$shipHtml .= '<div class="d-flex align-items-center gap-2 mb-1">'.
			'<span>'.date("m/d/y", strtotime($posting['date'])).' — QTY: '.$posting['qty'].' — '.$posting['ref'].$whLabel.'</span>'.
			'<button class="btn btn-outline-danger btn-sm py-0 px-1 undo-shipment" style="font-size:0.7rem;line-height:1.4;" data-postid="'.$posting['id'].'" data-orderid="'.$orderId.'">Undo</button>'.
		'</div>';
	}

	$order = $db->query("SELECT `qty`,`recqty`,`ordval`,`paidamt` FROM `orders` WHERE `id` = '$orderId'")->fetch();

	echo json_encode([
		'notes'      => $noteHtml,
		'payments'   => $payHtml,
		'shipments'  => $shipHtml,
		'summaryQty' => $order['qty'].' / '.$order['recqty'],
		'summaryVal' => $order['ordval'].' / '.$order['paidamt'],
	]);
