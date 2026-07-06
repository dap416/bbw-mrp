<?php
/** Mark a task complete / incomplete. */
	require_once(__DIR__."/../../includes/fns.php");
	require_login();
	header('Content-Type: application/json');

	$db = db_connect();
	tasks_ensure_table($db);

	$id   = (int)($_POST['id'] ?? 0);
	$done = (int)($_POST['completed'] ?? 0) ? 1 : 0;
	if ($id <= 0) { echo json_encode(['error' => 'Missing task id.']); exit; }

	if ($done) $db->prepare("UPDATE tasks SET completed = 1, completed_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$id]);
	else       $db->prepare("UPDATE tasks SET completed = 0, completed_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);

	$applied = [];
	if ($done) {
		briefing_touch($db);   // refresh the dashboard welcome on completion
		// Charles hook: completing a Charles task means George actually did the move —
		// apply its book updates to the cash-flow model, exactly once.
		try {
			$trow = $db->query("SELECT title, task_type, task_meta FROM tasks WHERE id = " . (int)$id)->fetch();
			if ($trow && ($trow['task_type'] ?? '') === 'charles' && !empty($trow['task_meta'])) {
				$meta = json_decode($trow['task_meta'], true) ?: [];
				if (empty($meta['applied_at']) && !empty($meta['actions']) && is_array($meta['actions'])) {
					require_once(__DIR__."/../../includes/charles.php");
					foreach ($meta['actions'] as $act) $applied[] = charles_apply_action($db, $act);
					$meta['applied_at'] = date('Y-m-d H:i:s');
					$meta['apply_results'] = $applied;
					$db->prepare("UPDATE tasks SET task_meta = ? WHERE id = ?")->execute([json_encode($meta), $id]);
					charles_memory_append($db, 'done', 'Completed "' . ($trow['title'] ?? 'task') . '": ' . implode('; ', $applied));
					try { $db->exec("DELETE FROM data_cache WHERE ckey = 'charles_brief'"); } catch (Throwable $e) {}
				}
			}
		} catch (Throwable $e) {}
	}

	echo json_encode(['ok' => true, 'applied' => $applied]);
