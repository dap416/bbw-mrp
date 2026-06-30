<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('build') && !has_access('research') && !has_access('inventory')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/header.php");

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

<div id="tsBody"></div>

<script>
function tsNum(n){ return Number(n||0).toLocaleString(); }

function tsRender(d) {
	if (!d || d.error) { $('#tsBody').html('<div class="alert alert-warning">' + (d && d.error ? $('<div>').text(d.error).html() : 'Could not load.') + '</div>'); return; }
	var shows = d.shows || [];
	var html = '';
	shows.forEach(function(s) {
		html += '<div class="card mb-3"><div class="card-body">';
		html += '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">' +
			'<h4 class="fw-bold mb-0">' + $('<div>').text(s.name).html() + '</h4>' +
			'<div class="d-flex gap-3 align-items-center">' +
				'<span class="badge bg-primary" style="font-size:0.85rem;">' + tsNum(s.total_units) + ' units</span>' +
				'<span class="text-muted small">$' + tsNum(s.revenue) + ' · ' + tsNum(s.orders) + ' orders</span>' +
			'</div></div>';

		if (s.error) { html += '<div class="text-danger small">' + $('<div>').text(s.error).html() + '</div></div></div>'; return; }
		if (!s.items || !s.items.length) { html += '<div class="text-muted small">No sales in this window.</div></div></div>'; return; }

		html += '<div class="row g-4">';
		// Bring list
		html += '<div class="col-12 col-lg-7"><div class="small fw-semibold text-uppercase text-muted mb-1" style="letter-spacing:.04em;">Bring List (units sold last year)</div>';
		html += '<table class="table table-sm table-hover mb-0" style="font-size:0.85rem;"><thead><tr style="background:#f1f3f5;"><th>SKU</th><th>Product</th><th class="text-end">Units</th></tr></thead><tbody>';
		s.items.forEach(function(it) {
			html += '<tr><td class="fw-semibold" style="width:90px;">' + $('<div>').text(it.sku).html() + '</td>' +
				'<td class="text-muted">' + $('<div>').text(it.title).html() + '</td>' +
				'<td class="text-end fw-bold">' + tsNum(it.units) + '</td></tr>';
		});
		html += '</tbody></table></div>';
		// By day (so multi-weekend shows like Game Fair are visible)
		html += '<div class="col-12 col-lg-5"><div class="small fw-semibold text-uppercase text-muted mb-1" style="letter-spacing:.04em;">By Day</div>';
		html += '<table class="table table-sm mb-0" style="font-size:0.82rem;"><tbody>';
		(s.by_date||[]).forEach(function(dd) {
			var dt = new Date(dd.date + 'T00:00:00');
			var lbl = dt.toLocaleDateString(undefined, { weekday:'short', month:'short', day:'numeric' });
			html += '<tr><td class="text-muted">' + lbl + '</td><td class="text-end fw-semibold">' + tsNum(dd.units) + '</td></tr>';
		});
		html += '</tbody></table></div>';
		html += '</div>';

		html += '</div></div>';
	});
	$('#tsBody').html(html || '<div class="text-muted">No shows configured.</div>');
}

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
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
