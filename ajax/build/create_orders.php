<?php
/**
 * Create packaging orders (intransit rows) from the recommendation, in one click.
 * Inserts each as a PLACED order (orddate set) so it appears immediately in the
 * Packaging Orders list, ready to add to a pick list and build. No inventory is
 * touched here — raw materials are only deducted later at Finalize.
 *
 * Input: orders = JSON [{prodid, qty}, ...], warehouse_id.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('build'), 'You do not have permission to create packaging orders.');

$db  = db_connect();
$now = date("Y-m-d H:i:s");
$whId = (int)($_POST['warehouse_id'] ?? 0);

$items = json_decode($_POST['orders'] ?? '[]', true);
if (!is_array($items) || empty($items)) { echo 'error: nothing to add'; exit; }

// Validate product ids up front.
$valid = [];
foreach ($db->query("SELECT id FROM `products`") as $r) { $valid[(int)$r['id']] = true; }

$created = 0;
try {
	$db->beginTransaction();
	$stmt = $db->prepare("INSERT INTO `intransit` (`prodid`,`qty`,`adddate`,`orddate`,`warehouse_id`) VALUES (?,?,?,?,?)");
	foreach ($items as $it) {
		$pid = (int)($it['prodid'] ?? 0);
		$qty = (int)($it['qty'] ?? 0);
		if ($pid <= 0 || $qty <= 0 || empty($valid[$pid])) continue;
		$stmt->execute([$pid, $qty, $now, $now, $whId ?: null]);
		$created++;
	}
	$db->commit();
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500);
	echo 'error: ' . $e->getMessage();
	exit;
}

echo 'ok:' . $created;
