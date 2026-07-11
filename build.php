<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('build')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}

	$db = db_connect();
	// Self-heal the "Build By" due-date column (works without running setup_build_duedate.php).
	try { $db->exec("ALTER TABLE `intransit` ADD COLUMN `duedate` DATE DEFAULT NULL"); } catch (Throwable $e) {}
	intransit_source_ensure($db);   // where each FP order came from
	$warehouses = get_warehouses($db);
	$canEditBuild = can_edit('build');
	$allProducts = $db->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();   // for the manual add-order control

	// Default warehouse: Arkansas
	$defaultWH = 0;
	foreach ($warehouses as $wh) {
		if (stripos($wh['name'], 'arkansas') !== false) { $defaultWH = $wh['id']; break; }
	}
	if (!$defaultWH && !empty($warehouses)) $defaultWH = $warehouses[0]['id'];

	// Warehouse filter — persist in session
	if (isset($_GET['wh'])) {
		$_SESSION['build_wh'] = (int)$_GET['wh'];
	}
	$activeWH = (int)($_SESSION['build_wh'] ?? $defaultWH);

	// Ensure activeWH is a valid warehouse
	$whIds = array_column($warehouses, 'id');
	if (!in_array($activeWH, $whIds) && !empty($whIds)) {
		$activeWH = $defaultWH;
		$_SESSION['build_wh'] = $activeWH;
	}
	$activeWHName = '';
	foreach ($warehouses as $wh) {
		if ($wh['id'] === $activeWH) { $activeWHName = $wh['name']; break; }
	}

	// Pending packaging orders — strict warehouse match only
	$whFilter = "AND i.warehouse_id = $activeWH";
	$ordersPending = $db->query("
		SELECT i.*, p.name AS prodname, w.name AS wh_name
		FROM intransit i
		JOIN products p ON p.id = i.prodid
		LEFT JOIN warehouses w ON w.id = i.warehouse_id
		WHERE i.orddate <> '0000-00-00 00:00:00'
		  AND i.qty > i.buildqty
		  $whFilter
		ORDER BY i.id ASC
	")->fetchAll();

	// Current open pick list — strict warehouse match only
	$pickWhFilter = "AND i.warehouse_id = $activeWH";
	$picks = $db->query("
		SELECT pk.*, p.name AS prodname, i.warehouse_id AS order_wh
		FROM picks pk
		JOIN products p ON p.id = pk.prodid
		JOIN intransit i ON i.id = pk.ordid
		WHERE pk.closedate = '0000-00-00 00:00:00'
		  $pickWhFilter
	")->fetchAll();

	// Detect warehouse from the open pick list (auto for finalize)
	$pickWarehouseId   = null;
	$pickWarehouseName = null;
	if (!empty($picks)) {
		$pwRow = $db->query("
			SELECT i.warehouse_id, w.name AS wh_name
			FROM picks pk
			JOIN intransit i ON i.id = pk.ordid
			LEFT JOIN warehouses w ON w.id = i.warehouse_id
			WHERE pk.closedate = '0000-00-00 00:00:00'
			  AND i.warehouse_id IS NOT NULL
			LIMIT 1
		")->fetch();
		$pickWarehouseId   = $pwRow['warehouse_id'] ?? null;
		$pickWarehouseName = $pwRow['wh_name']       ?? null;
	}

	// Ready to ship — filtered by warehouse
	$rtsFilter = "AND i.warehouse_id = $activeWH";
	$readyToShip = $db->query("
		SELECT SUM(i.buildqty) AS buildqty, MAX(i.builddate) AS builddate,
		       i.prodid, p.name AS prodname
		FROM intransit i
		JOIN products p ON p.id = i.prodid
		WHERE i.recdate = '0000-00-00 00:00:00' AND i.buildqty > 0
		  $rtsFilter
		GROUP BY i.prodid, p.name
		ORDER BY p.name ASC
	")->fetchAll();

	// Build materials array from picks
	// Prefetch all build lines for the picked products in one query (avoids per-pick N+1)
	$buildLinesByProd = [];
	$pickProdIds = array_values(array_unique(array_map(fn($l) => (int)$l['prodid'], $picks)));
	if (!empty($pickProdIds)) {
		$inList = implode(',', $pickProdIds);
		foreach ($db->query("
			SELECT b.*, p.partno, p.`desc`
			FROM build b JOIN parts p ON p.id = b.partid
			WHERE b.prodid IN ($inList)
			ORDER BY b.prodid ASC, p.partno ASC
		") as $bl) {
			$buildLinesByProd[$bl['prodid']][] = $bl;
		}
	}

	$compArray   = [];
	$pickSummary = [];
	foreach ($picks as $line) {
		$pickQty  = (int)$line['qty'];
		$prodName = $line['prodname'];
		$pickSummary[$prodName] = ($pickSummary[$prodName] ?? 0) + $pickQty;

		$buildLines = $buildLinesByProd[$line['prodid']] ?? [];
		foreach ($buildLines as $bl) {
			$pid = $bl['partid'];
			$compArray[$pid]['partno'] = $bl['partno'];
			$compArray[$pid]['desc']   = $bl['desc'];
			$compArray[$pid]['qty']    = ($compArray[$pid]['qty'] ?? 0) + $pickQty;
		}
	}
	ksort($compArray);

	$hasPickList = !empty($picks);
	$printDate   = date('m/d/Y g:i A');

	require_once(__DIR__."/includes/header.php");
?>

<style>
.build-card        { border-top: 3px solid #4680ff; }
.build-card-warn   { border-top: 3px solid #e58a00; }
.picklist-empty    { text-align:center; padding:40px 20px; color:#6c757d; }
.picklist-empty i  { font-size:2.5rem; display:block; margin-bottom:10px; opacity:.4; }
.qty-input         { width:70px; text-align:center; }
.duedate-input     { width:150px; }
.duedate-input.is-overdue { border-color:#e64545; color:#e64545; font-weight:600; }
.remaining-badge   { font-size:0.72rem; font-weight:600; padding:2px 8px; border-radius:20px; background:#fff3cd; color:#856404; }

.wh-tab { font-size:0.8rem; font-weight:600; padding:5px 16px; border-radius:20px; border:1px solid #dee2e6; background:#fff; color:#6c757d; cursor:pointer; text-decoration:none; transition:all .15s; }
.wh-tab:hover { border-color:#4680ff; color:#4680ff; }
.wh-tab.active { background:#4680ff; border-color:#4680ff; color:#fff; }

@media print {
	.pc-sidebar, .pc-header, .pc-container > .pc-content > *:not(#print-area),
	.no-print { display: none !important; }
	#print-area { display: block !important; padding: 0; }
	.pc-container { margin: 0 !important; padding: 0 !important; }
	.pc-content   { padding: 0 !important; }
	body          { background: #fff !important; }
	.card         { border: none !important; box-shadow: none !important; }
	.print-header { display: flex !important; }
}
#print-area          { display: contents; }
.print-header        { display: none; justify-content:space-between; align-items:flex-start; margin-bottom:20px; border-bottom:2px solid #000; padding-bottom:12px; }
.print-header h2     { margin:0; font-size:1.4rem; }
.print-header .meta  { text-align:right; font-size:0.8rem; color:#555; }
</style>

<div id="print-area">

<!-- Print-only header -->
<div class="print-header">
	<div>
		<h2>BBW MRP — Packaging Pick List</h2>
		<div style="font-size:0.9rem;color:#555;">
			<?php echo $activeWHName ? 'Warehouse: '.htmlspecialchars($activeWHName).' — ' : ''; ?>Pull these materials from storage before beginning packaging.
		</div>
	</div>
	<div class="meta">
		Printed: <?php echo $printDate; ?><br>
		<?php foreach ($pickSummary as $name => $qty): ?>
			<?php echo htmlspecialchars($name); ?>: <?php echo $qty; ?><br>
		<?php endforeach; ?>
	</div>
</div>

<!-- ── WAREHOUSE FILTER ──────────────────────────────────────────────────── -->
<?php if (count($warehouses) > 1): ?>
<div class="no-print mb-3 d-flex align-items-center gap-2 flex-wrap">
	<span class="small fw-semibold text-muted me-1">Warehouse:</span>
	<?php foreach ($warehouses as $wh): ?>
	<a href="/build.php?wh=<?php echo $wh['id']; ?>"
	   class="wh-tab <?php echo $wh['id'] === $activeWH ? 'active' : ''; ?>">
		<?php echo htmlspecialchars($wh['name']); ?>
	</a>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── RECOMMEND A PACKAGING ORDER ──────────────────────────────────────── -->
<div class="row g-3 mb-3 no-print">
<div class="col-12">
<div class="card" style="border-top:3px solid #6f42c1;">
<div class="card-body">

	<div class="panel-header mb-2">
		<span class="panel-title">Recommend a Packaging Order</span>
	</div>
	<p class="text-muted small mb-3">Suggests what to build to cover demand through a date you choose — last year's sales for the same window (online + POS/tradeshows), units already <strong>committed</strong> (sold, awaiting fulfillment) on Shopify, finished-product on-hand, what's already in the pipeline, and your raw-material stock. Then fine-tune it in plain language below.</p>

	<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
		<span class="small fw-semibold text-muted">Fulfill until:</span>
		<input type="date" id="recUntil" class="form-control form-control-sm" style="width:180px;"
			min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
			value="<?php echo date('Y-m-d', strtotime('+90 days')); ?>" />
		<button id="recBtn" class="btn btn-sm btn-primary"><i class="ti ti-bulb me-1"></i>Recommend</button>
		<span id="recMsg" class="small text-muted"></span>
	</div>

	<div id="recResults"></div>

	<?php if ($canEditBuild): ?>
	<hr class="my-3">
	<div class="d-flex align-items-center gap-2 flex-wrap">
		<span class="small fw-semibold text-muted">Or add one manually:</span>
		<select id="manOrderProd" class="form-select form-select-sm" style="width:280px;">
			<option value="">Select product…</option>
			<?php foreach ($allProducts as $p): ?>
			<option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="number" min="1" id="manOrderQty" class="form-control form-control-sm" style="width:90px;" placeholder="Qty" />
		<button id="manOrderAdd" class="btn btn-sm btn-outline-primary"><i class="ti ti-plus me-1"></i>Add packaging order</button>
		<span class="small text-muted">into <strong><?php echo htmlspecialchars($activeWHName ?: 'current warehouse'); ?></strong></span>
		<span id="manOrderMsg" class="small"></span>
	</div>
	<?php endif; ?>

</div>
</div>
</div>
</div>

<!-- ── SECTION 1: PACKAGING ORDERS ──────────────────────────────────────── -->
<div class="row g-3 mb-3 no-print">
<div class="col-12">
<div class="card build-card<?php echo count($ordersPending) ? '-warn' : ''; ?>">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">
			Packaging Orders
			<?php if ($activeWHName): ?>
			<span class="text-muted fw-normal" style="font-size:0.8rem;"> — <?php echo htmlspecialchars($activeWHName); ?></span>
			<?php endif; ?>
		</span>
		<?php if (count($ordersPending)): ?>
		<span class="badge bg-warning text-dark"><?php echo count($ordersPending); ?> pending</span>
		<?php else: ?>
		<span style="background:#ecfdf5;color:#065f46;font-size:0.72rem;padding:3px 10px;border-radius:20px;font-weight:700;">All Caught Up</span>
		<?php endif; ?>
	</div>

	<?php if (empty($ordersPending)): ?>
	<div class="picklist-empty"><i class="ti ti-circle-check"></i>No pending packaging orders<?php echo $activeWHName ? ' for '.htmlspecialchars($activeWHName) : ''; ?>.</div>
	<?php else: ?>
	<div class="scroll-table">
	<table class="table dash-table">
		<thead><tr>
			<th>Product</th>
			<?php if (count($warehouses) > 1): ?><th>Warehouse</th><?php endif; ?>
			<th>Order Date</th>
			<th style="width:170px;">Build By</th>
				<th class="text-center">Ordered</th>
			<th class="text-center">Completed</th>
			<th class="text-center">Remaining</th>
			<th style="width:220px;">Add to Pick List</th>
			<th class="text-center" style="width:90px;">Remove</th>
		</tr></thead>
		<tbody>
		<?php foreach ($ordersPending as $order):
			$remaining = (int)$order['qty'] - (int)$order['buildqty'];
			$su = $order['source_until'] ?? '';
			$explUntil = ($su && $su !== '0000-00-00' && $su > date('Y-m-d')) ? $su : date('Y-m-d', strtotime('+90 days'));
		?>
		<tr>
			<td class="fw-semibold">
					<a href="#" class="demand-explain" data-prodid="<?php echo (int)$order['prodid']; ?>" data-orderid="<?php echo (int)$order['id']; ?>" data-until="<?php echo $explUntil; ?>" data-orderqty="<?php echo (int)$order['qty']; ?>" style="text-decoration:underline dotted;text-underline-offset:3px;color:inherit;" title="Where does this demand come from?"><?php echo htmlspecialchars($order['prodname']); ?></a>
					<?php if (!empty($order['source_note'])): ?>
					<div class="text-muted" style="font-size:0.68rem;"><i class="ti ti-info-circle"></i> <?php echo htmlspecialchars($order['source_note']); ?></div>
					<?php endif; ?>
				</td>
			<?php if (count($warehouses) > 1): ?>
			<td class="text-muted small"><?php echo htmlspecialchars($order['wh_name'] ?? '—'); ?></td>
			<?php endif; ?>
			<td class="text-muted"><?php echo date('m/d/y', strtotime($order['orddate'])); ?></td>
				<?php
					$due = !empty($order['duedate']) && $order['duedate'] !== '0000-00-00' ? $order['duedate'] : '';
					$overdue = $due && $due < date('Y-m-d');
				?>
				<td><input type="date" class="form-control form-control-sm duedate-input<?php echo $overdue ? ' is-overdue' : ''; ?>" min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($due); ?>" data-orderid="<?php echo $order['id']; ?>" title="Set the date this build needs to be completed by" /></td>
			<td class="text-center text-muted">
					<?php echo number_format($order['qty']); ?>
					<?php if ($canEditBuild): ?>
					<button class="btn btn-sm btn-link p-0 ms-1 edit-order-btn" style="vertical-align:baseline;"
						data-orderid="<?php echo $order['id']; ?>"
						data-qty="<?php echo (int)$order['qty']; ?>"
						data-built="<?php echo (int)$order['buildqty']; ?>"
						data-prodname="<?php echo htmlspecialchars($order['prodname'], ENT_QUOTES); ?>"
						title="Edit ordered quantity (reason required)"><i class="ti ti-pencil"></i></button>
					<?php endif; ?>
				</td>
			<td class="text-center text-muted"><?php echo number_format($order['buildqty']); ?></td>
			<td class="text-center"><span class="remaining-badge"><?php echo number_format($remaining); ?></span></td>
			<td>
				<div class="d-flex align-items-center gap-2">
					<input type="number" min="1" max="<?php echo $remaining; ?>"
						placeholder="Qty" class="form-control form-control-sm qty-input"
						id="qty_<?php echo $order['id']; ?>" />
					<button class="btn btn-sm btn-primary add-pick-btn"
						data-orderid="<?php echo $order['id']; ?>"
						data-prodid="<?php echo $order['prodid']; ?>"
						data-max="<?php echo $remaining; ?>">
						Add to List
					</button>
				</div>
			</td>
			<td class="text-center">
				<button class="btn btn-sm btn-outline-danger remove-order-btn"
					data-orderid="<?php echo $order['id']; ?>"
					data-buildqty="<?php echo (int)$order['buildqty']; ?>"
					data-prodname="<?php echo htmlspecialchars($order['prodname'], ENT_QUOTES); ?>"
					title="Remove this packaging order">
					<i class="ti ti-trash"></i>
				</button>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>

</div>
</div>
</div>
</div>

<!-- ── SECTION 2: PICK LIST ─────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
<div class="col-12">
<div class="card build-card">
<div class="card-body">

	<div class="panel-header mb-3 no-print" style="align-items:flex-start;">
		<div class="d-flex flex-column align-items-start gap-2">
			<span class="panel-title">Pick List</span>
			<?php if ($hasPickList): ?>
			<div class="d-flex gap-2">
				<button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
					<i class="ti ti-printer me-1"></i>Print Pick List
				</button>
				<button id="clearButton" class="btn btn-sm btn-outline-danger">
					<i class="ti ti-trash me-1"></i>Clear List
				</button>
			</div>
			<?php endif; ?>
		</div>
		<?php if ($hasPickList): ?>
		<div class="d-flex flex-column align-items-end">
			<button id="finalButton" class="btn btn-sm btn-success"
				data-whid="<?php echo (int)$pickWarehouseId; ?>"
				data-whname="<?php echo htmlspecialchars($pickWarehouseName ?? $activeWHName, ENT_QUOTES); ?>">
				<i class="ti ti-check me-1"></i>Finalize &amp; Deduct Inventory
			</button>
			<?php if ($pickWarehouseName): ?>
			<span class="text-muted" style="font-size:0.72rem;margin-top:3px;">from <strong><?php echo htmlspecialchars($pickWarehouseName); ?></strong></span>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	</div>
	<div class="panel-title mb-3 d-none d-print-block">Pick List</div>

	<?php if (!$hasPickList): ?>
	<div class="picklist-empty">
		<i class="ti ti-clipboard-list"></i>
		No items in the pick list yet.<br>
		<small>Enter a quantity next to a packaging order above and click <strong>Add to List</strong>.</small>
	</div>
	<?php else: ?>

	<div class="row g-4">
		<div class="col-12 col-lg-5">
			<div class="small fw-semibold text-uppercase text-muted mb-2" style="letter-spacing:.05em;">Products to Package</div>
			<table class="table dash-table">
				<thead><tr><th>Product</th><th class="text-center">Qty</th></tr></thead>
				<tbody>
				<?php foreach ($pickSummary as $name => $qty): ?>
				<tr>
					<td class="fw-semibold"><?php echo htmlspecialchars($name); ?></td>
					<td class="text-center fw-semibold"><?php echo number_format($qty); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="col-12 col-lg-1 d-none d-lg-flex align-items-stretch justify-content-center">
			<div style="border-left:1px solid #e9ecef;"></div>
		</div>

		<div class="col-12 col-lg-6">
			<div class="small fw-semibold text-uppercase text-muted mb-2" style="letter-spacing:.05em;">Materials to Pull from Storage</div>
			<table class="table dash-table">
				<thead><tr><th>Part #</th><th>Description</th><th class="text-center">Qty Needed</th></tr></thead>
				<tbody>
				<?php foreach ($compArray as $part): ?>
				<tr>
					<td class="fw-semibold"><?php echo htmlspecialchars($part['partno']); ?></td>
					<td><?php echo htmlspecialchars($part['desc']); ?></td>
					<td class="text-center fw-bold"><?php echo number_format($part['qty']); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php endif; ?>

</div>
</div>
</div>
</div>

<!-- ── SECTION 3: READY TO SHIP ──────────────────────────────────────────── -->
<div class="row g-3 mb-3 no-print">
<div class="col-12">
<div class="card" style="border-top:3px solid <?php echo count($readyToShip) ? '#2ca87f' : '#dee2e6'; ?>">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">
			Ready to Ship
			<?php if ($activeWHName): ?>
			<span class="text-muted fw-normal" style="font-size:0.8rem;"> — <?php echo htmlspecialchars($activeWHName); ?></span>
			<?php endif; ?>
		</span>
		<div class="d-flex align-items-center gap-2">
			<?php if (count($readyToShip)): ?>
			<span class="badge bg-success"><?php echo count($readyToShip); ?> product<?php echo count($readyToShip) !== 1 ? 's' : ''; ?></span>
			<button id="markRecButton" class="btn btn-sm btn-success">
				<i class="ti ti-check me-1"></i>Mark All as Received
			</button>
			<?php endif; ?>
		</div>
	</div>

	<?php if (empty($readyToShip)): ?>
	<div class="picklist-empty"><i class="ti ti-truck"></i>No packaged products awaiting shipment<?php echo $activeWHName ? ' for '.htmlspecialchars($activeWHName) : ''; ?>.</div>
	<?php else: ?>
	<div class="scroll-table">
	<table class="table dash-table">
		<thead><tr>
			<th>Product</th>
			<th class="text-center">Qty Packaged</th>
			<th>Last Packaged</th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php foreach ($readyToShip as $item): ?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($item['prodname']); ?></td>
			<td class="text-center fw-bold"><?php echo number_format($item['buildqty']); ?></td>
			<td class="text-muted"><?php echo date('m/d/y g:i A', strtotime($item['builddate'])); ?></td>
			<td class="text-end">
				<div class="d-flex gap-2 justify-content-end">
				<button class="btn btn-sm btn-outline-secondary undo-build-btn"
					data-prodid="<?php echo $item['prodid']; ?>"
					data-prodname="<?php echo htmlspecialchars($item['prodname'], ENT_QUOTES); ?>"
					data-qty="<?php echo number_format($item['buildqty']); ?>"
					data-whid="<?php echo (int)$activeWH; ?>"
					title="Restore materials and move back to pending">
					<i class="ti ti-arrow-back-up me-1"></i>Undo Build
				</button>
				<button class="btn btn-sm btn-outline-danger remove-built-btn"
					data-prodid="<?php echo $item['prodid']; ?>"
					data-prodname="<?php echo htmlspecialchars($item['prodname'], ENT_QUOTES); ?>"
					data-qty="<?php echo number_format($item['buildqty']); ?>"
					data-whid="<?php echo (int)$activeWH; ?>"
					title="Restore materials and delete the order entirely">
					<i class="ti ti-trash me-1"></i>Remove
				</button>
				</div>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>

</div>
</div>
</div>
</div>

</div><!-- end print-area -->

<!-- Demand explanation modal -->
<div class="modal fade" id="demandModal" tabindex="-1">
	<div class="modal-dialog">
		<div class="modal-content">
			<div class="modal-header"><h5 class="modal-title" id="demandModalTitle">Where this demand comes from</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
			<div class="modal-body" id="demandModalBody"></div>
		</div>
	</div>
</div>

<script src="/js/recommend.js"></script>
<script>
// ── Demand explanation (click a product name) ──
$(document).on('click', '.demand-explain', function(e) {
	e.preventDefault();
	var prodid  = $(this).data('prodid');
	var until    = $(this).data('until') || $('#recUntil').val();
	var orderqty = $(this).data('orderqty') || 0;
	var orderid  = $(this).data('orderid') || 0;
	var product  = $(this).text();
	$('#demandModalTitle').text('Build: ' + product);
	$('#demandModalBody').html('<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Analyzing where this demand comes from…</div>');
	$('#demandModal').modal('show');
	$.post('/ajax/build/demand_explain.php', { prodid: prodid, until: until, order_qty: orderqty, orderid: orderid, warehouse_id: (typeof REC_WH !== 'undefined' ? REC_WH : 0) }, function(res) {
		if (res && res.ok) $('#demandModalBody').html(res.html);
		else $('#demandModalBody').html('<div class="text-danger small">' + $('<div>').text((res && res.error) || 'Could not load the explanation.').html() + '</div>');
	}, 'json').fail(function() { $('#demandModalBody').html('<div class="text-danger small">Request failed.</div>'); });
});

// ── Recommend panel (shared /js/recommend.js) ──
var REC_WH      = <?php echo (int)$activeWH; ?>;
var REC_WH_NAME = <?php echo json_encode($activeWHName); ?>;
initRecommendPanel({
	addEndpoint: '/ajax/build/create_orders.php',
	addMode: 'placed',
	getWarehouse: function(){ return REC_WH; },
	getWarehouseName: function(){ return REC_WH_NAME; }
});

// ── Manually add a single packaging order (goes straight into the queue) ──
$('#manOrderAdd').on('click', function(){
	var pid = parseInt($('#manOrderProd').val(), 10) || 0;
	var qty = parseInt($('#manOrderQty').val(), 10) || 0;
	if (!pid) { $('#manOrderMsg').removeClass('text-success').addClass('text-danger').text('Pick a product.'); return; }
	if (qty <= 0) { $('#manOrderMsg').removeClass('text-success').addClass('text-danger').text('Enter a quantity.'); return; }
	var $btn = $(this).prop('disabled', true);
	$('#manOrderMsg').removeClass('text-danger text-success').text('Adding…');
	$.post('/ajax/build/create_orders.php', { orders: JSON.stringify([{ prodid: pid, qty: qty }]), warehouse_id: REC_WH, source: 'manual' }, function(res){
		if (typeof res === 'string' && res.indexOf('ok') === 0) { location.reload(); }
		else { $('#manOrderMsg').addClass('text-danger').text(res || 'Failed'); $btn.prop('disabled', false); }
	}).fail(function(x){ $('#manOrderMsg').addClass('text-danger').text('Failed: ' + (x.responseText || x.status)); $btn.prop('disabled', false); });
});
$(document).on('keypress', '#manOrderQty', function(e){ if (e.which === 13) $('#manOrderAdd').click(); });

// ── Set "Build By" due date on a packaging order ──
$(document).on('change', '.duedate-input', function() {
	var $inp    = $(this);
	var orderId = $inp.data('orderid');
	var date    = $inp.val();

	// Reflect overdue state immediately.
	var today = new Date().toISOString().slice(0, 10);
	$inp.toggleClass('is-overdue', !!date && date < today);

	$inp.prop('disabled', true);
	$.post('/ajax/build/set_duedate.php', { orderid: orderId, date: date }, function(res) {
		if (res !== 'ok') { alert('Could not save the Build By date: ' + res); }
	}).fail(function(xhr) {
		alert('Could not save the Build By date: ' + (xhr.responseText || xhr.status));
	}).always(function() {
		$inp.prop('disabled', false);
	});
});

// ── Edit an FP stock order's quantity (reason required, audit-logged) ──
$(document).on('click', '.edit-order-btn', function() {
	var $btn  = $(this);
	var id    = $btn.data('orderid');
	var cur   = parseInt($btn.data('qty')) || 0;
	var built = parseInt($btn.data('built')) || 0;
	var name  = $btn.data('prodname');

	var q = prompt('New ordered quantity for "' + name + '" (currently ' + cur + (built > 0 ? '; ' + built + ' already built' : '') + '):', cur);
	if (q === null) return;
	q = parseInt(q);
	if (!q || q < 1) { alert('Enter a valid quantity (at least 1).'); return; }
	if (built > 0 && q < built) { alert('Cannot set below the ' + built + ' already built. Undo builds first.'); return; }
	if (q === cur) return;

	var reason = prompt('Reason for changing the order from ' + cur + ' to ' + q + ':');
	if (reason === null) return;
	reason = reason.trim();
	if (!reason) { alert('A reason is required to change the order.'); return; }

	$btn.prop('disabled', true);
	$.post('/ajax/build/edit_order.php', { orderid: id, qty: q, reason: reason }, function(res) {
		if (res === 'ok') { location.reload(); }
		else { alert(res); $btn.prop('disabled', false); }
	});
});

$('.add-pick-btn').on('click', function() {
	var $btn    = $(this);
	var orderId = $btn.data('orderid');
	var prodId  = $btn.data('prodid');
	var max     = parseInt($btn.data('max'));
	var qty     = parseInt($('#qty_' + orderId).val());

	if (!qty || qty < 1)  { alert('Please enter a quantity.'); return; }
	if (qty > max)        { alert('Quantity cannot exceed the remaining amount (' + max + ').'); return; }

	$btn.prop('disabled', true).text('Adding…');
	$.post('/ajax/build/add_prod.php', { prodid: prodId, qty: qty, orderid: orderId }, function() {
		location.reload();
	});
});

$('.remove-order-btn').on('click', function() {
	var $btn     = $(this);
	var orderId  = $btn.data('orderid');
	var built    = parseInt($btn.data('buildqty')) || 0;
	var prodName = $btn.data('prodname');

	var msg = 'Remove the packaging order for "' + prodName + '"?\n\n';
	if (built > 0) {
		msg += built + ' unit(s) were already packaged, so the raw materials for those units will be ADDED BACK into inventory, with a note recorded on each part.';
	} else {
		msg += 'No materials have been deducted for this order yet, so inventory will not change.';
	}
	msg += '\n\nContinue?';
	if (!confirm(msg)) return;

	$btn.prop('disabled', true).html('<i class="ti ti-loader"></i>');
	$.post('/ajax/build/remove_order.php', { orderid: orderId }, function(res) {
		if (res === 'ok') { location.reload(); }
		else { alert('Error: ' + res); $btn.prop('disabled', false).html('<i class="ti ti-trash"></i>'); }
	});
});

$('#finalButton').on('click', function() {
	var $btn   = $(this);
	var whId   = $btn.data('whid')   || <?php echo (int)$activeWH; ?>;
	var whName = $btn.data('whname') || <?php echo json_encode($activeWHName); ?>;

	if (!whId) { alert('No warehouse is associated with this pick list. Please ensure packaging orders have a warehouse assigned.'); return; }
	if (!confirm('Confirm: this will deduct all materials from "' + whName + '" and mark these products as packaged. Continue?')) return;

	$btn.prop('disabled', true).text('Finalizing…');
	$.post('/ajax/build/finalize.php', { warehouse_id: whId }, function() {
		$.post('/ajax/bsl_calc.php', {}, function() {
			location.reload();
		});
	});
});

$('#clearButton').on('click', function() {
	if (!confirm('Clear the entire pick list? This will not affect inventory.')) return;
	$.post('/ajax/build/clear_list.php', {}, function() { location.reload(); });
});

$('.undo-build-btn').on('click', function() {
	var $btn     = $(this);
	var prodName = $btn.data('prodname');
	var qty      = $btn.data('qty');
	var prodId   = $btn.data('prodid');
	var whId     = $btn.data('whid');

	if (!confirm(
		'Undo build for "' + prodName + '" (' + qty + ' units)?\n\n' +
		'This will:\n' +
		'  • Add all deducted materials back to inventory\n' +
		'  • Record a reversal in the transaction history\n' +
		'  • Move the packaging order back to pending\n\n' +
		'Continue?'
	)) return;

	$btn.prop('disabled', true).text('Undoing…');
	$.post('/ajax/build/undo_build.php', { prodid: prodId, warehouse_id: whId }, function(res) {
		if (res === 'ok') {
			location.reload();
		} else {
			alert('Error: ' + res);
			$btn.prop('disabled', false).html('<i class="ti ti-arrow-back-up me-1"></i>Undo Build');
		}
	});
});

$('.remove-built-btn').on('click', function() {
	var $btn     = $(this);
	var prodName = $btn.data('prodname');
	var qty      = $btn.data('qty');
	var prodId   = $btn.data('prodid');
	var whId     = $btn.data('whid');

	if (!confirm(
		'Remove the packaged order for "' + prodName + '" (' + qty + ' units)?\n\n' +
		'This will:\n' +
		'  • Add all deducted raw materials back to inventory (with a note on each part)\n' +
		'  • Delete the order entirely (it will NOT return to pending)\n\n' +
		'Use "Undo Build" instead if you want it to go back to pending.\n\nContinue?'
	)) return;

	$btn.prop('disabled', true).html('<i class="ti ti-loader"></i>');
	$.post('/ajax/build/remove_built.php', { prodid: prodId, warehouse_id: whId }, function(res) {
		if (res === 'ok') {
			location.reload();
		} else {
			alert('Error: ' + res);
			$btn.prop('disabled', false).html('<i class="ti ti-trash me-1"></i>Remove');
		}
	});
});

$('#markRecButton').on('click', function() {
	if (!confirm('Mark all packaged products as received? This will clear them from the Ready to Ship list.')) return;
	$(this).prop('disabled', true).text('Saving…');
	$.post('/ajax/rec_intransit.php', {}, function() { location.reload(); });
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
