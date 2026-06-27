<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('research')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/shopify.php");
	require_once(__DIR__."/includes/anthropic.php");

	$db = db_connect();

	$aiReady = anthropic_is_configured();
	$defaultTarget = date('Y-m-d', strtotime('+90 days'));

	// Planning events (POs + tradeshows)
	$planEvents = [];
	try {
		$planEvents = $db->query("SELECT * FROM planning_events ORDER BY event_date ASC, id ASC")->fetchAll();
	} catch (Throwable $e) { $planEvents = []; }

	// ── Has the migration been run? ──────────────────────────────────────────
	$hasCol = false;       // shopify_sku column present
	$hasGoalCol = false;   // annual_goal column present
	try { $db->query("SELECT `shopify_sku` FROM `products` LIMIT 1"); $hasCol = true; }
	catch (PDOException $e) { $hasCol = false; }
	try { $db->query("SELECT `annual_goal` FROM `products` LIMIT 1"); $hasGoalCol = true; }
	catch (PDOException $e) { $hasGoalCol = false; }

	// ── Pull live Shopify inventory (keyed by SKU) ───────────────────────────
	$shopConfigured = shopify_is_configured();
	$shop = $shopConfigured ? shopify_fetch_variants() : ['error' => null, 'skus' => []];
	$shopErr  = $shop['error'] ?? null;
	$shopSkus = $shop['skus']  ?? [];

	// ── Load products + prefetch BOM (one query, no N+1) ─────────────────────
	$cols = "`id`, `name`";
	if ($hasCol)     $cols .= ", `shopify_sku`";
	if ($hasGoalCol) $cols .= ", `annual_goal`";
	$products = $db->query("SELECT $cols FROM `products` ORDER BY `name` ASC")->fetchAll();

	$bomByProd = [];
	foreach ($db->query("
		SELECT b.prodid, b.qty, p.id AS partid, p.partno, p.`desc`, p.qoh, p.cost
		FROM build b JOIN parts p ON p.id = b.partid
		ORDER BY b.prodid ASC, p.partno ASC
	") as $bl) {
		$bomByProd[$bl['prodid']][] = $bl;
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

	/**
	 * Suggest the best Shopify SKU for an MRP product name by scoring each
	 * candidate on the letters and numbers in the name. E.g. "Avian X Wingz
	 * 11.5" favours a SKU like W-AX-11… (W=wingz, AX=Avian X, 11=11.5).
	 * Returns the best SKU string, or '' if nothing scores well enough.
	 */
	function research_suggest_sku($name, $skuOptions, $usedSkus = []) {
		$norm = fn($s) => strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s));
		$n = $norm($name);
		// alpha word tokens (>=3 chars) and numeric tokens (integer part)
		$alpha = array_values(array_filter(explode(' ', $n), fn($w) => strlen($w) >= 3 && !ctype_digit($w)));
		preg_match_all('/\d+(?:\.\d+)?/', $name, $m);
		$nums = array_values(array_unique(array_map(fn($x) => (string)intval($x), $m[0])));
		// brand/category abbreviations → token to look for inside the SKU
		$codes = [
			'wing' => 'w', 'wingz' => 'w', 'case' => 'case', 'plate' => 'pl',
			'animator' => 'a', 'rod' => 'rd', 'card' => 'cd', 'bag' => 'bag',
			'avian' => 'ax', 'lucky' => 'ld', 'mojo' => 'm', 'elite' => 'me',
			'baby' => 'bb', 'teal' => 'bb', 'king' => 'km', 'mallard' => 'km',
		];

		$best = ''; $bestScore = 0; $secondScore = 0;
		foreach ($skuOptions as $sku => $info) {
			if (isset($usedSkus[$sku])) continue;  // already confirmed on another product
			$skuNorm = strtolower(preg_replace('/[^a-z0-9]/i', '', $sku));
			$title   = $norm(($info['product_title'] ?? '') . ' ' . ($info['variant_title'] ?? ''));
			$titleW  = explode(' ', $title);
			$score = 0;

			foreach ($alpha as $w) {
				if (in_array($w, $titleW, true)) $score += 2;          // word appears in Shopify title
				if (isset($codes[$w]) && strpos($skuNorm, $codes[$w]) !== false) $score += 2; // code letters in SKU
			}
			foreach ($nums as $num) {
				if ($num !== '' && strpos($skuNorm, $num) !== false) $score += 3;  // size/number matches SKU
				elseif ($num !== '' && strpos($title, $num) !== false) $score += 1;
			}
			if ($score > $bestScore) { $secondScore = $bestScore; $bestScore = $score; $best = $sku; }
			elseif ($score > $secondScore) { $secondScore = $score; }
		}
		// Only suggest on a clear winner — if two SKUs score within 1 point
		// (e.g. Animator "case" vs Wingz "case"), leave it blank to avoid a
		// confident wrong guess; the user picks it manually.
		return ($bestScore >= 4 && ($bestScore - $secondScore) >= 2) ? $best : '';
	}

	// SKUs already confirmed on a product — never suggest these again.
	$usedSkus = [];
	foreach ($products as $pp) {
		$s = $pp['shopify_sku'] ?? '';
		if ($s !== '') $usedSkus[$s] = true;
	}

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
		<div class="text-muted small">Season readiness — prior-year sales vs. finished &amp; raw stock, and what to order, by quarter.</div>
	</div>
	<div class="d-flex align-items-center gap-2">
		<?php
			if (!$shopConfigured) {
				$badgeClass = 'bg-secondary';
				$badgeText  = 'Shopify: Not connected';
			} elseif ($shopErr) {
				$badgeClass = 'bg-danger';
				$badgeText  = 'Shopify: Connection error';
			} else {
				$badgeClass = 'bg-success';
				$badgeText  = 'Shopify: Connected';
			}
		?>
		<a href="/integrations.php" class="badge <?php echo $badgeClass; ?> text-decoration-none"
		   style="font-size:0.78rem;padding:6px 10px;"
		   title="<?php echo $shopErr ? htmlspecialchars($shopErr) : 'Manage Shopify connection'; ?>">
			<i class="ti ti-plug-connected me-1"></i><?php echo $badgeText; ?>
		</a>
		<button class="btn btn-outline-secondary btn-sm" onclick="location.reload()">
			<i class="ti ti-refresh me-1"></i>Refresh Live Data
		</button>
	</div>
</div>

<?php if (!$hasCol || !$hasGoalCol): ?>
<div class="alert alert-warning">
	<h6 class="fw-bold mb-1">One-time setup needed</h6>
	<p class="mb-2">The products table is missing a required column
		(<?php echo (!$hasCol ? '<code>shopify_sku</code>' : '') . (!$hasCol && !$hasGoalCol ? ' and ' : '') . (!$hasGoalCol ? '<code>annual_goal</code>' : ''); ?>).
		Run setup to add it.</p>
	<a href="/setup_research.php" class="btn btn-sm btn-warning">Run Research Setup</a>
</div>
<?php endif; ?>

<?php if (!$shopConfigured): ?>
<div class="alert alert-info">
	<h6 class="fw-bold mb-1">Connect your Shopify store</h6>
	<p class="mb-2">Live stock numbers will appear once you add your Shopify store domain and access token.</p>
	<a href="/integrations.php" class="btn btn-sm btn-info">Open Integrations Settings</a>
</div>
<?php elseif ($shopErr): ?>
<div class="alert alert-danger">
	<h6 class="fw-bold mb-1">Couldn't load Shopify data</h6>
	<p class="mb-0 small"><?php echo htmlspecialchars($shopErr); ?></p>
</div>
<?php endif; ?>

<!-- ── PLANNING ASSISTANT (AI CHAT) ─────────────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #d97757;">
<div class="card-body">

	<div class="panel-header mb-2">
		<span class="panel-title">Planning Assistant</span>
		<?php if (!$aiReady): ?>
		<a href="/integrations.php" class="badge bg-secondary text-decoration-none">Connect Claude →</a>
		<?php endif; ?>
	</div>

	<?php if (!$aiReady): ?>
	<div class="alert alert-info mb-0">
		<p class="mb-2">Ask plain-English ordering questions once you connect a Claude API key.</p>
		<a href="/integrations.php" class="btn btn-sm btn-info">Open Integrations Settings</a>
	</div>
	<?php else: ?>

	<p class="text-muted small mb-2">
		Ask things like <em>"How many of each Animator do I need to build to be ready for July?"</em> or
		<em>"What do I need to order to survive until Oct 1 vs last year?"</em>
		For Animators it tells you how many you have made, how many more you can build from raw on hand, what to build, and which raw
		materials to order (and by when). It uses your Shopify sales history, finished &amp; raw inventory, BOMs, MOQ, lead times, and the POs/tradeshows below.
	</p>

	<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
		<label class="small fw-semibold text-muted">Plan through:</label>
		<input type="date" id="planTarget" class="form-control form-control-sm" style="width:170px;"
			value="<?php echo htmlspecialchars($defaultTarget); ?>" />
	</div>

	<textarea id="planQuestion" class="form-control mb-2" rows="2"
		placeholder="Ask a planning question…"></textarea>

	<div class="d-flex align-items-center gap-2">
		<button id="askBtn" class="btn btn-primary btn-sm">Ask</button>
		<span id="askStatus" class="small text-muted"></span>
	</div>

	<div id="askAnswer" class="mt-3" style="display:none; background:#faf9f7; border:1px solid #eee; border-radius:8px; padding:16px;"></div>
	<?php endif; ?>

</div>
</div>

<!-- ── SEASON READINESS ─────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #2ca87f;">
<div class="card-body">

	<div class="panel-header mb-2">
		<span class="panel-title">Season Readiness</span>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<span id="seasonAsOf" class="small text-muted"></span>
			<button id="seasonRefresh" class="btn btn-sm btn-outline-secondary"<?php echo ($shopConfigured && !$shopErr) ? '' : ' disabled'; ?>>
				<i class="ti ti-refresh me-1"></i>Refresh
			</button>
			<?php if ($aiReady): ?>
			<button id="seasonAiBtn" class="btn btn-sm btn-success"<?php echo ($shopConfigured && !$shopErr) ? '' : ' disabled'; ?>>
				<i class="ti ti-robot me-1"></i>Detailed AI plan
			</button>
			<?php endif; ?>
		</div>
	</div>

	<p class="text-muted small mb-2">
		How prepared you are for each quarter vs. <strong>last year's sales (no growth)</strong> — comparing prior-year demand to
		your finished-product stock and raw materials, and what to order. Three quarters:
		<strong>Jul–Sep</strong>, <strong>Oct–Dec</strong> (duck season), <strong>Jan–Mar</strong>.
	</p>
	<?php if (!$shopConfigured): ?>
	<div class="alert alert-info mb-0"><a href="/integrations.php">Connect Shopify</a> to read prior-year sales.</div>
	<?php endif; ?>

	<span id="seasonStatus" class="small text-muted"></span>

	<!-- Three quarter readiness cards -->
	<div id="seasonSummary" class="row g-3 mt-1" style="display:none;"></div>

	<!-- Suggested build order per season (click a SKU for the BOM drill-down) -->
	<div id="buildPlan" class="mt-3" style="display:none;"></div>

	<!-- Chart + tradeshow -->
	<div id="seasonCharts" class="row g-3 mt-1" style="display:none;">
		<div class="col-12 col-lg-7"><canvas id="seasonChart" height="120"></canvas></div>
		<div class="col-12 col-lg-5"><div id="tradeshowBox" class="small"></div></div>
	</div>

	<!-- Raw-material order list -->
	<div id="rawOrders" class="mt-3" style="display:none;"></div>

	<!-- Optional AI detail plan -->
	<div id="seasonReport" class="mt-3" style="display:none; background:#faf9f7; border:1px solid #eee; border-radius:8px; padding:16px;"></div>
	<div id="seasonReportNote" class="small text-muted mt-2"></div>

</div>
</div>

<!-- ── PLANNING EVENTS (POs + TRADESHOWS) ───────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #4680ff;">
<div class="card-body">

	<div class="panel-header">
		<span class="panel-title">
			Large POs &amp; Tradeshows
			<span class="muted-pill ms-1"><?php echo count($planEvents); ?> saved</span>
		</span>
		<button id="poToggle" class="btn btn-sm btn-outline-primary">
			<i class="ti ti-plus me-1"></i>Add / Manage
		</button>
	</div>

	<div id="poPanel" style="display:none;" class="mt-3">

		<!-- Find POs from Shopify -->
		<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
			<button id="poDetect" class="btn btn-sm btn-primary">
				<i class="ti ti-search me-1"></i>Find POs from Shopify
			</button>
			<span class="small text-muted">Scans your orders for wholesale / large-quantity orders you can add as a PO.</span>
			<span id="poDetectStatus" class="small"></span>
		</div>
		<div id="poCandidates" class="mb-3"></div>

		<!-- Saved events -->
		<div class="scroll-table mb-3">
		<table class="table dash-table align-middle">
			<thead><tr>
				<th>Type</th><th>Name</th><th>Date</th><th>End</th><th>Repeats</th><th>Details</th><th></th>
			</tr></thead>
			<tbody id="eventRows">
			<?php foreach ($planEvents as $ev): ?>
			<tr data-id="<?php echo (int)$ev['id']; ?>">
				<td><span class="muted-pill"><?php echo htmlspecialchars($ev['type']); ?></span></td>
				<td class="fw-semibold"><?php echo htmlspecialchars($ev['name']); ?></td>
				<td class="small"><?php echo htmlspecialchars($ev['event_date'] ?? '—'); ?></td>
				<td class="small"><?php echo htmlspecialchars($ev['end_date'] ?? '—'); ?></td>
				<td class="small"><?php echo $ev['repeats'] ? 'yearly' : '—'; ?></td>
				<td class="small text-muted"><?php echo htmlspecialchars($ev['details'] ?? ''); ?></td>
				<td><button class="btn btn-sm btn-outline-danger ev-del" data-id="<?php echo (int)$ev['id']; ?>">Remove</button></td>
			</tr>
			<?php endforeach; ?>
			<?php if (!$planEvents): ?>
			<tr id="noEventsRow"><td colspan="7" class="text-muted text-center py-3">No events yet.</td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		</div>

		<!-- Manual add -->
		<div class="small fw-semibold text-muted mb-2">Or add one manually</div>
		<div class="row g-2 align-items-end">
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">Type</label>
				<select id="evType" class="form-select form-select-sm">
					<option value="po">PO</option>
					<option value="tradeshow">Tradeshow</option>
				</select>
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label small fw-semibold mb-1">Name</label>
				<input id="evName" class="form-control form-control-sm" placeholder="e.g. Cabela's PO" />
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">Date</label>
				<input id="evDate" type="date" class="form-control form-control-sm" />
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">End (optional)</label>
				<input id="evEnd" type="date" class="form-control form-control-sm" />
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">Details</label>
				<input id="evDetails" class="form-control form-control-sm" placeholder="e.g. 500x LDA, 250x MEA" />
			</div>
			<div class="col-6 col-md-1">
				<div class="form-check mb-1">
					<input class="form-check-input" type="checkbox" id="evRepeats">
					<label class="form-check-label small" for="evRepeats">Yearly</label>
				</div>
				<button id="evAdd" class="btn btn-sm btn-primary w-100">Add</button>
			</div>
		</div>

	</div>

</div>
</div>

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
		<div class="d-flex align-items-center gap-2">
			<?php if ($shopConfigured && !$shopErr): ?>
			<span class="muted-pill"><?php echo count($skuOptions); ?> Shopify SKUs available</span>
			<button id="applySuggest" class="btn btn-sm btn-outline-primary">
				<i class="ti ti-wand me-1"></i>Apply all suggestions
			</button>
			<?php endif; ?>
		</div>
	</div>

	<p class="text-muted small mb-3">
		Type or pick the Shopify SKU that matches each product — it <strong>saves automatically</strong> when you move off the field.
		Print-on-demand items are hidden from the picker. Clear a field to unlink.
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
		<?php foreach ($products as $p):
			$curSku = $p['shopify_sku'] ?? '';
			$sugg   = ($curSku === '' && $shopConfigured && !$shopErr) ? research_suggest_sku($p['name'], $skuOptions, $usedSkus) : '';
		?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></td>
			<td>
				<input type="text" class="form-control form-control-sm sku-input map-input"
					list="skuList"
					data-id="<?php echo (int)$p['id']; ?>"
					data-prev="<?php echo htmlspecialchars($curSku); ?>"
					value="<?php echo htmlspecialchars($curSku); ?>"
					placeholder="e.g. LDXL" />
				<?php if ($sugg !== ''): ?>
				<div class="small mt-1">
					<a href="#" class="map-suggest text-decoration-none"
					   data-id="<?php echo (int)$p['id']; ?>" data-sku="<?php echo htmlspecialchars($sugg); ?>">
						<i class="ti ti-wand"></i> Suggested: <code><?php echo htmlspecialchars($sugg); ?></code> — use
					</a>
				</div>
				<?php endif; ?>
			</td>
			<td>
				<span class="map-status small" data-id="<?php echo (int)$p['id']; ?>"></span>
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
	// Auto-save SKU mapping on change (blur / datalist pick) — no button needed.
	$(document).on('change', '.map-input', function() {
		var $inp = $(this);
		var id   = $inp.data('id');
		var sku  = $.trim($inp.val());
		if (sku === ($inp.data('prev') || '')) return;   // nothing changed
		var $st  = $(".map-status[data-id='" + id + "']");
		$st.removeClass('text-success text-danger').text('Saving…');
		$.post('/ajax/research/set_sku.php', { id: id, sku: sku }, function(resp) {
			if ($.trim(resp) === 'ok') {
				$inp.data('prev', sku);
				$st.addClass('text-success').text(sku === '' ? 'Unlinked' : 'Saved ✓');
				setTimeout(function(){ $st.fadeOut(400, function(){ $(this).text('').show(); }); }, 1500);
			} else {
				$st.addClass('text-danger').text('Error');
			}
		}).fail(function(){ $st.addClass('text-danger').text('Failed'); });
	});

	// Enter commits the field (triggers change/blur).
	$(document).on('keydown', '.map-input', function(e) {
		if (e.which === 13) { e.preventDefault(); $(this).blur(); }
	});

	// Click a suggestion → fill the field and auto-save.
	$(document).on('click', '.map-suggest', function(e) {
		e.preventDefault();
		var id  = $(this).data('id');
		var sku = $(this).data('sku');
		var $inp = $(".map-input[data-id='" + id + "']");
		$inp.val(sku).trigger('change');
		$(this).closest('.small').fadeOut(200);
	});

	// Apply every suggestion at once, skipping any SKU already in use.
	$('#applySuggest').on('click', function() {
		var used = {};
		$('.map-input').each(function() {
			var v = $.trim($(this).val());
			if (v) used[v] = true;
		});
		var applied = 0;
		$('.map-suggest').each(function() {
			var $s = $(this), sku = $s.data('sku');
			if (used[sku]) return;             // don't assign the same SKU twice
			used[sku] = true;
			var id = $s.data('id');
			$(".map-input[data-id='" + id + "']").val(sku).trigger('change');
			$s.closest('.small').fadeOut(200);
			applied++;
		});
		if (!applied) alert('No new suggestions to apply.');
	});


	// ── Planning Assistant ────────────────────────────────────────────────
	$('#askBtn').on('click', function() {
		var q = $.trim($('#planQuestion').val());
		if (!q) { $('#askStatus').text('Enter a question first.'); return; }
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#askStatus').removeClass('text-danger').text('Thinking… (this can take up to a minute)');
		$('#askAnswer').hide();
		$.ajax({
			url: '/ajax/research/ask.php',
			method: 'POST',
			dataType: 'json',
			timeout: 180000,
			data: { question: q, target_date: $('#planTarget').val() }
		}).done(function(res) {
			if (res.answer) {
				var html = (typeof marked !== 'undefined') ? marked.parse(res.answer)
					: res.answer.replace(/\n/g, '<br>');
				$('#askAnswer').html(html).show();
				$('#askStatus').text('');
			} else {
				$('#askStatus').addClass('text-danger').text(res.error || 'No answer returned.');
			}
		}).fail(function(xhr, status) {
			$('#askStatus').addClass('text-danger').text(
				status === 'timeout' ? 'Timed out — try a narrower question.'
				: 'Request failed (' + (xhr.status || 'no response') + ').');
		}).always(function() {
			$btn.prop('disabled', false);
		});
	});

	// ── Season Readiness ──────────────────────────────────────────────────
	var seasonChartObj = null;
	function verdictBadge(s) {
		if (s === 'ready') return '<span class="badge bg-success">Ready</span>';
		if (s === 'tight') return '<span class="badge bg-warning text-dark">Tight</span>';
		return '<span class="badge bg-danger">Short</span>';
	}

	function aiExplain(it) {
		var d = it.demand || 0, h = it.have || 0, b = it.to_build || 0, bld = (it.buildable == null ? null : it.buildable);
		var html = '<div class="p-2" style="background:#f8f9fb;"><div class="small">';
		html += '<div><strong>Demand</strong> — sold last year in this same season: ' + d + '</div>';
		html += '<div><strong>Have</strong> — already made, in stock entering this season: ' + h + '</div>';
		if (b > 0) {
			html += '<div><strong>Build</strong> = demand − stock = ' + d + ' − ' + Math.min(h, d) + ' = <span class="stat-neg">' + b + '</span></div>';
		} else {
			html += '<div><strong>Build</strong> = 0 — stock already covers demand' + (h > d ? ' (' + (h - d) + ' left over carries into next season)' : '') + '</div>';
		}
		if (it.limit) {
			html += '<div class="mt-1"><strong>Can build now</strong> from raw on hand: ' + (bld == null ? '-' : bld) +
				' — limited by <strong>' + esc(it.limit.desc || it.limit.part) + '</strong> (' + esc(it.limit.part) + '): ' +
				it.limit.pool + ' on hand ÷ ' + it.limit.per_unit + ' per unit</div>';
		}
		if (it.bom && it.bom.length) {
			html += '<table class="table table-sm mt-2 mb-0"><thead><tr><th class="small">Part</th><th class="small text-end">Per unit</th><th class="small text-end">On hand (entering)</th><th class="small text-end">Can make</th></tr></thead><tbody>';
			it.bom.forEach(function(p){
				var cls = (bld != null && p.can_make === bld) ? 'fw-bold' : '';
				html += '<tr class="' + cls + '"><td class="small"><strong>' + esc(p.desc || p.part) + '</strong> <span class="text-muted">' + esc(p.part) + '</span></td>' +
					'<td class="small text-end">' + p.per_unit + '</td><td class="small text-end text-muted">' + p.pool + '</td><td class="small text-end">' + p.can_make + '</td></tr>';
			});
			html += '</tbody></table>';
		}
		html += '</div></div>';
		return html;
	}

	function renderSharedDrawdown(parts, shorts) {
		shorts = shorts || [];
		var groups = {};
		parts.forEach(function(p){ (groups[p.category] = groups[p.category] || []).push(p); });
		var order = ['Camshafts', 'Packaging', 'Packaging Cards', 'Rods', 'Plates', 'Other'];

		var h = '<div class="panel-title mb-2">Shared Parts — Build Drawdown by Season</div>' +
			'<div class="small text-muted mb-2">All Animator builds draw from one shared pool. <strong>Start</strong> = on hand + on order; each season’s projected use (from last year’s sales) is deducted in order, so <strong>projected left</strong> carries into the next season. Red = you run out (see Raw Materials to Order for the buy quantity and order-by date).</div>';

		order.forEach(function(cat){
			var rows = groups[cat]; if (!rows || !rows.length) return;
			rows.sort(function(a, b){ return a.part < b.part ? -1 : 1; });
			h += '<div class="card mb-2"><div class="card-body py-2">' +
				'<div class="fw-bold mb-1">' + esc(cat) + '</div>' +
				'<div class="scroll-table"><table class="table table-sm mb-0"><thead><tr>' +
				'<th class="small">SKU</th><th class="small text-end">Start</th>';
			shorts.forEach(function(s){ h += '<th class="small text-end">' + esc(s) + ' proj. use</th><th class="small text-end">left</th>'; });
			h += '</tr></thead><tbody>';
			rows.forEach(function(p){
				h += '<tr><td class="small"><code>' + esc(p.part) + '</code></td>' +
					'<td class="small text-end text-muted">' + p.start + '</td>';
				p.cells.forEach(function(c){
					var cls = c.remaining < 0 ? 'stat-neg fw-bold' : (c.remaining === 0 ? 'stat-zero' : 'stat-pos');
					h += '<td class="small text-end text-muted">' + c.used + '</td>' +
						'<td class="small text-end ' + cls + '">' + c.remaining + '</td>';
				});
				h += '</tr>';
			});
			h += '</tbody></table></div></div></div>';
		});
		$('#buildPlan').html(h).show();
	}

	var BP_DATA = null, BP_PARTS = null;
	function bpNum(v, d) { v = parseInt(v, 10); return isNaN(v) ? d : Math.max(0, v); }

	function renderBuildPlan(plan, parts) {
		BP_DATA = plan; BP_PARTS = parts || {};
		var bp = '<div class="panel-title mb-2">Suggested Build Order — Animators by Season</div>' +
			'<div class="small text-muted mb-2">These are <strong>built</strong> from raw materials. Edit a build qty to see “remaining after” recalc live (shared cams/rods are pooled per season). Cases &amp; wings are <strong>ordered</strong>, not built — see the quarter cards.</div>' +
			'<div class="row g-3">';
		plan.forEach(function(season, si) {
			bp += '<div class="col-12 col-lg-4"><div class="card h-100"><div class="card-body">' +
				'<div class="fw-bold mb-2">' + esc(season.label) + '</div>';
			if (!season.animators.length) {
				bp += '<div class="text-muted small">Nothing to build this season.</div>';
			} else {
				bp += '<table class="table table-sm mb-0"><tbody>';
				season.animators.forEach(function(a, ai) {
					var rid = 'bp-' + si + '-' + ai;
					bp += '<tr class="bp-row" data-target="' + rid + '" style="cursor:pointer;">' +
						'<td class="fw-semibold"><i class="ti ti-chevron-right bp-chev"></i> ' + esc(a.sku) + '</td>' +
						'<td class="text-end" style="width:96px;">' +
							'<input type="number" min="0" class="form-control form-control-sm text-end bp-build" ' +
							'data-si="' + si + '" data-ai="' + ai + '" value="' + a.suggested_build + '" ' +
							'style="width:84px;display:inline-block;" onclick="event.stopPropagation();" /></td></tr>' +
						'<tr id="' + rid + '" class="bp-detail" style="display:none;"><td colspan="2" class="p-0">' +
						'<div class="bp-detail-body" data-si="' + si + '" data-ai="' + ai + '"></div></td></tr>';
				});
				bp += '</tbody></table>';
			}
			bp += '</div></div></div>';
		});
		bp += '</div>';
		$('#buildPlan').html(bp).show();
		plan.forEach(function(_, si) { recomputeSeason(si); });
	}

	function recomputeSeason(si) {
		var season = BP_DATA[si]; if (!season) return;
		var builds = season.animators.map(function(a, ai) {
			var el = $(".bp-build[data-si='" + si + "'][data-ai='" + ai + "']");
			return el.length ? bpNum(el.val(), a.suggested_build) : a.suggested_build;
		});
		var commit = {};
		season.animators.forEach(function(a, ai) {
			a.bom.forEach(function(b) { commit[b.part] = (commit[b.part] || 0) + builds[ai] * b.qty_per_unit; });
		});
		season.animators.forEach(function(a, ai) {
			var b = builds[ai], rows = [];
			rows.push({ kind: 'FP', name: a.sku, sub: 'finished',
				need: a.demand, have: a.entering, committed: null, remaining: a.entering + b - a.demand });
			a.bom.forEach(function(bi) {
				var info = BP_PARTS[bi.part] || { desc: bi.part, have: 0 };
				var need = b * bi.qty_per_unit, tot = commit[bi.part] || 0;
				rows.push({ kind: 'RAW', name: (info.desc || bi.part), sub: bi.part,
					need: need, have: info.have || 0, committed: Math.max(0, tot - need), remaining: (info.have || 0) - tot });
			});
			$(".bp-detail-body[data-si='" + si + "'][data-ai='" + ai + "']").html(detailRowsHtml(rows));
			var $inp = $(".bp-build[data-si='" + si + "'][data-ai='" + ai + "']");
			$inp.toggleClass('border-warning', bpNum($inp.val(), a.suggested_build) !== a.suggested_build);
		});
	}

	function detailRowsHtml(rows) {
		var h = '<div class="p-2" style="background:#f8f9fb;"><table class="table table-sm mb-0"><thead><tr>' +
			'<th class="small">Item</th><th class="small text-end">Need</th><th class="small text-end">Have</th>' +
			'<th class="small text-end">Other this build</th><th class="small text-end">Remaining after</th></tr></thead><tbody>';
		rows.forEach(function(d) {
			var remCls = d.remaining < 0 ? 'stat-neg' : (d.remaining === 0 ? 'stat-zero' : 'stat-pos');
			h += '<tr><td class="small"><span class="badge ' + (d.kind === 'FP' ? 'bg-secondary' : 'bg-light text-dark') + ' me-1">' + d.kind + '</span>' +
				'<strong>' + esc(d.name) + '</strong>' + (d.sub ? ' <span class="text-muted">' + esc(d.sub) + '</span>' : '') + '</td>' +
				'<td class="small text-end">' + d.need + '</td><td class="small text-end">' + d.have + '</td>' +
				'<td class="small text-end text-muted">' + (d.committed === null ? '—' : d.committed) + '</td>' +
				'<td class="small text-end ' + remCls + '">' + d.remaining + '</td></tr>';
		});
		h += '</tbody></table></div>';
		return h;
	}
	function loadReadiness(fresh, ai) {
		$('#seasonStatus').removeClass('text-danger')
			.text(ai ? 'Building the detailed plan…' : (fresh ? 'Refreshing from Shopify…' : 'Loading readiness…'));
		if (ai) { $('#seasonReport').hide(); $('#seasonReportNote').text(''); }
		$('#seasonRefresh, #seasonAiBtn').prop('disabled', true);
		$.ajax({ url: '/ajax/research/season_report.php', method: 'POST', dataType: 'json', timeout: 180000,
			data: { fresh: fresh ? 1 : 0, ai: ai ? 1 : 0 } })
		.done(function(res) {
			if (res.error) { $('#seasonStatus').addClass('text-danger').text(res.error); return; }
			$('#seasonStatus').text('');
			if (res.computed_at) $('#seasonAsOf').text('as of ' + res.computed_at);
			if (res.data_warning) {
				$('#seasonStatus').html('<div class="alert alert-warning mb-0 mt-1">' + esc(res.data_warning) + '</div>');
			}

			// Three quarter readiness cards
			if (res.summary) {
				var cards = '';
				res.summary.forEach(function(q, si) {
					cards += '<div class="col-12 col-md-4"><div class="card h-100" style="border-top:3px solid #4680ff;"><div class="card-body">' +
						'<div class="d-flex justify-content-between align-items-start mb-2">' +
						'<span class="fw-bold">' + esc(q.label) + '</span> ' + verdictBadge(q.status) + '</div>';
						cards += '<div class="small fw-semibold text-muted">Build (Animators):</div>';
						if (q.animator_items && q.animator_items.length) {
							cards += '<table class="table table-sm mb-2"><thead><tr><th class="small">SKU</th><th class="small text-end">Demand</th><th class="small text-end">Build</th><th class="small text-end">Have</th><th class="small text-end">Can build</th></tr></thead><tbody>';
							q.animator_items.forEach(function(it, ai){
								var rid = 'ai-' + si + '-' + ai; cards += '<tr class="ai-row" data-target="' + rid + '" style="cursor:pointer;"><td class="small"><i class="ti ti-chevron-right ai-chev"></i> <code>' + esc(it.sku) + '</code></td>' +
									'<td class="text-end small text-muted">' + (it.demand||0) + '</td>' +
										'<td class="text-end small ' + (it.to_build>0?'stat-neg':'stat-pos') + '">' + it.to_build + '</td>' +
									'<td class="text-end small text-muted">' + (it.have||0) + '</td>' +
									'<td class="text-end small">' + (it.buildable==null?'-':it.buildable) + '</td></tr>' +
										'<tr id="' + rid + '" class="ai-detail" style="display:none;"><td colspan="5" class="p-0">' + aiExplain(it) + '</td></tr>';
							});
							cards += '</tbody></table>';
						} else { cards += '<div class="small text-muted mb-2">No animator demand.</div>'; }
					if (q.fg_items && q.fg_items.length) {
						cards += '<div class="small fw-semibold text-muted">Buy (cases / wings):</div><ul class="small mb-0" style="padding-left:1.1rem;">';
						q.fg_items.forEach(function(it){
							cards += '<li><strong>' + it.order + '</strong> × <code>' + esc(it.sku) + '</code> ' +
								'<span class="text-muted">(have ' + (it.have||0) + ', last-yr ' + (it.need||0) + ')</span></li>';
						});
						cards += '</ul>';
					}
					cards += '<div class="small text-muted mt-2">vs ' + esc(q.prior_window) + '</div>';
					cards += '</div></div></div>';
				});
				$('#seasonSummary').html(cards).show();
			}

			// Shared-parts drawdown across seasons (cumulative), grouped by category
			if (res.shared_by_part) renderSharedDrawdown(res.shared_by_part, res.season_shorts);

			// Raw-material order list (cumulative shortfall + order-by date)
			if (res.raw_orders) {
				var ro = res.raw_orders, h = '';
				if (!ro.length) {
					h = '<div class="alert alert-success mb-0">Raw materials on hand + on order cover every animator build through all three seasons. Nothing to order.</div>';
				} else {
					h = '<div class="panel-title mb-2">Raw Materials to Order <span class="muted-pill ms-1">Est. $' + (res.raw_total_cost||0).toFixed(2) + '</span></div>' +
						'<div class="small text-muted mb-2">Projected quantities cover the whole year (all three seasons of projected builds), rounded up to MOQ. <strong>Order by</strong> is when to place it so it arrives before the part runs out (lead time). Red = already overdue.</div>' +
						'<div class="scroll-table"><table class="table dash-table align-middle"><thead><tr>' +
						'<th>Manufacturer</th><th>Part</th><th class="text-center">Proj. use (yr)</th><th class="text-center">Have</th>' +
						'<th class="text-center">Order (MOQ)</th><th class="text-center">Order by</th><th class="text-center">Lead</th><th class="text-end">Est. Cost</th></tr></thead><tbody>';
					ro.forEach(function(r){
						var byCls = r.by_past ? 'stat-neg fw-bold' : '';
						h += '<tr><td class="small fw-semibold">' + esc(r.manufacturer) + '</td>' +
							'<td class="fw-semibold">' + esc(r.part) + ' <span class="text-muted small">' + esc(r.description||'') + '</span></td>' +
							'<td class="text-center">' + r.total_usage + '</td>' +
							'<td class="text-center text-muted">' + (r.on_hand + r.on_order) + '</td>' +
							'<td class="text-center stat-neg">' + r.order_qty + '</td>' +
							'<td class="text-center small ' + byCls + '">' + (r.by_date || '—') + (r.by_past?' ⚠':'') + '</td>' +
							'<td class="text-center small">' + r.lead_time_days + 'd</td>' +
							'<td class="text-end fw-semibold">$' + r.cost.toFixed(2) + '</td></tr>';
					});
					h += '</tbody></table></div>';
				}
				$('#rawOrders').html(h).show();
			}

			// Chart: prior-year units per season (animators vs other)
			if (res.charts && typeof Chart !== 'undefined') {
				$('#seasonCharts').show();
				var ctx = document.getElementById('seasonChart').getContext('2d');
				if (seasonChartObj) seasonChartObj.destroy();
				seasonChartObj = new Chart(ctx, {
					type: 'bar',
					data: {
						labels: res.charts.labels,
						datasets: [
							{ label: 'Animators', data: res.charts.animators, backgroundColor: '#2ca87f' },
							{ label: 'Cases / Wings / Other', data: res.charts.finished_goods, backgroundColor: '#4680ff' }
						]
					},
					options: { responsive: true, plugins: { title: { display: true, text: 'Prior-year units sold by season' } },
						scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
				});
			}

			// Tradeshow spike box
			if (res.tradeshow) {
				var t = res.tradeshow, h = '<div class="fw-semibold mb-1">Prior-year Jul–Aug POS (tradeshows)</div>';
				h += '<div class="text-muted mb-1">Total POS units: <strong>' + (t.total || 0) + '</strong></div>';
				if (t.top_days && t.top_days.length) {
					h += '<div class="small text-muted">Biggest POS days:</div><ul class="small mb-0">';
					t.top_days.forEach(function(d){ h += '<li>' + d.date + ' — ' + d.units + ' units</li>'; });
					h += '</ul>';
				}
				$('#tradeshowBox').html(h);
			}

			// Optional AI detail plan
			if (res.report) {
				var html = (typeof marked !== 'undefined') ? marked.parse(res.report) : res.report.replace(/\n/g,'<br>');
				$('#seasonReport').html('<div class="fw-semibold mb-2">Detailed action plan & lead-time timing</div>' + html).show();
			}
			if (res.report_note) $('#seasonReportNote').text(res.report_note);
		})
		.fail(function(xhr, status) {
			$('#seasonStatus').addClass('text-danger').text(
				status === 'timeout' ? 'Timed out — try Refresh.' : 'Failed (' + (xhr.status || 'no response') + ').');
		})
		.always(function() { $('#seasonRefresh, #seasonAiBtn').prop('disabled', false); });
	}

	$(document).on('click', '.bp-row', function() {
		var $d = $('#' + $(this).data('target'));
		$d.toggle();
		$(this).find('.bp-chev').toggleClass('ti-chevron-right ti-chevron-down');
	});

	// Live recalc when a build quantity is edited
	$(document).on('input', '.bp-build', function() { recomputeSeason($(this).data('si')); });

	// Expand a per-SKU build explanation
	$(document).on('click', '.ai-row', function() {
		$('#' + $(this).data('target')).toggle();
		$(this).find('.ai-chev').toggleClass('ti-chevron-right ti-chevron-down');
	});

	$('#seasonRefresh').on('click', function() { loadReadiness(true, false); });
	$('#seasonAiBtn').on('click', function() { loadReadiness(false, true); });
	<?php if ($shopConfigured && !$shopErr): ?>
	loadReadiness(false, false);   // auto-load on page open (served from cache when fresh)
	<?php endif; ?>

	// ── Planning events ───────────────────────────────────────────────────
	$('#poToggle').on('click', function() {
		$('#poPanel').slideToggle(150);
	});

	$('#poDetect').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#poDetectStatus').removeClass('text-danger').text('Scanning Shopify…');
		$('#poCandidates').empty();
		$.ajax({ url: '/ajax/research/detect_pos.php', method: 'POST', dataType: 'json', timeout: 120000 })
		.done(function(res) {
			if (res.error) { $('#poDetectStatus').addClass('text-danger').text(res.error); return; }
			var c = res.candidates || [];
			if (!c.length) { $('#poDetectStatus').text('No wholesale / large orders found.'); return; }
			$('#poDetectStatus').text(c.length + ' found — click Add on any to track it.');
			var html = '<div class="scroll-table"><table class="table dash-table align-middle">' +
				'<thead><tr><th>Date</th><th>Order</th><th>Channel</th><th class="text-center">Units</th><th>Products</th><th></th></tr></thead><tbody>';
			c.forEach(function(o, i) {
				html += '<tr>' +
					'<td class="small">' + (o.date || '—') + '</td>' +
					'<td class="fw-semibold small">' + esc(o.label) + '</td>' +
					'<td><span class="muted-pill">' + esc(o.channel) + '</span></td>' +
					'<td class="text-center fw-bold">' + o.qty + '</td>' +
					'<td class="small text-muted">' + esc(o.details) + '</td>' +
					'<td><button class="btn btn-sm btn-success po-add" data-i="' + i + '">Add as PO</button></td>' +
					'</tr>';
			});
			html += '</tbody></table></div>';
			$('#poCandidates').html(html);
			$('#poCandidates').data('cands', c);
		})
		.fail(function(xhr) {
			$('#poDetectStatus').addClass('text-danger').text('Scan failed (' + (xhr.status || 'no response') + ').');
		})
		.always(function() { $btn.prop('disabled', false); });
	});

	$(document).on('click', '.po-add', function() {
		var $btn = $(this);
		var o = ($('#poCandidates').data('cands') || [])[$btn.data('i')];
		if (!o) return;
		$btn.prop('disabled', true).text('Adding…');
		$.post('/ajax/research/event_save.php', {
			type: 'po', name: o.label, event_date: o.date, repeats: 0, details: o.details
		}, function(resp) {
			if ($.trim(resp) === 'ok') { location.reload(); }
			else { alert('Could not add: ' + resp); $btn.prop('disabled', false).text('Add as PO'); }
		});
	});

	function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

	$('#evAdd').on('click', function() {
		var name = $.trim($('#evName').val());
		if (!name) { alert('Enter a name.'); return; }
		var $btn = $(this);
		$btn.prop('disabled', true).text('…');
		$.post('/ajax/research/event_save.php', {
			type:       $('#evType').val(),
			name:       name,
			event_date: $('#evDate').val(),
			end_date:   $('#evEnd').val(),
			repeats:    $('#evRepeats').is(':checked') ? 1 : 0,
			details:    $('#evDetails').val()
		}, function(resp) {
			if ($.trim(resp) === 'ok') { location.reload(); }
			else { alert('Could not save: ' + resp); $btn.prop('disabled', false).text('Add'); }
		}).fail(function(xhr) {
			alert('Save failed: ' + ($.trim(xhr.responseText) || xhr.status));
			$btn.prop('disabled', false).text('Add');
		});
	});

	$(document).on('click', '.ev-del', function() {
		if (!confirm('Remove this event?')) return;
		var id = $(this).data('id');
		$.post('/ajax/research/event_delete.php', { id: id }, function(resp) {
			if ($.trim(resp) === 'ok') {
				$("tr[data-id='" + id + "']").remove();
			} else { alert('Could not remove.'); }
		});
	});
</script>

<!-- Markdown renderer for assistant answers -->
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
