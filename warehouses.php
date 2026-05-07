<?php
	require_once(__DIR__.'/includes/fns.php');
	require_login();
	if (!has_access('users')) { deny_access(); }

	$db = db_connect();

	// AJAX actions
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$action = $_POST['action'] ?? '';

		if ($action === 'save') {
			$name     = trim($_POST['name'] ?? '');
			$location = trim($_POST['location'] ?? '');
			$id       = (int)($_POST['id'] ?? 0);
			if ($name) {
				if ($id) {
					$stmt = $db->prepare("UPDATE warehouses SET name=?, location=? WHERE id=?");
					$stmt->execute([$name, $location ?: null, $id]);
				} else {
					$stmt = $db->prepare("INSERT INTO warehouses (name, location) VALUES (?,?)");
					$stmt->execute([$name, $location ?: null]);
				}
			}
			echo 'ok'; exit;
		}

		if ($action === 'toggle') {
			$id = (int)($_POST['id'] ?? 0);
			if ($id) $db->exec("UPDATE warehouses SET active = 1 - active WHERE id = $id");
			echo 'ok'; exit;
		}

		echo 'error'; exit;
	}

	$whs = $db->query("
		SELECT w.*,
		    COALESCE(SUM(pwq.qty * p.cost), 0) AS oh_val,
		    COUNT(DISTINCT pwq.part_id) AS part_count
		FROM warehouses w
		LEFT JOIN part_warehouse_qty pwq ON pwq.warehouse_id = w.id AND pwq.qty > 0
		LEFT JOIN parts p ON p.id = pwq.part_id
		GROUP BY w.id
		ORDER BY w.name ASC
	")->fetchAll();

	require_once(__DIR__.'/includes/header.php');
?>

<div class="d-flex align-items-center justify-content-between mb-3">
	<h4 class="mb-0 fw-bold">Warehouse Manager</h4>
	<button class="btn btn-primary btn-sm" id="addWHBtn"><i class="ti ti-plus me-1"></i>Add Warehouse</button>
</div>

<!-- Add new warehouse form -->
<div id="addWHArea" class="hidden mb-3">
<div class="card">
<div class="card-body">
	<div class="d-flex gap-3 flex-wrap align-items-end">
		<div>
			<label class="form-label small fw-semibold text-muted">Warehouse Name <span class="text-danger">*</span></label>
			<input type="text" id="newWHName" class="form-control form-control-sm" style="width:220px;" placeholder="e.g. East Warehouse" />
		</div>
		<div>
			<label class="form-label small fw-semibold text-muted">Location / Address</label>
			<input type="text" id="newWHLocation" class="form-control form-control-sm" style="width:300px;" placeholder="Optional" />
		</div>
		<button class="btn btn-primary btn-sm" id="addWHSubmit">Add Warehouse</button>
		<button class="btn btn-secondary btn-sm" id="addWHCancel">Cancel</button>
	</div>
</div>
</div>
</div>

<!-- Warehouse table -->
<div class="card">
<div class="card-body p-0">
<table class="table dash-table mb-0">
	<thead><tr>
		<th>Warehouse</th>
		<th>Location</th>
		<th class="text-center">Parts Stocked</th>
		<th class="text-end">Inventory Value</th>
		<th class="text-center">Status</th>
		<th></th>
	</tr></thead>
	<tbody>
	<?php if (empty($whs)): ?>
	<tr><td colspan="6" class="text-muted text-center py-4">No warehouses yet. Add one above.</td></tr>
	<?php endif; ?>

	<?php foreach ($whs as $wh): ?>
	<tr>
		<td class="fw-semibold <?php echo $wh['active'] ? '' : 'text-muted'; ?>"><?php echo htmlspecialchars($wh['name']); ?></td>
		<td class="text-muted small"><?php echo htmlspecialchars($wh['location'] ?? '—'); ?></td>
		<td class="text-center"><?php echo number_format($wh['part_count']); ?></td>
		<td class="text-end fw-semibold">$<?php echo number_format($wh['oh_val'], 2); ?></td>
		<td class="text-center">
			<?php if ($wh['active']): ?>
			<span style="background:#ecfdf5;color:#065f46;font-size:0.7rem;padding:2px 10px;border-radius:20px;font-weight:600;">Active</span>
			<?php else: ?>
			<span style="background:#f1f3f5;color:#6c757d;font-size:0.7rem;padding:2px 10px;border-radius:20px;font-weight:600;">Inactive</span>
			<?php endif; ?>
		</td>
		<td class="text-end">
			<button class="btn btn-sm btn-light-primary edit-wh-btn"
				data-id="<?php echo $wh['id']; ?>"
				data-name="<?php echo htmlspecialchars($wh['name'], ENT_QUOTES); ?>"
				data-location="<?php echo htmlspecialchars($wh['location'] ?? '', ENT_QUOTES); ?>">
				Edit
			</button>
		</td>
	</tr>

	<!-- Inline edit row -->
	<tr class="wh-edit-row" id="editRow<?php echo $wh['id']; ?>" style="display:none;">
	<td colspan="6" class="p-0" style="border-top:none;">
	<div class="manage-area">
		<div class="d-flex gap-3 flex-wrap align-items-end">
			<div>
				<label class="form-label small fw-semibold text-muted">Warehouse Name <span class="text-danger">*</span></label>
				<input type="text" class="form-control form-control-sm edit-wh-name" style="width:220px;"
					value="<?php echo htmlspecialchars($wh['name'], ENT_QUOTES); ?>" />
			</div>
			<div>
				<label class="form-label small fw-semibold text-muted">Location / Address</label>
				<input type="text" class="form-control form-control-sm edit-wh-location" style="width:300px;"
					value="<?php echo htmlspecialchars($wh['location'] ?? '', ENT_QUOTES); ?>" placeholder="Optional" />
			</div>
			<button class="btn btn-primary btn-sm save-wh-btn" data-id="<?php echo $wh['id']; ?>">Save</button>
			<button class="btn btn-secondary btn-sm close-edit-btn">Cancel</button>
			<div class="ms-3">
				<button class="btn btn-sm <?php echo $wh['active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?> toggle-wh-btn"
					data-id="<?php echo $wh['id']; ?>"
					data-active="<?php echo $wh['active']; ?>">
					<?php echo $wh['active'] ? 'Deactivate' : 'Activate'; ?>
				</button>
			</div>
		</div>
	</div>
	</td>
	</tr>
	<?php endforeach; ?>

	</tbody>
</table>
</div>
</div>

<script>
// Add new warehouse
$('#addWHBtn').on('click', function() {
	$('#addWHArea').show();
	$('#newWHName').focus();
	$(this).hide();
});
$('#addWHCancel').on('click', function() {
	$('#addWHArea').hide();
	$('#addWHBtn').show();
});
$('#addWHSubmit').on('click', function() {
	var name = $('#newWHName').val().trim();
	if (!name) { alert('Warehouse name is required.'); return; }
	$(this).prop('disabled', true).text('Saving…');
	$.post('', { action: 'save', id: 0, name: name, location: $('#newWHLocation').val() }, function() {
		location.reload();
	});
});

// Open inline edit row
$(document).on('click', '.edit-wh-btn', function() {
	var id = $(this).data('id');
	// Close any other open rows
	$('.wh-edit-row').hide();
	$('#editRow' + id).show();
});

// Close inline edit row
$(document).on('click', '.close-edit-btn', function() {
	$(this).closest('.wh-edit-row').hide();
});

// Save edit
$(document).on('click', '.save-wh-btn', function() {
	var $btn  = $(this);
	var id    = $btn.data('id');
	var $row  = $('#editRow' + id);
	var name  = $row.find('.edit-wh-name').val().trim();
	var loc   = $row.find('.edit-wh-location').val().trim();
	if (!name) { alert('Warehouse name is required.'); return; }
	$btn.prop('disabled', true).text('Saving…');
	$.post('', { action: 'save', id: id, name: name, location: loc }, function() {
		location.reload();
	});
});

// Toggle active/inactive
$(document).on('click', '.toggle-wh-btn', function() {
	var $btn   = $(this);
	var id     = $btn.data('id');
	var active = $btn.data('active');
	var label  = active == 1 ? 'Deactivate' : 'Activate';
	if (!confirm(label + ' this warehouse?')) return;
	$btn.prop('disabled', true);
	$.post('', { action: 'toggle', id: id }, function() {
		location.reload();
	});
});
</script>

<?php require_once(__DIR__.'/includes/footer.php'); ?>
