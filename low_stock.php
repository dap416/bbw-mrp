<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");

	$db = db_connect();

	$parts = $db->query("
		SELECT
			p.partno, p.`desc`, p.qoh, p.lead_time, p.cost,
			COALESCE(oo.oo, 0)                                       AS on_order,
			ROUND(d.d12 / 365.0, 2)                                  AS daily_demand,
			ROUND(d.d12 / 365.0 * p.lead_time * 2)                   AS coverage_needed,
			ROUND((p.qoh + COALESCE(oo.oo,0)) / (d.d12 / 365.0))    AS days_covered
		FROM parts p
		LEFT JOIN (
			SELECT partid, SUM(qty-recqty) AS oo
			FROM orders WHERE qty > recqty
			GROUP BY partid
		) oo ON oo.partid = p.id
		LEFT JOIN (
			SELECT partid, SUM(ABS(qty)) AS d12
			FROM trans
			WHERE type = 'BUILD' AND date > DATE_SUB(NOW(), INTERVAL 12 MONTH)
			GROUP BY partid
		) d ON d.partid = p.id
		WHERE d.d12 > 0
		  AND p.lead_time > 0
		  AND (p.qoh + COALESCE(oo.oo,0)) < (d.d12 / 365.0 * p.lead_time * 2)
		ORDER BY days_covered ASC
	")->fetchAll();

?>

<div class="mb-4 d-flex align-items-center gap-3">
	<a href="/home.php" class="text-muted" style="text-decoration:none;font-size:0.85rem;"><i class="ti ti-arrow-left me-1"></i>Dashboard</a>
	<h2 class="fw-bold mb-0">Low Stock — At Risk Parts</h2>
</div>

<p class="text-muted mb-4" style="font-size:0.875rem;">
	Parts flagged here have less than <strong>2× lead-time</strong> worth of supply on hand plus on order, based on trailing 12-month daily demand.
</p>

<?php if (empty($parts)): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
	<i class="ti ti-circle-check" style="font-size:2rem;color:#22c55e;"></i>
	<div class="mt-2">No parts are currently at risk. All parts have sufficient coverage.</div>
</div></div>
<?php else: ?>
<div class="card">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#fef2f2;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Part</th>
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Description</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">QOH</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">On Order</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Daily Demand</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Lead Time</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Need</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Days Covered</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($parts as $p):
				$urgent = $p['days_covered'] < $p['lead_time'];
			?>
				<tr style="<?php echo $urgent ? 'background:#fff5f5;' : ''; ?>">
					<td class="fw-semibold"><?php echo htmlspecialchars($p['partno']); ?></td>
					<td><?php echo htmlspecialchars($p['desc']); ?></td>
					<td class="text-end"><?php echo number_format($p['qoh']); ?></td>
					<td class="text-end"><?php echo number_format($p['on_order']); ?></td>
					<td class="text-end"><?php echo $p['daily_demand']; ?></td>
					<td class="text-end"><?php echo $p['lead_time']; ?>d</td>
					<td class="text-end"><?php echo number_format($p['coverage_needed']); ?></td>
					<td class="text-end fw-semibold" style="color:<?php echo $urgent ? '#dc2626' : '#e67e00'; ?>;">
						<?php echo $p['days_covered']; ?>d
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<?php require_once(__DIR__."/includes/footer.php"); ?>
