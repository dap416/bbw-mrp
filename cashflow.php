<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/cashflow.php");

	// Financial data — admin/master only.
	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}

	require_once(__DIR__."/includes/header.php");

	$db       = db_connect();
	$data     = build_cashflow_data($db);
	$growth   = isset($_GET['growth']) ? (float)$_GET['growth'] : 0.0;
	$forecast = build_cashflow_forecast($db, $data, 12, $growth);
	$recur    = load_recurring_expenses($db);
	$events   = load_cash_events($db);
	$monthData= build_month_blocks($db, $data, $forecast, $events);
	$blocks   = $monthData['blocks'];
	$thisMonth= build_this_month($db, $monthData, $data, $forecast);
	$loanPct  = $monthData['loan_pct'];
	$syncedAt = cf_synced_at($db);
	$hideBefore = (string) setting_get($db, 'cashflow_hide_before', '');
	$curYm = date('Y-m');
	if ($hideBefore !== '' && $hideBefore > $curYm) $hideBefore = $curYm; // never hide current/future

	function money($n)  { return '$' . number_format((float)$n, 2); }
	function money0($n) { return '$' . number_format((float)$n, 0); }
	function fdate($d)  { return ($d && $d !== '0000-00-00' && $d !== '0000-00-00 00:00:00') ? date('m/d/y', strtotime($d)) : '—'; }
	function bal_badge($a) {
		if (!empty($a['due'])) {
			$t = $a['days_old'] === null ? 'no date — update' : $a['days_old'] . 'd · update due';
			return ' <span class="badge bg-danger" style="font-size:0.58rem;vertical-align:middle;">' . $t . '</span>';
		}
		return ' <span class="badge" style="font-size:0.58rem;vertical-align:middle;background:#e6f4ea;color:#1e7e34;">' . (int)$a['days_old'] . 'd ago</span>';
	}
	$balDue = (int)($data['manual']['due_count'] ?? 0);
	$updDays = (int)($data['manual']['update_days'] ?? 7);

	$monthOpts = '';
	foreach ($blocks as $b) $monthOpts .= '<option value="'.$b['ym'].'">'.htmlspecialchars($b['label']).'</option>';
	$knownLabels = array_values(array_unique(array_merge($events['labels_in'], $events['labels_out'])));
?>

<div class="mb-3 d-flex justify-content-between align-items-end flex-wrap gap-2">
	<div>
		<h2 class="fw-bold mb-0">Cash Management<?php echo $data['qb_company'] ? ' <span class="text-muted fw-normal" style="font-size:0.6em;">· '.htmlspecialchars($data['qb_company']).'</span>' : ''; ?></h2>
		<div class="text-muted small">12-month rolling plan. Each month combines projected sales, the <?php echo rtrim(rtrim(number_format($loanPct,2),'0'),'.'); ?>% Shopify loan, recurring costs, debt payments, bills/POs, and your own cash events.</div>
	</div>
	<div class="d-flex align-items-center gap-3 flex-wrap">
		<div class="text-end">
			<div class="small text-muted" id="syncLabel">
				<?php if ($syncedAt): ?>QuickBooks synced <?php echo date('M j, g:i A', strtotime($syncedAt)); ?>
				<?php else: ?>Not synced yet — click Refresh<?php endif; ?>
			</div>
			<button id="syncBtn" class="btn btn-sm btn-light-secondary py-0" style="font-size:0.75rem;"><i class="ti ti-refresh me-1"></i>Refresh now</button>
		</div>
		<form method="get" class="d-flex align-items-center gap-2 mb-0">
			<label class="small text-muted mb-0">Growth vs last yr:</label>
			<div class="input-group input-group-sm" style="width:100px;"><input type="number" step="1" name="growth" class="form-control" value="<?php echo (int)$growth; ?>" /><span class="input-group-text">%</span></div>
			<button class="btn btn-sm btn-light-primary">Apply</button>
		</form>
	</div>
</div>

<?php if (!$data['qb_connected']): ?>
<div class="alert alert-warning py-2">QuickBooks isn't connected — bank balances and bills won't load. <a href="/integrations.php">Go to Integrations</a>.</div>
<?php endif; ?>

<!-- SUMMARY STRIP -->
<div class="row g-2 mb-3">
	<div class="col-6 col-lg-3"><div class="card h-100" style="border-left:4px solid #2ca01c;"><div class="card-body py-2">
		<div class="text-muted text-uppercase fw-semibold" style="font-size:0.64rem;letter-spacing:.04em;">Cash on Hand</div>
		<div class="h5 fw-bold mb-0"><?php echo money($data['eff_cash']); ?></div>
		<div class="text-muted" style="font-size:0.68rem;"><?php echo $data['cash_source'] === 'manual' ? ('Manual'.($data['manual']['oldest_asof'] ? ' · as of '.fdate($data['manual']['oldest_asof']) : '')) : 'QuickBooks'; ?></div>
	</div></div></div>
	<div class="col-6 col-lg-3"><div class="card h-100" style="border-left:4px solid #4680ff;"><div class="card-body py-2">
		<div class="text-muted text-uppercase fw-semibold" style="font-size:0.64rem;letter-spacing:.04em;">Owed to You</div>
		<div class="h5 fw-bold mb-0 text-primary"><?php echo money($data['ar_total']); ?></div>
		<div class="text-muted" style="font-size:0.68rem;">Open Shopify orders</div>
	</div></div></div>
	<div class="col-6 col-lg-3"><div class="card h-100" style="border-left:4px solid #f5a623;"><div class="card-body py-2">
		<div class="text-muted text-uppercase fw-semibold" style="font-size:0.64rem;letter-spacing:.04em;">Credit / LOC Owed</div>
		<div class="h5 fw-bold mb-0" style="color:#d9822b;"><?php echo money($data['eff_credit']); ?></div>
		<?php // Same distinction as the Cash Flow page: LOC room is drawable cash, card room is not — never one merged "available".
		$locAv = (float)($data['manual']['loc_available'] ?? 0); $cardAv = max(0.0, (float)($data['manual']['card_available'] ?? 0)); ?>
		<div class="text-muted" style="font-size:0.68rem;"><?php echo $data['manual']['credit_limit_total'] > 0
			? money($locAv).' LOC to draw · '.money($cardAv).' card room'
			: 'outstanding debt'; ?></div>
	</div></div></div>
	<div class="col-6 col-lg-3"><div class="card h-100" style="border-left:4px solid <?php echo $data['net_quick'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><div class="card-body py-2">
		<div class="text-muted text-uppercase fw-semibold" style="font-size:0.64rem;letter-spacing:.04em;">Net Position</div>
		<div class="h5 fw-bold mb-0" style="color:<?php echo $data['net_quick'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><?php echo money($data['net_quick']); ?></div>
		<div class="text-muted" style="font-size:0.68rem;">Cash + owed − you owe</div>
	</div></div></div>
</div>

<?php $locFacs = $data['manual']['loc_facilities'] ?? []; if (!empty($locFacs)): ?>
<!-- LINES OF CREDIT (per-facility availability) -->
<div class="card mb-3"><div class="card-body py-2">
	<div class="d-flex flex-wrap gap-4 align-items-center">
		<span class="fw-semibold small text-uppercase text-muted" style="letter-spacing:.04em;">Lines of Credit</span>
		<?php foreach ($locFacs as $f):
			$isLoan = ($f['ceiling'] <= 0);   // no draw ceiling → a term loan (e.g. Shopify Capital), not a revolving line
			$over = !$isLoan && (!empty($f['overdrawn']) || $f['drawn'] > $f['ceiling'] + 0.005);
			$pct  = $isLoan ? 100 : min(100, round($f['drawn'] / $f['ceiling'] * 100));
			$barColor = $over ? '#e64545' : '#6f42c1';
		?>
		<div style="min-width:200px;">
			<div class="d-flex justify-content-between small"><span class="fw-semibold"><?php echo htmlspecialchars($f['name']); ?><?php if ($isLoan): ?> <span class="badge bg-info text-dark" style="font-size:0.5rem;">LOAN</span><?php endif; ?></span>
				<?php if ($isLoan): ?><span><strong style="color:#d9822b;"><?php echo money0($f['drawn']); ?></strong> <span class="text-muted">owed</span></span>
				<?php elseif ($over): ?><span><strong style="color:#e64545;">−<?php echo money0(abs($f['available'])); ?></strong> <span class="text-muted">over</span></span>
				<?php else: ?><span><strong style="color:#2ca01c;"><?php echo money0(max(0, $f['available'])); ?></strong> <span class="text-muted">avail</span></span><?php endif; ?>
			</div>
			<div style="height:6px;background:#eef1f5;border-radius:4px;overflow:hidden;"><div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $barColor; ?>;"></div></div>
			<div class="text-muted" style="font-size:0.64rem;"><?php if ($isLoan): ?>term loan · repaid from sales<?php else: ?><?php echo money0($f['drawn']); ?> drawn of <?php echo money0($f['ceiling']); ?><?php echo $over ? ' · <span style="color:#e64545;font-weight:600;">OVERDRAWN</span>' : ''; ?><?php endif; ?></div>
		</div>
		<?php endforeach; ?>
	</div>
</div></div>
<?php endif; ?>

<!-- AI ASSISTANT -->
<div class="card mb-3" style="border-left:4px solid #d97757;">
<div class="card-body py-2">
	<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
		<h6 class="fw-bold mb-0">🤖 Cash Flow Assistant <span class="text-muted fw-normal" style="font-size:0.72rem;">— talk to your plan; it proposes changes you approve</span></h6>
		<div class="d-flex align-items-center gap-2">
			<select id="cfChatHistory" class="form-select form-select-sm" style="max-width:200px;"><option value="">History…</option></select>
			<button class="btn btn-sm btn-light-secondary" id="cfChatNew" title="Start a new chat">New</button>
			<button class="btn btn-sm btn-light-primary" id="cfChatToggle">Open</button>
		</div>
	</div>
	<div id="cfChatPanel" class="mt-2 hidden">
		<div id="cfChatMsgs" style="max-height:340px;overflow-y:auto;font-size:0.86rem;"></div>
		<div id="cfChatActions" class="hidden border rounded p-2 my-2" style="background:#fff8f3;"></div>
		<div class="d-flex gap-2 mt-2">
			<input type="text" id="cfChatInput" class="form-control form-control-sm" placeholder="e.g. June's card payments are already made and my reported balances reflect them" />
			<button class="btn btn-sm btn-primary" id="cfChatSend">Send</button>
		</div>
		<div class="text-muted d-flex justify-content-between" style="font-size:0.7rem;"><span>It reads your balances, months, events, receivables &amp; settings. Nothing changes until you click <strong>Apply</strong>.</span><a href="#" id="cfChatDelete" class="text-danger hidden">Delete this chat</a></div>
	</div>
</div>
</div>

<?php if ($balDue > 0): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
	<div>⚠ <strong><?php echo $balDue; ?> account balance<?php echo $balDue === 1 ? '' : 's'; ?></strong> need the weekly update (older than <?php echo $updDays; ?> days). Keeping balances current keeps the whole forecast accurate.</div>
	<button class="btn btn-sm btn-warning" id="openBalances">Update now</button>
</div>
<?php endif; ?>

<!-- ── THIS MONTH — live progress tracker ──────────────────────────────────── -->
<?php if ($thisMonth): $tm = $thisMonth;
	$endColor = $tm['end_status']==='danger' ? '#e64545' : ($tm['end_status']==='warn' ? '#d9822b' : '#2ca01c');
	// Plain-English "are you on track" read.
	$opinion = [];
	if ($tm['proj_end'] !== null) {
		if ($tm['end_status']==='danger') $opinion[] = ['warn', 'On the current plan you end '.$tm['label'].' at '.money0($tm['proj_end']).' — below zero. Hold off on extra debt paydown and pull income forward.'];
		elseif ($tm['end_status']==='warn') $opinion[] = ['warn', 'You end the month at '.money0($tm['proj_end']).', under your '.money0($tm['buffer']).' buffer by '.money0($tm['buffer']-$tm['proj_end']).'. Pay only minimums on cards this month.'];
		else $opinion[] = ['good', 'On track — projected to end '.$tm['label'].' at '.money0($tm['proj_end']).', above your '.money0($tm['buffer']).' buffer.'];
	}
	if ($tm['pace']['cross_buffer_day'] !== null) $opinion[] = ['warn', 'At the current burn you dip below the buffer around the '.$tm['pace']['cross_buffer_day'].date('S', mktime(0,0,0,1,$tm['pace']['cross_buffer_day'],2000)).'. Slow discretionary spend or bring a receivable in sooner.'];
	if ($tm['in']['pace_delta'] < -1000) $opinion[] = ['info', 'Collections are running '.money0(abs($tm['in']['pace_delta'])).' behind pace ('.money0($tm['in']['received']).' of '.money0($tm['in']['planned']).' still expected in, '.round($tm['pace_frac']*100).'% through the days the plan covers).'];
	elseif ($tm['in']['pace_delta'] > 1000) $opinion[] = ['good', 'Collections are ahead of pace — '.money0($tm['in']['received']).' already in of '.money0($tm['in']['planned']).' planned.'];
	if (!empty($tm['debt']['target']) && $tm['debt']['target']['amount'] > 0) $opinion[] = ['info', 'Suggested debt focus: '.money0($tm['debt']['target']['amount']).' to '.htmlspecialchars($tm['debt']['target']['label']).($tm['debt']['target']['apr']!==null?' ('.rtrim(rtrim(number_format($tm['debt']['target']['apr'],2),'0'),'.').'% APR)':'').' — but only what keeps you above the buffer.'];
	if ($tm['yoy']['available']) { $up = $tm['yoy']['delta'] >= 0; $opinion[] = [$up?'good':'warn', 'Year-over-year: '.($up?'up':'down').' '.abs($tm['yoy']['pct']).'% vs '.date('M Y', strtotime($tm['ym'].'-01 -1 year')).' ('.money0($tm['yoy']['this']).' vs '.money0($tm['yoy']['prior']).' '.$tm['yoy']['basis'].').']; }
?>
<div class="card mb-3" style="border-top:4px solid #4680ff;">
	<div class="card-body">
		<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
			<div>
				<h4 class="fw-bold mb-0">This Month — <?php echo $tm['label']; ?></h4>
				<div class="text-muted small">Day <?php echo $tm['day']; ?> of <?php echo $tm['days_in']; ?> · <?php echo $tm['days_left']; ?> days left. Reconcile your real balances, tick what's already done, and see if you're on track.</div>
			</div>
			<button class="btn btn-primary" id="reconcileBtn"><i class="ti ti-checkbox me-1"></i>Reconcile now</button>
		</div>

		<!-- IN PROGRESS: this month is half-traded, so it is entered, not forecast.
		     Sales so far are already in the bank; only the estimate for the days that are
		     left can still bring cash in, and that estimate drives the whole 12-month plan. -->
		<?php $ip = $tm['in_progress']; $needEst = !empty($ip['needs_estimate']); ?>
		<div class="p-2 rounded mb-3" style="background:<?php echo $needEst ? '#fff8e6' : '#f2f7ff'; ?>;border-left:4px solid <?php echo $needEst ? '#e8a33d' : '#4680ff'; ?>;">
			<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
				<span class="badge" style="background:<?php echo $needEst ? '#e8a33d' : '#4680ff'; ?>;">IN PROGRESS</span>
				<span class="fw-semibold" style="font-size:0.82rem;"><?php echo $tm['label']; ?> is <?php echo round($tm['frac']*100); ?>% through — <?php echo $tm['days_left']; ?> days left to trade</span>
				<?php if (!empty($ip['as_of'])): ?>
					<span class="text-muted" style="font-size:0.7rem;">sales updated <?php echo $ip['age_days'] === 0 ? 'today' : $ip['age_days'].'d ago'; ?></span>
				<?php endif; ?>
			</div>
			<div class="row g-2 align-items-end">
				<div class="col-6 col-lg-3">
					<label class="text-muted text-uppercase fw-semibold d-block" style="font-size:0.6rem;letter-spacing:.04em;">Sales so far this month</label>
					<div class="input-group input-group-sm">
						<span class="input-group-text">$</span>
						<input type="text" class="form-control mo-actual" data-ym="<?php echo $tm['ym']; ?>" data-field="mtd"
						       value="<?php echo $ip['mtd']===null?'':number_format($ip['mtd'],0,'.',''); ?>" placeholder="enter">
					</div>
					<div class="text-muted" style="font-size:0.62rem;">already in your bank balance</div>
				</div>
				<div class="col-6 col-lg-3">
					<label class="text-muted text-uppercase fw-semibold d-block" style="font-size:0.6rem;letter-spacing:.04em;">Est. through month end</label>
					<div class="input-group input-group-sm">
						<span class="input-group-text">$</span>
						<input type="text" class="form-control mo-actual" data-ym="<?php echo $tm['ym']; ?>" data-field="rest"
						       value="<?php echo $ip['rest']===null?'':number_format($ip['rest'],0,'.',''); ?>" placeholder="enter">
					</div>
					<div class="text-muted" style="font-size:0.62rem;">
						<?php if ($ip['run_rate_rest'] !== null): ?>
							at your current run rate: <strong><?php echo money0($ip['run_rate_rest']); ?></strong>
						<?php else: ?>the only sales that can still come in<?php endif; ?>
					</div>
				</div>
				<div class="col-6 col-lg-3">
					<div class="text-muted text-uppercase fw-semibold" style="font-size:0.6rem;letter-spacing:.04em;">Month total</div>
					<div class="h5 fw-bold mb-0"><?php echo $ip['month_total']===null?'—':money0($ip['month_total']); ?></div>
					<div class="text-muted" style="font-size:0.62rem;">
						<?php if ($ip['run_rate'] !== null): ?><?php echo money0($ip['run_rate']); ?>/day so far<?php else: ?>so far + estimate<?php endif; ?>
					</div>
				</div>
				<div class="col-6 col-lg-3">
					<div class="text-muted text-uppercase fw-semibold" style="font-size:0.6rem;letter-spacing:.04em;">vs Last <?php echo date('M', strtotime($tm['ym'].'-01')); ?></div>
					<?php if ($tm['yoy']['available'] && $ip['month_total'] !== null): ?>
						<div class="h5 fw-bold mb-0" style="color:<?php echo $tm['yoy']['delta']>=0?'#2ca01c':'#e64545'; ?>;"><?php echo ($tm['yoy']['delta']>=0?'+':'').$tm['yoy']['pct']; ?>%</div>
						<div class="text-muted" style="font-size:0.62rem;"><?php echo money0($tm['yoy']['prior']); ?> last year</div>
					<?php else: ?>
						<div class="h5 fw-bold mb-0 text-muted">—</div>
						<div class="text-muted" style="font-size:0.62rem;">enter sales to compare</div>
					<?php endif; ?>
				</div>
			</div>
			<?php if ($needEst): ?>
				<div class="mt-2 mb-0" style="font-size:0.76rem;color:#8a6116;">
					<i class="ti ti-alert-triangle me-1"></i>No estimate yet, so the plan plans on <strong>$0</strong> more coming in this month and will not suggest spending against it. Enter both figures above to see the real picture.
				</div>
			<?php elseif (!empty($ip['stale'])): ?>
				<div class="mt-2 mb-0" style="font-size:0.76rem;color:#8a6116;">
					<i class="ti ti-clock me-1"></i>These sales figures are <?php echo $ip['age_days']; ?> days old. Update them — the whole 12-month plan is built on this month's estimate.
				</div>
			<?php endif; ?>
		</div>

		<!-- KPI row -->
		<div class="row g-2 mb-3">
			<div class="col-6 col-lg-3"><div class="p-2 rounded h-100" style="background:#f6f8fa;">
				<div class="text-muted text-uppercase fw-semibold" style="font-size:0.6rem;letter-spacing:.04em;">Cash in Bank Now</div>
				<div class="h5 fw-bold mb-0"><?php echo money0($tm['start_cash']); ?></div>
			</div></div>
			<div class="col-6 col-lg-3"><div class="p-2 rounded h-100" style="background:#f6f8fa;">
				<div class="text-muted text-uppercase fw-semibold" style="font-size:0.6rem;letter-spacing:.04em;">Projected Month-End</div>
				<div class="h5 fw-bold mb-0" style="color:<?php echo $endColor; ?>;"><?php echo $tm['proj_end']===null?'—':money0($tm['proj_end']); ?></div>
				<div class="text-muted" style="font-size:0.64rem;">buffer <?php echo money0($tm['buffer']); ?></div>
			</div></div>
			<div class="col-6 col-lg-3"><div class="p-2 rounded h-100" style="background:#f6f8fa;">
				<div class="text-muted text-uppercase fw-semibold" style="font-size:0.6rem;letter-spacing:.04em;">Suggested to Debt</div>
				<div class="h5 fw-bold mb-0" style="color:#6f42c1;"><?php echo money0($tm['debt']['planned']); ?></div>
				<div class="text-muted" style="font-size:0.64rem;">minimums + avalanche</div>
			</div></div>
			<div class="col-6 col-lg-3"><div class="p-2 rounded h-100" style="background:#f6f8fa;">
				<div class="text-muted text-uppercase fw-semibold" style="font-size:0.6rem;letter-spacing:.04em;">vs Last Year</div>
				<?php if ($tm['yoy']['available']): ?>
					<div class="h5 fw-bold mb-0" style="color:<?php echo $tm['yoy']['delta']>=0?'#2ca01c':'#e64545'; ?>;"><?php echo ($tm['yoy']['delta']>=0?'+':'').$tm['yoy']['pct']; ?>%</div>
					<div class="text-muted" style="font-size:0.64rem;"><?php echo money0($tm['yoy']['this']); ?> vs <?php echo money0($tm['yoy']['prior']); ?></div>
				<?php else: ?>
					<div class="fw-semibold mb-0" style="font-size:0.8rem;">Unlocks in 2027</div>
					<div class="text-muted" style="font-size:0.64rem;">needs a full prior year</div>
				<?php endif; ?>
			</div></div>
		</div>

		<!-- Progress bars: in received vs planned, out paid vs planned -->
		<div class="row g-3 mb-2">
			<div class="col-12 col-lg-6">
				<div class="d-flex justify-content-between align-items-center mb-1">
					<span class="fw-semibold text-success" style="font-size:0.8rem;">Cash In — received this month</span>
					<span class="small"><span class="fw-bold text-success"><?php echo money0($tm['in']['received']); ?></span> <span class="text-muted">of <?php echo money0($tm['in']['planned']); ?> planned</span></span>
				</div>
				<div class="progress" style="height:14px;"><div class="progress-bar bg-success" style="width:<?php echo $tm['in']['pct']; ?>%;"><?php echo $tm['in']['pct']; ?>%</div></div>
				<div class="text-muted mt-1" style="font-size:0.68rem;"><?php echo money0($tm['in']['remaining']); ?> still to come in.</div>
			</div>
			<div class="col-12 col-lg-6">
				<div class="d-flex justify-content-between align-items-center mb-1">
					<span class="fw-semibold" style="font-size:0.8rem;color:#d9822b;">Operating Cash Out — paid this month</span>
					<span class="small"><span class="fw-bold" style="color:#d9822b;"><?php echo money0($tm['out']['paid']); ?></span> <span class="text-muted">of <?php echo money0($tm['out']['planned']); ?> planned</span></span>
				</div>
				<div class="progress" style="height:14px;"><div class="progress-bar" style="width:<?php echo $tm['out']['pct']; ?>%;background:#d9822b;"><?php echo $tm['out']['pct']; ?>%</div></div>
				<div class="text-muted mt-1" style="font-size:0.68rem;"><?php echo money0($tm['out']['remaining']); ?> operating still to pay (debt payments tracked separately).</div>
			</div>
		</div>

		<!-- On-track opinion -->
		<?php if (!empty($opinion)): ?>
		<div class="p-2 rounded mb-2" style="background:#f8f9fb;">
			<div class="fw-semibold text-uppercase text-muted mb-1" style="font-size:0.62rem;letter-spacing:.04em;">Staying on track</div>
			<?php foreach ($opinion as $op): $oc = $op[0]==='warn' ? '#e64545' : ($op[0]==='good' ? '#2ca01c' : '#4680ff'); ?>
				<div class="small mb-1" style="line-height:1.35;"><span style="color:<?php echo $oc; ?>;">●</span> <?php echo $op[1]; ?></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<!-- LOC loan payments (auto-draw on their due day, clear ~3 days later) -->
		<?php if (!empty($tm['loan_payments'])): ?>
		<div class="p-2 rounded mb-2" style="background:#faf9fe;">
			<div class="fw-semibold text-uppercase mb-1" style="font-size:0.62rem;letter-spacing:.04em;color:#6f42c1;">LOC loan payments <span class="text-muted" style="text-transform:none;letter-spacing:0;font-weight:400;">· auto-drawn from the bank, clear ~3 days after the due date</span></div>
			<?php foreach ($tm['loan_payments'] as $lp):
				$suf = $lp['due_day'] ? date('S', mktime(0,0,0,1,(int)$lp['due_day'],2000)) : '';
				$st = $lp['status'];
				$stTxt = $lp['paid'] ? '' : ($st==='cleared' ? 'likely cleared — confirm' : ($st==='drawing' ? 'drawing / clearing now' : ($st==='upcoming' ? 'upcoming' : '')));
				$stColor = $st==='cleared' ? '#2ca01c' : ($st==='drawing' ? '#d9822b' : '#4680ff');
			?>
			<div class="d-flex justify-content-between align-items-center small py-1" style="border-bottom:1px solid #efeaf9;<?php echo $lp['paid'] ? 'opacity:.6;' : ''; ?>">
				<span>
					<?php if (!empty($lp['key'])): ?><input type="checkbox" class="cashout-paid" data-ym="<?php echo $tm['ym']; ?>" data-key="<?php echo htmlspecialchars($lp['key'], ENT_QUOTES); ?>"<?php echo $lp['paid'] ? ' checked' : ''; ?> title="Mark this loan payment as cleared this month" style="vertical-align:middle;margin-right:4px;"><?php endif; ?>
					<span<?php echo $lp['paid'] ? ' style="text-decoration:line-through;"' : ''; ?>><?php echo htmlspecialchars($lp['label']); ?></span>
					<?php if ($lp['due_day']): ?> <span class="text-muted" style="font-size:0.7rem;">due the <?php echo (int)$lp['due_day'].$suf; ?></span><?php endif; ?>
					<?php if ($lp['paid']): ?> <span class="badge bg-success" style="font-size:0.5rem;vertical-align:middle;">CLEARED</span><?php elseif ($stTxt): ?> <span style="color:<?php echo $stColor; ?>;font-size:0.68rem;">● <?php echo $stTxt; ?></span><?php endif; ?>
				</span>
				<span class="fw-semibold" style="color:#6f42c1;<?php echo $lp['paid'] ? 'text-decoration:line-through;' : ''; ?>"><?php echo money0($lp['amount']); ?></span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<!-- Done vs To-do -->
		<div class="row g-3">
			<div class="col-12 col-lg-6">
				<div class="fw-semibold text-uppercase mb-1" style="font-size:0.64rem;letter-spacing:.04em;color:#2ca01c;">✓ Done this month</div>
				<?php $doneAll = array_merge($tm['in']['done'], $tm['out']['done']);
				if (empty($doneAll)): ?><div class="text-muted small">Nothing reconciled yet — click <strong>Reconcile now</strong>.</div>
				<?php else: foreach ($tm['in']['done'] as $it): ?>
					<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid #f1f3f5;"><span class="text-muted" style="text-decoration:line-through;"><?php echo htmlspecialchars($it['label']); ?></span><span class="fw-semibold text-success">+<?php echo money0($it['amount']); ?></span></div>
				<?php endforeach; foreach ($tm['out']['done'] as $it): ?>
					<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid #f1f3f5;"><span class="text-muted" style="text-decoration:line-through;"><?php echo htmlspecialchars($it['label']); ?></span><span class="fw-semibold" style="color:#d9822b;">−<?php echo money0($it['amount']); ?></span></div>
				<?php endforeach; endif; ?>
			</div>
			<div class="col-12 col-lg-6">
				<div class="fw-semibold text-uppercase mb-1" style="font-size:0.64rem;letter-spacing:.04em;color:#d9822b;">◻ Still to do</div>
				<?php $todoAll = array_merge($tm['in']['todo'], $tm['out']['todo']);
				if (empty($todoAll)): ?><div class="text-muted small">Everything this month is reconciled. 🎉</div>
				<?php else: foreach ($tm['in']['todo'] as $it): ?>
					<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid #f1f3f5;"><span><?php echo htmlspecialchars($it['label']); ?></span><span class="fw-semibold text-success">+<?php echo money0($it['amount']); ?></span></div>
				<?php endforeach; foreach ($tm['out']['todo'] as $it): ?>
					<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid #f1f3f5;"><span><?php echo htmlspecialchars($it['label']); ?></span><span class="fw-semibold" style="color:#d9822b;">−<?php echo money0($it['amount']); ?></span></div>
				<?php endforeach; endif; ?>
			</div>
		</div>
	</div>
</div>

<!-- Reconcile modal -->
<div class="modal fade" id="reconcileModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title fw-bold">Reconcile <?php echo $tm['label']; ?> to reality</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<p class="text-muted small">Set your <strong>actual balances right now</strong>, then tick anything below that has <strong>already happened</strong> — it's already inside those balances, so it drops out of the forecast (no double-counting a drawn loan or a bill you've paid).</p>

				<div class="fw-semibold text-uppercase mb-1" style="font-size:0.64rem;letter-spacing:.04em;">Balances as of today</div>
				<div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
					<tbody>
					<?php foreach (array_merge($tm['bank_accounts'], $tm['credit_accounts']) as $acc): ?>
						<tr>
							<td class="small"><?php echo htmlspecialchars($acc['label']); ?> <span class="badge bg-light text-muted" style="font-size:0.54rem;"><?php echo $acc['type']==='bank'?'Bank':($acc['type']==='loc'?'LOC':'Card'); ?></span></td>
							<td style="max-width:160px;"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" class="form-control rec-bal" data-id="<?php echo (int)$acc['id']; ?>" value="<?php echo number_format((float)$acc['balance'],2,'.',''); ?>"></div></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>

				<div class="fw-semibold text-uppercase mb-1" style="font-size:0.64rem;letter-spacing:.04em;color:#2ca01c;">Cash IN — already received (in the bank now)</div>
				<?php $allIn = array_merge($tm['in']['done'], $tm['in']['todo']);
				if (empty($allIn)): ?><div class="text-muted small mb-2">No cash-in lines this month.</div>
				<?php else: foreach ($allIn as $it): ?>
					<div class="form-check"><input class="form-check-input rec-in" type="checkbox" value="<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"<?php echo !empty($it['received'])?' checked':''; ?> id="recin_<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"><label class="form-check-label small" for="recin_<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($it['label']); ?> <span class="text-success fw-semibold">+<?php echo money0($it['amount']); ?></span></label></div>
				<?php endforeach; endif; ?>

				<div class="fw-semibold text-uppercase mb-1 mt-3" style="font-size:0.64rem;letter-spacing:.04em;color:#d9822b;">Cash OUT — already paid</div>
				<?php $allOut = array_merge($tm['out']['done'], $tm['out']['todo']);
				if (empty($allOut)): ?><div class="text-muted small">No reconcilable cash-out lines this month.</div>
				<?php else: foreach ($allOut as $it): ?>
					<div class="form-check"><input class="form-check-input rec-out" type="checkbox" value="<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"<?php echo !empty($it['paid'])?' checked':''; ?> id="recout_<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"><label class="form-check-label small" for="recout_<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($it['label']); ?> <span class="fw-semibold" style="color:#d9822b;">−<?php echo money0($it['amount']); ?></span></label></div>
				<?php endforeach; endif; ?>

				<?php if (!empty($tm['loan_payments'])): ?>
				<div class="fw-semibold text-uppercase mb-1 mt-3" style="font-size:0.64rem;letter-spacing:.04em;color:#6f42c1;">LOC loan payments — already cleared</div>
				<?php foreach ($tm['loan_payments'] as $lp): if (empty($lp['key'])) continue; $suf = $lp['due_day'] ? date('S', mktime(0,0,0,1,(int)$lp['due_day'],2000)) : ''; ?>
					<div class="form-check"><input class="form-check-input rec-out" type="checkbox" value="<?php echo htmlspecialchars($lp['key'], ENT_QUOTES); ?>"<?php echo !empty($lp['paid'])?' checked':''; ?> id="recout_<?php echo htmlspecialchars($lp['key'], ENT_QUOTES); ?>"><label class="form-check-label small" for="recout_<?php echo htmlspecialchars($lp['key'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($lp['label']); ?><?php echo $lp['due_day'] ? ' <span class="text-muted">(due the '.(int)$lp['due_day'].$suf.')</span>' : ''; ?> <span class="fw-semibold" style="color:#6f42c1;">−<?php echo money0($lp['amount']); ?></span></label></div>
				<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div class="modal-footer">
				<span id="recMsg" class="small text-danger me-auto"></span>
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
				<button type="button" class="btn btn-primary" id="recSaveBtn">Save reconciliation</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- ── MANAGE (background inputs) ───────────────────────────────────────────── -->
<details class="mb-3" id="manageDetails"<?php echo $balDue > 0 ? ' open' : ''; ?>>
	<summary class="btn btn-sm btn-light-secondary">⚙ Manage balances, recurring costs, cash events &amp; settings</summary>
	<div class="row g-3 mt-1">

		<!-- BALANCES -->
		<div class="col-12 col-lg-4"><div class="card h-100"><div class="card-body">
			<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold mb-0">Account Balances</h6><button class="btn btn-sm btn-light-primary" id="addBalBtn">+ Add account</button></div>
			<p class="text-muted mb-2" style="font-size:0.72rem;">Permanent accounts. <strong>Update each balance weekly</strong> (every <?php echo $updDays; ?> days) — a red badge means it's due. APR rarely changes but stays editable.</p>
			<div id="balForm" class="border rounded p-2 mb-3 hidden" style="background:#f8f9fb;">
				<input type="hidden" id="balId" value="" /><input type="hidden" id="balQbId" value="" />
				<div class="row g-2">
					<?php if (!empty($data['qb_accounts'])): ?>
					<div class="col-12"><select id="balQbAccount" class="form-select form-select-sm"><option value="">— Pick from QuickBooks —</option>
						<?php foreach ($data['qb_accounts'] as $qa): ?><option value="<?php echo htmlspecialchars($qa['id'], ENT_QUOTES); ?>" data-name="<?php echo htmlspecialchars($qa['name'], ENT_QUOTES); ?>" data-type="<?php echo $qa['type']; ?>" data-balance="<?php echo $qa['balance']; ?>"><?php echo htmlspecialchars($qa['name']); ?> (<?php echo $qa['type'] === 'bank' ? 'Bank' : ($qa['type'] === 'credit' ? 'Credit Card' : 'LOC'); ?>)</option><?php endforeach; ?>
						<option value="__manual__">Other — manual</option></select></div>
					<?php endif; ?>
					<div class="col-12"><input type="text" id="balLabel" class="form-control form-control-sm" placeholder="Account name (e.g. Redwood Credit Union 1183)" /></div>
					<div class="col-6"><select id="balType" class="form-select form-select-sm"><option value="bank">Bank / Cash</option><option value="credit">Credit Card</option><option value="loc">Line of Credit</option></select></div>
					<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="balAmount" class="form-control" placeholder="Balance" /></div></div>
					<div class="col-6" id="balLimitWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">Limit $</span><input type="text" id="balLimit" class="form-control" placeholder="Credit limit" /></div></div>
					<div class="col-6" id="balPayWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">Loan pay/mo $</span><input type="text" id="balPayment" class="form-control" placeholder="Fixed loan payment" /></div><div class="form-text" style="font-size:0.62rem;">Cards don't need this — minimums auto-calc.</div></div>
					<div class="col-6" id="balLocWrap" style="display:none;"><input type="text" id="balLoc" class="form-control form-control-sm" list="balLocNames" placeholder="Which LOC (facility)"><datalist id="balLocNames"><?php foreach (loc_ceilings($db) as $c): ?><option value="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"></option><?php endforeach; ?></datalist><div class="form-text" style="font-size:0.62rem;">Which line of credit this loan draws on.</div></div>
					<div class="col-6" id="balDueWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">Pmt due day</span><input type="number" min="1" max="31" id="balDueDay" class="form-control" placeholder="e.g. 13" /></div><div class="form-text" style="font-size:0.62rem;">Day of month the loan auto-draws (clears ~3 days later).</div></div>
					<div class="col-6" id="balAprWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">APR %</span><input type="text" id="balApr" class="form-control" placeholder="e.g. 24.99" /></div></div>
					<div class="col-6"><input type="date" id="balAsOf" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" title="Date accurate as of" /></div>
					<div class="col-12"><input type="text" id="balNote" class="form-control form-control-sm" placeholder="Note (optional)" /></div>
					<div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary" id="balSaveBtn">Save</button><button class="btn btn-sm btn-secondary" id="balCancelBtn">Cancel</button><span id="balMsg" class="small ms-1"></span></div>
				</div>
			</div>
			<div class="fw-semibold small text-muted mb-1">Bank / Cash</div>
			<?php if (empty($data['manual']['bank'])): ?><div class="text-muted small mb-2">None entered.</div>
			<?php else: foreach ($data['manual']['bank'] as $a): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small bal-row" data-id="<?php echo $a['id']; ?>" data-label="<?php echo htmlspecialchars($a['label'], ENT_QUOTES); ?>" data-type="bank" data-balance="<?php echo $a['balance']; ?>" data-qbid="<?php echo htmlspecialchars((string)$a['qb_id'], ENT_QUOTES); ?>" data-asof="<?php echo $a['as_of']; ?>" data-note="<?php echo htmlspecialchars((string)$a['note'], ENT_QUOTES); ?>">
					<span><?php echo htmlspecialchars($a['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo fdate($a['as_of']); ?></span><?php echo bal_badge($a); ?></span>
					<span><span class="fw-semibold"><?php echo money($a['balance']); ?></span> <a href="#" class="bal-update ms-1 fw-semibold" style="font-size:0.7rem;">update</a> <a href="#" class="bal-edit ms-1 text-muted" style="font-size:0.7rem;">edit</a> <a href="#" class="bal-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; endif; ?>
			<div class="fw-semibold small text-muted mb-1 mt-3">Credit Cards / Lines of Credit</div>
			<?php if (empty($data['manual']['credit'])): ?><div class="text-muted small">None entered.</div>
			<?php else: foreach ($data['manual']['credit'] as $a): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small bal-row" data-id="<?php echo $a['id']; ?>" data-label="<?php echo htmlspecialchars($a['label'], ENT_QUOTES); ?>" data-type="<?php echo $a['type']; ?>" data-balance="<?php echo $a['balance']; ?>" data-limit="<?php echo $a['limit']; ?>" data-payment="<?php echo $a['payment']; ?>" data-apr="<?php echo $a['apr'] !== null ? $a['apr'] : ''; ?>" data-qbid="<?php echo htmlspecialchars((string)$a['qb_id'], ENT_QUOTES); ?>" data-asof="<?php echo $a['as_of']; ?>" data-note="<?php echo htmlspecialchars((string)$a['note'], ENT_QUOTES); ?>" data-locname="<?php echo htmlspecialchars((string)($a['loc_name'] ?? ''), ENT_QUOTES); ?>" data-dueday="<?php echo $a['due_day'] !== null ? (int)$a['due_day'] : ''; ?>">
					<span><?php echo htmlspecialchars($a['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo htmlspecialchars($a['kind']); ?><?php echo $a['apr'] !== null ? ' · '.rtrim(rtrim(number_format($a['apr'],2),'0'),'.').'% APR' : ''; ?><?php echo $a['payment'] > 0 ? ' · '.money($a['payment']).'/mo' : ''; ?></span><?php echo bal_badge($a); ?>
						<?php if ($a['type']==='loc'): ?>
						<select class="form-select form-select-sm bal-loc-inline d-inline-block ms-1" data-id="<?php echo $a['id']; ?>" style="width:auto;font-size:0.68rem;padding:1px 18px 1px 6px;height:auto;vertical-align:middle;">
							<option value="">— assign LOC —</option>
							<?php foreach (loc_ceilings($db) as $c): ?><option value="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"<?php echo (strcasecmp((string)($a['loc_name'] ?? ''), $c['name'])===0) ? ' selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
						</select>
						<?php endif; ?>
					</span>
					<span><span class="fw-semibold" style="color:#d9822b;"><?php echo money($a['balance']); ?></span> <a href="#" class="bal-update ms-1 fw-semibold" style="font-size:0.7rem;">update</a> <a href="#" class="bal-edit ms-1 text-muted" style="font-size:0.7rem;">edit</a> <a href="#" class="bal-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; endif; ?>
		</div></div></div>

		<!-- RECURRING EXPENSES -->
		<div class="col-12 col-lg-4"><div class="card h-100"><div class="card-body">
			<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold mb-0">Recurring Monthly Expenses</h6><button class="btn btn-sm btn-light-primary" id="addExpBtn">+ Add</button></div>
			<p class="text-muted mb-2" style="font-size:0.72rem;">Fixed monthly costs (rent, payroll, software). Applied to every month as "Recurring".</p>
			<div id="expForm" class="border rounded p-2 mb-3 hidden" style="background:#f8f9fb;">
				<input type="hidden" id="expId" value="" />
				<div class="row g-2">
					<div class="col-12"><input type="text" id="expLabel" class="form-control form-control-sm" placeholder="Expense name" /></div>
					<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="expAmount" class="form-control" placeholder="Monthly" /></div></div>
					<div class="col-6"><input type="text" id="expCategory" class="form-control form-control-sm" placeholder="Category" /></div>
					<div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary" id="expSaveBtn">Save</button><button class="btn btn-sm btn-secondary" id="expCancelBtn">Cancel</button><span id="expMsg" class="small ms-1"></span></div>
				</div>
			</div>
			<?php if (empty($recur['items'])): ?><div class="text-muted small mb-2">None entered.<?php if ($forecast['qb_estimate'] !== null): ?> Using QuickBooks estimate <strong><?php echo money($forecast['qb_estimate']); ?>/mo</strong>.<?php endif; ?></div>
			<?php else: foreach ($recur['items'] as $e): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small exp-row" data-id="<?php echo $e['id']; ?>" data-label="<?php echo htmlspecialchars($e['label'], ENT_QUOTES); ?>" data-amount="<?php echo $e['amount']; ?>" data-category="<?php echo htmlspecialchars((string)$e['category'], ENT_QUOTES); ?>">
					<span><?php echo htmlspecialchars($e['label']); ?><?php echo $e['category'] ? ' <span class="text-muted" style="font-size:0.7rem;">· '.htmlspecialchars($e['category']).'</span>' : ''; ?></span>
					<span><span class="fw-semibold"><?php echo money($e['amount']); ?></span> <a href="#" class="exp-edit ms-1" style="font-size:0.7rem;">edit</a> <a href="#" class="exp-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; ?>
				<div class="d-flex justify-content-between py-1 small fw-bold border-top mt-1"><span>Total / month</span><span><?php echo money($recur['total']); ?></span></div>
			<?php endif; ?>
		</div></div></div>

		<!-- CASH EVENTS + SETTINGS -->
		<div class="col-12 col-lg-4"><div class="card h-100"><div class="card-body">
			<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold mb-0">Cash In / Out Events</h6><button class="btn btn-sm btn-light-primary" id="addEvBtn">+ Add</button></div>
			<p class="text-muted mb-2" style="font-size:0.72rem;">One-off cash in/out tied to a month + week (e.g. a tax payment, a wholesale deposit). Type to reuse a known event name.</p>
			<div id="evForm" class="border rounded p-2 mb-3 hidden" style="background:#f8f9fb;">
				<input type="hidden" id="evId" value="" />
				<div class="row g-2">
					<div class="col-6"><select id="evType" class="form-select form-select-sm"><option value="out">Cash Out</option><option value="in">Cash In</option></select></div>
					<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="evAmount" class="form-control" placeholder="Amount" /></div></div>
					<div class="col-12"><input type="text" id="evLabel" class="form-control form-control-sm" list="evKnown" placeholder="What is it?" /><datalist id="evKnown"><?php foreach ($knownLabels as $l): ?><option value="<?php echo htmlspecialchars($l, ENT_QUOTES); ?>"></option><?php endforeach; ?></datalist></div>
					<div class="col-8"><select id="evMonth" class="form-select form-select-sm"><?php echo $monthOpts; ?></select></div>
					<div class="col-4"><select id="evWeek" class="form-select form-select-sm"><option value="1">Wk 1</option><option value="2">Wk 2</option><option value="3">Wk 3</option><option value="4">Wk 4</option></select></div>
					<div class="col-12" id="evCardWrap"><label class="small mb-0" style="cursor:pointer;"><input type="checkbox" id="evCard" /> 💳 Goes on a credit card <span class="text-muted">— tracked, but not counted as cash out of the bank</span></label></div>
					<div class="col-12" id="evPaidWrap" style="display:none;"><label class="small mb-0" style="cursor:pointer;"><input type="checkbox" id="evPaid" /> ✅ Already paid / on the card balance <span class="text-muted">— drops it from the upcoming credit-out total</span></label></div>
					<div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary" id="evSaveBtn">Save</button><button class="btn btn-sm btn-secondary" id="evCancelBtn">Cancel</button><span id="evMsg" class="small ms-1"></span></div>
				</div>
			</div>
			<?php if (empty($events['all'])): ?><div class="text-muted small mb-2">No cash events yet.</div>
			<?php else: foreach ($events['all'] as $e): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small ev-row" data-id="<?php echo $e['id']; ?>" data-etype="<?php echo $e['etype']; ?>" data-label="<?php echo htmlspecialchars($e['label'], ENT_QUOTES); ?>" data-amount="<?php echo $e['amount']; ?>" data-ym="<?php echo $e['ym']; ?>" data-week="<?php echo $e['week']; ?>" data-paidby="<?php echo ($e['paidby'] ?? 'cash'); ?>">
					<span><span class="badge <?php echo $e['etype']==='in'?'bg-success':'bg-warning text-dark'; ?>" style="font-size:0.6rem;"><?php echo $e['etype']==='in'?'IN':'OUT'; ?></span> <?php if (($e['paidby'] ?? 'cash')==='card'): ?><span class="badge bg-info text-dark" style="font-size:0.6rem;">💳 CARD</span> <?php if (!empty($e['paid'])): ?><span class="badge bg-success" style="font-size:0.6rem;">PAID</span> <?php endif; ?><?php endif; ?><?php echo htmlspecialchars($e['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo date('M Y', strtotime($e['ym'].'-01')); ?> wk<?php echo $e['week']; ?></span></span>
					<span><span class="fw-semibold"><?php echo money($e['amount']); ?></span> <a href="#" class="ev-edit ms-1" style="font-size:0.7rem;">edit</a> <a href="#" class="ev-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; endif; ?>

			<hr class="my-2">
			<div class="fw-semibold small text-muted mb-1">Planning settings</div>
			<div class="row g-2">
				<div class="col-12"><label class="form-text mb-0">Shopify loan — % of sales to cash out</label><div class="input-group input-group-sm"><input type="text" id="loanPct" class="form-control" value="<?php echo rtrim(rtrim(number_format($loanPct,2),'0'),'.'); ?>" /><span class="input-group-text">%</span></div></div>
				<div class="col-12"><label class="form-text mb-0">Cash buffer — keep this in the bank all year</label><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="cashBuffer" class="form-control" value="<?php echo number_format($monthData['buffer'], 0, '.', ''); ?>" /></div></div>
				<div class="col-12"><label class="form-text mb-0">Tax set-aside — saved each month, paid each quarter</label><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="taxMonthly" class="form-control" value="<?php echo number_format($monthData['tax_monthly'], 0, '.', ''); ?>" /><span class="input-group-text">/mo</span></div></div>
				<div class="col-12">
					<label class="form-text mb-0">Lines of credit — each facility's own ceiling</label>
					<div id="locCeilList">
						<?php foreach (loc_ceilings($db) as $c): ?>
						<div class="d-flex gap-1 mb-1 align-items-center loc-ceil-row">
							<input type="text" class="form-control form-control-sm loc-ceil-name" value="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>" placeholder="LOC name" style="max-width:150px;">
							<div class="input-group input-group-sm" style="max-width:130px;"><span class="input-group-text">$</span><input type="text" class="form-control loc-ceil-amt" value="<?php echo number_format($c['ceiling'], 0, '.', ''); ?>" placeholder="ceiling"></div>
							<a href="#" class="loc-ceil-del text-danger" title="Remove">×</a>
						</div>
						<?php endforeach; ?>
					</div>
					<button type="button" id="locCeilAdd" class="btn btn-sm btn-light">+ Add LOC</button>
					<div class="form-text" style="font-size:0.66rem;">Each LOC's available-to-draw = its ceiling − the loan balances assigned to it. Assign a loan to its LOC when you edit the balance.</div>
				</div>
				<div class="col-12"><label class="form-text mb-0">Credit-card minimum payment — auto-calculated each month</label><div class="d-flex gap-2"><div class="input-group input-group-sm"><input type="text" id="cardMinPct" class="form-control" value="<?php echo rtrim(rtrim(number_format(card_min_pct($db),2),'0'),'.'); ?>" style="max-width:70px;" /><span class="input-group-text">% of balance</span></div><div class="input-group input-group-sm"><span class="input-group-text">min $</span><input type="text" id="cardMinFloor" class="form-control" value="<?php echo number_format(card_min_floor($db), 0, '.', ''); ?>" style="max-width:80px;" /></div></div><div class="form-text" style="font-size:0.66rem;">Cards pay the greater of this % of the current balance or the floor; recalculates as balances change.</div></div>
				<div class="col-12"><button class="btn btn-sm btn-primary" id="loanSaveBtn">Save settings</button> <span id="loanMsg" class="small"></span></div>
			</div>
			<div class="text-muted" style="font-size:0.7rem;">Loan: 25% repays Shopify Capital (set 0 when paid off). Buffer: extra cash above this is thrown at the highest-APR card. Tax: builds a reserve, released each quarter-end.</div>
		</div></div></div>

	</div>

	<!-- RECEIVABLES — schedule expected payment into the right month -->
	<div class="card mt-2"><div class="card-body">
		<h6 class="fw-bold mb-1">Owed to You — schedule expected payment</h6>
		<p class="text-muted small mb-2">Set when each open Shopify order is expected to be paid (e.g. a Net-60 wholesale PO) — it then shows as <strong>cash in</strong> that month. Leave blank to keep it out of the forecast.</p>
		<?php if (!empty($data['ar']['error'])): ?>
			<div class="text-muted small"><?php echo htmlspecialchars($data['ar']['error']); ?></div>
		<?php elseif (empty($data['ar']['items'])): ?>
			<div class="text-muted small">No open Shopify orders.</div>
		<?php else: ?>
		<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:0.84rem;">
			<thead><tr style="background:#f1f3f5;"><th>Order</th><th>Customer</th><th>Type</th><th class="text-end">Amount</th><th style="width:175px;">Expected payment</th></tr></thead>
			<tbody>
			<?php foreach ($data['ar']['items'] as $a): ?>
				<tr>
					<td><?php echo htmlspecialchars($a['name']); ?></td>
					<td><?php echo htmlspecialchars($a['customer']); ?></td>
					<td><span class="badge bg-light text-dark" style="font-size:0.6rem;"><?php echo htmlspecialchars($a['type']); ?></span></td>
					<td class="text-end fw-semibold text-primary"><?php echo money($a['amount']); ?></td>
					<td><input type="date" class="form-control form-control-sm ar-date" data-key="<?php echo htmlspecialchars($a['name'], ENT_QUOTES); ?>" value="<?php echo htmlspecialchars((string)($a['expected'] ?? ''), ENT_QUOTES); ?>" /></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
		<?php endif; ?>
	</div></div>
</details>

<?php
	// The paydown plan is for the CURRENT month. $blocks[0] can be the hidden prior
	// month (kept for entering actuals), which carries no avalanche — using it labelled
	// the panel with last month and reported "no card payments".
	$b0 = null;
	foreach ($blocks as $_b) { if (($_b['ym'] ?? '') === date('Y-m')) { $b0 = $_b; break; } }
	if ($b0 === null) foreach ($blocks as $_b) { if (empty($_b['is_past'])) { $b0 = $_b; break; } }
	if ($b0 === null) $b0 = $blocks[0] ?? null;
?>
<div class="row g-3 mb-1">
	<!-- CARD PAYDOWN PLAN (this month) -->
	<div class="col-12 col-lg-7">
	<div class="card h-100" style="border-left:4px solid #6f42c1;"><div class="card-body py-2">
		<h6 class="fw-bold mb-1">Card &amp; Loan Paydown Plan — this month<?php echo $b0 ? ' ('.$b0['label'].')' : ''; ?></h6>
		<p class="text-muted small mb-2">Keep a <strong><?php echo money0($monthData['buffer']); ?></strong> cash buffer; pay each card &amp; loan its minimum, then throw every spare dollar at the <strong>highest-APR</strong> card first. Fixed-term LOC loans just take their scheduled payment.</p>
		<?php
		$cps = array_values(array_filter($b0['card_payments'] ?? [], fn($c)=>$c['amount']>0));
		usort($cps, fn($a,$b)=> ($b['is_target']<=>$a['is_target']) ?: (($b['apr']??-1)<=>($a['apr']??-1)));
		if (empty($cps)): ?>
			<div class="text-muted small">No card payments this month (no card balances, or no cash above the buffer).</div>
		<?php else: ?>
		<table class="table table-sm mb-0" style="font-size:0.84rem;">
			<thead><tr style="background:#f1f3f5;"><th>#</th><th>Card / Loan</th><th class="text-end">APR</th><th class="text-end">Balance</th><th class="text-end">Pay this month</th></tr></thead>
			<tbody>
			<?php $n=0; foreach ($cps as $c): $n++; ?>
				<tr<?php echo $c['is_target'] ? ' style="background:#f3effc;"' : ''; ?>>
					<td><?php echo $n; ?></td>
					<td class="fw-semibold"><?php echo htmlspecialchars($c['label']); ?> <?php echo ($c['type'] ?? '') === 'loc' ? '<span class="badge bg-info text-dark" style="font-size:0.54rem;">LOAN</span> ' : ''; ?><?php echo $c['is_target'] ? '<span class="badge bg-primary" style="font-size:0.58rem;">FOCUS</span>' : ''; ?><?php echo $c['paid_off'] ? ' <span class="badge bg-success" style="font-size:0.58rem;">PAID OFF</span>' : ''; ?></td>
					<td class="text-end"><?php echo $c['apr'] !== null ? rtrim(rtrim(number_format($c['apr'],2),'0'),'.').'%' : '—'; ?></td>
					<td class="text-end text-muted"><?php echo money0($c['balance']); ?><?php echo isset($c['available']) && $c['available'] !== null ? '<br><span style="font-size:0.66rem;">'.money0($c['available']).' Avail</span>' : ''; ?></td>
					<td class="text-end fw-bold"><?php echo money($c['amount']); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr class="fw-bold border-top"><td colspan="4">Total to cards &amp; loans this month</td><td class="text-end"><?php echo money(array_sum(array_map(fn($c)=>$c['amount'],$cps))); ?></td></tr></tfoot>
		</table>
		<?php endif; ?>
	</div></div>
	</div>

	<!-- PO → CARD -->
	<div class="col-12 col-lg-5">
	<div class="card h-100" style="border-left:4px solid #2ca01c;"><div class="card-body py-2">
		<h6 class="fw-bold mb-1">Raw-Material POs → Which Card</h6>
		<p class="text-muted small mb-2">POs go on cards. Charge each to the <strong>lowest-APR</strong> card with open credit (cheapest to carry while you pay the high-APR cards down).</p>
		<?php if (empty($monthData['po_card_plan'])): ?>
			<div class="text-muted small">No raw materials below stock level right now.</div>
		<?php else: ?>
		<table class="table table-sm mb-0" style="font-size:0.82rem;">
			<thead><tr style="background:#f1f3f5;"><th>Part</th><th class="text-end">Est. $</th><th>Put on</th></tr></thead>
			<tbody>
			<?php foreach ($monthData['po_card_plan'] as $p): ?>
				<tr>
					<td><?php echo htmlspecialchars($p['part']); ?> <span class="text-muted" style="font-size:0.7rem;">×<?php echo number_format($p['order']); ?></span></td>
					<td class="text-end"><?php echo money0($p['cost']); ?></td>
					<td><?php echo $p['card'] ? '<span class="fw-semibold">'.htmlspecialchars($p['card']).'</span>' : '<span class="text-danger" style="font-size:0.72rem;">'.htmlspecialchars($p['note']).'</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
		<p class="text-muted small mb-0 mt-2"><i class="ti ti-info-circle"></i> These PO's have to be marked paid in the MRP (Orders tab).</p>
	</div></div>
	</div>
</div>

<style>
	.month-drop.drop-hover { outline: 2px dashed #4680ff; outline-offset: -2px; background: #f5f9ff; }
	.cf-drag:hover { background: #f6f8fa; }
</style>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
	<div class="text-muted small"><i class="ti ti-grip-vertical"></i> Tip: drag any manual cash-in/out item (your own events) into another month — the whole plan recalculates.</div>
	<?php if ($hideBefore !== ''): ?><a href="#" id="showHidden" class="small">Show hidden prior month(s)</a><?php endif; ?>
</div>

<!-- ── 12 MONTH BLOCKS ─────────────────────────────────────────────────────── -->
<div class="row g-3">
<?php foreach ($blocks as $b):
	if ($hideBefore !== '' && $b['ym'] < $hideBefore) continue;   // hidden prior month(s)
	$isPast    = !empty($b['is_past']);
	$netColor  = $b['net'] >= 0 ? '#2ca01c' : '#e64545';
	$cashColor = ($b['end_cash'] !== null && $b['end_cash'] < 0) ? '#e64545' : '#2ca01c';
	$topColor  = $isPast ? '#adb5bd' : $cashColor;
	$eff       = $b['actual_income'] !== null ? $b['actual_income'] : ($b['actual_proj'] !== null ? $b['actual_proj'] : (float)$b['suggested']);
	$effSrc    = $b['income_source'];
?>
	<div class="col-12 col-lg-6 col-xxl-4">
	<div class="card h-100 month-drop" data-ym="<?php echo $b['ym']; ?>" style="border-top:3px solid <?php echo $topColor; ?>;<?php echo $isPast ? 'background:#fbfbfc;' : ''; ?>">
	<div class="card-body">
		<div class="d-flex justify-content-between align-items-start mb-2">
			<h5 class="fw-bold mb-0"><?php echo $b['label']; ?><?php echo $isPast ? ' <span class="text-muted" style="font-size:0.58rem;vertical-align:middle;">prior</span>' : ''; ?></h5>
			<div class="text-end">
				<?php if ($isPast): ?><span class="badge bg-secondary" style="font-size:0.58rem;">enter actuals</span> <a href="#" class="hide-month text-danger" data-ym="<?php echo $b['ym']; ?>" style="font-size:0.66rem;" title="Hide this month from the rotation">&times; hide</a>
				<?php else: ?><div class="text-muted" style="font-size:0.66rem;">PROJECTED END CASH</div><div class="fw-bold" style="color:<?php echo $cashColor; ?>;"><?php echo money0($b['end_cash']); ?></div><?php endif; ?>
			</div>
		</div>
		<div class="d-flex justify-content-between align-items-center mb-2 px-2 py-1 rounded" style="background:#f6f8fa;">
			<span class="small"><span class="text-muted">In</span> <span class="fw-bold text-success"><?php echo money0($b['in_total']); ?></span></span>
			<span class="small"><span class="text-muted">Out</span> <span class="fw-bold" style="color:#d9822b;"><?php echo money0($b['out_total']); ?></span></span>
			<span class="small"><span class="text-muted">Net</span> <span class="fw-bold" style="color:<?php echo $netColor; ?>;"><?php echo money0($b['net']); ?></span></span>
		</div>

		<div class="fw-semibold text-uppercase text-success mb-1" style="font-size:0.66rem;letter-spacing:.04em;">Cash In</div>

		<!-- Income tiers: suggested → actual projection → actual income (highest wins) -->
		<div class="px-2 py-1 mb-1" style="background:#f6fbf7;border-radius:5px;">
			<div class="d-flex justify-content-between align-items-center" style="font-size:0.72rem;">
				<span class="text-muted">Suggested projection</span>
				<span class="<?php echo $effSrc==='suggested' ? 'fw-bold text-success' : 'text-muted'; ?>"><?php echo money0($b['suggested']); ?></span>
			</div>
			<div class="d-flex justify-content-between align-items-center mt-1" style="font-size:0.72rem;">
				<span class="text-muted">Actual projection</span>
				<div class="input-group input-group-sm" style="width:115px;"><span class="input-group-text">$</span><input type="text" class="form-control mo-actual" data-ym="<?php echo $b['ym']; ?>" data-field="proj" value="<?php echo $b['actual_proj'] !== null ? number_format($b['actual_proj'],0,'.','') : ''; ?>" placeholder="—" /></div>
			</div>
			<div class="d-flex justify-content-between align-items-center mt-1" style="font-size:0.72rem;">
				<span class="text-muted">Actual income</span>
				<div class="input-group input-group-sm" style="width:115px;"><span class="input-group-text">$</span><input type="text" class="form-control mo-actual" data-ym="<?php echo $b['ym']; ?>" data-field="income" value="<?php echo $b['actual_income'] !== null ? number_format($b['actual_income'],0,'.','') : ''; ?>" placeholder="—" /></div>
			</div>
			<div class="d-flex justify-content-between align-items-center mt-1 pt-1" style="font-size:0.74rem;border-top:1px solid #e3f1e8;">
				<span class="fw-semibold">Using <span class="badge bg-<?php echo $effSrc==='income'?'success':($effSrc==='projection'?'primary':'secondary'); ?>" style="font-size:0.52rem;vertical-align:middle;"><?php echo $effSrc==='income'?'ACTUAL INCOME':($effSrc==='projection'?'ACTUAL PROJ':'SUGGESTED'); ?></span></span>
				<span class="fw-bold text-success"><?php echo money0($eff); ?></span>
			</div>
		</div>

		<?php $otherIn = array_values(array_filter($b['cash_in'], fn($it) => $it['source'] !== 'auto'));
		foreach ($otherIn as $it): ?>
			<div class="d-flex justify-content-between small py-1<?php echo $it['source']==='manual' ? ' cf-drag' : ''; ?>"<?php echo $it['source']==='manual' ? ' draggable="true" data-event-id="'.$it['id'].'" style="border-bottom:1px solid #f1f3f5;cursor:grab;" title="Drag to another month"' : ' style="border-bottom:1px solid #f1f3f5;"'; ?>>
				<span><?php echo htmlspecialchars($it['label']); ?><?php echo ($it['source']==='manual' && $it['week']) ? ' <span class="text-muted" style="font-size:0.68rem;">wk'.$it['week'].'</span>' : ''; ?></span>
				<span><span class="fw-semibold text-success"><?php echo money0($it['amount']); ?></span><?php echo $it['source']==='manual' ? ' <a href="#" class="ev-edit-id ms-1 text-muted" data-id="'.$it['id'].'" style="font-size:0.66rem;">edit</a>' : ''; ?></span>
			</div>
		<?php endforeach; ?>

		<div class="fw-semibold text-uppercase mb-1 mt-2" style="font-size:0.66rem;letter-spacing:.04em;color:#d9822b;">Cash Out</div>
		<?php if (empty($b['cash_out'])): ?><div class="text-muted small mb-1">—</div>
		<?php else: foreach ($b['cash_out'] as $it): $isPaid = !empty($it['paid']); ?>
			<div class="d-flex justify-content-between small py-1<?php echo $it['source']==='manual' ? ' cf-drag' : ''; ?>"<?php echo $it['source']==='manual' ? ' draggable="true" data-event-id="'.$it['id'].'" title="Drag to another month"' : ''; ?> style="border-bottom:1px solid #f1f3f5;<?php echo $it['source']==='manual' ? 'cursor:grab;' : ''; ?><?php echo $isPaid ? 'opacity:.55;' : ''; ?>">
				<span><?php if (!empty($it['payable'])): ?><input type="checkbox" class="cashout-paid" data-ym="<?php echo $b['ym']; ?>" data-key="<?php echo htmlspecialchars($it['key'], ENT_QUOTES); ?>"<?php echo $isPaid ? ' checked' : ''; ?> title="Mark as already paid this month" style="vertical-align:middle;margin-right:4px;"><?php endif; ?><span<?php echo $isPaid ? ' style="text-decoration:line-through;"' : ''; ?>><?php echo htmlspecialchars($it['label']); ?></span><?php echo ($it['source']==='manual' && $it['week']) ? ' <span class="text-muted" style="font-size:0.68rem;">wk'.$it['week'].'</span>' : ''; ?><?php echo $isPaid ? ' <span class="badge bg-success" style="font-size:0.5rem;vertical-align:middle;">PAID</span>' : ''; ?></span>
				<span><span class="fw-semibold" style="color:<?php echo $isPaid ? '#9aa7b0' : '#d9822b'; ?>;<?php echo $isPaid ? 'text-decoration:line-through;' : ''; ?>"><?php echo money0($it['amount']); ?></span><?php echo $it['source']==='manual' ? ' <a href="#" class="ev-edit-id ms-1 text-muted" data-id="'.$it['id'].'" style="font-size:0.66rem;">edit</a>' : ''; ?></span>
			</div>
		<?php endforeach; endif; ?>

		<?php if (!empty($b['credit_out'])): ?>
		<div class="fw-semibold text-uppercase mb-1 mt-2" style="font-size:0.66rem;letter-spacing:.04em;color:#3ea5c9;">On Credit Card <span class="text-muted" style="text-transform:none;letter-spacing:0;font-weight:400;">(tracked, not cash)</span></div>
		<?php foreach ($b['credit_out'] as $it): $isPaid = !empty($it['paid']); ?>
			<div class="d-flex justify-content-between small py-1<?php echo $isPaid ? '' : ' cf-drag'; ?>"<?php echo $isPaid ? '' : ' draggable="true" title="Drag to another month"'; ?> data-event-id="<?php echo $it['id']; ?>" style="border-bottom:1px solid #f1f3f5;<?php echo $isPaid ? 'opacity:.6;' : 'cursor:grab;'; ?>">
				<span><input type="checkbox" class="credit-paid" data-id="<?php echo $it['id']; ?>"<?php echo $isPaid ? ' checked' : ''; ?> title="Mark paid (already on the card balance)" style="vertical-align:middle;"> 💳 <span<?php echo $isPaid ? ' style="text-decoration:line-through;"' : ''; ?>><?php echo htmlspecialchars($it['label']); ?></span><?php echo $it['week'] ? ' <span class="text-muted" style="font-size:0.68rem;">wk'.$it['week'].'</span>' : ''; ?><?php echo $isPaid ? ' <span class="badge bg-success" style="font-size:0.54rem;">PAID</span>' : ''; ?></span>
				<span><span class="fw-semibold" style="color:<?php echo $isPaid ? '#9aa7b0' : '#3ea5c9'; ?>;<?php echo $isPaid ? 'text-decoration:line-through;' : ''; ?>"><?php echo money0($it['amount']); ?></span> <a href="#" class="ev-edit-id ms-1 text-muted" data-id="<?php echo $it['id']; ?>" style="font-size:0.66rem;">edit</a></span>
			</div>
		<?php endforeach; ?>
		<div class="text-muted" style="font-size:0.66rem;"><?php echo money0($b['credit_out_total']); ?> on card (unpaid) — doesn't reduce cash.</div>
		<?php endif; ?>

		<?php if (!empty($b['card_payments'])): ?>
		<div class="mt-2">
			<div class="fw-semibold text-uppercase mb-1" style="font-size:0.6rem;letter-spacing:.04em;color:#6f42c1;">Card &amp; Loan Payments</div>
			<?php foreach ($b['card_payments'] as $c): $isLoanPay = !empty($c['payable']) && !empty($c['key']); $loanPaid = !empty($c['paid']); ?>
			<div class="d-flex justify-content-between align-items-center py-1" style="font-size:0.76rem;border-bottom:1px solid #f3f1fa;<?php echo $loanPaid ? 'opacity:.6;' : ''; ?>">
				<span><?php if ($isLoanPay): ?><input type="checkbox" class="cashout-paid" data-ym="<?php echo $b['ym']; ?>" data-key="<?php echo htmlspecialchars($c['key'], ENT_QUOTES); ?>"<?php echo $loanPaid ? ' checked' : ''; ?> title="Mark this loan payment as cleared this month" style="vertical-align:middle;margin-right:3px;"><?php endif; ?><span<?php echo $loanPaid ? ' style="text-decoration:line-through;"' : ''; ?>><?php echo htmlspecialchars($c['label']); ?></span><?php
					echo ($c['type'] ?? '') === 'loc' ? ' <span class="badge bg-info text-dark" style="font-size:0.5rem;vertical-align:middle;">LOAN</span>' : '';
					echo $c['apr'] !== null ? ' <span class="text-muted" style="font-size:0.66rem;">'.rtrim(rtrim(number_format($c['apr'],2),'0'),'.').'%</span>' : '';
					echo $c['is_target'] ? ' <span class="badge bg-primary" style="font-size:0.54rem;vertical-align:middle;">FOCUS</span>' : '';
					echo $c['paid_off'] ? ' <span class="badge bg-success" style="font-size:0.54rem;vertical-align:middle;">PAID OFF</span>' : '';
					echo $loanPaid ? ' <span class="badge bg-success" style="font-size:0.5rem;vertical-align:middle;">CLEARED</span>' : '';
				?><br><span class="text-muted" style="font-size:0.66rem;">Bal <?php echo money0($c['balance']); ?><?php echo isset($c['available']) && $c['available'] !== null ? ' · '.money0($c['available']).' Avail' : ''; ?><?php echo !empty($c['due_day']) ? ' · due the '.(int)$c['due_day'].date('S', mktime(0,0,0,1,(int)$c['due_day'],2000)) : ''; ?></span></span>
				<span class="text-end">
					<?php if ($c['amount'] > 0): ?><span class="fw-semibold" style="color:#6f42c1;<?php echo $loanPaid ? 'text-decoration:line-through;' : ''; ?>"><?php echo money0($c['amount']); ?></span><?php elseif ($loanPaid && !empty($c['scheduled'])): ?><span class="text-muted" style="font-size:0.66rem;text-decoration:line-through;"><?php echo money0($c['scheduled']); ?></span><?php elseif (!empty($c['via_draw'])): ?><span class="fw-semibold" style="color:#6f42c1;"><?php echo money0($c['via_draw']); ?></span><br><span class="text-muted" style="font-size:0.6rem;">via % of sales</span><?php else: ?><span class="text-muted" style="font-size:0.66rem;">no pay</span><?php endif; ?>
				</span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<?php if ($b['tax_setaside'] > 0 || $b['tax_reserve'] > 0): ?>
		<div class="small text-muted" style="font-size:0.7rem;">Tax reserve: <strong><?php echo money0($b['tax_reserve']); ?></strong><?php echo $b['tax_payment'] > 0 ? ' · <span style="color:#d9822b;">paid '.money0($b['tax_payment']).' (quarter-end)</span>' : ''; ?></div>
		<?php endif; ?>

		<?php if (!empty($b['advice'])): ?>
		<div class="mt-2 p-2 rounded" style="background:#f8f9fb;">
			<div class="fw-semibold text-uppercase text-muted mb-1" style="font-size:0.62rem;letter-spacing:.04em;">Suggestions</div>
			<?php foreach ($b['advice'] as $adv):
				$ac = $adv['kind']==='warn' ? '#e64545' : ($adv['kind']==='good' ? '#2ca01c' : '#4680ff'); ?>
				<div class="small mb-1" style="line-height:1.3;"><span style="color:<?php echo $ac; ?>;">●</span> <?php echo htmlspecialchars($adv['text']); ?></div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<button class="btn btn-sm btn-light-primary mt-2 add-event-for" data-ym="<?php echo $b['ym']; ?>" style="font-size:0.72rem;">+ Add cash in/out for <?php echo date('M', strtotime($b['ym'].'-01')); ?></button>
		<div class="text-muted text-end mt-1" style="font-size:0.66rem;">End debt: <?php echo money0($b['end_debt']); ?></div>
	</div>
	</div>
	</div>
<?php endforeach; ?>
</div>

<!-- ── MORE DETAIL (collapsed) ─────────────────────────────────────────────── -->
<details class="mt-3">
	<summary class="btn btn-sm btn-light-secondary">▸ Pay planner, bills, POs, receivables &amp; Shopify-vs-QuickBooks reconciliation</summary>
	<div class="mt-2">
		<div class="row g-3">
			<div class="col-12 col-lg-6"><div class="card"><div class="card-body">
				<h6 class="fw-bold mb-2">Open Bills <span class="text-muted fw-normal small">(QuickBooks)</span></h6>
				<?php if ($data['bills']['error']): ?><div class="text-danger small"><?php echo htmlspecialchars($data['bills']['error']); ?></div>
				<?php elseif (empty($data['bills']['items'])): ?><div class="text-muted small">No open bills.</div>
				<?php else: ?><table class="table table-sm mb-0"><tbody><?php foreach ($data['bills']['items'] as $bb): ?><tr><td class="small"><?php echo htmlspecialchars($bb['vendor']); ?><br><span class="text-muted" style="font-size:0.7rem;">due <?php echo fdate($bb['due']); ?></span></td><td class="small text-end fw-semibold"><?php echo money($bb['balance']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
			</div></div></div>
			<div class="col-12 col-lg-6"><div class="card"><div class="card-body">
				<h6 class="fw-bold mb-2">Unpaid Purchase Orders <span class="text-muted fw-normal small">(MRP)</span></h6>
				<?php if (empty($data['pos']['items'])): ?><div class="text-muted small">No unpaid POs.</div>
				<?php else: ?><table class="table table-sm mb-0"><tbody><?php foreach ($data['pos']['items'] as $p): ?><tr><td class="small"><strong><?php echo htmlspecialchars($p['supplier']); ?></strong> <span class="text-muted" style="font-size:0.7rem;">· <?php echo htmlspecialchars($p['ref']); ?></span></td><td class="small text-end fw-semibold"><?php echo money($p['balance']); ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
			</div></div></div>
		</div>
		<div class="card mt-3"><div class="card-body">
			<h6 class="fw-bold mb-1">Sales vs. Income — last 12 months</h6>
			<p class="text-muted small mb-2"><strong>Shopify net sales</strong> are booked on the order date; <strong>QuickBooks income</strong> (cash basis) is money actually received. Gaps = terms, discounts, taxes, returns.</p>
			<?php if ($forecast['qb_error']): ?><div class="text-muted small mb-2">QuickBooks income unavailable: <?php echo htmlspecialchars($forecast['qb_error']); ?></div><?php endif; ?>
			<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:0.82rem;">
				<thead><tr style="background:#f1f3f5;"><th class="text-muted">Month</th><th class="text-muted text-end">Shopify sales</th><th class="text-muted text-end">QB income</th><th class="text-muted text-end">Diff</th></tr></thead>
				<tbody><?php $tShop=0;$tQb=0; foreach ($forecast['reconcile'] as $r): $tShop+=(float)$r['shopify'];$tQb+=(float)$r['qb']; ?>
					<tr><td><?php echo $r['label']; ?></td><td class="text-end"><?php echo $r['shopify']===null?'—':money($r['shopify']); ?></td><td class="text-end"><?php echo $r['qb']===null?'<span class="text-muted">n/a</span>':money($r['qb']); ?></td><td class="text-end" style="color:<?php echo ($r['diff']!==null&&abs($r['diff'])>0.01)?'#d9822b':'#888'; ?>;"><?php echo $r['diff']===null?'—':money($r['diff']); ?></td></tr>
				<?php endforeach; ?></tbody>
				<tfoot><tr class="fw-bold border-top"><td>Total</td><td class="text-end"><?php echo money($tShop); ?></td><td class="text-end"><?php echo money($tQb); ?></td><td class="text-end"><?php echo money($tShop-$tQb); ?></td></tr></tfoot>
			</table></div>
		</div></div>
	</div>
</details>

<script>
	// ── Balances ──
	function balShowForm(s){ $('#balForm').toggleClass('hidden', !s); }
	$('#balType').on('change', function(){ var t=$(this).val(); $('#balAprWrap').toggle(t!=='bank'); $('#balLimitWrap').toggle(t==='credit'); $('#balPayWrap').toggle(t==='loc'); $('#balLocWrap').toggle(t==='loc'); $('#balDueWrap').toggle(t==='loc'); });
	$('#balQbAccount').on('change', function(){ var v=$(this).val(), $o=$(this).find('option:selected'); if(v==='')return; if(v==='__manual__'){ $('#balQbId').val(''); $('#balLabel').val('').focus(); return; } $('#balQbId').val(v); $('#balLabel').val($o.data('name')); $('#balType').val($o.data('type')).trigger('change'); if(!$('#balAmount').val()) $('#balAmount').val(Math.abs(parseFloat($o.data('balance'))||0).toFixed(2)); });
	$('#addBalBtn').on('click', function(){ $('#balId,#balQbId,#balLabel,#balAmount,#balLimit,#balPayment,#balApr,#balNote,#balLoc,#balDueDay').val(''); $('#balQbAccount').val(''); $('#balType').val('bank').trigger('change'); $('#balAsOf').val('<?php echo date('Y-m-d'); ?>'); $('#balMsg').text(''); balShowForm(true); });
	$('#balCancelBtn').on('click', function(){ balShowForm(false); });
	$(document).on('click', '.bal-edit', function(e){ e.preventDefault(); var $r=$(this).closest('.bal-row'); $('#balId').val($r.data('id')); $('#balQbId').val($r.data('qbid')||''); $('#balQbAccount').val($r.data('qbid')||''); $('#balLabel').val($r.data('label')); $('#balType').val($r.data('type')).trigger('change'); $('#balAmount').val($r.data('balance')); $('#balLimit').val($r.data('limit')||''); $('#balPayment').val($r.data('payment')||''); $('#balApr').val($r.data('apr')||''); $('#balLoc').val($r.data('locname')||''); $('#balDueDay').val($r.attr('data-dueday')||''); $('#balAsOf').val($r.data('asof')||''); $('#balNote').val($r.data('note')||''); $('#balMsg').text(''); balShowForm(true); });
	// Quick weekly update: prefill everything, set the date to TODAY, focus the amount.
	$(document).on('click', '.bal-update', function(e){ e.preventDefault(); $('#manageDetails').attr('open','open'); var $r=$(this).closest('.bal-row'); $('#balId').val($r.data('id')); $('#balQbId').val($r.data('qbid')||''); $('#balQbAccount').val($r.data('qbid')||''); $('#balLabel').val($r.data('label')); $('#balType').val($r.data('type')).trigger('change'); $('#balAmount').val($r.data('balance')); $('#balLimit').val($r.data('limit')||''); $('#balPayment').val($r.data('payment')||''); $('#balApr').val($r.data('apr')||''); $('#balLoc').val($r.data('locname')||''); $('#balDueDay').val($r.attr('data-dueday')||''); $('#balAsOf').val('<?php echo date('Y-m-d'); ?>'); $('#balNote').val($r.data('note')||''); $('#balMsg').text(''); balShowForm(true); $('html,body').animate({scrollTop:$('#balForm').offset().top-90},200); $('#balAmount').focus().select(); });
	$('#openBalances').on('click', function(){ $('#manageDetails').attr('open','open'); $('html,body').animate({scrollTop:$('#manageDetails').offset().top-90},200); });
	$('#balSaveBtn').on('click', function(){ var $btn=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_balance.php', { id:$('#balId').val(), label:$('#balLabel').val(), acct_type:$('#balType').val(), balance:$('#balAmount').val(), credit_limit:$('#balLimit').val(), monthly_payment:$('#balPayment').val(), apr:$('#balApr').val(), qb_account_id:$('#balQbId').val(), as_of:$('#balAsOf').val(), note:$('#balNote').val(), loc_name:$('#balLoc').val(), due_day:$('#balDueDay').val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#balMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#balMsg').addClass('text-danger').text('Save failed: '+(x.responseText||x.status)); $btn.prop('disabled',false); }); });
	$(document).on('click', '.bal-del', function(e){ e.preventDefault(); if(!confirm('Remove this account balance?'))return; $.post('/ajax/cashflow/delete_balance.php', { id:$(this).closest('.bal-row').data('id') }, function(resp){ if($.trim(resp)==='ok') location.reload(); else alert(resp); }); });
	// Assign a LOC loan to its facility (QuickBooks / Shopify) in one click — the top-of-page LOC availability then reflects it.
	$(document).on('change', '.bal-loc-inline', function(){ var $sel=$(this).prop('disabled',true), $r=$sel.closest('.bal-row'); $.post('/ajax/cashflow/save_balance.php', { id:$r.data('id'), label:$r.attr('data-label'), acct_type:$r.attr('data-type'), balance:$r.attr('data-balance'), credit_limit:$r.attr('data-limit')||'', monthly_payment:$r.attr('data-payment')||'', apr:$r.attr('data-apr')||'', qb_account_id:$r.attr('data-qbid')||'', as_of:$r.attr('data-asof')||'', note:$r.attr('data-note')||'', loc_name:$sel.val(), due_day:$r.attr('data-dueday')||'' }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { alert(resp); $sel.prop('disabled',false); } }).fail(function(){ alert('Save failed'); $sel.prop('disabled',false); }); });

	// ── Recurring expenses ──
	function expShowForm(s){ $('#expForm').toggleClass('hidden', !s); }
	$('#addExpBtn').on('click', function(){ $('#expId,#expLabel,#expAmount,#expCategory').val(''); $('#expMsg').text(''); expShowForm(true); });
	$('#expCancelBtn').on('click', function(){ expShowForm(false); });
	$(document).on('click', '.exp-edit', function(e){ e.preventDefault(); var $r=$(this).closest('.exp-row'); $('#expId').val($r.data('id')); $('#expLabel').val($r.data('label')); $('#expAmount').val($r.data('amount')); $('#expCategory').val($r.data('category')||''); $('#expMsg').text(''); expShowForm(true); });
	$('#expSaveBtn').on('click', function(){ var $btn=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_expense.php', { id:$('#expId').val(), label:$('#expLabel').val(), amount:$('#expAmount').val(), category:$('#expCategory').val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#expMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#expMsg').addClass('text-danger').text('Save failed: '+(x.responseText||x.status)); $btn.prop('disabled',false); }); });
	$(document).on('click', '.exp-del', function(e){ e.preventDefault(); if(!confirm('Remove this expense?'))return; $.post('/ajax/cashflow/delete_expense.php', { id:$(this).closest('.exp-row').data('id') }, function(resp){ if($.trim(resp)==='ok') location.reload(); else alert(resp); }); });

	// ── Cash events ──
	var EVENTS = <?php echo json_encode($events['all']); ?>;
	function evShowForm(s){ $('#evForm').toggleClass('hidden', !s); if(s){ $('details').first().attr('open','open'); } }
	$('#evType').on('change', function(){ var isOut=$(this).val()==='out'; $('#evCardWrap').toggle(isOut); $('#evPaidWrap').toggle(isOut && $('#evCard').is(':checked')); });
	$('#evCard').on('change', function(){ $('#evPaidWrap').toggle($('#evType').val()==='out' && $(this).is(':checked')); });
	$('#addEvBtn').on('click', function(){ $('#evId,#evLabel,#evAmount').val(''); $('#evType').val('out'); $('#evWeek').val('1'); $('#evCard').prop('checked', false); $('#evPaid').prop('checked', false); $('#evCardWrap').show(); $('#evPaidWrap').hide(); $('#evMsg').text(''); evShowForm(true); });
	$('#evCancelBtn').on('click', function(){ evShowForm(false); });
	function evEdit(id){ var e=EVENTS.filter(function(x){return x.id==id;})[0]; if(!e)return; $('#evId').val(e.id); $('#evType').val(e.etype); $('#evLabel').val(e.label); $('#evAmount').val(e.amount); $('#evMonth').val(e.ym); $('#evWeek').val(e.week); $('#evCard').prop('checked', e.paidby==='card'); $('#evPaid').prop('checked', e.paid==1); $('#evCardWrap').toggle(e.etype==='out'); $('#evPaidWrap').toggle(e.etype==='out' && e.paidby==='card'); $('#evMsg').text(''); evShowForm(true); $('html,body').animate({scrollTop:$('#evForm').offset().top-90},200); }
	$(document).on('click', '.ev-edit', function(e){ e.preventDefault(); evEdit($(this).closest('.ev-row').data('id')); });
	$(document).on('click', '.ev-edit-id', function(e){ e.preventDefault(); evEdit($(this).data('id')); });
	$(document).on('click', '.add-event-for', function(){ $('#addEvBtn').click(); $('#evMonth').val($(this).data('ym')); $('html,body').animate({scrollTop:$('#evForm').offset().top-90},200); $('#evLabel').focus(); });
	$('#evSaveBtn').on('click', function(){ var $btn=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_event.php', { id:$('#evId').val(), etype:$('#evType').val(), label:$('#evLabel').val(), amount:$('#evAmount').val(), ym:$('#evMonth').val(), week:$('#evWeek').val(), paidby:(($('#evType').val()==='out' && $('#evCard').is(':checked'))?'card':'cash'), paid:(($('#evCard').is(':checked') && $('#evPaid').is(':checked'))?1:0) }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#evMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#evMsg').addClass('text-danger').text('Save failed: '+(x.responseText||x.status)); $btn.prop('disabled',false); }); });
	$(document).on('click', '.ev-del', function(e){ e.preventDefault(); if(!confirm('Remove this cash event?'))return; $.post('/ajax/cashflow/delete_event.php', { id:$(this).closest('.ev-row').data('id') }, function(resp){ if($.trim(resp)==='ok') location.reload(); else alert(resp); }); });
	// Mark a credit-card item paid (already reflected in the weekly card balance).
	$(document).on('change', '.credit-paid', function(){ var $c=$(this); $c.prop('disabled',true); $.post('/ajax/cashflow/set_event_paid.php', { id:$c.data('id'), paid:$c.is(':checked')?1:0 }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { alert(resp); $c.prop('disabled',false); } }).fail(function(){ alert('Save failed'); $c.prop('disabled',false); }); });
	// Mark a monthly cash-out line already paid (money has left the bank this month).
	$(document).on('change', '.cashout-paid', function(){ var $c=$(this); $c.prop('disabled',true); $.post('/ajax/cashflow/toggle_cashout_paid.php', { ym:$c.data('ym'), line_key:$c.data('key'), paid:$c.is(':checked')?1:0 }, function(resp){ if(resp&&resp.ok) location.reload(); else { alert((resp&&resp.error)||'Save failed'); $c.prop('disabled',false); } }, 'json').fail(function(){ alert('Save failed'); $c.prop('disabled',false); }); });

	// ── Reconcile Today: set real balances + tick what already happened this month ──
	$('#reconcileBtn').on('click', function(){ new bootstrap.Modal(document.getElementById('reconcileModal')).show(); });
	$('#recSaveBtn').on('click', function(){
		var $b=$(this).prop('disabled',true); $('#recMsg').text('');
		var balances=[]; $('.rec-bal').each(function(){ balances.push({ id:$(this).data('id'), balance:($(this).val()||'').replace(/[^0-9.\-]/g,'') }); });
		var received=[]; $('.rec-in:checked').each(function(){ received.push($(this).val()); });
		var paid=[]; $('.rec-out:checked').each(function(){ paid.push($(this).val()); });
		$.post('/ajax/cashflow/reconcile.php', { ym:'<?php echo $thisMonth ? $thisMonth['ym'] : date('Y-m'); ?>', balances:JSON.stringify(balances), received:JSON.stringify(received), paid:JSON.stringify(paid) }, function(resp){ if(resp&&resp.ok) location.reload(); else { $('#recMsg').text((resp&&resp.error)||'Save failed.'); $b.prop('disabled',false); } }, 'json')
			// Never swallow the reason: show whatever the server actually said, so a
			// broken save is diagnosable instead of a bare "Save failed".
			.fail(function(xhr){
				var msg = (xhr.responseJSON && xhr.responseJSON.error) || '';
				if (!msg && xhr.responseText) { var t = $('<div>').html(xhr.responseText).text().replace(/\s+/g,' ').trim(); if (t) msg = t.slice(0,300); }
				$('#recMsg').text('Save failed (HTTP ' + xhr.status + ')' + (msg ? ': ' + msg : '. No response from the server.'));
				$b.prop('disabled',false);
			});
	});

	// ── Hide / show prior month(s) ──
	$(document).on('click', '.hide-month', function(e){ e.preventDefault(); $.post('/ajax/cashflow/save_settings.php', { cashflow_hide_before: $(this).data('ym') }, function(){ location.reload(); }); });
	$('#showHidden').on('click', function(e){ e.preventDefault(); $.post('/ajax/cashflow/save_settings.php', { cashflow_hide_before: 'reset' }, function(){ location.reload(); }); });

	// ── Per-month actual projection / actual income ──
	$(document).on('change', '.mo-actual', function(){ var $i=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_month_actual.php', { ym:$(this).data('ym'), field:$(this).data('field'), value:$(this).val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { alert(resp); $i.prop('disabled',false); } }).fail(function(){ alert('Save failed'); $i.prop('disabled',false); }); });

	// ── Receivables: expected payment date ──
	$(document).on('change', '.ar-date', function(){ var $i=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_ar_date.php', { order_key:$(this).data('key'), date:$(this).val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { alert(resp); $i.prop('disabled',false); } }).fail(function(){ alert('Save failed'); $i.prop('disabled',false); }); });

	// ── Drag a manual cash event to another month (recalculates everything) ──
	var cfDragId = null;
	$(document).on('dragstart', '.cf-drag', function(e){ cfDragId = $(this).data('event-id'); try { e.originalEvent.dataTransfer.setData('text/plain', String(cfDragId)); e.originalEvent.dataTransfer.effectAllowed='move'; } catch(_){} $(this).css('opacity','0.4'); });
	$(document).on('dragend', '.cf-drag', function(){ $(this).css('opacity',''); $('.month-drop').removeClass('drop-hover'); });
	$(document).on('dragover', '.month-drop', function(e){ e.preventDefault(); $(this).addClass('drop-hover'); });
	$(document).on('dragleave', '.month-drop', function(){ $(this).removeClass('drop-hover'); });
	$(document).on('drop', '.month-drop', function(e){ e.preventDefault(); $(this).removeClass('drop-hover'); var ym=$(this).data('ym'); var id=cfDragId; cfDragId=null; if(!id){ try{ id=e.originalEvent.dataTransfer.getData('text/plain'); }catch(_){} } if(!id) return; var ev=(EVENTS||[]).filter(function(x){return String(x.id)===String(id);})[0]; if(!ev||ev.ym===ym) return; $.post('/ajax/cashflow/save_event.php', { id:ev.id, etype:ev.etype, label:ev.label, amount:ev.amount, ym:ym, week:ev.week, paidby:(ev.paidby||'cash') }, function(resp){ if($.trim(resp)==='ok') location.reload(); else alert(resp); }); });

	// ── Manual sync (refresh the QuickBooks + Shopify cache) ──
	$('#syncBtn').on('click', function(){
		var $btn = $(this).prop('disabled', true);
		$('#syncLabel').text('Refreshing from QuickBooks & Shopify…');
		$.ajax({ url: '/ajax/cashflow/sync.php', method: 'POST', dataType: 'json', timeout: 180000 })
			.done(function(d){ if (d && d.ok) { location.reload(); } else { $('#syncLabel').text(d && d.error ? d.error : 'Sync failed.'); $btn.prop('disabled', false); } })
			.fail(function(xhr, status){ $('#syncLabel').text(status === 'timeout' ? 'Sync timed out — try again.' : 'Sync failed.'); $btn.prop('disabled', false); });
	});

	// ── Planning settings (loan %, cash buffer, monthly tax) ──
	function locCeilTemplate(){ return '<div class="d-flex gap-1 mb-1 align-items-center loc-ceil-row"><input type="text" class="form-control form-control-sm loc-ceil-name" placeholder="LOC name" style="max-width:150px;"><div class="input-group input-group-sm" style="max-width:130px;"><span class="input-group-text">$</span><input type="text" class="form-control loc-ceil-amt" placeholder="ceiling"></div><a href="#" class="loc-ceil-del text-danger" title="Remove">×</a></div>'; }
	$('#locCeilAdd').on('click', function(){ $('#locCeilList').append(locCeilTemplate()); });
	$(document).on('click', '.loc-ceil-del', function(e){ e.preventDefault(); $(this).closest('.loc-ceil-row').remove(); });
	$('#loanSaveBtn').on('click', function(){
		var $btn=$(this).prop('disabled',true);
		var locCeils = [];
		$('#locCeilList .loc-ceil-row').each(function(){ var n=$.trim($(this).find('.loc-ceil-name').val()); var a=parseFloat(($(this).find('.loc-ceil-amt').val()||'').replace(/[^0-9.\-]/g,''))||0; if(n) locCeils.push({name:n, ceiling:a}); });
		$.post('/ajax/cashflow/save_settings.php', { shopify_loan_pct:$('#loanPct').val(), cash_buffer:$('#cashBuffer').val(), tax_monthly:$('#taxMonthly').val(), loc_ceilings:JSON.stringify(locCeils), card_min_pct:$('#cardMinPct').val(), card_min_floor:$('#cardMinFloor').val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#loanMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#loanMsg').addClass('text-danger').text('Failed'); $btn.prop('disabled',false); }); });

	// ── AI Cash Flow Assistant (saved & resumable) ──
	var cfMsgs = [], cfChatId = 0, cfHistLoaded = false;
	function cfOpenPanel(){ var p=$('#cfChatPanel'); if(p.hasClass('hidden')){ p.removeClass('hidden'); $('#cfChatToggle').text('Close'); } }
	$('#cfChatToggle').on('click', function(){ var p=$('#cfChatPanel'); p.toggleClass('hidden'); $(this).text(p.hasClass('hidden')?'Open':'Close'); if(!p.hasClass('hidden')){ $('#cfChatInput').focus(); if(!cfHistLoaded) cfLoadHistory(); } });
	function cfEsc(s){ return $('<div>').text(s==null?'':String(s)).html(); }
	function cfRender(){ var h=''; cfMsgs.forEach(function(m){ var who=m.role==='user'?'You':'Assistant'; var col=m.role==='user'?'#eef2f7':'#fff8f3'; h+='<div class="mb-2 p-2 rounded" style="background:'+col+';"><div class="fw-semibold small text-muted">'+who+'</div><div>'+cfEsc(m.content).replace(/\n/g,'<br>')+'</div></div>'; }); var $m=$('#cfChatMsgs').html(h); if($m[0]) $m.scrollTop($m[0].scrollHeight); $('#cfChatDelete').toggleClass('hidden', cfChatId<=0); }
	function cfLoadHistory(){ cfHistLoaded=true; $.getJSON('/ajax/cashflow/chat_list.php', function(d){ var o='<option value="">History…</option>'; (d.chats||[]).forEach(function(c){ o+='<option value="'+c.id+'"'+(c.id==cfChatId?' selected':'')+'>'+cfEsc(c.title)+'</option>'; }); $('#cfChatHistory').html(o); }); }
	$('#cfChatHistory').on('change', function(){ var id=parseInt($(this).val(),10); if(!id){ return; } $.post('/ajax/cashflow/chat_get.php',{id:id},function(d){ if(d.error){ alert(d.error); return; } cfChatId=d.id; cfMsgs=d.messages||[]; $('#cfChatActions').addClass('hidden').html(''); window._cfActions=null; cfRender(); cfOpenPanel(); },'json'); });
	$('#cfChatNew').on('click', function(){ cfChatId=0; cfMsgs=[]; $('#cfChatHistory').val(''); $('#cfChatActions').addClass('hidden').html(''); window._cfActions=null; cfRender(); cfOpenPanel(); $('#cfChatInput').focus(); });
	$('#cfChatDelete').on('click', function(e){ e.preventDefault(); if(!cfChatId||!confirm('Delete this saved chat?')) return; $.post('/ajax/cashflow/chat_delete.php',{id:cfChatId},function(){ cfChatId=0; cfMsgs=[]; cfRender(); cfLoadHistory(); }); });
	function cfSend(){ var t=($('#cfChatInput').val()||'').trim(); if(!t) return; $('#cfChatInput').val(''); cfMsgs.push({role:'user',content:t}); cfRender(); $('#cfChatActions').addClass('hidden').html(''); window._cfActions=null; var $b=$('#cfChatSend').prop('disabled',true).text('…'); $.ajax({url:'/ajax/cashflow/chat.php',method:'POST',dataType:'json',timeout:120000,data:{messages:JSON.stringify(cfMsgs), chat_id:cfChatId}}).done(function(d){ if(!d||d.error){ cfMsgs.push({role:'assistant',content:'⚠ '+((d&&d.error)||'failed')}); cfRender(); return; } cfMsgs.push({role:'assistant',content:d.reply||'(no reply)'}); if(d.chat_id){ cfChatId=d.chat_id; } cfRender(); cfLoadHistory(); if(d.actions&&d.actions.length) cfShowActions(d.actions); }).fail(function(x,s){ cfMsgs.push({role:'assistant',content:'⚠ '+(s==='timeout'?'timed out — try again':'request failed')}); cfRender(); }).always(function(){ $b.prop('disabled',false).text('Send'); }); }
	$('#cfChatSend').on('click', cfSend);
	$('#cfChatInput').on('keypress', function(e){ if(e.which===13) cfSend(); });
	function cfActionText(a){ var w=a.why?(' — '+a.why):''; switch(a.type){
		case 'mark_cards_paid': return 'Mark card payments DONE for '+a.ym+' (skip the avalanche there)'+w;
		case 'unmark_cards_paid': return 'Un-mark card payments for '+a.ym+w;
		case 'set_month_actual': return 'Set '+a.ym+' actual '+(a.field==='income'?'income':'projection')+' = '+(a.value==null?'(clear)':'$'+Number(a.value).toLocaleString())+w;
		case 'update_balance': return 'Update “'+a.label+'” balance to $'+Number(a.balance).toLocaleString()+(a.as_of?(' as of '+a.as_of):'')+(a.apr!=null?(', APR '+a.apr+'%'):'')+(a.min!=null?(', min $'+a.min):'')+w;
		case 'add_event': return 'Add cash '+(a.etype==='in'?'IN':'OUT')+' “'+a.label+'” $'+Number(a.amount).toLocaleString()+((a.etype==='out'&&a.paidby==='card')?' (on credit card — not cash)':'')+' to '+a.ym+' wk'+(a.week||1)+w;
		case 'delete_event': return 'Delete cash event #'+a.id+w;
		case 'set_setting': return 'Set '+a.key+' = '+a.value+w;
		case 'set_receivable_date': return 'Set receivable '+a.order+' expected '+(a.date||'(cleared)')+w;
		case 'add_recurring_expense': return 'Add recurring expense “'+a.label+'” $'+Number(a.amount).toLocaleString()+'/mo'+w;
		default: return JSON.stringify(a);
	}}
	function cfShowActions(actions){ window._cfActions=actions; var h='<div class="fw-semibold small mb-1">⚠ Proposed changes — review and approve:</div>'; actions.forEach(function(a){ h+='<div class="small mb-1">• '+cfEsc(cfActionText(a))+'</div>'; }); h+='<div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-success" id="cfApply">Apply changes</button><button class="btn btn-sm btn-secondary" id="cfCancelAct">Cancel</button><span id="cfApplyMsg" class="small ms-1"></span></div>'; $('#cfChatActions').html(h).removeClass('hidden'); }
	$(document).on('click','#cfApply',function(){ var $b=$(this).prop('disabled',true).text('Applying…'); $.ajax({url:'/ajax/cashflow/apply.php',method:'POST',dataType:'json',data:{actions:JSON.stringify(window._cfActions||[])}}).done(function(d){ if(d&&d.ok){ location.reload(); } else { $('#cfApplyMsg').addClass('text-danger').text((d&&d.error)||'failed'); $b.prop('disabled',false).text('Apply changes'); } }).fail(function(){ $('#cfApplyMsg').addClass('text-danger').text('Apply failed'); $b.prop('disabled',false).text('Apply changes'); }); });
	$(document).on('click','#cfCancelAct',function(){ $('#cfChatActions').addClass('hidden').html(''); window._cfActions=null; });
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
