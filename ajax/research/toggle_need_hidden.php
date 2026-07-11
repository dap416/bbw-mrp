<?php
/** Hide / unhide a Need-to-Order line (key = type::name). Persists in a setting. Research access. */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/shopify.php");   // setting_get() / setting_set()
require_once(__DIR__."/../../includes/planning.php");  // need_hidden_items()
require_login();
if (!has_access('research')) { http_response_code(403); echo json_encode(['error' => 'No access.']); exit; }
header('Content-Type: application/json');

$key    = trim($_POST['key'] ?? '');
$hidden = !empty($_POST['hidden']);
if ($key === '') { echo json_encode(['error' => 'Missing key.']); exit; }

$db = db_connect();
try {
	$list = need_hidden_items($db);
	if ($hidden) { if (!in_array($key, $list, true)) $list[] = $key; }
	else         { $list = array_values(array_filter($list, fn($k) => $k !== $key)); }
	setting_set($db, 'need_hidden_items', json_encode(array_values($list)));
	echo json_encode(['ok' => true, 'hidden' => $list]);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => 'Save failed: ' . $e->getMessage()]);
}
