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
		<h2 class="fw-bold mb-0">Warehouse Stock</h2>
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
		'<input type="text" id="wsSearch" class="form-control form-control-sm" style="max-width:280px;" placeholder="Search SKU or product…">' +
		'<select id="wsCat" class="form-select form-select-sm" style="max-width:240px;"><option value="">All categories</option>';
	catList.forEach(function(c){ html += '<option value="' + $('<div>').text(c).html() + '">' + $('<div>').text(c).html() + '</option>'; });
	html += '</select><span id="wsCount" class="text-muted small"></span></div>';

	html += '<div class="row g-3">';
	locs.forEach(function(loc) {
		var blocks = data[loc.id] || [];
		var totColor = loc.total < 0 ? '#e64545' : '#2ca01c';
		html += '<div class="col-12 col-xl-6 ws-loc"><div class="card h-100" style="border-top:3px solid #4680ff;"><div class="card-body">';
		html += '<div class="d-flex justify-content-between align-items-center mb-2">' +
			'<h5 class="fw-bold mb-0">' + $('<div>').text(loc.name).html() + '</h5>' +
			'<span class="badge" style="background:' + totColor + ';">' + Number(loc.total).toLocaleString() + ' units</span></div>';

		if (!blocks.length) { html += '<div class="text-muted small ws-empty">No tracked stock here.</div>'; }
		blocks.forEach(function(b) {
			html += '<div class="mt-3 ws-cat" data-cat="' + $('<div>').text(b.category).html() + '"><div class="d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e9ecef;padding-bottom:3px;">' +
				'<span class="fw-semibold small text-uppercase" style="letter-spacing:.04em;color:#4680ff;">' + $('<div>').text(b.category).html() + '</span>' +
				'<span class="text-muted small">' + Number(b.subtotal).toLocaleString() + '</span></div>';
			html += '<table class="table table-sm mb-0" style="font-size:0.82rem;"><tbody>';
			b.items.forEach(function(it) {
				var key = (it.sku + ' ' + it.title).toLowerCase();
				html += '<tr class="ws-item" data-search="' + $('<div>').text(key).html() + '" data-cat="' + $('<div>').text(b.category).html() + '">' +
					'<td style="width:90px;" class="fw-semibold">' + $('<div>').text(it.sku).html() + '</td>' +
					'<td class="text-muted">' + $('<div>').text(it.title).html() + '</td>' +
					'<td class="text-end" style="width:70px;">' + wsQtyCell(it.qty) + '</td></tr>';
			});
			html += '</tbody></table></div>';
		});

		html += '</div></div></div>';
	});
	html += '</div>';
	$('#wsBody').html(html);
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
		$(this).toggle($(this).find('.ws-item:visible').length > 0);
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
