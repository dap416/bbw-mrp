<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");

	$db = db_connect();
	tasks_ensure_table($db);

	$open = $db->query("SELECT * FROM tasks WHERE completed = 0 ORDER BY (due_date IS NULL), due_date ASC, created_at ASC")->fetchAll();
	$done = $db->query("SELECT * FROM tasks WHERE completed = 1 ORDER BY completed_at DESC LIMIT 100")->fetchAll();

	function task_due_meta($due) {
		if (empty($due) || $due === '0000-00-00') return ['', 'text-muted', ''];
		$ts = strtotime($due); $today = strtotime(date('Y-m-d'));
		$days = (int)floor(($ts - $today) / 86400);
		$lbl = date('D, M j', $ts);
		if ($days < 0)      return [$lbl, 'text-danger fw-semibold', 'Overdue ' . abs($days) . 'd'];
		if ($days === 0)    return [$lbl, 'text-danger fw-semibold', 'Today'];
		if ($days === 1)    return [$lbl, 'text-warning fw-semibold', 'Tomorrow'];
		if ($days <= 7)     return [$lbl, 'text-warning', 'In ' . $days . 'd'];
		return [$lbl, 'text-muted', 'In ' . $days . 'd'];
	}
?>

<div class="container-fluid" style="max-width:820px;">

	<div class="d-flex align-items-center justify-content-between mb-3 mt-2">
		<div>
			<h4 class="mb-0"><i class="ti ti-checklist me-1"></i>Task List</h4>
			<small class="text-muted">Things to do — add a due date, check off when done.</small>
		</div>
		<span class="badge bg-primary fs-6" id="open-count"><?php echo count($open); ?> open</span>
	</div>

	<!-- Add task -->
	<div class="card mb-3"><div class="card-body py-2">
		<form id="add-form" class="row g-2 align-items-center">
			<div class="col-12 col-md">
				<input type="text" class="form-control" id="new-title" placeholder="Add a task…" maxlength="255" autocomplete="off">
			</div>
			<div class="col-8 col-md-auto">
				<input type="date" class="form-control" id="new-due" title="Due date (optional)">
			</div>
			<div class="col-4 col-md-auto">
				<button type="submit" class="btn btn-primary w-100"><i class="ti ti-plus"></i> Add</button>
			</div>
		</form>
	</div></div>

	<!-- Open tasks -->
	<div class="card mb-3"><div class="card-body">
		<div class="fw-semibold text-uppercase text-muted mb-2" style="font-size:0.7rem;letter-spacing:.05em;">To do</div>
		<div id="open-list">
			<?php if (empty($open)): ?>
				<div class="text-muted small py-2" id="empty-open">Nothing to do — you're all caught up. 🎉</div>
			<?php else: foreach ($open as $t): list($dl,$dc,$badge) = task_due_meta($t['due_date']); ?>
				<div class="task-row d-flex align-items-center py-2 border-bottom" data-id="<?php echo (int)$t['id']; ?>">
					<input type="checkbox" class="form-check-input me-3 task-check" style="width:1.15rem;height:1.15rem;cursor:pointer;">
					<div class="flex-grow-1">
						<div class="task-title"><?php echo htmlspecialchars($t['title']); ?></div>
						<?php if ($dl): ?><div class="small <?php echo $dc; ?>"><i class="ti ti-calendar-event"></i> <?php echo $dl; ?><?php echo $badge ? ' · ' . $badge : ''; ?></div><?php endif; ?>
					</div>
					<button class="btn btn-sm btn-link text-muted task-del" title="Delete"><i class="ti ti-trash"></i></button>
				</div>
			<?php endforeach; endif; ?>
		</div>
	</div></div>

	<!-- Completed -->
	<details class="mb-4" <?php echo empty($done) ? '' : ''; ?>>
		<summary class="btn btn-sm btn-light-secondary">Completed (<?php echo count($done); ?>)</summary>
		<div class="card mt-2"><div class="card-body">
			<div id="done-list">
				<?php if (empty($done)): ?>
					<div class="text-muted small" id="empty-done">No completed tasks yet.</div>
				<?php else: foreach ($done as $t): ?>
					<div class="task-row d-flex align-items-center py-2 border-bottom" data-id="<?php echo (int)$t['id']; ?>">
						<input type="checkbox" class="form-check-input me-3 task-check" checked style="width:1.15rem;height:1.15rem;cursor:pointer;">
						<div class="flex-grow-1">
							<div class="task-title text-muted text-decoration-line-through"><?php echo htmlspecialchars($t['title']); ?></div>
							<div class="small text-muted">Done <?php echo $t['completed_at'] ? date('M j', strtotime($t['completed_at'])) : ''; ?></div>
						</div>
						<button class="btn btn-sm btn-link text-muted task-del" title="Delete"><i class="ti ti-trash"></i></button>
					</div>
				<?php endforeach; endif; ?>
			</div>
		</div></div>
	</details>

</div>

<script>
function taskRowHtml(t) {
	var due = '';
	if (t.due_label) due = '<div class="small ' + t.due_class + '"><i class="ti ti-calendar-event"></i> ' + t.due_label + (t.due_badge ? ' · ' + t.due_badge : '') + '</div>';
	return '<div class="task-row d-flex align-items-center py-2 border-bottom" data-id="' + t.id + '">' +
		'<input type="checkbox" class="form-check-input me-3 task-check" style="width:1.15rem;height:1.15rem;cursor:pointer;">' +
		'<div class="flex-grow-1"><div class="task-title">' + $('<div>').text(t.title).html() + '</div>' + due + '</div>' +
		'<button class="btn btn-sm btn-link text-muted task-del" title="Delete"><i class="ti ti-trash"></i></button></div>';
}

function bumpCount(d) {
	var $c = $('#open-count'); var n = Math.max(0, parseInt($c.text()) + d);
	$c.text(n + ' open');
}

$('#add-form').on('submit', function(e){
	e.preventDefault();
	var title = $('#new-title').val().trim();
	if (!title) return;
	var due = $('#new-due').val();
	$.post('/ajax/tasks/save.php', { title: title, due_date: due }, function(res){
		if (res.ok) {
			$('#empty-open').remove();
			$('#open-list').prepend(taskRowHtml(res.task));
			$('#new-title').val(''); $('#new-due').val('');
			bumpCount(1);
		} else { alert(res.error || 'Could not add task.'); }
	}, 'json');
});

$(document).on('change', '.task-check', function(){
	var $row = $(this).closest('.task-row');
	var id = $row.data('id');
	var done = this.checked ? 1 : 0;
	$.post('/ajax/tasks/toggle.php', { id: id, completed: done }, function(res){
		if (!res.ok) { alert(res.error || 'Update failed.'); return; }
		$row.fadeOut(150, function(){ $(this).remove(); });
		bumpCount(done ? -1 : 1);
	}, 'json');
});

$(document).on('click', '.task-del', function(){
	var $row = $(this).closest('.task-row');
	var id = $row.data('id');
	var wasOpen = !$row.find('.task-check').prop('checked');
	if (!confirm('Delete this task?')) return;
	$.post('/ajax/tasks/delete.php', { id: id }, function(res){
		if (!res.ok) { alert(res.error || 'Delete failed.'); return; }
		$row.fadeOut(150, function(){ $(this).remove(); });
		if (wasOpen) bumpCount(-1);
	}, 'json');
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
