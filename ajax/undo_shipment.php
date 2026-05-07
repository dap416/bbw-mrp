<?php

	require_once(__DIR__."/../includes/fns.php");

	$db = db_connect();
	$postId = (int)($_POST['postid'] ?? 0);
	if (!$postId) { echo 'error'; exit; }

	$posting = $db->query("SELECT * FROM `ordpost` WHERE `id` = '$postId'")->fetch();
	if (!$posting) { echo 'error'; exit; }

	$orderId     = $posting['ordid'];
	$postQty     = (int)$posting['qty'];
	$postRef     = $posting['ref'];
	$warehouseId = (int)($posting['warehouse_id'] ?? 0);

	$order  = $db->query("SELECT * FROM `orders` WHERE `id` = '$orderId'")->fetch();
	$partId = $order['partid'];

	$currQoh = (int)$db->query("SELECT `qoh` FROM `parts` WHERE `id` = '$partId'")->fetch()['qoh'];
	$newQoh  = $currQoh - $postQty;
	$now     = date("Y-m-d H:i:s");

	$userId = $_SESSION['user_id'] ?? null;
	$stmt = $db->prepare("INSERT INTO `trans` (`partid`,`type`,`date`,`ordid`,`postref`,`qty`,`old`,`new`,`user_id`,`warehouse_id`)
	                      VALUES (?,?,?,?,?,?,?,?,?,?)");
	$stmt->execute([$partId,'POSTUNDO',$now,$orderId,$postRef,-$postQty,$currQoh,$newQoh,$userId,$warehouseId?:null]);

	// Reverse warehouse qty (or total if no warehouse recorded)
	if ($warehouseId) {
		wh_adjust($db, $partId, $warehouseId, -$postQty);
	} else {
		$db->exec("UPDATE `parts` SET `qoh` = '$newQoh' WHERE `id` = '$partId'");
	}

	// Reopen order
	$db->exec("UPDATE `orders` SET `recqty` = `recqty` - '$postQty', `postdate` = '0000-00-00 00:00:00' WHERE `id` = '$orderId'");

	$db->exec("DELETE FROM `ordpost` WHERE `id` = '$postId'");

	echo 'ok';
