<?php
/**
 * Add/update an account for the Cash Flow module (writes cash_balances,
 * shared with Cash Management). Unlike the old save_balance.php this keeps
 * a card's monthly_payment (used here as the planned debt payment) intact on
 * edit, and stores a fixed payment for LOC loans. Admin/master only.
 */
require_once(__DIR__ . "/../../includes/cash_flow.php");
require_login();
header('Content-Type: application/json');
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
if (!$db) { http_response_code(500); echo json_encode(['error' => 'DB connection failed.']); exit; }
ensure_cash_balances_table($db);

$group = in_array($_POST['group'] ?? '', ['banks', 'cards', 'locs'], true) ? $_POST['group'] : 'banks';
$type  = $group === 'banks' ? 'bank' : ($group === 'cards' ? 'credit' : 'loc');
$id    = (int)($_POST['id'] ?? 0);
$label = trim((string)($_POST['label'] ?? ''));
if ($label === '') { echo json_encode(['error' => 'Please enter an account name.']); exit; }

$balance = round((float)($_POST['balance'] ?? 0), 2);
$asOf    = trim((string)($_POST['as_of'] ?? ''));
if ($asOf === '' || strpos($asOf, '0000-00-00') === 0) { $asOf = date('Y-m-d'); }
elseif (strtotime($asOf) === false) { echo json_encode(['error' => 'Invalid date.']); exit; }

$limit   = trim((string)($_POST['credit_limit'] ?? ''));
$limit   = ($limit === '' || $type === 'bank') ? null : round((float)$limit, 2);   // cards: limit ; locs: ceiling
$apr     = trim((string)($_POST['apr'] ?? ''));
$apr     = ($apr === '' || $type === 'bank') ? null : round((float)$apr, 2);
// Per-card minimum-payment %. Blank => NULL => fall back to the global card_min_pct
// setting, so a card only diverges from the default once someone sets it deliberately.
$minPct  = trim((string)($_POST['min_pct'] ?? ''));
$minPct  = ($minPct === '' || $type !== 'credit') ? null : max(0.0, min(100.0, round((float)$minPct, 2)));
$payment = trim((string)($_POST['monthly_payment'] ?? ''));
$payment = ($payment === '' || $type !== 'loc') ? null : round((float)$payment, 2);
$dueDay  = trim((string)($_POST['due_day'] ?? ''));
$dueDay  = ($dueDay === '' || $type !== 'loc') ? null : max(1, min(31, (int)$dueDay));
// A LOC's facility = the line of credit it draws on (NOT its own label). Several loans can share
// one facility; the ceiling lives on the facility (loc_ceilings), counted once — never per loan.
$locName = null;
if ($type === 'loc') {
	$locName = trim((string)($_POST['loc_name'] ?? ''));
	if ($locName === '' && $id > 0) {   // edit without a facility supplied → keep the existing one
		try { $st = $db->prepare("SELECT loc_name FROM cash_balances WHERE id=?"); $st->execute([$id]); $ex = $st->fetchColumn(); if ($ex !== false && $ex !== null) $locName = trim((string)$ex); } catch (Throwable $e) {}
	}
}
// The ceiling entered for a facility-linked LOC belongs to the shared facility, not the row.
// Persist it on the facility and clear the per-row limit so cf_match_ceiling can't double it.
if ($type === 'loc' && $locName !== null && $locName !== '') {
	if ($limit !== null) cf_set_facility_ceiling($db, $locName, $limit);
	$limit = null;
}
$qbId    = trim((string)($_POST['qb_account_id'] ?? ''));   // link to a QB account = auto-synced nightly
$uid     = (int)($_SESSION['user_id'] ?? 0) ?: null;

try {
	if ($id > 0) {
		if ($type === 'credit') {
			// Preserve monthly_payment (the planned debt payment set in the Debt view).
			$db->prepare("UPDATE cash_balances SET label=?, acct_type=?, balance=?, credit_limit=?, apr=?, min_pct=?, qb_account_id=?, as_of=?, updated_at=NOW() WHERE id=?")
			   ->execute([$label, $type, $balance, $limit, $apr, $minPct, $qbId, $asOf, $id]);
		} elseif ($type === 'loc') {
			$db->prepare("UPDATE cash_balances SET label=?, acct_type=?, balance=?, credit_limit=?, apr=?, monthly_payment=?, due_day=?, loc_name=?, qb_account_id=?, as_of=?, updated_at=NOW() WHERE id=?")
			   ->execute([$label, $type, $balance, $limit, $apr, $payment, $dueDay, $locName, $qbId, $asOf, $id]);
		} else {
			$db->prepare("UPDATE cash_balances SET label=?, acct_type=?, balance=?, qb_account_id=?, as_of=?, updated_at=NOW() WHERE id=?")
			   ->execute([$label, $type, $balance, $qbId, $asOf, $id]);
		}
	} else {
		$db->prepare("INSERT INTO cash_balances (label, acct_type, balance, credit_limit, monthly_payment, apr, min_pct, qb_account_id, as_of, loc_name, due_day, user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
		   ->execute([$label, $type, $balance, $limit, $payment, $apr, $minPct, $qbId, $asOf, $locName, $dueDay, $uid]);
		$id = (int)$db->lastInsertId();
	}
	echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}
