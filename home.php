<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();
	require_once(__DIR__."/includes/header.php");

	$db = db_connect();

	// ── KPI ───────────────────────────────────────────────────────────────────
	$ohVal      = (float)$db->query("SELECT COALESCE(SUM(qoh*cost),0) AS v FROM parts")->fetch()['v'];
	$ooVal      = (float)$db->query("SELECT COALESCE(SUM(ordval-(recqty/qty*ordval)),0) AS v FROM orders WHERE qty > recqty")->fetch()['v'];
	$unpaidAmt  = (float)$db->query("SELECT COALESCE(SUM(ordval-paidamt),0) AS v FROM orders WHERE paidamt < ordval")->fetch()['v'];
	$unpaidCnt  = (int)$db->query("SELECT COUNT(*) AS v FROM orders WHERE paidamt < ordval")->fetch()['v'];
	// Low stock: parts with real demand where (qoh + on_order) < 2× lead-time worth of daily demand
	$lowStockRes = $db->query("
		SELECT COUNT(*) AS cnt,
			MIN(ROUND((p.qoh + COALESCE(oo.oo,0)) / (d.d12 / 365.0))) AS min_days
		FROM parts p
		LEFT JOIN (SELECT partid, SUM(qty-recqty) AS oo FROM orders WHERE qty>recqty GROUP BY partid) oo ON oo.partid=p.id
		LEFT JOIN (SELECT partid, SUM(ABS(qty)) AS d12 FROM trans WHERE type='BUILD' AND date > DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY partid) d ON d.partid=p.id
		WHERE d.d12 > 0
		  AND p.lead_time > 0
		  AND (p.qoh + COALESCE(oo.oo,0)) < (d.d12 / 365.0 * p.lead_time * 2)
	")->fetch();
	$lowStockCount = (int)$lowStockRes['cnt'];
	$lowStockMinDays = (int)$lowStockRes['min_days'];
	$belowBsl = (int)$db->query("SELECT COUNT(*) AS v FROM parts WHERE qoh > 0 AND qoh < bsl AND bsl > 0")->fetch()['v'];
	$totalParts = (int)$db->query("SELECT COUNT(*) AS v FROM parts")->fetch()['v'];
	$avgLeadRow = $db->query("SELECT ROUND(AVG(DATEDIFF(postdate,orderdate))) AS v, COUNT(*) AS cnt FROM orders WHERE postdate IS NOT NULL AND postdate != '0000-00-00 00:00:00' AND orderdate IS NOT NULL AND DATEDIFF(postdate,orderdate) > 0")->fetch();
	$avgLead    = (int)$avgLeadRow['v'];
	$avgLeadCnt = (int)$avgLeadRow['cnt'];
	$build12mo  = (int)$db->query("SELECT COALESCE(SUM(ABS(t.qty)),0) AS v FROM trans t JOIN parts p ON p.id=t.partid WHERE t.type='BUILD' AND t.date > DATE_SUB(NOW(), INTERVAL 12 MONTH) AND p.partno LIKE 'CS-%'")->fetch()['v'];
	$openOrders = (int)$db->query("SELECT COUNT(*) AS v FROM orders WHERE qty > recqty")->fetch()['v'];

	// ── 1. ORDER RECOMMENDATIONS ──────────────────────────────────────────────
	// ── BATCH PREFETCH for recommendation loops (avoids per-part N+1) ──
	$ooUnposted = []; // on-order for orders not yet posted (postdate 0000-00-00)
	foreach ($db->query("SELECT `partid`, SUM(`qty`)-SUM(`recqty`) AS v FROM `orders` WHERE `postdate` LIKE '0000-00-00%' GROUP BY `partid`") as $r) {
		$ooUnposted[$r['partid']] = $r['v'];
	}
	$ooAny = []; // on-order across all orders for a part
	foreach ($db->query("SELECT `partid`, SUM(`qty`)-SUM(`recqty`) AS v FROM `orders` GROUP BY `partid`") as $r) {
		$ooAny[$r['partid']] = $r['v'];
	}
	$partsByNo = []; // full part row keyed by partno (for CS-* lookups)
	foreach ($db->query("SELECT * FROM `parts`") as $r) {
		$partsByNo[$r['partno']] = $r;
	}

	// ── CAMSHAFTS (CS-*) — same logic as stock_calc.php ─────────────────────
	$recCams = []; $totalCamCount = 0; $cardArray = [];
	$camsQ = $db->query("SELECT * FROM `parts` WHERE `partno` LIKE 'CS-%' ORDER BY `partno`");
	while ($cam = $camsQ->fetch()) {
		$camId  = $cam['id']; $camMOQ = max(1, (int)$cam['imoq']);
		$camOO  = max(0, (float)($ooUnposted[$camId] ?? 0));
		$toOrder = $cam['bsl'] - $cam['qoh'] - $camOO - $cam['omit'];
		if ($toOrder > 0) {
			$rounded = (floor($toOrder / $camMOQ) + 1) * $camMOQ;
			$recCams[] = ['partno'=>$cam['partno'],'desc'=>$cam['desc'],'qoh'=>$cam['qoh'],'bsl'=>$cam['bsl'],'on_order'=>$camOO,'omit'=>$cam['omit'],'need'=>$toOrder,'rec_qty'=>$rounded,'cost'=>$cam['cost'],'rec_cost'=>round($rounded*$cam['cost'],2)];
			$totalCamCount += $rounded;
			$cardArray[$camId] = $rounded;
		} else {
			$cardArray[$camId] = 0;
		}
	}

	// effectiveCams drives all universal component needs
	$camsOH      = (float)($db->query("SELECT SUM(`qoh`) AS v FROM `parts` WHERE `partno` LIKE 'CS%'")->fetch()['v'] ?? 0);
	$camsOO      = (float)($db->query("SELECT COALESCE(SUM(`qty`-`recqty`),0) AS v FROM `orders` WHERE `partid` IN (SELECT `id` FROM `parts` WHERE `partno` LIKE 'CS%') AND (`qty`-`recqty`)>0")->fetch()['v'] ?? 0);
	$camsOmitted = (float)($db->query("SELECT SUM(`omit`) AS v FROM `parts` WHERE `partno` LIKE 'CS%'")->fetch()['v'] ?? 0);
	$effectiveCams = $camsOH + $camsOO + $totalCamCount - $camsOmitted;

	// ── UNIVERSAL COMPONENTS ──────────────────────────────────────────────────
	$recUniversal = [];

	// Rods (pairs — divide by 2)
	$rodsOH  = ($db->query("SELECT COALESCE(SUM(`qoh`),0) AS v FROM `parts` WHERE `partno` LIKE 'RD%'")->fetch()['v'] ?? 0) / 2;
	$rodsOO  = ($db->query("SELECT COALESCE(SUM(`qty`-`recqty`),0) AS v FROM `orders` WHERE `partid` IN (SELECT `id` FROM `parts` WHERE `partno` LIKE 'RD%') AND (`qty`-`recqty`)>0")->fetch()['v'] ?? 0) / 2;
	$rodsNeed = $effectiveCams - $rodsOH - $rodsOO;
	if ($rodsNeed > 0) {
		$rounded = (floor($rodsNeed / 2500) + 1) * 2500;
		$rodCost = (float)($db->query("SELECT COALESCE(AVG(`cost`),0) AS v FROM `parts` WHERE `partno` LIKE 'RD%'")->fetch()['v'] ?? 0);
		$recUniversal[] = ['name'=>'Rods (Pairs)','qoh'=>$rodsOH,'on_order'=>$rodsOO,'effective'=>$rodsOH+$rodsOO,'cam_demand'=>$effectiveCams,'need'=>$rodsNeed,'rec_qty'=>$rounded,'rec_cost'=>round($rounded*$rodCost,2)];
	}

	// Splash Plates
	$plateInfo = $db->query("SELECT `id`,`qoh`,`cost` FROM `parts` WHERE `partno`='PL-SP'")->fetch();
	if ($plateInfo) {
		$plateOO   = max(0, (float)($db->query("SELECT COALESCE(SUM(`qty`-`recqty`),0) AS v FROM `orders` WHERE `partid`='{$plateInfo['id']}' AND (`qty`-`recqty`)>0")->fetch()['v'] ?? 0));
		$plateNeed = $effectiveCams - $plateInfo['qoh'] - $plateOO;
		if ($plateNeed > 0) {
			$rounded = (floor($plateNeed / 1000) + 1) * 1000;
			$recUniversal[] = ['name'=>'Splash Plates','qoh'=>$plateInfo['qoh'],'on_order'=>$plateOO,'effective'=>$plateInfo['qoh']+$plateOO,'cam_demand'=>$effectiveCams,'need'=>$plateNeed,'rec_qty'=>$rounded,'rec_cost'=>round($rounded*$plateInfo['cost'],2)];
		}
	}

	// Packaging (MC sets)
	$mcOH   = (float)($db->query("SELECT COALESCE(SUM(`qoh`),0) AS v FROM `parts` WHERE `partno` LIKE 'MC%'")->fetch()['v'] ?? 0);
	$mcOO   = (float)($db->query("SELECT COALESCE(SUM(`qty`)-SUM(`recqty`),0) AS v FROM `orders` WHERE `partid` IN (SELECT `id` FROM `parts` WHERE `partno` LIKE 'MC%')")->fetch()['v'] ?? 0);
	$mcNeed = $effectiveCams - $mcOH - $mcOO;
	if ($mcNeed > 0) {
		$rounded = (floor($mcNeed / 1000) + 1) * 1000;
		$mcCost  = (float)($db->query("SELECT COALESCE(AVG(`cost`),0) AS v FROM `parts` WHERE `partno` LIKE 'MC%'")->fetch()['v'] ?? 0);
		$recUniversal[] = ['name'=>'Packaging Sets','qoh'=>$mcOH,'on_order'=>$mcOO,'effective'=>$mcOH+$mcOO,'cam_demand'=>$effectiveCams,'need'=>$mcNeed,'rec_qty'=>$rounded,'rec_cost'=>round($rounded*$mcCost,2)];
	}

	// ── PACKAGE CARDS (CD-*) ──────────────────────────────────────────────────
	$recCards = [];
	$cardsQ = $db->query("SELECT * FROM `parts` WHERE `partno` LIKE 'CD-%' ORDER BY `partno` ASC");
	while ($card = $cardsQ->fetch()) {
		$partId = $card['id'];
		$cardOO = (float)($ooAny[$partId] ?? 0);
		if ($cardOO < 0) $cardOO = 0;
		$camCode  = substr($card['partno'], 3);
		$camInfo2 = $partsByNo["CS-$camCode"] ?? false;
		if (!$camInfo2) continue;
		$camId2  = $camInfo2['id'];
		$camOO2  = (float)($ooAny[$camId2] ?? 0);
		$camEff2 = $camInfo2['qoh'] + $camOO2 + ($cardArray[$camId2] ?? 0);
		$cardNeed = $camEff2 - ($card['qoh'] + $cardOO) - $camInfo2['omit'];
		if ($cardNeed > 0) {
			$rounded = (floor($cardNeed / 1000) + 1) * 1000;
			$recCards[] = ['partno'=>$card['partno'],'desc'=>$card['desc'],'qoh'=>$card['qoh'],'on_order'=>$cardOO,'cam_effective'=>$camEff2,'need'=>$cardNeed,'rec_qty'=>$rounded,'rec_cost'=>round($rounded*$card['cost'],2)];
		}
	}

	// ── AMAZON PACK CARDS (CDA-*) ─────────────────────────────────────────────
	$recAmazon = [];
	$amazonQ = $db->query("SELECT * FROM `parts` WHERE `partno` LIKE 'CDA-%' ORDER BY `partno` ASC");
	while ($ac = $amazonQ->fetch()) {
		$acMOQ = max(1, (int)($ac['imoq'] ?: 1000));
		$acOO  = max(0, (float)($ooAny[$ac['id']] ?? 0));
		$acNeed = $ac['bsl'] - $ac['qoh'] - $acOO;
		if ($acNeed > 0) {
			$rounded = (floor($acNeed / $acMOQ) + 1) * $acMOQ;
			$recAmazon[] = ['partno'=>$ac['partno'],'desc'=>$ac['desc'],'qoh'=>$ac['qoh'],'bsl'=>$ac['bsl'],'on_order'=>$acOO,'need'=>$acNeed,'rec_qty'=>$rounded,'rec_cost'=>round($rounded*$ac['cost'],2)];
		}
	}

	// ── TOTALS ────────────────────────────────────────────────────────────────
	$recCount = count($recCams) + count($recUniversal) + count($recCards) + count($recAmazon);
	$recTotal = 0;
	foreach (array_merge($recCams, $recUniversal, $recCards, $recAmazon) as $r) { $recTotal += $r['rec_cost']; }

	// ── 2. OPEN ORDERS WITH ETA ───────────────────────────────────────────────
	$etaRes = $db->query("
		SELECT o.id, o.orderref, o.orderdate, o.qty, o.recqty, o.ordval, o.paidamt,
			(o.qty - o.recqty) AS remaining,
			p.partno, p.`desc`, p.lead_time,
			COALESCE(o.eta, DATE_ADD(o.orderdate, INTERVAL p.lead_time DAY)) AS eta
		FROM orders o
		JOIN parts p ON p.id = o.partid
		WHERE o.qty > o.recqty
		ORDER BY eta ASC
	");
	$openEtaOrders = $etaRes->fetchAll();
	$etaOverdue = 0; $etaSoon = 0; $etaOnTrack = 0;
	foreach ($openEtaOrders as $eo) {
		$daysOut = (int)((strtotime($eo['eta']) - time()) / 86400);
		if ($daysOut < 0)      $etaOverdue++;
		elseif ($daysOut <= 14) $etaSoon++;
		else                   $etaOnTrack++;
	}

	// ── 3. OUTSTANDING PAYMENTS – full list + aging ───────────────────────────
	$payRes = $db->query("
		SELECT o.id, o.orderref, o.orderdate, o.qty, o.recqty, o.ordval, o.paidamt,
			(o.ordval - o.paidamt) AS owed,
			DATEDIFF(NOW(), o.orderdate) AS age_days,
			p.partno, p.`desc`
		FROM orders o
		JOIN parts p ON p.id = o.partid
		WHERE o.paidamt < o.ordval
		ORDER BY owed DESC
	");
	$payOrders = $payRes->fetchAll();
	$aging = ['0–90 days'=>0,'91–180 days'=>0,'181–365 days'=>0,'1–2 years'=>0,'2+ years'=>0];
	foreach ($payOrders as $po) {
		$age = (int)$po['age_days'];
		if ($age <= 90)       $aging['0–90 days']     += $po['owed'];
		elseif ($age <= 180)  $aging['91–180 days']    += $po['owed'];
		elseif ($age <= 365)  $aging['181–365 days']   += $po['owed'];
		elseif ($age <= 730)  $aging['1–2 years']      += $po['owed'];
		else                  $aging['2+ years']        += $po['owed'];
	}
	$agingLabels = array_keys($aging);
	$agingData   = array_values($aging);
	$agingColors = ['#2ca87f','#3ec9d6','#e58a00','#f97316','#dc2626'];

	// ── 4. OVERSTOCKED / IDLE INVENTORY ──────────────────────────────────────
	$idleRes = $db->query("
		SELECT p.id, p.partno, p.`desc`, p.qoh, p.bsl, p.cost, (p.qoh*p.cost) AS oh_val,
			COALESCE(d.units,0) AS demand_12mo,
			CASE WHEN COALESCE(d.units,0) > 0
				THEN ROUND(p.qoh / (d.units / 12.0), 1)
				ELSE 9999 END AS months_supply
		FROM parts p
		LEFT JOIN (
			SELECT partid, SUM(ABS(qty)) AS units
			FROM trans
			WHERE type='BUILD' AND date > DATE_SUB(NOW(), INTERVAL 12 MONTH)
			GROUP BY partid
		) d ON d.partid = p.id
		WHERE p.qoh > 0
		HAVING (demand_12mo = 0) OR (months_supply > 12)
		ORDER BY oh_val DESC
	");
	$idleParts = $idleRes->fetchAll();
	$idleTotal = 0; $idleCount = 0; $excessCount = 0; $excessTotal = 0;
	foreach ($idleParts as &$ip) {
		if ($ip['demand_12mo'] == 0) {
			$ip['status'] = 'IDLE';
			$idleCount++;
			$idleTotal += $ip['oh_val'];
		} elseif ($ip['months_supply'] > 24) {
			$ip['status'] = '2yr+ Supply';
			$excessCount++; $excessTotal += $ip['oh_val'];
		} else {
			$ip['status'] = '1yr+ Supply';
			$excessCount++; $excessTotal += $ip['oh_val'];
		}
	}
	unset($ip);

	// ── MONTHLY BUILD DEMAND ──────────────────────────────────────────────────
	$buildLabels = []; $buildData = [];
	$buildByMo = [];
	foreach ($db->query("SELECT DATE_FORMAT(date,'%Y-%m') AS mo, COALESCE(SUM(ABS(qty)),0) AS v FROM trans WHERE type='BUILD' GROUP BY mo") as $r) {
		$buildByMo[$r['mo']] = (int)$r['v'];
	}
	for ($i = 11; $i >= 0; $i--) {
		$mo = date('Y-m', strtotime("-$i months"));
		$buildLabels[] = date('M y', strtotime("-$i months"));
		$buildData[] = $buildByMo[$mo] ?? 0;
	}

	// ── MONTHLY ORDERS vs PAYMENTS ────────────────────────────────────────────
	$finLabels = []; $ordData = []; $payData = [];
	$ordByMo = [];
	foreach ($db->query("SELECT DATE_FORMAT(orderdate,'%Y-%m') AS mo, COALESCE(SUM(ordval),0) AS v FROM orders GROUP BY mo") as $r) {
		$ordByMo[$r['mo']] = (float)$r['v'];
	}
	$payByMo = [];
	foreach ($db->query("SELECT DATE_FORMAT(date,'%Y-%m') AS mo, COALESCE(SUM(amount),0) AS v FROM payments GROUP BY mo") as $r) {
		$payByMo[$r['mo']] = (float)$r['v'];
	}
	for ($i = 11; $i >= 0; $i--) {
		$mo = date('Y-m', strtotime("-$i months"));
		$finLabels[] = date('M y', strtotime("-$i months"));
		$ordData[] = $ordByMo[$mo] ?? 0.0;
		$payData[] = $payByMo[$mo] ?? 0.0;
	}

	// ── INVENTORY VALUE BY CATEGORY ───────────────────────────────────────────
	$catNames  = ['CS'=>'Camshafts','RD'=>'Rods','CD'=>'Package Cards','MC'=>'Packaging','PL'=>'Splash Plates','PC'=>'Packaging (PC)','RC'=>'Packaging (RC)'];
	$catColors = ['#4680ff','#2ca87f','#e58a00','#dc2626','#3ec9d6','#a855f7','#f97316'];
	$catLabels = []; $catData = []; $catColorMap = []; $ci = 0;
	$catRes = $db->query("SELECT LEFT(partno,2) AS cat, SUM(qoh*cost) AS val FROM parts WHERE qoh > 0 GROUP BY cat ORDER BY val DESC");
	while ($row = $catRes->fetch()) {
		$catLabels[]   = $catNames[$row['cat']] ?? $row['cat'];
		$catData[]     = round((float)$row['val'], 2);
		$catColorMap[] = $catColors[$ci % count($catColors)];
		$ci++;
	}

	// ── TOP PARTS BY 12-MO BUILD DEMAND ──────────────────────────────────────
	$demPartLabels = []; $demPartData = [];
	$dpRes = $db->query("SELECT p.partno, SUM(ABS(t.qty)) AS units FROM trans t JOIN parts p ON p.id=t.partid WHERE t.type='BUILD' AND t.date > DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY t.partid ORDER BY units DESC LIMIT 8");
	while ($row = $dpRes->fetch()) { $demPartLabels[] = $row['partno']; $demPartData[] = (int)$row['units']; }

	// ── TOP PARTS BY ON-HAND VALUE ────────────────────────────────────────────
	$ohParts = $db->query("SELECT partno, `desc`, qoh, cost, (qoh*cost) AS val FROM parts WHERE qoh > 0 ORDER BY val DESC LIMIT 8")->fetchAll();

	// ── PARTS NEEDING ATTENTION ───────────────────────────────────────────────
	$attnParts = $db->query("
		SELECT p.partno, p.`desc`, p.qoh, p.bsl,
			COALESCE((SELECT SUM(o.qty-o.recqty) FROM orders o WHERE o.partid=p.id AND o.qty>o.recqty),0) AS on_order
		FROM parts p WHERE p.qoh = 0 OR (p.bsl > 0 AND p.qoh < p.bsl) ORDER BY p.qoh ASC
	")->fetchAll();

	// ── RECENT ACTIVITY ───────────────────────────────────────────────────────
	$recentTrans = $db->query("
		SELECT t.type, t.date, t.qty, t.adjreason, p.partno, p.`desc`, u.name AS uname
		FROM trans t JOIN parts p ON p.id=t.partid LEFT JOIN users u ON u.id=t.user_id
		ORDER BY t.date DESC, t.id DESC LIMIT 10
	")->fetchAll();

	$typeLabels = ['BUILD'=>'Build','POST'=>'Received','ORDER'=>'Order Placed','ADJUST'=>'Adjustment','ADJORD'=>'Order Adjusted','ORDERDELETE'=>'Order Deleted','POSTUNDO'=>'Receipt Reversed','ARCHIVE'=>'Order Archived'];
	$typeBadge  = ['BUILD'=>'bg-primary','POST'=>'bg-success','ORDER'=>'bg-info text-dark','ADJUST'=>'bg-warning text-dark','ADJORD'=>'bg-secondary','ORDERDELETE'=>'bg-danger','POSTUNDO'=>'bg-danger','ARCHIVE'=>'bg-secondary'];

?>
<style>
.kpi-card        { border-radius:10px; padding:20px 22px; position:relative; overflow:hidden; }
.kpi-icon        { position:absolute; right:16px; top:16px; font-size:2rem; opacity:0.18; }
.kpi-label       { font-size:0.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:600; }
.kpi-value       { font-size:1.75rem; font-weight:700; line-height:1.15; margin:4px 0 2px; }
.kpi-sub         { font-size:0.75rem; opacity:.75; }
.panel-header    { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
.panel-title     { font-size:0.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:#6c757d; margin:0; }
.section-title   { font-size:0.72rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; color:#6c757d; margin-bottom:10px; }
.chart-card      { border-radius:10px; }
.dash-table th   { font-size:0.7rem; text-transform:uppercase; letter-spacing:.05em; color:#6c757d; font-weight:600; background:#f8f9fa; white-space:nowrap; }
.dash-table td   { font-size:0.82rem; vertical-align:middle; }
.dash-table      { margin-bottom:0; }
.scroll-table    { max-height:320px; overflow-y:auto; }
.scroll-table table { margin-bottom:0; }
.status-idle     { background:#fee2e2; color:#b91c1c; font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; white-space:nowrap; }
.status-excess2  { background:#fff7ed; color:#c2410c; font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; white-space:nowrap; }
.status-excess1  { background:#fef3c7; color:#92400e; font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; white-space:nowrap; }
.status-oos      { background:#fee2e2; color:#b91c1c; font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; }
.status-low      { background:#fef3c7; color:#92400e; font-size:0.68rem; padding:2px 8px; border-radius:20px; font-weight:600; }
.clear-state     { text-align:center; padding:22px 0; color:#6c757d; font-size:0.85rem; }
.clear-state i   { font-size:1.6rem; display:block; margin-bottom:6px; color:#2ca87f; }
.aging-bar       { height:6px; border-radius:3px; background:#e9ecef; overflow:hidden; margin-top:4px; }
.aging-fill      { height:100%; border-radius:3px; }
.big-num         { font-size:2rem; font-weight:700; line-height:1; }
.big-label       { font-size:0.7rem; text-transform:uppercase; letter-spacing:.05em; color:#6c757d; margin-top:2px; }
</style>

<div>
<div class="d-flex align-items-center justify-content-between mb-4">
	<h2 class="fw-bold mb-0">Dashboard</h2>
	<div class="text-muted small"><?php echo date('l, F j, Y'); ?></div>
</div>

<!-- ── KPI STRIP ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

	<div class="col-6 col-md-4 col-xl-2">
		<div class="kpi-card h-100" style="background:#eef2ff;border-left:4px solid #4680ff;">
			<i class="ti ti-packages kpi-icon" style="color:#4680ff;"></i>
			<div class="kpi-label" style="color:#4680ff;">On-Hand Value</div>
			<div class="kpi-value" style="color:#1e3a8a;">$<?php echo number_format($ohVal,0); ?></div>
			<div class="kpi-sub"><?php echo $totalParts; ?> parts tracked</div>
		</div>
	</div>

	<div class="col-6 col-md-4 col-xl-2">
		<div class="kpi-card h-100" style="background:#ecfdf5;border-left:4px solid #2ca87f;">
			<i class="ti ti-shopping-cart kpi-icon" style="color:#2ca87f;"></i>
			<div class="kpi-label" style="color:#2ca87f;">On-Order Value</div>
			<div class="kpi-value" style="color:#065f46;">$<?php echo number_format($ooVal,0); ?></div>
			<div class="kpi-sub"><?php echo $openOrders; ?> open order<?php echo $openOrders!=1?'s':''; ?></div>
		</div>
	</div>

	<div class="col-6 col-md-4 col-xl-2">
		<div class="kpi-card h-100" style="background:<?php echo $unpaidAmt>0?'#fff7ed':'#f8f9fa'; ?>;border-left:4px solid <?php echo $unpaidAmt>0?'#e58a00':'#adb5bd'; ?>;">
			<i class="ti ti-receipt-off kpi-icon" style="color:#e58a00;"></i>
			<div class="kpi-label" style="color:#e58a00;">Outstanding</div>
			<div class="kpi-value" style="color:#92400e;">$<?php echo number_format($unpaidAmt,0); ?></div>
			<div class="kpi-sub"><?php echo $unpaidCnt; ?> order<?php echo $unpaidCnt!=1?'s':''; ?> unpaid</div>
		</div>
	</div>

	<div class="col-6 col-md-4 col-xl-2">
		<a href="/low_stock.php" style="text-decoration:none;">
		<div class="kpi-card h-100" style="background:<?php echo $lowStockCount>0?'#fef2f2':'#f8f9fa'; ?>;border-left:4px solid <?php echo $lowStockCount>0?'#dc2626':'#adb5bd'; ?>;cursor:pointer;">
			<i class="ti ti-alert-triangle kpi-icon" style="color:#dc2626;"></i>
			<div class="kpi-label" style="color:#dc2626;">Low Stock</div>
			<div class="kpi-value" style="color:<?php echo $lowStockCount>0?'#991b1b':'#495057'; ?>;"><?php echo $lowStockCount; ?></div>
			<div class="kpi-sub">
				<?php if ($lowStockCount > 0): ?>
				<?php echo $lowStockMinDays; ?>d min coverage · <?php echo $lowStockCount; ?> part<?php echo $lowStockCount!=1?'s':''; ?> at risk
				<?php else: ?>
				all parts covered
				<?php endif; ?>
			</div>
		</div>
		</a>
	</div>

	<div class="col-6 col-md-4 col-xl-2">
		<div class="kpi-card h-100" style="background:#f0fdf4;border-left:4px solid #3ec9d6;">
			<i class="ti ti-hammer kpi-icon" style="color:#3ec9d6;"></i>
			<div class="kpi-label" style="color:#0e7490;">12-Mo Build</div>
			<div class="kpi-value" style="color:#164e63;"><?php echo number_format($build12mo); ?></div>
			<div class="kpi-sub">units consumed</div>
		</div>
	</div>

	<div class="col-6 col-md-4 col-xl-2">
		<div class="kpi-card h-100" style="background:#faf5ff;border-left:4px solid #a855f7;">
			<i class="ti ti-clock kpi-icon" style="color:#a855f7;"></i>
			<div class="kpi-label" style="color:#7c3aed;">Avg Lead Time</div>
			<div class="kpi-value" style="color:#4c1d95;"><?php echo $avgLead; ?></div>
			<div class="kpi-sub">days · <?php echo $avgLeadCnt; ?> actual orders</div>
		</div>
	</div>

</div><!-- end KPI strip -->

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION A — ORDER RECOMMENDATIONS + OPEN ORDERS ETA
     ══════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">

	<!-- 1. ORDER RECOMMENDATIONS -->
	<div class="col-12 col-xl-6">
	<div class="card h-100" style="border-top:3px solid <?php echo $recCount>0?'#e58a00':'#2ca87f'; ?>">
	<div class="card-body">
		<div class="panel-header">
			<span class="panel-title">Order Recommendations</span>
			<?php if ($recCount>0): ?>
			<span style="background:#fff7ed;color:#c2410c;font-size:0.72rem;padding:3px 10px;border-radius:20px;font-weight:700;">
				<?php echo $recCount; ?> item<?php echo $recCount!=1?'s':''; ?> &nbsp;·&nbsp; Est. $<?php echo number_format($recTotal,0); ?>
			</span>
			<?php else: ?>
			<span style="background:#ecfdf5;color:#065f46;font-size:0.72rem;padding:3px 10px;border-radius:20px;font-weight:700;">All Stocked</span>
			<?php endif; ?>
		</div>

		<?php if ($recCount === 0): ?>
		<div class="clear-state"><i class="ti ti-circle-check"></i>All parts are at or above their stock targets. No orders needed.</div>
		<?php else: ?>
		<div class="scroll-table">
		<table class="table dash-table">
			<thead><tr>
				<th>Part / Component</th>
				<th class="text-center">QOH</th>
				<th class="text-center">On Order</th>
				<th class="text-center">BSL / Need</th>
				<th class="text-center">To Order</th>
				<th class="text-end">Est. Cost</th>
			</tr></thead>
			<tbody>

			<?php if (!empty($recCams)): ?>
			<tr style="background:#f1f3f5;"><td colspan="6" class="fw-bold" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;padding:5px 8px;">Camshafts</td></tr>
			<?php foreach ($recCams as $r): ?>
			<tr>
				<td><span class="fw-semibold"><?php echo htmlspecialchars($r['partno']); ?></span> <span class="text-muted" style="font-size:0.78rem;"><?php echo htmlspecialchars($r['desc']); ?></span></td>
				<td class="text-center text-muted"><?php echo number_format($r['qoh']); ?></td>
				<td class="text-center <?php echo $r['on_order']>0?'text-success fw-semibold':'text-muted'; ?>"><?php echo $r['on_order']>0?number_format($r['on_order']):'—'; ?></td>
				<td class="text-center text-muted"><?php echo number_format($r['bsl']); ?></td>
				<td class="text-center fw-bold text-primary"><?php echo number_format($r['rec_qty']); ?></td>
				<td class="text-end fw-semibold">$<?php echo number_format($r['rec_cost'],0); ?></td>
			</tr>
			<?php endforeach; ?>
			<?php endif; ?>

			<?php if (!empty($recUniversal)): ?>
			<tr style="background:#f1f3f5;"><td colspan="6" class="fw-bold" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;padding:5px 8px;">Universal Components</td></tr>
			<?php foreach ($recUniversal as $r): ?>
			<tr>
				<td><span class="fw-semibold"><?php echo htmlspecialchars($r['name']); ?></span></td>
				<td class="text-center text-muted"><?php echo number_format($r['qoh']); ?></td>
				<td class="text-center <?php echo $r['on_order']>0?'text-success fw-semibold':'text-muted'; ?>"><?php echo $r['on_order']>0?number_format($r['on_order']):'—'; ?></td>
				<td class="text-center text-muted"><?php echo number_format($r['need']); ?></td>
				<td class="text-center fw-bold text-primary"><?php echo number_format($r['rec_qty']); ?></td>
				<td class="text-end fw-semibold">$<?php echo number_format($r['rec_cost'],0); ?></td>
			</tr>
			<?php endforeach; ?>
			<?php endif; ?>

			<?php if (!empty($recCards)): ?>
			<tr style="background:#f1f3f5;"><td colspan="6" class="fw-bold" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;padding:5px 8px;">Package Cards</td></tr>
			<?php foreach ($recCards as $r): ?>
			<tr>
				<td><span class="fw-semibold"><?php echo htmlspecialchars($r['partno']); ?></span> <span class="text-muted" style="font-size:0.78rem;"><?php echo htmlspecialchars($r['desc']); ?></span></td>
				<td class="text-center text-muted"><?php echo number_format($r['qoh']); ?></td>
				<td class="text-center <?php echo $r['on_order']>0?'text-success fw-semibold':'text-muted'; ?>"><?php echo $r['on_order']>0?number_format($r['on_order']):'—'; ?></td>
				<td class="text-center text-muted"><?php echo number_format($r['need']); ?></td>
				<td class="text-center fw-bold text-primary"><?php echo number_format($r['rec_qty']); ?></td>
				<td class="text-end fw-semibold">$<?php echo number_format($r['rec_cost'],0); ?></td>
			</tr>
			<?php endforeach; ?>
			<?php endif; ?>

			<?php if (!empty($recAmazon)): ?>
			<tr style="background:#f1f3f5;"><td colspan="6" class="fw-bold" style="font-size:0.7rem;text-transform:uppercase;letter-spacing:.05em;color:#6c757d;padding:5px 8px;">Amazon Pack Cards</td></tr>
			<?php foreach ($recAmazon as $r): ?>
			<tr>
				<td><span class="fw-semibold"><?php echo htmlspecialchars($r['partno']); ?></span> <span class="text-muted" style="font-size:0.78rem;"><?php echo htmlspecialchars($r['desc']); ?></span></td>
				<td class="text-center text-muted"><?php echo number_format($r['qoh']); ?></td>
				<td class="text-center <?php echo $r['on_order']>0?'text-success fw-semibold':'text-muted'; ?>"><?php echo $r['on_order']>0?number_format($r['on_order']):'—'; ?></td>
				<td class="text-center text-muted"><?php echo number_format($r['bsl']); ?></td>
				<td class="text-center fw-bold text-primary"><?php echo number_format($r['rec_qty']); ?></td>
				<td class="text-end fw-semibold">$<?php echo number_format($r['rec_cost'],0); ?></td>
			</tr>
			<?php endforeach; ?>
			<?php endif; ?>

			</tbody>
			<tfoot>
			<tr style="background:#f8f9fa;">
				<td colspan="5" class="fw-semibold text-end small text-muted">Total Estimated Order Value</td>
				<td class="text-end fw-bold">$<?php echo number_format($recTotal,0); ?></td>
			</tr>
			</tfoot>
		</table>
		</div>
		<div class="mt-2 text-end"><a href="/orders/stock_calc.php" class="small text-muted">View full stock order details →</a></div>
		<?php endif; ?>
	</div>
	</div>
	</div>

	<!-- 2. OPEN ORDERS WITH ETA -->
	<div class="col-12 col-xl-6">
	<div class="card h-100" style="border-top:3px solid <?php echo $etaOverdue>0?'#dc2626':($etaSoon>0?'#e58a00':'#2ca87f'); ?>">
	<div class="card-body">
		<div class="panel-header">
			<span class="panel-title">Open Orders — Arrival Status</span>
			<?php if (count($openEtaOrders)>0): ?>
			<span style="font-size:0.72rem;">
				<?php if ($etaOverdue>0): ?><span class="badge bg-danger me-1"><?php echo $etaOverdue; ?> overdue</span><?php endif; ?>
				<?php if ($etaSoon>0):    ?><span class="badge bg-warning text-dark me-1"><?php echo $etaSoon; ?> arriving soon</span><?php endif; ?>
				<?php if ($etaOnTrack>0): ?><span class="badge bg-success me-1"><?php echo $etaOnTrack; ?> on track</span><?php endif; ?>
			</span>
			<?php else: ?>
			<span style="background:#ecfdf5;color:#065f46;font-size:0.72rem;padding:3px 10px;border-radius:20px;font-weight:700;">No Open Orders</span>
			<?php endif; ?>
		</div>

		<?php if (empty($openEtaOrders)): ?>
		<div class="clear-state"><i class="ti ti-truck"></i>No open purchase orders awaiting receipt.</div>
		<?php else: ?>
		<div class="scroll-table">
		<table class="table dash-table">
			<thead><tr>
				<th>Ref</th><th>Part</th><th>Description</th>
				<th class="text-center">Remaining</th><th>Ordered</th>
				<th>ETA</th><th class="text-center">Status</th>
			</tr></thead>
			<tbody>
			<?php foreach ($openEtaOrders as $eo):
				$etaTs   = strtotime($eo['eta']);
				$daysOut = (int)(($etaTs - time()) / 86400);
				if ($daysOut < 0) {
					$etaBadge = '<span class="badge bg-danger">Overdue '.abs($daysOut).'d</span>';
				} elseif ($daysOut <= 45) {
					$etaBadge = '<span class="badge bg-success">In '.$daysOut.'d</span>';
				} elseif ($daysOut <= 60) {
					$etaBadge = '<span class="badge bg-warning text-dark">In '.$daysOut.'d</span>';
				} else {
					$etaBadge = '<span class="badge bg-danger">In '.$daysOut.'d</span>';
				}
			?>
			<tr>
				<td class="text-muted fw-semibold"><?php echo htmlspecialchars($eo['orderref'] ?: '#'.$eo['id']); ?></td>
				<td class="fw-semibold"><?php echo htmlspecialchars($eo['partno']); ?></td>
				<td><?php echo htmlspecialchars($eo['desc']); ?></td>
				<td class="text-center fw-semibold"><?php echo number_format($eo['remaining']); ?> / <?php echo number_format($eo['qty']); ?></td>
				<td class="text-muted" style="white-space:nowrap;"><?php echo date('m/d/y', strtotime($eo['orderdate'])); ?></td>
				<td style="white-space:nowrap;" class="eta-cell" data-order-id="<?php echo $eo['id']; ?>" data-eta="<?php echo date('Y-m-d', $etaTs); ?>">
					<span class="eta-display" style="cursor:pointer;text-decoration:underline dotted;text-underline-offset:3px;" title="Click to edit ETA"><?php echo date('m/d/y', $etaTs); ?></span>
					<input type="date" class="eta-input form-control form-control-sm" style="display:none;width:130px;" value="<?php echo date('Y-m-d', $etaTs); ?>" />
				</td>
				<td class="text-center eta-badge"><?php echo $etaBadge; ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php endif; ?>
	</div>
	</div>
	</div>

</div><!-- end row A -->

<!-- ── RECENT ACTIVITY ──────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
<div class="col-12">
<div class="card"><div class="card-body">
	<div class="section-title">Recent Activity</div>
	<table class="table dash-table">
		<thead><tr><th>Date</th><th>Type</th><th>Part</th><th>Description</th><th class="text-center">Qty</th><th>Reason / Ref</th><th>User</th></tr></thead>
		<tbody>
		<?php foreach ($recentTrans as $rt):
			$label   = $typeLabels[$rt['type']] ?? $rt['type'];
			$badge   = $typeBadge[$rt['type']] ?? 'bg-secondary';
			$qtySign = (int)$rt['qty']>0?'+':'';
		?>
		<tr>
			<td class="text-muted" style="white-space:nowrap;"><?php echo date('m/d/y g:ia', strtotime($rt['date'])); ?></td>
			<td><span class="badge <?php echo $badge; ?>" style="font-size:0.68rem;"><?php echo $label; ?></span></td>
			<td class="fw-semibold"><?php echo htmlspecialchars($rt['partno']); ?></td>
			<td><?php echo htmlspecialchars($rt['desc']); ?></td>
			<td class="text-center <?php echo (int)$rt['qty']<0?'text-danger':'text-success'; ?>"><?php echo $qtySign.(int)$rt['qty']; ?></td>
			<td class="text-muted"><?php echo htmlspecialchars($rt['adjreason']??''); ?></td>
			<td class="text-muted"><?php echo htmlspecialchars($rt['uname']??''); ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div></div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION B — OUTSTANDING PAYMENTS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
<div class="col-12">
<div class="card" style="border-top:3px solid <?php echo $unpaidAmt>0?'#dc2626':'#2ca87f'; ?>">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">Outstanding Payments Owed</span>
		<?php if ($unpaidCnt>0): ?>
		<span style="background:#fef2f2;color:#991b1b;font-size:0.72rem;padding:3px 10px;border-radius:20px;font-weight:700;">
			<?php echo $unpaidCnt; ?> invoices &nbsp;·&nbsp; $<?php echo number_format($unpaidAmt,0); ?> total
		</span>
		<?php endif; ?>
	</div>

	<?php if (empty($payOrders)): ?>
	<div class="clear-state"><i class="ti ti-circle-check"></i>All orders are fully paid. No outstanding balances.</div>
	<?php else: ?>

	<div class="row g-4">

		<!-- LEFT: aging summary + donut -->
		<div class="col-12 col-md-4 col-xl-3">
			<div class="text-center mb-3">
				<div class="big-num text-danger">$<?php echo number_format($unpaidAmt,0); ?></div>
				<div class="big-label">Total Outstanding</div>
			</div>
			<canvas id="chartAging" style="max-height:180px;"></canvas>
			<div class="mt-3">
			<?php foreach ($aging as $label => $amt):
				if ($amt <= 0) continue;
				$pct = round($amt / $unpaidAmt * 100);
				$i   = array_search($label, $agingLabels);
				$color = $agingColors[$i];
			?>
			<div class="d-flex justify-content-between align-items-center mb-1">
				<span style="font-size:0.75rem;color:#6c757d;"><?php echo $label; ?></span>
				<span style="font-size:0.75rem;font-weight:600;">$<?php echo number_format($amt,0); ?></span>
			</div>
			<div class="aging-bar mb-2"><div class="aging-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>;"></div></div>
			<?php endforeach; ?>
			</div>
		</div>

		<!-- RIGHT: full table -->
		<div class="col-12 col-md-8 col-xl-9">
		<div class="scroll-table">
		<table class="table dash-table">
			<thead><tr>
				<th>Order Ref</th><th>Part</th><th>Description</th>
				<th class="text-center">Status</th>
				<th class="text-end">Order Value</th><th class="text-end">Paid</th>
				<th class="text-end">Owed</th><th class="text-end">Age</th>
			</tr></thead>
			<tbody>
			<?php foreach ($payOrders as $po):
				$age    = (int)$po['age_days'];
				$rcvd   = ((int)$po['qty'] <= (int)$po['recqty']);
				if ($age > 730)      { $ageCls = 'text-danger fw-bold'; }
				elseif ($age > 365)  { $ageCls = 'text-danger'; }
				elseif ($age > 180)  { $ageCls = 'fw-semibold'; }
				else                 { $ageCls = 'text-muted'; }
			?>
			<tr>
				<td class="fw-semibold text-muted"><?php echo htmlspecialchars($po['orderref'] ?: '#'.$po['id']); ?></td>
				<td class="fw-semibold"><?php echo htmlspecialchars($po['partno']); ?></td>
				<td><?php echo htmlspecialchars($po['desc']); ?></td>
				<td class="text-center">
					<?php echo $rcvd
						? '<span class="badge bg-success">Received</span>'
						: '<span class="badge bg-warning text-dark">Open</span>'; ?>
				</td>
				<td class="text-end">$<?php echo number_format($po['ordval'],0); ?></td>
				<td class="text-end text-muted">$<?php echo number_format($po['paidamt'],0); ?></td>
				<td class="text-end fw-bold text-danger">$<?php echo number_format($po['owed'],0); ?></td>
				<td class="text-end <?php echo $ageCls; ?>" style="white-space:nowrap;"><?php echo $age; ?>d</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
			<tr style="background:#fef2f2;">
				<td colspan="4" class="fw-semibold text-end small text-muted">Total Owed</td>
				<td class="text-end fw-semibold">$<?php echo number_format($unpaidAmt,0); ?></td>
				<td></td>
				<td class="text-end fw-bold text-danger">$<?php echo number_format($unpaidAmt,0); ?></td>
				<td></td>
			</tr>
			</tfoot>
		</table>
		</div>
		</div>

	</div><!-- end row -->
	<?php endif; ?>

</div>
</div>
</div>
</div><!-- end row B -->

<!-- ══════════════════════════════════════════════════════════════════════════
     SECTION C — OVERSTOCKED / IDLE INVENTORY
     ══════════════════════════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">
<div class="col-12">
<div class="card" style="border-top:3px solid <?php echo $idleCount>0?'#dc2626':($excessCount>0?'#e58a00':'#2ca87f'); ?>">
<div class="card-body">

	<div class="panel-header mb-3">
		<span class="panel-title">Overstocked &amp; Idle Inventory</span>
		<span style="font-size:0.75rem;">
			<?php if ($idleCount>0): ?>
			<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:20px;font-weight:700;margin-right:6px;">
				<?php echo $idleCount; ?> idle &nbsp;·&nbsp; $<?php echo number_format($idleTotal,0); ?>
			</span>
			<?php endif; ?>
			<?php if ($excessCount>0): ?>
			<span style="background:#fff7ed;color:#c2410c;padding:3px 10px;border-radius:20px;font-weight:700;">
				<?php echo $excessCount; ?> excess supply &nbsp;·&nbsp; $<?php echo number_format($excessTotal,0); ?>
			</span>
			<?php endif; ?>
			<?php if (!$idleCount && !$excessCount): ?>
			<span style="background:#ecfdf5;color:#065f46;padding:3px 10px;border-radius:20px;font-weight:700;">All Healthy</span>
			<?php endif; ?>
		</span>
	</div>

	<?php if (empty($idleParts)): ?>
	<div class="clear-state"><i class="ti ti-circle-check"></i>No idle or overstocked inventory detected.</div>
	<?php else: ?>

	<div class="scroll-table">
	<table class="table dash-table">
		<thead><tr>
			<th></th><th>Part</th><th>Description</th>
			<th class="text-center">QOH</th><th class="text-center">BSL</th>
			<th class="text-center">12-Mo Demand</th><th class="text-center">Months Supply</th>
			<th class="text-end">OH Value</th>
		</tr></thead>
		<tbody>
		<?php foreach ($idleParts as $ip):
			if ($ip['status'] === 'IDLE') {
				$badge = '<span class="status-idle">Idle — No Demand</span>';
			} elseif ($ip['status'] === '2yr+ Supply') {
				$badge = '<span class="status-excess2">2yr+ Supply</span>';
			} else {
				$badge = '<span class="status-excess1">1yr+ Supply</span>';
			}
			$mosDisplay = $ip['months_supply'] >= 9999 ? '∞' : number_format($ip['months_supply'], 1);
		?>
		<tr>
			<td><?php echo $badge; ?></td>
			<td class="fw-semibold"><?php echo htmlspecialchars($ip['partno']); ?></td>
			<td><?php echo htmlspecialchars($ip['desc']); ?></td>
			<td class="text-center"><?php echo number_format($ip['qoh']); ?></td>
			<td class="text-center text-muted"><?php echo $ip['bsl']>0?number_format($ip['bsl']):'—'; ?></td>
			<td class="text-center <?php echo $ip['demand_12mo']==0?'text-danger':'text-muted'; ?>">
				<?php echo $ip['demand_12mo']>0?number_format($ip['demand_12mo']):'<span class="fw-bold">None</span>'; ?>
			</td>
			<td class="text-center <?php echo $ip['months_supply']>=9999?'text-danger fw-bold':($ip['months_supply']>24?'text-danger':($ip['months_supply']>12?'fw-semibold':'')); ?>">
				<?php echo $mosDisplay; ?>
			</td>
			<td class="text-end fw-semibold">$<?php echo number_format($ip['oh_val'],0); ?></td>
		</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot>
		<tr style="background:#fff7ed;">
			<td colspan="7" class="text-end fw-semibold small text-muted">Total Capital in Idle / Excess Inventory</td>
			<td class="text-end fw-bold">$<?php echo number_format($idleTotal+$excessTotal,0); ?></td>
		</tr>
		</tfoot>
	</table>
	</div>
	<?php endif; ?>

</div>
</div>
</div>
</div><!-- end row C -->

<!-- ── CHARTS ROW 1 ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
	<div class="col-12 col-xl-7">
		<div class="card chart-card h-100"><div class="card-body">
			<div class="section-title">Monthly Build Demand — Last 12 Months</div>
			<canvas id="chartBuild" style="max-height:240px;"></canvas>
		</div></div>
	</div>
	<div class="col-12 col-xl-5">
		<div class="card chart-card h-100"><div class="card-body">
			<div class="section-title">On-Hand Value by Category</div>
			<div style="max-height:240px;display:flex;align-items:center;justify-content:center;">
				<canvas id="chartCat"></canvas>
			</div>
		</div></div>
	</div>
</div>

<!-- ── CHARTS ROW 2 ─────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
	<div class="col-12 col-xl-6">
		<div class="card chart-card h-100"><div class="card-body">
			<div class="section-title">Orders Placed vs Payments — Last 12 Months</div>
			<canvas id="chartFinance" style="max-height:220px;"></canvas>
		</div></div>
	</div>
	<div class="col-12 col-xl-6">
		<div class="card chart-card h-100"><div class="card-body">
			<div class="section-title">Top Parts by 12-Mo Build Demand</div>
			<canvas id="chartDemand" style="max-height:220px;"></canvas>
		</div></div>
	</div>
</div>

<!-- ── DETAIL TABLES ROW ────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

	<!-- Parts Needing Attention -->
	<div class="col-12 col-xl-6">
	<div class="card h-100"><div class="card-body">
		<div class="section-title">
			Parts Needing Attention
			<?php if ($lowStockCount+$belowBsl>0): ?><span class="badge bg-danger ms-1"><?php echo $lowStockCount+$belowBsl; ?></span><?php endif; ?>
		</div>
		<?php if (empty($attnParts)): ?>
		<div class="clear-state"><i class="ti ti-circle-check"></i>All parts are at or above stock level.</div>
		<?php else: ?>
		<table class="table dash-table">
			<thead><tr><th>Part</th><th>Description</th><th class="text-center">QOH</th><th class="text-center">BSL</th><th class="text-center">On Order</th><th></th></tr></thead>
			<tbody>
			<?php foreach ($attnParts as $ap):
				$oos = ((int)$ap['qoh']===0);
			?>
			<tr>
				<td class="fw-semibold"><?php echo htmlspecialchars($ap['partno']); ?></td>
				<td><?php echo htmlspecialchars($ap['desc']); ?></td>
				<td class="text-center <?php echo $oos?'text-danger fw-bold':'text-warning fw-semibold'; ?>"><?php echo $ap['qoh']; ?></td>
				<td class="text-center text-muted"><?php echo $ap['bsl']; ?></td>
				<td class="text-center"><?php echo (int)$ap['on_order']>0?'<span class="text-success fw-semibold">'.htmlspecialchars($ap['on_order']).'</span>':'<span class="text-muted">—</span>'; ?></td>
				<td><span class="<?php echo $oos?'status-oos':'status-low'; ?>"><?php echo $oos?'OUT':'LOW'; ?></span></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div></div>
	</div>

	<!-- Top Parts by On-Hand Value -->
	<div class="col-12 col-xl-6">
	<div class="card h-100"><div class="card-body">
		<div class="section-title">Top Parts by On-Hand Value</div>
		<table class="table dash-table">
			<thead><tr><th>Part</th><th>Description</th><th class="text-end">QOH</th><th class="text-end">OH Value</th></tr></thead>
			<tbody>
			<?php foreach ($ohParts as $op): ?>
			<tr>
				<td class="fw-semibold"><?php echo htmlspecialchars($op['partno']); ?></td>
				<td><?php echo htmlspecialchars($op['desc']); ?></td>
				<td class="text-end text-muted"><?php echo number_format($op['qoh']); ?></td>
				<td class="text-end fw-semibold">$<?php echo number_format($op['val'],0); ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div></div>
	</div>

</div>

</div><!-- end main -->

<script>
const fontDefaults = { font:{family:'Inter, sans-serif',size:11}, color:'#6c757d' };

// ── BUILD DEMAND ────────────────────────────────────────────────────────────
new Chart(document.getElementById('chartBuild'), {
	type:'bar',
	data:{ labels:<?php echo json_encode($buildLabels); ?>, datasets:[{ label:'Units Used', data:<?php echo json_encode($buildData); ?>, backgroundColor:'rgba(70,128,255,0.75)', borderColor:'#4680ff', borderWidth:1, borderRadius:4 }] },
	options:{ responsive:true, maintainAspectRatio:true,
		plugins:{ legend:{display:false}, tooltip:{callbacks:{label:ctx=>' '+ctx.parsed.y.toLocaleString()+' units'}} },
		scales:{ x:{ticks:fontDefaults,grid:{display:false}}, y:{ticks:{...fontDefaults,callback:v=>v.toLocaleString()},beginAtZero:true} } }
});

// ── CATEGORY DONUT ──────────────────────────────────────────────────────────
new Chart(document.getElementById('chartCat'), {
	type:'doughnut',
	data:{ labels:<?php echo json_encode($catLabels); ?>, datasets:[{ data:<?php echo json_encode($catData); ?>, backgroundColor:<?php echo json_encode($catColorMap); ?>, borderWidth:2, borderColor:'#fff', hoverOffset:6 }] },
	options:{ responsive:true, maintainAspectRatio:true, cutout:'60%',
		plugins:{ legend:{position:'right',labels:{...fontDefaults,boxWidth:12,padding:10}},
			tooltip:{callbacks:{label:ctx=>' '+ctx.label+': $'+ctx.parsed.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:0})}} } }
});

// ── FINANCE (orders vs payments) ────────────────────────────────────────────
new Chart(document.getElementById('chartFinance'), {
	type:'bar',
	data:{ labels:<?php echo json_encode($finLabels); ?>, datasets:[
		{ label:'Orders Placed', data:<?php echo json_encode($ordData); ?>, backgroundColor:'rgba(70,128,255,0.65)', borderColor:'#4680ff', borderWidth:1, borderRadius:3, order:2 },
		{ label:'Payments Made', data:<?php echo json_encode($payData); ?>, type:'line', borderColor:'#2ca87f', backgroundColor:'rgba(44,168,127,0.12)', borderWidth:2, pointRadius:4, pointBackgroundColor:'#2ca87f', tension:0.3, fill:true, order:1 }
	] },
	options:{ responsive:true, maintainAspectRatio:true,
		plugins:{ legend:{labels:{...fontDefaults,boxWidth:12}}, tooltip:{callbacks:{label:ctx=>' $'+ctx.parsed.y.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:0})}} },
		scales:{ x:{ticks:fontDefaults,grid:{display:false}}, y:{ticks:{...fontDefaults,callback:v=>'$'+(v/1000).toFixed(0)+'k'},beginAtZero:true} } }
});

// ── TOP PARTS DEMAND (horizontal) ───────────────────────────────────────────
new Chart(document.getElementById('chartDemand'), {
	type:'bar',
	data:{ labels:<?php echo json_encode($demPartLabels); ?>, datasets:[{ label:'Units Used', data:<?php echo json_encode($demPartData); ?>, backgroundColor:['rgba(70,128,255,0.75)','rgba(44,168,127,0.75)','rgba(229,138,0,0.75)','rgba(62,201,214,0.75)','rgba(168,85,247,0.75)','rgba(249,115,22,0.75)','rgba(220,38,38,0.75)','rgba(156,163,175,0.75)'], borderRadius:4, borderWidth:0 }] },
	options:{ indexAxis:'y', responsive:true, maintainAspectRatio:true,
		plugins:{ legend:{display:false}, tooltip:{callbacks:{label:ctx=>' '+ctx.parsed.x.toLocaleString()+' units'}} },
		scales:{ x:{ticks:{...fontDefaults,callback:v=>v.toLocaleString()},beginAtZero:true,grid:{color:'#f0f0f0'}}, y:{ticks:fontDefaults,grid:{display:false}} } }
});

// ── PAYMENT AGING DONUT ──────────────────────────────────────────────────────
<?php if (!empty($payOrders)): ?>
new Chart(document.getElementById('chartAging'), {
	type:'doughnut',
	data:{ labels:<?php echo json_encode($agingLabels); ?>, datasets:[{ data:<?php echo json_encode($agingData); ?>, backgroundColor:<?php echo json_encode($agingColors); ?>, borderWidth:2, borderColor:'#fff', hoverOffset:4 }] },
	options:{ responsive:true, maintainAspectRatio:true, cutout:'65%',
		plugins:{ legend:{display:false}, tooltip:{callbacks:{label:ctx=>' '+ctx.label+': $'+ctx.parsed.toLocaleString(undefined,{minimumFractionDigits:0,maximumFractionDigits:0})}} } }
});
<?php endif; ?>

// ETA inline edit
$(document).on('click', '.eta-display', function() {
	var $cell = $(this).closest('.eta-cell');
	$(this).hide();
	$cell.find('.eta-input').show().focus();
});

$(document).on('blur change', '.eta-input', function(e) {
	var $input = $(this);
	var $cell  = $input.closest('.eta-cell');
	var $display = $cell.find('.eta-display');
	var orderId  = $cell.data('order-id');
	var newDate  = $input.val();
	if (!newDate) { $input.hide(); $display.show(); return; }

	$.post('/ajax/edit_order_eta.php', { id: orderId, eta: newDate }, function(res) {
		if (res.trim() !== 'ok') { alert('Save failed.'); $input.hide(); $display.show(); return; }

		// Format date for display
		var parts  = newDate.split('-');
		var d      = new Date(parts[0], parts[1]-1, parts[2]);
		var fmt    = ('0'+(d.getMonth()+1)).slice(-2) + '/' + ('0'+d.getDate()).slice(-2) + '/' + String(d.getFullYear()).slice(-2);
		$display.text(fmt).show();
		$input.hide();

		// Recalculate status badge
		var today  = new Date(); today.setHours(0,0,0,0);
		var daysOut = Math.round((d - today) / 86400000);
		var badge;
		if (daysOut < 0)       badge = '<span class="badge bg-danger">Overdue '+Math.abs(daysOut)+'d</span>';
		else if (daysOut <= 45) badge = '<span class="badge bg-success">In '+daysOut+'d</span>';
		else if (daysOut <= 60) badge = '<span class="badge bg-warning text-dark">In '+daysOut+'d</span>';
		else                   badge = '<span class="badge bg-danger">In '+daysOut+'d</span>';
		$cell.closest('tr').find('.eta-badge').html(badge);
	});
});
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
