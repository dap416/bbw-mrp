<?php
/**
 * Conversational assistant for the Recommend panel. It holds the FULL dataset behind
 * the build recommendation (per-product components + current numbers), so it can both
 * (a) answer questions about WHY the numbers are what they are, and (b) change the
 * math by returning an updated filter state. The browser applies the filters and
 * recomputes deterministically — the AI never does the final arithmetic.
 *
 * Input: message, filters (JSON), shows (JSON), history (JSON [{role,content}]),
 *        data (JSON {meta, components, results}).
 * Output: { ok, filters:{mode, excluded_shows[], include_committed, include_large_po,
 *           buffer_pct}, reply }.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/anthropic.php");
require_login();
header('Content-Type: application/json');

if (!has_access('build') && !has_access('orders')) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'No access.']); exit; }
if (!anthropic_is_configured()) { echo json_encode(['ok'=>false,'error'=>'AI is not configured — add an Anthropic key on Integrations.']); exit; }

$message = trim((string)($_POST['message'] ?? ''));
$shows   = json_decode($_POST['shows'] ?? '[]', true); if (!is_array($shows)) $shows = [];
$shows   = array_values(array_filter(array_map(fn($x) => trim((string)$x), $shows), fn($x) => $x !== ''));
$cur     = json_decode($_POST['filters'] ?? '{}', true); if (!is_array($cur)) $cur = [];
$curFilters = [
	'mode' => (($cur['mode'] ?? 'all') === 'online_only') ? 'online_only' : 'all',
	'excluded_shows' => array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($cur['excluded_shows'] ?? [])), fn($x) => $x !== '')),
	'include_committed' => array_key_exists('include_committed', $cur) ? (bool)$cur['include_committed'] : true,
	'include_large_po' => array_key_exists('include_large_po', $cur) ? (bool)$cur['include_large_po'] : false,
	'buffer_pct' => max(0, min(300, (int)round((float)($cur['buffer_pct'] ?? 0)))),
];

$history = json_decode($_POST['history'] ?? '[]', true); if (!is_array($history)) $history = [];
$data    = json_decode($_POST['data'] ?? '{}', true);   if (!is_array($data)) $data = [];
$meta       = $data['meta'] ?? [];
$components = $data['components'] ?? [];
$results    = $data['results'] ?? [];
$threshold  = (int)($meta['large_po_threshold'] ?? 2000);

$system =
"You are the build-planning assistant for a small waterfowl motion-decoy manufacturer. You hold the FULL dataset behind a finished-product BUILD recommendation. You do two things: (1) answer the user's questions about WHY the numbers are what they are, citing the specific drivers; (2) change the numbers when asked, by returning an updated filter state.

How each product's numbers are computed from its components and the current filters:
- Demand = online + sales at each INCLUDED tradeshow + (large prior-year POs only if include_large_po) + (Shopify committed only if include_committed). If mode is 'online_only', Demand = online only. Then Demand is scaled by (1 + buffer_pct/100).
- Recommended Build = max(0, Demand − FP on-hand − in-pipeline builds).
- Buildable Now = how many can be built from raw-material stock right now (limited by limit_part).
Large one-off POs over \$$threshold from the SAME window last year are BYPASSED by default: they rarely repeat and any that do recur are already in committed, so counting last year's copy would double-build.

You can change these filters: mode ('all' or 'online_only'), excluded_shows (any subset of the show list), include_committed (true/false), include_large_po (true/false), buffer_pct (a safety-stock %, 0-300). If the user asks for a change you cannot express with these, say so briefly and still answer what you can — do not invent other fields.

DATASET (per-product components; 'shows' maps show name → units sold there last year):
" . json_encode(['window' => $meta, 'products' => $components], JSON_UNESCAPED_SLASHES) . "

ALWAYS output ONLY a JSON object, no code fence, exactly: {\"filters\":{\"mode\":\"all\"|\"online_only\",\"excluded_shows\":[],\"include_committed\":true,\"include_large_po\":false,\"buffer_pct\":0},\"reply\":\"...\"}. Start filters from the current ones and apply any requested change (leave them unchanged for a pure question). 'reply' is your conversational answer/confirmation in plain sentences — concise, specific, no markdown headings, cite real numbers when explaining.";

// Build a valid alternating message list from the prior history, then the new turn.
$msgs = [];
foreach ($history as $h) {
	$role = (($h['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
	$content = trim((string)($h['content'] ?? ''));
	if ($content === '') continue;
	if (empty($msgs) && $role !== 'user') $msgs[] = ['role' => 'user', 'content' => '(build recommendation loaded)'];
	if (!empty($msgs) && $msgs[count($msgs) - 1]['role'] === $role) { $msgs[count($msgs) - 1]['content'] .= "\n\n" . $content; continue; }
	$msgs[] = ['role' => $role, 'content' => $content];
}

$state = "Current filters: " . json_encode($curFilters, JSON_UNESCAPED_SLASHES) . "\nCurrent numbers (product: demand -> build):\n";
foreach ((array)$results as $rr) { $state .= "- " . (string)($rr['product'] ?? '') . ": " . (int)($rr['demand'] ?? 0) . " -> " . (int)($rr['build'] ?? 0) . "\n"; }
$turn = $state . "\n" . ($message !== ''
	? ("User: " . $message)
	: "Write a brief 2-4 sentence plain-language summary of why these build numbers are what they are — the main demand drivers, and what is being included or bypassed. No headings.");

if (!empty($msgs) && $msgs[count($msgs) - 1]['role'] === 'user') $msgs[count($msgs) - 1]['content'] .= "\n\n" . $turn;
else $msgs[] = ['role' => 'user', 'content' => $turn];

$res = anthropic_chat($system, $msgs, 900);
if (!empty($res['error'])) { echo json_encode(['ok'=>false,'error'=>$res['error']]); exit; }

$txt = trim((string)$res['text']);
$jsonTxt = $txt;
if (preg_match('/\{.*\}/s', $txt, $m)) $jsonTxt = $m[0];
$out = json_decode($jsonTxt, true);

// Graceful fallback: if the model answered in prose, show it and keep filters as-is.
if (!is_array($out)) {
	echo json_encode(['ok' => true, 'filters' => $curFilters, 'reply' => $txt]);
	exit;
}

$f = is_array($out['filters'] ?? null) ? $out['filters'] : [];
$mode = (($f['mode'] ?? $curFilters['mode']) === 'online_only') ? 'online_only' : 'all';
$showLc = []; foreach ($shows as $s) $showLc[strtolower($s)] = $s;
$excl = [];
foreach ((array)($f['excluded_shows'] ?? $curFilters['excluded_shows']) as $x) { $k = strtolower(trim((string)$x)); if (isset($showLc[$k])) $excl[] = $showLc[$k]; }
$excl = array_values(array_unique($excl));
$incCommitted = array_key_exists('include_committed', $f) ? (bool)$f['include_committed'] : $curFilters['include_committed'];
$incLargePo   = array_key_exists('include_large_po', $f) ? (bool)$f['include_large_po'] : $curFilters['include_large_po'];
$buffer       = array_key_exists('buffer_pct', $f) ? max(0, min(300, (int)round((float)$f['buffer_pct']))) : $curFilters['buffer_pct'];

echo json_encode([
	'ok' => true,
	'filters' => ['mode' => $mode, 'excluded_shows' => $excl, 'include_committed' => $incCommitted, 'include_large_po' => $incLargePo, 'buffer_pct' => $buffer],
	'reply' => (string)($out['reply'] ?? ''),
]);
