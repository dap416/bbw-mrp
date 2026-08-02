<?php
/* ============================================================
   CASH FLOW — the new Titan-themed forecaster module.
   Full-bleed Titan shell (Option A): owns its own sidebar + top
   bar, skips the Berry header/footer. Admin/master only.
   ============================================================ */

require_once(__DIR__ . "/includes/fns.php");
require_login();
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true)) { http_response_code(403); exit('Admins only.'); }

require_once(__DIR__ . "/includes/cash_flow.php");
require_once(__DIR__ . "/titan-bbw/titan-icons.php");
require_once(__DIR__ . "/titan-bbw/titan-components.php");

$db = db_connect();
cf_ensure_tables($db);
cf_seed_records_if_empty($db);

$hs      = cf_horizon_start();
$buffer  = cash_buffer($db);
$taxPct  = cf_avg_sales_tax_pct($db);
$shopPct = shopify_loan_pct($db);
$growth  = 0.0;   // no growth control in the design; kept at 0 (prior-year baseline)
$availDebt = (float)(setting_get($db, 'cf_avail_debt') ?: 12000);

$acc     = cf_opening_accounts($db, $hs);   // projection opening (live balances)
$live    = cf_live_accounts($db);           // editable set for the Accounts view + readouts
$records = cf_load_records($db);
$opts    = ['horizon_start' => $hs, 'growth' => $growth, 'buffer' => $buffer, 'tax_pct' => $taxPct, 'shop_pct' => $shopPct];
$debts   = cf_debts($live);
$suggest = cf_suggest_map($live, $availDebt);
// Drive the projection from the snowball BUDGET, not from a one-off allocation.
// cf_compute re-derives minimums and re-picks the target every month, so a
// facility that clears frees its minimum and the surplus rolls to the next one.
// The Debt view's cf_suggest_map() still shows this month's split; the forecast
// now cascades the same rule forward instead of freezing month-0's answer.
$opts['debt_budget'] = $availDebt;
$rows    = cf_compute($acc, $records, $opts);
$cols    = cf_month_cols($hs, 12);
$afford  = cf_afford_calc($live, $records, $opts);
$qbAccounts = cf_qb_account_options($db);   // for the "auto-sync this account" picker

// "Now" readouts. LOC room and card room stay separate everywhere they're shown —
// a line of credit can be drawn as cash, a credit card can only buy things — and
// are only ever added back together in the combined "Liquid available" figure.
$cashNow     = $live['start_cash'];
$locAvailNow = $live['loc_available'];
$cardAvailNow= $live['card_available'];
$availNow    = $live['credit_available'];   // LOC + card room, combined
$cardDebtNow = $live['card_debt'];
$loanDebtNow = $live['loan_debt'];
$debtNow     = $live['credit_used'];        // card debt + loan debt, combined
$liquidNow   = $cashNow + $availNow;

// pay-method options for the record modal
$payOptions = [['v' => 'cash', 'label' => 'Cash']];
foreach ($live['cards'] as $c) $payOptions[] = ['v' => $c['label'], 'label' => $c['label']];
// Payout facilities (Shopify Capital) are deliberately NOT offered: they are term
// loans repaid from sales, not revolving lines you can charge against, so the
// forecast has no facility to route such a charge onto.
foreach ($live['locs']  as $l) {
	if (!empty($l['payout'])) continue;
	$payOptions[] = ['v' => $l['label'], 'label' => $l['label'] . ' (LOC)'];
}

$horizonLabel = cf_month_label($hs)['name'] . " " . cf_month_label($hs)['year'] . " \xE2\x80\x93 "
	. cf_month_label(cf_add_months($hs, 11))['name'] . " " . cf_month_label(cf_add_months($hs, 11))['year'];

/* ---- matrix chart (hand-built SVG, prototype style) ---- */
function cf_chart_svg($rows, $buffer) {
	$W = 820; $H = 128; $padT = 10; $padB = 8;
	$cash = array_map(fn($r) => $r['endCash'], $rows);
	$liq  = array_map(fn($r) => $r['liquid'], $rows);
	$max  = max(max($liq), $buffer) * 1.08;
	$min  = min(0, min($cash));
	$span = ($max - $min) ?: 1;
	$n = count($rows);
	$x = fn($i) => round(($n <= 1 ? 0 : $i / ($n - 1)) * $W, 1);
	$y = fn($v) => round($padT + ($H - $padT - $padB) * (1 - ($v - $min) / $span), 1);
	$pts = fn($arr) => implode(' ', array_map(fn($i) => $x($i) . ',' . $y($arr[$i]), array_keys($arr)));
	$cashPts = $pts($cash); $liqPts = $pts($liq); $by = $y($buffer);
	$area = 'M0,' . $y($cash[0]);
	foreach ($cash as $i => $v) $area .= ' L' . $x($i) . ',' . $y($v);
	$area .= ' L' . $x($n - 1) . ',' . ($H - $padB) . ' L0,' . ($H - $padB) . ' Z';
	$dots = '';
	foreach ($rows as $i => $r) if ($r['cashRisk']) $dots .= '<circle cx="' . $x($i) . '" cy="' . $y($r['endCash']) . '" r="3" fill="var(--crit)"/>';
	return '<svg viewBox="0 0 ' . $W . ' ' . $H . '" preserveAspectRatio="none" style="width:100%;height:130px;display:block">'
		. '<defs><linearGradient id="cfg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="var(--accent)" stop-opacity="0.22"/><stop offset="1" stop-color="var(--accent)" stop-opacity="0"/></linearGradient></defs>'
		. '<path d="' . $area . '" fill="url(#cfg)"/>'
		. '<line x1="0" y1="' . $by . '" x2="' . $W . '" y2="' . $by . '" stroke="var(--crit)" stroke-width="1" stroke-dasharray="4 4" opacity="0.7"/>'
		. '<polyline points="' . $liqPts . '" fill="none" stroke="var(--good)" stroke-width="2"/>'
		. '<polyline points="' . $cashPts . '" fill="none" stroke="var(--accent)" stroke-width="2.4"/>'
		. $dots . '</svg>';
}

/* ---- matrix row renderer ---- */
function cf_row($cols, $rows, $c) {
	$ind = $c['indent'] ?? 0;
	$trAttr = '';
	if (!empty($c['rowbg'])) $trAttr .= ' style="background:var(--bg-inset)"';
	if (!empty($c['child']))  $trAttr .= ' class="cf-child"' . ' hidden';
	echo '<tr' . $trAttr . '>';
	echo '<td class="cf-stick"' . ($ind ? ' style="padding-left:' . (12 + $ind) . 'px"' : '') . '>';
	if (!empty($c['caret'])) echo '<span class="cf-caret" id="cfIncCaret">' . $c['caret'] . '</span> ';
	echo '<span style="color:' . ($c['labelColor'] ?? 'var(--tx-mid)') . ';font-weight:' . ($c['weight'] ?? 500) . '">' . htmlspecialchars($c['label']) . '</span>';
	if (!empty($c['sub'])) echo '<div class="cf-sub mono">' . htmlspecialchars($c['sub']) . '</div>';
	echo '</td>';
	foreach ($cols as $i => $col) {
		$r = $rows[$i];
		$v = ($c['get'])($r);
		if (!empty($c['managed'])) {
			echo '<td><button type="button" class="cf-cb" data-row="' . $c['managed'] . '" data-m="' . $i . '" data-chan="' . htmlspecialchars($c['chan'] ?? '') . '">' . cf_money($v) . '</button></td>';
		} else {
			$disp = (abs($v) < 0.5 && !empty($c['dashzero'])) ? cf_dash() : cf_money($v);
			$color = isset($c['color']) ? ($c['color'])($r, $v) : 'var(--tx-hi)';
			echo '<td class="cf-num" style="color:' . $color . ';font-weight:' . ($c['weight'] ?? 500) . '">' . $disp . '</td>';
		}
	}
	echo '</tr>';
}
function cf_group_row($label, $cols) {
	echo '<tr class="cf-grouprow"><td class="cf-stick cf-group">' . htmlspecialchars($label) . '</td>';
	foreach ($cols as $col) echo '<td class="cf-group"></td>';
	echo '</tr>';
}

/** "as of MON D" from the newest as_of across a set of accounts (skips zero-dates). */
function cf_asof_label($accts) {
	$max = '';
	foreach ($accts as $a) {
		$d = (string)($a['as_of'] ?? '');
		if ($d !== '' && strpos($d, '0000') !== 0 && $d > $max) $max = $d;
	}
	return $max ? 'as of ' . strtoupper(date('M j', strtotime($max))) : '';
}
function cf_asof_html($accts) {
	$l = cf_asof_label($accts);
	return $l ? ' <span class="mono" style="font-size:9px;color:var(--tx-lo);font-weight:400;letter-spacing:.04em;text-transform:none;margin-left:6px">' . htmlspecialchars($l) . '</span>' : '';
}

// nav for the Titan shell (links back into the Berry app)
$nav = [
	'MRP' => [
		['label' => 'Dashboard', 'icon' => 'gauge', 'href' => '/home.php'],
		['label' => 'Orders', 'icon' => 'file', 'href' => '/orders.php'],
		['label' => 'Inventory', 'icon' => 'parts', 'href' => '/index.php'],
	],
	'Finance' => [
		['label' => 'Cash Flow', 'icon' => 'spark', 'href' => '/cash_flow.php', 'on' => true],
		['label' => 'Cash Management', 'icon' => 'clipboard', 'href' => '/cashflow.php'],
	],
];
// Top bar intentionally minimal — the liquidity readouts live in the control bar
// below (avoids duplicating them), and the module name is already in the sidebar.
$statsHtml = '';
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Cash Flow · BBW MRP</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap">
	<link rel="stylesheet" href="/titan-bbw/titan-bbw.css?v=<?php echo @filemtime(__DIR__ . '/titan-bbw/titan-bbw.css'); ?>">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
	<style>
	/* ---- module-local styles (matrix, control bar, modals) ---- */
	/* flush, divider-joined layout (locked together — no floating rounded cards) */
	.titan-app .t-content{ padding:0; }
	.titan-app .t-panel{ background:transparent; border:0; border-radius:0; box-shadow:none; margin:0 !important; border-bottom:1px solid var(--line); }
	.titan-app .t-panel-body{ padding:16px 22px; }
	.cf-view > .t-panel:last-child{ border-bottom:none; }
	.cf-strip{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:10px 22px; border-bottom:1px solid var(--line); }
	.cf-bar{ display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:13px 22px; border-bottom:1px solid var(--line); }
	.titan-app .t-table tfoot td{ border-top:1px solid var(--line-2); font-family:var(--font-num); font-variant-numeric:tabular-nums; color:var(--tx-hi); font-weight:700; padding-top:9px; }
	.titan-app .t-table tfoot .lbl{ font-family:var(--font-mono); font-size:9px; letter-spacing:.06em; text-transform:uppercase; color:var(--tx-lo); font-weight:600; }
	.cf-ctrl{ display:flex; align-items:center; gap:8px; }
	.cf-mini{ display:flex; align-items:center; gap:6px; background:var(--bg-inset); border:1px solid var(--line-2); border-radius:8px; padding:5px 10px; }
	.cf-mini label{ font-family:var(--font-mono); font-size:9.5px; letter-spacing:.06em; text-transform:uppercase; color:var(--tx-lo); }
	.cf-mini input{ width:64px; background:transparent; border:none; outline:none; color:var(--tx-hi); font-family:var(--font-num); font-size:13px; text-align:right; }
	.cf-readouts{ display:flex; gap:18px; margin-left:auto; flex-wrap:wrap; justify-content:flex-end; }
	.cf-ro{ text-align:right; }
	.cf-ro-lbl{ font-family:var(--font-mono); font-size:8.5px; letter-spacing:.08em; text-transform:uppercase; color:var(--tx-lo); }
	.cf-ro-val{ font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:16px; font-weight:700; color:var(--tx-hi); }
	.cf-ro.accent .cf-ro-val{ color:var(--accent); }

	.cf-mx-wrap{ overflow-x:auto; }
	table.cf-mx{ border-collapse:collapse; width:100%; min-width:1000px; }
	.cf-mx th, .cf-mx td{ padding:7px 12px; border-bottom:1px solid var(--line); white-space:nowrap; }
	.cf-mx thead th{ position:sticky; top:0; z-index:2; background:var(--bg-inset); font-family:var(--font-mono); font-size:9.5px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; color:var(--tx-lo); text-align:right; }
	.cf-mx thead th .cf-yr{ display:block; font-size:8px; color:var(--tx-lo); opacity:.7; }
	.cf-stick{ position:sticky; left:0; z-index:1; background:var(--bg-1); text-align:left; min-width:190px; }
	.cf-mx thead .cf-stick{ z-index:3; background:var(--bg-inset); }
	.cf-mx td.cf-num, .cf-mx td:not(.cf-stick){ text-align:right; font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:12.5px; }
	.cf-mx td:nth-child(2){ background:var(--accent-soft); box-shadow:inset 1px 0 0 var(--accent-line), inset -1px 0 0 var(--accent-line); }
	.cf-mx thead th:nth-child(2){ background:var(--accent-soft); }
	.cf-sub{ font-size:8px; letter-spacing:.05em; text-transform:uppercase; color:var(--tx-lo); margin-top:2px; }
	.cf-cb{ background:none; border:none; cursor:pointer; font-family:var(--font-num); font-variant-numeric:tabular-nums; font-size:12.5px; color:var(--tx-hi); padding:2px 4px; border-radius:5px; }
	.cf-cb:hover{ background:var(--accent-soft); color:var(--accent); box-shadow:inset 0 0 0 1px var(--accent-line); }
	.cf-grouprow td.cf-group{ background:var(--bg-inset); font-family:var(--font-mono); font-size:9px; letter-spacing:.12em; text-transform:uppercase; color:var(--tx-mid); padding:5px 12px; }
	.cf-caret{ display:inline-block; cursor:pointer; color:var(--tx-lo); width:12px; user-select:none; }
	.cf-legend{ display:flex; gap:18px; margin-top:12px; font-family:var(--font-mono); font-size:9px; letter-spacing:.06em; text-transform:uppercase; color:var(--tx-lo); flex-wrap:wrap; }
	.cf-legend span{ display:inline-flex; align-items:center; gap:6px; }

	.cf-view{ display:none; }
	.cf-view.on{ display:block; }
	.cf-acct-grid{ display:grid; grid-template-columns:minmax(0,0.8fr) minmax(0,1.1fr) minmax(0,1.1fr); gap:0; }
	.cf-acct-grid > .t-panel{ border-bottom:none; border-right:1px solid var(--line); }
	.cf-acct-grid > .t-panel:last-child{ border-right:none; }
	@media(max-width:1100px){ .cf-acct-grid{ grid-template-columns:1fr; } .cf-acct-grid > .t-panel{ border-right:none; border-bottom:1px solid var(--line); } }
	.cf-modal-body{ padding:20px 22px; }
	.cf-modal-head{ display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; }
	.cf-x{ background:none; border:none; color:var(--tx-lo); cursor:pointer; font-size:18px; line-height:1; }
	.cf-x:hover{ color:var(--tx-hi); }
	.cf-planned-inp{ width:88px; background:var(--bg-inset); border:1px solid var(--line-2); border-radius:6px; color:var(--tx-hi); font-family:var(--font-num); font-size:12px; text-align:right; padding:3px 7px; }
	.cf-actuals{ border:1px solid var(--warn-line); border-radius:12px; background:var(--warn-soft); margin-top:14px; }
	.cf-pay-menu{ position:fixed; z-index:70; background:var(--bg-2); border:1px solid var(--line-2); border-radius:10px; box-shadow:var(--shadow-pop); padding:5px; min-width:180px; max-height:260px; overflow:auto; }
	.cf-pay-menu button{ display:flex; width:100%; align-items:center; justify-content:space-between; gap:8px; background:none; border:none; color:var(--tx-mid); font-family:var(--font-ui); font-size:12.5px; padding:7px 10px; border-radius:7px; cursor:pointer; text-align:left; }
	.cf-pay-menu button:hover{ background:var(--bg-3); color:var(--tx-hi); }
	.cf-note-inline{ font-size:11px; color:var(--tx-lo); margin-top:6px; }
	.cf-fieldrow{ display:grid; grid-template-columns:1fr 1fr; gap:12px; }
	.cf-seg2{ display:inline-flex; background:var(--bg-3); border-radius:8px; padding:3px; gap:2px; border:1px solid var(--line); flex-wrap:wrap; }
	.cf-seg2 button{ font-family:var(--font-mono); font-size:10.5px; font-weight:600; padding:5px 9px; border-radius:6px; cursor:pointer; border:none; background:transparent; color:var(--tx-mid); }
	.cf-seg2 button.on{ background:var(--accent-deep); color:#fff; }
	</style>
</head>
<body>
<?php echo t_shell_open('', $nav, $statsHtml, 'dark'); ?>

	<!-- ===== CONTROL BAR ===== -->
	<div class="cf-bar">
		<div class="t-seg" id="cfViews" role="tablist">
			<button class="on" data-view="cash">Cash Flow</button>
			<button data-view="debt">Debt Reduction</button>
			<button data-view="accounts">Accounts</button>
		</div>
		<div class="cf-mini"><label>Cash buffer</label><span style="color:var(--tx-lo)">$</span><input id="cfBuffer" value="<?php echo (int)$buffer; ?>"></div>
		<div class="cf-mini"><label>Avg sales tax</label><input id="cfTax" value="<?php echo rtrim(rtrim(number_format($taxPct, 2), '0'), '.'); ?>"><span style="color:var(--tx-lo)">%</span></div>
		<div class="cf-readouts">
			<div class="cf-ro"><div class="cf-ro-lbl">Cash on hand</div><div class="cf-ro-val"><?php echo cf_money($cashNow); ?></div></div>
			<div class="cf-ro" title="Undrawn room on your lines of credit — this can be drawn as cash into the bank."><div class="cf-ro-lbl">LOC available</div><div class="cf-ro-val"><?php echo cf_money($locAvailNow); ?></div></div>
			<div class="cf-ro" title="Unused credit-card limit. Purchasing power only — it cannot be drawn as cash."><div class="cf-ro-lbl">Card available</div><div class="cf-ro-val"><?php echo cf_money($cardAvailNow); ?></div></div>
			<div class="cf-ro accent" title="Cash on hand + LOC available + card available."><div class="cf-ro-lbl">Liquid available</div><div class="cf-ro-val"><?php echo cf_money($liquidNow); ?></div></div>
			<div class="cf-ro" title="Balances owed on credit cards."><div class="cf-ro-lbl">Card debt</div><div class="cf-ro-val"><?php echo cf_money($cardDebtNow); ?></div></div>
			<div class="cf-ro" title="Drawn balances on lines of credit and loans."><div class="cf-ro-lbl">LOC / loan debt</div><div class="cf-ro-val"><?php echo cf_money($loanDebtNow); ?></div></div>
			<div class="cf-ro" title="Card debt + LOC / loan debt."><div class="cf-ro-lbl">Total debt</div><div class="cf-ro-val"><?php echo cf_money($debtNow); ?></div></div>
		</div>
	</div>

	<div class="cf-strip">
		<span class="t-eyebrow">Opening balances · live</span>
	</div>

	<!-- ============ VIEW: CASH FLOW ============ -->
	<div class="cf-view on" id="view-cash">
		<div class="t-panel" style="margin-bottom:16px">
			<div class="t-panel-body">
				<div class="t-panel-head">
					<h6 class="t-panel-title">Projected balances · <?php echo htmlspecialchars($horizonLabel); ?></h6>
					<div style="display:flex;gap:14px;align-items:center">
						<span class="cf-legend" style="margin:0"><span><span class="t-dot accent nohalo"></span>Ending cash</span><span><span class="t-dot good nohalo"></span>Liquid available</span><span><span style="width:12px;height:0;border-top:1px dashed var(--crit);display:inline-block"></span>Buffer</span></span>
						<button class="t-btn sm" id="cfChartToggle"><span>Hide chart</span></button>
					</div>
				</div>
				<div id="cfChart"><?php echo cf_chart_svg($rows, $buffer); ?></div>
			</div>
		</div>

		<div class="t-panel">
			<div class="t-panel-body">
				<div class="cf-mx-wrap">
					<table class="cf-mx">
						<thead><tr>
							<th class="cf-stick" style="text-align:left">Line item</th>
							<?php foreach ($cols as $i => $col) {
								$r = $rows[$i];
								$hc = $r['cashRisk'] ? 'var(--crit)' : ($r['creditTight'] ? 'var(--warn)' : 'var(--tx-mid)');
								echo '<th style="color:' . $hc . '">' . $col['name'] . '<span class="cf-yr">' . $col['year'] . '</span></th>';
							} ?>
						</tr></thead>
						<tbody>
						<?php
						// 1 — Sales income (managed, expandable)
						cf_row($cols, $rows, ['label' => 'Sales income', 'managed' => 'income', 'get' => fn($r) => $r['inc'], 'weight' => 700, 'labelColor' => 'var(--tx-hi)', 'caret' => "\xE2\x96\xB8"]);
						cf_row($cols, $rows, ['label' => 'Online', 'child' => true, 'indent' => 16, 'managed' => 'income', 'chan' => 'Online', 'get' => fn($r) => $r['online'], 'dashzero' => true]);
						cf_row($cols, $rows, ['label' => 'Shows', 'child' => true, 'indent' => 16, 'managed' => 'income', 'chan' => 'Shows', 'get' => fn($r) => $r['shows'], 'dashzero' => true]);
						cf_row($cols, $rows, ['label' => 'Wholesale', 'child' => true, 'indent' => 16, 'managed' => 'income', 'chan' => 'Wholesale', 'get' => fn($r) => $r['wholesale'], 'dashzero' => true]);
						// Cash out
						cf_group_row('Cash out', $cols);
						cf_row($cols, $rows, ['label' => 'Operating', 'managed' => 'operating', 'get' => fn($r) => $r['op']]);
						cf_row($cols, $rows, ['label' => 'Purchases', 'managed' => 'purchase', 'get' => fn($r) => $r['pur']]);
						// Memo only — how the Operating + Purchases spend above is funded. Neither
						// line is a term in the Cash out sum: the cash half is what reaches Total
						// cash out, the credit half raises a facility balance instead.
						cf_row($cols, $rows, ['label' => 'Charged to cash', 'indent' => 16, 'get' => fn($r) => $r['onCash'], 'dashzero' => true, 'color' => fn($r, $v) => 'var(--tx-lo)']);
						cf_row($cols, $rows, ['label' => 'Charged to credit', 'indent' => 16, 'get' => fn($r) => $r['onCredit'], 'dashzero' => true, 'color' => fn($r, $v) => 'var(--tx-lo)']);
						cf_row($cols, $rows, ['label' => 'Shopify payback', 'get' => fn($r) => $r['shopPay'], 'dashzero' => true, 'color' => fn($r, $v) => 'var(--tx-mid)']);
						cf_row($cols, $rows, ['label' => 'Debt paydown', 'get' => fn($r) => $r['dp'], 'dashzero' => true, 'color' => fn($r, $v) => 'var(--tx-mid)']);
						cf_row($cols, $rows, ['label' => 'Total cash out', 'get' => fn($r) => $r['cashOut'], 'weight' => 600, 'labelColor' => 'var(--tx-hi)', 'rowbg' => true, 'color' => fn($r, $v) => 'var(--tx-hi)']);
						// Position
						cf_group_row('Position', $cols);
						cf_row($cols, $rows, ['label' => 'Net cash flow', 'get' => fn($r) => $r['net'], 'weight' => 600, 'color' => fn($r, $v) => $v < 0 ? 'var(--crit)' : 'var(--good)']);
						// Held for the state, never ours to spend — taken out before ending cash
						// so that figure is money we can actually use.
						cf_row($cols, $rows, ['label' => 'Sales tax reserve', 'get' => fn($r) => $r['tax_collected'], 'dashzero' => true, 'color' => fn($r, $v) => 'var(--warn)']);
						cf_row($cols, $rows, ['label' => 'Ending cash', 'get' => fn($r) => $r['endCash'], 'weight' => 700, 'color' => fn($r, $v) => $r['cashRisk'] ? 'var(--crit)' : 'var(--tx-hi)']);
						// Debt and headroom are grouped by facility KIND rather than listed flat:
						// card room is purchasing power, LOC room can be drawn as cash, and the
						// two are never interchangeable. Balance then liquid, for each.
						cf_group_row('Credit cards', $cols);
						cf_row($cols, $rows, ['label' => 'Card balance', 'indent' => 16, 'get' => fn($r) => $r['endCard'], 'color' => fn($r, $v) => 'var(--tx-mid)']);
						cf_row($cols, $rows, ['label' => 'Card liquid', 'indent' => 16, 'get' => fn($r) => $r['availCard'], 'color' => fn($r, $v) => 'var(--tx-mid)']);
						// Unindented: this is interest across BOTH kinds, so it belongs to neither.
						cf_row($cols, $rows, ['label' => 'Interest accrued', 'get' => fn($r) => $r['interest'], 'color' => fn($r, $v) => 'var(--warn)']);
						cf_group_row('Line of credit', $cols);
						cf_row($cols, $rows, ['label' => 'LOC balance', 'indent' => 16, 'get' => fn($r) => $r['endLoc'], 'color' => fn($r, $v) => 'var(--tx-mid)']);
						cf_row($cols, $rows, ['label' => 'LOC liquid', 'indent' => 16, 'get' => fn($r) => $r['availLoc'], 'color' => fn($r, $v) => 'var(--tx-mid)']);
						cf_row($cols, $rows, ['label' => 'Ending liquid', 'get' => fn($r) => $r['liquid'], 'weight' => 700, 'rowbg' => true, 'color' => fn($r, $v) => 'var(--accent)']);
						?>
						</tbody>
					</table>
				</div>
				<div class="cf-legend">
					<span><span class="t-dot crit nohalo"></span>Ending cash below buffer</span>
					<span><span class="t-dot warn nohalo"></span>Credit headroom tight</span>
					<span>Click amounts to manage records</span>
				</div>
			</div>
		</div>
	</div>

	<!-- ============ VIEW: DEBT REDUCTION ============ -->
	<div class="cf-view" id="view-debt">
		<div class="t-panel" style="margin-bottom:16px"><div class="t-panel-body">
			<div style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap">
				<div class="t-field" style="max-width:260px">
					<span class="t-label">Monthly amount available for debt</span>
					<div class="t-input"><span style="color:var(--tx-lo)">$</span><input id="cfAvailDebt" value="<?php echo (int)$availDebt; ?>"></div>
				</div>
				<button class="t-btn" id="cfAfford"><?php echo titan_icon('gauge', 15); ?><span>What can I afford to pay?</span></button>
				<button class="t-btn" id="cfFocus"><?php echo titan_icon('spark', 15); ?><span>Where should I focus?</span></button>
			</div>
			<div id="cfAssist" hidden style="margin-top:14px;padding:14px 16px;border-radius:12px;background:var(--accent-soft);box-shadow:inset 0 0 0 1px var(--accent-line)">
				<div style="display:flex;justify-content:space-between;gap:12px">
					<div><div style="font-weight:700;color:var(--tx-hi);margin-bottom:3px" id="cfAssistTitle"></div><div style="font-size:12.5px;color:var(--tx-mid)" id="cfAssistText"></div>
					<div class="t-eyebrow" style="margin-top:8px">Suggestion · the forecast engine finalizes exact amounts</div></div>
					<button class="cf-x" id="cfAssistX">&times;</button>
				</div>
			</div>
		</div></div>

		<div class="t-panel"><div class="t-panel-body">
			<div class="t-panel-head"><h6 class="t-panel-title">Debts · avalanche order</h6>
				<div style="display:flex;gap:10px;align-items:center"><span class="t-eyebrow">Planned payments feed the cash-flow debt line</span><button class="t-btn sm" id="cfUseAll" hidden>Use all</button></div>
			</div>
			<div class="t-scroll" style="max-height:none">
			<table class="t-table"><thead><tr>
				<th>Debt</th><th style="text-align:right">Balance</th><th style="text-align:right">APR</th><th style="text-align:right">Min</th><th style="text-align:right">Planned / mo</th><th style="text-align:right">Suggested</th><th></th>
			</tr></thead><tbody>
			<?php foreach ($debts as $d) { ?>
				<tr data-key="<?php echo $d['key']; ?>" data-id="<?php echo $d['id']; ?>">
					<td><?php echo htmlspecialchars($d['label']); ?> <?php if ($d['focus']) echo '<span class="t-chip accent">Focus</span>'; ?></td>
					<td class="num" style="text-align:right"><?php echo cf_money($d['balance']); ?></td>
					<td class="num" style="text-align:right"><?php echo $d['apr'] !== null ? rtrim(rtrim(number_format($d['apr'], 2), '0'), '.') . '%' : cf_dash(); ?></td>
					<td class="num" style="text-align:right"><?php echo $d['min'] !== null ? cf_money($d['min']) : cf_dash(); ?></td>
					<td style="text-align:right">
						<?php if ($d['is_payout']) { ?><span style="color:var(--tx-lo);font-size:11px"><?php echo rtrim(rtrim(number_format((float)$d['payout_pct'], 1), '0'), '.'); ?>% of sales</span>
						<?php } else { ?><input class="cf-planned-inp" data-id="<?php echo $d['id']; ?>" value="<?php echo (int)round($d['planned']); ?>"><?php } ?>
					</td>
					<td class="num cf-suggest-cell" style="text-align:right;color:var(--accent)" data-key="<?php echo $d['key']; ?>"><?php echo $d['is_payout'] ? cf_dash() : ''; ?></td>
					<td style="text-align:right"><?php if (!$d['is_payout']) echo '<button class="t-btn sm cf-use" data-id="' . $d['id'] . '" data-key="' . $d['key'] . '" hidden>Use this</button>'; ?></td>
				</tr>
			<?php } ?>
			</tbody><tfoot><tr><td colspan="4"></td><td style="text-align:right;font-family:var(--font-mono);font-size:10px;color:var(--tx-lo)">TOTAL / MO</td><td class="num" style="text-align:right;color:var(--tx-hi)"><?php echo cf_money(cf_planned_total($live)); ?></td><td></td></tr></tfoot></table>
			</div>
		</div></div>
	</div>

	<!-- ============ VIEW: ACCOUNTS ============ -->
	<div class="cf-view" id="view-accounts">
		<?php
		$bankTot = 0.0; foreach ($live['banks'] as $b) $bankTot += $b['balance'];
		$cardBal = 0.0; $cardLim = 0.0; foreach ($live['cards'] as $c) { $cardBal += $c['balance']; $cardLim += ($c['limit'] ?? 0); } $cardAvail = $cardLim - $cardBal;
		// LOCs can share ONE facility (two loans drawing on the same line of credit). The ceiling
		// belongs to the facility and is counted ONCE — summing it per loan would double the room.
		// Group by facility for ceiling/available; drawn stays per loan. A facility with no ceiling
		// (a term loan, e.g. Shopify Capital) contributes 0 available.
		$locFacAgg = [];
		foreach ($live['locs'] as $l) {
			$fk = (($l['facility'] ?? '') !== '') ? strtolower($l['facility']) : ('#' . $l['id']);
			if (!isset($locFacAgg[$fk])) $locFacAgg[$fk] = ['name' => (($l['facility'] ?? '') !== '') ? $l['facility'] : $l['label'], 'ceiling' => (float)$l['ceiling'], 'drawn' => 0.0, 'count' => 0];
			$locFacAgg[$fk]['drawn']  += (float)$l['drawn'];
			$locFacAgg[$fk]['ceiling'] = max($locFacAgg[$fk]['ceiling'], (float)$l['ceiling']);
			$locFacAgg[$fk]['count']++;
		}
		$locDrawn = 0.0; $locCeil = 0.0; $locAvail = 0.0;
		foreach ($locFacAgg as $f) { $locDrawn += $f['drawn']; $locCeil += $f['ceiling']; $locAvail += max(0.0, $f['ceiling'] - $f['drawn']); }
		?>
		<div class="cf-acct-grid">
			<div class="t-panel"><div class="t-panel-body">
				<div class="t-panel-head"><h6 class="t-panel-title">Cash accounts<?php echo cf_asof_html($live['banks']); ?></h6><button class="t-btn sm cf-acct-add" data-group="banks">+ Add</button></div>
				<table class="t-table"><thead><tr><th>Account</th><th style="text-align:right">Balance</th><th>As of</th><th></th></tr></thead><tbody>
				<?php foreach ($live['banks'] as $b) { ?>
					<tr><td><?php echo htmlspecialchars($b['label']); ?><?php if (!empty($b['qb_id'])) echo ' <span class="t-chip accent" title="Balance auto-synced from QuickBooks nightly">Auto</span>'; ?></td><td class="num" style="text-align:right"><?php echo cf_money($b['balance']); ?></td><td class="id"><?php echo htmlspecialchars(strtoupper((string)$b['as_of'])); ?></td>
					<td style="text-align:right"><button class="t-btn sm icon cf-acct-edit" data-group="banks" data-id="<?php echo $b['id']; ?>"><?php echo titan_icon('pen', 13); ?></button></td></tr>
				<?php } ?>
				</tbody><tfoot><tr><td class="lbl">Total</td><td class="num" style="text-align:right"><?php echo cf_money($bankTot); ?></td><td></td><td></td></tr></tfoot></table>
			</div></div>

			<div class="t-panel"><div class="t-panel-body">
				<div class="t-panel-head"><h6 class="t-panel-title">Credit cards <span class="t-chip ghost" title="Unused card limit is purchasing power — it cannot be drawn as cash into the bank.">purchasing power</span><?php echo cf_asof_html($live['cards']); ?></h6><button class="t-btn sm cf-acct-add" data-group="cards">+ Add</button></div>
				<div style="overflow-x:auto"><table class="t-table" style="min-width:560px"><thead><tr><th>Card</th><th style="text-align:right">Balance</th><th style="text-align:right">Limit</th><th style="text-align:right">Avail</th><th style="text-align:right">APR</th><th style="text-align:right" title="Minimum payment as a % of the balance. Blank on a card means it inherits the global default.">Min %</th><th></th></tr></thead><tbody>
				<?php foreach ($live['cards'] as $c) { $avail = ($c['limit'] ?? 0) - $c['balance']; ?>
					<tr><td><?php echo htmlspecialchars($c['label']); ?><?php if (!empty($c['qb_id'])) echo ' <span class="t-chip accent" title="Balance auto-synced from QuickBooks nightly">Auto</span>'; ?></td><td class="num" style="text-align:right"><?php echo cf_money($c['balance']); ?></td>
					<td class="num" style="text-align:right"><?php echo $c['limit'] !== null ? cf_money($c['limit']) : cf_dash(); ?></td>
					<td class="num" style="text-align:right;color:var(--good)"><?php echo cf_money($avail); ?></td>
					<td class="num" style="text-align:right"><?php echo $c['apr'] !== null ? rtrim(rtrim(number_format((float)$c['apr'], 2), '0'), '.') . '%' : cf_dash(); ?></td>
					<?php // Own value shown plainly; an inherited one is dimmed so the two are distinguishable at a glance. ?>
					<td class="num" style="text-align:right<?php echo $c['min_pct_own'] === null ? ';color:var(--tx-lo)' : ''; ?>"
						title="<?php echo $c['min_pct_own'] === null ? 'Inherited from the global default' : 'Set on this card'; ?>"><?php
						echo rtrim(rtrim(number_format((float)$c['min_pct'], 2), '0'), '.') . '%'; ?></td>
					<td style="text-align:right"><button class="t-btn sm icon cf-acct-edit" data-group="cards" data-id="<?php echo $c['id']; ?>"><?php echo titan_icon('pen', 13); ?></button></td></tr>
				<?php } ?>
				</tbody><tfoot><tr><td class="lbl">Total</td><td class="num" style="text-align:right"><?php echo cf_money($cardBal); ?></td><td class="num" style="text-align:right"><?php echo cf_money($cardLim); ?></td><td class="num" style="text-align:right;color:var(--good)"><?php echo cf_money($cardAvail); ?></td><td></td><td></td><td></td></tr></tfoot></table></div>
			</div></div>

			<div class="t-panel"><div class="t-panel-body">
				<div class="t-panel-head"><h6 class="t-panel-title">Lines of credit &amp; loans <span class="t-chip ghost" title="Undrawn room on a line of credit can be drawn as cash into the bank. Term loans (no ceiling) contribute no room.">drawable as cash</span><?php echo cf_asof_html($live['locs']); ?></h6><button class="t-btn sm cf-acct-add" data-group="locs">+ Add</button></div>
				<div style="overflow-x:auto"><table class="t-table" style="min-width:600px"><thead><tr><th>Loan / line</th><th style="text-align:right">Drawn</th><th style="text-align:right">Ceiling</th><th style="text-align:right">Avail</th><th style="text-align:right">APR</th><th style="text-align:right">Repay</th><th></th></tr></thead><tbody>
				<?php foreach ($live['locs'] as $l) {
					$fk = (($l['facility'] ?? '') !== '') ? strtolower($l['facility']) : ('#' . $l['id']);
					$fac = $locFacAgg[$fk]; $shared = $fac['count'] > 1;
					$avail = max(0.0, (float)$fac['ceiling'] - (float)$fac['drawn']);   // facility-level room (not per loan)
				?>
					<tr><td><?php echo htmlspecialchars($l['label']); ?><?php if ($shared) echo ' <span class="t-chip ghost" title="Draws on the ' . htmlspecialchars($fac['name']) . ' line of credit (shared ceiling)">' . htmlspecialchars($fac['name']) . '</span>'; ?><?php if ($l['payout']) echo ' <span class="t-chip ghost">Payout</span>'; ?><?php if (!empty($l['qb_id'])) echo ' <span class="t-chip accent" title="Balance auto-synced from QuickBooks nightly">Auto</span>'; ?></td>
					<td class="num" style="text-align:right"><?php echo cf_money($l['drawn']); ?></td>
					<td class="num" style="text-align:right"><?php if ((float)$l['ceiling'] <= 0) { echo cf_dash(); } else { echo cf_money($l['ceiling']); if ($shared) echo ' <span class="t-chip ghost" title="One line of credit shared across ' . (int)$fac['count'] . ' loans — this ceiling is the whole line, not per loan">shared</span>'; } ?></td>
					<td class="num" style="text-align:right;color:var(--good)"><?php echo ((float)$l['ceiling'] <= 0) ? cf_dash() : cf_money($avail); ?></td>
					<td class="num" style="text-align:right"><?php echo (!$l['payout'] && $l['apr'] !== null) ? rtrim(rtrim(number_format((float)$l['apr'], 2), '0'), '.') . '%' : cf_dash(); ?></td>
					<td class="num" style="text-align:right"><?php echo $l['payout'] ? (rtrim(rtrim(number_format((float)$l['payout_pct'], 1), '0'), '.') . '% sales') : ($l['payment'] ? cf_money($l['payment']) . '/mo' : cf_dash()); ?></td>
					<td style="text-align:right"><button class="t-btn sm icon cf-acct-edit" data-group="locs" data-id="<?php echo $l['id']; ?>"><?php echo titan_icon('pen', 13); ?></button></td></tr>
				<?php } ?>
				</tbody><tfoot><tr><td class="lbl">Total</td><td class="num" style="text-align:right"><?php echo cf_money($locDrawn); ?></td><td class="num" style="text-align:right"><?php echo cf_money($locCeil); ?></td><td class="num" style="text-align:right;color:var(--good)"><?php echo cf_money($locAvail); ?></td><td></td><td></td><td></td></tr></tfoot></table></div>
			</div></div>
		</div>
	</div>

	<div id="cfModalRoot"></div>

<?php echo t_shell_close(); ?>

<script>
const CF = <?php echo json_encode([
	'hs' => $hs, 'cols' => $cols, 'records' => $records, 'accounts' => $live,
	'payOptions' => $payOptions, 'suggest' => $suggest, 'afford' => $afford, 'qbAccounts' => $qbAccounts,
	'settings' => ['buffer' => $buffer, 'tax' => $taxPct, 'shop' => $shopPct, 'growth' => $growth, 'availDebt' => $availDebt],
	'recOptions' => [['once', 'One-time'], ['monthly', 'Monthly'], ['quarterly', 'Quarterly'], ['annual', 'Annual']],
	'subOptions' => ['Online', 'Shows', 'Wholesale'],
], JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="/titan-bbw/titan-theme.js"></script>
<script src="/cash_flow.js?v=<?php echo @filemtime(__DIR__ . '/cash_flow.js'); ?>"></script>
</body>
</html>
