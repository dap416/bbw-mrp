<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	if (!has_access('orders')) deny_access();

	require_once(__DIR__."/../includes/header.php");

	$dbLink = db_connect();
	intransit_source_ensure($dbLink);   // where each FP order came from

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
		<p class="text-muted small mb-3">Suggests what to order to cover demand through a date you choose — last year's sales for the same window (online + POS/tradeshows), units already <strong>committed</strong> (sold, awaiting fulfillment) on Shopify, finished-product on-hand and pipeline. Then fine-tune it in plain language below.</p>
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
		$srcNote  = $order['source_note'] ?? '';
?>
				<tr>
					<td><?php echo htmlspecialchars($prodName); ?><?php if (!empty($srcNote)): ?><div class="text-muted" style="font-size:0.68rem;"><i class="ti ti-info-circle"></i> <?php echo htmlspecialchars($srcNote); ?></div><?php endif; ?></td>
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
		$srcNote  = $sent['source_note'] ?? '';
?>
				<tr id="sent-row-<?php echo $rowId; ?>">
					<td><?php echo htmlspecialchars($prodName); ?><?php if (!empty($srcNote)): ?><div class="text-muted" style="font-size:0.68rem;"><i class="ti ti-info-circle"></i> <?php echo htmlspecialchars($srcNote); ?></div><?php endif; ?></td>
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

<script src="/js/recommend.js"></script>
<script>
initRecommendPanel({
	addEndpoint: '/ajax/orders/package_order_add.php',
	addMode: 'pending',
	getWarehouse: function(){ return $('#recWarehouse').val(); },
	getWarehouseName: function(){ return $('#recWarehouse option:selected').text(); }
});
</script>

<?php require_once(__DIR__."/../includes/footer.php"); ?>
