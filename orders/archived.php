<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	if (!has_access('orders')) deny_access();

	require_once(__DIR__."/../includes/header.php");

	$dbLink = db_connect();

	// The archived columns are added by /setup_order_archive.php.
	$hasArchive = false;
	try { $hasArchive = $dbLink->query("SHOW COLUMNS FROM `orders` LIKE 'archived'")->rowCount() > 0; }
	catch (Throwable $e) {}

?>

<div>

	<h2 class="fw-bold mb-3">Archived Orders</h2>

<?php if (!$hasArchive): ?>

	<div class="alert alert-warning">
		Order archiving isn't set up yet. A master user should open
		<a href="/setup_order_archive.php">setup_order_archive.php</a> once to enable it.
	</div>

<?php else: ?>

	<div class="mb-3 d-flex gap-2 align-items-center flex-wrap">
		<input type="text" id="archFilter" class="form-control form-control-sm" style="max-width:320px" placeholder="Filter by product or order #…"/>
		<a href="/orders/export_archived.php" class="btn btn-sm btn-light-primary">Export CSV</a>
	</div>

	<div class="card">
	<div class="card-body p-0">
	<table class="table table-hover mb-0">
		<thead>
			<tr style="background-color:#e2e5e8;">
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Product Name</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Order Ref</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Ordered / Rec</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Order Date</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Archived</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Value / Paid</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Actions</th>
			</tr>
		</thead>
		<tbody>

		<?php

		// ── BATCH PREFETCH (mirrors orders.php; avoids per-order N+1 queries) ──
		$partsById = [];
		foreach ($dbLink->query("SELECT * FROM `parts`") as $r) { $partsById[$r['id']] = $r; }
		$notesByOrder = [];
		foreach ($dbLink->query("SELECT * FROM `notes` ORDER BY `date` DESC") as $r) { $notesByOrder[$r['ordid']][] = $r; }
		$paymentsByOrder = [];
		foreach ($dbLink->query("SELECT * FROM `payments` ORDER BY `date` DESC") as $r) { $paymentsByOrder[$r['ordid']][] = $r; }
		$postByOrder = [];
		foreach ($dbLink->query("SELECT op.*, w.name AS wh_name FROM `ordpost` op LEFT JOIN `warehouses` w ON w.id = op.warehouse_id ORDER BY op.`date` DESC") as $r) { $postByOrder[$r['ordid']][] = $r; }

		$archived = $dbLink->query("SELECT * FROM `orders` WHERE `archived` = 1 ORDER BY `archived_date` DESC, `id` DESC");

		if ($archived->rowCount() === 0) {
			echo '<tr><td colspan="7" class="text-muted py-4 text-center">No archived orders yet. Orders appear here once they are archived from the <a href="/orders.php" class="link">Open Orders</a> page.</td></tr>';
		}

		while ($order = $archived->fetch()) {

			$orderId  = $order['id'];
			$partInfo = $partsById[$order['partid']] ?? [];
			$partName = ($partInfo['partno'] ?? '?').' - '.($partInfo['desc'] ?? '');
			$orderQty = (int)$order['qty'];
			$recQty   = (int)$order['recqty'];
			$orderRef = $order['orderref'];
			$orderDate = $order['orderdate'] && $order['orderdate'] !== '0000-00-00 00:00:00' ? date("m/d/y", strtotime($order['orderdate'])) : '—';
			$archDate  = !empty($order['archived_date']) && $order['archived_date'] !== '0000-00-00 00:00:00' ? date("m/d/y", strtotime($order['archived_date'])) : '—';

			$diff = $recQty - $orderQty;
			if ($diff > 0)      { $qtyBadge = ' <span class="badge bg-warning text-dark">+'.$diff.' over</span>'; }
			elseif ($diff < 0)  { $qtyBadge = ' <span class="badge bg-danger">'.$diff.' short</span>'; }
			else                { $qtyBadge = ''; }

			$filterKey = htmlspecialchars(strtolower($partName.' '.$orderRef), ENT_QUOTES);
			?>

			<tr class="arch-row" data-filter="<?php echo $filterKey; ?>">
				<td><?php echo htmlspecialchars($partName); ?></td>
				<td><?php echo htmlspecialchars($orderRef); ?></td>
				<td><?php echo $orderQty.' / '.$recQty.$qtyBadge; ?></td>
				<td><?php echo $orderDate; ?></td>
				<td><?php echo $archDate; ?></td>
				<td><?php echo $order['ordval'].' / '.$order['paidamt']; ?></td>
				<td>
					<button type="button" class="btn btn-sm btn-light-primary arch-toggle" data-record="<?php echo $orderId; ?>">DETAILS</button>
				</td>
			</tr>

			<tr class="arch-detail-row" data-record="<?php echo $orderId; ?>" style="display:none;">
				<td colspan="7" class="p-0" style="border-top:none;">
				<div class="manage-area" style="padding:1rem;">
					<div class="row g-3">

						<div class="col-md-4">
							<div class="fw-semibold small text-muted mb-1">Notes</div>
							<div class="small">
							<?php
							$notes = $notesByOrder[$orderId] ?? [];
							if (!$notes) echo '<em class="text-muted">No notes.</em>';
							foreach ($notes as $note) {
								echo '<div>'.date("m/d/y", strtotime($note['date'])).' — '.htmlspecialchars($note['note']).'</div>';
							}
							?>
							</div>
						</div>

						<div class="col-md-4">
							<div class="fw-semibold small text-muted mb-1">Payments</div>
							<div class="small">
							<?php
							$pays = $paymentsByOrder[$orderId] ?? [];
							if (!$pays) echo '<em class="text-muted">No payments.</em>';
							foreach ($pays as $payment) {
								echo '<div>'.date("m/d/y", strtotime($payment['date'])).' — $'.$payment['amount'].' — '.htmlspecialchars($payment['ref']).'</div>';
							}
							?>
							</div>
						</div>

						<div class="col-md-4">
							<div class="fw-semibold small text-muted mb-1">Shipments Received</div>
							<div class="small">
							<?php
							$posts = $postByOrder[$orderId] ?? [];
							if (!$posts) echo '<em class="text-muted">No shipments posted.</em>';
							foreach ($posts as $posting) {
								$whLabel = $posting['wh_name'] ? ' — <em class="text-muted">'.htmlspecialchars($posting['wh_name']).'</em>' : '';
								echo '<div>'.date("m/d/y", strtotime($posting['date'])).' — QTY: '.$posting['qty'].' — '.htmlspecialchars($posting['ref']).$whLabel.'</div>';
							}
							?>
							</div>
						</div>

					</div>
				</div>
				</td>
			</tr>

			<?php
		}
		?>

		</tbody>
	</table>
	</div><!-- card-body -->
	</div><!-- card -->

<?php endif; ?>

</div>

<script>
	// Expand / collapse a row's details.
	$(document).on("click", ".arch-toggle", function() {
		var rec = $(this).data('record');
		$(".arch-detail-row[data-record='" + rec + "']").toggle();
	});

	// Client-side filter by product / order #.
	$("#archFilter").on("input", function() {
		var q = $(this).val().toLowerCase().trim();
		$(".arch-detail-row").hide();
		$(".arch-row").each(function() {
			var match = $(this).data('filter').indexOf(q) !== -1;
			$(this).toggle(match);
		});
	});
</script>

<?php require_once(__DIR__."/../includes/footer.php"); ?>
