<?php
/** Edit a PENDING (unsent) FP stock order's quantity. Reason required + audit-logged. */
	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	require_can(can_edit('orders'), 'You do not have permission to perform this action.');

	$db     = db_connect();
	$id     = (int)($_POST['id'] ?? 0);
	$qty    = (int)($_POST['qty'] ?? 0);
	$reason = trim((string)($_POST['reason'] ?? ''));

	if (!$id || $qty <= 0) { echo 'error'; exit; }
	if ($reason === '')    { echo 'error: reason required'; exit; }

	$order = $db->query("SELECT `prodid`, `qty` FROM `intransit` WHERE `id` = " . $id . " AND `orddate` = '0000-00-00 00:00:00'")->fetch();
	if (!$order) { echo 'error'; exit; }
	$old = (int)$order['qty'];

	$db->prepare("UPDATE `intransit` SET `qty` = ? WHERE `id` = ? AND `orddate` = '0000-00-00 00:00:00'")->execute([$qty, $id]);
	if ($qty !== $old) intransit_log_edit($db, $id, (int)$order['prodid'], 'qty', $old, $qty, $reason);

	echo 'ok';
