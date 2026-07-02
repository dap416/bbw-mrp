<?php
/** Create (or update) a task. Returns the row for client rendering.
 *  Managers (admin/master) can also assign it to a user and give it a
 *  "type" (tradeshow build & pack / inventory count) with metadata that the
 *  assignee's dashboard briefing turns into live stats. */
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

	$uid   = $_SESSION['user_id'] ?? null;
	$uname = $_SESSION['user_name'] ?? '';
	$isManager = in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true);

	// ── Type ──────────────────────────────────────────────────────────────────
	$type = (string)($_POST['task_type'] ?? 'general');
	if (!in_array($type, ['general', 'tradeshow', 'inv_count'], true)) $type = 'general';

	// ── Assignment (managers only) ────────────────────────────────────────────
	$assignedTo = null; $assignedName = null; $typeMeta = null; $metaSummary = '';
	if ($isManager) {
		$reqAssign = (int)($_POST['assigned_to'] ?? 0);
		if ($reqAssign > 0) {
			$u = $db->prepare("SELECT id, name FROM users WHERE id = ? AND active = 1");
			$u->execute([$reqAssign]);
			if ($row = $u->fetch()) { $assignedTo = (int)$row['id']; $assignedName = $row['name']; }
		}

		// ── Type metadata ─────────────────────────────────────────────────────
		$rawMeta = json_decode((string)($_POST['task_meta'] ?? ''), true);
		if (!is_array($rawMeta)) $rawMeta = [];

		if ($type === 'tradeshow') {
			$reqShows = array_values(array_filter(array_map(fn($s) => trim((string)$s), (array)($rawMeta['shows'] ?? [])), fn($s) => $s !== ''));
			// Prefer to keep only real show names, but don't block the save if the
			// Shopify location list is momentarily unavailable — trust the client
			// (it picked from show_list.php).
			$known = [];
			try {
				require_once(__DIR__."/../../includes/shopify.php");
				foreach (tradeshow_locations() as $loc) { $n = trim((string)($loc['name'] ?? '')); if ($n !== '') $known[strtolower($n)] = $n; }
			} catch (Throwable $e) {}
			$shows = [];
			foreach ($reqShows as $s) { $shows[] = $known[strtolower($s)] ?? $s; }
			$shows = array_values(array_unique($shows));
			if ($known) { $shows = array_values(array_filter($shows, fn($s) => isset($known[strtolower($s)]))); }
			if ($shows) { $typeMeta = ['shows' => $shows]; $metaSummary = implode(', ', $shows); }
			else $type = 'general';
		} elseif ($type === 'inv_count') {
			$pid = (int)($rawMeta['part_id'] ?? 0);
			if ($pid > 0) {
				$pr = $db->prepare("SELECT id, partno, `desc` FROM parts WHERE id = ?");
				$pr->execute([$pid]);
				if ($p = $pr->fetch()) {
					$typeMeta = ['part_id' => (int)$p['id'], 'partno' => $p['partno'], 'desc' => $p['desc']];
					$metaSummary = $p['partno'] . ($p['desc'] ? ' — ' . $p['desc'] : '');
				} else $type = 'general';
			} else $type = 'general';
		}
	} else {
		$type = 'general'; // standard users create plain personal tasks
	}
	$metaJson = $typeMeta ? json_encode($typeMeta) : null;

	if ($id > 0) {
		$db->prepare("UPDATE tasks SET title = ?, due_date = ?, notes = ?, assigned_to = ?, assigned_to_name = ?, task_type = ?, task_meta = ?, updated_at = NOW() WHERE id = ?")
		   ->execute([$title, $dueVal, ($notes === '' ? null : $notes), $assignedTo, $assignedName, $type, $metaJson, $id]);
	} else {
		$db->prepare("INSERT INTO tasks (title, due_date, notes, created_by, created_by_name, assigned_to, assigned_to_name, task_type, task_meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
		   ->execute([$title, $dueVal, ($notes === '' ? null : $notes), $uid, $uname, $assignedTo, $assignedName, $type, $metaJson]);
		$id = (int)$db->lastInsertId();
	}

	// Regenerate the assignee's welcome message on their next dashboard load.
	if ($assignedTo) briefing_touch($db);

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

	$typeLabel = $type === 'tradeshow' ? 'Tradeshow build & pack' : ($type === 'inv_count' ? 'Inventory count' : '');

	echo json_encode(['ok' => true, 'task' => [
		'id' => $id, 'title' => $title, 'due_date' => $dueVal,
		'due_label' => $dl, 'due_class' => $dc, 'due_badge' => $badge,
		'assigned_to' => $assignedTo, 'assigned_to_name' => $assignedName,
		'task_type' => $type, 'type_label' => $typeLabel, 'meta_summary' => $metaSummary,
	]]);
