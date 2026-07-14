<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('inventory') && !has_access('build')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/header.php");
?>

<div class="mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
	<div>
		<h2 class="fw-bold mb-0">Warehouse FP Stock</h2>
		<div class="text-muted small">Live Shopify finished-product inventory by warehouse and category (apparel excluded). Refreshes each time you open this page.</div>
	</div>
	<div class="d-flex align-items-center gap-2">
		<span id="wsUpdated" class="text-muted small"></span>
		<button id="wsRefresh" class="btn btn-sm btn-light-primary"><i class="ti ti-refresh me-1"></i>Refresh</button>
	</div>
</div>

<div id="wsBody">
	<div class="text-muted py-5 text-center"><i class="ti ti-loader"></i> Loading live inventory from Shopify…</div>
</div>

<script>
function wsQtyCell(q) {
	var c = q < 0 ? '#e64545' : (q === 0 ? '#adb5bd' : '#1a1a2e');
	return '<span style="color:' + c + ';font-weight:600;">' + Number(q).toLocaleString() + '</span>';
}

function wsRender(d) {
	if (!d || d.error) {
		$('#wsBody').html('<div class="alert alert-warning">' + (d && d.error ? $('<div>').text(d.error).html() : 'Could not load inventory.') +
			'<div class="small text-muted mt-1">If this mentions permissions/locations, add the <code>read_locations</code> scope to the Shopify app, reinstall, and Save on Integrations.</div></div>');
		return;
	}
	var locs = d.locations || [], data = d.data || {};
	if (!locs.length) { $('#wsBody').html('<div class="text-muted">No inventory found.</div>'); return; }

	// Unique categories across all locations (for the filter dropdown).
	var cats = {};
	locs.forEach(function(loc){ (data[loc.id]||[]).forEach(function(b){ cats[b.category] = true; }); });
	var catList = Object.keys(cats).sort();

	var html = '';
	// Filter bar.
	html += '<div class="d-flex align-items-center gap-2 flex-wrap mb-3">' +
		'<div class="btn-group btn-group-sm" role="group" aria-label="Location view">' +
			'<button type="button" id="wsViewBoth" class="btn btn-outline-primary" onclick="wsSetView(\'both\')">All</button>' +
			'<button type="button" id="wsViewArk" class="btn btn-outline-primary" onclick="wsSetView(\'arkansas\')">Arkansas</button>' +
			'<button type="button" id="wsViewOre" class="btn btn-outline-primary" onclick="wsSetView(\'oregon\')">Oregon</button>' +
			'<button type="button" id="wsViewAway" class="btn btn-outline-warning" onclick="wsSetView(\'elsewhere\')">At shows</button>' +
		'</div>' +
		'<input type="text" id="wsSearch" class="form-control form-control-sm" style="max-width:280px;" placeholder="Search SKU or product…">' +
		'<select id="wsCat" class="form-select form-select-sm" style="max-width:240px;"><option value="">All categories</option>';
	catList.forEach(function(c){ html += '<option value="' + $('<div>').text(c).html() + '">' + $('<div>').text(c).html() + '</option>'; });
	html += '</select><span id="wsCount" class="text-muted small"></span></div>';

	// Finished product still parked at tradeshow/POS locations — it should be brought home.
	var awayLocs = locs.filter(function(l){ return l.role === 'elsewhere' && Number(l.total || 0) > 0; });
	if (awayLocs.length) {
		var awayTotal = awayLocs.reduce(function(a, l){ return a + Number(l.total || 0); }, 0);
		html += '<div class="alert alert-warning py-2"><div class="fw-bold small mb-1">' +
			'<i class="ti ti-alert-triangle me-1"></i>' + awayTotal.toLocaleString() + ' unit' + (awayTotal === 1 ? '' : 's') +
			' of finished product still at a show location</div>' +
			'<div class="small mb-1">Show stock is meant to be temporary. Until it is moved back to Arkansas or Oregon in Shopify, it can\'t ship from a warehouse — and the build planners will not count it, so they will tell you to build more than you need.</div>' +
			'<div class="small">' + awayLocs.map(function(l){
				return $('<div>').text(l.name).html() + ' <b>(' + Number(l.total).toLocaleString() + ')</b>';
			}).join(' · ') + '</div></div>';
	}

	html += '<div class="row g-3">';
	locs.forEach(function(loc) {
		var blocks = data[loc.id] || [];
		var totColor = loc.total < 0 ? '#e64545' : '#2ca01c';
		var isAway = (loc.role === 'elsewhere');
		var awayBadge = isAway ? ' <span class="badge bg-warning text-dark" style="font-size:0.6rem;" title="Tradeshow/POS location — move this stock back to a warehouse">SHOW</span>' : '';
		html += '<div class="col-12 col-xl-6 ws-loc" data-role="' + $('<div>').text(loc.role || 'arkansas').html() + '" data-locname="' + $('<div>').text((loc.name||'').toLowerCase()).html() + '"><div class="card h-100" style="border-top:3px solid ' + (isAway ? '#e8a33d' : '#4680ff') + ';"><div class="card-body">';
		html += '<div class="d-flex justify-content-between align-items-center mb-2">' +
			'<h5 class="fw-bold mb-0">' + $('<div>').text(loc.name).html() + awayBadge + '</h5>' +
			'<span class="badge" style="background:' + totColor + ';">' + Number(loc.total).toLocaleString() + ' units</span></div>';

		if (!blocks.length) { html += '<div class="text-muted small ws-empty">No tracked stock here.</div>'; }
		blocks.forEach(function(b) {
			html += '<div class="mt-3 ws-cat" data-cat="' + $('<div>').text(b.category).html() + '"><div class="d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e9ecef;padding-bottom:3px;">' +
				'<span class="fw-semibold small text-uppercase" style="letter-spacing:.04em;color:#4680ff;">' + $('<div>').text(b.category).html() + '</span>' +
				'<span class="text-muted small">' + Number(b.subtotal).toLocaleString() + '</span></div>';
			html += '<table class="table table-sm mb-0" style="font-size:0.82rem;">' +
				'<thead><tr class="text-muted" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.03em;">' +
				'<th style="width:90px;">SKU</th><th>Product</th>' +
				'<th class="text-end" style="width:70px;">Avail</th>' +
				'<th class="text-end" style="width:82px;">Committed</th>' +
				'<th class="text-end" style="width:78px;">On Hand</th>' +
				'<th class="text-end" style="width:78px;">Incoming</th></tr></thead><tbody>';
			b.items.forEach(function(it) {
				var key = (it.sku + ' ' + it.title).toLowerCase();
				var committed = Number(it.committed || 0);
				var onhand    = Number(it.on_hand != null ? it.on_hand : it.qty);
				var incoming  = Number(it.incoming || 0);
				html += '<tr class="ws-item" data-search="' + $('<div>').text(key).html() + '" data-cat="' + $('<div>').text(b.category).html() + '">' +
					'<td class="fw-semibold">' + $('<div>').text(it.sku).html() + '</td>' +
					'<td class="text-muted">' + $('<div>').text(it.title).html() + '</td>' +
					'<td class="text-end">' + wsQtyCell(it.qty) + '</td>' +
					'<td class="text-end">' + (committed ? '<span style="color:#e58a00;font-weight:600;">' + committed.toLocaleString() + '</span>' : '<span class="text-muted">0</span>') + '</td>' +
					'<td class="text-end fw-semibold">' + onhand.toLocaleString() + '</td>' +
					'<td class="text-end text-muted">' + (incoming ? incoming.toLocaleString() : '-') + '</td></tr>';
			});
			html += '</tbody></table></div>';
		});

		html += '</div></div></div>';
	});
	html += '</div>';
	$('#wsBody').html(html);
	wsSetView(wsView);
}

// Location view toggle: Both (side by side) / Arkansas / Oregon.
var wsView = 'both';
function wsSetView(v) {
	wsView = v;
	$('#wsViewBoth,#wsViewArk,#wsViewOre,#wsViewAway').removeClass('active');
	var btn = { arkansas: 'Ark', oregon: 'Ore', elsewhere: 'Away' }[v] || 'Both';
	$('#wsView' + btn).addClass('active');
	$('.ws-loc').toggleClass('col-xl-6', v === 'both'); // full width when a single location is chosen
	wsFilter();
}

// Live filtering over the rendered blocks (search + category).
function wsFilter() {
	var q   = ($('#wsSearch').val() || '').toLowerCase().trim();
	var cat = $('#wsCat').val() || '';
	var shown = 0;
	$('.ws-item').each(function() {
		var $r = $(this);
		var ok = (q === '' || $r.attr('data-search').indexOf(q) !== -1) && (cat === '' || $r.attr('data-cat') === cat);
		$r.toggle(ok);
		if (ok) shown++;
	});
	// Hide empty category sections and empty location blocks.
	$('.ws-cat').each(function() {
		$(this).toggle($(this).find('.ws-item:visible').length > 0);
	});
	$('.ws-loc').each(function() {
		// Each location carries its true role from the server: oregon | arkansas | elsewhere.
		// "elsewhere" = a tradeshow/POS location — stock there is temporary and does NOT belong
		// under Arkansas (it used to, because "Arkansas" just meant "not Oregon").
		var role = $(this).attr('data-role') || 'arkansas';
		var locMatch = (wsView === 'both') || (wsView === role);
		$(this).toggle(locMatch && $(this).find('.ws-item:visible').length > 0);
	});
	$('#wsCount').text((q !== '' || cat !== '') ? (shown + ' match' + (shown === 1 ? '' : 'es')) : '');
}
$(document).on('input', '#wsSearch', wsFilter);
$(document).on('change', '#wsCat', wsFilter);

function wsStamp(d) {
	if (!d.updated_at) return '';
	var t = new Date(d.updated_at.replace(' ', 'T'));
	var when = isNaN(t) ? d.updated_at : t.toLocaleString();
	return (d.cached ? 'As of ' + when + (d.stale ? ' (Shopify unreachable — showing last good)' : ' (cached)') : 'Updated ' + when + ' (live)');
}

function wsLoad(fresh) {
	var $btn = $('#wsRefresh').prop('disabled', true);
	$('#wsUpdated').text(fresh ? 'Refreshing from Shopify…' : 'Loading…');
	if (fresh) $('#wsBody').html('<div class="text-muted py-5 text-center"><i class="ti ti-loader"></i> Pulling live inventory from Shopify…</div>');
	$.ajax({ url: '/ajax/shopify_inventory.php', method: 'POST', dataType: 'json', timeout: 120000, data: { fresh: fresh ? 1 : 0 } })
		.done(function(d) {
			wsRender(d);
			$('#wsUpdated').text(wsStamp(d));
		})
		.fail(function(xhr, status) {
			$('#wsBody').html('<div class="alert alert-danger">' + (status === 'timeout' ? 'Timed out loading from Shopify — try Refresh.' : 'Request failed (' + (xhr.status||'?') + ').') + '</div>');
			$('#wsUpdated').text('');
		})
		.always(function() { $btn.prop('disabled', false); });
}

$(function() { wsLoad(false); });              // open = use cache if pulled in the last few hours
$('#wsRefresh').on('click', function(){ wsLoad(true); }); // Refresh = force a live pull
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
