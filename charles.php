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
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
