<?php
/** Create (or update) a task. Returns the row for client rendering. */
	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	header('Content-Type: application/json');

	$db = db_connect();
	tasks_ensure_table($db);

	$id    = (int)($_POST['id'] ?? 0);
	$title = trim((string)($_POST['title'] ?? ''));
	$due   = trim((string)($_POST['due_date'] ?? ''));
	$notes = trim((string)($_POST['notes'] ?? ''));
	if ($title === '') { echo json_encode(['error' => 'A task needs a title.']); exit; }
	if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $due = '';
	$dueVal = $due === '' ? null : $due;

	$uid  = $_SESSION['user_id'] ?? null;
	$uname= $_SESSION['user_name'] ?? '';

	if ($id > 0) {
		$db->prepare("UPDATE tasks SET title = ?, due_date = ?, notes = ?, updated_at = NOW() WHERE id = ?")
		   ->execute([$title, $dueVal, ($notes === '' ? null : $notes), $id]);
	} else {
		$db->prepare("INSERT INTO tasks (title, due_date, notes, created_by, created_by_name) VALUES (?, ?, ?, ?, ?)")
		   ->execute([$title, $dueVal, ($notes === '' ? null : $notes), $uid, $uname]);
		$id = (int)$db->lastInsertId();
	}

	// Build the due-date display meta (mirrors task_due_meta in tasks.php).
	$dl = ''; $dc = 'text-muted'; $badge = '';
	if ($dueVal) {
		$days = (int)floor((strtotime($dueVal) - strtotime(date('Y-m-d'))) / 86400);
		$dl = date('D, M j', strtotime($dueVal));
		if     ($days < 0)   { $dc = 'text-danger fw-semibold';  $badge = 'Overdue ' . abs($days) . 'd'; }
		elseif ($days === 0) { $dc = 'text-danger fw-semibold';  $badge = 'Today'; }
		elseif ($days === 1) { $dc = 'text-warning fw-semibold'; $badge = 'Tomorrow'; }
		elseif ($days <= 7)  { $dc = 'text-warning';             $badge = 'In ' . $days . 'd'; }
		else                 { $dc = 'text-muted';               $badge = 'In ' . $days . 'd'; }
	}

	echo json_encode(['ok' => true, 'task' => [
		'id' => $id, 'title' => $title, 'due_date' => $dueVal,
		'due_label' => $dl, 'due_class' => $dc, 'due_badge' => $badge,
	]]);
