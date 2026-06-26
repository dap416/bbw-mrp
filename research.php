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

	// ── Annual goal planning ─────────────────────────────────────────────────
	// For each product: credit finished stock already on hand against the goal,
	// explode the remaining units-to-build through the BOM, and aggregate the
	// gross part requirement across every product. Subtracting parts on hand
	// gives the shopping list to hit the year's targets.
	$goalRows  = [];   // per-product planner rows
	$partNeed  = [];   // partid => required parts across all goals
	$anyGoals  = false;
	foreach ($products as $p) {
		$goal  = $hasGoalCol ? (int)($p['annual_goal'] ?? 0) : 0;
		$sku   = $p['shopify_sku'] ?? '';
		$found = $sku !== '' && isset($shopSkus[$sku]);
		$stock = $found ? (int)$shopSkus[$sku]['qty'] : null;
		$credit  = ($stock !== null && $stock > 0) ? $stock : 0; // only credit positive on-hand
		$toBuild = max(0, $goal - $credit);
		$lines   = $bomByProd[$p['id']] ?? [];
		$hasBom  = !empty($lines);
		if ($goal > 0) $anyGoals = true;

		if ($toBuild > 0 && $hasBom) {
			foreach ($lines as $l) {
				$pid = $l['partid'];
				if (!isset($partNeed[$pid])) {
					$partNeed[$pid] = [
						'partno' => $l['partno'],
						'desc'   => $l['desc'],
						'cost'   => (float)$l['cost'],
						'qoh'    => (int)$l['qoh'],
						'need'   => 0,
					];
				}
				$partNeed[$pid]['need'] += $toBuild * (int)$l['qty'];
			}
		}

		$goalRows[] = [
			'p'       => $p,
			'goal'    => $goal,
			'stock'   => $stock,
			'toBuild' => $toBuild,
			'hasBom'  => $hasBom,
		];
	}
	ksort($partNeed);

	$totalOrderCost = 0.0;
	foreach ($partNeed as $pn) {
		$toOrder = max(0, $pn['need'] - $pn['qoh']);
		$totalOrderCost += $toOrder * $pn['cost'];
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
		<div class="text-muted small">Compare live Shopify stock against what you can build from raw materials on hand.</div>
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
		Ask things like <em>"How much do I need to order to survive until Oct 1 vs last year's sales?"</em>
		It considers your Shopify sales history, finished &amp; raw inventory, BOMs, MOQ, lead times, and the POs/tradeshows below.
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

<!-- ── SEASON READINESS REPORT ──────────────────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #2ca87f;">
<div class="card-body">

	<div class="panel-header mb-2">
		<span class="panel-title">Season Readiness Report</span>
		<button id="seasonBtn" class="btn btn-sm btn-success"<?php echo $aiReady ? '' : ' disabled'; ?>>
			<i class="ti ti-report-analytics me-1"></i>Generate Report
		</button>
	</div>

	<p class="text-muted small mb-2">
		Three sections — <strong>Jul–Sep</strong>, <strong>Oct–Dec</strong>, <strong>Jan–Mar</strong> — scoring how prepared you are vs. last year (no growth):
		raw-material POs by manufacturer for Animators, and finished-goods orders (cases, wings, etc.) for everything else.
	</p>
	<?php if (!$aiReady): ?>
	<div class="alert alert-info mb-0"><a href="/integrations.php">Connect a Claude API key</a> to enable this report.</div>
	<?php endif; ?>

	<span id="seasonStatus" class="small text-muted"></span>

	<div id="seasonCharts" class="row g-3 mt-1" style="display:none;">
		<div class="col-12 col-lg-7"><canvas id="seasonChart" height="120"></canvas></div>
		<div class="col-12 col-lg-5">
			<div id="tradeshowBox" class="small"></div>
		</div>
	</div>

	<div id="seasonReport" class="mt-3" style="display:none; background:#faf9f7; border:1px solid #eee; border-radius:8px; padding:16px;"></div>

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

<!-- ── ANNUAL GOAL PLANNER ──────────────────────────────────────────────── -->
<?php if ($hasGoalCol): ?>
<div class="card mb-4" style="border-top:3px solid #2ca87f;">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">Annual Goal Planner</span>
		<span class="muted-pill">Units you want to produce this year</span>
	</div>

	<p class="text-muted small mb-3">
		Set a yearly target per product. Finished stock already on hand (from Shopify) is credited against the
		goal, and the remaining <strong>To Build</strong> drives the parts requirement below.
	</p>

	<div class="scroll-table">
	<table class="table dash-table align-middle">
		<thead><tr>
			<th>Product</th>
			<th style="width:200px;">Annual Goal</th>
			<th class="text-center">In Stock</th>
			<th class="text-center">To Build</th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php foreach ($goalRows as $r): $p = $r['p']; ?>
		<tr>
			<td class="fw-semibold">
				<?php echo htmlspecialchars($p['name']); ?>
				<?php if (!$r['hasBom']): ?><span class="muted-pill ms-1">No BOM</span><?php endif; ?>
			</td>
			<td>
				<input type="number" min="0" class="form-control form-control-sm goal-input" style="width:120px;"
					data-id="<?php echo (int)$p['id']; ?>"
					value="<?php echo (int)$r['goal']; ?>" />
			</td>
			<td class="text-center text-muted">
				<?php echo $r['stock'] === null ? '—' : number_format($r['stock']); ?>
			</td>
			<td class="text-center fw-bold">
				<?php echo $r['goal'] > 0 ? number_format($r['toBuild']) : '—'; ?>
			</td>
			<td>
				<button class="btn btn-sm btn-primary goal-save" data-id="<?php echo (int)$p['id']; ?>">Save</button>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if (!$goalRows): ?>
		<tr><td colspan="5" class="text-muted text-center py-3">No products found.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	</div>

</div>
</div>

<!-- ── PARTS REQUIRED FOR GOALS ─────────────────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #e58a00;">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">Parts Required for Annual Goals</span>
		<?php if (!empty($partNeed)): ?>
		<span class="muted-pill">Est. to order: $<?php echo number_format($totalOrderCost, 2); ?></span>
		<?php endif; ?>
	</div>

	<?php if (!$anyGoals): ?>
	<div class="text-center text-muted py-4">
		<i class="ti ti-target" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
		Set an annual goal above to see the parts you'll need.
	</div>
	<?php elseif (empty($partNeed)): ?>
	<div class="text-center text-muted py-4">
		<i class="ti ti-circle-check" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
		Your finished-goods stock already covers every goal — nothing left to build.
	</div>
	<?php else: ?>
	<p class="text-muted small mb-3">
		Total parts needed to build every product up to its goal, after crediting finished stock on hand.
		<strong>To Order</strong> is the shortfall after subtracting raw materials you already have.
	</p>
	<div class="scroll-table">
	<table class="table dash-table align-middle">
		<thead><tr>
			<th>Part #</th>
			<th>Description</th>
			<th class="text-center">Required</th>
			<th class="text-center">On Hand</th>
			<th class="text-center">To Order</th>
			<th class="text-end">Unit Cost</th>
			<th class="text-end">Est. Cost</th>
		</tr></thead>
		<tbody>
		<?php foreach ($partNeed as $pn):
			$toOrder = max(0, $pn['need'] - $pn['qoh']);
			$lineCost = $toOrder * $pn['cost'];
		?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($pn['partno']); ?></td>
			<td class="small"><?php echo htmlspecialchars($pn['desc']); ?></td>
			<td class="text-center"><?php echo number_format($pn['need']); ?></td>
			<td class="text-center text-muted"><?php echo number_format($pn['qoh']); ?></td>
			<td class="text-center">
				<span class="<?php echo $toOrder > 0 ? 'stat-neg' : 'stat-pos'; ?>"><?php echo number_format($toOrder); ?></span>
			</td>
			<td class="text-end text-muted">$<?php echo number_format($pn['cost'], 2); ?></td>
			<td class="text-end fw-semibold"><?php echo $lineCost > 0 ? '$'.number_format($lineCost, 2) : '—'; ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<td colspan="6" class="text-end fw-bold">Estimated total to order</td>
				<td class="text-end fw-bold">$<?php echo number_format($totalOrderCost, 2); ?></td>
			</tr>
		</tfoot>
	</table>
	</div>
	<?php endif; ?>

</div>
</div>
<?php endif; ?>

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
		<?php foreach ($products as $p): ?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></td>
			<td>
				<input type="text" class="form-control form-control-sm sku-input map-input"
					list="skuList"
					data-id="<?php echo (int)$p['id']; ?>"
					data-prev="<?php echo htmlspecialchars($p['shopify_sku'] ?? ''); ?>"
					value="<?php echo htmlspecialchars($p['shopify_sku'] ?? ''); ?>"
					placeholder="e.g. LDXL" />
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

	$(document).on('click', '.goal-save', function() {
		var $btn = $(this);
		var id   = $btn.data('id');
		var goal = $(".goal-input[data-id='" + id + "']").val();
		$btn.prop('disabled', true).text('Saving…');
		$.post('/ajax/research/set_goal.php', { id: id, goal: goal }, function(resp) {
			if (resp === 'ok') {
				location.reload();
			} else {
				alert('Could not save goal.');
				$btn.prop('disabled', false).text('Save');
			}
		});
	});

	$(document).on('keypress', '.goal-input', function(e) {
		if (e.which === 13) $(this).closest('tr').find('.goal-save').click();
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

	// ── Season Readiness report ───────────────────────────────────────────
	var seasonChartObj = null;
	$('#seasonBtn').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#seasonStatus').removeClass('text-danger').text('Crunching last year’s sales and building the report… (up to ~90s)');
		$('#seasonReport').hide();
		$.ajax({ url: '/ajax/research/season_report.php', method: 'POST', dataType: 'json', timeout: 180000 })
		.done(function(res) {
			if (res.error) { $('#seasonStatus').addClass('text-danger').text(res.error); return; }
			$('#seasonStatus').text('');

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

			var html = (typeof marked !== 'undefined') ? marked.parse(res.report) : res.report.replace(/\n/g,'<br>');
			$('#seasonReport').html(html).show();
		})
		.fail(function(xhr, status) {
			$('#seasonStatus').addClass('text-danger').text(
				status === 'timeout' ? 'Timed out — try again.' : 'Failed (' + (xhr.status || 'no response') + ').');
		})
		.always(function() { $btn.prop('disabled', false); });
	});

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
