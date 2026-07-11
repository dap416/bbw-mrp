<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");
	if (!has_access('products')) { deny_access(); }

	$dbLink = $mysqli = db_connect();

	// Regular products first (A–Z), then all [Amazon] twins grouped at the bottom (A–Z).
	$products = $dbLink->query("SELECT * FROM `products` ORDER BY (LOWER(`name`) LIKE '%[amazon]%'), `name` ASC");

	// ── BATCH PREFETCH (avoids per-product N+1 queries inside the loop) ──
	$allPartsList = $dbLink->query("SELECT * FROM `parts` ORDER BY `partno` ASC")->fetchAll();
	$partCostById = [];
	foreach ($allPartsList as $p) { $partCostById[$p['id']] = $p['cost']; }
	$buildByProd = [];
	foreach ($dbLink->query("SELECT * FROM `build`") as $b) { $buildByProd[$b['prodid']][] = $b; }

	?>

		<div id="addProdArea" class="mb-3 d-flex gap-2">
			<input type="text" id="addProdName" class="form-control form-control-sm" style="width:280px" placeholder="Enter Product Name"/>
			<button id="addProdButton" class="btn btn-primary btn-sm">Add Product</button>
		</div>

	

		<div class="card mt-3">
		<div class="card-body p-0">
		<table class="table table-hover mb-0">
			<thead>
				<tr>
					<th>Product Name</th>
					<th>Build Cost</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>

		<?php

		while($product = $products->fetch()) {

			$prodName = $product['name'];
			$prodId = $product['id'];
			
			/*$parts = $mysqli->query("SELECT * FROM `build` WHERE `prodid` = '$prodId'");
			
			while($part = mysql_fetch_array($parts)) {
				
				$partId = $part['id'];
				$partQty = $part['qty'];
				
				$partDetail = mysql_fetch_array($mysqli->query("SELECT * FROM `parts` WHERE `id` = '$partId'"));
				
				$partCost = $partDetail['cost'];
				$thisCost = $partCost * $partQty;
				$buildCost += $thisCost;
				
			} */
			
		?>

		<tr id="prod<?php echo $prodId; ?>">
			<td><?php echo $prodName; ?></td>
			<td>
				<?php

					$totalCost = false;

					foreach (($buildByProd[$prodId] ?? []) as $part) {

						$partId = $part['partid'];

						$partCost = $partCostById[$partId] ?? null;

						$totalCost += $partCost;

					}

					if ($totalCost !== false) {
						$totalCost = number_format($totalCost, 2);
					}
			
				echo $totalCost;

				?>
			</td>
			<td>
				<a class="link me-3" action="editButton" record="<?php echo $prodId; ?>">Edit</a>
				<a class="link text-danger" action="deleteProd" prodid="<?php echo $prodId; ?>">Delete</a>
			</td>
		</tr>

		<!-- HIDDEN EDIT AREA -->
		<tr>
		<td colspan="3" class="p-0" style="border-top: none;">
		<div class="manage-area hidden" id="<?php echo $prodId; ?>EditArea">
			<div class="row g-3">
				<div class="col-md-5">
					<h6 class="fw-bold mb-3">Edit: <?php echo $prodName; ?></h6>
					<div class="d-flex gap-2 mb-3">
						<input type="text" class="form-control form-control-sm" id="<?php echo $prodId; ?>Title" value="<?php echo $prodName; ?>" />
						<button action="updateName" class="btn btn-primary btn-sm" record="<?php echo $prodId; ?>">Rename</button>
					</div>
					<div class="mb-3">
						<select action="addCompSelect" record="<?php echo $prodId; ?>" class="form-select form-select-sm">
							<option value="">Add Component...</option>
							<?php
							foreach ($allPartsList as $part) { ?>
							<option value="<?php echo $part['id']; ?>"><?php echo $part['partno'].' - '.$part['desc']; ?></option>
							<?php } ?>
						</select>
					</div>
					<button action="save" class="btn btn-secondary btn-sm">Close</button>
				</div>
				<div class="col-md-5">
					<div id="<?php echo $prodId; ?>CompList"></div>
				</div>
			</div>
		</div><!-- end EditArea -->
		</td>
		</tr>

		<?php } ?>

			</tbody>
		</table>
		</div><!-- end card-body -->
		</div><!-- end card -->

	<script>

		// ADD PRODUCT
		$("#addProdButton").click(function() {
			
			var name = $("#addProdName").val();
			
			$.post('/ajax/add_prod.php', { name: name }, function() {
				location.reload();
			});
			
		});

		// ADD COMPONENT
		
		$("[action=addCompSelect]").change(function() {
			
			var compId = $(this).val();
			var prodId = $(this).attr('record');
			$(this).val('');
			
			$.post('/ajax/products/add_comp.php', { prodid: prodId, compid: compId }, function() {

				// LOAD COMPONENT LIST
				$.post('/ajax/products/get_comp_list.php', { prodid: prodId }, function(data) {

					$("#"+prodId+"CompList").html(data);
					

				});
				
			});
		});
		
		// SHOW EDIT AREA
		$("[action=editButton]").click(function() {
			
			var prodId = $(this).attr('record');
			
			
			
			// LOAD COMPONENT LIST
				$.post('ajax/products/get_comp_list.php', { prodid: prodId }, function(data) {

					$("#"+prodId+"CompList").html(data);
					$("[id*=EditArea]").slideUp(300);
					$("#"+prodId+"EditArea").slideDown(300);

				});
			
		});
		
		// EDIT NAME
		
		$("[action=updateName]").click(function() {
			
			
			var prodId = $(this).attr('record');
			var name = $("#"+prodId+"Title").val();
			
			$.post('/ajax/products/change_title.php', { name: name, prodid: prodId }, function() {
				location.reload();				
			});
			
		});
		
		// CLOSE EDIT AREA
		
		$("[action=save]").click(function() {
			location.reload();
		});
		
		// DELETE PRODUCT
		$("[action=deleteProd]").click(function() {
			
			var prodId = $(this).attr('prodid');
			
			if (confirm("Are you certain you would like to DELETE this product?  Doing so will clear all build information!") == true) {
			  
				$.post('/ajax/products/delete.php', { prodid: prodId }, function() {
					location.reload();
				});
				
			} else {
			  
				alert('Got it. Product was not deleted.');
				
			}
			
		});

		// Deep link: /products.php?edit=<id> opens that product's BOM editor and scrolls to it.
		(function() {
			var m = window.location.search.match(/[?&]edit=(\d+)/);
			if (!m) return;
			var id = m[1];
			$("[action=editButton][record='" + id + "']").trigger('click');
			var $row = $("#prod" + id);
			if ($row.length) $('html,body').animate({ scrollTop: $row.offset().top - 80 }, 300);
		})();

	</script>
	

	<?php

	require_once(__DIR__."/includes/footer.php");

?>