<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");

	$db = db_connect();
	tasks_ensure_table($db);

	$isManager = in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true);
	$users        = $isManager ? active_users_list($db) : [];
	$partsForCount = $isManager ? $db->query("SELECT id, partno, `desc` FROM parts ORDER BY partno ASC")->fetchAll() : [];

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

	// Assignee chip + type badge for a task row (server-rendered).
	function task_badges_html($t) {
		$h = '';
		if (!empty($t['assigned_to_name'])) {
			$h .= '<span class="badge bg-light text-dark border me-1"><i class="ti ti-user"></i> ' . htmlspecialchars($t['assigned_to_name']) . '</span>';
		}
		$tt = $t['task_type'] ?? 'general';
		if ($tt === 'tradeshow' || $tt === 'inv_count') {
			$meta = json_decode($t['task_meta'] ?? '', true);
			$sum  = '';
			if ($tt === 'tradeshow' && !empty($meta['shows'])) $sum = implode(', ', $meta['shows']);
			if ($tt === 'inv_count' && !empty($meta['partno'])) $sum = $meta['partno'];
			$lbl  = $tt === 'tradeshow' ? 'Tradeshow' : 'Count';
			$icon = $tt === 'tradeshow' ? 'ti-tent' : 'ti-clipboard-list';
			$h .= '<span class="badge bg-info text-dark me-1"><i class="ti ' . $icon . '"></i> ' . $lbl . ($sum !== '' ? ': ' . htmlspecialchars($sum) : '') . '</span>';
		}
		return $h === '' ? '' : '<div class="small mt-1">' . $h . '</div>';
	}
?>

<div class="container-fluid" style="max-width:820px;">

	<div class="d-flex align-items-center justify-content-between mb-3 mt-2">
		<div>
			<h4 class="mb-0"><i class="ti ti-checklist me-1"></i>Task List</h4>
			<small class="text-muted">Things to do — add a due date, check off when done.<?php echo $isManager ? ' Assign a task to someone and it shows in their dashboard welcome with live stats.' : ''; ?></small>
		</div>
		<span class="badge bg-primary fs-6" id="open-count"><?php echo count($open); ?> open</span>
	</div>

	<!-- Add task -->
	<div class="card mb-3"><div class="card-body py-2">
		<form id="add-form">
			<div class="row g-2 align-items-center">
				<div class="col-12 col-md">
					<input type="text" class="form-control" id="new-title" placeholder="Add a task…" maxlength="255" autocomplete="off">
				</div>
				<div class="col-8 col-md-auto">
					<input type="date" class="form-control" id="new-due" title="Due date (optional)">
				</div>
				<div class="col-4 col-md-auto">
					<button type="submit" class="btn btn-primary w-100"><i class="ti ti-plus"></i> Add</button>
				</div>
			</div>

			<?php if ($isManager): ?>
			<div class="row g-2 align-items-center mt-1">
				<div class="col-12 col-md-auto">
					<div class="input-group input-group-sm" style="min-width:210px;">
						<span class="input-group-text"><i class="ti ti-user"></i></span>
						<select class="form-select" id="new-assign">
							<option value="">Assign to… (optional)</option>
							<?php foreach ($users as $u): ?>
							<option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['name']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
				<div class="col-12 col-md-auto">
					<div class="input-group input-group-sm" style="min-width:230px;">
						<span class="input-group-text">Type</span>
						<select class="form-select" id="new-type">
							<option value="general">General</option>
							<option value="tradeshow">Tradeshow build &amp; pack</option>
							<option value="inv_count">Inventory count</option>
						</select>
					</div>
				</div>

				<!-- Tradeshow show picker (lazy-loaded) -->
				<div class="col-12 type-extra" id="extra-tradeshow" style="display:none;">
					<div class="border rounded p-2 bg-light">
						<div class="small fw-semibold text-muted mb-1"><i class="ti ti-tent me-1"></i>Which show(s)? Last year's sales at these drive the build/pack estimate in the assignee's briefing.</div>
						<div id="show-picker" class="d-flex flex-wrap gap-2"><span class="text-muted small">Pick “Tradeshow build &amp; pack” to load shows…</span></div>
					</div>
				</div>

				<!-- Inventory-count part picker -->
				<div class="col-12 col-md-auto type-extra" id="extra-inv_count" style="display:none;">
					<div class="input-group input-group-sm" style="min-width:300px;">
						<span class="input-group-text"><i class="ti ti-clipboard-list"></i></span>
						<select class="form-select" id="new-part">
							<option value="">Select a part to count…</option>
							<?php foreach ($partsForCount as $p): ?>
							<option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['partno'] . ' — ' . $p['desc']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>
			<?php endif; ?>
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
						<?php echo task_badges_html($t); ?>
					</div>
					<button class="btn btn-sm btn-link text-muted task-del" title="Delete"><i class="ti ti-trash"></i></button>
				</div>
			<?php endforeach; endif; ?>
		</div>
	</div></div>

	<!-- Completed -->
	<details class="mb-4">
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
function taskBadges(t) {
	var h = '';
	if (t.assigned_to_name) h += '<span class="badge bg-light text-dark border me-1"><i class="ti ti-user"></i> ' + $('<div>').text(t.assigned_to_name).html() + '</span>';
	if (t.task_type === 'tradeshow' || t.task_type === 'inv_count') {
		var lbl = t.task_type === 'tradeshow' ? 'Tradeshow' : 'Count';
		var ic  = t.task_type === 'tradeshow' ? 'ti-tent' : 'ti-clipboard-list';
		var sum = t.meta_summary ? ': ' + $('<div>').text(t.meta_summary).html() : '';
		h += '<span class="badge bg-info text-dark me-1"><i class="ti ' + ic + '"></i> ' + lbl + sum + '</span>';
	}
	return h ? '<div class="small mt-1">' + h + '</div>' : '';
}

function taskRowHtml(t) {
	var due = '';
	if (t.due_label) due = '<div class="small ' + t.due_class + '"><i class="ti ti-calendar-event"></i> ' + t.due_label + (t.due_badge ? ' · ' + t.due_badge : '') + '</div>';
	return '<div class="task-row d-flex align-items-center py-2 border-bottom" data-id="' + t.id + '">' +
		'<input type="checkbox" class="form-check-input me-3 task-check" style="width:1.15rem;height:1.15rem;cursor:pointer;">' +
		'<div class="flex-grow-1"><div class="task-title">' + $('<div>').text(t.title).html() + '</div>' + due + taskBadges(t) + '</div>' +
		'<button class="btn btn-sm btn-link text-muted task-del" title="Delete"><i class="ti ti-trash"></i></button></div>';
}

function bumpCount(d) {
	var $c = $('#open-count'); var n = Math.max(0, parseInt($c.text()) + d);
	$c.text(n + ' open');
}

// Toggle type-specific pickers.
$('#new-type').on('change', function() {
	var t = $(this).val();
	$('.type-extra').hide();
	if (t === 'tradeshow') { $('#extra-tradeshow').show(); loadShows(); }
	else if (t === 'inv_count') { $('#extra-inv_count').show(); }
});

var showsLoaded = false;
function loadShows() {
	if (showsLoaded) return;
	var $p = $('#show-picker').html('<span class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Loading shows…</span>');
	$.getJSON('/ajax/tasks/show_list.php', function(res) {
		showsLoaded = true;
		$p.empty();
		if (!res.shows || !res.shows.length) { $p.html('<span class="text-muted small">' + $('<div>').text(res.note || 'No shows found.').html() + '</span>'); return; }
		res.shows.forEach(function(s, i) {
			var id = 'show-cb-' + i;
			var nm = $('<div>').text(s.name).html();
			$p.append('<div class="form-check form-check-inline"><input class="form-check-input show-cb" type="checkbox" id="' + id + '" value="' + nm + '"><label class="form-check-label small" for="' + id + '">' + nm + '</label></div>');
		});
	}).fail(function() { $p.html('<span class="text-danger small">Could not load shows.</span>'); });
}

$('#add-form').on('submit', function(e) {
	e.preventDefault();
	var title = $('#new-title').val().trim();
	if (!title) return;
	var data = { title: title, due_date: $('#new-due').val() };

	var type = $('#new-type').val() || 'general';
	data.task_type = type;
	data.assigned_to = $('#new-assign').val() || '';
	if (type === 'tradeshow') {
		var shows = $('#show-picker input.show-cb:checked').map(function() { return $(this).val(); }).get();
		if (!shows.length) { alert('Pick at least one show for a Tradeshow task.'); return; }
		data.task_meta = JSON.stringify({ shows: shows });
	} else if (type === 'inv_count') {
		var pid = parseInt($('#new-part').val());
		if (!pid) { alert('Pick a part to count.'); return; }
		data.task_meta = JSON.stringify({ part_id: pid });
	}

	$.post('/ajax/tasks/save.php', data, function(res) {
		if (res.ok) {
			$('#empty-open').remove();
			$('#open-list').prepend(taskRowHtml(res.task));
			$('#new-title').val(''); $('#new-due').val('');
			$('#new-assign').val(''); $('#new-type').val('general').trigger('change');
			$('#new-part').val(''); $('#show-picker input.show-cb:checked').prop('checked', false);
			bumpCount(1);
		} else { alert(res.error || 'Could not add task.'); }
	}, 'json');
});

$(document).on('change', '.task-check', function() {
	var $row = $(this).closest('.task-row');
	var id = $row.data('id');
	var done = this.checked ? 1 : 0;
	$.post('/ajax/tasks/toggle.php', { id: id, completed: done }, function(res) {
		if (!res.ok) { alert(res.error || 'Update failed.'); return; }
		$row.fadeOut(150, function() { $(this).remove(); });
		bumpCount(done ? -1 : 1);
	}, 'json');
});

$(document).on('click', '.task-del', function() {
	var $row = $(this).closest('.task-row');
	var id = $row.data('id');
	var wasOpen = !$row.find('.task-check').prop('checked');
	if (!confirm('Delete this task?')) return;
	$.post('/ajax/tasks/delete.php', { id: id }, function(res) {
		if (!res.ok) { alert(res.error || 'Delete failed.'); return; }
		$row.fadeOut(150, function() { $(this).remove(); });
		if (wasOpen) bumpCount(-1);
	}, 'json');
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
