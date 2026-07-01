<?php
/**
 * Dashboard widget: this month's cash-flow payments to make (card payments,
 * bills/POs due) + which card each raw-material PO should go on. Admin-only.
 * Result HTML is cached ~1h in data_cache so the dashboard stays fast.
 */
	require_once(__DIR__."/../includes/fns.php");
	require_login();
	header('Content-Type: application/json');

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { echo json_encode(['error' => 'denied']); exit; }

	require_once(__DIR__."/../includes/cashflow.php");
	$db = db_connect();

	$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	$key = 'dash_payments';
	if (empty($_GET['refresh'])) {
		try {
			$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$key]);
			$row = $s->fetch();
			if ($row && (time() - strtotime($row['updated_at'])) < 3600) { echo json_encode(['ok' => true, 'html' => $row['cval'], 'cached' => true]); exit; }
		} catch (Throwable $e) {}
	}

	$m0 = fn($n) => '$' . number_format((float)$n, 0);

	try {
		$data     = build_cashflow_data($db);
		$forecast = build_cashflow_forecast($db, $data, 12, 0.0);
		$events   = load_cash_events($db);
		$md       = build_month_blocks($db, $data, $forecast, $events);
	} catch (Throwable $e) {
		echo json_encode(['ok' => true, 'html' => '<div class="text-muted small">Cash-flow data unavailable: ' . htmlspecialchars($e->getMessage()) . '</div>']);
		exit;
	}

	$cur = null; foreach ($md['blocks'] as $b) { if (empty($b['is_past'])) { $cur = $b; break; } }
	if (!$cur) { echo json_encode(['ok' => true, 'html' => '<div class="text-muted small">No current month in the forecast.</div>']); exit; }

	$cards = array_values(array_filter($cur['card_payments'], fn($c) => $c['amount'] > 0));
	usort($cards, fn($a, $b) => ($b['is_target'] <=> $a['is_target']) ?: (($b['apr'] ?? -1) <=> ($a['apr'] ?? -1)));
	$cardTotal = array_sum(array_map(fn($c) => $c['amount'], $cards));

	// Non-card operating cash-out (bills/POs due, recurring, loan, tax) — exclude the rolled-up "Card payments" line.
	$outs = array_values(array_filter($cur['cash_out'], fn($o) => stripos($o['label'], 'card payments') === false));

	ob_start();
?>
<div class="row g-3">
	<div class="col-12 col-lg-6">
		<div class="fw-semibold text-uppercase text-muted mb-2" style="font-size:0.7rem;letter-spacing:.05em;">Card Payments — <?php echo htmlspecialchars($cur['label']); ?></div>
		<?php if (empty($cards)): ?>
			<div class="text-muted small">No card payments recommended this month.</div>
		<?php else: ?>
		<table class="table dash-table"><thead><tr><th>Card</th><th class="text-end">APR</th><th class="text-end">Balance</th><th class="text-end">Pay</th></tr></thead><tbody>
		<?php foreach ($cards as $c): ?>
			<tr>
				<td class="fw-semibold"><?php echo htmlspecialchars($c['label']); ?>
					<?php echo !empty($c['is_target']) ? '<span class="badge bg-primary" style="font-size:0.54rem;">FOCUS</span>' : ''; ?>
					<?php echo !empty($c['paid_off']) ? '<span class="badge bg-success" style="font-size:0.54rem;">PAID OFF</span>' : ''; ?></td>
				<td class="text-end text-muted"><?php echo $c['apr'] !== null ? rtrim(rtrim(number_format($c['apr'],2),'0'),'.').'%' : '—'; ?></td>
				<td class="text-end text-muted"><?php echo isset($c['balance']) ? $m0($c['balance']) : '—'; ?></td>
				<td class="text-end fw-bold" style="color:#6f42c1;"><?php echo $m0($c['amount']); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody><tfoot><tr style="background:#f8f9fa;"><td colspan="3" class="text-end small text-muted fw-semibold">Total to cards</td><td class="text-end fw-bold"><?php echo $m0($cardTotal); ?></td></tr></tfoot></table>
		<?php endif; ?>
	</div>

	<div class="col-12 col-lg-6">
		<div class="fw-semibold text-uppercase text-muted mb-2" style="font-size:0.7rem;letter-spacing:.05em;">Other Cash Out — <?php echo htmlspecialchars($cur['label']); ?></div>
		<?php if (empty($outs)): ?>
			<div class="text-muted small">No other scheduled cash-out this month.</div>
		<?php else: ?>
		<table class="table dash-table"><tbody>
		<?php foreach ($outs as $o): ?>
			<tr><td><?php echo htmlspecialchars($o['label']); ?></td><td class="text-end fw-semibold" style="color:#d9822b;"><?php echo $m0($o['amount']); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>

		<?php $plan = array_slice($md['po_card_plan'] ?? [], 0, 6); if (!empty($plan)): ?>
		<div class="fw-semibold text-uppercase text-muted mb-2 mt-2" style="font-size:0.7rem;letter-spacing:.05em;">Raw-Material POs → Which Card</div>
		<table class="table dash-table"><tbody>
		<?php foreach ($plan as $p): ?>
			<tr>
				<td><?php echo htmlspecialchars($p['part']); ?> <span class="text-muted" style="font-size:0.72rem;">×<?php echo number_format($p['order']); ?></span></td>
				<td class="text-end text-muted"><?php echo $m0($p['cost']); ?></td>
				<td class="text-end"><?php echo $p['card'] ? '<span class="fw-semibold">'.htmlspecialchars($p['card']).'</span>' : '<span class="text-danger" style="font-size:0.72rem;">needs credit room</span>'; ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<div class="text-muted small"><i class="ti ti-info-circle"></i> POs go on a real credit card, then get marked paid on the Orders tab.</div>
		<?php endif; ?>
	</div>
</div>
<?php
	$html = ob_get_clean();
	try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$key, $html]); } catch (Throwable $e) {}
	echo json_encode(['ok' => true, 'html' => $html, 'cached' => false]);
