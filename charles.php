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

<!-- ── DATA GAPS ────────────────────────────────────────────────────────────── -->
<?php if (!empty($snap['data_gaps'])): ?>
<div class="card mb-3" style="border-left:4px solid #d9822b;"><div class="card-body py-2">
	<div class="fw-semibold small mb-1"><i class="ti ti-plug-connected-x me-1"></i>What Charles can't see yet</div>
	<ul class="mb-0 text-muted small" style="padding-left:1.1rem;"><?php foreach ($snap['data_gaps'] as $g): ?><li><?php echo htmlspecialchars($g); ?></li><?php endforeach; ?></ul>
</div></div>
<?php endif; ?>

<!-- ── TALK TO CHARLES ──────────────────────────────────────────────────────── -->
<div class="card"><div class="card-body">
	<div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
		<h6 class="fw-bold mb-0"><i class="ti ti-message-2 me-1"></i>Talk to Charles</h6>
		<div class="d-flex gap-2 align-items-center">
			<select id="chHistory" class="form-select form-select-sm" style="width:170px;"><option value="">History…</option></select>
			<button id="chNew" class="btn btn-sm btn-light">+ New</button>
			<a href="#" id="chDelete" class="small text-danger hidden">delete</a>
		</div>
	</div>
	<div id="chMsgs" style="max-height:440px;overflow-y:auto;background:#f7f9fc;border:1px solid #e6e9f0;border-radius:8px;padding:10px;font-size:0.9rem;">
		<div class="text-muted small">Ask Charles anything — “Are we going to be okay this fall?”, “Should I pay down the highest card with the LOC?”, “What should I build next and how do I pay for it?”</div>
	</div>
	<div id="chActions" class="mt-2 hidden"></div>
	<div class="mt-2 d-flex gap-2">
		<input type="text" id="chInput" class="form-control" placeholder="Talk to Charles…">
		<button id="chSend" class="btn btn-primary">Send</button>
	</div>
</div></div>

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
	.done(function(d){ chPending=false; if(!d||d.error){ chMsgs.push({role:'assistant',content:'⚠ '+((d&&d.error)||'failed')}); chRender(); return; } chMsgs.push({role:'assistant',content:d.reply||'(no reply)'}); if(d.chat_id) chChatId=d.chat_id; chRender(); chLoadHistory(); if(d.tasks&&d.tasks.length) chShowTasks(d.tasks); })
	.fail(function(x,s){ chPending=false; chMsgs.push({role:'assistant',content:'⚠ '+(s==='timeout'?'timed out — try again':'request failed')}); chRender(); });
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
$(document).on('click','#chApply',function(){ var $b=$(this).prop('disabled',true).text('Adding…'); $.ajax({url:'/ajax/charles/apply.php',method:'POST',dataType:'json',data:{tasks:JSON.stringify(window._chTasks||[])}}).done(function(d){ if(d&&d.ok){ $('#chApplyMsg').removeClass('text-danger').text('Added '+d.created+' task(s) to your list ✓'); $('#chApply,#chCancelTasks').prop('disabled',true); } else { $('#chApplyMsg').addClass('text-danger').text((d&&d.error)||'failed'); $b.prop('disabled',false).text('Add to my tasks'); } }).fail(function(){ $('#chApplyMsg').addClass('text-danger').text('failed'); $b.prop('disabled',false).text('Add to my tasks'); }); });
$(document).on('click','#chCancelTasks',function(){ $('#chActions').addClass('hidden').html(''); window._chTasks=null; });

function chLoadHistory(){ $.getJSON('/ajax/charles/chat_list.php', function(d){ var o='<option value="">History…</option>'; (d.chats||[]).forEach(function(c){ o+='<option value="'+c.id+'"'+(c.id==chChatId?' selected':'')+'>'+chEsc(c.title)+'</option>'; }); $('#chHistory').html(o); }); }
$('#chHistory').on('change', function(){ var id=parseInt($(this).val(),10); if(!id) return; $.post('/ajax/charles/chat_get.php',{id:id},function(d){ if(d.error){ alert(d.error); return; } chChatId=d.id; chMsgs=d.messages||[]; $('#chActions').addClass('hidden').html(''); chRender(); },'json'); });
$('#chNew').on('click', function(){ chChatId=0; chMsgs=[]; $('#chHistory').val(''); $('#chActions').addClass('hidden').html(''); chRender(); $('#chInput').focus(); });
$('#chDelete').on('click', function(e){ e.preventDefault(); if(!chChatId||!confirm('Delete this chat?')) return; $.post('/ajax/charles/chat_delete.php',{id:chChatId},function(){ chChatId=0; chMsgs=[]; chRender(); chLoadHistory(); }); });
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
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
