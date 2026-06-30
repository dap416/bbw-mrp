<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();

	$db = db_connect();
	$warehouses = get_warehouses($db);

	// Default warehouse: Arkansas
	$defaultWH = 0;
	foreach ($warehouses as $wh) {
		if (stripos($wh['name'], 'arkansas') !== false) { $defaultWH = $wh['id']; break; }
	}
	if (!$defaultWH && !empty($warehouses)) $defaultWH = $warehouses[0]['id'];

	// Warehouse filter — persist in session
	if (isset($_GET['wh'])) {
		$_SESSION['physinv_wh'] = (int)$_GET['wh'];
	}
	$activeWH = (int)($_SESSION['physinv_wh'] ?? $defaultWH);

	// Ensure activeWH is valid
	$whIds = array_column($warehouses, 'id');
	if (!in_array($activeWH, $whIds) && !empty($whIds)) {
		$activeWH = $defaultWH;
		$_SESSION['physinv_wh'] = $activeWH;
	}
	$activeWHName = '';
	foreach ($warehouses as $wh) {
		if ($wh['id'] === $activeWH) { $activeWHName = $wh['name']; break; }
	}

	// Category definitions
	$categoryPrefixes = [
		'CDA' => 'Package Cards',
		'CD'  => 'Package Cards',
		'CS'  => 'Camshafts',
		'MC'  => 'Packaging',
		'PC'  => 'Packaging',
		'PL'  => 'Splash Plates',
		'RC'  => 'Packaging',
		'RD'  => 'Rods',
	];
	$categoryOrder = ['Camshafts', 'Package Cards', 'Packaging', 'Rods', 'Splash Plates'];

	function physGetCategory($partno, $prefixes) {
		foreach ($prefixes as $prefix => $category) {
			if (strpos(strtoupper($partno), strtoupper($prefix)) === 0) {
				return $category;
			}
		}
		return 'Other';
	}

	// Fetch all parts with warehouse qty
	$parts = $db->query("
		SELECT p.id, p.partno, p.desc, p.qoh,
		       COALESCE(w.qty, 0) AS wh_qty
		FROM parts p
		LEFT JOIN part_warehouse_qty w ON w.part_id = p.id AND w.warehouse_id = $activeWH
		ORDER BY p.partno ASC
	")->fetchAll();

	$grouped = [];
	foreach ($parts as $part) {
		$cat = physGetCategory($part['partno'], $categoryPrefixes);
		$grouped[$cat][] = $part;
	}
	$totalParts = count($parts);

	// Staged counts awaiting confirmation (across all warehouses).
	phys_inv_ensure_tables($db);
	$pendingBatches = $db->query("SELECT * FROM phys_inv_batches WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
	$appliedCount = isset($_GET['applied']) ? (int)$_GET['applied'] : null;

	require_once(__DIR__."/includes/header.php");
?>

<?php if ($appliedCount !== null): ?>
<div class="container-fluid"><div class="alert alert-success alert-dismissible fade show mt-2" role="alert">
	<i class="ti ti-check me-1"></i>Inventory updated — <?php echo $appliedCount; ?> part(s) adjusted from the confirmed count.
	<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div></div>
<?php endif; ?>

<?php if (!empty($pendingBatches)): ?>
<div class="container-fluid"><div class="card border-warning mb-3 mt-2"><div class="card-body py-2">
	<div class="fw-bold mb-2"><i class="ti ti-clock text-warning me-1"></i>Counts awaiting confirmation</div>
	<div class="table-responsive"><table class="table table-sm mb-0">
		<thead class="table-light"><tr><th>Report</th><th>Warehouse</th><th>Counted by</th><th>Submitted</th><th class="text-end">Changes</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($pendingBatches as $pb): ?>
			<tr>
				<td>#<?php echo (int)$pb['id']; ?></td>
				<td><?php echo htmlspecialchars($pb['warehouse_name'] ?: ('#'.$pb['warehouse_id'])); ?></td>
				<td><?php echo htmlspecialchars($pb['user_name'] ?: '—'); ?></td>
				<td class="text-muted small"><?php echo htmlspecialchars($pb['created_at']); ?></td>
				<td class="text-end"><span class="badge bg-danger"><?php echo (int)$pb['variance_parts']; ?></span></td>
				<td class="text-end"><a href="/physical_inv_report.php?batch=<?php echo (int)$pb['id']; ?>" class="btn btn-sm btn-outline-primary">Review &amp; Confirm</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
</div></div></div>
<?php endif; ?>

<div class="container-fluid">

	<!-- Page header -->
	<div class="d-flex align-items-center justify-content-between mb-3 mt-2">
		<div>
			<h4 class="mb-0">Physical Inventory</h4>
			<small class="text-muted">Count all parts and submit a report — you confirm before inventory changes</small>
		</div>
		<div class="text-end">
			<span class="badge bg-secondary fs-6" id="progress-badge">0 / <?php echo $totalParts; ?> counted</span>
		</div>
	</div>

	<!-- Warehouse tabs -->
	<?php if (count($warehouses) > 1): ?>
	<ul class="nav nav-pills mb-3">
		<?php foreach ($warehouses as $wh): ?>
		<li class="nav-item">
			<a class="nav-link <?php echo ($wh['id'] === $activeWH) ? 'active' : ''; ?>"
			   href="?wh=<?php echo $wh['id']; ?>">
				<?php echo htmlspecialchars($wh['name']); ?>
			</a>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>

	<!-- Submit bar (top) -->
	<div class="d-flex align-items-center justify-content-between mb-3 p-3 bg-light rounded border">
		<div>
			<strong>Warehouse:</strong> <?php echo htmlspecialchars($activeWHName); ?>
			&nbsp;&mdash;&nbsp;
			<span class="text-muted" id="variance-summary">No variances yet.</span>
		</div>
		<button class="btn btn-success" id="submit-top" onclick="confirmSubmit()">
			<i class="ti ti-clipboard-check me-1"></i> Submit Count for Review
		</button>
	</div>

	<!-- Parts table -->
	<form id="phys-inv-form">
		<input type="hidden" name="warehouse_id" value="<?php echo $activeWH; ?>">

		<?php foreach ($categoryOrder as $categoryName):
			if (empty($grouped[$categoryName])) continue;
			$catSlug = strtolower(preg_replace('/[^a-z0-9]/i', '-', $categoryName));
		?>

		<div class="card mb-3">
			<div class="card-header py-2 d-flex align-items-center justify-content-between" style="cursor:pointer;"
			     onclick="toggleCat('<?php echo $catSlug; ?>')">
				<div>
					<i class="ti ti-chevron-right me-2 cat-arrow" id="arrow-<?php echo $catSlug; ?>"></i>
					<strong><?php echo $categoryName; ?></strong>
					<span class="text-muted ms-2">(<?php echo count($grouped[$categoryName]); ?> parts)</span>
				</div>
				<span class="badge bg-warning text-dark cat-variance" id="var-<?php echo $catSlug; ?>" style="display:none;"></span>
			</div>
			<div class="cat-body" id="cat-<?php echo $catSlug; ?>" style="display:none;">
				<table class="table table-sm table-hover mb-0">
					<thead class="table-light">
						<tr>
							<th style="width:140px;">Part #</th>
							<th>Description</th>
							<th class="text-end" style="width:100px;">QOH</th>
							<th class="text-end" style="width:120px;">Counted</th>
							<th class="text-end" style="width:120px;">Variance</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($grouped[$categoryName] as $part): ?>
					<tr data-part="<?php echo $part['id']; ?>" data-qoh="<?php echo (int)$part['wh_qty']; ?>">
						<td class="font-monospace"><?php echo htmlspecialchars($part['partno']); ?></td>
						<td><?php echo htmlspecialchars($part['desc']); ?></td>
						<td class="text-end">
							<span class="qoh-display"><?php echo (int)$part['wh_qty']; ?></span>
						</td>
						<td class="text-end">
							<input type="number" min="0"
							       class="form-control form-control-sm text-end count-input"
							       name="counts[<?php echo $part['id']; ?>]"
							       data-part="<?php echo $part['id']; ?>"
							       placeholder="—"
							       style="width:90px; display:inline-block;">
						</td>
						<td class="text-end variance-cell" id="var-cell-<?php echo $part['id']; ?>">
							<span class="text-muted">—</span>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<?php endforeach; ?>

		<!-- Other category if present -->
		<?php if (!empty($grouped['Other'])): ?>
		<div class="card mb-3">
			<div class="card-header py-2 d-flex align-items-center justify-content-between" style="cursor:pointer;"
			     onclick="toggleCat('other')">
				<div>
					<i class="ti ti-chevron-right me-2 cat-arrow" id="arrow-other"></i>
					<strong>Other</strong>
					<span class="text-muted ms-2">(<?php echo count($grouped['Other']); ?> parts)</span>
				</div>
				<span class="badge bg-warning text-dark cat-variance" id="var-other" style="display:none;"></span>
			</div>
			<div class="cat-body" id="cat-other" style="display:none;">
				<table class="table table-sm table-hover mb-0">
					<thead class="table-light">
						<tr>
							<th style="width:140px;">Part #</th>
							<th>Description</th>
							<th class="text-end" style="width:100px;">QOH</th>
							<th class="text-end" style="width:120px;">Counted</th>
							<th class="text-end" style="width:120px;">Variance</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($grouped['Other'] as $part): ?>
					<tr data-part="<?php echo $part['id']; ?>" data-qoh="<?php echo (int)$part['wh_qty']; ?>">
						<td class="font-monospace"><?php echo htmlspecialchars($part['partno']); ?></td>
						<td><?php echo htmlspecialchars($part['desc']); ?></td>
						<td class="text-end">
							<span class="qoh-display"><?php echo (int)$part['wh_qty']; ?></span>
						</td>
						<td class="text-end">
							<input type="number" min="0"
							       class="form-control form-control-sm text-end count-input"
							       name="counts[<?php echo $part['id']; ?>]"
							       data-part="<?php echo $part['id']; ?>"
							       placeholder="—"
							       style="width:90px; display:inline-block;">
						</td>
						<td class="text-end variance-cell" id="var-cell-<?php echo $part['id']; ?>">
							<span class="text-muted">—</span>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php endif; ?>

	</form>

	<!-- Submit bar (bottom) -->
	<div class="d-flex justify-content-end mb-4">
		<button class="btn btn-success btn-lg" id="submit-bottom" onclick="confirmSubmit()">
			<i class="ti ti-clipboard-check me-1"></i> Submit Count for Review
		</button>
	</div>

</div>

<!-- Confirm modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Submit Physical Count for Review</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body" id="confirm-body">
				<p>This saves the count as a <strong>report</strong> for review. <strong>Inventory will not change yet</strong> — you'll see exactly what was counted and confirm before anything is adjusted.</p>
				<div id="confirm-variance-detail" class="mt-2"></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel — Go Back</button>
				<button type="button" class="btn btn-success" id="confirm-submit-btn">Submit for Review</button>
			</div>
		</div>
	</div>
</div>

<script>
var totalParts = <?php echo $totalParts; ?>;
var countedParts = 0;
var variances = {}; // partId => {part, qoh, counted, diff}

function toggleCat(slug) {
	var $body  = $('#cat-' + slug);
	var $arrow = $('#arrow-' + slug);
	var open   = $body.is(':visible');
	$body.toggle(!open);
	$arrow.toggleClass('ti-chevron-right', open).toggleClass('ti-chevron-down', !open);
}

// Blur handler — compute variance
$(document).on('blur', '.count-input', function() {
	var $input  = $(this);
	var partId  = $input.data('part');
	var qoh     = parseInt($input.closest('tr').data('qoh'), 10);
	var val     = $input.val().trim();
	var $cell   = $('#var-cell-' + partId);

	if (val === '') {
		$input.addClass('is-invalid');
		$cell.html('<span class="text-muted">—</span>');
		delete variances[partId];
		updateProgress();
		updateVarianceSummary();
		updateCatBadges();
		return;
	}

	$input.removeClass('is-invalid');
	var counted = parseInt(val, 10);
	var diff    = counted - qoh;

	// Track
	variances[partId] = { partId: partId, qoh: qoh, counted: counted, diff: diff };

	// Display variance
	if (diff === 0) {
		$cell.html('<span class="text-success fw-bold">0</span>');
	} else if (diff > 0) {
		$cell.html('<span class="text-primary fw-bold">+' + diff + '</span>');
	} else {
		$cell.html('<span class="text-danger fw-bold">' + diff + '</span>');
	}

	updateProgress();
	updateVarianceSummary();
	updateCatBadges();
});

function updateProgress() {
	countedParts = $('.count-input').filter(function() { return $(this).val().trim() !== ''; }).length;
	var badge = $('#progress-badge');
	badge.text(countedParts + ' / ' + totalParts + ' counted');
	if (countedParts === totalParts) {
		badge.removeClass('bg-secondary').addClass('bg-success');
	} else {
		badge.removeClass('bg-success').addClass('bg-secondary');
	}
}

function updateVarianceSummary() {
	var keys = Object.keys(variances);
	if (keys.length === 0) {
		$('#variance-summary').text('No variances yet.');
		return;
	}
	var varCount = keys.filter(function(k) { return variances[k].diff !== 0; }).length;
	var zeroCount = keys.filter(function(k) { return variances[k].diff === 0; }).length;
	var parts = [];
	if (varCount > 0) parts.push('<span class="text-danger fw-bold">' + varCount + ' variance' + (varCount !== 1 ? 's' : '') + '</span>');
	if (zeroCount > 0) parts.push('<span class="text-success">' + zeroCount + ' match</span>');
	$('#variance-summary').html(parts.join(', ') + ' of ' + keys.length + ' counted');
}

function updateCatBadges() {
	// For each category body, count variances among its inputs
	$('.cat-body').each(function() {
		var slug = this.id.replace('cat-', '');
		var varCount = 0;
		$(this).find('.count-input').each(function() {
			var partId = $(this).data('part');
			if (variances[partId] && variances[partId].diff !== 0) varCount++;
		});
		var $badge = $('#var-' + slug);
		if (varCount > 0) {
			$badge.text(varCount + ' variance' + (varCount !== 1 ? 's' : '')).show();
		} else {
			$badge.hide();
		}
	});
}

function confirmSubmit() {
	// Check all inputs are filled
	var $blank = $('.count-input').filter(function() { return $(this).val().trim() === ''; });
	if ($blank.length > 0) {
		$blank.addClass('is-invalid');
		// Open the first category that has a blank
		var $firstBlank = $blank.first();
		var $catBody = $firstBlank.closest('.cat-body');
		if ($catBody.length && !$catBody.is(':visible')) {
			var slug = $catBody.attr('id').replace('cat-', '');
			toggleCat(slug);
		}
		$firstBlank[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
		alert($blank.length + ' part(s) still need a count. All parts must have a quantity entered (use 0 if none).');
		return;
	}

	// Build variance detail
	var keys = Object.keys(variances);
	var varLines = keys.filter(function(k) { return variances[k].diff !== 0; });
	var detail = '';
	if (varLines.length === 0) {
		detail = '<div class="alert alert-success mb-0">All counted parts match current quantities — no adjustments needed.</div>';
	} else {
		detail = '<p class="mb-1"><strong>' + varLines.length + ' part(s) will be adjusted:</strong></p>';
		detail += '<ul class="mb-0 small">';
		varLines.forEach(function(k) {
			var v = variances[k];
			var sign = v.diff > 0 ? '+' : '';
			detail += '<li>Part ID ' + v.partId + ': ' + v.qoh + ' &rarr; ' + v.counted + ' (' + sign + v.diff + ')</li>';
		});
		detail += '</ul>';
	}

	$('#confirm-variance-detail').html(detail);
	$('#confirmModal').modal('show');
}

$('#confirm-submit-btn').on('click', function() {
	var $btn = $(this);
	$btn.prop('disabled', true).text('Saving…');

	// Build POST data — all inputs are guaranteed filled at this point
	var data = { warehouse_id: $('[name="warehouse_id"]').val() };
	$('.count-input').each(function() {
		data['counts[' + $(this).data('part') + ']'] = $(this).val().trim();
	});

	$.post('/ajax/physical_inv_submit.php', data, function(resp) {
		if (resp.ok && resp.report_url) {
			// Staged — go to the review report. Inventory has NOT changed yet.
			window.location = resp.report_url;
		} else {
			$('#confirmModal').modal('hide');
			alert('Error: ' + (resp.error || 'Unknown error'));
			$btn.prop('disabled', false).text('Submit for Review');
		}
	}, 'json').fail(function() {
		$('#confirmModal').modal('hide');
		alert('Submission failed. Please try again.');
		$btn.prop('disabled', false).text('Submit for Review');
	});
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
