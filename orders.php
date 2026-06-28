<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");
	if (!has_access('orders')) { deny_access(); }

	$canCreateOrders = can_do('orders.create');
	$canReceiveOrders = can_do('orders.receive');
	$canEditOrders   = can_edit('orders');

	$dbLink = $mysqli = db_connect();

	$parts      = $dbLink->query("SELECT * FROM `parts` ORDER BY `partno` ASC");
	$warehouses = get_warehouses($dbLink);

?>

<div>

			<h2 class="fw-bold mb-3">Open Orders<?php echo $canCreateOrders ? '' : ' <span class="badge bg-secondary" style="font-size:0.6em;vertical-align:middle;">view / receive only</span>'; ?></h2>

			<?php if ($canCreateOrders): ?>
			<div class="mb-3">
				<button id="orderAreaButton" class="btn btn-light-primary">+ Add Order</button>
			</div>
		<?php endif; ?>

			<!-- ADD ORDER AREA -->
		<div id="orderArea" class="hidden mb-3">
			<div class="card">
			<div class="card-body">
				<div class="d-flex flex-wrap align-items-center gap-2">
					<select id="orderPart" class="form-select form-select-sm" style="width:280px">
						<option value="">Select Component</option>
						<?php
						$parts = $dbLink->query("SELECT * FROM `parts` ORDER BY `partno` ASC");
						while($part = $parts->fetch()) { ?>
						<option value="<?php echo $part['id']; ?>"><?php echo $part['partno'].' - '.$part['desc']; ?></option>
						<?php } ?>
					</select>
					<input type="text" id="orderQty" class="form-control form-control-sm" style="width:70px" placeholder="QTY"/>
					<input type="text" id="orderRef" class="form-control form-control-sm" style="width:120px" placeholder="Order #"/>
					<button id="orderButton" class="btn btn-primary btn-sm">Add Order</button>
					<button id="orderCancelButton" class="btn btn-secondary btn-sm">Cancel</button>
				</div>
			</div>
			</div>
		</div>

			</div>

		<!-- MANUAL POST
		<div class="bold" style="margin-bottom: 5px; margin-left: 15px; margin-top: 15px;">Manual Post:</div>
			<div style="margin-bottom: 15px; margin-left: 15px;">
				<div class="inline middle">
					<select id="postPart">

						<option value="">Select Component</option>

						<?php /*
						
						$parts = $mysqli->query("SELECT * FROM `parts` ORDER BY `partno` ASC");

						while($part = mysql_fetch_array($parts)) {

							$partno = $part['partno'];
							$desc = $part['desc'];
							$id = $part['id'];

							?>
						<option value="<?php echo $id; ?>"><?php echo "$partno - $desc"; ?></option>
							<?php

						}


					*/	?>

					</select>
				</div>

				<div class="inline middle" style="">
					<input type="text" id="postQty" style="width: 50px" placeholder="QTY"/>
				</div>

				<div class="inline middle" style="">
					<input type="text" id="postRef" style="width: 100px" placeholder="Ref #"/>
				</div>

				<div class="inline middle" style="">
					<input type="button" id="postButton" value="POST"/>
				</div>

			</div>	-->

		<!-- POST INVENTORY FORM -->
			<div id="postArea" class="">

			<div class="card mt-3">
			<div class="card-body p-0">
			<table class="table table-hover mb-0">
				<thead>
					<tr style="background-color:#e2e5e8;">
						<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Product Name</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Order Ref</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">QTY / Rec</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Order Date</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Value / Paid</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Actions</th>
					</tr>
				</thead>
				<tbody>

			<?php

			// ── BATCH PREFETCH (avoids per-order N+1 queries inside the loop) ──
			$partsById = [];
			foreach ($dbLink->query("SELECT * FROM `parts`") as $r) { $partsById[$r['id']] = $r; }
			$notesByOrder = [];
			foreach ($dbLink->query("SELECT * FROM `notes` ORDER BY `date` DESC") as $r) { $notesByOrder[$r['ordid']][] = $r; }
			$paymentsByOrder = [];
			foreach ($dbLink->query("SELECT * FROM `payments` ORDER BY `date` DESC") as $r) { $paymentsByOrder[$r['ordid']][] = $r; }
			$postByOrder = [];
			foreach ($dbLink->query("SELECT * FROM `ordpost` ORDER BY `date` DESC") as $r) { $postByOrder[$r['ordid']][] = $r; }

			$openOrders = $dbLink->query("SELECT * FROM `orders` WHERE `postdate` = '0000-00-00 00:00:00' AND `recqty` < `qty` ORDER BY `orderdate` ASC");

			if ($openOrders->rowCount() === 0) {
				echo '<tr><td colspan="6" class="text-muted py-4 text-center">There are no open orders at this time. Click <a href="#" id="emptyAddOrder" class="link">Add Order</a> to add an order that has been placed.</td></tr>';
			}

			while($order = $openOrders->fetch()) {

				$partId = $order['partid'];
				$orderId = $order['id'];
				$partInfo = $partsById[$partId] ?? [];
				$partName = $partInfo['partno'].' - '.$partInfo['desc'];
				$orderQty = $order['qty'];
				$recQty = $order['recqty'];
				$orderDate = date("m/d/y", strtotime($order['orderdate']));
				$orderVal = $order['ordval'];
				$paidVal = $order['paidamt'];
				$orderRef = $order['orderref'];

				?>

				<tr>
					<td><?php echo $partName; ?></td>
					<td id="<?php echo $orderId; ?>summaryRef"><?php echo htmlspecialchars($orderRef); ?></td>
					<td id="<?php echo $orderId; ?>summaryQty"><?php echo "$orderQty / $recQty"; ?></td>
					<td><?php echo $orderDate; ?></td>
					<td id="<?php echo $orderId; ?>summaryVal"><?php echo "$orderVal / $paidVal"; ?></td>
					<td>
						<input type="button" action="manOrdButton" record="<?php echo $orderId; ?>" value="MANAGE" class="btn btn-sm btn-light-primary" />
					</td>
				</tr>

				<!-- MANAGE ORDER AREA -->
				<tr>
				<td colspan="6" class="p-0" style="border-top: none;">
				<div id="<?php echo $orderId; ?>manArea" class="manage-area hidden">
					<div class="row g-4">

						<!-- LEFT COLUMN: Actions -->
						<div class="col-md-5">

							<?php if ($canEditOrders): ?>
							<div class="mb-3">
								<label class="form-label fw-semibold small text-muted">Add Note</label>
								<div class="d-flex gap-2">
									<input type="text" id="<?php echo $orderId; ?>note" class="form-control form-control-sm" placeholder="e.g. 500 shipped 8/27"/>
									<button action="addNote" record="<?php echo $orderId; ?>" class="btn btn-primary btn-sm">Submit</button>
								</div>
							</div>

							<div class="mb-3">
								<label class="form-label fw-semibold small text-muted">Edit On Order QTY / Order #</label>
								<div class="d-flex gap-2 align-items-center flex-wrap">
									<input type="text" id="<?php echo $orderId; ?>editQty" class="form-control form-control-sm" style="width:90px" value="<?php echo $orderQty; ?>"/>
									<button action="editQty" record="<?php echo $orderId; ?>" class="btn btn-primary btn-sm">Submit</button>
									<div class="vr mx-1"></div>
									<input type="text" id="<?php echo $orderId; ?>editRef" class="form-control form-control-sm" style="width:140px" value="<?php echo htmlspecialchars($orderRef); ?>" placeholder="Order #"/>
									<button action="editRef" record="<?php echo $orderId; ?>" class="btn btn-primary btn-sm">Submit</button>
								</div>
							</div>

							<div class="mb-3">
								<label class="form-label fw-semibold small text-muted">Order Date</label>
								<div class="d-flex gap-2 align-items-center flex-wrap">
									<input type="date" id="<?php echo $orderId; ?>editDate" class="form-control form-control-sm" style="width:170px" max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime($order['orderdate'])); ?>" />
									<button action="editDate" record="<?php echo $orderId; ?>" class="btn btn-primary btn-sm">Submit</button>
								</div>
								<div class="text-muted" style="font-size:0.72rem;">Can be backdated; cannot be set in the future.</div>
							</div>

							<div class="mb-3">
								<label class="form-label fw-semibold small text-muted">Change Product on Order</label>
								<div class="d-flex gap-2 align-items-center flex-wrap">
									<select id="<?php echo $orderId; ?>changePart" class="form-select form-select-sm" style="width:280px">
										<?php foreach ($partsById as $pid => $pinfo): ?>
										<option value="<?php echo $pid; ?>" <?php echo $pid == $partId ? 'selected' : ''; ?>><?php echo htmlspecialchars($pinfo['partno'].' - '.$pinfo['desc']); ?></option>
										<?php endforeach; ?>
									</select>
									<button action="changePart" record="<?php echo $orderId; ?>" class="btn btn-warning btn-sm">Change</button>
								</div>
								<div class="text-muted" style="font-size:0.72rem;">Wrong part? Re-point the order. The old part's order entry is marked corrected, a new order entry is added on the right part, and any received stock is moved.</div>
							</div>

							<div class="mb-3">
								<label class="form-label fw-semibold small text-muted">Post Payment</label>
								<div class="d-flex gap-2">
									<div class="input-group input-group-sm" style="width:120px">
										<span class="input-group-text">$</span>
										<input type="text" id="<?php echo $orderId; ?>payAmt" class="form-control form-control-sm" placeholder="0.00"/>
									</div>
									<input type="text" id="<?php echo $orderId; ?>payRef" class="form-control form-control-sm" style="width:90px" placeholder="Ref #"/>
									<button action="addPay" record="<?php echo $orderId; ?>" class="btn btn-primary btn-sm">Submit</button>
									<button action="payFull" record="<?php echo $orderId; ?>" class="btn btn-success btn-sm">Pay in Full</button>
								</div>
							</div>

							<?php endif; ?>

							<?php if ($canReceiveOrders): ?>
							<div class="mb-3">
								<label class="form-label fw-semibold small text-muted">Receive Shipment</label>
								<div class="d-flex gap-2 flex-wrap">
									<input type="text" id="<?php echo $orderId; ?>recAmt" class="form-control form-control-sm" style="width:90px" placeholder="Qty"/>
									<input type="text" id="<?php echo $orderId; ?>recRef" class="form-control form-control-sm" style="width:90px" placeholder="Ref #"/>
									<input type="date" id="<?php echo $orderId; ?>recDate" class="form-control form-control-sm" style="width:150px" max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" title="Received date"/>
									<select id="<?php echo $orderId; ?>recWH" class="form-select form-select-sm" style="width:180px">
										<option value="">Select Warehouse</option>
										<?php foreach ($warehouses as $wh): ?>
										<option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
										<?php endforeach; ?>
									</select>
									<button action="recInv" record="<?php echo $orderId; ?>" class="btn btn-primary btn-sm">Submit</button>
								</div>
							</div>

							<?php endif; ?>

							<div class="d-flex gap-2 mt-4">
								<button action="closeManArea" record="<?php echo $orderId; ?>" class="btn btn-secondary btn-sm">Close</button>
								<?php if ($canEditOrders): ?>
								<button action="deleteOrder" record="<?php echo $orderId; ?>" class="btn btn-danger btn-sm">Delete Order</button>
								<?php endif; ?>
							</div>

						</div>

						<!-- RIGHT COLUMN: Notes / Payments / Shipments -->
						<div class="col-md-7">
							<div class="row g-3">

								<!-- NOTES -->
								<div class="col-12">
									<div class="fw-semibold small text-muted mb-1">Notes</div>
									<div id="<?php echo $orderId; ?>notesList" class="small">
									<?php
									foreach (($notesByOrder[$orderId] ?? []) as $note) {
										echo '<div>'.date("m/d/y", strtotime($note['date'])).' — '.htmlspecialchars($note['note']).'</div>';
									}
									?>
									</div>
								</div>

								<!-- PAYMENTS -->
								<div class="col-12">
									<div class="fw-semibold small text-muted mb-1">Payments</div>
									<div class="small" id="<?php echo $orderId; ?>paymentsList">
									<?php
									foreach (($paymentsByOrder[$orderId] ?? []) as $payment) {
										echo '<div>'.date("m/d/y", strtotime($payment['date'])).' — $'.$payment['amount'].' — '.$payment['ref'].'</div>';
									}
									?>
									</div>
								</div>

								<!-- SHIPMENTS RECEIVED -->
								<div class="col-12">
									<div class="fw-semibold small text-muted mb-1">Shipments Received</div>
									<div class="small" id="<?php echo $orderId; ?>shipmentsList">
									<?php
									$orderPostings = $postByOrder[$orderId] ?? [];
									if (count($orderPostings) === 0) {
										echo '<em class="text-muted">No shipments posted yet.</em>';
									}
									foreach ($orderPostings as $posting) {
										echo '<div class="d-flex align-items-center gap-2 mb-1">'.
											'<span>'.date("m/d/y", strtotime($posting['date'])).' — QTY: '.$posting['qty'].' — '.$posting['ref'].'</span>'.
											'<button class="btn btn-outline-danger btn-sm py-0 px-1 undo-shipment" style="font-size:0.7rem;line-height:1.4;" data-postid="'.$posting['id'].'" data-orderid="'.$orderId.'">Undo</button>'.
										'</div>';
									}
									?>
									</div>
								</div>

							</div>
						</div>

					</div>
				</div><!-- manArea -->
					</td>
					</tr>

					<?php
				}
				?>

				</tbody>
			</table>
			</div><!-- end card-body -->
			</div><!-- end card -->
		</div>

	<script>

		// HELPERS
		function showUpdated($btn) {
			var $notice = $('<span class="text-success ms-2 small updated-notice">Updated</span>');
			$btn.after($notice);
			setTimeout(function() { $notice.fadeOut(500, function() { $(this).remove(); }); }, 3000);
		}

		function refreshPanel(record) {
			$.post('/ajax/get_order_panel.php', { record: record }, function(data) {
				var d = JSON.parse(data);
				$("#"+record+"notesList").html(d.notes);
				$("#"+record+"paymentsList").html(d.payments);
				$("#"+record+"shipmentsList").html(d.shipments);
				$("#"+record+"summaryQty").html(d.summaryQty);
				$("#"+record+"summaryVal").html(d.summaryVal);
				});
		}

		// UNDO SHIPMENT
		$(document).on("click", ".undo-shipment", function() {
			var postId  = $(this).data('postid');
			var orderId = $(this).data('orderid');

			if (!confirm("This will reverse this shipment receipt and reduce on-hand inventory accordingly. Continue?")) return;

			$.post('/ajax/undo_shipment.php', { postid: postId }, function(response) {
				if (response === 'ok') {
					refreshPanel(orderId);
				} else {
					alert('Something went wrong. Please try again.');
				}
			});
		});

		// ADD NOTE - trigger on Enter key
		$(document).on("keypress", "input[id$='note']", function(e) {
			if (e.which === 13) {
				var record = $(this).attr('id').replace('note', '');
				$("[action=addNote][record='" + record + "']").click();
			}
		});

		// POST PAYMENT - trigger on Enter in ref field
		$(document).on("keypress", "input[id$='payRef']", function(e) {
			if (e.which === 13) {
				var record = $(this).attr('id').replace('payRef', '');
				$("[action=addPay][record='" + record + "']").click();
			}
		});

		// RECEIVE SHIPMENT - trigger on Enter in ref field
		$(document).on("keypress", "input[id$='recRef']", function(e) {
			if (e.which === 13) {
				var record = $(this).attr('id').replace('recRef', '');
				$("[action=recInv][record='" + record + "']").click();
			}
		});

		// ADD NOTE
		$("[action=addNote]").click(function() {

			var record = $(this).attr('record');
			var note = $("#"+record+"note").val();

			$.post('/ajax/add_note.php', { note:note, record: record }, function(response) {
				$("#"+record+"notesList").prepend("<div>" + response + "</div>");
				$("#"+record+"note").val("");
			});

		})
		
		// POST PAYMENT
		$("[action=addPay]").click(function() {

			var $btn = $(this);
			var record = $btn.attr('record');
			var payamt = $("#"+record+"payAmt").val();
			var payref = $("#"+record+"payRef").val();

			$.post('/ajax/add_payment.php', { payamt: payamt, payref: payref, record: record }, function() {
				$("#"+record+"payAmt").val('');
				$("#"+record+"payRef").val('');
				showUpdated($btn);
				refreshPanel(record);
			});

		});

		// PAY IN FULL — posts the exact remaining balance
		$("[action=payFull]").click(function() {

			var $btn = $(this);
			var record = $btn.attr('record');
			var payref = $("#"+record+"payRef").val();

			if (!confirm("Post a payment for the full remaining balance on this order?")) return;

			$.post('/ajax/add_payment.php', { payfull: 1, payref: payref, record: record }, function(response) {
				if (response === 'paid') { alert('This order is already paid in full.'); return; }
				if (response !== 'ok') { alert('Could not post payment: ' + response); return; }
				$("#"+record+"payAmt").val('');
				$("#"+record+"payRef").val('');
				showUpdated($btn);
				refreshPanel(record);
			});

		});

		// RECEIVE ORDER
		$("[action=recInv]").click(function() {

			var $btn = $(this);
			var record = $btn.attr('record');
			var recamt = $("#"+record+"recAmt").val();
			var recref = $("#"+record+"recRef").val();
			var recdate = $("#"+record+"recDate").val();
			var whId   = $("#"+record+"recWH").val();

			if (!whId) { alert('Please select a warehouse.'); return; }

			$.post('/ajax/post_order.php', { recamt: recamt, recref: recref, rec_date: recdate, record: record, warehouse_id: whId }, function(response) {
				if (response !== 'ok') { alert('Could not receive: ' + response); return; }
				$("#"+record+"recAmt").val('');
				$("#"+record+"recRef").val('');
				showUpdated($btn);
				refreshPanel(record);
			});

		});
		
		// EDIT ORDER QTY - trigger on Enter key
		$(document).on("keypress", "input[id$='editQty']", function(e) {
			if (e.which === 13) {
				var record = $(this).attr('id').replace('editQty', '');
				$("[action=editQty][record='" + record + "']").click();
			}
		});

		// EDIT ORDER QTY
		$("[action=editQty]").click(function() {

			var $btn = $(this);
			var record = $btn.attr('record');
			var editqty = $("#"+record+"editQty").val();

			$.post('/ajax/edit_order_qty.php', { editqty: editqty, record: record }, function() {
				showUpdated($btn);
				refreshPanel(record);
			});

		});

		// EDIT ORDER REF - trigger on Enter key
		$(document).on("keypress", "input[id$='editRef']", function(e) {
			if (e.which === 13) {
				var record = $(this).attr('id').replace('editRef', '');
				$("[action=editRef][record='" + record + "']").click();
			}
		});

		// EDIT ORDER REF
		$("[action=editRef]").click(function() {

			var $btn = $(this);
			var record = $btn.attr('record');
			var editref = $("#"+record+"editRef").val();

			$.post('/ajax/edit_order_ref.php', { editref: editref, record: record }, function() {
				showUpdated($btn);
				$("#"+record+"summaryRef").text(editref);
			});

		});
		
		// EDIT ORDER DATE (can backdate; not future)
		$("[action=editDate]").click(function() {
			var record  = $(this).attr('record');
			var newdate = $("#"+record+"editDate").val();
			if (!newdate) { alert('Pick a date.'); return; }
			$.post('/ajax/edit_order_date.php', { record: record, orderdate: newdate }, function(response) {
				if (response === 'ok') { location.reload(); }
				else { alert('Could not change date: ' + response); }
			});
		});

		// CHANGE PRODUCT ON ORDER
		$("[action=changePart]").click(function() {
			var record  = $(this).attr('record');
			var newpart = $("#"+record+"changePart").val();
			if (!confirm("Change the product on this order?\n\nThe old part's order entry will be marked corrected, a new order entry added on the new part, and any received stock moved to the new part. Continue?")) return;
			$.post('/ajax/change_order_part.php', { record: record, newpart: newpart }, function(response) {
				if (response === 'ok') { location.reload(); }
				else if (response === 'same') { alert('That is already the product on this order.'); }
				else { alert('Could not change product: ' + response); }
			});
		});

		// SHOW MANAGE ORDER AREA
		$("[action=manOrdButton]").click(function() {
			
			var record= $(this).attr('record');
			$("#"+record+"manArea").slideDown(100);
			
		});
		
		$("#postButton").click(function() {
			
			var partid = $("#postPart option:selected").val();
			var qty = $("#postQty").val(); 
			var refnum = $("#postRef").val();
			
			$.post('/ajax/post_comp.php', { partid: partid, refnum: refnum, qty: qty }, function() {
				 location.reload();
			})
			
		});
		
		$("#orderButton").click(function() {
			
			var partid = $("#orderPart option:selected").val();
			var qty = $("#orderQty").val(); 
			var refnum = $("#orderRef").val();
			
			$.post('/ajax/add_order.php', { partid: partid, refnum: refnum, qty: qty }, function() {
				 location.reload();
			})
			
		});
		
		$(document).on("click", "#emptyAddOrder", function(e) {
			e.preventDefault();
			$("#orderAreaButton").click();
		});

		$("#orderAreaButton").click(function() {

			$("#orderArea").show();

		});

		$("#orderCancelButton").click(function() {

			$("#orderPart").val("");
			$("#orderQty").val("");
			$("#orderRef").val("");
			$("#orderArea").hide();

		});
		
		$("[action=closeManArea]").click(function() {

			var record = $(this).attr('record');
			$("#"+record+"manArea").slideUp(100);

		});

		// DELETE ORDER
		$("[action=deleteOrder]").click(function() {

			var record = $(this).attr('record');

			if (!confirm("Are you sure you want to delete this order? This cannot be undone.")) return;

			$.post('/ajax/delete_order.php', { record: record }, function(response) {
				if (response === 'blocked') {
					alert('This order cannot be deleted because it has received shipments posted against it. Please contact the admin.');
				} else {
					location.reload();
				}
			});

		});



	</script>

















<?php require_once(__DIR__."/includes/footer.php"); ?>
