<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/shopify.php");

	$role = $_SESSION['user_role'] ?? '';
	$isAdmin = in_array($role, ['admin', 'master'], true);
	if (!$isAdmin) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}

	$db = db_connect();

	// Make sure the settings table exists before reading from it.
	$settingsReady = false;
	try { $db->query("SELECT 1 FROM settings LIMIT 1"); $settingsReady = true; }
	catch (Throwable $e) { $settingsReady = false; }

	$curDomain   = $settingsReady ? (string)setting_get($db, 'shopify_domain', '') : '';
	$curVersion  = $settingsReady ? (string)setting_get($db, 'shopify_api_version', '') : '';
	$curClientId = $settingsReady ? (string)setting_get($db, 'shopify_client_id', '') : '';
	$curSecret   = $settingsReady ? (string)setting_get($db, 'shopify_client_secret', '') : '';
	if ($curVersion === '') $curVersion = '2025-01';

	$secretSet  = ($curSecret !== '' && strpos($curSecret, 'CHANGE_ME') === false);
	$secretMask = $secretSet ? '••••••••' . substr($curSecret, -4) : '';
	$connected  = ($curDomain !== '' && $curClientId !== '' && $secretSet);

	$curAiKey   = $settingsReady ? (string)setting_get($db, 'anthropic_api_key', '') : '';
	$curAiModel = $settingsReady ? (string)setting_get($db, 'anthropic_model', '') : '';
	if ($curAiModel === '') $curAiModel = 'claude-opus-4-8';
	$aiKeySet   = ($curAiKey !== '' && strpos($curAiKey, 'CHANGE_ME') === false);
	$aiKeyMask  = $aiKeySet ? '••••••••' . substr($curAiKey, -4) : '';

	require_once(__DIR__."/includes/header.php");
?>

<div class="mb-4">
	<h2 class="fw-bold mb-0">Integrations</h2>
	<div class="text-muted small">Connect external services. Credentials are stored in your database — not in the code.</div>
</div>

<?php if (!$settingsReady): ?>
<div class="alert alert-warning">
	<h6 class="fw-bold mb-1">One-time setup needed</h6>
	<p class="mb-2">The <code>settings</code> table hasn't been created yet. Run setup, then come back here.</p>
	<a href="/setup_research.php" class="btn btn-sm btn-warning">Run Research Setup</a>
</div>
<?php endif; ?>

<div class="row g-4">
	<div class="col-12 col-lg-7">
		<div class="card" style="border-top:3px solid #95bf47;">
		<div class="card-body">

			<div class="panel-header mb-3">
				<span class="panel-title">Shopify</span>
				<?php if ($connected): ?>
				<span class="badge bg-success">Credentials saved</span>
				<?php else: ?>
				<span class="badge bg-secondary">Not connected</span>
				<?php endif; ?>
			</div>

			<div class="mb-3">
				<label class="form-label small fw-semibold">Store domain</label>
				<input type="text" id="shopDomain" class="form-control"
					value="<?php echo htmlspecialchars($curDomain); ?>"
					placeholder="your-store.myshopify.com" />
				<div class="form-text">Use the <code>.myshopify.com</code> domain (not bluebirdwaterfowl.com).</div>
			</div>

			<div class="mb-3">
				<label class="form-label small fw-semibold">Client ID</label>
				<input type="text" id="shopClientId" class="form-control" autocomplete="off"
					value="<?php echo htmlspecialchars($curClientId); ?>"
					placeholder="from the app's Settings tab" />
			</div>

			<div class="mb-3">
				<label class="form-label small fw-semibold">Client secret</label>
				<input type="password" id="shopClientSecret" class="form-control" autocomplete="off"
					placeholder="<?php echo $secretSet ? 'Saved ('.htmlspecialchars($secretMask).') — leave blank to keep' : 'from the app\'s Settings tab'; ?>" />
				<div class="form-text">Leave blank to keep the current secret. The MRP fetches and refreshes the access token automatically.</div>
			</div>

			<div class="mb-3">
				<label class="form-label small fw-semibold">API version</label>
				<input type="text" id="shopVersion" class="form-control" style="max-width:160px;"
					value="<?php echo htmlspecialchars($curVersion); ?>" />
			</div>

			<div class="d-flex align-items-center gap-2">
				<button id="saveBtn" class="btn btn-primary">Save</button>
				<button id="testBtn" class="btn btn-outline-secondary">Test Connection</button>
				<span id="statusMsg" class="ms-2 small"></span>
			</div>

		</div>
		</div>
	</div>

	<div class="col-12 col-lg-5">
		<div class="card">
		<div class="card-body">
			<h6 class="fw-bold mb-2">How to get these credentials</h6>
			<p class="small text-muted mb-2">Shopify now uses the <strong>Dev Dashboard</strong> (<code>dev.shopify.com/dashboard</code>) for this — the old "Develop apps" token flow is gone.</p>
			<ol class="small text-muted mb-0" style="padding-left:1.1rem;">
				<li class="mb-1">Open your app (e.g. <strong>MRP</strong>) in the <strong>Dev Dashboard</strong>.</li>
				<li class="mb-1">Go to the <strong>Versions</strong> tab and add the scopes <code>read_products</code> and <code>read_inventory</code>, then save/release.</li>
				<li class="mb-1">On the app's <strong>Home/Overview</strong>, click <strong>Install app</strong> and select your store.</li>
				<li class="mb-1">Open the <strong>Settings</strong> tab and copy the <strong>Client ID</strong> and <strong>Client secret</strong>.</li>
				<li>Paste both here with your <code>.myshopify.com</code> domain and Save.</li>
			</ol>
		</div>
		</div>
	</div>
</div>

<div class="row g-4 mt-1">
	<div class="col-12 col-lg-7">
		<div class="card" style="border-top:3px solid #d97757;">
		<div class="card-body">

			<div class="panel-header mb-3">
				<span class="panel-title">Claude (Planning Assistant)</span>
				<?php if ($aiKeySet): ?>
				<span class="badge bg-success">Key saved</span>
				<?php else: ?>
				<span class="badge bg-secondary">Not connected</span>
				<?php endif; ?>
			</div>

			<p class="text-muted small mb-3">Powers the plain-English planning assistant on the Research page.</p>

			<div class="mb-3">
				<label class="form-label small fw-semibold">Anthropic API key</label>
				<input type="password" id="aiKey" class="form-control" autocomplete="off"
					placeholder="<?php echo $aiKeySet ? 'Saved ('.htmlspecialchars($aiKeyMask).') — leave blank to keep' : 'sk-ant-…'; ?>" />
				<div class="form-text">Starts with <code>sk-ant-</code>. Leave blank to keep the current key.</div>
			</div>

			<div class="mb-3">
				<label class="form-label small fw-semibold">Model</label>
				<input type="text" id="aiModel" class="form-control" style="max-width:260px;"
					value="<?php echo htmlspecialchars($curAiModel); ?>" />
				<div class="form-text">Default <code>claude-opus-4-8</code> (most capable). Use <code>claude-sonnet-4-6</code> or <code>claude-haiku-4-5</code> to cut cost.</div>
			</div>

			<div class="d-flex align-items-center gap-2">
				<button id="aiSaveBtn" class="btn btn-primary">Save</button>
				<span id="aiStatusMsg" class="ms-2 small"></span>
			</div>

		</div>
		</div>
	</div>

	<div class="col-12 col-lg-5">
		<div class="card">
		<div class="card-body">
			<h6 class="fw-bold mb-2">How to get an API key</h6>
			<ol class="small text-muted mb-0" style="padding-left:1.1rem;">
				<li class="mb-1">Go to <strong>console.anthropic.com</strong> and sign in.</li>
				<li class="mb-1">Add a little billing credit under <strong>Billing</strong> (pay-as-you-go).</li>
				<li class="mb-1">Open <strong>Settings → API Keys → Create Key</strong>.</li>
				<li>Copy the key (<code>sk-ant-…</code>) and paste it here, then Save.</li>
			</ol>
		</div>
		</div>
	</div>
</div>

<script>
	$('#saveBtn').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Saving…');
		$('#statusMsg').removeClass('text-success text-danger').text('');
		$.ajax({
			url: '/ajax/research/save_integration.php',
			method: 'POST',
			timeout: 15000,
			data: {
				domain:        $('#shopDomain').val(),
				client_id:     $('#shopClientId').val(),
				client_secret: $('#shopClientSecret').val(),
				api_version:   $('#shopVersion').val()
			}
		}).done(function(resp) {
			if ($.trim(resp) === 'ok') {
				$('#statusMsg').addClass('text-success').text('Saved.');
				$('#shopClientSecret').val('');
				setTimeout(function(){ location.reload(); }, 700);
			} else {
				$('#statusMsg').addClass('text-danger').text('Could not save: ' + resp);
				$btn.prop('disabled', false).text('Save');
			}
		}).fail(function(xhr, status) {
			var msg = (status === 'timeout')
				? 'Save timed out — the server took too long to respond.'
				: 'Save failed (' + (xhr.status || 'no response') + '). ' + ($.trim(xhr.responseText) || '');
			$('#statusMsg').addClass('text-danger').text(msg);
			$btn.prop('disabled', false).text('Save');
		});
	});

	$('#testBtn').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Testing…');
		$('#statusMsg').removeClass('text-success text-danger').text('');
		$.getJSON('/ajax/research/test_shopify.php', function(res) {
			if (res.ok) {
				$('#statusMsg').addClass('text-success').text('Connected to "' + res.name + '".');
			} else {
				$('#statusMsg').addClass('text-danger').text(res.error || 'Connection failed.');
			}
		}).fail(function() {
			$('#statusMsg').addClass('text-danger').text('Connection test failed.');
		}).always(function() {
			$btn.prop('disabled', false).text('Test Connection');
		});
	});

	$('#aiSaveBtn').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Saving…');
		$('#aiStatusMsg').removeClass('text-success text-danger').text('');
		$.ajax({
			url: '/ajax/research/save_integration.php',
			method: 'POST',
			timeout: 15000,
			data: {
				anthropic_api_key: $('#aiKey').val(),
				anthropic_model:   $('#aiModel').val()
			}
		}).done(function(resp) {
			if ($.trim(resp) === 'ok') {
				$('#aiStatusMsg').addClass('text-success').text('Saved.');
				$('#aiKey').val('');
				setTimeout(function(){ location.reload(); }, 700);
			} else {
				$('#aiStatusMsg').addClass('text-danger').text('Could not save: ' + resp);
				$btn.prop('disabled', false).text('Save');
			}
		}).fail(function(xhr, status) {
			var msg = (status === 'timeout')
				? 'Save timed out.'
				: 'Save failed (' + (xhr.status || 'no response') + '). ' + ($.trim(xhr.responseText) || '');
			$('#aiStatusMsg').addClass('text-danger').text(msg);
			$btn.prop('disabled', false).text('Save');
		});
	});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
