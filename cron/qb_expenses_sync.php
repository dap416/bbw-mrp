<?php
/**
 * Nightly transaction import — QuickBooks expenses, Shopify orders, QuickBooks
 * cash received. One job, one log, run in that order.
 *
 *   1. QBO `Purchase`  -> qb_expenses + qb_expense_lines       (spend)
 *   2. Shopify orders  -> shop_orders + shop_order_lines       (sales, master)
 *   3. QBO Deposit /
 *      SalesReceipt /
 *      RefundReceipt   -> qb_cash_in + qb_cash_in_lines        (cash landed)
 *
 * Import only — no UI, no reports, nothing shared with the Cash Flow / Cash
 * Management caches. Step 1 runs first because it refreshes the chart-of-accounts
 * mirror (qb_accounts_ref) that step 3 classifies against.
 *
 * Each source is independent: one failing does not stop the others, and the exit
 * code is non-zero if any of them failed.
 *
 * The filename is unchanged despite the wider scope, so an installed crontab
 * entry and its log keep working.
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
require_once(__DIR__."/../includes/shop_orders.php");
require_once(__DIR__."/../includes/qb_cash_in.php");

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

$started = date('Y-m-d H:i:s');
$failed  = 0;

/* ---- 1. QuickBooks expenses (also refreshes the chart of accounts) ------- */
$r = qb_expenses_sync($db);
echo "[$started] QuickBooks expense sync ({$r['secs']}s)\n";
echo "  window: {$r['window']}\n";
echo "  accounts {$r['accounts']}; balances captured {$r['balances']}\n";
echo "  fetched {$r['fetched']}; inserted {$r['inserted']}; updated {$r['updated']}; "
   . "soft-deleted {$r['deleted']}; lines {$r['lines']}\n";
if ($r['error']) {
	// The refresh token dies after ~100 days without a successful call, and that
	// cannot self-heal — an admin must reconnect at /quickbooks/connect.php.
	echo "  ⚠ ERROR: {$r['error']}\n";
	$failed++;
} else {
	echo "  ✓ ok\n";
}

/* ---- 2. Shopify orders (the master record for sales) -------------------- */
if (!shopify_is_configured()) {
	// Not an error: a store that isn't connected yet shouldn't fail the run.
	echo "  — Shopify orders skipped (not configured)\n";
} else {
	$s = shop_orders_sync($db);
	echo "[" . date('Y-m-d H:i:s') . "] Shopify order sync ({$s['secs']}s)\n";
	echo "  window: {$s['window']}\n";
	echo "  fetched {$s['fetched']}; inserted {$s['inserted']}; updated {$s['updated']}; "
	   . "soft-deleted {$s['deleted']}; lines {$s['lines']}\n";
	if ($s['line_truncated']) {
		// Say it out loud rather than letting a capped order read as complete.
		echo "  ⚠ {$s['line_truncated']} order(s) had more than 100 line items; extra lines not stored\n";
	}
	if ($s['error']) { echo "  ⚠ ERROR: {$s['error']}\n"; $failed++; }
	else             { echo "  ✓ ok\n"; }
}

/* ---- 3. QuickBooks cash received (reconciliation only) ------------------ */
$c = qb_cash_in_sync($db);
echo "[" . date('Y-m-d H:i:s') . "] QuickBooks cash-in sync ({$c['secs']}s)\n";
echo "  window: {$c['window']}\n";
echo "  fetched {$c['fetched']}; inserted {$c['inserted']}; updated {$c['updated']}; "
   . "soft-deleted {$c['deleted']}; lines {$c['lines']}\n";
if ($c['error']) { echo "  ⚠ ERROR: {$c['error']}\n"; $failed++; }
else             { echo "  ✓ ok\n"; }

if ($failed) exit(1);
