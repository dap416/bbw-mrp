<?php
/** Add / update / delete a finished-product purchase (FP WINGZ, cases from China). Owner only. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_login();
header('Content-Type: application/json');

if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Private.']); exit; }

$db = db_connect();
ensure_fp_purchases_table($db);

$id = (int)($_POST['id'] ?? 0);

if (!empty($_POST['delete'])) {
	if ($id > 0) $db->prepare("DELETE FROM fp_purchases WHERE id = ?")->execute([$id]);
	try { $db->exec("DELETE FROM data_cache WHERE ckey = 'charles_brief'"); } catch (Throwable $e) {}
	echo json_encode(['ok' => true]); exit;
}

$item  = trim((string)($_POST['item'] ?? ''));
$qty   = (int)($_POST['qty'] ?? 0);
$unit  = round((float)($_POST['unit_cost'] ?? 0), 2);
$total = round((float)($_POST['total_cost'] ?? 0), 2);
$ym    = trim((string)($_POST['order_ym'] ?? ''));
$card  = trim((string)($_POST['card_label'] ?? ''));
$note  = trim((string)($_POST['note'] ?? ''));

if ($item === '')                        { echo json_encode(['error' => 'What is the item?']); exit; }
if (!preg_match('/^\d{4}-\d{2}$/', $ym))  { echo json_encode(['error' => 'Pick the order month (YYYY-MM).']); exit; }
if ($total <= 0) $total = $qty * $unit;
if ($total <= 0)                          { echo json_encode(['error' => 'Enter a total cost (or qty + unit cost).']); exit; }

try {
	if ($id > 0) {
		$db->prepare("UPDATE fp_purchases SET item=?, qty=?, unit_cost=?, total_cost=?, order_ym=?, card_label=?, note=?, updated_at=NOW() WHERE id=?")
		   ->execute([$item, $qty, $unit, $total, $ym, $card, $note, $id]);
	} else {
		$db->prepare("INSERT INTO fp_purchases (item, qty, unit_cost, total_cost, order_ym, card_label, note, user_id) VALUES (?,?,?,?,?,?,?,?)")
		   ->execute([$item, $qty, $unit, $total, $ym, $card, $note, $_SESSION['user_id'] ?? null]);
	}
	try { $db->exec("DELETE FROM data_cache WHERE ckey = 'charles_brief'"); } catch (Throwable $e) {}
	echo json_encode(['ok' => true]);
} catch (Throwable $e) {
	http_response_code(500); echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
