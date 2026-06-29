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
			<div class="h4 fw-bold mb-0"><?php echo money($data['cash']['total']); ?></div>
			<div class="text-muted" style="font-size:0.72rem;">QuickBooks bank accounts</div>
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

<?php if (!empty($data['credit']['accounts']) || $data['credit']['total'] > 0): ?>
<div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center">
	<span class="fw-semibold">Credit cards &amp; lines of credit owed:</span>
	<span class="h5 mb-0" style="color:#d9822b;"><?php echo money($data['credit']['total']); ?></span>
	<span class="text-muted small">(outstanding debt — not all due at once; shown separately from the cash position above)</span>
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

	<!-- BALANCES -->
	<div class="col-12 col-xl-5">
		<div class="card mb-4">
		<div class="card-body">
			<h6 class="fw-bold mb-2">Bank / Cash Accounts</h6>
			<?php if ($data['cash']['error']): ?>
				<div class="text-danger small"><?php echo htmlspecialchars($data['cash']['error']); ?></div>
			<?php elseif (empty($data['cash']['accounts'])): ?>
				<div class="text-muted small">No bank accounts found<?php echo $data['qb_connected'] ? '.' : ' (QuickBooks not connected).'; ?></div>
			<?php else: foreach ($data['cash']['accounts'] as $a): ?>
				<div class="d-flex justify-content-between border-bottom py-1 small">
					<span><?php echo htmlspecialchars($a['name']); ?></span>
					<span class="fw-semibold"><?php echo money($a['balance']); ?></span>
				</div>
			<?php endforeach; endif; ?>
		</div>
		</div>

		<div class="card">
		<div class="card-body">
			<h6 class="fw-bold mb-2">Credit Cards &amp; Lines of Credit</h6>
			<?php if (empty($data['credit']['accounts'])): ?>
				<div class="text-muted small">None found.</div>
			<?php else: foreach ($data['credit']['accounts'] as $a): ?>
				<div class="d-flex justify-content-between border-bottom py-1 small">
					<span><?php echo htmlspecialchars($a['name']); ?> <span class="text-muted" style="font-size:0.7rem;">· <?php echo htmlspecialchars($a['kind']); ?></span></span>
					<span class="fw-semibold" style="color:#d9822b;"><?php echo money($a['balance']); ?></span>
				</div>
			<?php endforeach; endif; ?>
		</div>
		</div>
	</div>

</div>

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

<?php require_once(__DIR__."/includes/footer.php"); ?>
