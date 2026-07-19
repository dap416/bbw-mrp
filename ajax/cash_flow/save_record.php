<?php
/** Create/update a cash-flow forecast record. Admin/master only. */
require_once(__DIR__ . "/../../includes/cash_flow.php");
require_login();
header('Content-Type: application/json');
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo json_encode(['error' => 'DB connection failed.']); exit; }
cf_ensure_tables($db);

$id     = (int)($_POST['id'] ?? 0);
$rtype  = in_array($_POST['rtype'] ?? '', ['income', 'operating', 'purchase'], true) ? $_POST['rtype'] : 'operating';
$sub    = trim((string)($_POST['sub'] ?? ''));
$sub    = $sub === '' ? null : $sub;
$amount = (float)($_POST['amount'] ?? 0);
$desc   = trim((string)($_POST['description'] ?? ''));
$note   = trim((string)($_POST['note'] ?? ''));
$rec    = in_array($_POST['recurrence'] ?? '', ['once', 'monthly', 'quarterly', 'annual'], true) ? $_POST['recurrence'] : 'once';
$start  = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['start_ym'] ?? '')) ? $_POST['start_ym'] : cf_horizon_start();
$pay    = trim((string)($_POST['pay'] ?? 'cash'));
$pay    = $pay === '' ? 'cash' : $pay;

try {
	if ($id > 0) {
		$db->prepare("UPDATE cf_records SET rtype=?, sub=?, amount=?, description=?, note=?, recurrence=?, pay=?, updated_at=NOW() WHERE id=?")
			->execute([$rtype, $sub, $amount, $desc, $note, $rec, $pay, $id]);
	} else {
		$db->prepare("INSERT INTO cf_records (rtype, sub, amount, description, note, recurrence, start_ym, pay, user_id) VALUES (?,?,?,?,?,?,?,?,?)")
			->execute([$rtype, $sub, $amount, $desc, $note, $rec, $start, $pay, (int)($_SESSION['user_id'] ?? 0) ?: null]);
		$id = (int)$db->lastInsertId();
	}
	echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
