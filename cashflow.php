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
	$loanPct  = $monthData['loan_pct'];
	$syncedAt = cf_synced_at($db);

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
		<h2 class="fw-bold mb-0">Cash Flow<?php echo $data['qb_company'] ? ' <span class="text-muted fw-normal" style="font-size:0.6em;">· '.htmlspecialchars($data['qb_company']).'</span>' : ''; ?></h2>
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
		<div class="text-muted" style="font-size:0.68rem;"><?php echo $data['manual']['credit_limit_total'] > 0 ? money($data['manual']['credit_available']).' available' : 'outstanding debt'; ?></div>
	</div></div></div>
	<div class="col-6 col-lg-3"><div class="card h-100" style="border-left:4px solid <?php echo $data['net_quick'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><div class="card-body py-2">
		<div class="text-muted text-uppercase fw-semibold" style="font-size:0.64rem;letter-spacing:.04em;">Net Position</div>
		<div class="h5 fw-bold mb-0" style="color:<?php echo $data['net_quick'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><?php echo money($data['net_quick']); ?></div>
		<div class="text-muted" style="font-size:0.68rem;">Cash + owed − you owe</div>
	</div></div></div>
</div>

<?php if ($balDue > 0): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
	<div>⚠ <strong><?php echo $balDue; ?> account balance<?php echo $balDue === 1 ? '' : 's'; ?></strong> need the weekly update (older than <?php echo $updDays; ?> days). Keeping balances current keeps the whole forecast accurate.</div>
	<button class="btn btn-sm btn-warning" id="openBalances">Update now</button>
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
					<div class="col-6" id="balPayWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">Pay/mo $</span><input type="text" id="balPayment" class="form-control" placeholder="Monthly payment" /></div></div>
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
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small bal-row" data-id="<?php echo $a['id']; ?>" data-label="<?php echo htmlspecialchars($a['label'], ENT_QUOTES); ?>" data-type="<?php echo $a['type']; ?>" data-balance="<?php echo $a['balance']; ?>" data-limit="<?php echo $a['limit']; ?>" data-payment="<?php echo $a['payment']; ?>" data-apr="<?php echo $a['apr'] !== null ? $a['apr'] : ''; ?>" data-qbid="<?php echo htmlspecialchars((string)$a['qb_id'], ENT_QUOTES); ?>" data-asof="<?php echo $a['as_of']; ?>" data-note="<?php echo htmlspecialchars((string)$a['note'], ENT_QUOTES); ?>">
					<span><?php echo htmlspecialchars($a['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo htmlspecialchars($a['kind']); ?><?php echo $a['apr'] !== null ? ' · '.rtrim(rtrim(number_format($a['apr'],2),'0'),'.').'% APR' : ''; ?><?php echo $a['payment'] > 0 ? ' · '.money($a['payment']).'/mo' : ''; ?></span><?php echo bal_badge($a); ?></span>
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
					<div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary" id="evSaveBtn">Save</button><button class="btn btn-sm btn-secondary" id="evCancelBtn">Cancel</button><span id="evMsg" class="small ms-1"></span></div>
				</div>
			</div>
			<?php if (empty($events['all'])): ?><div class="text-muted small mb-2">No cash events yet.</div>
			<?php else: foreach ($events['all'] as $e): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small ev-row" data-id="<?php echo $e['id']; ?>" data-etype="<?php echo $e['etype']; ?>" data-label="<?php echo htmlspecialchars($e['label'], ENT_QUOTES); ?>" data-amount="<?php echo $e['amount']; ?>" data-ym="<?php echo $e['ym']; ?>" data-week="<?php echo $e['week']; ?>">
					<span><span class="badge <?php echo $e['etype']==='in'?'bg-success':'bg-warning text-dark'; ?>" style="font-size:0.6rem;"><?php echo $e['etype']==='in'?'IN':'OUT'; ?></span> <?php echo htmlspecialchars($e['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo date('M Y', strtotime($e['ym'].'-01')); ?> wk<?php echo $e['week']; ?></span></span>
					<span><span class="fw-semibold"><?php echo money($e['amount']); ?></span> <a href="#" class="ev-edit ms-1" style="font-size:0.7rem;">edit</a> <a href="#" class="ev-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; endif; ?>

			<hr class="my-2">
			<div class="fw-semibold small text-muted mb-1">Shopify loan (% of sales → cash out)</div>
			<div class="d-flex gap-2 align-items-center">
				<div class="input-group input-group-sm" style="width:120px;"><input type="text" id="loanPct" class="form-control" value="<?php echo rtrim(rtrim(number_format($loanPct,2),'0'),'.'); ?>" /><span class="input-group-text">%</span></div>
				<button class="btn btn-sm btn-primary" id="loanSaveBtn">Save</button>
				<span id="loanMsg" class="small"></span>
			</div>
			<div class="text-muted" style="font-size:0.7rem;">25% repays the Shopify Capital loan. Set to 0 once it's paid off.</div>
		</div></div></div>

	</div>
</details>

<!-- ── 12 MONTH BLOCKS ─────────────────────────────────────────────────────── -->
<div class="row g-3">
<?php foreach ($blocks as $b):
	$netColor  = $b['net'] >= 0 ? '#2ca01c' : '#e64545';
	$cashColor = $b['end_cash'] >= 0 ? '#2ca01c' : '#e64545';
?>
	<div class="col-12 col-lg-6 col-xxl-4">
	<div class="card h-100" style="border-top:3px solid <?php echo $cashColor; ?>;">
	<div class="card-body">
		<div class="d-flex justify-content-between align-items-start mb-2">
			<h5 class="fw-bold mb-0"><?php echo $b['label']; ?></h5>
			<div class="text-end"><div class="text-muted" style="font-size:0.66rem;">PROJECTED END CASH</div><div class="fw-bold" style="color:<?php echo $cashColor; ?>;"><?php echo money0($b['end_cash']); ?></div></div>
		</div>
		<div class="d-flex justify-content-between align-items-center mb-2 px-2 py-1 rounded" style="background:#f6f8fa;">
			<span class="small"><span class="text-muted">In</span> <span class="fw-bold text-success"><?php echo money0($b['in_total']); ?></span></span>
			<span class="small"><span class="text-muted">Out</span> <span class="fw-bold" style="color:#d9822b;"><?php echo money0($b['out_total']); ?></span></span>
			<span class="small"><span class="text-muted">Net</span> <span class="fw-bold" style="color:<?php echo $netColor; ?>;"><?php echo money0($b['net']); ?></span></span>
		</div>

		<div class="fw-semibold text-uppercase text-success mb-1" style="font-size:0.66rem;letter-spacing:.04em;">Cash In</div>
		<?php if (empty($b['cash_in'])): ?><div class="text-muted small mb-1">—</div>
		<?php else: foreach ($b['cash_in'] as $it): ?>
			<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid #f1f3f5;">
				<span><?php echo htmlspecialchars($it['label']); ?><?php echo ($it['source']==='manual' && $it['week']) ? ' <span class="text-muted" style="font-size:0.68rem;">wk'.$it['week'].'</span>' : ''; ?></span>
				<span><span class="fw-semibold text-success"><?php echo money0($it['amount']); ?></span><?php echo $it['source']==='manual' ? ' <a href="#" class="ev-edit-id ms-1 text-muted" data-id="'.$it['id'].'" style="font-size:0.66rem;">edit</a>' : ''; ?></span>
			</div>
		<?php endforeach; endif; ?>

		<div class="fw-semibold text-uppercase mb-1 mt-2" style="font-size:0.66rem;letter-spacing:.04em;color:#d9822b;">Cash Out</div>
		<?php if (empty($b['cash_out'])): ?><div class="text-muted small mb-1">—</div>
		<?php else: foreach ($b['cash_out'] as $it): ?>
			<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid #f1f3f5;">
				<span><?php echo htmlspecialchars($it['label']); ?><?php echo ($it['source']==='manual' && $it['week']) ? ' <span class="text-muted" style="font-size:0.68rem;">wk'.$it['week'].'</span>' : ''; ?></span>
				<span><span class="fw-semibold" style="color:#d9822b;"><?php echo money0($it['amount']); ?></span><?php echo $it['source']==='manual' ? ' <a href="#" class="ev-edit-id ms-1 text-muted" data-id="'.$it['id'].'" style="font-size:0.66rem;">edit</a>' : ''; ?></span>
			</div>
		<?php endforeach; endif; ?>

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
	$('#balType').on('change', function(){ var c = $(this).val() !== 'bank'; $('#balLimitWrap,#balPayWrap,#balAprWrap').toggle(c); });
	$('#balQbAccount').on('change', function(){ var v=$(this).val(), $o=$(this).find('option:selected'); if(v==='')return; if(v==='__manual__'){ $('#balQbId').val(''); $('#balLabel').val('').focus(); return; } $('#balQbId').val(v); $('#balLabel').val($o.data('name')); $('#balType').val($o.data('type')).trigger('change'); if(!$('#balAmount').val()) $('#balAmount').val(Math.abs(parseFloat($o.data('balance'))||0).toFixed(2)); });
	$('#addBalBtn').on('click', function(){ $('#balId,#balQbId,#balLabel,#balAmount,#balLimit,#balPayment,#balApr,#balNote').val(''); $('#balQbAccount').val(''); $('#balType').val('bank').trigger('change'); $('#balAsOf').val('<?php echo date('Y-m-d'); ?>'); $('#balMsg').text(''); balShowForm(true); });
	$('#balCancelBtn').on('click', function(){ balShowForm(false); });
	$(document).on('click', '.bal-edit', function(e){ e.preventDefault(); var $r=$(this).closest('.bal-row'); $('#balId').val($r.data('id')); $('#balQbId').val($r.data('qbid')||''); $('#balQbAccount').val($r.data('qbid')||''); $('#balLabel').val($r.data('label')); $('#balType').val($r.data('type')).trigger('change'); $('#balAmount').val($r.data('balance')); $('#balLimit').val($r.data('limit')||''); $('#balPayment').val($r.data('payment')||''); $('#balApr').val($r.data('apr')||''); $('#balAsOf').val($r.data('asof')||''); $('#balNote').val($r.data('note')||''); $('#balMsg').text(''); balShowForm(true); });
	// Quick weekly update: prefill everything, set the date to TODAY, focus the amount.
	$(document).on('click', '.bal-update', function(e){ e.preventDefault(); $('#manageDetails').attr('open','open'); var $r=$(this).closest('.bal-row'); $('#balId').val($r.data('id')); $('#balQbId').val($r.data('qbid')||''); $('#balQbAccount').val($r.data('qbid')||''); $('#balLabel').val($r.data('label')); $('#balType').val($r.data('type')).trigger('change'); $('#balAmount').val($r.data('balance')); $('#balLimit').val($r.data('limit')||''); $('#balPayment').val($r.data('payment')||''); $('#balApr').val($r.data('apr')||''); $('#balAsOf').val('<?php echo date('Y-m-d'); ?>'); $('#balNote').val($r.data('note')||''); $('#balMsg').text(''); balShowForm(true); $('html,body').animate({scrollTop:$('#balForm').offset().top-90},200); $('#balAmount').focus().select(); });
	$('#openBalances').on('click', function(){ $('#manageDetails').attr('open','open'); $('html,body').animate({scrollTop:$('#manageDetails').offset().top-90},200); });
	$('#balSaveBtn').on('click', function(){ var $btn=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_balance.php', { id:$('#balId').val(), label:$('#balLabel').val(), acct_type:$('#balType').val(), balance:$('#balAmount').val(), credit_limit:$('#balLimit').val(), monthly_payment:$('#balPayment').val(), apr:$('#balApr').val(), qb_account_id:$('#balQbId').val(), as_of:$('#balAsOf').val(), note:$('#balNote').val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#balMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#balMsg').addClass('text-danger').text('Save failed: '+(x.responseText||x.status)); $btn.prop('disabled',false); }); });
	$(document).on('click', '.bal-del', function(e){ e.preventDefault(); if(!confirm('Remove this account balance?'))return; $.post('/ajax/cashflow/delete_balance.php', { id:$(this).closest('.bal-row').data('id') }, function(resp){ if($.trim(resp)==='ok') location.reload(); else alert(resp); }); });

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
	$('#addEvBtn').on('click', function(){ $('#evId,#evLabel,#evAmount').val(''); $('#evType').val('out'); $('#evWeek').val('1'); $('#evMsg').text(''); evShowForm(true); });
	$('#evCancelBtn').on('click', function(){ evShowForm(false); });
	function evEdit(id){ var e=EVENTS.filter(function(x){return x.id==id;})[0]; if(!e)return; $('#evId').val(e.id); $('#evType').val(e.etype); $('#evLabel').val(e.label); $('#evAmount').val(e.amount); $('#evMonth').val(e.ym); $('#evWeek').val(e.week); $('#evMsg').text(''); evShowForm(true); $('html,body').animate({scrollTop:$('#evForm').offset().top-90},200); }
	$(document).on('click', '.ev-edit', function(e){ e.preventDefault(); evEdit($(this).closest('.ev-row').data('id')); });
	$(document).on('click', '.ev-edit-id', function(e){ e.preventDefault(); evEdit($(this).data('id')); });
	$(document).on('click', '.add-event-for', function(){ $('#addEvBtn').click(); $('#evMonth').val($(this).data('ym')); $('html,body').animate({scrollTop:$('#evForm').offset().top-90},200); $('#evLabel').focus(); });
	$('#evSaveBtn').on('click', function(){ var $btn=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_event.php', { id:$('#evId').val(), etype:$('#evType').val(), label:$('#evLabel').val(), amount:$('#evAmount').val(), ym:$('#evMonth').val(), week:$('#evWeek').val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#evMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#evMsg').addClass('text-danger').text('Save failed: '+(x.responseText||x.status)); $btn.prop('disabled',false); }); });
	$(document).on('click', '.ev-del', function(e){ e.preventDefault(); if(!confirm('Remove this cash event?'))return; $.post('/ajax/cashflow/delete_event.php', { id:$(this).closest('.ev-row').data('id') }, function(resp){ if($.trim(resp)==='ok') location.reload(); else alert(resp); }); });

	// ── Manual sync (refresh the QuickBooks + Shopify cache) ──
	$('#syncBtn').on('click', function(){
		var $btn = $(this).prop('disabled', true);
		$('#syncLabel').text('Refreshing from QuickBooks & Shopify…');
		$.ajax({ url: '/ajax/cashflow/sync.php', method: 'POST', dataType: 'json', timeout: 180000 })
			.done(function(d){ if (d && d.ok) { location.reload(); } else { $('#syncLabel').text(d && d.error ? d.error : 'Sync failed.'); $btn.prop('disabled', false); } })
			.fail(function(xhr, status){ $('#syncLabel').text(status === 'timeout' ? 'Sync timed out — try again.' : 'Sync failed.'); $btn.prop('disabled', false); });
	});

	// ── Shopify loan % ──
	$('#loanSaveBtn').on('click', function(){ var $btn=$(this).prop('disabled',true); $.post('/ajax/cashflow/save_settings.php', { shopify_loan_pct:$('#loanPct').val() }, function(resp){ if($.trim(resp)==='ok') location.reload(); else { $('#loanMsg').addClass('text-danger').text(resp); $btn.prop('disabled',false); } }).fail(function(x){ $('#loanMsg').addClass('text-danger').text('Failed'); $btn.prop('disabled',false); }); });
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
