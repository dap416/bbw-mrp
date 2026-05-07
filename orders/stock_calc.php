<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	if (!has_access('orders')) deny_access();

	require_once(__DIR__."/../includes/header.php");

	$dbLink = db_connect();

	require_once(__DIR__."/../ajax/bsl_calc.php");

	$currentMonth = (int)date('n');
	$monthNames   = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
	$safetyBuffer = 30;

	// Temporary pause list — cam IDs whose order qty should be excluded from
	// universal component calculations for this page visit only.
	$pausedIds = [];
	if (!empty($_GET['paused'])) {
		$pausedIds = array_values(array_filter(array_map('intval', explode(',', $_GET['paused']))));
	}

?>

<div class="mb-4 d-flex align-items-center justify-content-between">
	<h2 class="fw-bold mb-0">Raw Materials Stock Order</h2>
</div>


<!-- CAMSHAFT ORDERS -->
<h5 class="fw-bold mb-2">Camshaft Order Suggestions</h5>
<p class="text-muted mb-3" style="font-size:0.8rem;"><i class="ti ti-info-circle me-1"></i>The <strong>To Order</strong> quantity is rounded up to the nearest MOQ. The numbers in parentheses show the actual recommended order quantity plus the amount added to reach the next MOQ increment — e.g. <em>500 (420 + 80)</em>. <strong>Omit</strong> quantities are treated as on-hand overstock and are subtracted from the BSL before calculating the order recommendation.</p>
<p class="mb-3" style="font-size:0.8rem;"><i class="ti ti-alert-triangle me-1" style="color:#e67e00;"></i>Order recommendations shown in <strong style="color:#e67e00;">amber</strong> warrant a second look before ordering: either the actual need is less than 25% of the MOQ (you are ordering mostly to meet the minimum, not because stock is critically low), or the part carries an <strong>Omit</strong> value indicating some on-hand inventory is considered non-saleable — review before committing to a new order.</p>
<div class="card mb-4">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:260px;">Part</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">QOH</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">QOO</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:60px;">12MD</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:60px;">6MD</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">BSL</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:220px;">To Order</th>
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:120px;">Omit</th>
				</tr>
			</thead>
			<tbody>
<?php

	$cams = $dbLink->query("SELECT * FROM `parts` WHERE `partno` LIKE 'CS-%' ORDER BY `partno`");

	$camOrderArray = array();
	$camArrayCount = 0;
	$totalCamCount = 0;
	$cardArray = array();

	while ($cam = $cams->fetch()) {

		$camId   = $cam['id'];
		$camOH   = $cam['qoh'];
		$camBSL  = $cam['bsl'];
		$camName = $cam['partno'] . ' — ' . $cam['desc'];
		$camMOQ  = $cam['imoq'];
		$camOmit = $cam['omit'];

		$onOrder = $dbLink->query("SELECT SUM(`qty`) - SUM(`recqty`) AS `onorder` FROM `orders` WHERE `partid` = '$camId' AND `postdate` LIKE '0000-00-00%'")->fetch();
		$onOrder = max(0, $onOrder['onorder'] ?? 0);

		$twelveMonthsAgo = date("Y-m-d H:i:s", strtotime("12 months ago"));
		$twelveDemand = $dbLink->query("SELECT SUM(`qty`) AS `demand` FROM `trans` WHERE `type` = 'BUILD' AND `partid` = '$camId' AND `date` > '$twelveMonthsAgo'")->fetch();
		$twelveDemand = ($twelveDemand['demand'] ?? 0) * -1;

		$sixMonthsAgo = date("Y-m-d H:i:s", strtotime("6 months ago"));
		$sixDemand = $dbLink->query("SELECT SUM(`qty`) AS `demand` FROM `trans` WHERE `type` = 'BUILD' AND `partid` = '$camId' AND `date` > '$sixMonthsAgo'")->fetch();
		$sixDemand = ($sixDemand['demand'] ?? 0) * -1;

		$toOrder = $camBSL - $camOH - $onOrder;

		if ($toOrder <= 0) {
			$toOrderDisplay = '0';
			$rowClass       = '';
			$rowStyle       = '';
			$toOrderRounded = 0;
		} else {
			$withinMOQ     = floor($toOrder / $camMOQ);
			$overMOQ       = $toOrder % $camMOQ;
			$roundUp       = $camMOQ - $overMOQ;
			$toOrderRounded = ($withinMOQ * $camMOQ) + $camMOQ;
			$toOrderDisplay = "$toOrderRounded ($toOrder + $roundUp)";

			// Warn in amber if actual need is <25% of what gets ordered (mostly MOQ padding)
			// or if the part carries an omit value (non-saleable stock present — why order more?)
			$isWarning = ($toOrder < $toOrderRounded * 0.25) || ($camOmit > 0);
			$rowClass  = $isWarning ? 'fw-semibold' : 'text-primary fw-semibold';
			$rowStyle  = $isWarning ? 'color:#e67e00;' : '';

			$camOrderArray[$camArrayCount]['partid']   = $camId;
			$camOrderArray[$camArrayCount]['orderqty'] = $toOrderRounded;
			$camArrayCount++;
			if (!in_array($camId, $pausedIds)) {
				$totalCamCount      += $toOrderRounded;
				$cardArray[$camId]   = $toOrderRounded;
			}
		}

		// --- Seasonal breakdown for explanation ---
		$camLeadTime   = max(1, (int)($cam['lead_time'] ?? 45));
		$windowDays    = $camLeadTime + $safetyBuffer;
		$monthsFwd     = $windowDays / 30.0;

		$camYrs = $dbLink->query("SELECT COUNT(DISTINCT YEAR(`date`)) AS `y` FROM `trans` WHERE `type`='BUILD' AND `partid`='$camId'")->fetch();
		$camNumYears = max(1, (int)($camYrs['y'] ?? 1));

		$camMonthQ = $dbLink->query("SELECT MONTH(`date`) AS `mo`, SUM(`qty`)*-1 AS `demand` FROM `trans` WHERE `type`='BUILD' AND `partid`='$camId' GROUP BY MONTH(`date`)");
		$avgByMonth = array_fill(1, 12, 0.0);
		while ($mrow = $camMonthQ->fetch()) {
			$avgByMonth[(int)$mrow['mo']] = (float)$mrow['demand'] / $camNumYears;
		}

		$projection = [];
		for ($i = 0; $i < ceil($monthsFwd); $i++) {
			$fMo     = (($currentMonth - 1 + $i) % 12) + 1;
			$weight  = min(1.0, $monthsFwd - $i);
			$projection[] = [
				'name'   => $monthNames[$fMo - 1],
				'avg'    => round($avgByMonth[$fMo], 1),
				'weight' => $weight,
				'contrib'=> round($avgByMonth[$fMo] * $weight, 1),
			];
		}
		$rawNeed = max(0, $toOrder);

?>
				<tr>
					<td><?php echo htmlspecialchars($camName); ?></td>
					<td class="text-end"><?php echo $camOH; ?></td>
					<td class="text-end"><?php echo $onOrder; ?></td>
					<td class="text-end"><?php echo $twelveDemand; ?></td>
					<td class="text-end"><?php echo $sixDemand; ?></td>
					<td class="text-end"><?php echo $camBSL; ?></td>
					<?php $isPaused = in_array($camId, $pausedIds); ?>
					<td class="<?php echo $rowClass; ?>"<?php if ($rowStyle) echo ' style="'.$rowStyle.($isPaused ? 'opacity:0.45;text-decoration:line-through;' : '').'"'; elseif ($isPaused) echo ' style="opacity:0.45;text-decoration:line-through;"'; ?>>
						<div class="d-flex align-items-center justify-content-end gap-2">
							<span><?php echo $toOrderDisplay; ?></span>
							<?php if ($isWarning && $toOrderRounded > 0): ?>
							<?php if (in_array($camId, $pausedIds)): ?>
							<button class="btn btn-sm" style="font-size:0.7rem;padding:1px 7px;background:#fff3cd;border:1px solid #e67e00;color:#e67e00;white-space:nowrap;" action="pauseBtn" record="<?php echo $camId; ?>" title="Resume — include in universal component calculation">Resume</button>
							<?php else: ?>
							<button class="btn btn-sm" style="font-size:0.7rem;padding:1px 7px;background:#fff3cd;border:1px solid #e67e00;color:#e67e00;white-space:nowrap;" action="pauseBtn" record="<?php echo $camId; ?>" title="Pause — exclude from universal component calculation">Pause</button>
							<?php endif; ?>
							<?php endif; ?>
							<?php if ($toOrderRounded > 0): ?>
							<button class="btn btn-outline-primary btn-sm" style="font-size:0.7rem;padding:1px 7px;white-space:nowrap;" action="orderBtn" record="<?php echo $camId; ?>">+ Order</button>
							<?php endif; ?>
							<span action="explainBtn" record="<?php echo $camId; ?>" title="How was this calculated?" style="cursor:pointer;color:#adb5bd;font-size:1rem;line-height:1;"><i class="ti ti-info-circle"></i></span>
						</div>
					</td>
					<td>
						<div class="d-flex align-items-center gap-2">
							<input type="text" id="<?php echo $camId; ?>omitField" class="form-control form-control-sm" style="width:60px;" value="<?php echo $camOmit; ?>" />
							<button class="btn btn-outline-secondary btn-sm" action="changeOmitButton" record="<?php echo $camId; ?>">Set</button>
						</div>
					</td>
				</tr>
				<tr id="explainRow_<?php echo $camId; ?>" style="display:none;">
					<td colspan="8" style="background:#fffef5;border-top:none;padding:14px 18px;">
<?php
	$effective    = $camOH + $onOrder;
	$shortfall    = $camBSL - $effective;
	$arrivalDate  = date('M j', strtotime("+{$camLeadTime} days"));
	$windowEnd    = date('M j', strtotime("+{$windowDays} days"));

	// Build a readable list of months in the projection for the narrative
	$projNarrative = [];
	foreach ($projection as $pr) {
		if ($pr['avg'] > 0) {
			$pct = $pr['weight'] >= 1.0 ? 'full month' : round($pr['weight'] * 100) . '% of the month';
			$projNarrative[] = "<strong>{$pr['name']}</strong>: avg {$pr['avg']}/mo &times; {$pct} = <strong>{$pr['contrib']}</strong> units";
		}
	}
?>
						<div class="d-flex align-items-center justify-content-between mb-3">
						<div class="fw-semibold" style="font-size:0.88rem;color:#333;"><?php echo htmlspecialchars($camName); ?></div>
						<button action="explainBtn" record="<?php echo $camId; ?>" class="btn btn-sm btn-outline-secondary">Close</button>
					</div>

						<p style="font-size:0.83rem;margin-bottom:10px;">
							<?php if ($camLeadTime > 0): ?>
							This part has a <strong><?php echo $camLeadTime; ?>-day lead time</strong>, meaning any order placed today won't arrive until around <strong><?php echo $arrivalDate; ?></strong>.
							Adding a <?php echo $safetyBuffer; ?>-day safety buffer to absorb supplier delays or unexpected demand, your stocking target needs to cover the next <strong><?php echo $windowDays; ?> days</strong> (through approximately <?php echo $windowEnd; ?>).
							<?php else: ?>
							With a <?php echo $safetyBuffer; ?>-day safety buffer applied, your stocking target covers the next <strong><?php echo $windowDays; ?> days</strong> (through approximately <?php echo $windowEnd; ?>).
							<?php endif; ?>
						</p>

						<p style="font-size:0.83rem;margin-bottom:6px;">
							Based on your historical build demand, here is the average usage expected during that window, weighted by seasonal patterns:
						</p>

						<table class="table table-sm table-bordered mb-3" style="font-size:0.8rem;width:auto;">
							<thead>
								<tr style="background:#f1f3f5;">
									<th>Month</th>
									<th class="text-end">Hist. Avg/Mo</th>
									<th class="text-end">Coverage</th>
									<th class="text-end">Projected Use</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($projection as $pr): ?>
								<tr>
									<td><?php echo $pr['name']; ?></td>
									<td class="text-end"><?php echo $pr['avg'] > 0 ? $pr['avg'] : '<span class="text-muted">—</span>'; ?></td>
									<td class="text-end"><?php echo $pr['weight'] >= 1.0 ? 'Full' : round($pr['weight'] * 100) . '%'; ?></td>
									<td class="text-end"><?php echo $pr['contrib'] > 0 ? $pr['contrib'] : '<span class="text-muted">—</span>'; ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr style="background:#f1f3f5;">
									<td colspan="3" class="fw-semibold">Total projected use = Best Stocking Level (BSL)</td>
									<td class="text-end fw-semibold"><?php echo $camBSL; ?></td>
								</tr>
							</tfoot>
						</table>

						<p style="font-size:0.83rem;margin-bottom:10px;">
							The sum of this projected demand is <strong><?php echo $camBSL; ?> units</strong> — your <strong>Best Stocking Level</strong>.
							This is the minimum you should have on hand <?php echo $toOrderRounded > 0 ? '<em>right now</em>' : ''; ?> to ensure you won't run out before a new order arrives.
						</p>

						<p style="font-size:0.83rem;margin-bottom:<?php echo $toOrderRounded > 0 ? '10' : '0'; ?>px;">
							<?php if ($toOrderRounded > 0): ?>
							Your current position is <strong><?php echo $camOH; ?> on hand</strong><?php echo $onOrder > 0 ? " + <strong>{$onOrder} on order</strong>" : ''; ?> = <strong><?php echo $effective; ?> effective units</strong>.
							That leaves you <strong><?php echo $shortfall; ?> units short</strong> of your BSL of <?php echo $camBSL; ?>.
							<?php else: ?>
							Your current position is <strong><?php echo $camOH; ?> on hand</strong><?php echo $onOrder > 0 ? " + <strong>{$onOrder} on order</strong>" : ''; ?> = <strong><?php echo $effective; ?> effective units</strong> — which meets or exceeds your BSL of <?php echo $camBSL; ?>. <strong>No order is needed at this time.</strong>
							<?php endif; ?>
						</p>

						<?php if ($toOrderRounded > 0): ?>
						<p style="font-size:0.83rem;margin-bottom:0;">
							Rounding <?php echo $rawNeed; ?> up to the nearest MOQ of <?php echo number_format($camMOQ); ?> units, the suggested order quantity is <strong><?php echo number_format($toOrderRounded); ?> units</strong> (<?php echo $rawNeed; ?> needed + <?php echo $toOrderRounded - $rawNeed; ?> to reach the next MOQ).
						</p>
						<?php endif; ?>

					</td>
				</tr>
				<?php if ($toOrderRounded > 0): ?>
				<tr id="orderRow_<?php echo $camId; ?>" style="display:none;">
					<td colspan="8" style="background:#f0f4ff;border-top:none;">
						<div class="d-flex align-items-center gap-3 py-1">
							<span class="text-muted fw-semibold" style="font-size:0.8rem;">New Order for <?php echo htmlspecialchars($camName); ?></span>
							<input type="text" id="orderRef_<?php echo $camId; ?>" class="form-control form-control-sm" style="width:160px;" placeholder="Order / PO #" />
							<input type="number" id="orderQty_<?php echo $camId; ?>" class="form-control form-control-sm" style="width:90px;" value="<?php echo $toOrderRounded; ?>" />
							<button class="btn btn-primary btn-sm" action="submitOrderBtn" record="<?php echo $camId; ?>">Place Order</button>
							<button class="btn btn-outline-secondary btn-sm" action="cancelOrderBtn" record="<?php echo $camId; ?>">Cancel</button>
						</div>
					</td>
				</tr>
				<?php endif; ?>
<?php } ?>
			</tbody>
			<tfoot>
				<tr style="background:#f8f9fa;">
					<td colspan="8" class="text-muted" style="font-size:0.8rem;">
<?php
	$camsOH      = $dbLink->query("SELECT SUM(`qoh`) AS `v` FROM `parts` WHERE `partno` LIKE 'CS%'")->fetch()['v'] ?? 0;
	$camsOO      = $dbLink->query("SELECT SUM(`qty` - `recqty`) AS `v` FROM `orders` WHERE `partid` IN (SELECT `id` FROM `parts` WHERE `partno` LIKE 'CS%') AND (`qty` - `recqty`) > 0")->fetch()['v'] ?? 0;
	$camsOmitted = $dbLink->query("SELECT SUM(`omit`) AS `v` FROM `parts` WHERE `partno` LIKE 'CS%'")->fetch()['v'] ?? 0;
	$effectiveCams = $camsOH + $camsOO + $totalCamCount - $camsOmitted;
	echo "Effective Camshaft Demand: <strong>$effectiveCams</strong> &nbsp;=&nbsp; <strong>$camsOH</strong> On-Hand + <strong>$camsOO</strong> On-Order + <strong>$totalCamCount</strong> Suggested for Order (above) &minus; <strong>$camsOmitted</strong> Omitted (above)";
?>
					</td>
				</tr>
			</tfoot>
		</table>
	</div>
</div>

<!-- PLATES & PACKAGING -->
<?php

	$platesAndPackaging = $dbLink->query("
		SELECT * FROM `parts`
		WHERE `partno` LIKE 'PL-%' OR `partno` LIKE 'MC%'
		ORDER BY `partno` ASC
	");

?>

<h5 class="fw-bold mb-3">Plates &amp; Packaging Order Suggestions</h5>
<div class="card mb-4">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:260px;">Part</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">QOH</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">QOO</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:60px;">12MD</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:60px;">6MD</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">BSL</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:220px;">To Order</th>
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:120px;">Omit</th>
				</tr>
			</thead>
			<tbody>
<?php

	while ($pp = $platesAndPackaging->fetch()) {
		$ppId   = $pp['id'];
		$ppName = $pp['partno'] . ' — ' . $pp['desc'];
		$ppMOQ  = max(1, (int)($pp['imoq'] ?: 1000));
		$ppOmit = max(0, (int)($pp['omit'] ?? 0));
		$ppBSL  = (int)$pp['bsl'];
		$ppQOH  = (int)$pp['qoh'];
		$ppLeadTime = max(1, (int)($pp['lead_time'] ?? 45));
		$ppWindowDays = $ppLeadTime + $safetyBuffer;
		$ppMonthsFwd  = $ppWindowDays / 30.0;

		$ppOO = max(0, (float)($dbLink->query("SELECT SUM(`qty` - `recqty`) AS `v` FROM `orders` WHERE `partid` = '$ppId' AND (`qty` - `recqty`) > 0")->fetch()['v'] ?? 0));

		$ppTwelveMonthsAgo = date("Y-m-d H:i:s", strtotime("12 months ago"));
		$ppTwelveDemand = $dbLink->query("SELECT SUM(`qty`) AS `demand` FROM `trans` WHERE `type` = 'BUILD' AND `partid` = '$ppId' AND `date` > '$ppTwelveMonthsAgo'")->fetch();
		$ppTwelveDemand = ($ppTwelveDemand['demand'] ?? 0) * -1;

		$ppSixMonthsAgo = date("Y-m-d H:i:s", strtotime("6 months ago"));
		$ppSixDemand = $dbLink->query("SELECT SUM(`qty`) AS `demand` FROM `trans` WHERE `type` = 'BUILD' AND `partid` = '$ppId' AND `date` > '$ppSixMonthsAgo'")->fetch();
		$ppSixDemand = ($ppSixDemand['demand'] ?? 0) * -1;

		$ppToOrder = $ppBSL - $ppQOH - $ppOO;
		$ppToOrderRounded = 0;

		if ($ppToOrder <= 0) {
			$ppDisplay        = '0';
			$ppClass          = '';
			$ppStyle          = '';
			$ppIsWarning      = false;
		} else {
			$ppWithin         = floor($ppToOrder / $ppMOQ);
			$ppOver           = $ppToOrder % $ppMOQ;
			$ppRoundUp        = $ppMOQ - $ppOver;
			$ppToOrderRounded = ($ppWithin * $ppMOQ) + $ppMOQ;
			$ppDisplay        = "$ppToOrderRounded ($ppToOrder + $ppRoundUp)";
			$ppIsWarning      = ($ppToOrder < $ppToOrderRounded * 0.25) || ($ppOmit > 0);
			$ppClass          = $ppIsWarning ? 'fw-semibold' : 'text-primary fw-semibold';
			$ppStyle          = $ppIsWarning ? 'color:#e67e00;' : '';
		}

		// Seasonal breakdown for explain row
		$ppYrs = $dbLink->query("SELECT COUNT(DISTINCT YEAR(`date`)) AS `y` FROM `trans` WHERE `type`='BUILD' AND `partid`='$ppId'")->fetch();
		$ppNumYears = max(1, (int)($ppYrs['y'] ?? 1));

		$ppMonthQ = $dbLink->query("SELECT MONTH(`date`) AS `mo`, SUM(`qty`)*-1 AS `demand` FROM `trans` WHERE `type`='BUILD' AND `partid`='$ppId' GROUP BY MONTH(`date`)");
		$ppAvgByMonth = array_fill(1, 12, 0.0);
		while ($mrow = $ppMonthQ->fetch()) {
			$ppAvgByMonth[(int)$mrow['mo']] = (float)$mrow['demand'] / $ppNumYears;
		}

		$ppProjection = [];
		for ($i = 0; $i < ceil($ppMonthsFwd); $i++) {
			$fMo    = (($currentMonth - 1 + $i) % 12) + 1;
			$weight = min(1.0, $ppMonthsFwd - $i);
			$ppProjection[] = [
				'name'    => $monthNames[$fMo - 1],
				'avg'     => round($ppAvgByMonth[$fMo], 1),
				'weight'  => $weight,
				'contrib' => round($ppAvgByMonth[$fMo] * $weight, 1),
			];
		}
		$ppRawNeed = max(0, $ppToOrder);

		$ppKey = 'pp_' . $ppId;
		$ppIsPaused = in_array($ppId, $pausedIds);
?>
			<tr>
				<td><?php echo htmlspecialchars($ppName); ?></td>
				<td class="text-end"><?php echo $ppQOH; ?></td>
				<td class="text-end"><?php echo $ppOO; ?></td>
				<td class="text-end"><?php echo $ppTwelveDemand; ?></td>
				<td class="text-end"><?php echo $ppSixDemand; ?></td>
				<td class="text-end"><?php echo $ppBSL; ?></td>
				<td class="<?php echo $ppClass; ?>"<?php if ($ppStyle) echo ' style="'.$ppStyle.($ppIsPaused ? 'opacity:0.45;text-decoration:line-through;' : '').'"'; elseif ($ppIsPaused) echo ' style="opacity:0.45;text-decoration:line-through;"'; ?>>
					<div class="d-flex align-items-center justify-content-end gap-2">
						<span><?php echo $ppDisplay; ?></span>
						<?php if ($ppIsWarning && $ppToOrderRounded > 0): ?>
						<?php if ($ppIsPaused): ?>
						<button class="btn btn-sm" style="font-size:0.7rem;padding:1px 7px;background:#fff3cd;border:1px solid #e67e00;color:#e67e00;white-space:nowrap;" action="pauseBtn" record="<?php echo $ppId; ?>" title="Resume — include in calculations">Resume</button>
						<?php else: ?>
						<button class="btn btn-sm" style="font-size:0.7rem;padding:1px 7px;background:#fff3cd;border:1px solid #e67e00;color:#e67e00;white-space:nowrap;" action="pauseBtn" record="<?php echo $ppId; ?>" title="Pause — exclude from calculations">Pause</button>
						<?php endif; ?>
						<?php endif; ?>
						<?php if ($ppToOrderRounded > 0): ?>
						<button class="btn btn-outline-primary btn-sm" style="font-size:0.7rem;padding:1px 7px;white-space:nowrap;" action="orderBtn" record="<?php echo $ppId; ?>">+ Order</button>
						<?php endif; ?>
						<span action="explainBtn" record="<?php echo $ppId; ?>" title="How was this calculated?" style="cursor:pointer;color:#adb5bd;font-size:1rem;line-height:1;"><i class="ti ti-info-circle"></i></span>
					</div>
				</td>
				<td>
					<div class="d-flex align-items-center gap-2">
						<input type="text" id="<?php echo $ppId; ?>omitField" class="form-control form-control-sm" style="width:60px;" value="<?php echo $ppOmit; ?>" />
						<button class="btn btn-outline-secondary btn-sm" action="changeOmitButton" record="<?php echo $ppId; ?>">Set</button>
					</div>
				</td>
			</tr>
			<tr id="explainRow_<?php echo $ppId; ?>" style="display:none;">
				<td colspan="8" style="background:#fffef5;border-top:none;padding:14px 18px;">
<?php
		$ppEffective   = $ppQOH + $ppOO;
		$ppShortfall   = $ppBSL - $ppEffective;
		$ppArrivalDate = date('M j', strtotime("+{$ppLeadTime} days"));
		$ppWindowEnd   = date('M j', strtotime("+{$ppWindowDays} days"));
?>
					<div class="d-flex align-items-center justify-content-between mb-3">
						<div class="fw-semibold" style="font-size:0.88rem;color:#333;"><?php echo htmlspecialchars($ppName); ?></div>
						<button action="explainBtn" record="<?php echo $ppId; ?>" class="btn btn-sm btn-outline-secondary">Close</button>
					</div>

					<p style="font-size:0.83rem;margin-bottom:10px;">
						<?php if ($ppLeadTime > 0): ?>
						This part has a <strong><?php echo $ppLeadTime; ?>-day lead time</strong>, meaning any order placed today won't arrive until around <strong><?php echo $ppArrivalDate; ?></strong>.
						Adding a <?php echo $safetyBuffer; ?>-day safety buffer, your stocking target needs to cover the next <strong><?php echo $ppWindowDays; ?> days</strong> (through approximately <?php echo $ppWindowEnd; ?>).
						<?php else: ?>
						With a <?php echo $safetyBuffer; ?>-day safety buffer applied, your stocking target covers the next <strong><?php echo $ppWindowDays; ?> days</strong> (through approximately <?php echo $ppWindowEnd; ?>).
						<?php endif; ?>
					</p>

					<p style="font-size:0.83rem;margin-bottom:6px;">
						Based on your historical build demand, here is the average usage expected during that window, weighted by seasonal patterns:
					</p>

					<table class="table table-sm table-bordered mb-3" style="font-size:0.8rem;width:auto;">
						<thead>
							<tr style="background:#f1f3f5;">
								<th>Month</th>
								<th class="text-end">Hist. Avg/Mo</th>
								<th class="text-end">Coverage</th>
								<th class="text-end">Projected Use</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($ppProjection as $pr): ?>
							<tr>
								<td><?php echo $pr['name']; ?></td>
								<td class="text-end"><?php echo $pr['avg'] > 0 ? $pr['avg'] : '<span class="text-muted">—</span>'; ?></td>
								<td class="text-end"><?php echo $pr['weight'] >= 1.0 ? 'Full' : round($pr['weight'] * 100) . '%'; ?></td>
								<td class="text-end"><?php echo $pr['contrib'] > 0 ? $pr['contrib'] : '<span class="text-muted">—</span>'; ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
						<tfoot>
							<tr style="background:#f1f3f5;">
								<td colspan="3" class="fw-semibold">Total projected use = Best Stocking Level (BSL)</td>
								<td class="text-end fw-semibold"><?php echo $ppBSL; ?></td>
							</tr>
						</tfoot>
					</table>

					<p style="font-size:0.83rem;margin-bottom:10px;">
						The sum of this projected demand is <strong><?php echo $ppBSL; ?> units</strong> — your <strong>Best Stocking Level</strong>.
						This is the minimum you should have on hand <?php echo $ppToOrderRounded > 0 ? '<em>right now</em>' : ''; ?> to ensure you won't run out before a new order arrives.
					</p>

					<p style="font-size:0.83rem;margin-bottom:<?php echo $ppToOrderRounded > 0 ? '10' : '0'; ?>px;">
						<?php if ($ppToOrderRounded > 0): ?>
						Your current position is <strong><?php echo $ppQOH; ?> on hand</strong><?php echo $ppOO > 0 ? " + <strong>{$ppOO} on order</strong>" : ''; ?> = <strong><?php echo $ppEffective; ?> effective units</strong>.
						That leaves you <strong><?php echo $ppShortfall; ?> units short</strong> of your BSL of <?php echo $ppBSL; ?>.
						<?php else: ?>
						Your current position is <strong><?php echo $ppQOH; ?> on hand</strong><?php echo $ppOO > 0 ? " + <strong>{$ppOO} on order</strong>" : ''; ?> = <strong><?php echo $ppEffective; ?> effective units</strong> — which meets or exceeds your BSL of <?php echo $ppBSL; ?>. <strong>No order is needed at this time.</strong>
						<?php endif; ?>
					</p>

					<?php if ($ppToOrderRounded > 0): ?>
					<p style="font-size:0.83rem;margin-bottom:0;">
						Rounding <?php echo $ppRawNeed; ?> up to the nearest MOQ of <?php echo number_format($ppMOQ); ?> units, the suggested order quantity is <strong><?php echo number_format($ppToOrderRounded); ?> units</strong> (<?php echo $ppRawNeed; ?> needed + <?php echo $ppToOrderRounded - $ppRawNeed; ?> to reach the next MOQ).
					</p>
					<?php endif; ?>

				</td>
			</tr>
			<?php if ($ppToOrderRounded > 0): ?>
			<tr id="orderRow_<?php echo $ppId; ?>" style="display:none;">
				<td colspan="8" style="background:#f0f4ff;border-top:none;">
					<div class="d-flex align-items-center gap-3 py-1">
						<span class="text-muted fw-semibold" style="font-size:0.8rem;">New Order for <?php echo htmlspecialchars($ppName); ?></span>
						<input type="text" id="orderRef_<?php echo $ppId; ?>" class="form-control form-control-sm" style="width:160px;" placeholder="Order / PO #" />
						<input type="number" id="orderQty_<?php echo $ppId; ?>" class="form-control form-control-sm" style="width:90px;" value="<?php echo $ppToOrderRounded; ?>" />
						<button class="btn btn-primary btn-sm" action="submitOrderBtn" record="<?php echo $ppId; ?>">Place Order</button>
						<button class="btn btn-outline-secondary btn-sm" action="cancelOrderBtn" record="<?php echo $ppId; ?>">Cancel</button>
					</div>
				</td>
			</tr>
			<?php endif; ?>
<?php } ?>
			</tbody>
		</table>
	</div>
</div>

<!-- ROD ORDERS -->
<?php

	$rodsOH = ($dbLink->query("SELECT SUM(`qoh`) AS `v` FROM `parts` WHERE `partno` LIKE 'RD%'")->fetch()['v'] ?? 0) / 2;
	$rodsOO = ($dbLink->query("SELECT SUM(`qty` - `recqty`) AS `v` FROM `orders` WHERE `partid` IN (SELECT `id` FROM `parts` WHERE `partno` LIKE 'RD%') AND (`qty` - `recqty`) > 0")->fetch()['v'] ?? 0) / 2;
	$effectiveRods    = $rodsOH + $rodsOO;
	$rodsOrder        = $effectiveCams - $effectiveRods;
	$toOrderRodsRounded = 0;
	if ($rodsOrder > 0) {
		$withinRodsMOQ      = floor($rodsOrder / 2500);
		$overRodsMOQ        = $rodsOrder % 2500;
		$roundRodsUp        = 2500 - $overRodsMOQ;
		$toOrderRodsRounded = ($withinRodsMOQ * 2500) + 2500;
		$toOrderRodsDisplay = "$toOrderRodsRounded ($rodsOrder + $roundRodsUp)";
		$rodsClass          = 'text-primary fw-semibold';
	} else {
		$toOrderRodsDisplay = '0'; $rodsClass = '';
	}

	$rodIdRows = $dbLink->query("SELECT `id` FROM `parts` WHERE `partno` LIKE 'RD%'");
	$rodIds = [];
	while ($r = $rodIdRows->fetch()) $rodIds[] = $r['id'];

?>

<h5 class="fw-bold mb-3">Rod Order Suggestions</h5>
<div class="card mb-4">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Component</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">On-Hand</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">On-Order</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Effective</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Cam Demand</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Need</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">To Order</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>Rods (Pairs)</td>
					<td class="text-end"><?php echo $rodsOH; ?></td>
					<td class="text-end"><?php echo $rodsOO; ?></td>
					<td class="text-end"><?php echo $effectiveRods; ?></td>
					<td class="text-end"><?php echo $effectiveCams; ?></td>
					<td class="text-end"><?php echo $rodsOrder; ?></td>
					<td class="text-end <?php echo $rodsClass; ?>">
						<div class="d-flex align-items-center justify-content-end gap-2">
							<span><?php echo $toOrderRodsDisplay; ?></span>
							<?php if ($toOrderRodsRounded > 0): ?>
							<button class="btn btn-outline-primary btn-sm" style="font-size:0.7rem;padding:1px 7px;white-space:nowrap;" action="uniOrderBtn" ukey="rods">+ Order</button>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php if ($toOrderRodsRounded > 0): ?>
				<tr id="uniOrderRow_rods" style="display:none;">
					<td colspan="7" style="background:#f0f4ff;border-top:none;">
						<div class="d-flex align-items-center gap-3 py-1">
							<span class="text-muted fw-semibold" style="font-size:0.8rem;">New Order — Rods (<?php echo implode(' + ', array_map(fn($id) => $dbLink->query("SELECT partno FROM parts WHERE id='$id'")->fetch()['partno'], $rodIds)); ?>)</span>
							<input type="text" id="uniOrderRef_rods" class="form-control form-control-sm" style="width:160px;" placeholder="Order / PO #" />
							<input type="number" id="uniOrderQty_rods" class="form-control form-control-sm" style="width:90px;" value="<?php echo $toOrderRodsRounded; ?>" />
							<button class="btn btn-primary btn-sm" action="submitUniOrderBtn" ukey="rods" parts="<?php echo htmlspecialchars(implode(',', $rodIds)); ?>">Place Order</button>
							<button class="btn btn-outline-secondary btn-sm" action="cancelUniOrderBtn" ukey="rods">Cancel</button>
						</div>
					</td>
				</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

<!-- PACKAGE CARDS -->
<h5 class="fw-bold mb-3">Package Card Order Suggestions</h5>
<div class="card mb-4">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Card</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">On-Hand</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">On-Order</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Effective</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Cam Demand</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Need</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">To Order</th>
				</tr>
			</thead>
			<tbody>
<?php

	$cards = $dbLink->query("SELECT * FROM `parts` WHERE `partno` LIKE 'CD-%' ORDER BY `partno` ASC");

	while ($card = $cards->fetch()) {

		$cardOH   = $card['qoh'];
		$partId   = $card['id'];
		$partNum  = $card['partno'];
		$partDesc = $card['desc'];

		$cardOO = $dbLink->query("SELECT SUM(`qty`) - SUM(`recqty`) AS `v` FROM `orders` WHERE `partid` = '$partId'")->fetch()['v'] ?? 0;
		if (!$cardOO) $cardOO = 0;
		$cardEffective = $cardOH + $cardOO;

		$camCode   = substr($partNum, 3);
		$camPartNum = "CS-" . $camCode;
		$camInfo   = $dbLink->query("SELECT * FROM `parts` WHERE `partno` = '$camPartNum'")->fetch();
		$camOH     = $camInfo['qoh'] ?? 0;
		$camId     = $camInfo['id'] ?? null;
		$camOmit   = $camInfo['omit'] ?? 0;
		$camOO     = $camId ? ($dbLink->query("SELECT SUM(`qty`) - SUM(`recqty`) AS `v` FROM `orders` WHERE `partid` = '$camId'")->fetch()['v'] ?? 0) : 0;
		$camEffective = $camOH + $camOO + ($cardArray[$camId] ?? 0);

		$cardOrder = $camEffective - $cardEffective - $camOmit;

		if ($cardOrder > 0) {
			$withinCardMOQ    = floor($cardOrder / 1000);
			$overCardMOQ      = $cardOrder % 1000;
			$roundCardUp      = 1000 - $overCardMOQ;
			$toOrderCardRounded = ($withinCardMOQ * 1000) + 1000;
			$toOrderCardDisplay = "$toOrderCardRounded ($cardOrder + $roundCardUp)";
			$cardClass = 'text-primary fw-semibold';
		} else {
			$toOrderCardRounded = 0;
			$toOrderCardDisplay = '0';
			$cardClass = '';
		}

?>
				<tr>
					<td><?php echo htmlspecialchars($partDesc); ?></td>
					<td class="text-end"><?php echo $cardOH; ?></td>
					<td class="text-end"><?php echo $cardOO; ?></td>
					<td class="text-end"><?php echo $cardEffective; ?></td>
					<td class="text-end"><?php echo $camEffective; ?></td>
					<td class="text-end"><?php echo $cardOrder; ?></td>
					<td class="text-end <?php echo $cardClass; ?>">
						<div class="d-flex align-items-center justify-content-end gap-2">
							<span><?php echo $toOrderCardDisplay; ?></span>
							<?php if ($toOrderCardRounded > 0): ?>
							<button class="btn btn-outline-primary btn-sm" style="font-size:0.7rem;padding:1px 7px;white-space:nowrap;" action="uniOrderBtn" ukey="card_<?php echo $partId; ?>">+ Order</button>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php if ($toOrderCardRounded > 0): ?>
				<tr id="uniOrderRow_card_<?php echo $partId; ?>" style="display:none;">
					<td colspan="7" style="background:#f0f4ff;border-top:none;">
						<div class="d-flex align-items-center gap-3 py-1">
							<span class="text-muted fw-semibold" style="font-size:0.8rem;">New Order — <?php echo htmlspecialchars($partDesc); ?></span>
							<input type="text" id="uniOrderRef_card_<?php echo $partId; ?>" class="form-control form-control-sm" style="width:160px;" placeholder="Order / PO #" />
							<input type="number" id="uniOrderQty_card_<?php echo $partId; ?>" class="form-control form-control-sm" style="width:90px;" value="<?php echo $toOrderCardRounded; ?>" />
							<button class="btn btn-primary btn-sm" action="submitUniOrderBtn" ukey="card_<?php echo $partId; ?>" parts="<?php echo $partId; ?>">Place Order</button>
							<button class="btn btn-outline-secondary btn-sm" action="cancelUniOrderBtn" ukey="card_<?php echo $partId; ?>">Cancel</button>
						</div>
					</td>
				</tr>
				<?php endif; ?>
<?php } ?>
			</tbody>
		</table>
	</div>
</div>

<!-- AMAZON PACK CARDS -->
<h5 class="fw-bold mb-3">Amazon Pack Card Order Suggestions</h5>
<p class="text-muted mb-3" style="font-size:0.8rem;"><i class="ti ti-info-circle me-1"></i>Amazon pack cards are calculated independently based on their own build demand history and upcoming seasonality — the same method used for camshafts.</p>
<div class="card mb-4">
	<div class="card-body p-0">
		<table class="table table-sm table-bordered mb-0">
			<thead>
				<tr style="background-color:#e2e5e8;">
					<th class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">Card</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">QOH</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">QOO</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;width:70px;">BSL</th>
					<th class="text-muted fw-semibold text-end" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">To Order</th>
				</tr>
			</thead>
			<tbody>
<?php

	$amazonCards = $dbLink->query("SELECT * FROM `parts` WHERE `partno` LIKE 'CDA-%' ORDER BY `partno` ASC");

	while ($ac = $amazonCards->fetch()) {

		$acId   = $ac['id'];
		$acOH   = $ac['qoh'];
		$acBSL  = $ac['bsl'];
		$acDesc = $ac['desc'];
		$acMOQ  = $ac['imoq'] ?: 1000;

		$acOO = (float)($dbLink->query("SELECT SUM(`qty`) - SUM(`recqty`) AS `v` FROM `orders` WHERE `partid` = '$acId'")->fetch()['v'] ?? 0);
		if ($acOO < 0) $acOO = 0;

		$acToOrder = $acBSL - $acOH - $acOO;

		if ($acToOrder > 0) {
			$acWithin   = floor($acToOrder / $acMOQ);
			$acOver     = $acToOrder % $acMOQ;
			$acRoundup  = $acMOQ - $acOver;
			$acRounded  = ($acWithin * $acMOQ) + $acMOQ;
			$acDisplay  = "$acRounded ($acToOrder + $acRoundup)";
			$acClass    = 'text-primary fw-semibold';
		} else {
			$acRounded = 0;
			$acDisplay = '0';
			$acClass   = '';
		}

?>
				<tr>
					<td><?php echo htmlspecialchars($acDesc); ?></td>
					<td class="text-end"><?php echo $acOH; ?></td>
					<td class="text-end"><?php echo $acOO; ?></td>
					<td class="text-end"><?php echo $acBSL; ?></td>
					<td class="text-end <?php echo $acClass; ?>"><?php echo $acDisplay; ?></td>
				</tr>
<?php } ?>
			</tbody>
		</table>
	</div>
</div>

<script>
	$("[action=changeOmitButton]").click(function() {
		var record = $(this).attr('record');
		var amount = $("#" + record + "omitField").val();
		$.post('/ajax/change_omit.php', { record: record, amount: amount }, function() {
			location.reload();
		});
	});

	$("[action=explainBtn]").on('click', function() {
		var record = $(this).attr('record');
		var $row = $("#explainRow_" + record);
		$row.is(':visible') ? $row.slideUp(150) : $row.slideDown(150);
	});

	$("[action=orderBtn]").click(function() {
		var record = $(this).attr('record');
		var $row = $("#orderRow_" + record);
		$row.is(':visible') ? $row.hide() : $row.show();
	});

	$("[action=cancelOrderBtn]").click(function() {
		var record = $(this).attr('record');
		$("#orderRow_" + record).hide();
	});

	$("[action=submitOrderBtn]").click(function() {
		var record = $(this).attr('record');
		var refnum = $.trim($("#orderRef_" + record).val());
		var qty    = $("#orderQty_" + record).val();
		if (!refnum) { alert("Please enter an order / PO number."); return; }
		if (!qty || qty <= 0) { alert("Please enter a valid quantity."); return; }
		$.post('/ajax/add_order.php', { partid: record, qty: qty, refnum: refnum }, function() {
			location.reload();
		});
	});

	// Pause button — toggles a cam ID in the ?paused= URL param and reloads
	$("[action=pauseBtn]").click(function() {
		var id     = String($(this).attr('record'));
		var url    = new URL(window.location.href);
		var paused = url.searchParams.get('paused') ? url.searchParams.get('paused').split(',').filter(Boolean) : [];
		var idx    = paused.indexOf(id);
		if (idx === -1) { paused.push(id); } else { paused.splice(idx, 1); }
		if (paused.length) { url.searchParams.set('paused', paused.join(',')); }
		else               { url.searchParams.delete('paused'); }
		window.location.href = url.toString();
	});

	// Universal components + package cards order buttons
	$("[action=uniOrderBtn]").click(function() {
		var key  = $(this).attr('ukey');
		var $row = $("#uniOrderRow_" + key);
		$row.is(':visible') ? $row.hide() : $row.show();
	});

	$("[action=cancelUniOrderBtn]").click(function() {
		$("#uniOrderRow_" + $(this).attr('ukey')).hide();
	});

	$("[action=submitUniOrderBtn]").click(function() {
		var key    = $(this).attr('ukey');
		var parts  = $(this).attr('parts').split(',');
		var refnum = $.trim($("#uniOrderRef_" + key).val());
		var qty    = parseInt($("#uniOrderQty_" + key).val(), 10);
		if (!refnum) { alert("Please enter an order / PO number."); return; }
		if (!qty || qty <= 0) { alert("Please enter a valid quantity."); return; }
		var remaining = parts.length;
		$.each(parts, function(i, partid) {
			$.post('/ajax/add_order.php', { partid: $.trim(partid), qty: qty, refnum: refnum }, function() {
				if (--remaining === 0) location.reload();
			});
		});
	});
</script>

<?php require_once(__DIR__."/../includes/footer.php"); ?>
