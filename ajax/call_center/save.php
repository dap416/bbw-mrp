<?php
/**
 * Create or update a call ticket.
 *
 * When "George needs to call them back" is ticked we also create a real Task assigned to
 * the owner (task_type = 'callback'), carrying the caller's number and the reason, and
 * remember its id on the ticket. Untick it and that task is removed again, so the ticket
 * and the task list can never disagree about whether a callback is outstanding.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
require_can(can_edit('call_center'), 'You do not have permission to log calls.');
header('Content-Type: application/json');

$db = db_connect();
if (!$db) { echo json_encode(['error' => 'Database connection failed.']); exit; }
call_center_ensure_tables($db);
tasks_ensure_table($db);

$id     = (int)($_POST['id'] ?? 0);
$uid    = (int)($_SESSION['user_id'] ?? 0) ?: null;
$uname  = $_SESSION['user_name'] ?? '';

$callerName = trim((string)($_POST['caller_name'] ?? ''));
if ($callerName === '') { echo json_encode(['error' => 'Who called? Please enter a name.']); exit; }

$reasons = call_reasons();
$reason  = (string)($_POST['reason'] ?? 'other');
if (!isset($reasons[$reason])) $reason = 'other';

$validActions = call_actions();
$actions = json_decode((string)($_POST['actions'] ?? '[]'), true);
$actions = is_array($actions) ? array_values(array_intersect($actions, array_keys($validActions))) : [];

// Two states only: it's either taken care of, or it still needs work. ("waiting" was a
// third option that meant exactly the same thing as open to anyone reading the report —
// the resolution note carries the nuance instead. Legacy rows may still hold it.)
$status = (string)($_POST['status'] ?? 'open');
if ($status !== 'resolved') $status = 'open';

$callback = !empty($_POST['callback_required']) ? 1 : 0;
$refund   = ($_POST['refund_amount'] ?? '') === '' ? null : round((float)$_POST['refund_amount'], 2);
$orderTot = ($_POST['order_total']   ?? '') === '' ? null : round((float)$_POST['order_total'], 2);

$fields = [
	'agent_id'            => $uid,
	'agent_name'          => $uname,
	'caller_name'         => $callerName,
	'caller_phone'        => trim((string)($_POST['caller_phone'] ?? '')) ?: null,
	'caller_email'        => trim((string)($_POST['caller_email'] ?? '')) ?: null,
	'shopify_customer_id' => trim((string)($_POST['shopify_customer_id'] ?? '')) ?: null,
	'shopify_order_id'    => trim((string)($_POST['shopify_order_id'] ?? '')) ?: null,
	'order_number'        => trim((string)($_POST['order_number'] ?? '')) ?: null,
	'order_total'         => $orderTot,
	'order_status'        => trim((string)($_POST['order_status'] ?? '')) ?: null,
	'reason'              => $reason,
	'summary'             => trim((string)($_POST['summary'] ?? '')) ?: null,
	'actions'             => json_encode($actions),
	'refund_amount'       => $refund,
	'exchange_notes'      => trim((string)($_POST['exchange_notes'] ?? '')) ?: null,
	'status'              => $status,
	'resolution'          => trim((string)($_POST['resolution'] ?? '')) ?: null,
	'callback_required'   => $callback,
];

try {
	$db->beginTransaction();

	if ($id > 0) {
		$existing = $db->prepare("SELECT callback_task_id FROM call_tickets WHERE id = ?");
		$existing->execute([$id]);
		$prev = $existing->fetch();
		if (!$prev) { $db->rollBack(); echo json_encode(['error' => 'That ticket no longer exists.']); exit; }
		$taskId = (int)($prev['callback_task_id'] ?? 0) ?: null;

		$sets = []; $vals = [];
		foreach ($fields as $col => $v) { $sets[] = "`$col` = ?"; $vals[] = $v; }
		$vals[] = $id;
		$db->prepare("UPDATE call_tickets SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
	} else {
		$cols = array_keys($fields);
		$db->prepare("INSERT INTO call_tickets (`" . implode('`,`', $cols) . "`) VALUES (" . rtrim(str_repeat('?,', count($cols)), ',') . ")")
		   ->execute(array_values($fields));
		$id = (int)$db->lastInsertId();
		$taskId = null;
	}

	// ── Callback task for the owner, kept in lockstep with the checkbox ──────────
	if ($callback) {
		$owner = owner_user($db);
		$due   = trim((string)($_POST['callback_due'] ?? '')) ?: date('Y-m-d');
		$who   = $callerName . (!empty($fields['caller_phone']) ? ' — ' . $fields['caller_phone'] : '');
		$title = 'Call back ' . $who;
		$notes = "Reason: " . $reasons[$reason] . "\n"
		       . (!empty($fields['order_number']) ? "Order: " . $fields['order_number'] . "\n" : '')
		       . (!empty($fields['summary']) ? "\n" . $fields['summary'] . "\n" : '')
		       . "\nLogged by " . ($uname ?: 'the call centre') . " · Call ticket #" . $id;
		$meta  = json_encode(['ticket_id' => $id, 'phone' => $fields['caller_phone'], 'order' => $fields['order_number']]);

		if ($taskId) {
			$db->prepare("UPDATE tasks SET title = ?, notes = ?, due_date = ?, task_meta = ? WHERE id = ?")
			   ->execute([$title, $notes, $due, $meta, $taskId]);
		} else {
			$db->prepare("INSERT INTO tasks (title, notes, due_date, assigned_to, assigned_to_name, created_by, created_by_name, task_type, task_meta)
			              VALUES (?,?,?,?,?,?,?,'callback',?)")
			   ->execute([$title, $notes, $due, $owner['id'] ?? null, $owner['name'] ?? null, $uid, $uname, $meta]);
			$taskId = (int)$db->lastInsertId();
			$db->prepare("UPDATE call_tickets SET callback_task_id = ? WHERE id = ?")->execute([$taskId, $id]);
		}
	} elseif ($taskId) {
		// Callback no longer needed — drop the task so the two never disagree.
		$db->prepare("DELETE FROM tasks WHERE id = ? AND task_type = 'callback'")->execute([$taskId]);
		$db->prepare("UPDATE call_tickets SET callback_task_id = NULL WHERE id = ?")->execute([$id]);
		$taskId = null;
	}

	$db->commit();
} catch (Throwable $e) {
	if ($db->inTransaction()) $db->rollBack();
	http_response_code(500);
	echo json_encode(['error' => 'Could not save the call: ' . $e->getMessage()]);
	exit;
}

briefing_touch($db);   // a new callback should show up in George's dashboard briefing
echo json_encode(['ok' => true, 'id' => $id, 'callback_task_id' => $taskId]);
