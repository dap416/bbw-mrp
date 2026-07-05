<?php
/**
 * AI chat for the Recommend panel. Translates a plain-language instruction into a
 * demand FILTER state and writes a short narrative. The browser applies the filters
 * and recomputes the numbers deterministically — the AI never does the arithmetic.
 *
 * Input: message, filters (JSON), shows (JSON array of show names).
 * Output: { ok, filters:{mode, excluded_shows[], include_committed}, narrative }.
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
];

$system =
"You maintain the demand filters for a build recommendation at a small waterfowl motion-decoy maker, and write a 1-2 sentence plain narrative of what the demand now includes. Output ONLY a JSON object (no code fence), exactly this shape: {\"filters\":{\"mode\":\"all\"|\"online_only\",\"excluded_shows\":[],\"include_committed\":true},\"narrative\":\"...\"}.
Start from current_filters and apply the user's instruction:
- 'only online' / 'online sales only' => mode=online_only.
- 'reset' / 'start over' / 'everything back' => mode=all, excluded_shows=[], include_committed=true.
- 'remove' / 'exclude' / 'not building for' / 'skip' a show => add the matching show name (from the shows list) to excluded_shows.
- 'add back' / 'include' a show => remove it from excluded_shows.
- 'ignore committed' / 'without committed' => include_committed=false; 'include committed' => include_committed=true.
Use ONLY names from the shows list for excluded_shows (match the user's wording to the closest one). If the instruction is empty, keep current_filters unchanged and just summarize. The narrative describes what is included now (online, which shows or 'all shows', and whether committed units count) in plain words — no advice, no numbers.";

$payload = ['shows' => $shows, 'current_filters' => $curFilters, 'instruction' => $message];
$res = anthropic_message($system, json_encode($payload, JSON_UNESCAPED_SLASHES), 500);
if (!empty($res['error'])) { echo json_encode(['ok'=>false,'error'=>$res['error']]); exit; }

$txt = trim((string)$res['text']);
if (preg_match('/\{.*\}/s', $txt, $m)) $txt = $m[0];
$out = json_decode($txt, true);
if (!is_array($out)) { echo json_encode(['ok'=>false,'error'=>'Could not understand that adjustment — try rephrasing (e.g. "remove Delta" or "only online").']); exit; }

$f    = is_array($out['filters'] ?? null) ? $out['filters'] : [];
$mode = (($f['mode'] ?? 'all') === 'online_only') ? 'online_only' : 'all';
$showLc = []; foreach ($shows as $s) $showLc[strtolower($s)] = $s;
$excl = [];
foreach ((array)($f['excluded_shows'] ?? []) as $x) { $k = strtolower(trim((string)$x)); if (isset($showLc[$k])) $excl[] = $showLc[$k]; }
$excl = array_values(array_unique($excl));
$incCommitted = array_key_exists('include_committed', $f) ? (bool)$f['include_committed'] : true;

echo json_encode([
	'ok' => true,
	'filters' => ['mode' => $mode, 'excluded_shows' => $excl, 'include_committed' => $incCommitted],
	'narrative' => (string)($out['narrative'] ?? ''),
]);
