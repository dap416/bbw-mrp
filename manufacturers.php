<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");
	if (!has_access('manufacturers')) { deny_access(); }

	$dbLink = db_connect();
	$manufacturers = $dbLink->query("SELECT * FROM `manufacturers` ORDER BY `name` ASC");

?>

<div>

	<h2 class="fw-bold mb-3">Manufacturers</h2>

	<div class="mb-3">
		<button id="addMfgButton" class="btn btn-light-primary">+ Add Manufacturer</button>
	</div>

	<!-- ADD MANUFACTURER FORM -->
	<div id="addMfgArea" class="hidden mb-3">
		<div class="card">
		<div class="card-body">
			<h6 class="fw-bold mb-3">New Manufacturer</h6>
			<div class="row g-2">
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Company Name <span class="text-danger">*</span></label>
					<input type="text" id="addMfgName" class="form-control form-control-sm" placeholder="Company Name" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Contact Person</label>
					<input type="text" id="addMfgContact" class="form-control form-control-sm" placeholder="Contact Person" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Email</label>
					<input type="email" id="addMfgEmail" class="form-control form-control-sm" placeholder="Email" />
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small text-muted fw-semibold">Phone</label>
					<input type="text" id="addMfgPhone" class="form-control form-control-sm" placeholder="Phone" />
				</div>
				<div class="col-12 col-md-5">
					<label class="form-label small text-muted fw-semibold">Address Line 1</label>
					<input type="text" id="addMfgAddr1" class="form-control form-control-sm" placeholder="Street Address" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Address Line 2</label>
					<input type="text" id="addMfgAddr2" class="form-control form-control-sm" placeholder="Suite, Unit, etc." />
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small text-muted fw-semibold">City</label>
					<input type="text" id="addMfgCity" class="form-control form-control-sm" placeholder="City" />
				</div>
				<div class="col-12 col-md-2">
					<label class="form-label small text-muted fw-semibold">State / Province</label>
					<input type="text" id="addMfgState" class="form-control form-control-sm" placeholder="State / Province" />
				</div>
				<div class="col-12 col-md-2">
					<label class="form-label small text-muted fw-semibold">Postal Code</label>
					<input type="text" id="addMfgPostal" class="form-control form-control-sm" placeholder="Postal Code" />
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small text-muted fw-semibold">Country</label>
					<input type="text" id="addMfgCountry" class="form-control form-control-sm" placeholder="Country" />
				</div>
			</div>
			<div class="d-flex gap-2 mt-3">
				<button id="addMfgSubmit" class="btn btn-primary btn-sm">Add Manufacturer</button>
				<button id="addMfgCancel" class="btn btn-secondary btn-sm">Cancel</button>
			</div>
		</div>
		</div>
	</div>

	<!-- MANUFACTURERS TABLE -->
	<div class="card mt-3">
	<div class="card-body p-0">
	<table class="table table-hover mb-0">
		<thead>
			<tr style="background-color:#e2e5e8;">
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Name</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Contact</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Email</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Phone</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Country</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Actions</th>
			</tr>
		</thead>
		<tbody>

	<?php while ($mfg = $manufacturers->fetch()) {
		$mid      = $mfg['id'];
		$mname    = htmlspecialchars($mfg['name']);
		$mcontact = htmlspecialchars($mfg['contact_person']);
		$memail   = htmlspecialchars($mfg['email']);
		$mphone   = htmlspecialchars($mfg['phone']);
		$maddr1   = htmlspecialchars($mfg['address1']);
		$maddr2   = htmlspecialchars($mfg['address2']);
		$mcity    = htmlspecialchars($mfg['city']);
		$mstate   = htmlspecialchars($mfg['state_province']);
		$mpostal  = htmlspecialchars($mfg['postal_code']);
		$mcountry = htmlspecialchars($mfg['country']);
	?>

		<tr>
			<td class="fw-semibold"><?php echo $mname; ?></td>
			<td><?php echo $mcontact ?: '<span class="text-muted">—</span>'; ?></td>
			<td><?php echo $memail ? '<a href="mailto:'.$memail.'">'.$memail.'</a>' : '<span class="text-muted">—</span>'; ?></td>
			<td><?php echo $mphone ?: '<span class="text-muted">—</span>'; ?></td>
			<td><?php echo $mcountry ?: '<span class="text-muted">—</span>'; ?></td>
			<td>
				<input type="button" action="editMfgButton" record="<?php echo $mid; ?>" value="MANAGE" class="btn btn-sm btn-light-primary" />
			</td>
		</tr>

		<!-- MANAGE AREA -->
		<tr>
		<td colspan="6" class="p-0" style="border-top:none;">
		<div id="<?php echo $mid; ?>mfgManArea" class="manage-area hidden p-4">

			<h5 class="fw-bold mb-4"><?php echo $mname; ?></h5>

			<div class="row g-3">

				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Company Name <span class="text-danger">*</span></label>
					<input type="text" id="<?php echo $mid; ?>editMfgName" class="form-control form-control-sm" value="<?php echo $mname; ?>" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Contact Person</label>
					<input type="text" id="<?php echo $mid; ?>editMfgContact" class="form-control form-control-sm" value="<?php echo $mcontact; ?>" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Email</label>
					<input type="email" id="<?php echo $mid; ?>editMfgEmail" class="form-control form-control-sm" value="<?php echo $memail; ?>" />
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small text-muted fw-semibold">Phone</label>
					<input type="text" id="<?php echo $mid; ?>editMfgPhone" class="form-control form-control-sm" value="<?php echo $mphone; ?>" />
				</div>
				<div class="col-12 col-md-5">
					<label class="form-label small text-muted fw-semibold">Address Line 1</label>
					<input type="text" id="<?php echo $mid; ?>editMfgAddr1" class="form-control form-control-sm" value="<?php echo $maddr1; ?>" />
				</div>
				<div class="col-12 col-md-4">
					<label class="form-label small text-muted fw-semibold">Address Line 2</label>
					<input type="text" id="<?php echo $mid; ?>editMfgAddr2" class="form-control form-control-sm" value="<?php echo $maddr2; ?>" />
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small text-muted fw-semibold">City</label>
					<input type="text" id="<?php echo $mid; ?>editMfgCity" class="form-control form-control-sm" value="<?php echo $mcity; ?>" />
				</div>
				<div class="col-12 col-md-2">
					<label class="form-label small text-muted fw-semibold">State / Province</label>
					<input type="text" id="<?php echo $mid; ?>editMfgState" class="form-control form-control-sm" value="<?php echo $mstate; ?>" />
				</div>
				<div class="col-12 col-md-2">
					<label class="form-label small text-muted fw-semibold">Postal Code</label>
					<input type="text" id="<?php echo $mid; ?>editMfgPostal" class="form-control form-control-sm" value="<?php echo $mpostal; ?>" />
				</div>
				<div class="col-12 col-md-3">
					<label class="form-label small text-muted fw-semibold">Country</label>
					<input type="text" id="<?php echo $mid; ?>editMfgCountry" class="form-control form-control-sm" value="<?php echo $mcountry; ?>" />
				</div>

			</div>

			<div class="d-flex gap-2 mt-4">
				<button action="saveMfg" record="<?php echo $mid; ?>" class="btn btn-primary btn-sm">Save Changes</button>
				<button action="closeMfgMan" record="<?php echo $mid; ?>" class="btn btn-secondary btn-sm">Close</button>
				<button action="deleteMfg" record="<?php echo $mid; ?>" class="btn btn-outline-danger btn-sm ms-auto">Delete Manufacturer</button>
			</div>

		</div>
		</td>
		</tr>

	<?php } ?>

		</tbody>
	</table>
	</div>
	</div>

</div>

<script>

	// ADD FORM TOGGLE
	$("#addMfgButton").click(function() {
		$("#addMfgArea").show();
		$(this).hide();
	});

	$("#addMfgCancel").click(function() {
		$("#addMfgArea").hide();
		$("#addMfgButton").show();
	});

	// SUBMIT ADD
	$("#addMfgSubmit").click(function() {
		var name = $("#addMfgName").val().trim();
		if (!name) { alert("Company name is required."); return; }

		$.post('/ajax/manufacturers/add.php', {
			name:           name,
			contact_person: $("#addMfgContact").val(),
			email:          $("#addMfgEmail").val(),
			phone:          $("#addMfgPhone").val(),
			address1:       $("#addMfgAddr1").val(),
			address2:       $("#addMfgAddr2").val(),
			city:           $("#addMfgCity").val(),
			state_province: $("#addMfgState").val(),
			postal_code:    $("#addMfgPostal").val(),
			country:        $("#addMfgCountry").val()
		}, function(response) {
			if (response === 'ok') {
				location.reload();
			} else {
				alert(response);
			}
		});
	});

	// OPEN MANAGE AREA
	$("[action=editMfgButton]").click(function() {
		var record = $(this).attr('record');
		$("[id$='mfgManArea']").slideUp(100);
		$("#"+record+"mfgManArea").slideDown(100);
	});

	// CLOSE MANAGE AREA
	$("[action=closeMfgMan]").click(function() {
		var record = $(this).attr('record');
		$("#"+record+"mfgManArea").slideUp(100);
	});

	// SAVE CHANGES
	$("[action=saveMfg]").click(function() {
		var $btn   = $(this);
		var record = $btn.attr('record');
		var name   = $("#"+record+"editMfgName").val().trim();
		if (!name) { alert("Company name is required."); return; }

		$.post('/ajax/manufacturers/save.php', {
			record:         record,
			name:           name,
			contact_person: $("#"+record+"editMfgContact").val(),
			email:          $("#"+record+"editMfgEmail").val(),
			phone:          $("#"+record+"editMfgPhone").val(),
			address1:       $("#"+record+"editMfgAddr1").val(),
			address2:       $("#"+record+"editMfgAddr2").val(),
			city:           $("#"+record+"editMfgCity").val(),
			state_province: $("#"+record+"editMfgState").val(),
			postal_code:    $("#"+record+"editMfgPostal").val(),
			country:        $("#"+record+"editMfgCountry").val()
		}, function(response) {
			if (response === 'ok') {
				location.reload();
			} else {
				alert(response);
			}
		});
	});

	// DELETE
	$("[action=deleteMfg]").click(function() {
		var $btn   = $(this);
		var record = $btn.attr('record');
		if (!confirm("Delete this manufacturer? Any parts assigned to this manufacturer will be unlinked.")) return;

		$.post('/ajax/manufacturers/delete.php', { record: record }, function(response) {
			if (response === 'ok') {
				location.reload();
			} else {
				alert(response);
			}
		});
	});

</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
