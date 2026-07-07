<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();

	// "Talk to Charles" — the AI CPA. OWNER ONLY (George). Not other admins/masters.
	if (!is_owner()) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}

	require_once(__DIR__."/includes/charles.php");
	require_once(__DIR__."/includes/header.php");

	$db   = db_connect();
	$snap = charles_snapshot($db);

	// Charles's suggested tasks (his advice that became tasks assigned to George).
	$charlesTasks = [];
	try { tasks_ensure_table($db); foreach ($db->query("SELECT id, title, notes, due_date, completed, completed_at FROM tasks WHERE task_type = 'charles' ORDER BY completed ASC, (due_date IS NULL), due_date ASC, id DESC LIMIT 40") as $t) $charlesTasks[] = $t; } catch (Throwable $e) {}

	function c_money0($n) { return '$' . number_format((float)$n, 0); }
	$buffer  = (float)($snap['settings']['cash_buffer'] ?? 0);
	$runway  = $snap['runway_months'];
	$low     = $snap['low_point'];
?>

<div class="d-flex align-items-center justify-content-between mb-1 flex-wrap gap-2">
	<h2 class="fw-bold mb-0"><i class="ti ti-user-dollar me-1" style="color:#2ca01c;"></i>Talk to Charles</h2>
	<div class="text-muted small">
		<?php echo $snap['qb_connected'] ? '<span class="badge" style="background:#e6f4ea;color:#1e7e34;">QuickBooks connected</span>' : '<span class="badge bg-warning text-dark">QuickBooks not connected</span>'; ?>
		<?php if (!empty($snap['synced_at'])): ?><span class="ms-2">Data as of <?php echo htmlspecialchars(date('M j, g:ia', strtotime($snap['synced_at']))); ?></span><?php endif; ?>
	</div>
</div>
<!-- ── TABS ─────────────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="charlesTabs">
	<li class="nav-item"><a class="nav-link active" href="#" data-tab="talk"><i class="ti ti-microphone me-1"></i>Talk to Charles</a></li>
	<li class="nav-item"><a class="nav-link" href="#" data-tab="reports"><i class="ti ti-report-analytics me-1"></i>Reports from Charles</a></li>
</ul>

<!-- ══ REPORTS TAB ═══════════════════════════════════════════════════════════ -->
<div id="tab-reports" class="charles-tab" style="display:none;">
<p class="text-muted mb-3" style="max-width:860px;">Everything Charles is looking at — the numbers, charts, his written briefing, tradeshow ROI, and the tasks his advice has turned into. He never moves money: his advice becomes tasks you approve and complete.</p>

<!-- ── KEY NUMBERS ──────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
	<?php
	$tiles = [
		['Cash in bank',   c_money0($snap['cash_in_bank']),   '#2ca01c', 'ti-building-bank'],
		['Card room left', c_money0($snap['card_available']), '#4680ff', 'ti-credit-card'],
		['LOC room left',  c_money0($snap['loc_available']),  '#6f42c1', 'ti-cash-banknote'],
		['Owed to you (A/R)', c_money0($snap['ar_total']),    '#12b886', 'ti-arrow-down-left'],
		['Owe suppliers (A/P)', c_money0($snap['ap_total']),  '#e64545', 'ti-arrow-up-right'],
		['Net position',   c_money0($snap['net_position']),   ($snap['net_position']>=0?'#1e7e34':'#e64545'), 'ti-scale'],
	];
	if (!empty($snap['upcoming_card_charges'])) $tiles[] = ['Planned card charges', c_money0($snap['upcoming_card_charges']), '#3ea5c9', 'ti-credit-card'];
	foreach ($tiles as $t): ?>
	<div class="col-6 col-md-4 col-xl-2">
		<div class="card h-100 mb-0"><div class="card-body py-2 px-3">
			<div class="text-muted" style="font-size:0.68rem;text-transform:uppercase;letter-spacing:.03em;"><i class="ti <?php echo $t[3]; ?> me-1"></i><?php echo $t[0]; ?></div>
			<div class="fw-bold" style="font-size:1.15rem;color:<?php echo $t[2]; ?>;"><?php echo $t[1]; ?></div>
		</div></div>
	</div>
	<?php endforeach; ?>
</div>

<!-- Runway headline -->
<div class="card mb-3" style="border-left:4px solid <?php echo ($runway===null?'#2ca01c':($runway<=2?'#e64545':'#d9822b')); ?>;">
	<div class="card-body py-2">
		<?php if ($runway === null): ?>
			<span class="fw-semibold" style="color:#1e7e34;">✓ Cash runway looks safe.</span> Your bank balance is projected to stay above your <?php echo c_money0($buffer); ?> safety buffer for the next 12 months<?php echo $low ? ' (lowest point ' . c_money0($low['end_cash']) . ' in ' . htmlspecialchars($low['label']) . ')' : ''; ?>.
		<?php else: ?>
			<span class="fw-semibold" style="color:<?php echo $runway<=2?'#e64545':'#d9822b'; ?>;">⚠ Heads up:</span> at the current plan your bank dips below the <?php echo c_money0($buffer); ?> buffer in about <strong><?php echo (int)$runway; ?> month<?php echo $runway==1?'':'s'; ?></strong><?php echo $low ? ', bottoming near ' . c_money0($low['end_cash']) . ' in ' . htmlspecialchars($low['label']) : ''; ?>. Ask Charles below what to do about it.
		<?php endif; ?>
	</div>
</div>

<!-- ── CHARLES'S BRIEFING ───────────────────────────────────────────────────── -->
<div class="card mb-3" style="border-top:3px solid #2ca01c;"><div class="card-body">
	<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
		<h6 class="fw-bold mb-0"><i class="ti ti-report-analytics me-1"></i>Charles's briefing</h6>
		<div class="d-flex align-items-center gap-2"><span id="chBriefAsOf" class="text-muted small"></span><button id="chBriefRefresh" class="btn btn-sm btn-light-primary"><i class="ti ti-refresh me-1"></i>Re-analyze</button></div>
	</div>
	<div id="chBrief" class="charles-brief" style="font-size:0.9rem;line-height:1.5;"><div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Charles is reading your numbers…</div></div>
</div></div>

<!-- ── CHARTS ───────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
	<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">
		<h6 class="fw-bold mb-2">Cash runway (projected bank balance)</h6>
		<canvas id="chartRunway" height="150"></canvas>
	</div></div></div>
	<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">
		<h6 class="fw-bold mb-2">Debt payoff (projected total owed)</h6>
		<canvas id="chartDebt" height="150"></canvas>
	</div></div></div>
	<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">
		<h6 class="fw-bold mb-2">Money in vs. money out (per month)</h6>
		<canvas id="chartInOut" height="150"></canvas>
	</div></div></div>
	<div class="col-12 col-lg-6"><div class="card h-100"><div class="card-body">
		<h6 class="fw-bold mb-2">Cards &amp; line of credit</h6>
		<?php if (empty($snap['cards']) && empty($snap['locs'])): ?>
			<div class="text-muted small">No cards or line of credit on file. Add them (with APR &amp; limit) on the Cash Flow page so Charles can plan financing.</div>
		<?php else: ?>
			<?php foreach ($snap['cards'] as $c):
				$lim = $c['limit']; $bal = $c['balance'];
				$pct = ($lim && $lim > 0) ? min(100, round($bal / $lim * 100)) : 0;
				$col = $pct >= 80 ? '#e64545' : '#4680ff'; ?>
				<div class="mb-2">
					<div class="d-flex justify-content-between small">
						<span class="fw-semibold"><?php echo htmlspecialchars($c['label']); ?> <span class="badge bg-light text-dark" style="font-size:0.54rem;">CARD</span> <?php echo $c['apr']!==null ? '<span class="text-muted" style="font-size:0.66rem;">'.rtrim(rtrim(number_format($c['apr'],2),'0'),'.').'% APR</span>' : ''; ?></span>
						<span><?php echo c_money0($bal); ?><?php echo $lim!==null ? ' <span class="text-muted">/ '.c_money0($lim).'</span>' : ''; ?></span>
					</div>
					<div style="height:7px;background:#eef1f5;border-radius:4px;overflow:hidden;"><div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div></div>
				</div>
			<?php endforeach; ?>

			<?php if (!empty($snap['locs']) || !empty($snap['loc_limit'])):
				$loclim = (float)($snap['loc_limit'] ?? 0); $locbal = (float)($snap['loc_debt'] ?? 0);
				$lpct = ($loclim > 0) ? min(100, round($locbal / $loclim * 100)) : 0; ?>
				<div class="mt-3 d-flex justify-content-between small">
					<span class="fw-semibold">Line of Credit <span class="badge bg-info text-dark" style="font-size:0.54rem;">LOC</span></span>
					<span><?php echo c_money0($locbal); ?><?php echo $loclim > 0 ? ' <span class="text-muted">/ '.c_money0($loclim).'</span>' : ''; ?></span>
				</div>
				<div style="height:7px;background:#eef1f5;border-radius:4px;overflow:hidden;"><div style="height:100%;width:<?php echo $lpct; ?>%;background:#6f42c1;"></div></div>
				<div class="text-muted mt-1" style="font-size:0.68rem;"><strong><?php echo c_money0($snap['loc_available'] ?? 0); ?></strong> available to draw · <?php echo c_money0($snap['loc_monthly_payment'] ?? 0); ?>/mo in loan payments (cash out)</div>
				<?php foreach ($snap['locs'] as $l): ?>
					<div class="d-flex justify-content-between small mt-1" style="padding-left:0.5rem;border-left:2px solid #e6e0f5;">
						<span><?php echo htmlspecialchars($l['label']); ?><?php echo !empty($l['note']) ? '<br><span class="text-muted" style="font-size:0.63rem;">'.htmlspecialchars($l['note']).'</span>' : ''; ?></span>
						<span class="text-end"><?php echo c_money0($l['balance']); ?><?php echo !empty($l['monthly_payment']) ? '<br><span class="text-muted" style="font-size:0.63rem;">'.c_money0($l['monthly_payment']).'/mo</span>' : ''; ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php endif; ?>
	</div></div></div>
</div>

<!-- ── EXPENSES YEAR OVER YEAR ──────────────────────────────────────────────── -->
<?php if (!empty($snap['expense_yoy']['categories'])): ?>
<div class="card mb-3"><div class="card-body">
	<h6 class="fw-bold mb-2">Expenses — year over year <span class="text-muted small">(top categories, from QuickBooks)</span></h6>
	<canvas id="chartExpYoY" height="130"></canvas>
</div></div>
<?php elseif (!empty($snap['qb_connected'])): ?>
<div class="card mb-3"><div class="card-body py-2 text-muted small">Your full P&amp;L / expense history loads when Charles analyzes — hit <strong>Re-analyze</strong> above to pull it from QuickBooks (refreshes weekly).</div></div>
<?php endif; ?>

<!-- ── TRADESHOW ROI ────────────────────────────────────────────────────────── -->
<?php $roi = $snap['tradeshow_roi'] ?? []; $roiRows = $roi['rows'] ?? []; ?>
<?php if (!empty($roiRows)): ?>
<div class="card mb-3"><div class="card-body">
	<h6 class="fw-bold mb-1">Tradeshow ROI <span class="text-muted small">— last season's floor sales vs. cost (from QuickBooks)</span></h6>
	<?php if (!empty($roi['overall_roi']) || !empty($roi['qb_expense_total'])):
		$orv = $roi['overall_roi']; $ocol = $orv===null ? '#adb5bd' : ($orv < 1 ? '#e64545' : ($orv < 1.5 ? '#d9822b' : '#2ca01c')); ?>
	<div class="mb-2 p-2 rounded" style="background:#f6f8fc;border:1px solid #e6e9f0;font-size:0.9rem;">
		Overall: <strong><?php echo c_money0($roi['overall_revenue'] ?? 0); ?></strong> in show sales vs <strong><?php echo c_money0($roi['qb_expense_total'] ?? 0); ?></strong> tradeshow expense<?php echo !empty($roi['qb_expense_year']) ? ' (' . htmlspecialchars($roi['qb_expense_year']) . ')' : ''; ?> =
		<span class="fw-bold" style="color:<?php echo $ocol; ?>;"><?php echo $orv===null ? '—' : number_format($orv, 2) . '× ROI'; ?></span>
		<?php echo ($orv !== null && $orv < 1) ? ' <span class="badge bg-danger" style="font-size:0.55rem;">below break-even</span>' : ''; ?>
	</div>
	<?php endif; ?>
	<p class="text-muted small mb-2">Costs come from your <strong>QuickBooks</strong> tradeshow expense accounts. For per-show ROI, name the expense account after the show (e.g. "Tradeshow – Delta"); you can also type a cost to override. Under 1.0× means the show's floor sales didn't cover its cost — though it may still pay off in wholesale accounts and exposure.</p>
	<div class="row g-3">
		<div class="col-12 col-lg-7">
			<div class="table-responsive"><table class="table table-sm align-middle mb-1" style="font-size:0.84rem;">
				<thead><tr><th>Show</th><th class="text-end">Revenue</th><th class="text-end">Your cost</th><th class="text-center">ROI</th></tr></thead>
				<tbody>
				<?php foreach ($roiRows as $r):
					$rv = $r['roi']; $col = $rv===null ? '#adb5bd' : ($rv < 1 ? '#e64545' : ($rv < 1.5 ? '#d9822b' : '#2ca01c')); ?>
					<tr>
						<td class="fw-semibold"><?php echo htmlspecialchars($r['show']); ?></td>
						<td class="text-end"><?php echo c_money0($r['revenue']); ?></td>
						<td class="text-end">
							<div class="input-group input-group-sm" style="max-width:120px;margin-left:auto;">
								<span class="input-group-text">$</span>
								<input type="number" class="form-control show-cost" data-show="<?php echo htmlspecialchars($r['show'], ENT_QUOTES); ?>" value="<?php echo $r['cost']!==null ? (int)$r['cost'] : ''; ?>" placeholder="cost">
							</div>
							<?php if (($r['cost_source'] ?? '') === 'quickbooks'): ?><div class="text-muted" style="font-size:0.6rem;">from QuickBooks</div><?php elseif (($r['cost_source'] ?? '') === 'manual'): ?><div class="text-muted" style="font-size:0.6rem;">manual override</div><?php endif; ?>
						</td>
						<td class="text-center"><?php echo $rv===null ? '<span class="text-muted">—</span>' : '<span class="fw-bold" style="color:'.$col.';">'.number_format($rv,2).'×</span>' . ($rv<1 ? ' <span class="badge bg-danger" style="font-size:0.5rem;vertical-align:middle;">under 1</span>' : ''); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<div class="text-muted" style="font-size:0.68rem;">Revenue = POS sales at the show over <?php echo htmlspecialchars($roi['window'] ?? ''); ?>.</div>
		</div>
		<div class="col-12 col-lg-5"><canvas id="chartRoi" height="220"></canvas></div>
	</div>
</div></div>
<?php elseif (isset($snap['tradeshow_roi'])): ?>
<div class="card mb-3"><div class="card-body py-2 text-muted small">Tradeshow ROI loads when Charles analyzes — hit <strong>Re-analyze</strong> above to pull last season's show sales.</div></div>
<?php endif; ?>

<!-- ── PRODUCT MARGINS ──────────────────────────────────────────────────────── -->
<?php if (!empty($snap['product_margins'])): ?>
<div class="card mb-3"><div class="card-body">
	<h6 class="fw-bold mb-1">Product margins <span class="text-muted small">— Shopify price − true build cost (BOM × part cost)</span></h6>
	<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:0.84rem;">
		<thead><tr><th>Product</th><th class="text-end">Build cost</th><th class="text-end">Price</th><th class="text-end">Margin</th><th class="text-end">%</th></tr></thead>
		<tbody>
		<?php foreach (array_slice($snap['product_margins'], 0, 30) as $m): $mc = $m['margin']===null ? '#adb5bd' : ($m['margin']<0 ? '#e64545' : '#2ca01c'); ?>
			<tr>
				<td class="fw-semibold"><?php echo htmlspecialchars($m['product']); ?><?php echo $m['sku'] ? ' <span class="text-muted small">· '.htmlspecialchars($m['sku']).'</span>' : ''; ?></td>
				<td class="text-end"><?php echo c_money0($m['build_cost']); ?></td>
				<td class="text-end"><?php echo $m['price']!==null ? c_money0($m['price']) : '<span class="text-muted">—</span>'; ?></td>
				<td class="text-end fw-semibold" style="color:<?php echo $mc; ?>;"><?php echo $m['margin']!==null ? c_money0($m['margin']) : '—'; ?></td>
				<td class="text-end"><?php echo $m['margin_pct']!==null ? $m['margin_pct'].'%' : '—'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php $noPrice = array_filter($snap['product_margins'], fn($m)=>$m['price']===null); if ($noPrice): ?><div class="text-muted small mt-1"><?php echo count($noPrice); ?> product(s) have a build cost but no matching Shopify price — check the SKU on the product.</div><?php endif; ?>
</div></div>
<?php endif; ?>

<!-- ── FINISHED-PRODUCT PURCHASES (China imports) ───────────────────────────── -->
<div class="card mb-3"><div class="card-body">
	<h6 class="fw-bold mb-1">Finished-product purchases <span class="text-muted small">— WINGZ, cases &amp; other imports (on cards)</span></h6>
	<p class="text-muted small mb-2">These are finished goods you buy directly from China — not built from raw materials, so Charles can't see them anywhere else. Log them here (item, cost, month, card) and he factors them into cash flow and card room.</p>
	<?php if (!empty($snap['fp_purchases'])): ?>
	<div class="table-responsive"><table class="table table-sm align-middle mb-2" style="font-size:0.84rem;">
		<thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Total</th><th>Month</th><th>Card</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($snap['fp_purchases'] as $p): ?>
			<tr>
				<td class="fw-semibold"><?php echo htmlspecialchars($p['item']); ?><?php echo $p['note'] ? ' <span class="text-muted small">— '.htmlspecialchars($p['note']).'</span>' : ''; ?></td>
				<td class="text-end"><?php echo $p['qty'] ? number_format($p['qty']) : '—'; ?></td>
				<td class="text-end fw-semibold"><?php echo c_money0($p['total']); ?></td>
				<td><?php echo htmlspecialchars(date('M Y', strtotime($p['order_ym'].'-01'))); ?></td>
				<td><?php echo htmlspecialchars($p['card_label'] ?: '—'); ?></td>
				<td class="text-end"><a href="#" class="fp-del text-danger" data-id="<?php echo $p['id']; ?>" title="Remove">×</a></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table></div>
	<?php endif; ?>
	<div class="row g-2 align-items-end" style="max-width:860px;">
		<div class="col-12 col-md-3"><input type="text" id="fpItem" class="form-control form-control-sm" placeholder="Item (e.g. FP WINGZ)"></div>
		<div class="col-6 col-md-2"><input type="number" id="fpQty" class="form-control form-control-sm" placeholder="Qty"></div>
		<div class="col-6 col-md-2"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="number" id="fpTotal" class="form-control" placeholder="Total cost"></div></div>
		<div class="col-6 col-md-2"><input type="month" id="fpMonth" class="form-control form-control-sm"></div>
		<div class="col-6 col-md-3"><input type="text" id="fpCard" class="form-control form-control-sm" list="fpCards" placeholder="Which card"><datalist id="fpCards"><?php foreach ($snap['cards'] as $c): ?><option value="<?php echo htmlspecialchars($c['label'], ENT_QUOTES); ?>"></option><?php endforeach; ?></datalist></div>
		<div class="col-12"><button id="fpAdd" class="btn btn-sm btn-primary">Add purchase</button> <span id="fpMsg" class="small ms-1"></span></div>
	</div>
</div></div>

<!-- ── DATA GAPS ────────────────────────────────────────────────────────────── -->
<?php if (!empty($snap['data_gaps'])): ?>
<div class="card mb-3" style="border-left:4px solid #d9822b;"><div class="card-body py-2">
	<div class="fw-semibold small mb-1"><i class="ti ti-plug-connected-x me-1"></i>What Charles can't see yet</div>
	<ul class="mb-0 text-muted small" style="padding-left:1.1rem;"><?php foreach ($snap['data_gaps'] as $g): ?><li><?php echo htmlspecialchars($g); ?></li><?php endforeach; ?></ul>
</div></div>
<?php endif; ?>

<!-- ── CHARLES'S SUGGESTED TASKS ────────────────────────────────────────────── -->
<?php if (!empty($charlesTasks)): ?>
<div class="card mb-3"><div class="card-body">
	<h6 class="fw-bold mb-2"><i class="ti ti-checklist me-1"></i>Charles's suggested tasks</h6>
	<?php foreach ($charlesTasks as $t): $done = !empty($t['completed']); ?>
		<div class="d-flex justify-content-between align-items-center py-1 small" style="border-bottom:1px solid #f1f3f5;<?php echo $done ? 'opacity:.55;' : ''; ?>">
			<span><?php echo $done ? '✓ ' : ''; ?><span<?php echo $done ? ' style="text-decoration:line-through;"' : ''; ?>><?php echo htmlspecialchars($t['title']); ?></span><?php echo !empty($t['notes']) ? ' <span class="text-muted">— '.htmlspecialchars($t['notes']).'</span>' : ''; ?></span>
			<span class="text-muted" style="font-size:0.7rem;white-space:nowrap;"><?php echo $done ? ('done '.date('M j', strtotime($t['completed_at']))) : (($t['due_date'] && $t['due_date'] !== '0000-00-00') ? ('by '.date('M j', strtotime($t['due_date']))) : 'open'); ?></span>
		</div>
	<?php endforeach; ?>
	<div class="text-muted mt-2" style="font-size:0.7rem;">Manage &amp; complete these on your <a href="/tasks.php">Task List</a> — completing one applies its money move to your books.</div>
</div></div>
<?php endif; ?>

</div><!-- ══ /REPORTS TAB ══ -->

<!-- ══ TALK TAB ══════════════════════════════════════════════════════════════ -->
<div id="tab-talk" class="charles-tab" style="display:flex;flex-direction:column;">
	<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
		<div class="d-flex gap-2 align-items-center">
			<button id="chCall" class="btn btn-sm btn-outline-success">📞 Live talk</button>
			<span id="chStatus" class="small text-muted"></span>
		</div>
		<div class="d-flex gap-2 align-items-center flex-wrap">
			<button id="chVoice" class="btn btn-sm btn-light" title="Have Charles speak his replies">🔈 Voice off</button>
			<select id="chVoiceSel" class="form-select form-select-sm" style="width:150px;" title="Charles's voice"></select>
			<select id="chHistory" class="form-select form-select-sm" style="width:130px;"><option value="">History…</option></select>
			<button id="chNew" class="btn btn-sm btn-light">+ New</button>
			<a href="#" id="chDelete" class="small text-danger hidden">delete</a>
		</div>
	</div>
	<div id="chMsgs" style="flex:1 1 auto;min-height:280px;max-height:68vh;overflow-y:auto;background:#f7f9fc;border:1px solid #e6e9f0;border-radius:12px;padding:14px;font-size:0.95rem;"></div>
	<div id="chActions" class="mt-2 hidden"></div>
	<div class="mt-2 d-flex gap-2 align-items-center">
		<button id="chMic" class="btn btn-outline-secondary btn-lg" title="Talk to Charles (microphone)"><i class="ti ti-microphone"></i></button>
		<input type="text" id="chInput" class="form-control form-control-lg" placeholder="Talk to Charles… or tap the mic and speak">
		<button id="chSend" class="btn btn-primary btn-lg">Send</button>
	</div>
	<div style="height:25vh;flex:0 0 auto;"></div><!-- keeps the input ~25% up from the bottom -->
</div>

<script>
var CH = <?php echo json_encode(['months' => $snap['months'], 'forecast' => $snap['forecast'], 'buffer' => $buffer, 'expense_yoy' => $snap['expense_yoy'], 'tradeshow_roi' => $snap['tradeshow_roi']], JSON_UNESCAPED_SLASHES); ?>;
(function(){
	if (typeof Chart === 'undefined') return;
	var usd = function(v){ return '$' + Number(v||0).toLocaleString(); };
	var money = { ticks: { callback: function(v){ return usd(v); } } };
	var fut = CH.months;
	var labels = fut.map(function(m){ return m.label.replace(/ 20/, " '"); });

	new Chart(document.getElementById('chartRunway'), {
		type: 'line',
		data: { labels: labels, datasets: [
			{ label: 'Bank balance', data: fut.map(function(m){ return m.end_cash; }), borderColor: '#2ca01c', backgroundColor: 'rgba(44,160,28,0.08)', fill: true, tension: 0.3, spanGaps: true },
			{ label: 'Safety buffer', data: fut.map(function(){ return CH.buffer; }), borderColor: '#e64545', borderDash: [5,4], pointRadius: 0, fill: false }
		]},
		options: { plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } }, scales: { y: money } }
	});

	new Chart(document.getElementById('chartDebt'), {
		type: 'line',
		data: { labels: labels, datasets: [
			{ label: 'Total owed', data: fut.map(function(m){ return m.end_debt; }), borderColor: '#6f42c1', backgroundColor: 'rgba(111,66,193,0.08)', fill: true, tension: 0.3 }
		]},
		options: { plugins: { legend: { display: false } }, scales: { y: money } }
	});

	var fc = CH.forecast;
	new Chart(document.getElementById('chartInOut'), {
		type: 'bar',
		data: { labels: fc.map(function(m){ return m.label.replace(/ 20/, " '"); }), datasets: [
			{ label: 'Money in', data: fc.map(function(m){ return m.income; }), backgroundColor: '#2ca01c' },
			{ label: 'Money out', data: fc.map(function(m){ return m.cash_out; }), backgroundColor: '#d9822b' }
		]},
		options: { plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } }, scales: { y: money } }
	});
})();

// ── light markdown for Charles's prose ──
function chMd(t){
	t = $('<div>').text(t||'').html();
	t = t.replace(/^\s*#{1,4}\s?(.*)$/gm, '<div class="fw-bold mt-2 mb-1" style="color:#1e4620;">$1</div>');
	t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
	t = t.replace(/^\s*[-•]\s+(.*)$/gm, '<div style="padding-left:1rem;text-indent:-0.65rem;">• $1</div>');
	t = t.replace(/\n{2,}/g, '<br><br>').replace(/\n/g, '<br>');
	return t;
}

// ── Charles's briefing ──
function chLoadBrief(refresh){
	if (refresh) $('#chBrief').html('<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Re-analyzing…</div>');
	$.post('/ajax/charles/brief.php', refresh ? {refresh:1} : {}, function(d){
		if (d && d.ok){ $('#chBrief').html(chMd(d.text)); try { $('#chBriefAsOf').text('as of ' + new Date((d.as_of||'').replace(' ','T')).toLocaleString() + (d.cached ? '' : ' · fresh')); } catch(_){ $('#chBriefAsOf').text(d.cached?'':'fresh'); } }
		else $('#chBrief').html('<div class="text-danger small">' + $('<div>').text((d&&d.error)||'Could not generate the briefing.').html() + '</div>');
	}, 'json').fail(function(){ $('#chBrief').html('<div class="text-danger small">Request failed — try Re-analyze.</div>'); });
}
$('#chBriefRefresh').on('click', function(){ chLoadBrief(true); });
chLoadBrief(false);

// ── Chat ──
var chMsgs=[], chChatId=0, chPending=false;
function chEsc(s){ return $('<div>').text(s==null?'':String(s)).html(); }
function chRender(){
	var h='';
	chMsgs.forEach(function(m){
		if(m.role==='user') h+='<div class="text-end mb-2"><span style="display:inline-block;background:#2ca01c;color:#fff;padding:5px 10px;border-radius:12px;max-width:85%;text-align:left;">'+chEsc(m.content)+'</span></div>';
		else h+='<div class="mb-2"><span style="display:inline-block;background:#fff;border:1px solid #e6e9f0;padding:6px 11px;border-radius:12px;max-width:93%;">'+chMd(m.content)+'</span></div>';
	});
	if(chPending) h+='<div class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Charles is thinking…</div>';
	var $m=$('#chMsgs').html(h||'<div class="text-muted small">Ask Charles anything.</div>');
	if($m[0]) $m.scrollTop($m[0].scrollHeight);
	$('#chDelete').toggleClass('hidden', chChatId<=0);
}
function chSend(){
	var t=$.trim($('#chInput').val()); if(!t||chPending) return;
	$('#chInput').val(''); chMsgs.push({role:'user',content:t}); $('#chActions').addClass('hidden').html(''); window._chTasks=null;
	chPending=true; chRender();
	$.ajax({url:'/ajax/charles/chat.php',method:'POST',dataType:'json',timeout:180000,data:{messages:JSON.stringify(chMsgs), chat_id:chChatId}})
	.done(function(d){ chPending=false; if(!d||d.error){ chMsgs.push({role:'assistant',content:'⚠ '+((d&&d.error)||'failed')}); chRender(); return; } chMsgs.push({role:'assistant',content:d.reply||'(no reply)'}); if(d.chat_id) chChatId=d.chat_id; if(typeof chSaveSession==='function') chSaveSession(); chRender(); chLoadHistory(); if(typeof chSpeak==='function') chSpeak((d.spoken&&d.spoken.trim())?d.spoken:(d.reply?'I put my answer up on the screen — take a look.':'')); if(d.tasks&&d.tasks.length) chShowTasks(d.tasks); })
	.fail(function(x,s){ chPending=false; chMsgs.push({role:'assistant',content:'⚠ '+(s==='timeout'?'timed out — try again':'request failed')}); chRender(); if(typeof chLiveHint==='function') chLiveHint(); });
}
$('#chSend').on('click', chSend);
$('#chInput').on('keypress', function(e){ if(e.which===13) chSend(); });

function chShowTasks(tasks){
	window._chTasks=tasks;
	var h='<div class="p-2 rounded" style="background:#f2fbf4;border:1px solid #bfe6c8;"><div class="fw-semibold small mb-1">📋 Charles suggests adding these to your tasks:</div>';
	tasks.forEach(function(t){ var acts=(t.actions||[]).length; var due=t.due?(' · by '+chEsc(t.due)):''; h+='<div class="mb-1"><strong>'+chEsc(t.title)+'</strong>'+due+(t.why?' <span class="text-muted">— '+chEsc(t.why)+'</span>':'')+(acts?' <span class="badge bg-light text-dark" style="font-size:0.6rem;">updates books when done</span>':'')+'</div>'; });
	h+='<div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-success" id="chApply">Add to my tasks</button><button class="btn btn-sm btn-secondary" id="chCancelTasks">Not now</button><span id="chApplyMsg" class="small ms-1"></span></div><div class="text-muted mt-1" style="font-size:0.68rem;">Nothing changes in your books until you complete the task (after you\'ve actually done it).</div></div>';
	$('#chActions').html(h).removeClass('hidden');
}
$(document).on('click','#chApply',function(){
	var $b=$(this).prop('disabled',true).text('Adding…');
	$.ajax({url:'/ajax/charles/apply.php',method:'POST',dataType:'json',timeout:30000,data:{tasks:JSON.stringify(window._chTasks||[])}})
	.done(function(d){
		if(d&&d.ok){ window._chTasks=null; $('#chActions').html('<div class="p-2 rounded" style="background:#f2fbf4;border:1px solid #bfe6c8;font-size:0.85rem;"><span class="text-success fw-semibold">✓ Added '+(d.created||0)+' task(s) to your list.</span> <span class="text-muted">Complete them — after you\'ve actually made the move — to update your books.</span> <a href="/tasks.php" class="ms-1">Open Task List</a></div>'); }
		else { $('#chApplyMsg').addClass('text-danger').text((d&&d.error)||'Could not add.'); $b.prop('disabled',false).text('Add to my tasks'); }
	})
	.fail(function(x,s){ $('#chApplyMsg').addClass('text-danger').text(s==='timeout'?'Timed out — it may still have added; check your Task List.':'Failed to add.'); $b.prop('disabled',false).text('Add to my tasks'); });
});
$(document).on('click','#chCancelTasks',function(){ $('#chActions').addClass('hidden').html(''); window._chTasks=null; });

function chLoadHistory(){ $.getJSON('/ajax/charles/chat_list.php', function(d){ var o='<option value="">History…</option>'; (d.chats||[]).forEach(function(c){ o+='<option value="'+c.id+'"'+(c.id==chChatId?' selected':'')+'>'+chEsc(c.title)+'</option>'; }); $('#chHistory').html(o); }); }
$('#chHistory').on('change', function(){ var id=parseInt($(this).val(),10); if(!id) return; $.post('/ajax/charles/chat_get.php',{id:id},function(d){ if(d.error){ alert(d.error); return; } chChatId=d.id; chMsgs=d.messages||[]; $('#chActions').addClass('hidden').html(''); chSaveSession(); chRender(); },'json'); });
$('#chNew').on('click', function(){ chChatId=0; chMsgs=[]; $('#chHistory').val(''); $('#chActions').addClass('hidden').html(''); chSaveSession(); window._chOpened=true; chRender(); $('#chInput').focus(); });
$('#chDelete').on('click', function(e){ e.preventDefault(); if(!chChatId||!confirm('Delete this chat?')) return; $.post('/ajax/charles/chat_delete.php',{id:chChatId},function(){ chChatId=0; chMsgs=[]; try{ localStorage.removeItem('charlesSession'); }catch(_){} chRender(); chLoadHistory(); }); });
chLoadHistory();

// ── Expenses year over year ──
(function(){
	var yoy = CH.expense_yoy;
	if (typeof Chart === 'undefined' || !yoy || !yoy.categories || !yoy.categories.length) return;
	var el = document.getElementById('chartExpYoY'); if (!el) return;
	var palette = ['#c7d2fe', '#8f7ae6', '#2ca01c'];
	var ds = (yoy.years || []).map(function(y, i){ return { label: String(y), data: yoy.categories.map(function(c){ return (c.by_year && c.by_year[y]) || 0; }), backgroundColor: palette[i % palette.length] }; });
	new Chart(el, {
		type: 'bar',
		data: { labels: yoy.categories.map(function(c){ return c.category; }), datasets: ds },
		options: { plugins: { legend: { labels: { boxWidth: 12, font: { size: 11 } } } }, scales: { y: { ticks: { callback: function(v){ return '$' + Number(v||0).toLocaleString(); } } }, x: { ticks: { font: { size: 10 } } } } }
	});
})();

// ── Tradeshow ROI chart + cost inputs ──
(function(){
	var roi = CH.tradeshow_roi;
	if (typeof Chart === 'undefined' || !roi || !roi.rows) return;
	var el = document.getElementById('chartRoi'); if (!el) return;
	var rows = roi.rows.filter(function(r){ return r.roi !== null && r.roi !== undefined; });
	if (!rows.length) { $(el).replaceWith('<div class="text-muted small">Enter show costs on the left and the ROI chart appears here.</div>'); return; }
	new Chart(el, {
		type: 'bar',
		data: { labels: rows.map(function(r){ return r.show; }), datasets: [{ label: 'ROI (×)', data: rows.map(function(r){ return r.roi; }), backgroundColor: rows.map(function(r){ return r.roi < 1 ? '#e64545' : (r.roi < 1.5 ? '#d9822b' : '#2ca01c'); }) }] },
		options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { title: { display: true, text: 'ROI (revenue per $ spent)' } } } }
	});
})();
$(document).on('change', '.show-cost', function(){
	var $i = $(this).prop('disabled', true);
	$.post('/ajax/charles/save_show_cost.php', { show: $i.data('show'), cost: $i.val() || 0 }, function(resp){
		if (resp && resp.ok) location.reload(); else { alert((resp && resp.error) || 'Save failed'); $i.prop('disabled', false); }
	}, 'json').fail(function(){ alert('Save failed'); $i.prop('disabled', false); });
});

// ── Finished-product purchases (China imports) ──
$('#fpAdd').on('click', function(){
	var $b = $(this).prop('disabled', true);
	$.post('/ajax/charles/save_fp_purchase.php', { item: $('#fpItem').val(), qty: $('#fpQty').val() || 0, total_cost: $('#fpTotal').val() || 0, order_ym: $('#fpMonth').val(), card_label: $('#fpCard').val() }, function(r){
		if (r && r.ok) location.reload(); else { $('#fpMsg').addClass('text-danger').text((r && r.error) || 'Save failed'); $b.prop('disabled', false); }
	}, 'json').fail(function(){ $('#fpMsg').addClass('text-danger').text('Save failed'); $b.prop('disabled', false); });
});
$(document).on('click', '.fp-del', function(e){ e.preventDefault(); if (!confirm('Remove this purchase?')) return; $.post('/ajax/charles/save_fp_purchase.php', { id: $(this).data('id'), delete: 1 }, function(r){ if (r && r.ok) location.reload(); }, 'json'); });

// ── Tabs ──
$('#charlesTabs a').on('click', function(e){
	e.preventDefault();
	var tab = $(this).data('tab');
	$('#charlesTabs a').removeClass('active'); $(this).addClass('active');
	$('.charles-tab').hide(); $('#tab-' + tab).css('display', tab === 'talk' ? 'flex' : 'block');
	if (tab === 'talk') { var m = document.getElementById('chMsgs'); if (m) m.scrollTop = m.scrollHeight; $('#chInput').focus(); }
	else { setTimeout(function(){ window.dispatchEvent(new Event('resize')); }, 60); }  // let Chart.js size to the now-visible tab
});

// ── Voice state ──
var chVoiceOn = localStorage.getItem('charlesVoice') === '1';
var chLive = false;                          // live-talk: voice replies + push-to-talk
var chVoices = [], chVoiceName = localStorage.getItem('charlesVoiceName') || '';
var chSR = window.SpeechRecognition || window.webkitSpeechRecognition;
var chRecog = null, chListening = false, chFinalText = '', chPttHeld = false;
function chStatus(s){ $('#chStatus').text(s || ''); }
function chLiveHint(){ if (chLive) chStatus('Hold Spacebar (or the mic) to talk'); }
function chIsTyping(e){ var el = e.target, tag = (el && el.tagName || '').toLowerCase(); return tag === 'input' || tag === 'textarea' || tag === 'select' || (el && el.isContentEditable); }
function chUpdVoiceBtn(){ $('#chVoice').html(chVoiceOn ? '🔊 Voice on' : '🔈 Voice off').toggleClass('btn-primary', chVoiceOn).toggleClass('btn-light', !chVoiceOn); }
chUpdVoiceBtn();

// Voice picker (from the device's speechSynthesis voices).
function chLoadVoices(){
	if (!window.speechSynthesis) { $('#chVoiceSel').hide(); return; }
	chVoices = speechSynthesis.getVoices() || [];
	var en = chVoices.filter(function(v){ return /^en/i.test(v.lang); });
	var list = en.length ? en : chVoices;
	if (!list.length) return;
	var o = '<option value="">Default voice</option>';
	list.forEach(function(v){ o += '<option value="' + v.name.replace(/"/g,'') + '"' + (v.name === chVoiceName ? ' selected' : '') + '>' + v.name + '</option>'; });
	$('#chVoiceSel').html(o);
}
if (window.speechSynthesis) { chLoadVoices(); speechSynthesis.onvoiceschanged = chLoadVoices; } else { $('#chVoiceSel').hide(); }
$('#chVoiceSel').on('change', function(){ chVoiceName = $(this).val(); localStorage.setItem('charlesVoiceName', chVoiceName); if (chVoiceOn) chSpeak('This is how I sound now.'); });

// Charles speaks — his short conversational line, not the written answer.
function chSpeak(text){
	if (!chVoiceOn || !window.speechSynthesis || !text) { chLiveHint(); return; }
	try {
		speechSynthesis.cancel();
		var clean = String(text).replace(/```[\s\S]*?```/g, '').replace(/[#*_`>]/g, '').replace(/\s+/g, ' ').trim();
		if (!clean) { chLiveHint(); return; }
		var u = new SpeechSynthesisUtterance(clean); u.rate = 1.03; u.pitch = 1;
		if (chVoiceName) { var vv = chVoices.filter(function(v){ return v.name === chVoiceName; })[0]; if (vv) u.voice = vv; }
		u.onstart = function(){ if (chLive) chStatus('Charles is talking… (hold Tab to jump in)'); };
		u.onend   = function(){ chLiveHint(); };
		u.onerror = function(){ chLiveHint(); };
		speechSynthesis.speak(u);
	} catch (e) { chLiveHint(); }
}
$('#chVoice').on('click', function(){ chVoiceOn = !chVoiceOn; localStorage.setItem('charlesVoice', chVoiceOn ? '1' : '0'); chUpdVoiceBtn(); if (!chVoiceOn && window.speechSynthesis) speechSynthesis.cancel(); if (chVoiceOn) chSpeak('Okay, I can talk now.'); });

// Mic / speech-to-text.
if (!chSR) { $('#chMic, #chCall').prop('disabled', true).attr('title', 'Voice needs Chrome or Edge.'); }
else {
	chRecog = new chSR(); chRecog.lang = 'en-US'; chRecog.interimResults = true; chRecog.maxAlternatives = 1;
	chRecog.onstart  = function(){ chListening = true; $('#chMic').removeClass('btn-outline-secondary').addClass('btn-danger'); chStatus('Listening…'); };
	chRecog.onresult = function(e){ var interim = '', fin = ''; for (var i = e.resultIndex; i < e.results.length; i++){ if (e.results[i].isFinal) fin += e.results[i][0].transcript; else interim += e.results[i][0].transcript; } if (fin) chFinalText += fin; $('#chInput').val((chFinalText + ' ' + interim).trim()); };
	chRecog.onerror  = function(){ chListening = false; $('#chMic').removeClass('btn-danger').addClass('btn-outline-secondary'); chLiveHint(); };
	chRecog.onend    = function(){ chListening = false; $('#chMic').removeClass('btn-danger').addClass('btn-outline-secondary'); var t = $.trim($('#chInput').val()); chFinalText = ''; if (t) chSend(); else chLiveHint(); };
}
function chListen(){ if (!chRecog || chListening || chPending) return; try { chFinalText = ''; $('#chInput').val(''); if (window.speechSynthesis) speechSynthesis.cancel(); chRecog.start(); } catch(_){} }
function chStopListen(){ if (chRecog && chListening) { try { chRecog.stop(); } catch(_){} } }

// Mic button: tap to dictate one message (when not in a live call).
$('#chMic').on('click', function(){ if (chLive) return; if (chListening) { chStopListen(); return; } chListen(); });

// Live talk: voice replies + push-to-talk (no always-on mic).
$('#chCall').on('click', function(){
	chLive = !chLive;
	$('#chCall').toggleClass('btn-success', chLive).toggleClass('btn-outline-success', !chLive).html(chLive ? '📞 End call' : '📞 Live talk');
	if (chLive) { if (!chVoiceOn) { chVoiceOn = true; localStorage.setItem('charlesVoice', '1'); chUpdVoiceBtn(); } chLiveHint(); }
	else { chStopListen(); if (window.speechSynthesis) speechSynthesis.cancel(); chStatus(''); }
});

// Push-to-talk: hold Tab (or press-and-hold the mic) to talk; release to send.
// Holding also INTERRUPTS Charles mid-sentence so you can jump in.
function chPttStart(){ if (!chLive || chListening || chPending) return; chPttHeld = true; if (window.speechSynthesis) speechSynthesis.cancel(); chListen(); }
function chPttStop(){ if (!chPttHeld) return; chPttHeld = false; chStopListen(); }   // onend auto-sends the transcript
$(document).on('keydown', function(e){ if (chLive && (e.key === ' ' || e.code === 'Space' || e.keyCode === 32) && !e.repeat && !chIsTyping(e)) { e.preventDefault(); chPttStart(); } });
$(document).on('keyup',   function(e){ if (chLive && (e.key === ' ' || e.code === 'Space' || e.keyCode === 32) && !chIsTyping(e)) { e.preventDefault(); chPttStop(); } });
$('#chMic').on('mousedown touchstart', function(e){ if (chLive) { e.preventDefault(); chPttStart(); } });
$(document).on('mouseup touchend', function(){ if (chLive) chPttStop(); });

// ── Session persistence ──
// One chat stays active per "day", where a day runs until ~2am (so a late night
// carries over), then resets to a fresh chat. It's resumed across page navigations
// so moving around the app doesn't restart your conversation. Charles's durable
// memory carries context into the new chat after the 2am reset.
function chSessionDate(){ var d = new Date(); if (d.getHours() < 2) d.setDate(d.getDate() - 1); return d.getFullYear() + '-' + ('0'+(d.getMonth()+1)).slice(-2) + '-' + ('0'+d.getDate()).slice(-2); }
function chSaveSession(){ try { localStorage.setItem('charlesSession', JSON.stringify({ date: chSessionDate(), chatId: chChatId })); } catch(_){} }
function chReadSession(){ try { var s = JSON.parse(localStorage.getItem('charlesSession') || 'null'); if (s && s.date === chSessionDate() && s.chatId > 0) return s; } catch(_){} return null; }
function chAutoOpen(){ if (window._chOpened) return; window._chOpened = true; if (chMsgs.length === 0 && chChatId === 0) { $('#chInput').val("Give me a brief update — the 1 or 2 most important things right now, then let's talk."); chSend(); } }
setTimeout(function(){
	var s = chReadSession();
	if (s) {   // resume today's chat
		chChatId = s.chatId; window._chOpened = true;
		$.post('/ajax/charles/chat_get.php', { id: chChatId }, function(d){
			if (d && !d.error && d.messages && d.messages.length) { chMsgs = d.messages; chRender(); }
			else { chChatId = 0; window._chOpened = false; chAutoOpen(); }
		}, 'json').fail(function(){ chChatId = 0; window._chOpened = false; chAutoOpen(); });
	} else {   // new day / after the 2am reset → fresh chat with a greeting
		chAutoOpen();
	}
}, 500);
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
