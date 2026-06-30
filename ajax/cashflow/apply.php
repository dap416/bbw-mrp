<?php
/** Apply AI-proposed cash-flow changes AFTER the user approves them. Admin/master. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/cashflow.php");
require_once(__DIR__."/../../includes/shopify.php"); // setting_set
require_login();
header('Content-Type: application/json');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error' => 'Admins only.']); exit; }

$db = db_connect();
$actions = json_decode($_POST['actions'] ?? '[]', true) ?: [];
if (!is_array($actions) || empty($actions)) { echo json_encode(['error' => 'No actions to apply.']); exit; }

$uid     = $_SESSION['user_id'] ?? null;
$today   = date('Y-m-d');
$results = [];

function ymok($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v); }
function dateok($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v); }

foreach ($actions as $a) {
	$type = $a['type'] ?? '';
	try {
		switch ($type) {

			case 'mark_cards_paid':
			case 'unmark_cards_paid':
				if (!ymok($a['ym'] ?? '')) { $results[] = "skip $type: bad month"; break; }
				$list = cardpay_done_months($db);
				if ($type === 'mark_cards_paid') { if (!in_array($a['ym'], $list, true)) $list[] = $a['ym']; }
				else { $list = array_values(array_diff($list, [$a['ym']])); }
				setting_set($db, 'cardpay_done_months', json_encode(array_values($list)));
				$results[] = ($type === 'mark_cards_paid' ? 'Marked' : 'Unmarked') . ' card payments for ' . $a['ym'];
				break;

			case 'mark_expenses_paid':
			case 'unmark_expenses_paid':
				if (!ymok($a['ym'] ?? '')) { $results[] = "skip $type: bad month"; break; }
				$list = expenses_done_months($db);
				if ($type === 'mark_expenses_paid') { if (!in_array($a['ym'], $list, true)) $list[] = $a['ym']; }
				else { $list = array_values(array_diff($list, [$a['ym']])); }
				setting_set($db, 'expenses_done_months', json_encode(array_values($list)));
				$results[] = ($type === 'mark_expenses_paid' ? 'Marked' : 'Unmarked') . ' operating expenses for ' . $a['ym'];
				break;

			case 'set_month_actual':
				if (!ymok($a['ym'] ?? '')) { $results[] = 'skip set_month_actual: bad month'; break; }
				$field = (($a['field'] ?? '') === 'income') ? 'actual_income' : 'actual_projection';
				$val = (!isset($a['value']) || $a['value'] === null || $a['value'] === '') ? null : round((float)$a['value'], 2);
				ensure_month_actuals_table($db);
				$db->prepare("INSERT INTO cash_month_actuals (ym, `$field`, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE `$field`=VALUES(`$field`), updated_at=NOW()")->execute([$a['ym'], $val]);
				$results[] = "Set $field for {$a['ym']} = " . ($val === null ? 'cleared' : $val);
				break;

			case 'update_balance':
				$label = trim($a['label'] ?? '');
				if ($label === '') { $results[] = 'skip update_balance: missing label'; break; }
				$row = $db->prepare("SELECT * FROM cash_balances WHERE LOWER(label) = LOWER(?) LIMIT 1");
				$row->execute([$label]); $bal = $row->fetch();
				if (!$bal) { $results[] = "skip update_balance: no account '$label'"; break; }
				$newBal = isset($a['balance']) ? round((float)$a['balance'], 2) : (float)$bal['balance'];
				$asOf   = dateok($a['as_of'] ?? '') ? $a['as_of'] : $today;
				$apr    = array_key_exists('apr', $a) && $a['apr'] !== null && $a['apr'] !== '' ? round((float)$a['apr'], 2) : ($bal['apr'] ?? null);
				$min    = array_key_exists('min', $a) && $a['min'] !== null && $a['min'] !== '' ? round((float)$a['min'], 2) : ($bal['monthly_payment'] ?? null);
				$db->prepare("UPDATE cash_balances SET balance=?, as_of=?, apr=?, monthly_payment=?, updated_at=NOW(), user_id=? WHERE id=?")
				   ->execute([$newBal, $asOf, $apr, $min, $uid, $bal['id']]);
				$results[] = "Updated $label → $newBal (as of $asOf)";
				break;

			case 'add_event':
				$etype = (($a['etype'] ?? '') === 'in') ? 'in' : 'out';
				$lab   = trim($a['label'] ?? '');
				$amt   = round((float)($a['amount'] ?? 0), 2);
				$week  = max(1, min(4, (int)($a['week'] ?? 1)));
				if ($lab === '' || $amt <= 0 || !ymok($a['ym'] ?? '')) { $results[] = 'skip add_event: bad fields'; break; }
				ensure_cash_events_table($db);
				$db->prepare("INSERT INTO cash_events (etype,label,amount,ym,week,user_id) VALUES (?,?,?,?,?,?)")->execute([$etype, $lab, $amt, $a['ym'], $week, $uid]);
				$results[] = "Added $etype event '$lab' \$$amt to {$a['ym']} wk$week";
				break;

			case 'delete_event':
				$id = (int)($a['id'] ?? 0);
				if ($id <= 0) { $results[] = 'skip delete_event: bad id'; break; }
				$db->prepare("DELETE FROM cash_events WHERE id=?")->execute([$id]);
				$results[] = "Deleted event #$id";
				break;

			case 'set_setting':
				$key = $a['key'] ?? '';
				if (!in_array($key, ['shopify_loan_pct', 'cash_buffer', 'tax_monthly'], true)) { $results[] = "skip set_setting: bad key"; break; }
				$v = max(0.0, (float)($a['value'] ?? 0));
				if ($key === 'shopify_loan_pct') $v = min(100.0, $v);
				setting_set($db, $key, (string)$v);
				$results[] = "Set $key = $v";
				break;

			case 'set_receivable_date':
				$ord = trim($a['order'] ?? '');
				if ($ord === '') { $results[] = 'skip set_receivable_date: missing order'; break; }
				ensure_ar_schedule_table($db);
				if (empty($a['date'])) { $db->prepare("DELETE FROM ar_schedule WHERE order_key=?")->execute([$ord]); $results[] = "Cleared expected date for $ord"; }
				elseif (dateok($a['date'])) { $db->prepare("INSERT INTO ar_schedule (order_key,expected_date,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE expected_date=VALUES(expected_date), updated_at=NOW()")->execute([$ord, $a['date']]); $results[] = "Set $ord expected " . $a['date']; }
				else { $results[] = 'skip set_receivable_date: bad date'; }
				break;

			case 'add_recurring_expense':
				$lab = trim($a['label'] ?? '');
				$amt = round((float)($a['amount'] ?? 0), 2);
				if ($lab === '' || $amt <= 0) { $results[] = 'skip add_recurring_expense: bad fields'; break; }
				ensure_cash_expenses_table($db);
				$db->prepare("INSERT INTO cash_expenses (label,amount,user_id) VALUES (?,?,?)")->execute([$lab, $amt, $uid]);
				$results[] = "Added recurring expense '$lab' \$$amt/mo";
				break;

			default:
				$results[] = "skip unknown action: $type";
		}
	} catch (Throwable $e) {
		$results[] = "error on $type: " . $e->getMessage();
	}
}

echo json_encode(['ok' => true, 'results' => $results]);
