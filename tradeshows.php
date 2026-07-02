<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('build') && !has_access('research') && !has_access('inventory')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/header.php");

	$db         = db_connect();
	$warehouses = get_warehouses($db);
	$canBuild   = can_edit('build');
	$defWH = 0;
	foreach ($warehouses as $w) { if (stripos($w['name'], 'arkansas') !== false) { $defWH = $w['id']; break; } }
	if (!$defWH && $warehouses) $defWH = $warehouses[0]['id'];

	$ly      = (int)date('Y') - 1;     // prior year, for projecting this year's shows
	$defFrom = "$ly-07-01";
	$defTo   = "$ly-08-31";
?>

<div class="mb-3">
	<h2 class="fw-bold mb-0">Tradeshow Planner</h2>
	<div class="text-muted small">Exact units sold per show (Shopify POS location) — your "what to bring" lists. Each show is its own Shopify location, so these numbers are precise, not estimated.</div>
</div>

<div class="card mb-3"><div class="card-body py-3">
	<div class="d-flex align-items-end gap-2 flex-wrap">
		<div>
			<label class="form-label small fw-semibold mb-0">From</label>
			<input type="date" id="tsFrom" class="form-control form-control-sm" style="width:170px;" value="<?php echo $defFrom; ?>">
		</div>
		<div>
			<label class="form-label small fw-semibold mb-0">To</label>
			<input type="date" id="tsTo" class="form-control form-control-sm" style="width:170px;" value="<?php echo $defTo; ?>">
		</div>
		<button id="tsLoad" class="btn btn-sm btn-primary"><i class="ti ti-search me-1"></i>Show Sales</button>
		<span class="text-muted small">Defaults to last year (<?php echo $defFrom; ?> – <?php echo $defTo; ?>). For a single weekend, narrow the dates.</span>
		<span id="tsMsg" class="small ms-1"></span>
	</div>
</div></div>

<?php if ($canBuild): ?>
<!-- Combined build/pack from selected shows -->
<div id="tsSelectBar" class="card mb-3" style="display:none;border-left:4px solid #2ca87f;">
	<div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
		<span class="fw-semibold"><span id="tsSelCount">0</span> show(s) selected</span>
		<span class="text-muted small">— combine into one build/pack order</span>
		<div class="ms-auto d-flex align-items-center gap-2">
			<?php if (count($warehouses) > 1): ?>
			<span class="small text-muted">Build in</span>
			<select id="tsWH" class="form-select form-select-sm" style="width:auto;">
				<?php foreach ($warehouses as $w): ?>
				<option value="<?php echo (int)$w['id']; ?>" <?php echo $w['id'] == $defWH ? 'selected' : ''; ?>><?php echo htmlspecialchars($w['name']); ?></option>
				<?php endforeach; ?>
			</select>
			<?php else: ?>
			<input type="hidden" id="tsWH" value="<?php echo (int)$defWH; ?>">
			<?php endif; ?>
			<button id="tsClearSel" class="btn btn-sm btn-outline-secondary">Clear</button>
			<button id="tsBuildSel" class="btn btn-sm btn-success"><i class="ti ti-package me-1"></i>Build/Pack selected</button>
		</div>
	</div>
</div>
<div id="combinedArea"></div>
<?php endif; ?>

<div id="tsBody"></div>

<script>
var TS_CAN_BUILD = <?php echo $canBuild ? 'true' : 'false'; ?>;
function tsNum(n){ return Number(n||0).toLocaleString(); }

function tsDateRange(s) {
	if (!s.start || s.start === '9999-99-99') return '';
	var fmt = function(x){ return new Date(x + 'T00:00:00').toLocaleDateString(undefined, { month:'short', day:'numeric' }); };
	return s.end && s.end !== s.start ? (fmt(s.start) + ' – ' + fmt(s.end)) : fmt(s.start);
}

function tsRender(d) {
	window._tsData = d;
	$('.ts-select').prop('checked', false); $('#tsSelectBar').hide(); $('#tsSelCount').text(0); $('#combinedArea').empty();
	if (!d || d.error) { $('#tsBody').html('<div class="alert alert-warning">' + (d && d.error ? $('<div>').text(d.error).html() : 'Could not load.') + '</div>'); return; }
	var shows = d.shows || [];
	if (!shows.length) { $('#tsBody').html('<div class="text-muted">No show sales in this window.</div>'); return; }

	var html = '<div class="d-flex gap-2 mb-2"><button id="tsExpand" class="btn btn-sm btn-light-secondary">Expand all</button><button id="tsCollapse" class="btn btn-sm btn-light-secondary">Collapse all</button></div>';

	shows.forEach(function(s, idx) {
		var range = tsDateRange(s);
		html += '<div class="card mb-2 ts-show">';
		// Clickable header (the "dropdown").
		html += '<div class="card-body py-2 ts-head" style="cursor:pointer;">' +
			'<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">' +
				'<div class="d-flex align-items-center gap-2">' +
					(TS_CAN_BUILD ? '<input type="checkbox" class="ts-select form-check-input" data-idx="' + idx + '" onclick="event.stopPropagation()" title="Select this show for a combined build/pack order">' : '') +
					'<i class="ti ti-chevron-right ts-chev" style="transition:transform .15s;"></i>' +
					'<span class="fw-bold" style="font-size:1.05rem;">' + $('<div>').text(s.name).html() + '</span>' +
					(range ? '<span class="text-muted small">· ' + range + '</span>' : '') +
				'</div>' +
				'<div class="d-flex gap-3 align-items-center">' +
					'<span class="badge bg-primary" style="font-size:0.85rem;">' + tsNum(s.total_units) + ' units</span>' +
					'<span class="text-muted small">$' + tsNum(s.revenue) + ' · ' + tsNum(s.orders) + ' orders</span>' +
				'</div>' +
			'</div></div>';

		// Collapsible body.
		html += '<div class="ts-detail" style="display:none;"><div class="card-body pt-0">';
		if (s.error) { html += '<div class="text-danger small">' + $('<div>').text(s.error).html() + '</div>'; }
		else if (!s.items || !s.items.length) { html += '<div class="text-muted small">No sales in this window.</div>'; }
		else {
			html += '<div class="row g-4">';
			html += '<div class="col-12 col-lg-7"><div class="small fw-semibold text-uppercase text-muted mb-1" style="letter-spacing:.04em;">Bring List (units sold)</div>';
			html += '<table class="table table-sm table-hover mb-0" style="font-size:0.85rem;"><thead><tr style="background:#f1f3f5;"><th>SKU</th><th>Product</th><th class="text-end">Units</th></tr></thead><tbody>';
			s.items.forEach(function(it) {
				html += '<tr><td class="fw-semibold" style="width:90px;">' + $('<div>').text(it.sku).html() + '</td>' +
					'<td class="text-muted">' + $('<div>').text(it.title).html() + '</td>' +
					'<td class="text-end fw-bold">' + tsNum(it.units) + '</td></tr>';
			});
			html += '</tbody></table></div>';
			html += '<div class="col-12 col-lg-5"><div class="small fw-semibold text-uppercase text-muted mb-1" style="letter-spacing:.04em;">By Day</div>';
			html += '<table class="table table-sm mb-0" style="font-size:0.82rem;"><tbody>';
			(s.by_date||[]).forEach(function(dd) {
				var dt = new Date(dd.date + 'T00:00:00');
				var lbl = dt.toLocaleDateString(undefined, { weekday:'short', month:'short', day:'numeric' });
				html += '<tr><td class="text-muted">' + lbl + '</td><td class="text-end fw-semibold">' + tsNum(dd.units) + '</td></tr>';
			});
			html += '</tbody></table></div></div>';
		}
		html += '</div></div>';

		html += '</div>';
	});
	$('#tsBody').html(html);
}

// Toggle a show open/closed.
$(document).on('click', '.ts-head', function() {
	var $card = $(this).closest('.ts-show');
	var open = $card.find('.ts-detail').is(':visible');
	$card.find('.ts-detail').toggle(!open);
	$card.find('.ts-chev').css('transform', open ? '' : 'rotate(90deg)');
});
$(document).on('click', '#tsExpand',   function(){ $('.ts-detail').show(); $('.ts-chev').css('transform','rotate(90deg)'); });
$(document).on('click', '#tsCollapse', function(){ $('.ts-detail').hide(); $('.ts-chev').css('transform',''); });

function tsLoad() {
	var from = $('#tsFrom').val(), to = $('#tsTo').val();
	if (!from || !to) { alert('Pick a date range.'); return; }
	var $btn = $('#tsLoad').prop('disabled', true);
	$('#tsMsg').removeClass('text-danger').addClass('text-muted').text('Pulling show sales from Shopify…');
	$('#tsBody').html('<div class="text-muted py-4 text-center"><i class="ti ti-loader"></i> Loading…</div>');
	$.ajax({ url: '/ajax/tradeshow_sales.php', method: 'POST', dataType: 'json', timeout: 120000, data: { from: from, to: to } })
		.done(function(d) { tsRender(d); $('#tsMsg').text(''); })
		.fail(function(xhr, status) { $('#tsBody').html('<div class="alert alert-danger">' + (status==='timeout' ? 'Timed out — try a narrower date range.' : 'Request failed (' + (xhr.status||'?') + ').') + '</div>'); $('#tsMsg').text(''); })
		.always(function() { $btn.prop('disabled', false); });
}

$(function() { tsLoad(); });
$('#tsLoad').on('click', tsLoad);

// ── Combined build/pack order from selected shows ──────────────────────────
function tsSelectedIdxs() {
	return $('.ts-select:checked').map(function(){ return parseInt($(this).data('idx')); }).get();
}
function tsUpdateSelBar() {
	var n = tsSelectedIdxs().length;
	$('#tsSelCount').text(n);
	$('#tsSelectBar').toggle(n > 0);
	if (n === 0) $('#combinedArea').empty();
}
$(document).on('change', '.ts-select', tsUpdateSelBar);
$(document).on('click', '#tsClearSel', function(){ $('.ts-select').prop('checked', false); tsUpdateSelBar(); });

$(document).on('click', '#tsBuildSel', function() {
	var idxs = tsSelectedIdxs();
	if (!idxs.length) return;
	var shows = (window._tsData && window._tsData.shows) || [];
	var combined = {}; var names = [];
	idxs.forEach(function(i) {
		var s = shows[i]; if (!s) return;
		names.push(s.name);
		(s.items || []).forEach(function(it) {
			if (!it.sku) return;
			combined[it.sku] = (combined[it.sku] || 0) + Number(it.units || 0);
		});
	});
	if (!Object.keys(combined).length) { alert('The selected shows have no SKU-level sales to build from.'); return; }

	var $btn = $(this).prop('disabled', true).html('<i class="ti ti-loader me-1"></i>Preparing…');
	$('#combinedArea').html('<div class="text-muted py-3 text-center"><i class="ti ti-loader"></i> Mapping products…</div>');
	$.ajax({ url:'/ajax/build/tradeshow_order_prep.php', method:'POST', dataType:'json',
		data:{ skus: JSON.stringify(combined), warehouse_id: $('#tsWH').val() } })
	.done(function(d){ tsRenderPrep(d, names); })
	.fail(function(xhr){ $('#combinedArea').html('<div class="alert alert-danger">Could not prepare the order (' + (xhr.status||'?') + ').</div>'); })
	.always(function(){ $btn.prop('disabled', false).html('<i class="ti ti-package me-1"></i>Build/Pack selected'); });
});

function tsRenderPrep(d, names) {
	if (!d || !d.ok) { $('#combinedArea').html('<div class="alert alert-warning">' + (d && d.error ? $('<div>').text(d.error).html() : 'Could not prepare the order.') + '</div>'); return; }
	var rows = d.rows || [];
	if (!rows.length) {
		$('#combinedArea').html('<div class="alert alert-warning">None of the products sold at the selected show(s) can be auto-built - no matching product with a bill of materials.'
			+ (d.unmapped && d.unmapped.length ? ' (' + d.unmapped.length + ' SKU(s) had sales but no product/BOM.)' : '') + '</div>');
		return;
	}
	var html = '<div class="card mb-3" style="border-top:3px solid #2ca87f;"><div class="card-body">';
	html += '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">';
	html += '<span class="fw-bold">Combined Build/Pack Order <span class="text-muted fw-normal small">- ' + $('<div>').text(names.join(', ')).html() + '</span></span>';
	html += '<button id="tsCreateOrder" class="btn btn-sm btn-success"><i class="ti ti-check me-1"></i>Create packaging order(s)</button></div>';
	if (d.fp_note) html += '<div class="small text-warning mb-1"><i class="ti ti-alert-triangle"></i> ' + $('<div>').text(d.fp_note).html() + '</div>';
	html += '<div class="text-muted small mb-2"><b>Demand</b> = combined units these shows sold last year (min 10). <b>Bring</b> = pulled from finished product already on hand; <b>Build</b> = what is left to make (Bring + Build = Demand). Only <b>Build</b> becomes a packaging order - edit any Build qty before creating. No inventory is deducted until you finalize on the Packaging page.</div>';
	html += '<div class="table-responsive"><table class="table table-sm align-middle" style="font-size:0.88rem;"><thead><tr>' +
		'<th>Product</th><th class="text-center">Demand</th><th class="text-center">FP on hand</th><th class="text-center">Bring</th><th class="text-center">Build</th><th class="text-center" style="width:130px;">Build qty</th></tr></thead><tbody>';
	rows.forEach(function(r) {
		var note = r.short > 0
			? '<div class="text-danger" style="font-size:0.66rem;">buildable now ' + tsNum(r.buildable) + ' - short ' + tsNum(r.short) + ' (' + $('<div>').text(r.limit_part||'raw materials').html() + ')</div>'
			: '<div class="text-success" style="font-size:0.66rem;">buildable now ' + tsNum(r.buildable) + '</div>';
		html += '<tr>' +
			'<td class="fw-semibold">' + $('<div>').text(r.product).html() + ' <span class="text-muted" style="font-size:0.72rem;">' + $('<div>').text(r.sku).html() + '</span></td>' +
			'<td class="text-center fw-semibold">' + tsNum(r.demand) + '</td>' +
			'<td class="text-center">' + tsNum(r.on_hand) + '</td>' +
			'<td class="text-center text-secondary">' + tsNum(r.bring) + '</td>' +
			'<td class="text-center fw-bold text-primary">' + tsNum(r.build) + '</td>' +
			'<td class="text-center"><input type="number" min="0" class="form-control form-control-sm ts-ord-qty text-center" data-prodid="' + r.prodid + '" value="' + r.build + '">' + note + '</td>' +
			'</tr>';
	});
	html += '</tbody></table></div>';
	if (d.unmapped && d.unmapped.length) {
		var un = d.unmapped.map(function(u){ return $('<div>').text(u.sku).html() + ' (' + tsNum(u.units) + ')'; }).join(', ');
		html += '<div class="text-muted small mt-1"><i class="ti ti-info-circle"></i> Not auto-built (no product/BOM match): ' + un + '</div>';
	}
	html += '<div id="tsCreateMsg" class="small mt-2"></div>';
	html += '</div></div>';
	$('#combinedArea').html(html);
	if ($('#combinedArea').offset()) $('html,body').animate({ scrollTop: $('#combinedArea').offset().top - 90 }, 300);
}

$(document).on('click', '#tsCreateOrder', function() {
	var orders = [];
	$('.ts-ord-qty').each(function(){
		var q = parseInt($(this).val()) || 0;
		if (q > 0) orders.push({ prodid: parseInt($(this).data('prodid')), qty: q });
	});
	if (!orders.length) { alert('Set at least one quantity above 0.'); return; }
	var wh = $('#tsWH').val();
	if (!confirm('Create ' + orders.length + ' packaging order(s)? They will appear on the Packaging page, where you build & pack them. No inventory is deducted until you finalize there.')) return;
	var $btn = $(this).prop('disabled', true).html('<i class="ti ti-loader me-1"></i>Creating…');
	$.post('/ajax/build/create_orders.php', { orders: JSON.stringify(orders), warehouse_id: wh }, function(res) {
		if (typeof res === 'string' && res.indexOf('ok:') === 0) {
			$('#combinedArea').html('<div class="alert alert-success">✓ Created ' + orders.length + ' packaging order(s). <a href="/build.php" class="alert-link">Go to Packaging →</a></div>');
			$('.ts-select').prop('checked', false); tsUpdateSelBar();
		} else {
			$('#tsCreateMsg').html('<span class="text-danger">Error: ' + $('<div>').text(res).html() + '</span>');
			$btn.prop('disabled', false).html('<i class="ti ti-check me-1"></i>Create packaging order(s)');
		}
	}).fail(function(xhr){
		$('#tsCreateMsg').html('<span class="text-danger">Failed: ' + (xhr.responseText || xhr.status) + '</span>');
		$btn.prop('disabled', false).html('<i class="ti ti-check me-1"></i>Create packaging order(s)');
	});
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
