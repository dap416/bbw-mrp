<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('research')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/shopify.php");

	$db = db_connect();

	// ── Has the migration been run? ──────────────────────────────────────────
	$hasCol = false;
	try { $db->query("SELECT `shopify_sku` FROM `products` LIMIT 1"); $hasCol = true; }
	catch (PDOException $e) { $hasCol = false; }

	// ── Pull live Shopify inventory (keyed by SKU) ───────────────────────────
	$shopConfigured = shopify_is_configured();
	$shop = $shopConfigured ? shopify_fetch_variants() : ['error' => null, 'skus' => []];
	$shopErr  = $shop['error'] ?? null;
	$shopSkus = $shop['skus']  ?? [];

	// ── Load products + prefetch BOM (one query, no N+1) ─────────────────────
	$products = $hasCol
		? $db->query("SELECT `id`, `name`, `shopify_sku` FROM `products` ORDER BY `name` ASC")->fetchAll()
		: [];

	$bomByProd = [];
	if ($hasCol) {
		foreach ($db->query("
			SELECT b.prodid, b.qty, p.partno, p.`desc`, p.qoh
			FROM build b JOIN parts p ON p.id = b.partid
			ORDER BY b.prodid ASC, p.partno ASC
		") as $bl) {
			$bomByProd[$bl['prodid']][] = $bl;
		}
	}

	/** How many units can be built from raw materials on hand; returns [qty|null, limitingLine|null]. */
	function compute_buildable($lines) {
		if (empty($lines)) return [null, null];
		$min = null; $limit = null;
		foreach ($lines as $l) {
			$need = (int)$l['qty'];
			if ($need <= 0) continue;
			$can = intdiv((int)$l['qoh'], $need);
			if ($min === null || $can < $min) { $min = $can; $limit = $l; }
		}
		return [$min, $limit];
	}

	// ── Split products into mapped (comparison table) vs unmapped ────────────
	$mapped = [];
	foreach ($products as $p) {
		if (!empty($p['shopify_sku'])) $mapped[] = $p;
	}

	// SKU picker options — exclude print-on-demand (qty pinned at 9999)
	$skuOptions = [];
	foreach ($shopSkus as $sku => $info) {
		if ($info['qty'] >= 9999) continue;
		$skuOptions[$sku] = $info;
	}
	uasort($skuOptions, fn($a, $b) => strcasecmp($a['product_title'], $b['product_title']));

	require_once(__DIR__."/includes/header.php");
?>

<style>
.research-card     { border-top: 3px solid #7e57c2; }
.stat-pos          { color:#065f46; font-weight:700; }
.stat-zero         { color:#92400e; font-weight:700; }
.stat-neg          { color:#b91c1c; font-weight:700; }
.muted-pill        { font-size:0.7rem; padding:2px 8px; border-radius:20px; background:#f1f3f5; color:#6c757d; }
.warn-pill         { font-size:0.7rem; padding:2px 8px; border-radius:20px; background:#fff3cd; color:#856404; font-weight:600; }
.sku-input         { width:170px; }
</style>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
	<div>
		<h2 class="fw-bold mb-0">Research</h2>
		<div class="text-muted small">Compare live Shopify stock against what you can build from raw materials on hand.</div>
	</div>
	<button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
		<i class="ti ti-refresh me-1"></i>Refresh Live Data
	</button>
</div>

<?php if (!$hasCol): ?>
<div class="alert alert-warning">
	<h6 class="fw-bold mb-1">One-time setup needed</h6>
	<p class="mb-2">The <code>shopify_sku</code> column hasn't been added to the products table yet.</p>
	<a href="/setup_research.php" class="btn btn-sm btn-warning">Run Research Setup</a>
</div>
<?php endif; ?>

<?php if (!$shopConfigured): ?>
<div class="alert alert-info">
	<h6 class="fw-bold mb-1">Connect your Shopify store</h6>
	<p class="mb-1">Live stock numbers will appear once Shopify credentials are added to <code>includes/config.local.php</code>.</p>
	<ol class="mb-0 small">
		<li>In Shopify admin: <strong>Settings → Apps and sales channels → Develop apps → Create an app</strong>.</li>
		<li>Under <strong>Configuration → Admin API</strong>, grant scopes <code>read_products</code> and <code>read_inventory</code>.</li>
		<li><strong>Install</strong> the app, then copy the <strong>Admin API access token</strong> (starts with <code>shpat_</code>).</li>
		<li>Paste the token, your <code>*.myshopify.com</code> domain into the <code>shopify</code> block of <code>config.local.php</code> (see <code>config.local.example.php</code>).</li>
	</ol>
</div>
<?php elseif ($shopErr): ?>
<div class="alert alert-danger">
	<h6 class="fw-bold mb-1">Couldn't load Shopify data</h6>
	<p class="mb-0 small"><?php echo htmlspecialchars($shopErr); ?></p>
</div>
<?php endif; ?>

<!-- ── COMPARISON TABLE ─────────────────────────────────────────────────── -->
<div class="card research-card mb-4">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">Stock vs. Build Capacity</span>
		<?php if ($mapped): ?>
		<span class="muted-pill"><?php echo count($mapped); ?> linked product<?php echo count($mapped) !== 1 ? 's' : ''; ?></span>
		<?php endif; ?>
	</div>

	<?php if (!$mapped): ?>
	<div class="text-center text-muted py-4">
		<i class="ti ti-link-off" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
		No products are linked to a Shopify SKU yet.<br>
		<small>Use the <strong>Link Products to Shopify</strong> section below to connect them.</small>
	</div>
	<?php else: ?>
	<div class="scroll-table">
	<table class="table dash-table align-middle">
		<thead><tr>
			<th>Product</th>
			<th>Shopify SKU</th>
			<th class="text-center">In Stock<br><small class="text-muted fw-normal">Shopify</small></th>
			<th class="text-center">Can Build Now<br><small class="text-muted fw-normal">from raw materials</small></th>
			<th class="text-center">Total Available<br><small class="text-muted fw-normal">stock + buildable</small></th>
			<th>Limiting Raw Material</th>
		</tr></thead>
		<tbody>
		<?php foreach ($mapped as $p):
			$sku   = $p['shopify_sku'];
			$found = isset($shopSkus[$sku]);
			$stock = $found ? (int)$shopSkus[$sku]['qty'] : null;

			[$buildable, $limit] = compute_buildable($bomByProd[$p['id']] ?? []);

			$stockClass = $stock === null ? '' : ($stock > 0 ? 'stat-pos' : ($stock < 0 ? 'stat-neg' : 'stat-zero'));
			$total = ($stock ?? 0) + ($buildable ?? 0);
		?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></td>
			<td><code><?php echo htmlspecialchars($sku); ?></code></td>
			<td class="text-center">
				<?php if (!$shopConfigured || $shopErr): ?>
					<span class="text-muted">—</span>
				<?php elseif (!$found): ?>
					<span class="warn-pill">SKU not found</span>
				<?php else: ?>
					<span class="<?php echo $stockClass; ?>"><?php echo number_format($stock); ?></span>
				<?php endif; ?>
			</td>
			<td class="text-center">
				<?php if ($buildable === null): ?>
					<span class="muted-pill">No BOM</span>
				<?php else: ?>
					<span class="<?php echo $buildable > 0 ? 'stat-pos' : 'stat-zero'; ?>"><?php echo number_format($buildable); ?></span>
				<?php endif; ?>
			</td>
			<td class="text-center fw-bold">
				<?php echo ($stock === null && $buildable === null) ? '—' : number_format($total); ?>
			</td>
			<td class="small text-muted">
				<?php if ($limit): ?>
					<?php echo htmlspecialchars($limit['partno']); ?> — <?php echo htmlspecialchars($limit['desc']); ?>
					<span class="muted-pill ms-1"><?php echo number_format((int)$limit['qoh']); ?> on hand</span>
				<?php else: ?>
					<span class="text-muted">—</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<?php endif; ?>

</div>
</div>

<!-- ── SKU MAPPING ──────────────────────────────────────────────────────── -->
<?php if ($hasCol): ?>
<div class="card mb-4" style="border-top:3px solid #4680ff;">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">Link Products to Shopify</span>
		<?php if ($shopConfigured && !$shopErr): ?>
		<span class="muted-pill"><?php echo count($skuOptions); ?> Shopify SKUs available</span>
		<?php endif; ?>
	</div>

	<p class="text-muted small mb-3">
		Type or pick the Shopify SKU that matches each product, then click Save. Print-on-demand items are hidden from the picker.
		Leave blank and save to unlink.
	</p>

	<?php if ($shopConfigured && !$shopErr): ?>
	<datalist id="skuList">
		<?php foreach ($skuOptions as $sku => $info): ?>
		<option value="<?php echo htmlspecialchars($sku); ?>"><?php echo htmlspecialchars($info['product_title'].' — '.$info['variant_title']); ?></option>
		<?php endforeach; ?>
	</datalist>
	<?php endif; ?>

	<div class="scroll-table">
	<table class="table dash-table align-middle">
		<thead><tr>
			<th>Product</th>
			<th style="width:320px;">Shopify SKU</th>
			<th style="width:120px;"></th>
		</tr></thead>
		<tbody>
		<?php foreach ($products as $p): ?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></td>
			<td>
				<input type="text" class="form-control form-control-sm sku-input map-input"
					list="skuList"
					data-id="<?php echo (int)$p['id']; ?>"
					value="<?php echo htmlspecialchars($p['shopify_sku'] ?? ''); ?>"
					placeholder="e.g. LDXL" />
			</td>
			<td>
				<button class="btn btn-sm btn-primary map-save" data-id="<?php echo (int)$p['id']; ?>">Save</button>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if (!$products): ?>
		<tr><td colspan="3" class="text-muted text-center py-3">No products found.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	</div>

</div>
</div>
<?php endif; ?>

<script>
	$(document).on('click', '.map-save', function() {
		var $btn = $(this);
		var id   = $btn.data('id');
		var sku  = $(".map-input[data-id='" + id + "']").val();
		$btn.prop('disabled', true).text('Saving…');
		$.post('/ajax/research/set_sku.php', { id: id, sku: sku }, function(resp) {
			if (resp === 'ok') {
				location.reload();
			} else {
				alert('Could not save mapping.');
				$btn.prop('disabled', false).text('Save');
			}
		});
	});

	$(document).on('keypress', '.map-input', function(e) {
		if (e.which === 13) $(this).closest('tr').find('.map-save').click();
	});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
