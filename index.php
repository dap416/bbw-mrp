<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");
	if (!has_access('inventory')) { deny_access(); }

	$dbLink = db_connect();
	$warehouses = get_warehouses($dbLink);
	$warehouseJson = json_encode(array_column($warehouses, 'name', 'id'));

	$parts = $dbLink->query("SELECT * FROM `parts` ORDER BY `partno` ASC");

	$vendors = $dbLink->query("SELECT * FROM `vendors` ORDER BY `name` ASC");

	// GLOBAL MONTHLY BUILD CHART DATA
	$globalMonthLabels = [];
	$globalMonthData   = [];
	for ($i = 11; $i >= 0; $i--) {
		$globalMonthLabels[] = date('M Y', strtotime("-$i months"));
		$globalMonthData[date('Y-m', strtotime("-$i months"))] = 0;
	}
	$globalTwelveMonthsAgo = date("Y-m-d H:i:s", strtotime("12 months ago"));
	$globalBuildByMonth = $dbLink->query("SELECT DATE_FORMAT(`date`, '%Y-%m') AS `mo`, SUM(`qty`) AS `qty` FROM `trans` WHERE `type` = 'BUILD' AND `date` > '$globalTwelveMonthsAgo' GROUP BY `mo`");
	while ($row = $globalBuildByMonth->fetch()) {
		if (isset($globalMonthData[$row['mo']])) {
			$globalMonthData[$row['mo']] = abs($row['qty']);
		}
	}
	// GLOBAL FUTURE DEMAND CHART — same concept as per-part charts
	$globalFutureDemand = array_values($globalMonthData); // past 12 months as projected next 12
	$globalTotalQoh     = (int)($dbLink->query("SELECT SUM(`qoh`) AS `t` FROM `parts`")->fetch()['t'] ?? 0);
	$globalTotalOnOrder = (float)($dbLink->query("SELECT SUM(`qty` - `recqty`) AS `t` FROM `orders` WHERE `qty` > `recqty`")->fetch()['t'] ?? 0);
	$globalLeadMonths   = 2; // 45-day global default

	$globalAllLabels = ['Now'];
	for ($i = 1; $i <= 12; $i++) {
		$globalAllLabels[] = date('M Y', strtotime("+$i months"));
	}

	$globalAllBuild = array_merge([null], $globalFutureDemand);

	$globalAllOrder = [];
	for ($i = 0; $i <= 12; $i++) {
		$idx = $i + $globalLeadMonths;
		$globalAllOrder[] = ($idx <= 12) ? $globalAllBuild[$idx] : null;
	}

	$globalQohLine = [(int)$globalTotalQoh];
	$gProjQoh      = (float)$globalTotalQoh;
	for ($i = 1; $i <= 12; $i++) {
		$gProjQoh -= $globalFutureDemand[$i - 1];
		if ($i === $globalLeadMonths) { $gProjQoh += $globalTotalOnOrder; }
		$globalQohLine[] = max(0, (int)round($gProjQoh));
	}

	$globalLabelsJson = json_encode($globalAllLabels);
	$globalDataJson   = json_encode($globalAllBuild);
	$globalOrderJson  = json_encode($globalAllOrder);
	$globalQohJson    = json_encode($globalQohLine);

	?>

		<!-- GLOBAL BUILD USAGE CHART -->
		<div id="globalChartCard" class="card mb-4">
			<div class="card-body">
				<div class="d-flex align-items-center justify-content-between mb-3">
					<h6 class="fw-semibold mb-0 text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">12-Month Forward Build Demand (Based on Prior 12 Months Build Demand)</h6>
					<a href="#" id="toggleGlobalChart" class="small text-muted" style="text-decoration:none;">Hide</a>
				</div>
				<div style="position:relative; width:100%;">
					<canvas id="globalBuildChart"></canvas>
				</div>
			</div>
		</div>
		<a href="#" id="showGlobalChart" class="small text-muted d-none mb-4 d-block" style="text-decoration:none;">Show build demand chart for all components</a>
		<script>
			document.addEventListener('DOMContentLoaded', function() {
				document.getElementById('toggleGlobalChart').addEventListener('click', function(e) {
					e.preventDefault();
					document.getElementById('globalChartCard').classList.add('d-none');
					document.getElementById('showGlobalChart').classList.remove('d-none');
				});
				document.getElementById('showGlobalChart').addEventListener('click', function(e) {
					e.preventDefault();
					document.getElementById('globalChartCard').classList.remove('d-none');
					document.getElementById('showGlobalChart').classList.add('d-none');
				});

				var ctx = document.getElementById('globalBuildChart').getContext('2d');
				window.chartInst_global = new Chart(ctx, {
					type: 'line',
					data: {
						labels: <?php echo $globalLabelsJson; ?>,
						datasets: [
							{
								label: 'Units Used in Build',
								data: <?php echo $globalDataJson; ?>,
								borderColor: '#4680ff',
								backgroundColor: 'rgba(70,128,255,0.1)',
								borderWidth: 2,
								pointRadius: 3,
								tension: 0.3,
								fill: true
							},
						]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						plugins: {
							legend: {
								display: true,
								position: 'bottom',
								labels: { boxWidth: 12, font: { size: 11 } }
							}
						},
						scales: {
							y: {
								beginAtZero: true,
								ticks: { precision: 0 }
							}
						}
					}
				});
				setPartChartHeight(window.chartInst_global);
			});
		</script>

		<div class="mb-3">
			<button id="addAreaButton" class="btn btn-light-primary">+ Add New Component</button>
		</div>

		<!-- ADD NEW COMPONENT FORM -->
		<div id="addArea" class="hidden mb-3">
			<div class="card">
			<div class="card-body">
				<!-- Horizontal layout on wide screens, vertical on small -->
				<div class="d-none d-xl-flex align-items-center gap-2">
					<input type="text" id="addMFG" class="form-control form-control-sm" style="width:140px" placeholder="MFG Part #"/>
					<input type="text" id="addPartno" class="form-control form-control-sm" style="width:140px" placeholder="Part #"/>
					<input type="text" id="addDesc" class="form-control form-control-sm" style="width:200px" placeholder="Description"/>
					<div class="input-group input-group-sm" style="width:100px">
						<span class="input-group-text">$</span>
						<input type="text" id="addCost" class="form-control form-control-sm" placeholder="Cost"/>
					</div>
					<input type="text" id="addIMOQ" class="form-control form-control-sm" style="width:80px" placeholder="IMOQ"/>
					<select id="addManufacturer" class="form-select form-select-sm" style="width:180px">
						<option value="0">Select Manufacturer</option>
						<?php
						$mfgList = $dbLink->query("SELECT `id`,`name` FROM `manufacturers` ORDER BY `name` ASC");
						while($mfgRow = $mfgList->fetch()) { ?>
						<option value="<?php echo $mfgRow['id']; ?>"><?php echo htmlspecialchars($mfgRow['name']); ?></option>
						<?php } ?>
					</select>
					<button id="addButton" class="btn btn-primary btn-sm">Add</button>
				</div>
				<!-- Vertical layout for smaller screens -->
				<div class="d-flex d-xl-none flex-column gap-2" style="max-width:340px;">
					<input type="text" id="addMFG2" class="form-control form-control-sm" placeholder="MFG Part #"/>
					<input type="text" id="addPartno2" class="form-control form-control-sm" placeholder="Part #"/>
					<input type="text" id="addDesc2" class="form-control form-control-sm" placeholder="Description"/>
					<div class="input-group input-group-sm">
						<span class="input-group-text">$</span>
						<input type="text" id="addCost2" class="form-control form-control-sm" placeholder="Cost"/>
					</div>
					<input type="text" id="addIMOQ2" class="form-control form-control-sm" placeholder="IMOQ"/>
					<select id="addManufacturer2" class="form-select form-select-sm">
						<option value="0">Select Manufacturer</option>
						<?php
						$mfgList2 = $dbLink->query("SELECT `id`,`name` FROM `manufacturers` ORDER BY `name` ASC");
						while($mfgRow2 = $mfgList2->fetch()) { ?>
						<option value="<?php echo $mfgRow2['id']; ?>"><?php echo htmlspecialchars($mfgRow2['name']); ?></option>
						<?php } ?>
					</select>
					<button id="addButton2" class="btn btn-primary btn-sm">Add</button>
				</div>
			</div>
			</div>
		</div>
		

		<!-- DISPLAY INVENTORY AREA -->

		<?php

		// Category definitions — longest prefix first to ensure correct matching
		$categoryPrefixes = [
			'CDA' => 'Package Cards',
			'CD'  => 'Package Cards',
			'CS'  => 'Camshafts',
			'MC'  => 'Packaging',
			'PC'  => 'Packaging',
			'PL'  => 'Splash Plates',
			'RC'  => 'Packaging',
			'RD'  => 'Rods',
		];

		function getPartCategory($partno, $prefixes) {
			foreach ($prefixes as $prefix => $category) {
				if (strpos(strtoupper($partno), strtoupper($prefix)) === 0) {
					return $category;
				}
			}
			return 'Other';
		}

		// Fetch all parts and group by category
		$partList = $dbLink->query("SELECT * FROM `parts` ORDER BY `partno` ASC");
		$grouped = [];
		$partRows = [];

		while ($part = $partList->fetch()) {
			$cat = getPartCategory($part['partno'], $categoryPrefixes);
			$grouped[$cat][] = $part;
		}

		// ── BATCH PREFETCH (avoids per-part N+1 queries inside the render loop) ──
		$prefetchTwelveAgo = date("Y-m-d H:i:s", strtotime("12 months ago"));

		// Manufacturers (once, reused for every part's dropdown + name lookup)
		$allMfgs = $dbLink->query("SELECT `id`,`name` FROM `manufacturers` ORDER BY `name` ASC")->fetchAll();
		$mfgNameMap = [];
		foreach ($allMfgs as $m) { $mfgNameMap[$m['id']] = $m['name']; }

		// On-order qty per part
		$ooMap = [];
		foreach ($dbLink->query("SELECT `partid`, SUM(`qty` - `recqty`) AS `oo` FROM `orders` WHERE `qty` > `recqty` GROUP BY `partid`") as $r) {
			$ooMap[$r['partid']] = $r['oo'];
		}

		// 12-month BUILD demand per part
		$demandMap = [];
		foreach ($dbLink->query("SELECT `partid`, SUM(`qty`) AS `demand` FROM `trans` WHERE `type` = 'BUILD' AND `date` > '$prefetchTwelveAgo' GROUP BY `partid`") as $r) {
			$demandMap[$r['partid']] = $r['demand'];
		}

		// Monthly BUILD totals per part (for the per-part chart)
		$buildMonthMap = [];
		foreach ($dbLink->query("SELECT `partid`, DATE_FORMAT(`date`, '%Y-%m') AS `mo`, SUM(`qty`) AS `qty` FROM `trans` WHERE `type` = 'BUILD' AND `date` > '$prefetchTwelveAgo' GROUP BY `partid`, `mo`") as $r) {
			$buildMonthMap[$r['partid']][$r['mo']] = $r['qty'];
		}

		// Per-warehouse qty per part
		$whQtyAll = [];
		foreach ($dbLink->query("SELECT `part_id`, `warehouse_id`, `qty` FROM `part_warehouse_qty`") as $r) {
			$whQtyAll[$r['part_id']][$r['warehouse_id']] = (int)$r['qty'];
		}

		// Last 20 transactions per part (single query, capped per part in PHP)
		$transMap = [];
		foreach ($dbLink->query("SELECT t.*, u.name AS user_name, w.name AS wh_name
			FROM `trans` t
			LEFT JOIN `users` u ON t.user_id = u.id
			LEFT JOIN `warehouses` w ON w.id = t.warehouse_id
			ORDER BY t.`partid` ASC, t.`date` DESC") as $r) {
			$pid = $r['partid'];
			if (!isset($transMap[$pid])) { $transMap[$pid] = []; }
			if (count($transMap[$pid]) < 20) { $transMap[$pid][] = $r; }
		}

		// Define display order
		$categoryOrder = ['Camshafts', 'Package Cards', 'Packaging', 'Rods', 'Splash Plates'];

		?>

		<div class="card mt-3">
		<div class="card-body p-0">
		<table class="table table-hover mb-0">
			<tbody>

		<?php

		foreach ($categoryOrder as $categoryName) {
			if (empty($grouped[$categoryName])) continue;

			$catSlug = strtolower(preg_replace('/[^a-z0-9]/i', '-', $categoryName));
			$count = count($grouped[$categoryName]);
			$catQoh = array_sum(array_column($grouped[$categoryName], 'qoh'));
			$catCosts = array_column($grouped[$categoryName], 'cost');
			$catAvgCost = $count > 0 ? array_sum($catCosts) / $count : 0;
			$catOhVal = array_sum(array_map(fn($p) => $p['qoh'] * $p['cost'], $grouped[$categoryName]));

		?>
			<!-- Category Header Row -->
			<tr class="category-header table-light" data-cat="<?php echo $catSlug; ?>" style="cursor:pointer;">
				<td class="fw-semibold text-muted" colspan="2">
					<i class="ti ti-chevron-right me-2 category-arrow"></i>
					<?php echo $categoryName; ?>
					<span class="badge bg-light-secondary text-secondary ms-2"><?php echo $count; ?> parts</span>
				</td>
				<td class="text-muted"><span class="small text-muted me-1">On-Hand</span><strong><?php echo number_format($catQoh); ?></strong></td>
				<td class="text-muted" colspan="2"><span class="small text-muted me-1">Avg Cost</span><strong>$<?php echo number_format($catAvgCost, 2); ?></strong></td>
				<td class="text-muted" colspan="3"><span class="small text-muted me-1">OH Value</span><strong>$<?php echo number_format($catOhVal, 2); ?></strong></td>
			</tr>
			<tr class="category-row category-row-<?php echo $catSlug; ?> table-light" style="display:none">
				<th>Part #</th>
				<th>Description</th>
				<th>Manufacturer</th>
				<th>QOH</th>
				<th>QOO</th>
				<th>Cost EA</th>
				<th colspan="2">OH Val</th>
			</tr>

		<?php foreach ($grouped[$categoryName] as $part) {
			extract($part);
			$extVal = number_format($cost * $qoh, 2);
			$onOrder = $ooMap[$id] ?? 0;
			$mfgName = $mfgNameMap[$manufacturer] ?? '';
		?>

			<tr class="category-row category-row-<?php echo $catSlug; ?>" action="showTrans" record="<?php echo $id; ?>" style="display:none; cursor:pointer;">
				<td><?php echo $partno; ?></td>
				<td><?php echo $desc; ?></td>
				<td><?php echo htmlspecialchars($mfgName); ?></td>
				<td id="<?php echo $id; ?>rowQoh"><?php echo $qoh; ?></td>
				<td><?php echo $onOrder; ?></td>
				<td>$<?php echo $cost; ?></td>
				<td>$<?php echo $extVal; ?></td>
				<td></td>
			</tr>

			<tr class="category-row category-row-<?php echo $catSlug; ?>" style="display:none">
			<td colspan="8" class="p-0" style="border-top: none;">
			<div class="hidden" id="<?php echo $id; ?>transArea">

			<!-- STOCKING DATA -->
				
			<?php
			// GET BUILD HISTORY
			$twelveMonthsAgo = date("Y-m-d H:i:s", strtotime("12 months ago"));
			$twelveDemand = $demandMap[$id] ?? 0;
			$twelveDemand = $twelveDemand * -1;

			// MONTHLY BUILD DATA FOR CHART
			$chartMonthLabels = [];
			$chartMonthData   = [];
			for ($i = 11; $i >= 0; $i--) {
				$chartMonthLabels[] = date('M Y', strtotime("-$i months"));
				$chartMonthData[date('Y-m', strtotime("-$i months"))] = 0;
			}
			foreach (($buildMonthMap[$id] ?? []) as $mo => $moQty) {
				if (isset($chartMonthData[$mo])) {
					$chartMonthData[$mo] = abs($moQty);
				}
			}
			// FUTURE DEMAND CHART — three linked lines:
			// 1. Build demand  : past 12 months replayed as projected next 12 months
			// 2. Order qty needed: qty to order each month to keep QOH ≥ 50
			//                      (receipt arrives leadMonths later)
			// 3. Projected QOH : starts at current QOH, depletes by demand,
			//                    receives orders on schedule
			$leadMonths     = max(1, (int)ceil($lead_time / 30));
			$futureDemand   = array_values($chartMonthData); // indices 0-11 = months +1 to +12

			$allLabels = ['Now'];
			for ($i = 1; $i <= 12; $i++) {
				$allLabels[] = date('M Y', strtotime("+$i months"));
			}

			// Build array: null at index 0 (Now), then 12 projected months
			$allBuildArr = array_merge([null], $futureDemand);

			// THREE-LINE SIMULATION — all three datasets are linked:
			//   Line 1: Build demand     — allBuildArr (above)
			//   Line 2: Order qty needed — qty to order each month so QOH never drops below $minQoh
			//                              receipt arrives leadMonths later, lifting the QOH line
			//   Line 3: Projected QOH   — depletes by demand, receives scheduled orders
			$minQoh      = 50;
			$pending     = []; // pending[$month] = qty arriving that month

			// Seed with current real on-order
			if ((float)$onOrder > 0 && $leadMonths <= 12) {
				$pending[$leadMonths] = (float)$onOrder;
			}

			$qohLineArr  = [(int)$qoh];
			$orderQtyArr = [];
			$runQoh      = (float)$qoh;

			// "Now" (index 0): project QOH leadMonths out; order if QOH will drop below $minQoh
			$proj = $runQoh;
			for ($j = 1; $j <= min($leadMonths, 12); $j++) {
				$proj -= (float)($futureDemand[$j - 1] ?? 0);
				$proj += (float)($pending[$j] ?? 0);
			}
			$orderNow = max(0.0, $minQoh - $proj);
			$orderQtyArr[] = $orderNow > 0 ? (int)round($orderNow) : null;
			if ($orderNow > 0 && $leadMonths <= 12) {
				$pending[$leadMonths] = ($pending[$leadMonths] ?? 0) + $orderNow;
			}

			for ($m = 1; $m <= 12; $m++) {
				// Advance QOH
				$runQoh -= (float)($futureDemand[$m - 1] ?? 0);
				$runQoh += (float)($pending[$m] ?? 0);
				$runQoh  = max(0.0, $runQoh);
				$qohLineArr[] = (int)round($runQoh);

				// Order qty needed at month $m (arrives at $m + leadMonths)
				$arrive = $m + $leadMonths;
				if ($arrive <= 12) {
					// Project QOH at arrival month using already-scheduled pending orders
					$proj = $runQoh;
					for ($j = $m + 1; $j <= $arrive; $j++) {
						$proj -= (float)($futureDemand[$j - 1] ?? 0);
						$proj += (float)($pending[$j] ?? 0);
					}
					$orderNeeded = max(0.0, $minQoh - $proj);
					if ($orderNeeded > 0) {
						$orderQtyArr[] = (int)round($orderNeeded);
						$pending[$arrive] = ($pending[$arrive] ?? 0) + $orderNeeded;
					} else {
						$orderQtyArr[] = null;
					}
				} else {
					$orderQtyArr[] = null;
				}
			}

			$chartLabelsJson = json_encode($allLabels);
			$chartDataJson   = json_encode($allBuildArr);
			$orderLineJson   = json_encode($orderQtyArr);
			$qohLineJson     = json_encode($qohLineArr);

			$toOrder = $bsl - $qoh - $onOrder;
			if($toOrder < 0) { $over = "(+ ".$toOrder *-1 .")";  $toOrder = 0; } else { $over = ''; }
				
			?>
			
			<div class="manage-area p-4">

				<div class="mb-3">
					<button action="closeTrans" class="btn btn-secondary btn-sm">Close</button>
				</div>

				<!-- TOP: Edit fields (left) + Stocking stats (right) — always side by side -->
				<div class="d-flex align-items-stretch gap-4">

					<!-- LEFT: Edit fields (fixed width based on content) -->
					<div style="flex:0 0 auto;">
						<h4 class="fw-bold mb-3"><?php echo "$partno — $desc"; ?></h4>
						<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
							<div class="input-group input-group-sm" style="width:160px">
								<span class="input-group-text" style="width:60px">Part #</span>
								<input type="text" id="<?php echo $id; ?>editSkuField" class="form-control form-control-sm" value="<?php echo $partno; ?>" />
							</div>
							<div class="input-group input-group-sm" style="width:300px">
								<span class="input-group-text" style="width:60px">Desc</span>
								<input type="text" id="<?php echo $id; ?>editDescField" class="form-control form-control-sm" value="<?php echo $desc; ?>" />
							</div>
						</div>

						<?php
						$whQtysAdj = $whQtyAll[$id] ?? [];
						$whQtysAdjJson = htmlspecialchars(json_encode($whQtysAdj), ENT_QUOTES);
						$firstWH = $warehouses[0] ?? null;
						$firstWHQty = $firstWH ? ((int)($whQtysAdj[$firstWH['id']] ?? 0)) : $qoh;
						?>

						<div style="border-top:2px solid #e9ecef; margin:18px 0 14px 0; padding-top:14px;">
							<span style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#4680ff;">Inventory Adjustments</span>
						</div>

						<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
							<select id="<?php echo $id; ?>adjWH" class="form-select form-select-sm" style="width:180px" data-whqty="<?php echo $whQtysAdjJson; ?>" data-partid="<?php echo $id; ?>">
								<?php foreach ($warehouses as $wh): ?>
								<option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="input-group input-group-sm" style="width:160px">
								<span class="input-group-text" style="width:60px">QOH</span>
								<input type="text" id="<?php echo $id; ?>adjQty" class="form-control form-control-sm" value="<?php echo $firstWHQty; ?>" data-original="<?php echo $firstWHQty; ?>" />
							</div>
							<div class="input-group input-group-sm" style="width:300px">
								<span class="input-group-text" style="width:60px">Reason</span>
								<input type="text" id="<?php echo $id; ?>editQtyReason" class="form-control form-control-sm" placeholder="Required if QOH changed" />
							</div>
						</div>

						<?php if (count($warehouses) > 1): ?>
						<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
							<span class="small text-muted fw-semibold" style="width:60px;">Transfer</span>
							<select id="<?php echo $id; ?>xfrFrom" class="form-select form-select-sm" style="width:180px">
								<?php foreach ($warehouses as $wh): ?>
								<option value="<?php echo $wh['id']; ?>"><?php echo htmlspecialchars($wh['name']); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="small text-muted">→</span>
							<select id="<?php echo $id; ?>xfrTo" class="form-select form-select-sm" style="width:180px">
								<?php foreach ($warehouses as $wh): ?>
								<option value="<?php echo $wh['id']; ?>" <?php echo ($wh === end($warehouses)) ? 'selected' : ''; ?>><?php echo htmlspecialchars($wh['name']); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="input-group input-group-sm" style="width:120px">
								<span class="input-group-text">Qty</span>
								<input type="number" min="1" id="<?php echo $id; ?>xfrQty" class="form-control form-control-sm" />
							</div>
							<button class="btn btn-outline-secondary btn-sm xfr-btn" data-partid="<?php echo $id; ?>">Transfer</button>
						</div>
						<?php else: ?>
						<div class="mb-3"></div>
						<?php endif; ?>

						<div style="border-top:2px solid #e9ecef; margin:18px 0 14px 0; padding-top:14px;">
							<span style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#4680ff;">Manufacturing</span>
						</div>

						<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
							<div class="input-group input-group-sm" style="width:160px">
								<span class="input-group-text" style="width:60px">$</span>
								<input type="text" id="<?php echo $id; ?>editCost" class="form-control form-control-sm" value="<?php echo $cost; ?>" />
							</div>
							<div class="input-group input-group-sm" style="width:145px">
								<span class="input-group-text" style="width:60px">MOQ</span>
								<input type="text" id="<?php echo $id; ?>editIMOQ" class="form-control form-control-sm" value="<?php echo $imoq; ?>" />
							</div>
							<div class="input-group input-group-sm" style="width:155px">
								<span class="input-group-text" style="width:90px">Lead (days)</span>
								<input type="number" id="<?php echo $id; ?>editLeadTime" class="form-control form-control-sm" value="<?php echo (int)$lead_time; ?>" min="0" />
							</div>
							<select id="<?php echo $id; ?>editManufacturer" class="form-select form-select-sm" style="width:220px">
								<option value="0">No Manufacturer</option>
								<?php foreach($allMfgs as $mfgOpt) { ?>
								<option value="<?php echo $mfgOpt['id']; ?>" <?php echo ((int)$manufacturer === (int)$mfgOpt['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mfgOpt['name']); ?></option>
								<?php } ?>
							</select>
						</div>
						<div class="mb-3">
							<button class="btn btn-primary btn-sm" record="<?php echo $id; ?>" action="saveAllPartFields">Save All Changes</button>
						</div>
					</div>

					<!-- RIGHT: Stocking stats (always anchored to the right of edit fields) -->
					<div style="flex:1 1 0; min-width:0; max-width:500px;">
						<table class="table table-bordered mb-0 w-100" style="height:100%;">
							<tbody>
								<tr>
									<td class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;background:#f1f3f5;width:55%;padding:4px 8px;">12-Mo Demand</td>
									<td class="fw-bold text-end" style="font-size:0.75rem;padding:4px 8px;"><?php echo $twelveDemand; ?></td>
								</tr>
								<tr>
									<td class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;background:#f1f3f5;padding:4px 8px;">Best Stock Level</td>
									<td class="fw-bold text-end" style="font-size:0.75rem;padding:4px 8px;"><?php echo $bsl; ?></td>
								</tr>
								<tr>
									<td class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;background:#f1f3f5;padding:4px 8px;">On-Hand</td>
									<td class="fw-bold text-end" style="font-size:0.75rem;padding:4px 8px;"><?php echo $qoh; ?></td>
								</tr>
								<tr>
									<td class="text-muted fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;background:#f1f3f5;padding:4px 8px;">On-Order</td>
									<td class="fw-bold text-end" style="font-size:0.75rem;padding:4px 8px;"><?php echo $onOrder; ?></td>
								</tr>
								<tr style="background:#eef2ff;">
									<td class="fw-semibold" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;color:#3a57c9;padding:4px 8px;">
										Suggested Order
									</td>
									<td class="fw-bold text-end text-primary" style="font-size:0.75rem;padding:4px 8px;"><?php echo $toOrder; ?></td>
								</tr>
							</tbody>
						</table>
					</div>

				</div><!-- end top row -->

				<!-- BOTTOM: Build usage chart — always below both columns -->
				<div class="mt-4">
					<h6 class="fw-semibold mb-2 text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.04em;">12-Month Forward Build Demand (Based on Prior 12 Months Build Demand)</h6>
					<div style="position:relative; width:100%;">
						<canvas id="buildChart_<?php echo $id; ?>"></canvas>
					</div>
				</div>
			<script>
				window.chartData_<?php echo $id; ?> = {
					labels:    <?php echo $chartLabelsJson; ?>,
					data:      <?php echo $chartDataJson; ?>,
					orderLine: <?php echo $orderLineJson; ?>,
					qohLine:   <?php echo $qohLineJson; ?>,
					leadTime:  <?php echo (int)$lead_time; ?>
				};
			</script>

			<!-- Per-warehouse qty breakdown -->
			<?php
			$whQtyMap = $whQtyAll[$id] ?? [];
			?>
			<?php if (count($warehouses) > 1): ?>
			<div class="mt-3 mb-2">
				<div class="small fw-semibold text-uppercase text-muted mb-1" style="letter-spacing:.05em;">Inventory by Warehouse</div>
				<table class="table table-sm table-bordered mb-0" style="max-width:360px;">
					<thead><tr style="background:#f1f3f5;">
						<th class="small text-muted fw-semibold" style="font-size:0.72rem;text-transform:uppercase;">Warehouse</th>
						<th class="small text-muted fw-semibold text-end" style="font-size:0.72rem;text-transform:uppercase;">QOH</th>
					</tr></thead>
					<tbody>
					<?php foreach ($warehouses as $wh): ?>
					<tr>
						<td class="small"><?php echo htmlspecialchars($wh['name']); ?></td>
						<td class="small fw-semibold text-end"><?php echo number_format($whQtyMap[$wh['id']] ?? 0); ?></td>
					</tr>
					<?php endforeach; ?>
					<tr style="background:#eef2ff;">
						<td class="small fw-semibold" style="color:#3a57c9;">Total</td>
						<td class="small fw-bold text-end" style="color:#3a57c9;"><?php echo number_format($qoh); ?></td>
					</tr>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<!-- TRANSACTION DATA -->
			<div class="d-flex align-items-center justify-content-between mb-2 mt-4">
				<h5 class="fw-bold mb-0">Transaction History</h5>
				<button class="btn btn-outline-danger btn-sm" action="deletePartButton" record="<?php echo $id; ?>">Delete Component</button>
			</div>

			<div>
			<table class="table table-sm table-bordered mb-2">
				<thead>
					<tr style="background-color:#e2e5e8;">
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">Date</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">Reason</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">Old</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">QTY</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">New</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em;">Warehouse</th>
						<th class="text-muted fw-semibold" style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.04em; width:110px;">User</th>
					</tr>
				</thead>
				<tbody>

			<?php

			foreach (($transMap[$id] ?? []) as $transrecord) {

				$transType = $transrecord['type'];
				$transDate = date("m/d/y", strtotime($transrecord['date']));
				$transQty = $transrecord['qty'];
				$transOld = $transrecord['old'];
				$transNew = $transrecord['new'];
				$orderId = $transrecord['ordid'];
				$adjReason = $transrecord['adjreason'];
				$transDesc = $transType;
				$transUser = '';
				if (!empty($transrecord['user_name'])) {
					$nameParts = explode(' ', trim($transrecord['user_name']));
					$firstName = $nameParts[0];
					$lastInitial = count($nameParts) > 1 ? ' ' . strtoupper(substr(end($nameParts), 0, 1)) . '.' : '';
					$transUser = $firstName . $lastInitial;
				}

				switch($transType) {

					case "ADJORD":
					$transDesc = "Order Quantity Adjusted: $transOld → $transNew (Order ID# $orderId)";
					$transQty = "—";
					$transOld = "—";
					$transNew = "—";
					break;

					case "POST":
					$transRef  = $transrecord['postref'];
					$transWHLabel = $transrecord['wh_name'] ? ' → '.$transrecord['wh_name'] : '';
					$transDesc = "Shipment Received ($transRef$transWHLabel)";
					$transQty = $transQty > 0 ? "Plus +".$transQty : "Minus ".$transQty;
					break;

					case "BUILD":
					$transBuild   = $transrecord['buildid'];
					$transWHLabel = $transrecord['wh_name'] ? ' — '.$transrecord['wh_name'] : '';
					$transDesc = "Materials Used for Build ($transBuild$transWHLabel)";
					$transQty = $transQty > 0 ? "Plus +".$transQty : "Minus ".$transQty;
					break;

					case "ORDER":
					$transDesc = "Order Placed: $transQty units (Order ID# $orderId)";
					$transQty = "—";
					break;

					case "ADJUST":
					$reasonDisplay = $adjReason ?: "No reason entered";
					$transDesc = "Manual Adjustment ($reasonDisplay)";
					$transQty = $transQty > 0 ? "Plus +".$transQty : "Minus ".$transQty;
					break;

					case "POSTUNDO":
					$transRef     = $transrecord['postref'];
					$transWHLabel = $transrecord['wh_name'] ? ' ← '.$transrecord['wh_name'] : '';
					$transDesc = "Shipment Receipt Reversed ($transRef$transWHLabel)";
					$transQty = "Minus ".$transQty;
					break;

					case "BUILDUNDO":
					$transBuild   = $transrecord['buildid'];
					$transWHLabel = $transrecord['wh_name'] ? ' — '.$transrecord['wh_name'] : '';
					$transDesc = "Build Reversed — Materials Returned ($transBuild$transWHLabel)";
					$transQty = "Plus +".$transQty;
					break;

					case "ORDERDELETE":
					$transDesc = "Order Deleted (Order ID# $orderId)";
					$transQty = "—";
					break;

				}

				if ($transQty === "—") { $transOld = "—"; $transNew = "—"; }

			$transWH = htmlspecialchars($transrecord['wh_name'] ?? '—');
			?>
				<tr>
					<td><?php echo $transDate; ?></td>
					<td><?php echo $transDesc; ?></td>
					<td><?php echo $transOld; ?></td>
					<td><?php echo $transQty; ?></td>
					<td><?php echo $transNew; ?></td>
					<td class="text-muted small"><?php echo $transWH; ?></td>
					<td><?php echo $transUser; ?></td>
				</tr>
			<?php } ?>

				</tbody>
			</table>
			<button action="closeTrans" class="btn btn-secondary btn-sm mt-2">Close</button>
			</div>

			</div><!-- end manage-area -->
			</div><!-- end transArea -->
			</td>
			</tr>

			<?php
		} // end foreach part
		} // end foreach category
		?>

			</tbody>
		</table>
		</div><!-- end card-body -->
		</div><!-- end card -->



<script>

		// CATEGORY TOGGLE — close any other open category first
		$(".category-header").click(function() {
			var cat = $(this).data("cat");
			var $rows = $(".category-row-" + cat);
			var isOpen = $rows.first().is(":visible");

			// Close all categories
			$(".category-header").each(function() {
				var otherCat = $(this).data("cat");
				$(".category-row-" + otherCat).hide().removeClass("cat-active");
				$(this).removeClass("cat-open");
			});

			// If this one was closed, open it
			if (!isOpen) {
				$rows.show().addClass("cat-active");
				$(this).addClass("cat-open");
			}
		});

		// SHOW FORMS
		$("#postAreaButton").click(function() {
			
			$("#postArea").show();
			$("#addArea").hide();
			$("#orderArea").hide();
			
		});
		
		$("#addAreaButton").click(function() {
			
			$("#postArea").hide();
			$("#orderArea").hide();
			$("#addArea").show();
			
		});
		
		
		
		
		
		// ADD COMPONENT (shared handler)
		function submitAddComp(suffix) {
			var s = suffix || '';
			var mfg    = $("#addMFG"+s).val();
			var partno = $("#addPartno"+s).val();
			var desc   = $("#addDesc"+s).val();
			var cost   = $("#addCost"+s).val();
			var imoq   = $("#addIMOQ"+s).val();
			var mfrid  = $("#addManufacturer"+s+" option:selected").val();
			$.post('/ajax/add_comp.php', { mfg: mfg, partno: partno, desc: desc, cost: cost, imoq: imoq, mfrid: mfrid }, function() {
				location.reload();
			});
		}

		$("#addButton").click(function()  { submitAddComp('');  });
		$("#addButton2").click(function() { submitAddComp('2'); });
		
		
		
		
		
		// SUBMIT INVENTORY ADJUSTMENT
		$("[action=adjButton]").click(function() {
			
			var record = $(this).attr('record');
			var adjQty = $("#"+record+"adjQty").val();
			var reason = $("#"+record+"editQtyReason").val();
			
			if(!reason) {
				alert('You must enter an adjustment reason!');
			} else {
			
				if (confirm("Confirm you wish to adjust the inventory on hand.  This transaction will be recorded.") == true) {

					$.post('/ajax/inv_adj.php', { record: record, qty: adjQty, reason: reason }, function() {
						location.reload();
					});

				} else {

				}
				
			}
		});
		
		// SUBMIT INVENTORY ADJUSTMENT
		$("[action=showTrans]").click(function() {

			var record = $(this).attr('record');

			$("#"+record+"transArea").slideDown(100, function() {
				var cd = window['chartData_' + record];
				if (cd && !window['chartInst_' + record]) {
					var ctx = document.getElementById('buildChart_' + record).getContext('2d');
					window['chartInst_' + record] = new Chart(ctx, {
						type: 'line',
						data: {
							labels: cd.labels,
							datasets: [
								{
									label: 'Units Used in Build',
									data: cd.data,
									borderColor: '#4680ff',
									backgroundColor: 'rgba(70,128,255,0.1)',
									borderWidth: 2,
									pointRadius: 3,
									tension: 0.3,
									fill: true
								},
							]
						},
						options: {
							responsive: true,
							maintainAspectRatio: false,
							plugins: {
								legend: {
									display: true,
									position: 'bottom',
									labels: { boxWidth: 12, font: { size: 11 } }
								}
							},
							scales: {
								y: {
									beginAtZero: true,
									ticks: { precision: 0 }
								}
							}
						}
					});
					setPartChartHeight(window['chartInst_' + record]);
				}
			});

		});

		$("[action=closeTrans]").click(function() {
			$(this).closest("[id$='transArea']").slideUp(100);
		});

		// Sets the container height for a per-part chart: proportional to width
		// (÷5 gives a ~5:1 ratio) but capped at 170px so it never gets too tall.
		function setPartChartHeight(chart) {
			var container = chart.canvas.parentNode;
			var w = container.offsetWidth || 0;
			container.style.height = Math.min(170, Math.max(60, Math.round(w / 5))) + 'px';
		}

		// Force per-part charts to resize when browser width changes.
		// Zero the canvas width first so the table cell can shrink,
		// then update the container height before Chart.js redraws.
		window.addEventListener('resize', function() {
			for (var key in window) {
				if (key.indexOf('chartInst_') === 0 && window[key]) {
					var chart = window[key];
					chart.canvas.style.width = '0px';
					setPartChartHeight(chart);
					chart.resize();
				}
			}
		});
		
		// DELETE COMPONENT
		$("[action=deletePartButton]").click(function() {
			var record = $(this).attr('record');
			if (!confirm("WARNING: Deleting this component will permanently erase the component, all transaction history, and all associated order data.\n\nThis cannot be undone.\n\nAre you absolutely sure you want to proceed?")) return;
			if (!confirm("Final confirmation — this deletion is permanent and cannot be reversed. Continue?")) return;
			$.post('/ajax/delete_part.php', { record: record }, function(response) {
				if (response === 'in_build') {
					alert("This component cannot be deleted because it is included in one or more product builds. Remove it from all product builds before deleting.");
				} else {
					location.reload();
				}
			});
		});

		// WAREHOUSE TRANSFER
		$(document).on('click', '.xfr-btn', function() {
			var partId  = $(this).data('partid');
			var fromId  = $('#'+partId+'xfrFrom').val();
			var toId    = $('#'+partId+'xfrTo').val();
			var qty     = parseInt($('#'+partId+'xfrQty').val());
			var $btn    = $(this);

			if (!qty || qty < 1)   { alert('Enter a transfer quantity.'); return; }
			if (fromId === toId)   { alert('From and To warehouses must be different.'); return; }

			var fromName = $('#'+partId+'xfrFrom option:selected').text();
			var toName   = $('#'+partId+'xfrTo option:selected').text();

			$btn.prop('disabled', true).text('Transferring…');
			$.post('/ajax/wh_transfer.php', { partid: partId, from_id: fromId, to_id: toId, qty: qty }, function(res) {
				if (res === 'ok') {
					location.reload();
				} else {
					alert('Error: ' + res);
					$btn.prop('disabled', false).text('Transfer');
				}
			});
		});

		// WAREHOUSE DROPDOWN — update QOH field to show warehouse-specific qty
		$(document).on('change', '[id$="adjWH"]', function() {
			var $sel    = $(this);
			var whId    = $sel.val();
			var whQty   = $sel.data('whqty') || {};
			var partId  = $sel.data('partid');
			var qty     = whQty[whId] !== undefined ? whQty[whId] : 0;
			var $input  = $('#'+partId+'adjQty');
			$input.val(qty).data('original', qty);
			$('#'+partId+'editQtyReason').val('');
		});

		// SAVE ALL PART FIELDS
		$("[action=saveAllPartFields]").click(function() {
			var record    = $(this).attr('record');
			var sku       = $("#"+record+"editSkuField").val();
			var desc      = $("#"+record+"editDescField").val();
			var cost      = $("#"+record+"editCost").val();
			var imoq      = $("#"+record+"editIMOQ").val();
			var lead_time    = $("#"+record+"editLeadTime").val();
			var manufacturer = $("#"+record+"editManufacturer").val();
			var adjQty    = $("#"+record+"adjQty");
			var newQty    = adjQty.val();
			var origQty   = adjQty.data('original');
			var reason    = $("#"+record+"editQtyReason").val();
			var whId      = $("#"+record+"adjWH").val();
			var $btn      = $(this);

			var qohChanged = (newQty != origQty);

			if (qohChanged && !reason) {
				alert('Please enter a reason for the quantity adjustment.');
				return;
			}

			$btn.prop('disabled', true);
			var errors = [];

			// Step 2: save the QOH adjustment (independent of the part-field save,
			// so a pure inventory change still goes through and reports its result).
			function saveQoh() {
				if (!qohChanged) { finish(); return; }
				$.post('/ajax/inv_adj.php', { record: record, qty: newQty, reason: reason, warehouse_id: whId })
					.done(function(resp) {
						var total = (resp && typeof resp === 'object') ? resp.qoh : newQty;
						$("#"+record+"rowQoh").text(total);
						adjQty.data('original', newQty);
						$("#"+record+"editQtyReason").val('');
					})
					.fail(function(xhr) {
						errors.push('Inventory: ' + ((xhr.responseText || '').replace(/^error:\s*/, '') || 'save failed'));
					})
					.always(finish);
			}

			// Step 3: report outcome.
			function finish() {
				$btn.prop('disabled', false);
				if (errors.length) {
					alert('Could not save:\n\n' + errors.join('\n'));
					$btn.text('Save All Changes');
					return;
				}
				$btn.text('Changes saved');
				setTimeout(function() {
					$("#"+record+"transArea").slideUp(200);
					$btn.text('Save All Changes');
				}, 700);
			}

			// Step 1: save the editable part fields. A 403 here just means this user
			// can view inventory but not edit part fields — that's fine, we skip it
			// and still save the QOH. Any other failure is surfaced.
			$.post('/ajax/edit_part.php', { record: record, sku: sku, desc: desc, cost: cost, imoq: imoq, lead_time: lead_time, manufacturer: manufacturer })
				.fail(function(xhr) {
					if (xhr.status !== 403) {
						errors.push('Part fields: ' + ((xhr.responseText || '').replace(/^error:\s*/, '') || 'save failed'));
					}
				})
				.always(saveQoh);
		});
		
		
		
		
		
		

	</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>


	
