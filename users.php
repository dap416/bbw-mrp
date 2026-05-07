<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");
	if (!has_access('users')) { deny_access(); }
	$dbLink = db_connect();
	$users = $dbLink->query("SELECT * FROM `users` ORDER BY `name` ASC");

?>

<div>

	<h2 class="fw-bold mb-3">Users</h2>

	<div class="alert alert-light border text-muted small mb-3" style="max-width:700px;">
		<strong>Master Admins</strong> have full access to all pages and can manage users.
		<strong>Admins</strong> have access to all pages but cannot manage users.
		When adding a <strong>Standard User</strong>, you can manage their individual page access after adding them.
	</div>

	<div class="mb-3">
		<button id="addUserButton" class="btn btn-light-primary">+ Add User</button>
	</div>

	<!-- ADD USER FORM -->
	<div id="addUserArea" class="hidden mb-3">
		<div class="card">
		<div class="card-body">
			<div class="d-flex flex-wrap align-items-center gap-2">
				<input type="text" id="addName" class="form-control form-control-sm" style="width:180px" placeholder="Full Name" />
				<input type="email" id="addEmail" class="form-control form-control-sm" style="width:220px" placeholder="Email Address" />
				<input type="password" id="addPassword" class="form-control form-control-sm" style="width:150px" placeholder="Password" />
				<input type="password" id="addPasswordConfirm" class="form-control form-control-sm" style="width:150px" placeholder="Confirm Password" />
				<select id="addRole" class="form-select form-select-sm" style="width:160px">
					<option value="user">Standard User</option>
					<option value="admin">Admin</option>
					<option value="master">Master Admin</option>
				</select>
				<button id="addUserSubmit" class="btn btn-primary btn-sm">Add User</button>
				<button id="addUserCancel" class="btn btn-secondary btn-sm">Cancel</button>
			</div>
		</div>
		</div>
	</div>

	<!-- USERS TABLE -->
	<div class="card mt-3">
	<div class="card-body p-0">
	<table class="table table-hover mb-0">
		<thead>
			<tr style="background-color:#e2e5e8;">
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Name</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Email</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Role</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Status</th>
				<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Actions</th>
			</tr>
		</thead>
		<tbody>

	<?php

	$roleLabels = [
		'user'   => 'Standard User',
		'admin'  => 'Admin',
		'master' => 'Master Admin',
	];

	$pages = [
		'access_orders'         => 'Orders',
		'access_inventory'      => 'Inventory',
		'access_products'       => 'Products',
		'access_build'          => 'Packaging',
		'access_manufacturers'  => 'Manufacturers',
	];

	while ($user = $users->fetch()) {
		$uid      = $user['id'];
		$uname    = htmlspecialchars($user['name']);
		$uuser    = htmlspecialchars($user['username']);
		$urole    = $user['role'];
		$uactive  = $user['active'];
		$roleLabel = $roleLabels[$urole];
		$statusBadge = $uactive
			? '<span class="badge bg-success">Active</span>'
			: '<span class="badge bg-secondary">Inactive</span>';
	?>

		<tr>
			<td><?php echo $uname; ?></td>
			<td><?php echo $uuser; ?></td>
			<td><?php echo $roleLabel; ?></td>
			<td><?php echo $statusBadge; ?></td>
			<td>
				<input type="button" action="editUserButton" record="<?php echo $uid; ?>" value="MANAGE" class="btn btn-sm btn-light-primary" />
			</td>
		</tr>

		<!-- MANAGE AREA -->
		<tr>
		<td colspan="5" class="p-0" style="border-top:none;">
		<div id="<?php echo $uid; ?>userManArea" class="manage-area hidden p-4">

			<h5 class="fw-bold mb-4"><?php echo $uname; ?></h5>

			<div class="row g-4">

				<!-- LEFT: Edit Info -->
				<div class="col-md-5">

					<div class="mb-3">
						<label class="form-label fw-semibold small text-muted">Full Name</label>
						<input type="text" id="<?php echo $uid; ?>editName" class="form-control form-control-sm" value="<?php echo $uname; ?>" />
					</div>

					<div class="mb-3">
						<label class="form-label fw-semibold small text-muted">Email</label>
						<div class="d-flex gap-2">
							<input type="email" id="<?php echo $uid; ?>editUsername" class="form-control form-control-sm" value="<?php echo $uuser; ?>" />
						</div>
					</div>

					<div class="mb-3">
						<label class="form-label fw-semibold small text-muted">Role</label>
						<select id="<?php echo $uid; ?>editRole" class="form-select form-select-sm">
							<option value="user"   <?php echo $urole === 'user'   ? 'selected' : ''; ?>>Standard User</option>
							<option value="admin"  <?php echo $urole === 'admin'  ? 'selected' : ''; ?>>Admin</option>
							<option value="master" <?php echo $urole === 'master' ? 'selected' : ''; ?>>Master Admin</option>
						</select>
					</div>

					<div class="mb-4">
						<label class="form-label fw-semibold small text-muted">Status</label>
						<select id="<?php echo $uid; ?>editActive" class="form-select form-select-sm">
							<option value="1" <?php echo $uactive ? 'selected' : ''; ?>>Active</option>
							<option value="0" <?php echo !$uactive ? 'selected' : ''; ?>>Inactive</option>
						</select>
					</div>

					<div class="mb-4">
						<button action="saveUserInfo" record="<?php echo $uid; ?>" class="btn btn-primary btn-sm">Save Changes</button>
					</div>

					<hr />

					<div class="mt-3">
						<label class="form-label fw-semibold small text-muted">Change Password</label>
						<div class="d-flex gap-2">
							<input type="password" id="<?php echo $uid; ?>newPassword" class="form-control form-control-sm" placeholder="New password" />
							<button action="changePassword" record="<?php echo $uid; ?>" class="btn btn-warning btn-sm">Update</button>
						</div>
					</div>

				</div>

				<!-- RIGHT: Page Access -->
				<div class="col-md-5">
					<h6 class="fw-bold mb-2">Site Access</h6>

					<div id="<?php echo $uid; ?>accessChecks" <?php echo $urole !== 'user' ? 'style="display:none"' : ''; ?>>
						<div class="text-muted small mb-2">Check the pages this user can access.</div>
						<?php foreach ($pages as $col => $label) { ?>
						<div class="form-check mb-2">
							<input class="form-check-input" type="checkbox" id="<?php echo $uid.$col; ?>" <?php echo $user[$col] ? 'checked' : ''; ?> />
							<label class="form-check-label" for="<?php echo $uid.$col; ?>"><?php echo $label; ?></label>
						</div>
						<?php } ?>
						<button action="saveAccess" record="<?php echo $uid; ?>" class="btn btn-primary btn-sm mt-2">Save Access</button>
					</div>

					<div id="<?php echo $uid; ?>accessMsg" class="text-muted small" <?php echo $urole === 'user' ? 'style="display:none"' : ''; ?>>
						<?php echo $urole === 'master' ? 'Master Admins have full access to all pages including User Management.' : 'Admins have access to all pages.'; ?>
					</div>
				</div>

			</div>

			<div class="mt-4">
				<button action="closeUserMan" record="<?php echo $uid; ?>" class="btn btn-secondary btn-sm">Close</button>
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

	// ADD USER
	$("#addUserButton").click(function() {
		$("#addUserArea").show();
	});

	$("#addUserCancel").click(function() {
		$("#addUserArea").hide();
	});

	$("#addUserSubmit").click(function() {
		var name     = $("#addName").val();
		var username = $("#addEmail").val();
		var password = $("#addPassword").val();
		var confirm  = $("#addPasswordConfirm").val();
		var role     = $("#addRole").val();

		if (!name || !username || !password || !confirm) {
			alert("Please fill in all fields.");
			return;
		}

		var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		if (!emailRegex.test(username)) {
			alert("Please enter a valid email address.");
			return;
		}

		if (password !== confirm) {
			alert("Passwords do not match.");
			return;
		}

		$.post('/ajax/users/add.php', { name: name, username: username, password: password, role: role }, function(response) {
			if (response === 'ok') {
				location.reload();
			} else {
				alert(response);
			}
		});
	});

	// TOGGLE ACCESS SECTION BASED ON ROLE
	$("[id$='editRole']").on("change", function() {
		var record = $(this).attr('id').replace('editRole', '');
		var role = $(this).val();
		if (role === 'user') {
			$("#"+record+"accessChecks").show();
			$("#"+record+"accessMsg").hide();
		} else {
			$("#"+record+"accessChecks").hide();
			var msg = role === 'master'
				? 'Master Admins have full access to all pages including User Management.'
				: 'Admins have access to all pages.';
			$("#"+record+"accessMsg").text(msg).show();
		}
	});

	// OPEN MANAGE AREA
	$("[action=editUserButton]").click(function() {
		var record = $(this).attr('record');
		$("[id$='userManArea']").slideUp(100);
		$("#"+record+"userManArea").slideDown(100);
	});

	// CLOSE MANAGE AREA
	$("[action=closeUserMan]").click(function() {
		var record = $(this).attr('record');
		$("#"+record+"userManArea").slideUp(100);
	});

	// SAVE USER INFO
	$("[action=saveUserInfo]").click(function() {
		var $btn     = $(this);
		var record   = $btn.attr('record');
		var name     = $("#"+record+"editName").val();
		var username = $("#"+record+"editUsername").val();
		var role     = $("#"+record+"editRole").val();
		var active   = $("#"+record+"editActive").val();

		$.post('/ajax/users/save.php', { record: record, name: name, username: username, role: role, active: active }, function(response) {
			if (response === 'ok') {
				location.reload();
			} else {
				alert(response);
			}
		});
	});

	// SAVE ACCESS
	$("[action=saveAccess]").click(function() {
		var $btn    = $(this);
		var record  = $btn.attr('record');
		var access  = {
			access_orders:        $("#"+record+"access_orders").is(":checked") ? 1 : 0,
			access_inventory:     $("#"+record+"access_inventory").is(":checked") ? 1 : 0,
			access_products:      $("#"+record+"access_products").is(":checked") ? 1 : 0,
			access_build:         $("#"+record+"access_build").is(":checked") ? 1 : 0,
			access_manufacturers: $("#"+record+"access_manufacturers").is(":checked") ? 1 : 0,
		};

		$.post('/ajax/users/save_access.php', { record: record, access: JSON.stringify(access) }, function(response) {
			if (response === 'ok') {
				var $notice = $('<span class="text-success ms-2 small">Saved</span>');
				$btn.after($notice);
				setTimeout(function() { $notice.fadeOut(500, function() { $(this).remove(); }); }, 3000);
			} else {
				alert(response);
			}
		});
	});

	// CHANGE PASSWORD
	$("[action=changePassword]").click(function() {
		var $btn    = $(this);
		var record  = $btn.attr('record');
		var password = $("#"+record+"newPassword").val();

		if (!password) { alert("Please enter a new password."); return; }

		$.post('/ajax/users/change_password.php', { record: record, password: password }, function(response) {
			if (response === 'ok') {
				$("#"+record+"newPassword").val('');
				var $notice = $('<span class="text-success ms-2 small">Updated</span>');
				$btn.after($notice);
				setTimeout(function() { $notice.fadeOut(500, function() { $(this).remove(); }); }, 3000);
			} else {
				alert(response);
			}
		});
	});

</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
