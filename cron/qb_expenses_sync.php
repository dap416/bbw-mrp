<?php
/**
 * Nightly QuickBooks expense-transaction import.
 *
 * Pulls the QBO `Purchase` entity (the UI's "Expense") into qb_expenses +
 * qb_expense_lines. Expenses only — no UI, no reports, nothing else from
 * QuickBooks, and nothing shared with the Cash Flow / Cash Management caches.
 *
 * Run from cron via PHP CLI (recommended):
 *     php /path/to/cron/qb_expenses_sync.php
 *
 * Or via an authenticated URL (set the `cron_key` setting first):
 *     curl "https://mrp.bbwmanager.com/cron/qb_expenses_sync.php?key=YOURKEY"
 *
 * CLI runs are always allowed; web runs require ?key= to match the `cron_key`
 * setting. No login/session is used, so it works headless from cron.
 *
 * Kept on its own schedule (and its own log) so a failure here cannot break the
 * 2:30 cashflow_sync run:
 *     45 2 * * * php /var/www/html/cron/qb_expenses_sync.php >> /var/log/qb_expenses_sync.log 2>&1
 */
require_once(__DIR__."/../includes/fns.php");
require_once(__DIR__."/../includes/qb_expenses.php");

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

$r = qb_expenses_sync($db);

echo "[" . date('Y-m-d H:i:s') . "] QuickBooks expense sync ({$r['secs']}s)\n";
echo "  window: {$r['window']}\n";
echo "  fetched {$r['fetched']}; inserted {$r['inserted']}; updated {$r['updated']}; "
   . "soft-deleted {$r['deleted']}; lines {$r['lines']}\n";
if ($r['error']) {
	// The refresh token dies after ~100 days without a successful call, and that
	// cannot self-heal — an admin must reconnect at /quickbooks/connect.php.
	echo "  ⚠ ERROR: {$r['error']}\n";
	exit(1);
}
echo "  ✓ ok\n";
