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
<p class="text-muted mb-3" style="max-width:860px;">Charles is your AI CPA. He reads your QuickBooks, cash, cards &amp; line of credit, and the whole MRP — then tells you, in plain English, what to order when and how to fund it without running out of cash. He never moves money: his advice becomes tasks you approve and complete.</p>

<!-- ── KEY NUMBERS ──────────────────────────────────────────────────────────── -->
<div class="row g-2 mb-3">
	<?php
	$tiles = [
		['Cash in bank',   c_money0($snap['cash_in_bank']),   '#2ca01c', 'ti-building-bank'],
		['Card room left', c_money0($snap['card_available']), '#4680ff', 'ti-credit-card'],
		['LOC room left',  c_money0($snap['loc_available']),  '#6f42c1', 'ti-cash-banknote'],
		['Owed to you (A/R)', c_money0($snap['ar_total']),    '#12b886', 'ti-arrow-down-left'],
		['You owe (A/P)',  c_money0($snap['ap_total']),       '#e64545', 'ti-arrow-up-right'],
		['Net position',   c_money0($snap['net_position']),   ($snap['net_position']>=0?'#1e7e34':'#e64545'), 'ti-scale'],
	];
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

<!-- ── CHARLES'S BRIEFING (Phase 3) ─────────────────────────────────────────── -->
<div class="card mb-3"><div class="card-body">
	<div class="d-flex align-items-center justify-content-between mb-1">
		<h6 class="fw-bold mb-0"><i class="ti ti-report-analytics me-1"></i>Charles's briefing</h6>
	</div>
	<div class="text-muted small">The written analysis, plan, and chat are being wired in next. The numbers and charts below are live now.</div>
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
		<?php
		$allDebt = array_merge(
			array_map(function($c){ $c['kind']='card'; return $c; }, $snap['cards']),
			array_map(function($c){ $c['kind']='loc';  return $c; }, $snap['locs'])
		);
		if (empty($allDebt)): ?>
			<div class="text-muted small">No cards or line of credit on file. Add them (with APR &amp; limit) on the Cash Flow page so Charles can plan financing.</div>
		<?php else: foreach ($allDebt as $c):
			$lim = $c['limit']; $bal = $c['balance'];
			$pct = ($lim && $lim > 0) ? min(100, round($bal / $lim * 100)) : 0;
			$col = $c['kind']==='loc' ? '#6f42c1' : ($pct >= 80 ? '#e64545' : '#4680ff'); ?>
			<div class="mb-2">
				<div class="d-flex justify-content-between small">
					<span class="fw-semibold"><?php echo htmlspecialchars($c['label']); ?>
						<span class="badge <?php echo $c['kind']==='loc'?'bg-info text-dark':'bg-light text-dark'; ?>" style="font-size:0.54rem;"><?php echo $c['kind']==='loc'?'LOC':'CARD'; ?></span>
						<?php echo $c['apr']!==null ? '<span class="text-muted" style="font-size:0.66rem;">'.rtrim(rtrim(number_format($c['apr'],2),'0'),'.').'% APR</span>' : ''; ?>
					</span>
					<span><?php echo c_money0($bal); ?><?php echo $lim!==null ? ' <span class="text-muted">/ '.c_money0($lim).'</span>' : ''; ?></span>
				</div>
				<div style="height:7px;background:#eef1f5;border-radius:4px;overflow:hidden;"><div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div></div>
			</div>
		<?php endforeach; endif; ?>
	</div></div></div>
</div>

<!-- ── DATA GAPS ────────────────────────────────────────────────────────────── -->
<?php if (!empty($snap['data_gaps'])): ?>
<div class="card mb-3" style="border-left:4px solid #d9822b;"><div class="card-body py-2">
	<div class="fw-semibold small mb-1"><i class="ti ti-plug-connected-x me-1"></i>What Charles can't see yet</div>
	<ul class="mb-0 text-muted small" style="padding-left:1.1rem;"><?php foreach ($snap['data_gaps'] as $g): ?><li><?php echo htmlspecialchars($g); ?></li><?php endforeach; ?></ul>
</div></div>
<?php endif; ?>

<!-- ── TALK TO CHARLES (Phase 4) ────────────────────────────────────────────── -->
<div class="card"><div class="card-body">
	<h6 class="fw-bold mb-2"><i class="ti ti-message-2 me-1"></i>Talk to Charles</h6>
	<div class="text-muted small">The conversation (with permanent memory and one-click "add to my tasks") is coming in the next phase.</div>
</div></div>

<script>
var CH = <?php echo json_encode(['months' => $snap['months'], 'forecast' => $snap['forecast'], 'buffer' => $buffer], JSON_UNESCAPED_SLASHES); ?>;
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
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
