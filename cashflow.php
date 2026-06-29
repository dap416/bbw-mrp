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

	$db   = db_connect();
	$data = build_cashflow_data($db);

	$growth   = isset($_GET['growth']) ? (float)$_GET['growth'] : 0.0;
	$forecast = build_cashflow_forecast($db, $data, 12, $growth);
	$recur    = load_recurring_expenses($db);

	function money($n) { return '$' . number_format((float)$n, 2); }
	function fdate($d) { return ($d && $d !== '0000-00-00' && $d !== '0000-00-00 00:00:00') ? date('m/d/y', strtotime($d)) : '—'; }
?>

<div class="mb-4">
	<h2 class="fw-bold mb-0">Cash Flow<?php echo $data['qb_company'] ? ' <span class="text-muted fw-normal" style="font-size:0.6em;">· '.htmlspecialchars($data['qb_company']).'</span>' : ''; ?></h2>
	<div class="text-muted small">Live from QuickBooks + your open purchase orders. Figures are for planning — verify against QuickBooks before paying.</div>
</div>

<?php if (!$data['qb_connected']): ?>
<div class="alert alert-warning">
	<h6 class="fw-bold mb-1">QuickBooks isn't connected yet</h6>
	<p class="mb-2">Connect QuickBooks Online to see bank balances, credit/line-of-credit balances, and bills.</p>
	<a href="/integrations.php" class="btn btn-sm btn-warning">Go to Integrations</a>
</div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="row g-3 mb-4">
	<div class="col-6 col-lg-3">
		<div class="card h-100" style="border-left:4px solid #2ca01c;">
		<div class="card-body py-3">
			<div class="text-muted small text-uppercase fw-semibold" style="font-size:0.68rem;letter-spacing:.04em;">Cash on Hand</div>
			<div class="h4 fw-bold mb-0"><?php echo money($data['eff_cash']); ?></div>
			<div class="text-muted" style="font-size:0.72rem;">
				<?php if ($data['cash_source'] === 'manual'): ?>
					Manually entered<?php echo $data['manual']['oldest_asof'] ? ' · as of '.fdate($data['manual']['oldest_asof']) : ''; ?>
				<?php else: ?>
					QuickBooks bank accounts
				<?php endif; ?>
			</div>
		</div>
		</div>
	</div>
	<div class="col-6 col-lg-3">
		<div class="card h-100" style="border-left:4px solid #4680ff;">
		<div class="card-body py-3">
			<div class="text-muted small text-uppercase fw-semibold" style="font-size:0.68rem;letter-spacing:.04em;">Owed to You</div>
			<div class="h4 fw-bold mb-0 text-primary"><?php echo money($data['ar_total']); ?></div>
			<div class="text-muted" style="font-size:0.72rem;">Open QuickBooks invoices</div>
		</div>
		</div>
	</div>
	<div class="col-6 col-lg-3">
		<div class="card h-100" style="border-left:4px solid #f5a623;">
		<div class="card-body py-3">
			<div class="text-muted small text-uppercase fw-semibold" style="font-size:0.68rem;letter-spacing:.04em;">You Owe (Bills + POs)</div>
			<div class="h4 fw-bold mb-0" style="color:#d9822b;"><?php echo money($data['ap_total']); ?></div>
			<div class="text-muted" style="font-size:0.72rem;"><?php echo money($data['bills']['total']); ?> bills · <?php echo money($data['pos']['total']); ?> POs</div>
		</div>
		</div>
	</div>
	<div class="col-6 col-lg-3">
		<div class="card h-100" style="border-left:4px solid <?php echo $data['net_quick'] >= 0 ? '#2ca01c' : '#e64545'; ?>;">
		<div class="card-body py-3">
			<div class="text-muted small text-uppercase fw-semibold" style="font-size:0.68rem;letter-spacing:.04em;">Net Position</div>
			<div class="h4 fw-bold mb-0" style="color:<?php echo $data['net_quick'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><?php echo money($data['net_quick']); ?></div>
			<div class="text-muted" style="font-size:0.72rem;">Cash + owed to you − what you owe</div>
		</div>
		</div>
	</div>
</div>

<?php if ($data['eff_credit'] > 0 || !empty($data['manual']['credit'])): ?>
<div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center">
	<span class="fw-semibold">Credit &amp; lines of credit owed:</span>
	<span class="h5 mb-0" style="color:#d9822b;"><?php echo money($data['eff_credit']); ?></span>
	<?php if ($data['manual']['credit_limit_total'] > 0): ?>
	<span class="text-muted">·</span>
	<span class="small">Available credit: <strong class="text-success"><?php echo money($data['manual']['credit_available']); ?></strong> of <?php echo money($data['manual']['credit_limit_total']); ?> limit</span>
	<?php endif; ?>
	<span class="text-muted small">(outstanding debt — not all due at once)</span>
</div>
<?php endif; ?>

<div class="row g-4">

	<!-- PAY PLANNER -->
	<div class="col-12 col-xl-7">
		<div class="card">
		<div class="card-body">
			<h5 class="fw-bold mb-1">Pay Planner</h5>
			<p class="text-muted small mb-3">Obligations by due date. "Cash After" shows your bank balance if you pay each one in order — when it turns <span style="color:#e64545;">red</span>, you'd run short.</p>
			<?php if (empty($data['queue'])): ?>
			<div class="text-muted">No open bills or unpaid POs. 🎉</div>
			<?php else: ?>
			<div class="table-responsive">
			<table class="table table-sm table-hover mb-0 align-middle">
				<thead><tr style="background:#f1f3f5;">
					<th class="small text-muted">Due</th>
					<th class="small text-muted">Pay To</th>
					<th class="small text-muted text-end">Amount</th>
					<th class="small text-muted text-end">Cash After</th>
				</tr></thead>
				<tbody>
				<?php foreach ($data['queue'] as $q): ?>
					<tr>
						<td class="small"><?php echo $q['due'] ? fdate($q['due']) : '<span class="text-muted">no date</span>'; ?></td>
						<td class="small"><strong><?php echo htmlspecialchars($q['what']); ?></strong><br><span class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($q['detail']); ?></span></td>
						<td class="small text-end"><?php echo money($q['amount']); ?></td>
						<td class="small text-end fw-semibold" style="color:<?php echo $q['running'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><?php echo money($q['running']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php endif; ?>
		</div>
		</div>
	</div>

	<!-- BALANCES (manual, with date of accuracy) -->
	<div class="col-12 col-xl-5">
		<div class="card mb-4">
		<div class="card-body">
			<div class="d-flex justify-content-between align-items-center mb-2">
				<h6 class="fw-bold mb-0">Account Balances</h6>
				<button class="btn btn-sm btn-light-primary" id="addBalBtn">+ Add / Update</button>
			</div>
			<p class="text-muted mb-2" style="font-size:0.72rem;">Manually keep these current — enter the balance and the date it's accurate as of. These drive the Cash on Hand and credit figures above (QuickBooks can lag).</p>

			<!-- Add / edit form -->
			<div id="balForm" class="border rounded p-2 mb-3 hidden" style="background:#f8f9fb;">
				<input type="hidden" id="balId" value="" />
				<div class="row g-2">
					<div class="col-12"><input type="text" id="balLabel" class="form-control form-control-sm" placeholder="Account name (e.g. Chase Checking)" /></div>
					<div class="col-6">
						<select id="balType" class="form-select form-select-sm">
							<option value="bank">Bank / Cash</option>
							<option value="credit">Credit Card</option>
							<option value="loc">Line of Credit</option>
						</select>
					</div>
					<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="balAmount" class="form-control" placeholder="Balance" /></div></div>
					<div class="col-6" id="balLimitWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">Limit $</span><input type="text" id="balLimit" class="form-control" placeholder="Credit limit" /></div></div>
					<div class="col-6" id="balPayWrap" style="display:none;"><div class="input-group input-group-sm"><span class="input-group-text">Pay/mo $</span><input type="text" id="balPayment" class="form-control" placeholder="Planned monthly payment" /></div></div>
					<div class="col-6"><input type="date" id="balAsOf" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>" title="Date this balance is accurate as of" /></div>
					<div class="col-12"><input type="text" id="balNote" class="form-control form-control-sm" placeholder="Note (optional)" /></div>
					<div class="col-12 d-flex gap-2">
						<button class="btn btn-sm btn-primary" id="balSaveBtn">Save</button>
						<button class="btn btn-sm btn-secondary" id="balCancelBtn">Cancel</button>
						<span id="balMsg" class="small ms-1"></span>
					</div>
				</div>
			</div>

			<div class="fw-semibold small text-muted mb-1">Bank / Cash</div>
			<?php if (empty($data['manual']['bank'])): ?>
				<div class="text-muted small mb-2">None entered yet.</div>
			<?php else: foreach ($data['manual']['bank'] as $a): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small bal-row"
					data-id="<?php echo $a['id']; ?>" data-label="<?php echo htmlspecialchars($a['label'], ENT_QUOTES); ?>" data-type="bank"
					data-balance="<?php echo $a['balance']; ?>" data-asof="<?php echo $a['as_of']; ?>" data-note="<?php echo htmlspecialchars((string)$a['note'], ENT_QUOTES); ?>">
					<span><?php echo htmlspecialchars($a['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· as of <?php echo fdate($a['as_of']); ?></span></span>
					<span><span class="fw-semibold"><?php echo money($a['balance']); ?></span>
						<a href="#" class="bal-edit ms-1" style="font-size:0.7rem;">edit</a>
						<a href="#" class="bal-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; endif; ?>

			<div class="fw-semibold small text-muted mb-1 mt-3">Credit Cards / Lines of Credit</div>
			<?php if (empty($data['manual']['credit'])): ?>
				<div class="text-muted small">None entered yet.</div>
			<?php else: foreach ($data['manual']['credit'] as $a): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small bal-row"
					data-id="<?php echo $a['id']; ?>" data-label="<?php echo htmlspecialchars($a['label'], ENT_QUOTES); ?>" data-type="<?php echo $a['type']; ?>"
					data-balance="<?php echo $a['balance']; ?>" data-limit="<?php echo $a['limit']; ?>" data-payment="<?php echo $a['payment']; ?>" data-asof="<?php echo $a['as_of']; ?>" data-note="<?php echo htmlspecialchars((string)$a['note'], ENT_QUOTES); ?>">
					<span><?php echo htmlspecialchars($a['label']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo htmlspecialchars($a['kind']); ?> · as of <?php echo fdate($a['as_of']); ?><?php echo $a['payment'] > 0 ? ' · pay '.money($a['payment']).'/mo' : ''; ?></span></span>
					<span><span class="fw-semibold" style="color:#d9822b;"><?php echo money($a['balance']); ?></span>
						<a href="#" class="bal-edit ms-1" style="font-size:0.7rem;">edit</a>
						<a href="#" class="bal-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; endif; ?>

			<?php if ($data['qb_connected'] && (!empty($data['cash']['accounts']) || !empty($data['credit']['accounts']))): ?>
			<details class="mt-3">
				<summary class="text-muted small" style="cursor:pointer;">QuickBooks balances (for reference)</summary>
				<div class="mt-2">
					<?php foreach ($data['cash']['accounts'] as $a): ?>
						<div class="d-flex justify-content-between small text-muted"><span><?php echo htmlspecialchars($a['name']); ?></span><span><?php echo money($a['balance']); ?></span></div>
					<?php endforeach; ?>
					<?php foreach ($data['credit']['accounts'] as $a): ?>
						<div class="d-flex justify-content-between small text-muted"><span><?php echo htmlspecialchars($a['name']); ?> · <?php echo htmlspecialchars($a['kind']); ?></span><span><?php echo money($a['balance']); ?></span></div>
					<?php endforeach; ?>
				</div>
			</details>
			<?php endif; ?>
		</div>
		</div>
	</div>

</div>

<script>
	function balShowForm(show) { $('#balForm').toggleClass('hidden', !show); }
	$('#balType').on('change', function(){ var c = $(this).val() !== 'bank'; $('#balLimitWrap').toggle(c); $('#balPayWrap').toggle(c); });
	$('#addBalBtn').on('click', function(){
		$('#balId,#balLabel,#balAmount,#balLimit,#balPayment,#balNote').val('');
		$('#balType').val('bank').trigger('change');
		$('#balAsOf').val('<?php echo date('Y-m-d'); ?>');
		$('#balMsg').text(''); balShowForm(true);
	});
	$('#balCancelBtn').on('click', function(){ balShowForm(false); });
	$(document).on('click', '.bal-edit', function(e){
		e.preventDefault();
		var $r = $(this).closest('.bal-row');
		$('#balId').val($r.data('id'));
		$('#balLabel').val($r.data('label'));
		$('#balType').val($r.data('type')).trigger('change');
		$('#balAmount').val($r.data('balance'));
		$('#balLimit').val($r.data('limit') || '');
		$('#balPayment').val($r.data('payment') || '');
		$('#balAsOf').val($r.data('asof') || '');
		$('#balNote').val($r.data('note') || '');
		$('#balMsg').text(''); balShowForm(true);
	});
	$('#balSaveBtn').on('click', function(){
		var $btn = $(this).prop('disabled', true);
		$.post('/ajax/cashflow/save_balance.php', {
			id: $('#balId').val(), label: $('#balLabel').val(), acct_type: $('#balType').val(),
			balance: $('#balAmount').val(), credit_limit: $('#balLimit').val(), monthly_payment: $('#balPayment').val(),
			as_of: $('#balAsOf').val(), note: $('#balNote').val()
		}, function(resp){
			if ($.trim(resp) === 'ok') { location.reload(); }
			else { $('#balMsg').addClass('text-danger').text(resp); $btn.prop('disabled', false); }
		}).fail(function(x){ $('#balMsg').addClass('text-danger').text('Save failed: ' + (x.responseText||x.status)); $btn.prop('disabled', false); });
	});
	$(document).on('click', '.bal-del', function(e){
		e.preventDefault();
		if (!confirm('Remove this account balance?')) return;
		var id = $(this).closest('.bal-row').data('id');
		$.post('/ajax/cashflow/delete_balance.php', { id: id }, function(resp){
			if ($.trim(resp) === 'ok') location.reload(); else alert(resp);
		});
	});
</script>

<!-- DETAIL TABLES -->
<div class="row g-4 mt-1">
	<div class="col-12 col-lg-6">
		<div class="card">
		<div class="card-body">
			<h6 class="fw-bold mb-2">Open Bills <span class="text-muted fw-normal small">(QuickBooks)</span></h6>
			<?php if ($data['bills']['error']): ?>
				<div class="text-danger small"><?php echo htmlspecialchars($data['bills']['error']); ?></div>
			<?php elseif (empty($data['bills']['items'])): ?>
				<div class="text-muted small">No open bills.</div>
			<?php else: ?>
			<table class="table table-sm mb-0"><tbody>
			<?php foreach ($data['bills']['items'] as $b): ?>
				<tr>
					<td class="small"><?php echo htmlspecialchars($b['vendor']); ?><br><span class="text-muted" style="font-size:0.7rem;">due <?php echo fdate($b['due']); ?></span></td>
					<td class="small text-end fw-semibold"><?php echo money($b['balance']); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>
		</div>
		</div>
	</div>

	<div class="col-12 col-lg-6">
		<div class="card">
		<div class="card-body">
			<h6 class="fw-bold mb-2">Unpaid Purchase Orders <span class="text-muted fw-normal small">(MRP)</span></h6>
			<?php if (empty($data['pos']['items'])): ?>
				<div class="text-muted small">No unpaid POs.</div>
			<?php else: ?>
			<table class="table table-sm mb-0"><tbody>
			<?php foreach ($data['pos']['items'] as $p): ?>
				<tr>
					<td class="small"><strong><?php echo htmlspecialchars($p['supplier']); ?></strong> <span class="text-muted" style="font-size:0.7rem;">· <?php echo htmlspecialchars($p['ref']); ?></span><br><span class="text-muted" style="font-size:0.7rem;"><?php echo htmlspecialchars($p['part']); ?></span></td>
					<td class="small text-end fw-semibold"><?php echo money($p['balance']); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>
		</div>
		</div>
	</div>
</div>

<!-- RECURRING EXPENSES + FORECAST -->
<div class="row g-4 mt-1">
	<div class="col-12 col-lg-4">
		<div class="card h-100">
		<div class="card-body">
			<div class="d-flex justify-content-between align-items-center mb-2">
				<h6 class="fw-bold mb-0">Recurring Monthly Expenses</h6>
				<button class="btn btn-sm btn-light-primary" id="addExpBtn">+ Add</button>
			</div>
			<p class="text-muted mb-2" style="font-size:0.72rem;">Your fixed monthly costs (rent, payroll, software, etc.). These feed the forecast's "cash out."</p>

			<div id="expForm" class="border rounded p-2 mb-3 hidden" style="background:#f8f9fb;">
				<input type="hidden" id="expId" value="" />
				<div class="row g-2">
					<div class="col-12"><input type="text" id="expLabel" class="form-control form-control-sm" placeholder="Expense name (e.g. Rent)" /></div>
					<div class="col-6"><div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="text" id="expAmount" class="form-control" placeholder="Monthly" /></div></div>
					<div class="col-6"><input type="text" id="expCategory" class="form-control form-control-sm" placeholder="Category (optional)" /></div>
					<div class="col-12 d-flex gap-2">
						<button class="btn btn-sm btn-primary" id="expSaveBtn">Save</button>
						<button class="btn btn-sm btn-secondary" id="expCancelBtn">Cancel</button>
						<span id="expMsg" class="small ms-1"></span>
					</div>
				</div>
			</div>

			<?php if (empty($recur['items'])): ?>
				<div class="text-muted small mb-2">No recurring expenses entered.
				<?php if ($forecast['qb_estimate'] !== null): ?><br>Using QuickBooks estimate of <strong><?php echo money($forecast['qb_estimate']); ?>/mo</strong> until you add line items.<?php endif; ?>
				</div>
			<?php else: foreach ($recur['items'] as $e): ?>
				<div class="d-flex justify-content-between align-items-center border-bottom py-1 small exp-row"
					data-id="<?php echo $e['id']; ?>" data-label="<?php echo htmlspecialchars($e['label'], ENT_QUOTES); ?>"
					data-amount="<?php echo $e['amount']; ?>" data-category="<?php echo htmlspecialchars((string)$e['category'], ENT_QUOTES); ?>">
					<span><?php echo htmlspecialchars($e['label']); ?><?php echo $e['category'] ? ' <span class="text-muted" style="font-size:0.7rem;">· '.htmlspecialchars($e['category']).'</span>' : ''; ?></span>
					<span><span class="fw-semibold"><?php echo money($e['amount']); ?></span>
						<a href="#" class="exp-edit ms-1" style="font-size:0.7rem;">edit</a>
						<a href="#" class="exp-del ms-1 text-danger" style="font-size:0.7rem;">×</a></span>
				</div>
			<?php endforeach; ?>
				<div class="d-flex justify-content-between py-1 small fw-bold border-top mt-1"><span>Total / month</span><span><?php echo money($recur['total']); ?></span></div>
			<?php endif; ?>
		</div>
		</div>
	</div>

	<div class="col-12 col-lg-8">
		<div class="card h-100">
		<div class="card-body">
			<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
				<h5 class="fw-bold mb-0">12-Month Forecast</h5>
				<form method="get" class="d-flex align-items-center gap-2 mb-0">
					<label class="small text-muted mb-0">Sales growth vs last yr:</label>
					<div class="input-group input-group-sm" style="width:110px;">
						<input type="number" step="1" name="growth" class="form-control" value="<?php echo (int)$growth; ?>" />
						<span class="input-group-text">%</span>
					</div>
					<button class="btn btn-sm btn-light-primary">Apply</button>
				</form>
			</div>
			<p class="text-muted small mb-3">
				Sales = last year's same month from Shopify<?php echo $growth ? ' +'.(int)$growth.'%' : ''; ?>.
				Cash out = recurring (<?php echo money($forecast['recur_total'] > 0 ? $forecast['recur_total'] : (float)$forecast['qb_estimate']); ?>/mo)<?php echo ($forecast['recur_total'] <= 0 && $forecast['qb_estimate'] !== null) ? ' <em>(QuickBooks estimate)</em>' : ''; ?> + bills/POs due + debt payments (<?php echo money($forecast['debt_pay_mo']); ?>/mo).
				Starting cash <?php echo money($forecast['start_cash']); ?>, starting debt <?php echo money($forecast['start_debt']); ?>.
			</p>
			<div class="table-responsive">
			<table class="table table-sm table-hover align-middle mb-0" style="font-size:0.82rem;">
				<thead><tr style="background:#f1f3f5;">
					<th class="text-muted">Month</th>
					<th class="text-muted text-end">Sales In</th>
					<th class="text-muted text-end">Recurring</th>
					<th class="text-muted text-end">Bills/POs</th>
					<th class="text-muted text-end">Debt Pay</th>
					<th class="text-muted text-end">Net</th>
					<th class="text-muted text-end">End Cash</th>
					<th class="text-muted text-end">End Debt</th>
				</tr></thead>
				<tbody>
				<?php foreach ($forecast['rows'] as $r): ?>
					<tr<?php echo $r['end_cash'] < 0 ? ' style="background:#fdecea;"' : ''; ?>>
						<td class="fw-semibold"><?php echo $r['label']; ?></td>
						<td class="text-end text-success"><?php echo money($r['income']); ?></td>
						<td class="text-end"><?php echo money($r['recurring']); ?></td>
						<td class="text-end"><?php echo $r['onetime'] > 0 ? money($r['onetime']) : '—'; ?></td>
						<td class="text-end"><?php echo $r['debt_pay'] > 0 ? money($r['debt_pay']) : '—'; ?></td>
						<td class="text-end" style="color:<?php echo $r['net'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><?php echo money($r['net']); ?></td>
						<td class="text-end fw-bold" style="color:<?php echo $r['end_cash'] >= 0 ? '#2ca01c' : '#e64545'; ?>;"><?php echo money($r['end_cash']); ?></td>
						<td class="text-end" style="color:#d9822b;"><?php echo money($r['end_debt']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
			$allZero = true;
			foreach ($forecast['rows'] as $r) { if ($r['income'] > 0) { $allZero = false; break; } }
			if ($allZero): ?>
			<div class="text-muted small mt-2">Projected sales are all $0 — this usually means Shopify isn't connected or has no prior-year history in range. Connect Shopify on the Integrations page to populate projections.</div>
			<?php endif; ?>
		</div>
		</div>
	</div>
</div>

<script>
	function expShowForm(s){ $('#expForm').toggleClass('hidden', !s); }
	$('#addExpBtn').on('click', function(){ $('#expId,#expLabel,#expAmount,#expCategory').val(''); $('#expMsg').text(''); expShowForm(true); });
	$('#expCancelBtn').on('click', function(){ expShowForm(false); });
	$(document).on('click', '.exp-edit', function(e){
		e.preventDefault(); var $r = $(this).closest('.exp-row');
		$('#expId').val($r.data('id')); $('#expLabel').val($r.data('label'));
		$('#expAmount').val($r.data('amount')); $('#expCategory').val($r.data('category') || '');
		$('#expMsg').text(''); expShowForm(true);
	});
	$('#expSaveBtn').on('click', function(){
		var $btn = $(this).prop('disabled', true);
		$.post('/ajax/cashflow/save_expense.php', { id: $('#expId').val(), label: $('#expLabel').val(), amount: $('#expAmount').val(), category: $('#expCategory').val() }, function(resp){
			if ($.trim(resp) === 'ok') location.reload();
			else { $('#expMsg').addClass('text-danger').text(resp); $btn.prop('disabled', false); }
		}).fail(function(x){ $('#expMsg').addClass('text-danger').text('Save failed: ' + (x.responseText||x.status)); $btn.prop('disabled', false); });
	});
	$(document).on('click', '.exp-del', function(e){
		e.preventDefault(); if (!confirm('Remove this expense?')) return;
		$.post('/ajax/cashflow/delete_expense.php', { id: $(this).closest('.exp-row').data('id') }, function(resp){
			if ($.trim(resp) === 'ok') location.reload(); else alert(resp);
		});
	});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
