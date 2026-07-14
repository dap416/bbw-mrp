<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('research')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/shopify.php");
	require_once(__DIR__."/includes/planning.php");   // fp_available_qty()
	require_once(__DIR__."/includes/anthropic.php");

	$db = db_connect();

	// "Refresh Live Data" — drop cached live Shopify inventory so stock re-pulls fresh,
	// force the season report to recompute, then bounce back to a clean URL.
	if (!empty($_GET['fresh'])) {
		try {
			$db->exec("DELETE FROM data_cache WHERE ckey = 'season_fp_loc_v2' OR ckey LIKE 'season_loc_%' OR ckey LIKE 'season_src_%'");
			setting_set($db, 'season_cache_at', '0');
		} catch (Throwable $e) {}
		header('Location: /research.php'); exit;
	}

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
	// Finished-product AVAILABLE (on hand − committed) by SKU, from the per-location data
	// (shared cache with the season report). Used so every FP stock figure is consistent.
	$fpLoc = ($shopConfigured && !$shopErr)
		? (shopify_cache_remember($db, 'season_fp_loc_v2', inventory_cache_ttl($db), fn() => shopify_fp_by_location())['data']['skus'] ?? [])
		: [];

	// Tradeshows + which are excluded from demand (owner picks which shows to include).
	$showList = $shopConfigured ? tradeshow_locations() : [];
	$excludedShows = demand_excluded_shows($db);
	$needHidden = need_hidden_items($db);   // Need-to-Order lines hidden (chosen not to order)

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
	// [Amazon] twins share the base SKU — keep them out of Research lists so nothing is
	// doubled (they're only shown on the Products and Packaging pages).
	$mapped = [];
	foreach ($products as $p) {
		if (is_amazon_product($p['name'])) continue;
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

/* Readable tables for AI markdown answers (chat + season report) */
#chatThread table, #seasonReport table {
	width:100%; border-collapse:collapse; margin:10px 0; font-size:0.85rem;
}
#chatThread th, #chatThread td, #seasonReport th, #seasonReport td {
	border:1px solid #e2e5e8; padding:6px 10px; text-align:left; vertical-align:top;
}
#chatThread th, #seasonReport th { background:#eef1f4; font-weight:600; white-space:nowrap; }
#chatThread h1, #chatThread h2, #chatThread h3, #chatThread h4 { font-size:1rem; font-weight:700; margin:12px 0 6px; }
#chatThread ul, #seasonReport ul { padding-left:1.2rem; margin-bottom:8px; }
#chatThread code, #seasonReport code { background:#f1f3f5; padding:1px 5px; border-radius:4px; }
#chatThread p { margin-bottom:8px; }
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
		<button class="btn btn-outline-secondary btn-sm" onclick="this.disabled=true;this.innerHTML='<i class=\'ti ti-refresh me-1\'></i>Refreshing…';location.href='?fresh=1'">
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

<!-- ── INCLUDE TRADESHOWS IN DEMAND ─────────────────────────────────────── -->
<?php if ($showList): ?>
<div class="card mb-3" style="border-left:4px solid #2ca87f;">
<div class="card-body py-2">
	<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
		<div>
			<span class="fw-bold"><i class="ti ti-tent me-1"></i>Tradeshows in demand</span>
			<span class="text-muted small ms-1">Shows are intermittent — uncheck any you're <strong>not</strong> attending and their POS units drop out of projected demand.<?php echo $excludedShows ? ' <strong>'.count($excludedShows).' excluded.</strong>' : ''; ?></span>
		</div>
		<button class="btn btn-sm btn-outline-secondary" id="demandShowsToggle"><i class="ti ti-adjustments me-1"></i>Choose shows</button>
	</div>
	<div id="demandShowsPanel" class="mt-2" style="display:none;">
		<div class="row g-1">
			<?php foreach ($showList as $show): $inc = !in_array($show['name'], $excludedShows, true); $id = 'ds_'.substr(md5($show['name']),0,8); ?>
			<div class="col-12 col-md-6 col-lg-4">
				<div class="form-check">
					<input class="form-check-input demand-show" type="checkbox" value="<?php echo htmlspecialchars($show['name'], ENT_QUOTES); ?>" id="<?php echo $id; ?>"<?php echo $inc ? ' checked' : ''; ?>>
					<label class="form-check-label small" for="<?php echo $id; ?>"><?php echo htmlspecialchars($show['name']); ?> <span class="show-units text-muted" data-name="<?php echo htmlspecialchars($show['name'], ENT_QUOTES); ?>" style="font-size:0.7rem;"></span></label>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="d-flex gap-3 mt-2 align-items-center flex-wrap">
			<button class="btn btn-sm btn-primary" id="demandShowsSave">Save &amp; recompute demand</button>
			<a href="#" id="demandShowsImpact" class="small text-decoration-none"><i class="ti ti-eye me-1"></i>Show each show's last-year units</a>
			<span id="demandShowsMsg" class="small"></span>
		</div>
	</div>
</div>
</div>
<?php endif; ?>

<!-- ── RESEARCH TABS ────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="researchTabs" role="tablist">
	<li class="nav-item" role="presentation"><button class="nav-link active" id="tab-season-btn" data-bs-toggle="tab" data-bs-target="#pane-season" type="button" role="tab"><i class="ti ti-calendar-stats me-1"></i>Season Readiness</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link" id="tab-need-btn" data-bs-toggle="tab" data-bs-target="#pane-need" type="button" role="tab"><i class="ti ti-shopping-cart-plus me-1"></i>Need to Order</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link" id="tab-parts-btn" data-bs-toggle="tab" data-bs-target="#pane-parts" type="button" role="tab"><i class="ti ti-list-details me-1"></i>Parts Breakdown</button></li>
	<li class="nav-item" role="presentation"><button class="nav-link" id="tab-planning-btn" data-bs-toggle="tab" data-bs-target="#pane-planning" type="button" role="tab"><i class="ti ti-robot me-1"></i>Planning Assistant</button></li>
</ul>
<div class="tab-content" id="researchTabContent">

<!-- ══ PANE: PLANNING ASSISTANT ═════════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-planning" role="tabpanel" aria-labelledby="tab-planning-btn">

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

	<div class="row g-3">
		<!-- Saved chats -->
		<div class="col-12 col-lg-3">
			<div class="d-flex align-items-center justify-content-between mb-2">
				<span class="small fw-semibold text-muted">Saved chats</span>
				<button id="chatNew" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> New</button>
			</div>
			<div id="chatList" class="small" style="max-height:320px; overflow:auto;"></div>
		</div>

		<!-- Conversation -->
		<div class="col-12 col-lg-9">
			<div id="chatTitle" class="fw-semibold mb-2" style="display:none;"></div>

			<div id="chatNewHint" class="text-muted small mb-2">
				New chat. Ask things like <em>"How many of each Animator do I build to be ready for July?"</em> —
				mention any dates or deadlines in your question. It uses your Shopify sales history (by season), finished &amp; raw inventory, BOMs, MOQ, lead times, and the POs/tradeshows below.
			</div>

			<div id="chatThread" class="mb-2"></div>

			<textarea id="planQuestion" class="form-control mb-2" rows="2"
				placeholder="Ask a question (or a follow-up)…"></textarea>
			<div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
				<input type="file" id="chatFiles" accept=".pdf,image/png,image/jpeg,image/gif,image/webp" multiple style="display:none" />
				<button id="chatAttach" type="button" class="btn btn-outline-secondary btn-sm"><i class="ti ti-paperclip me-1"></i>Attach PO / image</button>
				<span id="chatFileNames" class="small text-muted"></span>
			</div>
			<div class="d-flex align-items-center gap-2">
				<button id="askBtn" class="btn btn-primary btn-sm">Ask</button>
				<span id="askStatus" class="small text-muted"></span>
			</div>
		</div>
	</div>
	<?php endif; ?>

</div>
</div>

</div><!-- /pane-planning -->

<!-- ══ PANE: SEASON READINESS ═══════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="pane-season" role="tabpanel" aria-labelledby="tab-season-btn">

<!-- ── SEASON READINESS ─────────────────────────────────────────────────── -->
<div class="card mb-4" style="border-top:3px solid #2ca87f;">
<div class="card-body">

	<div class="panel-header mb-2">
		<span class="panel-title">Season Readiness</span>
		<div class="d-flex align-items-center gap-2 flex-wrap">
			<span id="seasonAsOf" class="small text-muted"></span>
			<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="toggleCanBuild"><label class="form-check-label small" for="toggleCanBuild">Show &ldquo;Can build&rdquo;</label></div>
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

</div><!-- /pane-season -->

<!-- ══ PANE: NEED TO ORDER ══════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-need" role="tabpanel" aria-labelledby="tab-need-btn">
<div class="card mb-4" style="border-top:3px solid #e64545;">
<div class="card-body">
	<div class="panel-header mb-2">
		<span class="panel-title">Need to Order</span>
		<span id="needSummary" class="small text-muted"></span>
	</div>
	<p class="text-muted small mb-2">Everything to order to stay ahead of demand, <strong>rounded up to each item's MOQ</strong> and back-timed by <strong>lead time</strong>. <strong>Order by</strong> is the last day to place it so it lands before you run short — anything already past that is flagged <span class="badge bg-danger" style="font-size:0.6rem;">ORDER NOW</span>. <strong>Need</strong> = last year's sales across the whole season — <strong>preseason (Jul–Sep) + in-season (Oct–Dec) + postseason (Jan–Mar)</strong>, through Mar 2027 — shown as the total with the three-quarter split (Pre·In·Post) beneath it. Finished goods use their group's MOQ/lead (set on the Parts Breakdown tab); raw parts use the parts table.</p>
	<div id="needToOrder"></div>
</div>
</div>
</div><!-- /pane-need -->

<!-- ══ PANE: PARTS BREAKDOWN ════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="pane-parts" role="tabpanel" aria-labelledby="tab-parts-btn">

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
			<th class="text-center">In Stock<br><small class="text-muted fw-normal">on hand (Shopify)</small></th>
			<th class="text-center">Can Build Now<br><small class="text-muted fw-normal">from raw materials</small></th>
			<th class="text-center">Total Available<br><small class="text-muted fw-normal">stock + buildable</small></th>
			<th>Limiting Raw Material</th>
		</tr></thead>
		<tbody>
		<?php foreach ($mapped as $p):
			$sku   = $p['shopify_sku'];
			$found = isset($shopSkus[$sku]);
			// On hand — total physical (consistent with Need to Order / Season Readiness).
			$stock = $found ? (int)fp_onhand_qty($fpLoc, $sku, (int)$shopSkus[$sku]['qty']) : null;

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
			if (is_amazon_product($p['name'])) continue;   // [Amazon] twins inherit the base SKU — not shown here
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

<!-- ── FINISHED-GOODS SUPPLY GROUPS (MOQ / lead / cost) ──────────────────── -->
<div class="card mb-4" style="border-top:3px solid #e64545;">
<div class="card-body">
	<div class="panel-header mb-2"><span class="panel-title">Finished-Goods Order Terms</span></div>
	<p class="text-muted small mb-2">Imported finished goods (WINGZ, cases, etc.) are ordered by <strong>group</strong> — each group shares one source, cost, and lead time. Set the <strong>MOQ</strong>, <strong>lead time</strong>, and <strong>unit cost</strong> per group and the <a href="#" class="goto-need">Need to Order</a> tab times the orders correctly. Groups without terms assume a 90-day lead and MOQ 1 until you set them.</p>
	<div id="fgGroups"></div>
</div>
</div>

<!-- ── ALL RAW MATERIALS — STOCK REFERENCE (from the old stock-order page) ── -->
<div id="rawAllWrap" class="mb-4" style="display:none;">
	<div class="card"><div class="card-body">
		<div class="panel-header mb-2"><span class="panel-title">All Raw Materials — Stock Reference</span></div>
		<div class="small text-muted mb-2">Every stocked part with its Best Stock Level (auto-computed from build history minus omit), trailing 6- &amp; 12-month build demand, and a BSL-based order suggestion. <strong>Omit</strong> = units treated as overstock that reduce the stock target; editing it recomputes BSL on the next refresh. Ordering is done from the <a href="/orders.php">Open Orders</a> page.</div>
		<div id="rawAll"></div>
	</div></div>
</div>

</div><!-- /pane-parts -->
</div><!-- /tab-content -->

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


	// ── Planning Assistant (saved, multi-turn chats) ──────────────────────
	var currentChatId = 0;

	function mdToHtml(t) { return (typeof marked !== 'undefined') ? marked.parse(t) : (t || '').replace(/\n/g, '<br>'); }

	function renderThread(messages) {
		var h = '';
		(messages || []).forEach(function(m){
			if (m.role === 'user') {
				var chips = '';
				(m._files || []).forEach(function(fobj){
					chips += '<span class="badge bg-light text-dark border me-1"><i class="ti ti-' + (fobj.kind === 'document' ? 'file-text' : 'photo') + ' me-1"></i>' + esc(fobj.name) + '</span>';
				});
				h += '<div class="mb-2"><div class="small fw-semibold text-muted">You</div>' +
					(m.content ? '<div style="white-space:pre-wrap;">' + esc(m.content) + '</div>' : '') +
					(chips ? '<div class="mt-1">' + chips + '</div>' : '') + '</div>';
			} else {
				h += '<div class="mb-3" style="background:#faf9f7; border:1px solid #eee; border-radius:8px; padding:12px; overflow-x:auto;">' +
					mdToHtml(m.content) + '</div>';
			}
		});
		$('#chatThread').html(h);
	}

	function loadChatList() {
		$.getJSON('/ajax/research/chat_list.php', function(res){
			var c = (res && res.chats) || [], h = '';
			if (!c.length) { h = '<div class="text-muted">No saved chats yet.</div>'; }
			c.forEach(function(ch){
				var active = (ch.id === currentChatId) ? ' fw-bold' : '';
				h += '<div class="d-flex align-items-center justify-content-between py-1 border-bottom">' +
					'<a href="#" class="chat-open text-decoration-none' + active + '" data-id="' + ch.id + '" title="' + esc(ch.updated_at) + '">' + esc(ch.title) + '</a>' +
					'<a href="#" class="chat-del text-danger ms-2" data-id="' + ch.id + '" title="Delete">&times;</a></div>';
			});
			$('#chatList').html(h);
		});
	}

	function openChat(id) {
		$.ajax({ url: '/ajax/research/chat_get.php', method: 'POST', dataType: 'json', data: { id: id } })
		.done(function(res){
			if (res.error) { return; }
			currentChatId = res.chat_id;
			localStorage.setItem('bbw_chat_id', currentChatId);
			$('#chatNewHint').hide();
			$('#chatTitle').text(res.title).show();
			renderThread(res.messages);
			loadChatList();
		});
	}

	function newChat() {
		currentChatId = 0;
		localStorage.removeItem('bbw_chat_id');
		$('#chatTitle').hide().text('');
		$('#chatThread').empty();
		$('#chatNewHint').show();
		$('#askStatus').text('');
		loadChatList();
	}

	$('#chatNew').on('click', newChat);
	$(document).on('click', '.chat-open', function(e){ e.preventDefault(); openChat($(this).data('id')); });
	$(document).on('click', '.chat-del', function(e){
		e.preventDefault();
		if (!confirm('Delete this chat?')) return;
		var id = $(this).data('id');
		$.post('/ajax/research/chat_delete.php', { id: id }, function(){
			if (id === currentChatId) newChat(); else loadChatList();
		});
	});

	// Attach files (PDF / images)
	$('#chatAttach').on('click', function(){ $('#chatFiles').click(); });
	$('#chatFiles').on('change', function(){
		var names = $.map(this.files, function(f){ return f.name; });
		$('#chatFileNames').text(names.length ? names.join(', ') : '');
	});

	$('#askBtn').on('click', function() {
		var q = $.trim($('#planQuestion').val());
		var files = document.getElementById('chatFiles').files;
		if (!q && (!files || !files.length)) { $('#askStatus').text('Enter a question or attach a file.'); return; }
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#askStatus').removeClass('text-danger').text(files && files.length ? 'Reading your file…' : 'Thinking… (up to a minute)');

		var fd = new FormData();
		fd.append('id', currentChatId);
		fd.append('question', q);
		for (var i = 0; i < (files ? files.length : 0); i++) fd.append('files[]', files[i]);

		$.ajax({
			url: '/ajax/research/chat_ask.php', method: 'POST', dataType: 'json', timeout: 180000,
			data: fd, processData: false, contentType: false
		}).done(function(res){
			if (res.error) { $('#askStatus').addClass('text-danger').text(res.error); return; }
			currentChatId = res.chat_id;
			localStorage.setItem('bbw_chat_id', currentChatId);
			$('#chatNewHint').hide();
			$('#chatTitle').text(res.title).show();
			renderThread(res.messages);
			$('#planQuestion').val('');
			$('#chatFiles').val(''); $('#chatFileNames').text('');
			$('#askStatus').text('');
			loadChatList();
		}).fail(function(xhr, status){
			$('#askStatus').addClass('text-danger').text(
				status === 'timeout' ? 'Timed out — try a smaller file or narrower question.'
				: 'Request failed (' + (xhr.status || 'no response') + ').');
		}).always(function(){ $btn.prop('disabled', false); });
	});

	// Restore last chat (or show the list) on load
	<?php if ($aiReady): ?>
	loadChatList();
	(function(){ var last = parseInt(localStorage.getItem('bbw_chat_id') || '0', 10); if (last) openChat(last); })();
	<?php endif; ?>

	// ── Season Readiness ──────────────────────────────────────────────────
	var seasonChartObj = null;
	function verdictBadge(s) {
		if (s === 'ready') return '<span class="badge bg-success">Ready</span>';
		if (s === 'tight') return '<span class="badge bg-warning text-dark">Tight</span>';
		return '<span class="badge bg-danger">Short</span>';
	}

	var AMZ_CUST = <?php echo json_encode(shopify_amazon_customer()); ?>;
	var BIG_ORDER_MIN = <?php echo json_encode(demand_big_order_min()); ?>;
	function money0(n) { return '$' + Math.round(n).toLocaleString(); }

	// "Where the demand comes from" — three main rows (Online store, Shows, Other), each with
	// its own sub-lines. The rows are built to add up to exactly the Demand shown on the SKU row.
	function demandSourceTable(src, demand) {
		if (!src) return '';
		var main = function(label, units) {
			return '<tr class="fw-semibold"><td class="small">' + label + '</td>' +
				'<td class="small text-end">' + units + '</td></tr>';
		};
		var sub = function(label, units) {
			return '<tr><td class="small text-muted" style="padding-left:26px;">' + label + '</td>' +
				'<td class="small text-end text-muted">' + units + '</td></tr>';
		};

		var h = '<table class="table table-sm mb-2" style="max-width:460px;"><thead><tr>' +
			'<th class="small">Where the demand comes from</th><th class="small text-end">Units</th>' +
			'</tr></thead><tbody>';

		// 1 — Online store
		var on = src.online || {};
		h += main('<i class="ti ti-world me-1"></i>Online store', on.total || 0);
		if ((on.total || 0) > 0) {
			if (on.web)         h += sub('web checkout', on.web);
			if (on.draft_big)   h += sub('drafts &ge; ' + money0(BIG_ORDER_MIN), on.draft_big);
			if (on.draft_small) h += sub('drafts &lt; ' + money0(BIG_ORDER_MIN), on.draft_small);
		}

		// 2 — Shows (POS), one sub-line per show
		var sh = src.shows || {};
		h += main('<i class="ti ti-tent me-1"></i>Shows <span class="text-muted fw-normal">(POS)</span>', sh.total || 0);
		(sh.items || []).forEach(function(s){ h += sub(esc(s.name), s.units); });

		// 3 — Other
		var ot = src.other || {};
		h += main('<i class="ti ti-dots me-1"></i>Other', ot.total || 0);
		(ot.items || []).forEach(function(s){ h += sub(esc(s.name), s.units); });

		// Anything the source scan couldn't account for (e.g. its cache is a refresh behind).
		var gap = (demand || 0) - (src.total || 0);
		if (gap !== 0) h += sub('<em>Unattributed — click Refresh to re-pull</em>', gap);

		h += '<tr class="fw-bold" style="border-top:2px solid #dee2e6;"><td class="small">Total demand</td>' +
			'<td class="small text-end">' + (demand || 0) + '</td></tr>';
		return h + '</tbody></table>';
	}

	// Where the on-hand stock physically sits right now. Units at a tradeshow location are
	// still yours (they're inside On Hand) but can't ship from a warehouse until moved back.
	function stockLocationTable(it) {
		var ar = it.at_arkansas || 0, or_ = it.at_oregon || 0, aw = it.at_shows || 0;
		if (!ar && !or_ && !aw) return '';
		var h = '<table class="table table-sm mb-2" style="max-width:460px;"><thead><tr>' +
			'<th class="small">Where that stock physically is <span class="text-muted fw-normal">(today)</span></th>' +
			'<th class="small text-end">Units</th></tr></thead><tbody>' +
			'<tr><td class="small">Arkansas warehouse</td><td class="small text-end">' + ar + '</td></tr>' +
			'<tr><td class="small">Oregon warehouse</td><td class="small text-end">' + or_ + '</td></tr>';
		if (aw > 0) {
			h += '<tr class="table-warning"><td class="small fw-semibold">At a show — not shippable from a warehouse</td>' +
				'<td class="small text-end fw-semibold">' + aw + '</td></tr>';
			(it.at_shows_detail || []).forEach(function(a){
				h += '<tr><td class="small text-muted" style="padding-left:26px;">' + esc(a.name) + '</td>' +
					'<td class="small text-end text-muted">' + (a.on_hand || 0) + '</td></tr>';
			});
			h += '<tr><td colspan="2" class="small text-muted"><i class="ti ti-alert-triangle me-1"></i>' +
				'Move these back to Arkansas or Oregon in Shopify after the show — until you do, they count as On Hand but can\'t actually ship.</td></tr>';
		} else {
			h += '<tr><td class="small text-muted">At a show</td><td class="small text-end text-muted">0</td></tr>';
		}
		return h + '</tbody></table>';
	}

	function aiExplain(it) {
		var d = it.demand || 0, h = it.have || 0, b = it.to_build || 0, bld = (it.buildable == null ? null : it.buildable);
		var html = '<div class="p-2" style="background:#f8f9fb;"><div class="small">';

		// Where this season's demand actually comes from.
		html += demandSourceTable(it.sources, d);

		// Where the on-hand stock actually is.
		html += stockLocationTable(it);

		// Regular vs [Amazon] split (this SKU combines the base animator + its [Amazon] twin).
		if (it.has_amazon && it.regular && it.amazon) {
			html += '<table class="table table-sm mb-2" style="max-width:460px;"><thead><tr><th class="small">Portion</th><th class="small text-end">Demand</th><th class="small text-end">On Hand</th><th class="small text-end">Build</th></tr></thead><tbody>' +
				'<tr><td class="small">Regular <code>' + esc(it.sku) + '</code></td><td class="text-end small">' + (it.regular.demand||0) + '</td><td class="text-end small">' + (it.regular.have||0) + '</td><td class="text-end small">' + (it.regular.to_build||0) + '</td></tr>' +
				'<tr><td class="small"><code>' + esc(it.sku) + '</code> [Amazon] <span class="text-muted">(' + AMZ_CUST + ')</span></td><td class="text-end small">' + (it.amazon.demand||0) + '</td><td class="text-end small">' + (it.amazon.have||0) + '</td><td class="text-end small stat-neg">' + (it.amazon.to_build||0) + '</td></tr>' +
				'<tr class="fw-bold"><td class="small">Total</td><td class="text-end small">' + d + '</td><td class="text-end small">' + h + '</td><td class="text-end small">' + b + '</td></tr>' +
				'</tbody></table>';
		}
		html += '<div><strong>Demand</strong> — sold last year in this same season: ' + d + '</div>';
		html += '<div><strong>On Hand</strong> — physical units in stock entering this season. Units already <em>committed</em> to open orders are <strong>not</strong> deducted: the demand above is the full season, which already includes those sales, so subtracting them here would double-count and over-build. Entering this season: ' + h + '</div>';
		if (b > 0) {
			html += '<div><strong>Build</strong> = demand − on hand = ' + d + ' − ' + Math.min(h, d) + ' = <span class="stat-neg">' + b + '</span></div>';
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

	// Full raw-material stock reference (merged from the old Raw Materials Stock Order page)
	function renderRawAll(rows) {
		if (!rows || !rows.length) { $('#rawAllWrap').hide(); return; }
		var groups = {};
		rows.forEach(function(p){ (groups[p.category] = groups[p.category] || []).push(p); });
		var order = ['Camshafts', 'Rods', 'Plates', 'Packaging Cards', 'Packaging', 'Other'];
		var h = '';
		order.forEach(function(cat){
			var g = groups[cat]; if (!g || !g.length) return;
			h += '<div class="card mb-2"><div class="card-body py-2">' +
				'<div class="fw-bold mb-1">' + esc(cat) + ' <span class="text-muted small">(' + g.length + ')</span></div>' +
				'<div class="scroll-table"><table class="table table-sm align-middle mb-0"><thead><tr>' +
				'<th class="small">Part</th><th class="small text-end">On hand</th><th class="small text-end">On order</th>' +
				'<th class="small text-end">12mo use</th><th class="small text-end">6mo use</th><th class="small text-end">BSL</th>' +
				'<th class="small text-end">MOQ</th><th class="small text-end">Lead</th><th class="small text-end">To order</th><th class="small text-end">Omit</th></tr></thead><tbody>';
			g.forEach(function(p){
				var toCls = p.to_order > 0 ? 'stat-neg fw-bold' : 'text-muted';
				var supStyle = 'width:66px;display:inline-block;font-size:0.72rem;padding:1px 4px;';
				h += '<tr><td class="small fw-semibold">' + esc(p.part) + ' <span class="text-muted">' + esc(p.description||'') + '</span>' + (p.animator_component ? ' <span class="badge bg-light text-muted" style="font-size:0.5rem;">BOM</span>' : '') + '</td>' +
					'<td class="small text-end">' + p.on_hand + '</td>' +
					'<td class="small text-end text-muted">' + p.on_order + '</td>' +
					'<td class="small text-end text-muted">' + p.demand_12mo + '</td>' +
					'<td class="small text-end text-muted">' + p.demand_6mo + '</td>' +
					'<td class="small text-end">' + p.bsl + '</td>' +
					'<td class="text-end"><input type="number" min="1" class="form-control form-control-sm raw-supply text-end" data-id="' + p.part_id + '" data-field="moq" value="' + (p.moq||1) + '" style="' + supStyle + '"></td>' +
					'<td class="text-end"><input type="number" min="1" class="form-control form-control-sm raw-supply text-end" data-id="' + p.part_id + '" data-field="lead" value="' + (p.lead_time_days||0) + '" style="' + supStyle + '"></td>' +
					'<td class="small text-end ' + toCls + '">' + (p.to_order > 0 ? p.to_order : '—') + '</td>' +
					'<td class="text-end"><input type="number" min="0" class="form-control form-control-sm raw-omit text-end" data-id="' + p.part_id + '" value="' + (p.omit||0) + '" style="' + supStyle + '"></td></tr>';
			});
			h += '</tbody></table></div></div></div>';
		});
		$('#rawAll').html(h);
		$('#rawAllWrap').show();
	}

	// Save an omit value, then refresh so BSL (and the order suggestions) recompute.
	$(document).on('change', '.raw-omit', function(){
		var $i = $(this).prop('disabled', true), id = $i.data('id'), amount = Math.max(0, parseInt($i.val(), 10) || 0);
		$.post('/ajax/change_omit.php', { record: id, amount: amount }, function(){ loadReadiness(true, false); })
			.fail(function(){ alert('Could not save omit.'); $i.prop('disabled', false); });
	});

	// Save a raw part's MOQ / lead time, then refresh so the order timing recomputes.
	$(document).on('change', '.raw-supply', function(){
		var $i = $(this).prop('disabled', true), id = $i.data('id'), field = $i.data('field'), value = Math.max(0, parseInt($i.val(), 10) || 0);
		$.post('/ajax/research/set_part_supply.php', { id: id, field: field, value: value }, function(res){
			if (res && res.ok) { loadReadiness(true, false); }
			else { alert((res && res.error) || 'Could not save.'); $i.prop('disabled', false); }
		}, 'json').fail(function(){ alert('Could not save.'); $i.prop('disabled', false); });
	});

	// ── Need to Order: per-line hide (chosen not to order) — persisted server-side ──
	var needHidden = <?php echo json_encode($needHidden ?: []); ?>;
	function needKey(it) { return it.type + '::' + it.name; }
	function isNeedHidden(it) { return needHidden.indexOf(needKey(it)) !== -1; }

	// ── Need to Order: split into "Order Now" and "Coming Up" (estimated purchase date) ──
	// Prior-year demand broken out by season: Pre (Jul–Sep) · In (Oct–Dec) · Post (Jan–Mar).
	function needSeasonBreakdown(bs) {
		if (!bs) return '';
		var pre = bs.jul_sep || 0, ins = bs.oct_dec || 0, post = bs.jan_mar || 0;
		return '<div class="text-muted" style="font-size:0.64rem;" title="Preseason Jul–Sep · In-season Oct–Dec · Postseason Jan–Mar">'
			+ pre + ' · ' + ins + ' · ' + post + '</div>';
	}
	function needRow(it, isUp) {
		var typeBadge = it.type === 'raw'
			? '<span class="badge bg-light text-dark border" style="font-size:0.56rem;">RAW</span>'
			: '<span class="badge" style="background:#fde8e8;color:#b42318;font-size:0.56rem;">FINISHED</span>';
		var moqNote = (it.type === 'finished' && !it.moq_known)
			? ' <span class="warn-pill" title="No MOQ set for this group — set it on the Parts Breakdown tab">MOQ?</span>' : '';
		var dateCls = isUp ? (it.urgency === 'soon' ? 'fw-semibold' : 'text-muted') : 'stat-neg fw-bold';
		var whenCell = isUp
			? '<td class="text-center">' + (it.urgency === 'soon' ? '<span class="badge bg-warning text-dark">Soon</span>' : '<span class="badge bg-light text-dark border">Later</span>') + '</td>'
			: '';
		var rowStyle = isUp ? '' : ' style="background:#fff5f5;"';
		var hideBox = '<input type="checkbox" class="need-hide" data-key="' + esc(needKey(it)) + '"' + (isNeedHidden(it) ? ' checked' : '') + ' title="Hide this line — restore it from Hidden below" style="margin-right:6px;vertical-align:middle;">';
		return '<tr' + rowStyle + '><td class="fw-semibold">' + hideBox + typeBadge + ' ' + esc(it.name) +
				' <span class="text-muted small">' + esc(it.description || '') + '</span>' + moqNote + '</td>' +
			'<td class="small text-muted">' + esc(it.supplier || '—') + '</td>' +
			'<td class="text-center text-muted">' + it.have + '</td>' +
			'<td class="text-center"><div class="fw-semibold">' + it.need + '</div>' + needSeasonBreakdown(it.by_season) + '</td>' +
			'<td class="text-center stat-neg fw-bold">' + (it.short != null ? it.short : '—') + '</td>' +
			'<td class="text-center stat-neg fw-bold">' + it.order_qty + ' <span class="text-muted fw-normal" style="font-size:0.68rem;">(MOQ ' + it.moq + ')</span></td>' +
			'<td class="text-center small ' + dateCls + '">' + (it.by_date || '—') + '</td>' +
			'<td class="text-center small">' + it.lead_time_days + 'd</td>' +
			'<td class="text-end fw-semibold">$' + (it.cost || 0).toFixed(2) + '</td>' + whenCell + '</tr>';
	}
	var NEED_CAT_ORDER = ['Camshafts', 'Rods', 'Plates', 'Packaging Cards', 'Packaging', 'WINGZ', 'Bags & Cases', 'Batteries', 'Hats', 'Accessories', 'Other'];
	function needTable(list, isUp) {
		var cols = isUp ? 10 : 9;
		// Group like products together (raw category or finished-goods group), keep each
		// group's rows in the incoming (soonest-first) order.
		var groups = {};
		list.forEach(function(it){ var c = it.category || 'Other'; (groups[c] = groups[c] || []).push(it); });
		var cats = Object.keys(groups).sort(function(a, b){
			var ia = NEED_CAT_ORDER.indexOf(a), ib = NEED_CAT_ORDER.indexOf(b);
			if (ia < 0) ia = 999; if (ib < 0) ib = 999;
			return ia - ib || (a < b ? -1 : 1);
		});
		var h = '<div class="scroll-table"><table class="table dash-table align-middle"><thead><tr>' +
			'<th>Item</th><th>Source</th><th class="text-center" title="Raw parts: on hand + already on order. Finished goods: physical on hand (committed not deducted).">On Hand<br><small class="text-muted fw-normal">raw: + on order</small></th><th class="text-center">Need<br><small class="text-muted fw-normal">season total · Pre·In·Post</small></th>' +
			'<th class="text-center">Short by</th>' +
			'<th class="text-center">Order (MOQ)</th><th class="text-center">' + (isUp ? 'Purchase by (est.)' : 'Order by') + '</th>' +
			'<th class="text-center">Lead</th><th class="text-end">Est. Cost</th>' + (isUp ? '<th class="text-center">When</th>' : '') + '</tr></thead><tbody>';
		cats.forEach(function(c){
			h += '<tr><td colspan="' + cols + '" class="fw-bold small text-uppercase" style="letter-spacing:.03em;background:#f1f3f5;">' + esc(c) + ' <span class="text-muted">(' + groups[c].length + ')</span></td></tr>';
			groups[c].forEach(function(it){ h += needRow(it, isUp); });
		});
		return h + '</tbody></table></div>';
	}
	function renderNeedToOrder(items, totalCost) {
		window._needItems = items; window._needCost = totalCost;   // kept so hide/unhide can re-render
		if (!items || !items.length) {
			$('#needToOrder').html('<div class="alert alert-success mb-0">Nothing to order — stock + on-order covers projected demand across all three seasons.</div>');
			$('#needSummary').text('');
			return;
		}
		var visible = items.filter(function(i){ return !isNeedHidden(i); });
		var hiddenItems = items.filter(isNeedHidden);
		var nowItems = visible.filter(function(i){ return i.urgency === 'now'; });
		var upItems  = visible.filter(function(i){ return i.urgency !== 'now'; });   // already sorted soonest-first
		var sumCost = function(l){ return l.reduce(function(s, i){ return s + (i.cost || 0); }, 0); };
		$('#needSummary').html('<strong class="' + (nowItems.length ? 'text-danger' : '') + '">' + nowItems.length + ' to order now</strong> · ' +
			upItems.length + ' coming up · Est. $' + sumCost(visible).toFixed(2) +
			(hiddenItems.length ? ' · <span class="text-muted">' + hiddenItems.length + ' hidden</span>' : ''));

		var h = '';
		// Order Now
		if (nowItems.length) {
			h += '<div class="mb-3">' +
				'<div class="d-flex align-items-center gap-2 mb-1"><span class="fw-bold" style="color:#b42318;"><i class="ti ti-alert-triangle me-1"></i>Order Now</span>' +
				'<span class="muted-pill">' + nowItems.length + ' item' + (nowItems.length !== 1 ? 's' : '') + ' · Est. $' + sumCost(nowItems).toFixed(2) + '</span></div>' +
				'<div class="small text-muted mb-2">Their order-by date has already arrived — place these today so they land before you run short.</div>' +
				needTable(nowItems, false) + '</div>';
		} else {
			h += '<div class="alert alert-success py-2 mb-3"><i class="ti ti-circle-check me-1"></i>Nothing to order right now — everything below still has runway.</div>';
		}
		// Coming Up (with estimated purchase date)
		if (upItems.length) {
			h += '<div class="mt-2">' +
				'<div class="d-flex align-items-center gap-2 mb-1"><span class="fw-bold" style="color:#4680ff;"><i class="ti ti-calendar-clock me-1"></i>Coming Up</span>' +
				'<span class="muted-pill">' + upItems.length + ' item' + (upItems.length !== 1 ? 's' : '') + ' · Est. $' + sumCost(upItems).toFixed(2) + '</span></div>' +
				'<div class="small text-muted mb-2">Not urgent yet. <strong>Purchase by (est.)</strong> is the date to place each order so it arrives in time (run-short date minus lead time).</div>' +
				needTable(upItems, true) + '</div>';
		}
		if (!nowItems.length && !upItems.length && hiddenItems.length) {
			h += '<div class="alert alert-info py-2 mb-2">Every item to order is hidden. Restore some from the Hidden list below.</div>';
		}
		// Hidden — items you've chosen not to order (restore by unchecking)
		if (hiddenItems.length) {
			h += '<details class="mt-3"><summary class="btn btn-sm btn-light-secondary">▸ Hidden — not ordering (' + hiddenItems.length + ')</summary>' +
				'<div class="small text-muted mt-2 mb-1">Items you\'ve chosen not to order. <strong>Uncheck</strong> one to bring it back into the list above.</div>' +
				needTable(hiddenItems, false) + '</details>';
		}
		$('#needToOrder').html(h);
	}

	// Hide / restore a Need-to-Order line; persists and re-renders from the stored data.
	$(document).on('change', '.need-hide', function(){
		var key = $(this).data('key'), hide = $(this).is(':checked');
		if (hide) { if (needHidden.indexOf(key) === -1) needHidden.push(key); }
		else { needHidden = needHidden.filter(function(k){ return k !== key; }); }
		$.post('/ajax/research/toggle_need_hidden.php', { key: key, hidden: hide ? 1 : 0 });
		if (window._needItems) renderNeedToOrder(window._needItems, window._needCost);
	});

	// ── Finished-goods supply group editor (MOQ / lead / unit cost) ──
	function renderFgGroups(groups) {
		if (!groups || !groups.length) { $('#fgGroups').html('<div class="text-muted small">No finished-goods groups found (they appear once Shopify prior-year sales load).</div>'); return; }
		var h = '<div class="scroll-table"><table class="table dash-table align-middle"><thead><tr>' +
			'<th>Group</th><th class="text-center">Items</th><th class="text-center" style="width:110px;">MOQ</th>' +
			'<th class="text-center" style="width:130px;">Lead (days)</th><th class="text-center" style="width:140px;">Unit cost</th>' +
			'<th class="text-center" style="width:90px;"></th></tr></thead><tbody>';
		groups.forEach(function(g){
			var flag = g.set ? '' : ' <span class="warn-pill" style="font-size:0.56rem;">not set</span>';
			h += '<tr data-group="' + esc(g.group) + '"><td class="fw-semibold">' + esc(g.group) + flag + '</td>' +
				'<td class="text-center text-muted">' + g.count + '</td>' +
				'<td><input type="number" min="1" class="form-control form-control-sm fg-moq text-end" value="' + (g.moq || '') + '" placeholder="1"></td>' +
				'<td><input type="number" min="1" class="form-control form-control-sm fg-lead text-end" value="' + (g.lead_days || '') + '" placeholder="90"></td>' +
				'<td><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="number" min="0" step="0.01" class="form-control fg-cost text-end" value="' + (g.unit_cost || '') + '" placeholder="0.00"></div></td>' +
				'<td class="text-center"><button class="btn btn-sm btn-primary fg-save">Save</button> <span class="fg-msg small"></span></td></tr>';
		});
		h += '</tbody></table></div>';
		$('#fgGroups').html(h);
	}

	// Save a finished-goods group's supply terms, then refresh so timing recomputes.
	$(document).on('click', '.fg-save', function(){
		var $row = $(this).closest('tr'), $btn = $(this).prop('disabled', true);
		var grp = $row.data('group');
		var moq = Math.max(0, parseInt($row.find('.fg-moq').val(), 10) || 0);
		var lead = Math.max(0, parseInt($row.find('.fg-lead').val(), 10) || 0);
		var cost = Math.max(0, parseFloat($row.find('.fg-cost').val()) || 0);
		$row.find('.fg-msg').removeClass('text-danger text-success').text('Saving…');
		$.post('/ajax/research/set_fg_supply.php', { group: grp, moq: moq, lead_days: lead, unit_cost: cost }, function(res){
			if (res && res.ok) { loadReadiness(true, false); }
			else { $row.find('.fg-msg').addClass('text-danger').text((res && res.error) || 'Failed'); $btn.prop('disabled', false); }
		}, 'json').fail(function(){ $row.find('.fg-msg').addClass('text-danger').text('Failed'); $btn.prop('disabled', false); });
	});

	// "Need to Order" quick-link from the Parts Breakdown tab.
	$(document).on('click', '.goto-need', function(e){ e.preventDefault(); var t = document.getElementById('tab-need-btn'); if (t) new bootstrap.Tab(t).show(); });

	// ── Include tradeshows in demand ──
	$('#demandShowsToggle').on('click', function(){ $('#demandShowsPanel').slideToggle(150); });
	$('#demandShowsSave').on('click', function(){
		var excluded = [];
		$('.demand-show').each(function(){ if (!$(this).is(':checked')) excluded.push($(this).val()); });
		var $b = $(this).prop('disabled', true);
		$('#demandShowsMsg').removeClass('text-danger text-success').text('Saving…');
		$.post('/ajax/research/set_demand_shows.php', { excluded: JSON.stringify(excluded) }, function(res){
			if (res && res.ok) { $('#demandShowsMsg').addClass('text-success').text('Saved — recomputing demand…'); loadReadiness(true, false); }
			else { $('#demandShowsMsg').addClass('text-danger').text((res && res.error) || 'Save failed'); $b.prop('disabled', false); }
		}, 'json').fail(function(){ $('#demandShowsMsg').addClass('text-danger').text('Save failed'); $b.prop('disabled', false); });
	});
	$('#demandShowsImpact').on('click', function(e){
		e.preventDefault();
		var $l = $(this).text('Loading last-year units… (can take a moment)');
		$.getJSON('/ajax/research/show_demand.php', function(res){
			if (res && res.shows) {
				$('.show-units').each(function(){
					var nm = $(this).data('name');
					var m = res.shows.filter(function(s){ return s.name === nm; })[0];
					if (m) $(this).text('· ' + m.units + ' last yr');
				});
				$l.html('<i class="ti ti-eye me-1"></i>Units loaded');
			} else { $l.text((res && res.error) || 'Failed to load'); }
		}).fail(function(){ $l.text('Failed to load'); });
	});

	// Remember the active tab across refreshes; resize the chart when its pane appears.
	$('#researchTabs button[data-bs-toggle="tab"]').on('shown.bs.tab', function(e){
		try { localStorage.setItem('bbw_research_tab', e.target.id); } catch(_){}
		if (e.target.id === 'tab-season-btn' && seasonChartObj) { try { seasonChartObj.resize(); } catch(_){} }
	});
	(function(){ try { var t = localStorage.getItem('bbw_research_tab'); if (t && document.getElementById(t)) new bootstrap.Tab(document.getElementById(t)).show(); } catch(_){} })();

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
							var cbCls = 'canbuild-col' + (window._showCanBuild ? '' : ' d-none');
							cards += '<table class="table table-sm mb-2"><thead><tr><th class="small">SKU</th>' +
								'<th class="small text-end" title="Physical units in stock. Units committed to open orders are NOT deducted — the demand column is the full season and already includes those sales, so netting them out here would double-count.">On Hand<br><small class="text-muted fw-normal">incl. committed</small></th>' +
								'<th class="small text-end" title="Units sold last year in this same quarter. Expand the row to see exactly where it came from.">Demand</th>' +
								'<th class="small text-end">Need to Build</th><th class="small text-end ' + cbCls + '">Can build</th></tr></thead><tbody>';
							q.animator_items.forEach(function(it, ai){
								var rid = 'ai-' + si + '-' + ai; cards += '<tr class="ai-row" data-target="' + rid + '" style="cursor:pointer;"><td class="small"><i class="ti ti-chevron-right ai-chev"></i> <code>' + esc(it.sku) + '</code>' + (it.has_amazon ? ' <span class="badge bg-light text-muted border" style="font-size:0.5rem;" title="Includes an Amazon portion — expand for the split">+AMZ</span>' : '') + '</td>' +
									'<td class="text-end small text-muted">' + (it.have||0) + '</td>' +
									'<td class="text-end small text-muted">' + (it.demand||0) + '</td>' +
										'<td class="text-end small ' + (it.to_build>0?'stat-neg':'stat-pos') + '">' + it.to_build + '</td>' +
									'<td class="text-end small ' + cbCls + '">' + (it.buildable==null?'-':it.buildable) + '</td></tr>' +
										'<tr id="' + rid + '" class="ai-detail" style="display:none;"><td colspan="5" class="p-0">' + aiExplain(it) + '</td></tr>';
							});
							cards += '</tbody></table>';
						} else { cards += '<div class="small text-muted mb-2">No animator demand.</div>'; }
					if (q.fg_items && q.fg_items.length) {
						cards += '<div class="small fw-semibold text-muted">Buy (cases / wings):</div><ul class="small mb-0" style="padding-left:1.1rem;">';
						q.fg_items.forEach(function(it){
							cards += '<li><strong>' + it.order + '</strong> × <code>' + esc(it.sku) + '</code> ' +
								'<span class="text-muted">(on hand ' + (it.have||0) + ', last-yr ' + (it.need||0) + ')</span></li>';
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

			// Consolidated "Need to Order" list (raw + finished, urgency-sorted)
			if (res.need_to_order) renderNeedToOrder(res.need_to_order, res.need_total_cost);

			// Full raw-material stock reference (merged from the old stock-order page)
			if (res.raw_all) renderRawAll(res.raw_all);

			// Finished-goods supply group editor (MOQ / lead / cost)
			if (res.fg_groups) renderFgGroups(res.fg_groups);

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

	// Show / hide the "Can build" column (hidden by default).
	$('#toggleCanBuild').on('change', function() {
		window._showCanBuild = $(this).is(':checked');
		$('.canbuild-col').toggleClass('d-none', !window._showCanBuild);
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
