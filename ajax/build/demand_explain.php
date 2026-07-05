<?php
/**
 * Explain where a product's projected "Demand" number comes from, in plain terms.
 * Breaks demand into: last year's same-window sales (online vs in-person/tradeshow
 * POS) + current OPEN wholesale draft orders (>= 10 mixed units) that include this
 * SKU. A short AI narrative is generated (cached ~1h); falls back to a computed
 * summary if the AI isn't configured.
 *
 * Input: prodid, until (YYYY-MM-DD), warehouse_id.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");
require_once(__DIR__."/../../includes/planning.php");
require_once(__DIR__."/../../includes/anthropic.php");
require_login();
header('Content-Type: application/json');

if (!has_access('build') && !has_access('orders')) { http_response_code(403); echo json_encode(['error' => 'No access.']); exit; }

$db     = db_connect();
$prodid = (int)($_POST['prodid'] ?? 0);
$until  = trim($_POST['until'] ?? '');
$whId   = (int)($_POST['warehouse_id'] ?? 0);
$ts     = strtotime($until);
if ($prodid <= 0)                 { echo json_encode(['error' => 'Missing product.']); exit; }
if (!$ts || date('Y-m-d',$ts) <= date('Y-m-d')) { echo json_encode(['error' => 'Pick a future target date.']); exit; }
$until = date('Y-m-d', $ts);

// Cache the whole explanation per product+window+warehouse.
$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
$ckey = 'demexpl_' . $prodid . '_' . $until . '_' . $whId;
if (empty($_POST['refresh'])) {
	try {
		$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$ckey]);
		if ($row = $s->fetch()) { if ((time() - strtotime($row['updated_at'])) < 3600) { echo json_encode(['ok'=>true,'html'=>$row['cval'],'cached'=>true]); exit; } }
	} catch (Throwable $e) {}
}

$plan = fp_build_plan($db, $until, $whId);
if (!empty($plan['error'])) { echo json_encode(['error' => 'Shopify lookup failed: ' . $plan['error']]); exit; }

$rowP = null;
foreach ($plan['rows'] as $r) { if ((int)$r['prodid'] === $prodid) { $rowP = $r; break; } }
if (!$rowP) { echo json_encode(['ok'=>true,'html'=>'<div class="text-muted small">No projected demand for this product in the chosen window.</div>']); exit; }

$sku    = (string)($rowP['sku'] ?? '');
$retail = (int)$rowP['retail'];
$draft  = (int)$rowP['draft'];
$demand = (int)$rowP['demand'];
$window = $plan['meta']['prior_window'] ?? '';
$since = ''; $lyEnd = '';
if (preg_match('/(\d{4}-\d{2}-\d{2})\s+to\s+(\d{4}-\d{2}-\d{2})/', $window, $mm)) { $since = $mm[1]; $lyEnd = $mm[2]; }

// Tradeshow / POS portion of last year's sales for this SKU.
$tradeshowUnits = 0; $shows = [];
if ($sku !== '' && $since !== '' && shopify_is_configured()) {
	$ttl = inventory_cache_ttl($db);
	try {
		foreach (tradeshow_locations() as $loc) {
			$ids = isset($loc['ids']) ? $loc['ids'] : (isset($loc['id']) ? [$loc['id']] : []);
			$u = 0;
			foreach ($ids as $lid) {
				$r = shopify_cache_remember($db, "showsales_{$lid}_{$since}_{$lyEnd}", $ttl, fn() => shopify_show_sales($lid, $since, $lyEnd))['data'];
				if (!empty($r['by_sku'][$sku])) $u += (int)$r['by_sku'][$sku];
			}
			if ($u > 0) { $shows[] = ['show' => $loc['name'], 'units' => $u]; $tradeshowUnits += $u; }
		}
	} catch (Throwable $e) {}
}
$onlineEst = max(0, $retail - $tradeshowUnits);

// Open wholesale orders that include this SKU (the "reportable PO" contributors).
$woLines = [];
if ($sku !== '' && shopify_is_configured()) {
	try {
		$wo = shopify_cache_remember($db, 'rai_wholesale_orders', 300, fn() => shopify_open_wholesale_orders(60))['data'];
		foreach (($wo['orders'] ?? []) as $o) {
			foreach (($o['lines'] ?? []) as $ln) {
				if (strcasecmp(trim((string)$ln['sku']), $sku) === 0 && (int)$ln['qty'] > 0) {
					$woLines[] = ['order' => $o['name'], 'customer' => $o['customer'], 'qty' => (int)$ln['qty']];
				}
			}
		}
	} catch (Throwable $e) {}
}

$ctx = [
	'product' => $rowP['product'], 'sku' => $sku, 'fulfill_until' => $until,
	'total_demand' => $demand,
	'last_year_window' => $window,
	'last_year_sales_total' => $retail,
	'last_year_tradeshow_pos' => $tradeshowUnits,
	'last_year_tradeshow_by_show' => $shows,
	'last_year_online_and_other_est' => $onlineEst,
	'open_wholesale_units' => $draft,
	'open_wholesale_orders' => $woLines,
];

$html = '';
if (anthropic_is_configured()) {
	$system = "You explain, in 2-3 short plain sentences, where a finished product's projected demand number comes from for a small waterfowl motion-decoy manufacturer. Use ONLY the numbers given. total_demand = last_year_sales_total + open_wholesale_units. Point out the biggest driver(s): last year's same-window sales (and, if last_year_tradeshow_pos is meaningful, note how much of that was in-person/tradeshow POS vs online) and any current open wholesale orders (name the customer + units briefly). Keep it basic — no advice, no fluff, no headings. If a piece is zero, don't mention it.";
	$res = anthropic_message($system, "Explain the demand from this data (JSON):\n" . json_encode($ctx, JSON_UNESCAPED_SLASHES), 400);
	if (empty($res['error'])) $html = '<div style="font-size:0.9rem;">' . nl2br(htmlspecialchars(trim($res['text']))) . '</div>';
}

// Deterministic breakdown table always shown under the narrative (the "receipts").
$rowsHtml = '';
$rowsHtml .= '<tr><td>Last year, same window ('.htmlspecialchars($window).')</td><td class="text-end fw-semibold">'.number_format($retail).'</td></tr>';
if ($tradeshowUnits > 0) {
	$shnames = array_map(fn($s) => htmlspecialchars($s['show']).' ('.number_format($s['units']).')', $shows);
	$rowsHtml .= '<tr><td class="ps-3 text-muted small">• of that, in-person/tradeshow POS: '.htmlspecialchars(implode(', ', array_map(fn($s)=>$s['show'], $shows))).'</td><td class="text-end text-muted small">'.number_format($tradeshowUnits).'</td></tr>';
	$rowsHtml .= '<tr><td class="ps-3 text-muted small">• online &amp; other (est.)</td><td class="text-end text-muted small">'.number_format($onlineEst).'</td></tr>';
}
if ($draft > 0) {
	$rowsHtml .= '<tr><td>Open wholesale orders (unfulfilled POs, ≥10 mixed units)</td><td class="text-end fw-semibold">'.number_format($draft).'</td></tr>';
	foreach ($woLines as $w) {
		$rowsHtml .= '<tr><td class="ps-3 text-muted small">• '.htmlspecialchars(($w['order'] ?: '').($w['customer'] ? ' — '.$w['customer'] : '')).'</td><td class="text-end text-muted small">'.number_format($w['qty']).'</td></tr>';
	}
}
$rowsHtml .= '<tr style="border-top:2px solid #dee2e6;"><td class="fw-bold">Total demand</td><td class="text-end fw-bold">'.number_format($demand).'</td></tr>';

$html .= '<table class="table table-sm mt-2 mb-0" style="font-size:0.85rem;"><tbody>'.$rowsHtml.'</tbody></table>';
if (!shopify_is_configured()) $html .= '<div class="text-muted small mt-1">Connect Shopify for the tradeshow/wholesale breakdown.</div>';

try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$ckey, $html]); } catch (Throwable $e) {}

echo json_encode(['ok' => true, 'html' => $html, 'cached' => false]);
