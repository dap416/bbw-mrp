<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();

	$db      = db_connect();
	phys_inv_ensure_tables($db);

	$batchId = (int)($_GET['batch'] ?? 0);
	$bs = $db->prepare("SELECT * FROM phys_inv_batches WHERE id = ?");
	$bs->execute([$batchId]);
	$batch = $bs->fetch();

	$items = [];
	if ($batch) {
		$is = $db->prepare("SELECT * FROM phys_inv_batch_items WHERE batch_id = ? ORDER BY (diff <> 0) DESC, partno ASC");
		$is->execute([$batchId]);
		$items = $is->fetchAll();
	}
	$canEdit  = can_edit('inventory');
	$isMaster = is_master();

	require_once(__DIR__."/includes/header.php");
?>

<div class="container-fluid">

	<?php if (!$batch): ?>
		<div class="alert alert-danger mt-3">Count report not found. <a href="/physical_inventory.php">Back to Physical Inventory</a></div>
	<?php else:
		$status   = $batch['status'];
		$isPending= $status === 'pending';
		$variances = array_values(array_filter($items, fn($i) => (int)$i['diff'] !== 0));
		$matches   = count($items) - count($variances);
	?>

	<!-- Header -->
	<div class="d-flex align-items-center justify-content-between mb-3 mt-2">
		<div>
			<h4 class="mb-0">Physical Count Report <span class="text-muted">#<?php echo (int)$batch['id']; ?></span></h4>
			<small class="text-muted">Review what was counted before any inventory changes are made.</small>
		</div>
		<div>
			<?php if ($status === 'applied'): ?>
				<span class="badge bg-success fs-6"><i class="ti ti-check me-1"></i>Applied</span>
			<?php elseif ($status === 'discarded'): ?>
				<span class="badge bg-secondary fs-6">Discarded</span>
			<?php else: ?>
				<span class="badge bg-warning text-dark fs-6"><i class="ti ti-clock me-1"></i>Awaiting confirmation</span>
			<?php endif; ?>
		</div>
	</div>

	<!-- Meta -->
	<div class="card mb-3"><div class="card-body py-2">
		<div class="row g-3">
			<div class="col-6 col-md-3"><div class="text-muted small">Warehouse</div><div class="fw-bold"><?php echo htmlspecialchars($batch['warehouse_name'] ?: ('#'.$batch['warehouse_id'])); ?></div></div>
			<div class="col-6 col-md-3"><div class="text-muted small">Counted by</div><div class="fw-bold"><?php echo htmlspecialchars($batch['user_name'] ?: '—'); ?></div></div>
			<div class="col-6 col-md-3"><div class="text-muted small">Submitted</div><div class="fw-bold"><?php echo htmlspecialchars($batch['created_at']); ?></div></div>
			<div class="col-6 col-md-3"><div class="text-muted small">Parts counted</div><div class="fw-bold"><?php echo (int)$batch['total_parts']; ?></div></div>
		</div>
		<?php if ($status === 'applied'): ?>
		<div class="mt-2 small text-success">Applied <?php echo htmlspecialchars($batch['applied_at']); ?> by <?php echo htmlspecialchars($batch['applied_by_name'] ?: '—'); ?> — <?php echo (int)$batch['adjusted_parts']; ?> part(s) adjusted.</div>
		<?php endif; ?>
	</div></div>

	<!-- Summary -->
	<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
		<span class="badge bg-danger fs-6"><?php echo count($variances); ?> change<?php echo count($variances) !== 1 ? 's' : ''; ?> to apply</span>
		<span class="badge bg-success fs-6"><?php echo $matches; ?> already match</span>
	</div>

	<!-- Action bar -->
	<?php if ($isPending): ?>
	<div class="d-flex align-items-center justify-content-between mb-3 p-3 bg-light rounded border flex-wrap gap-2">
		<div class="text-muted">
			<i class="ti ti-alert-triangle text-warning me-1"></i>
			Inventory has <strong>not</strong> changed yet. Review the counts below, then confirm to apply them.
		</div>
		<div class="d-flex gap-2">
			<?php if ($isMaster): ?>
			<button class="btn btn-outline-secondary" id="discard-btn">Discard</button>
			<button class="btn btn-success btn-lg" id="confirm-btn" data-batch="<?php echo (int)$batch['id']; ?>">
				<i class="ti ti-database-import me-1"></i> Confirm and Change Inventory
			</button>
			<?php elseif ($canEdit): ?>
			<span class="text-muted small">Only a master admin can confirm or discard this count.</span>
			<?php else: ?>
			<span class="text-muted small">You have view-only access — ask a master admin to confirm.</span>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<!-- Variances -->
	<div class="card mb-3">
		<div class="card-header py-2"><strong>Changes</strong> <span class="text-muted ms-2">(parts whose count differs from the system)</span></div>
		<div class="table-responsive">
			<table class="table table-sm table-hover mb-0">
				<thead class="table-light"><tr>
					<th style="width:160px;">Part #</th><th>Description</th>
					<th class="text-end" style="width:110px;">System QOH</th>
					<th class="text-end" style="width:110px;">Counted</th>
					<th class="text-end" style="width:110px;">Variance</th>
				</tr></thead>
				<tbody>
				<?php if (empty($variances)): ?>
					<tr><td colspan="5" class="text-center text-muted py-3">No variances — every counted part matches the system.</td></tr>
				<?php else: foreach ($variances as $it): $d = (int)$it['diff']; ?>
					<tr>
						<td class="font-monospace"><?php echo htmlspecialchars($it['partno']); ?></td>
						<td><?php echo htmlspecialchars($it['pdesc']); ?></td>
						<td class="text-end"><?php echo (int)$it['qoh_at_count']; ?></td>
						<td class="text-end fw-bold"><?php echo (int)$it['counted']; ?></td>
						<td class="text-end fw-bold <?php echo $d > 0 ? 'text-primary' : 'text-danger'; ?>"><?php echo ($d > 0 ? '+' : '') . $d; ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Full count (collapsible) -->
	<div class="card mb-4">
		<div class="card-header py-2 d-flex align-items-center justify-content-between" style="cursor:pointer;" onclick="$('#full-count').toggle();$('#full-arrow').toggleClass('ti-chevron-right ti-chevron-down');">
			<div><i class="ti ti-chevron-right me-2" id="full-arrow"></i><strong>Full count</strong> <span class="text-muted ms-2">(all <?php echo count($items); ?> parts)</span></div>
		</div>
		<div id="full-count" style="display:none;">
			<div class="table-responsive">
				<table class="table table-sm table-hover mb-0">
					<thead class="table-light"><tr>
						<th style="width:160px;">Part #</th><th>Description</th>
						<th class="text-end" style="width:110px;">System QOH</th>
						<th class="text-end" style="width:110px;">Counted</th>
						<th class="text-end" style="width:110px;">Variance</th>
					</tr></thead>
					<tbody>
					<?php foreach ($items as $it): $d = (int)$it['diff']; ?>
						<tr>
							<td class="font-monospace"><?php echo htmlspecialchars($it['partno']); ?></td>
							<td><?php echo htmlspecialchars($it['pdesc']); ?></td>
							<td class="text-end"><?php echo (int)$it['qoh_at_count']; ?></td>
							<td class="text-end"><?php echo (int)$it['counted']; ?></td>
							<td class="text-end <?php echo $d === 0 ? 'text-success' : ($d > 0 ? 'text-primary fw-bold' : 'text-danger fw-bold'); ?>"><?php echo $d === 0 ? '0' : (($d > 0 ? '+' : '') . $d); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<a href="/physical_inventory.php" class="btn btn-link">&larr; Back to Physical Inventory</a>

	<?php endif; ?>
</div>

<!-- Confirm modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header"><h5 class="modal-title">Confirm and Change Inventory</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
			<div class="modal-body">
				<p>This will adjust warehouse quantities for the variances in this report. This cannot be undone automatically.</p>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-success" id="apply-yes">Yes, change inventory</button>
			</div>
		</div>
	</div>
</div>

<script>
var BATCH_ID = <?php echo (int)($batch['id'] ?? 0); ?>;
$('#confirm-btn').on('click', function(){ $('#applyModal').modal('show'); });

$('#apply-yes').on('click', function(){
	var $btn = $(this); $btn.prop('disabled', true).text('Applying…');
	var batch = $('#confirm-btn').data('batch');
	$.post('/ajax/physical_inv_confirm.php', { batch_id: BATCH_ID }, function(resp){
		if (resp.ok) { window.location = '/physical_inventory.php?applied=' + resp.adjusted; }
		else { alert('Error: ' + (resp.error || 'Unknown error')); $btn.prop('disabled', false).text('Yes, change inventory'); }
	}, 'json').fail(function(){ alert('Request failed. Please try again.'); $btn.prop('disabled', false).text('Yes, change inventory'); });
});

$('#discard-btn').on('click', function(){
	if (!confirm('Discard this count without changing inventory?')) return;
	var batch = $('#confirm-btn').data('batch');
	$.post('/ajax/physical_inv_discard.php', { batch_id: BATCH_ID }, function(resp){
		if (resp.ok) { window.location = '/physical_inventory.php'; }
		else { alert('Error: ' + (resp.error || 'Unknown error')); }
	}, 'json').fail(function(){ alert('Request failed. Please try again.'); });
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
