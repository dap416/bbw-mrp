<?php
/**
 * Nightly cache refresh for the Cash Flow page (QuickBooks + Shopify).
 *
 * Run from cron via PHP CLI (recommended):
 *     php /path/to/cron/cashflow_sync.php
 *
 * Or via an authenticated URL (set the `cron_key` setting first):
 *     curl "https://mrp.bbwmanager.com/cron/cashflow_sync.php?key=YOURKEY"
 *
 * CLI runs are always allowed; web runs require ?key= to match the `cron_key`
 * setting. No login/session is used, so it works headless from cron.
 */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/cashflow.php");

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
	$db0 = db_connect();
	$key = $db0 ? (string)setting_get($db0, 'cron_key', '') : '';
	$given = (string)($_GET['key'] ?? '');
	if ($key === '' || !hash_equals($key, $given)) {
		http_response_code(403);
		echo "Forbidden — set a 'cron_key' setting and pass ?key=...";
		exit;
	}
	header('Content-Type: text/plain');
}

$db = db_connect();
if (!$db) { echo "DB connection failed\n"; exit(1); }

$start = microtime(true);
$log   = cashflow_sync($db);
$secs  = round(microtime(true) - $start, 1);

echo "[" . date('Y-m-d H:i:s') . "] Cash Flow sync ({$secs}s)\n";
foreach ($log as $line) echo "  " . $line . "\n";
