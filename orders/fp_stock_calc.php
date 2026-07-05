<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	if (!has_access('orders')) deny_access();

	require_once(__DIR__."/../includes/header.php");

	$dbLink = db_connect();

	$products      = $dbLink->query("SELECT * FROM `products` ORDER BY `name` ASC");
	$pendingOrders = $dbLink->query("SELECT i.*, p.name AS prodname, w.name AS wh_name FROM `intransit` i JOIN `products` p ON p.id = i.prodid LEFT JOIN `warehouses` w ON w.id = i.warehouse_id WHERE i.`orddate` = '0000-00-00 00:00:00'");
	$sentOrders    = $dbLink->query("SELECT i.*, p.name AS prodname, w.name AS wh_name FROM `intransit` i JOIN `products` p ON p.id = i.prodid LEFT JOIN `warehouses` w ON w.id = i.warehouse_id WHERE i.`orddate` != '0000-00-00 00:00:00' AND i.`buildqty` = 0 AND i.`recdate` = '0000-00-00 00:00:00' ORDER BY i.`orddate` ASC");
	$warehouses    = get_warehouses($dbLink);

?>

<div class="mb-4 d-flex align-items-center justify-content-between">
	<h2 class="fw-bold mb-0">Finished Product Stock Order</h2>
</div>

<!-- ── RECOMMEND A STOCK ORDER ──────────────────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #6f42c1;">
	<div class="card-body">
		<div class="mb-2"><span class="fw-bold">Recommend a Stock Order</span></div>
		<p class="text-muted small mb-3">Suggests what to order to cover demand through a date you choose — last year's sales for the same window (online, POS/tradeshow &amp; completed drafts), current open wholesale draft orders (≥10 units), finished-product stock and pipeline. <strong>Click a product</strong> to see where its demand comes from.</p>
		<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
			<span class="small fw-semibold text-muted">Fulfill until:</span>
			<input type="date" id="recUntil" class="form-control form-control-sm" style="width:170px;" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo date('Y-m-d', strtotime('+90 days')); ?>" />
			<span class="small fw-semibold text-muted">Warehouse:</span>
			<select id="recWarehouse" class="form-select form-select-sm" style="width:180px;">
				<?php foreach ($warehouses as $wh): ?>
				<option value="<?php echo (int)$wh['id']; ?>" <?php echo (stripos($wh['name'],'arkansas')!==false)?'selected':''; ?>><?php echo htmlspecialchars($wh['name']); ?></option>
				<?php endforeach; ?>
			</select>
			<button id="recBtn" class="btn btn-sm btn-primary"><i class="ti ti-bulb me-1"></i>Recommend</button>
			<span id="recMsg" class="small text-muted"></span>
		</div>
		<div id="recResults"></div>
	</div>
</div>

<h5 class="fw-semibold mb-2">Pending Order</h5>
<div class="card mb-4">
	<div class="card-body">
		<div class="d-flex align-items-center gap-3 flex-wrap">
			<select class="form-select form-select-sm" id="selProd" style="width:300px;">
				<option value="">Select Product to Package</option>
				<?php while ($product = $products->fetch()) { ?>
				<option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['name']); ?></option>
				<?php } ?>
			</select>
			<input type="text" id="addProdQty" class="form-control form-control-sm" style="width:80px;" placeholder="QTY" />
			<select class="form-select form-select-sm" id="selWarehouse" style="width:200px;">
				<option value="">Select Warehouse</option>
				<?php foreach ($warehouses as $wh): ?>
				<option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="btn btn-light-primary btn-sm" id="addProdSubmit">Add</button>
			<div class="ms-auto d-flex gap-2">
				<button class="btn btn-primary btn-sm" id="sendButton">Send Order</button>
				<button class="btn btn-outline-secondary btn-sm" id="clearButton">Clear List</button>
			</div>
		</div>
	</div>
</div>

<div class="card">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Product</th>
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Warehouse</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:160px;">QTY</th>
					<th style="width:80px;"></th>
				</tr>
			</thead>
			<tbody>
<?php

	$hasRows = false;
	while ($order = $pendingOrders->fetch()) {
		$hasRows  = true;
		$rowId    = $order['id'];
		$prodName = $order['prodname'] ?? '(unknown)';
		$whName   = $order['wh_name']  ?? '—';
?>
				<tr>
					<td><?php echo htmlspecialchars($prodName); ?></td>
					<td class="text-muted small"><?php echo htmlspecialchars($whName); ?></td>
					<td class="text-end">
						<div class="d-flex align-items-center justify-content-end gap-2">
							<input type="number" class="form-control form-control-sm fp-qty-input" style="width:80px;text-align:right;" data-id="<?php echo $rowId; ?>" value="<?php echo $order['qty']; ?>" />
							<button class="btn btn-primary btn-sm fp-qty-save" data-id="<?php echo $rowId; ?>">Save</button>
						</div>
					</td>
					<td class="text-center">
						<button class="btn btn-outline-danger btn-sm fp-delete" data-id="<?php echo $rowId; ?>">Remove</button>
					</td>
				</tr>
<?php } ?>
<?php if (!$hasRows) { ?>
				<tr>
					<td colspan="3" class="text-muted text-center py-3" style="font-size:0.875rem;">No items in the pending order. Add products above.</td>
				</tr>
<?php } ?>
			</tbody>
		</table>
	</div>
</div>

<hr class="my-4">

<h5 class="fw-semibold mb-2">Sent Orders — Awaiting Packaging</h5>
<div class="card">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0" id="sent-orders-table">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Product</th>
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Warehouse</th>
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Ordered</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:180px;">QTY</th>
					<th style="width:100px;"></th>
				</tr>
			</thead>
			<tbody>
<?php
	$hasSent = false;
	while ($sent = $sentOrders->fetch()) {
		$hasSent  = true;
		$rowId    = $sent['id'];
		$prodName = $sent['prodname'] ?? '(unknown)';
		$whName   = $sent['wh_name']  ?? '—';
		$ordDate  = date('M j, Y', strtotime($sent['orddate']));
?>
				<tr id="sent-row-<?php echo $rowId; ?>">
					<td><?php echo htmlspecialchars($prodName); ?></td>
					<td class="text-muted small"><?php echo htmlspecialchars($whName); ?></td>
					<td class="text-muted small"><?php echo $ordDate; ?></td>
					<td class="text-end">
						<div class="d-flex align-items-center justify-content-end gap-2">
							<input type="number" class="form-control form-control-sm sent-qty-input" style="width:80px;text-align:right;" data-id="<?php echo $rowId; ?>" value="<?php echo $sent['qty']; ?>" />
							<button class="btn btn-primary btn-sm sent-qty-save" data-id="<?php echo $rowId; ?>">Save</button>
						</div>
					</td>
					<td class="text-center">
						<button class="btn btn-outline-danger btn-sm sent-cancel" data-id="<?php echo $rowId; ?>">Cancel</button>
					</td>
				</tr>
<?php } ?>
<?php if (!$hasSent) { ?>
				<tr>
					<td colspan="5" class="text-muted text-center py-3" style="font-size:0.875rem;">No sent orders awaiting packaging.</td>
				</tr>
<?php } ?>
			</tbody>
		</table>
	</div>
</div>

<script>

	$("#addProdSubmit").click(function() {
		var prodId = $("#selProd").val();
		var qty    = $("#addProdQty").val();
		var whId   = $("#selWarehouse").val();
		if (!prodId || !qty) return;
		if (!whId) { alert('Please select a warehouse.'); return; }
		$.post('/ajax/orders/package_order_add.php', { prodid: prodId, qty: qty, warehouse_id: whId }, function() {
			location.reload();
		});
	});

	$("#sendButton").click(function() {
		if (confirm("Are you sure you want to send this order?")) {
			$.post('/ajax/orders/package_order_send.php', {}, function() {
				location.reload();
			});
		}
	});

	$("#clearButton").click(function() {
		if (confirm("Are you sure you want to clear this pending order?")) {
			$.post('/ajax/orders/package_order_clear.php', {}, function() {
				location.reload();
			});
		}
	});

	// Save qty
	$(document).on("click", ".fp-qty-save", function() {
		var $btn = $(this);
		var id   = $btn.data('id');
		var qty  = $(".fp-qty-input[data-id='" + id + "']").val();
		if (!qty || qty <= 0) return;
		var reason = prompt('Reason for changing this quantity to ' + qty + ':');
			if (reason === null) return;
			reason = reason.trim();
			if (!reason) { alert('A reason is required to change the quantity.'); return; }
			$.post('/ajax/orders/package_order_edit.php', { id: id, qty: qty, reason: reason }, function() {
			var $notice = $('<span class="text-success ms-1 small">Saved</span>');
			$btn.after($notice);
			setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 2000);
		});
	});

	// Save on Enter key in qty input
	$(document).on("keypress", ".fp-qty-input", function(e) {
		if (e.which === 13) $(this).closest('tr').find('.fp-qty-save').click();
	});

	// Delete row (pending)
	$(document).on("click", ".fp-delete", function() {
		var $row = $(this).closest('tr');
		var id   = $(this).data('id');
		$.post('/ajax/orders/package_order_delete.php', { id: id }, function() {
			$row.remove();
		});
	});

	// Save qty (sent orders)
	$(document).on("click", ".sent-qty-save", function() {
		var $btn = $(this);
		var id   = $btn.data('id');
		var qty  = $(".sent-qty-input[data-id='" + id + "']").val();
		if (!qty || qty <= 0) return;
		var reason = prompt('Reason for changing this quantity to ' + qty + ':');
			if (reason === null) return;
			reason = reason.trim();
			if (!reason) { alert('A reason is required to change the quantity.'); return; }
			$.post('/ajax/orders/package_order_edit_sent.php', { id: id, qty: qty, reason: reason }, function(resp) {
			if (resp === 'ok') {
				var $notice = $('<span class="text-success ms-1 small">Saved</span>');
				$btn.after($notice);
				setTimeout(function() { $notice.fadeOut(400, function() { $(this).remove(); }); }, 2000);
			} else {
				alert('Could not save. The order may have already been built.');
			}
		});
	});

	// Save sent qty on Enter
	$(document).on("keypress", ".sent-qty-input", function(e) {
		if (e.which === 13) $(this).closest('tr').find('.sent-qty-save').click();
	});

	// Cancel sent order
	$(document).on("click", ".sent-cancel", function() {
		var $row = $(this).closest('tr');
		var id   = $(this).data('id');
		if (!confirm("Cancel this packaging order? This cannot be undone.")) return;
		$.post('/ajax/orders/package_order_cancel.php', { id: id }, function(resp) {
			if (resp === 'ok') {
				$row.fadeOut(300, function() {
					$(this).remove();
					if ($('#sent-orders-table tbody tr').length === 0) {
						$('#sent-orders-table tbody').html('<tr><td colspan="5" class="text-muted text-center py-3" style="font-size:0.875rem;">No sent orders awaiting packaging.</td></tr>');
					}
				});
			} else {
				alert('Could not cancel. The order may have already been built.');
			}
		});
	});

</script>

<!-- Demand explanation modal -->
<div class="modal fade" id="demandModal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header"><h5 class="modal-title" id="demandModalTitle">Where this demand comes from</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
			<div class="modal-body" id="demandModalBody"></div>
		</div>
	</div>
</div>

<script>
function fmt(n){ return Number(n||0).toLocaleString(); }
var REC_WH = 0, REC_WH_NAME = '';

$('#recBtn').on('click', function() {
	var until = $('#recUntil').val();
	if (!until) { alert('Choose a target date.'); return; }
	REC_WH = $('#recWarehouse').val();
	REC_WH_NAME = $('#recWarehouse option:selected').text();
	var $btn = $(this).prop('disabled', true).html('<i class="ti ti-loader me-1"></i>Analyzing…');
	$('#recMsg').removeClass('text-danger').addClass('text-muted').text('Pulling sales history, draft orders & stock…');
	$('#recResults').html('');
	$.ajax({ url:'/ajax/build/recommend.php', method:'POST', dataType:'json', timeout:120000, data:{ until: until, warehouse_id: REC_WH } })
	.done(function(d){ if (!d || d.error) { $('#recMsg').removeClass('text-muted').addClass('text-danger').text(d && d.error ? d.error : 'Could not build a recommendation.'); return; } renderRec(d); })
	.fail(function(xhr, status){ $('#recMsg').removeClass('text-muted').addClass('text-danger').text(status==='timeout'?'Timed out pulling Shopify data — try again.':'Request failed ('+(xhr.status||'?')+').'); })
	.always(function(){ $btn.prop('disabled', false).html('<i class="ti ti-bulb me-1"></i>Recommend'); });
});

function renderRec(d) {
	var m = d.meta || {}, rows = d.rows || [];
	$('#recMsg').removeClass('text-danger').addClass('text-muted').html('<strong>' + $('<div>').text(m.warehouse || 'All').html() + '</strong> — demand through ' + m.until + ' (' + m.window_days + ' days); baseline last year ' + m.prior_window + (m.draft_orders ? '; ' + m.draft_orders + ' open wholesale draft(s).' : '.'));
	if (!rows.length) { $('#recResults').html('<div class="text-muted small mt-2">Nothing needs ordering — stock and pipeline already cover projected demand. 🎉</div>'); return; }
	var toOrder = rows.filter(function(r){ return r.recommend > 0; });
	var html = '<div class="table-responsive mt-2"><table class="table table-sm align-middle" style="font-size:0.85rem;"><thead><tr>' +
		'<th>Product</th><th class="text-center">Projected Retail</th><th class="text-center">Open Drafts</th><th class="text-center">Total Demand</th><th class="text-center">FP On-Hand</th><th class="text-center">In Pipeline</th><th class="text-center">Recommend</th></tr></thead><tbody>';
	rows.forEach(function(r) {
		var recColor = r.recommend > 0 ? '#6f42c1' : '#adb5bd';
		html += '<tr>' +
			'<td class="fw-semibold"><a href="#" class="demand-explain" data-prodid="' + r.prodid + '" style="text-decoration:underline dotted;text-underline-offset:3px;color:inherit;" title="Where does this demand come from?">' + $('<div>').text(r.product).html() + '</a>' + (r.sku ? ' <span class="text-muted" style="font-size:0.7rem;">· ' + $('<div>').text(r.sku).html() + '</span>' : '') + '</td>' +
			'<td class="text-center">' + fmt(r.retail) + '</td>' +
			'<td class="text-center">' + (r.draft > 0 ? '<span class="badge bg-light text-dark">' + fmt(r.draft) + '</span>' : '—') + '</td>' +
			'<td class="text-center fw-semibold">' + fmt(r.demand) + '</td>' +
			'<td class="text-center">' + fmt(r.fp_stock) + '</td>' +
			'<td class="text-center">' + (r.pipeline > 0 ? fmt(r.pipeline) : '—') + '</td>' +
			'<td class="text-center"><span style="color:' + recColor + ';font-weight:800;font-size:1.05rem;">' + fmt(r.recommend) + '</span></td>' +
			'</tr>';
	});
	html += '</tbody></table></div>';
	window._recToOrder = toOrder.map(function(r){ return { prodid: r.prodid, qty: r.recommend, product: r.product }; });
	if (window._recToOrder.length) {
		html += '<div class="mt-3 d-flex align-items-center gap-2 flex-wrap"><button id="recAddBtn" class="btn btn-sm btn-success"><i class="ti ti-plus me-1"></i>Add ' + window._recToOrder.length + ' to pending order</button><span class="text-muted small">into <strong>' + $('<div>').text(REC_WH_NAME).html() + '</strong> — review below, then Send Order.</span><span id="recAddMsg" class="small ms-1"></span></div>';
	}
	$('#recResults').html(html);
}

$(document).on('click', '#recAddBtn', function() {
	var items = window._recToOrder || [];
	if (!items.length) return;
	var $btn = $(this);
	if (!confirm('Add ' + items.length + ' product(s) to the pending order in "' + REC_WH_NAME + '"? You can review and edit before sending.')) return;
	$btn.prop('disabled', true).html('<i class="ti ti-loader me-1"></i>Adding…');
	(function next(i) {
		if (i >= items.length) { location.reload(); return; }
		$.post('/ajax/orders/package_order_add.php', { prodid: items[i].prodid, qty: items[i].qty, warehouse_id: REC_WH }, function() { next(i + 1); })
		 .fail(function(){ alert('Failed adding ' + items[i].product); $btn.prop('disabled', false).html('<i class="ti ti-plus me-1"></i>Add to pending order'); });
	})(0);
});

$(document).on('click', '.demand-explain', function(e) {
	e.preventDefault();
	var prodid = $(this).data('prodid');
	var product = $(this).text();
	$('#demandModalTitle').text('Demand: ' + product);
	$('#demandModalBody').html('<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Analyzing…</div>');
	$('#demandModal').modal('show');
	$.post('/ajax/build/demand_explain.php', { prodid: prodid, until: $('#recUntil').val(), warehouse_id: (REC_WH || $('#recWarehouse').val()) }, function(res) {
		if (res && res.ok) $('#demandModalBody').html(res.html);
		else $('#demandModalBody').html('<div class="text-danger small">' + $('<div>').text((res && res.error) || 'Could not load the explanation.').html() + '</div>');
	}, 'json').fail(function() { $('#demandModalBody').html('<div class="text-danger small">Request failed.</div>'); });
});
</script>

<?php require_once(__DIR__."/../includes/footer.php"); ?>
