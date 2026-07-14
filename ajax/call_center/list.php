<?php
/**
 * The call log + the roll-up George reads: who called, what about, was it resolved,
 * was there an order / refund / exchange, and is a callback still outstanding.
 * Filters: date range, status, reason, agent, free-text.
 */
require_once(__DIR__."/../../includes/fns.php");
require_login();
if (!has_access('call_center')) { http_response_code(403); echo json_encode(['error' => 'No access to the Call Center.']); exit; }
header('Content-Type: application/json');

$db = db_connect();
if (!$db) { echo json_encode(['error' => 'Database connection failed.']); exit; }
call_center_ensure_tables($db);

$from   = trim((string)($_POST['from']   ?? ''));
$to     = trim((string)($_POST['to']     ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));
$agent  = (int)($_POST['agent'] ?? 0);
$q      = trim((string)($_POST['q'] ?? ''));

$where = []; $args = [];
if ($from   !== '') { $where[] = "t.called_at >= ?"; $args[] = $from . ' 00:00:00'; }
if ($to     !== '') { $where[] = "t.called_at <= ?"; $args[] = $to   . ' 23:59:59'; }
if ($status !== '' && in_array($status, ['open','resolved','waiting'], true)) { $where[] = "t.status = ?"; $args[] = $status; }
if ($reason !== '' && isset(call_reasons()[$reason])) { $where[] = "t.reason = ?"; $args[] = $reason; }
if ($agent  > 0)    { $where[] = "t.agent_id = ?"; $args[] = $agent; }
if ($q      !== '') {
	$where[] = "(t.caller_name LIKE ? OR t.caller_phone LIKE ? OR t.caller_email LIKE ? OR t.order_number LIKE ? OR t.summary LIKE ?)";
	$like = '%' . $q . '%';
	array_push($args, $like, $like, $like, $like, $like);
}
$sql = "SELECT t.*, k.completed AS callback_done
        FROM call_tickets t
        LEFT JOIN tasks k ON k.id = t.callback_task_id"
     . ($where ? " WHERE " . implode(' AND ', $where) : '')
     . " ORDER BY t.called_at DESC LIMIT 300";

try {
	$stmt = $db->prepare($sql);
	$stmt->execute($args);
	$rows = $stmt->fetchAll();
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Could not load calls: ' . $e->getMessage()]);
	exit;
}

$tickets = [];
foreach ($rows as $r) {
	$acts = json_decode((string)($r['actions'] ?? '[]'), true);
	$tickets[] = [
		'id'            => (int)$r['id'],
		'called_at'     => $r['called_at'],
		'agent_name'    => $r['agent_name'],
		'agent_id'      => (int)($r['agent_id'] ?? 0),
		'caller_name'   => $r['caller_name'],
		'caller_phone'  => $r['caller_phone'],
		'caller_email'  => $r['caller_email'],
		'shopify_customer_id' => $r['shopify_customer_id'],
		'order_number'  => $r['order_number'],
		'order_total'   => $r['order_total'] === null ? null : (float)$r['order_total'],
		'order_status'  => $r['order_status'],
		'reason'        => $r['reason'],
		'summary'       => $r['summary'],
		'actions'       => is_array($acts) ? $acts : [],
		'refund_amount' => $r['refund_amount'] === null ? null : (float)$r['refund_amount'],
		'exchange_notes'=> $r['exchange_notes'],
		'status'        => $r['status'],
		'resolution'    => $r['resolution'],
		'callback_required' => (int)$r['callback_required'],
		'callback_task_id'  => (int)($r['callback_task_id'] ?? 0),
		'callback_done'     => (int)($r['callback_done'] ?? 0),
	];
}

// Roll-up over the filtered set — the numbers George glances at.
$stats = ['calls' => count($tickets), 'resolved' => 0, 'open' => 0, 'callbacks' => 0,
          'refund_total' => 0.0, 'refunds' => 0, 'exchanges' => 0, 'by_reason' => []];
foreach ($tickets as $t) {
	if ($t['status'] === 'resolved') $stats['resolved']++; else $stats['open']++;
	if ($t['callback_required'] && !$t['callback_done']) $stats['callbacks']++;
	if ($t['refund_amount'] > 0) { $stats['refunds']++; $stats['refund_total'] += $t['refund_amount']; }
	if (in_array('exchange_sent', $t['actions'], true)) $stats['exchanges']++;
	$stats['by_reason'][$t['reason']] = ($stats['by_reason'][$t['reason']] ?? 0) + 1;
}
arsort($stats['by_reason']);
$stats['refund_total'] = round($stats['refund_total'], 2);

echo json_encode(['ok' => true, 'tickets' => $tickets, 'stats' => $stats,
                  'reasons' => call_reasons(), 'action_labels' => call_actions()]);
