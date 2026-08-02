<?php
/* ============================================================
   QUICKBOOKS EXPENSE TRANSACTIONS — nightly import.

   Pulls the QBO `Purchase` entity (what the QuickBooks UI calls
   an "Expense": Cash / Check / CreditCard payments) into our own
   normalized tables, qb_expenses + qb_expense_lines.

   Design, in one line: every night re-pull all expenses dated in
   the last 90 days, upsert them, and soft-delete anything in that
   window we didn't see. No cursor, no change-tracking, no delete
   endpoint — the missing rows ARE the delete signal, and a failed
   run self-heals because the next run redoes the same window.

   This writes real rows, following the cf_capture_balances()
   precedent in includes/cash_flow.php. It deliberately does NOT
   touch the data_cache / cf_cache_*() JSON-blob layer in
   includes/cashflow.php, and modifies neither module.

   Tokens are handled inside qb_query(); there is no OAuth code here.
   ============================================================ */

require_once __DIR__ . '/fns.php';
require_once __DIR__ . '/quickbooks.php';

/** How far back each nightly run re-pulls. */
const QB_EXPENSES_WINDOW_DAYS = 90;

/** QBO caps a query at 1000 rows. */
const QB_EXPENSES_PAGE = 1000;

/* ---- schema ------------------------------------------------------------ */

function ensure_qb_expenses_tables($db) {
	// Expense headers. One row per QBO Purchase. Soft-deleted, never purged —
	// downstream reads filter `deleted_at IS NULL`.
	$db->exec("CREATE TABLE IF NOT EXISTS qb_expenses (
		id            BIGINT AUTO_INCREMENT PRIMARY KEY,
		realm_id      VARCHAR(32)  NOT NULL,
		qb_id         VARCHAR(32)  NOT NULL,             -- QBO Purchase Id
		sync_token    VARCHAR(16)  NULL,
		txn_date      DATE         NULL,
		doc_number    VARCHAR(64)  NULL,
		payment_type  VARCHAR(20)  NULL,                 -- Cash | Check | CreditCard
		is_credit     TINYINT      NOT NULL DEFAULT 0,   -- Purchase.Credit = true -> refund, treat as negative
		account_id    VARCHAR(32)  NULL,                 -- account paid FROM
		account_name  VARCHAR(160) NULL,
		entity_id     VARCHAR(32)  NULL,                 -- vendor / customer / employee
		entity_type   VARCHAR(20)  NULL,
		entity_name   VARCHAR(200) NULL,
		total_amt     DECIMAL(14,2) NOT NULL DEFAULT 0,
		currency      VARCHAR(8)   NULL,
		private_note  TEXT         NULL,
		qb_created_at DATETIME     NULL,                 -- MetaData.CreateTime
		qb_updated_at DATETIME     NULL,                 -- MetaData.LastUpdatedTime
		deleted_at    DATETIME     NULL,                 -- soft delete
		raw_json      LONGTEXT     NULL,                 -- full object: a missed field is a re-parse, not a re-sync
		synced_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY uq_qb (realm_id, qb_id),
		KEY idx_txn_date (txn_date),
		KEY idx_entity (entity_name)
	) ENGINE=InnoDB");

	// Line items. The expense CATEGORY lives here (line AccountRef), not on the
	// header — the header's AccountRef is the account the money came FROM.
	$db->exec("CREATE TABLE IF NOT EXISTS qb_expense_lines (
		id           BIGINT AUTO_INCREMENT PRIMARY KEY,
		expense_id   BIGINT NOT NULL,
		line_num     INT NULL,
		detail_type  VARCHAR(40) NULL,   -- AccountBasedExpenseLineDetail | ItemBasedExpenseLineDetail
		amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
		description  TEXT NULL,
		account_id   VARCHAR(32) NULL,   -- the expense CATEGORY
		account_name VARCHAR(160) NULL,
		account_type VARCHAR(60) NULL,   -- QBO AccountType of the category account
		account_subtype VARCHAR(60) NULL,
		is_expense   TINYINT NOT NULL DEFAULT 0,  -- 1 = real P&L spend; see qb_line_is_expense()
		item_id      VARCHAR(32) NULL,
		item_name    VARCHAR(160) NULL,
		CONSTRAINT fk_qbexp FOREIGN KEY (expense_id) REFERENCES qb_expenses(id) ON DELETE CASCADE,
		KEY idx_expense (expense_id),
		KEY idx_account (account_name),
		KEY idx_is_expense (is_expense)
	) ENGINE=InnoDB");

	// Added after the first release — bring existing tables up to date.
	foreach ([
		"ALTER TABLE qb_expense_lines ADD COLUMN account_type VARCHAR(60) NULL AFTER account_name",
		"ALTER TABLE qb_expense_lines ADD COLUMN account_subtype VARCHAR(60) NULL AFTER account_type",
		"ALTER TABLE qb_expense_lines ADD COLUMN is_expense TINYINT NOT NULL DEFAULT 0 AFTER account_subtype",
		"ALTER TABLE qb_expense_lines ADD KEY idx_is_expense (is_expense)",
	] as $sql) {
		try { $db->exec($sql); } catch (Throwable $e) { /* already there */ }
	}

	ensure_qb_accounts_ref_table($db);
}

/* ---- chart of accounts (local mirror) ---------------------------------- */

/**
 * Local copy of the QBO chart of accounts. We need each account's TYPE to tell
 * real spend apart from money movement, and the line rows only carry the name.
 *
 * Deliberately our own table rather than the `data_cache` 'qb_accounts' blob:
 * that one is written by a query with no MAXRESULTS, so QBO's default page size
 * silently truncates it at 100 of the 192 accounts.
 */
function ensure_qb_accounts_ref_table($db) {
	$db->exec("CREATE TABLE IF NOT EXISTS qb_accounts_ref (
		realm_id     VARCHAR(32)  NOT NULL,
		qb_id        VARCHAR(32)  NOT NULL,
		name         VARCHAR(200) NULL,
		fq_name      VARCHAR(300) NULL,   -- FullyQualifiedName, e.g. 'Interest paid:Loan interest'
		acct_type    VARCHAR(60)  NULL,   -- Expense | Credit Card | Other Current Liability | ...
		acct_subtype VARCHAR(60)  NULL,
		active       TINYINT NOT NULL DEFAULT 1,
		synced_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (realm_id, qb_id),
		KEY idx_type (acct_type)
	) ENGINE=InnoDB");
}

/**
 * Nightly balance history, one row per account per run. APPEND-ONLY: no unique
 * key, no upsert, no soft delete, no one-row-per-day constraint. The newest
 * captured_at for a qb_id is the current balance; everything older is the trend.
 * Running the job twice in a day is expected and harmless.
 */
function ensure_qb_account_balances_table($db) {
	$db->exec("CREATE TABLE IF NOT EXISTS qb_account_balances (
		id          BIGINT AUTO_INCREMENT PRIMARY KEY,
		realm_id    VARCHAR(32)  NOT NULL,
		qb_id       VARCHAR(32)  NOT NULL,
		fq_name     VARCHAR(300) NULL,
		acct_type   VARCHAR(60)  NULL,
		balance     DECIMAL(14,2) NOT NULL DEFAULT 0,
		captured_at DATETIME     NOT NULL,
		KEY idx_latest (realm_id, qb_id, captured_at),
		KEY idx_captured (captured_at)
	) ENGINE=InnoDB");
}

/**
 * Snapshot every account's balance from an Account payload we already have.
 *
 * No extra API call: qb_refresh_accounts_ref() pulls the whole chart of accounts
 * nightly and CurrentBalance rides along in that same response — it was simply
 * being discarded.
 *
 * Every account, no acct_type filter — which types matter is a query concern,
 * and a filter here would silently decide it for every future reader. The value
 * is stored exactly as QuickBooks reports it: no sign normalization, no abs().
 * The "amount owed" convention cash_balances uses (includes/cash_flow.php) is a
 * presentation choice for that table and does not apply to raw capture.
 */
function qb_capture_account_balances($db, $realmId, array $accounts) {
	ensure_qb_account_balances_table($db);
	$ins = $db->prepare("INSERT INTO qb_account_balances
			(realm_id, qb_id, fq_name, acct_type, balance, captured_at)
		VALUES (?,?,?,?,?,NOW())");
	$n = 0;
	foreach ($accounts as $a) {
		$id = (string)($a['Id'] ?? '');
		if ($id === '') continue;
		$ins->execute([$realmId, $id, $a['FullyQualifiedName'] ?? null,
		               $a['AccountType'] ?? null, round((float)($a['CurrentBalance'] ?? 0), 2)]);
		$n++;
	}
	return $n;
}

/**
 * The only account types that are real profit-and-loss spend.
 *
 * Everything else a Purchase can point at is money movement, not cost:
 *   Credit Card / Other Current Liability / Long Term Liability -> paying down a
 *     balance. The original charges are already in this table as their own rows,
 *     so counting these too would double-count the same spend.
 *   Bank -> a transfer between our own accounts.
 *   Accounts Payable -> settling a bill that was already recorded.
 *   Equity -> an owner draw. Fixed/Other Asset -> buying an asset.
 *
 * Loan and card INTEREST is unaffected: QuickBooks books it to its own
 * 'Interest paid:*' account (type Expense) on a separate line of the same
 * payment, so it is flagged is_expense = 1 while the principal line beside it
 * is flagged 0. That is why this flag lives on the line and not the header.
 */
function qb_expense_account_types() {
	return ['Expense', 'Cost of Goods Sold', 'Other Expense'];
}

function qb_line_is_expense($acctType) {
	return in_array((string)$acctType, qb_expense_account_types(), true) ? 1 : 0;
}

/**
 * Re-pull the full chart of accounts (paged — the list exceeds one page).
 *
 * Runs TWICE: a bare Account query returns only active accounts, but historical
 * lines still reference deactivated ones ('Inventory (deleted)', 'Shopify Loan
 * (deleted)', …). Those are all liability/asset accounts, so missing them would
 * leave real non-expenses unclassified and counted as spend.
 */
function qb_refresh_accounts_ref($db, $realmId) {
	ensure_qb_accounts_ref_table($db);
	$all = [];
	foreach (['', "WHERE Active = false"] as $filter) {
		$pos = 1;
		do {
			$r = qb_query(trim("SELECT * FROM Account $filter") . " STARTPOSITION $pos MAXRESULTS " . QB_EXPENSES_PAGE);
			if (!empty($r['error'])) return ['error' => $r['error'], 'count' => 0];
			$batch = $r['Account'] ?? [];
			$all   = array_merge($all, $batch);
			$pos  += QB_EXPENSES_PAGE;
		} while (count($batch) === QB_EXPENSES_PAGE);
	}

	$ins = $db->prepare("INSERT INTO qb_accounts_ref
			(realm_id, qb_id, name, fq_name, acct_type, acct_subtype, active, synced_at)
		VALUES (?,?,?,?,?,?,?,NOW())
		ON DUPLICATE KEY UPDATE name=VALUES(name), fq_name=VALUES(fq_name), acct_type=VALUES(acct_type),
			acct_subtype=VALUES(acct_subtype), active=VALUES(active), synced_at=NOW()");
	foreach ($all as $a) {
		$id = (string)($a['Id'] ?? '');
		if ($id === '') continue;
		$ins->execute([$realmId, $id, $a['Name'] ?? null, $a['FullyQualifiedName'] ?? null,
		               $a['AccountType'] ?? null, $a['AccountSubType'] ?? null, !empty($a['Active']) ? 1 : 0]);
	}

	// Same payload, second use: snapshot tonight's balances before $all is dropped.
	$balances = qb_capture_account_balances($db, $realmId, $all);

	return ['error' => null, 'count' => count($all), 'balances' => $balances];
}

/** account id => ['type'=>..., 'subtype'=>...] for the connected realm. */
function qb_accounts_ref_map($db, $realmId) {
	ensure_qb_accounts_ref_table($db);
	$m = [];
	$s = $db->prepare("SELECT qb_id, acct_type, acct_subtype FROM qb_accounts_ref WHERE realm_id = ?");
	$s->execute([$realmId]);
	foreach ($s as $r) $m[$r['qb_id']] = ['type' => $r['acct_type'], 'subtype' => $r['acct_subtype']];
	return $m;
}

/* ---- helpers ----------------------------------------------------------- */

/** The connected company id — stamped on every row so a re-auth to a different realm can't blend books. */
function qb_expenses_realm_id() {
	$s = qb_settings();
	return (string)($s['qb_realm_id'] ?? '');
}

/** QBO timestamps are ISO8601 with an offset; store them as plain DATETIME. */
function qb_expense_dt($v) {
	$v = trim((string)$v);
	if ($v === '') return null;
	$ts = strtotime($v);
	return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** 'YYYY-MM-DD' or null. */
function qb_expense_date($v) {
	$v = trim((string)$v);
	if ($v === '') return null;
	$ts = strtotime($v);
	return $ts ? date('Y-m-d', $ts) : null;
}

/* ---- upsert ------------------------------------------------------------ */

/**
 * Write one QBO Purchase (header + lines) in a single transaction.
 * Lines are deleted and re-inserted rather than matched, because QBO renumbers
 * them on edit. Returns 'insert' or 'update'.
 */
function qb_expense_upsert($db, array $row, $realmId, array $acctMap = []) {
	$qbId = (string)($row['Id'] ?? '');
	if ($qbId === '') return null;

	$sel = $db->prepare("SELECT id FROM qb_expenses WHERE realm_id = ? AND qb_id = ?");
	$sel->execute([$realmId, $qbId]);
	$existingId = $sel->fetchColumn();

	$vals = [
		'sync_token'    => (string)($row['SyncToken'] ?? ''),
		'txn_date'      => qb_expense_date($row['TxnDate'] ?? ''),
		'doc_number'    => ($row['DocNumber'] ?? '') !== '' ? (string)$row['DocNumber'] : null,
		'payment_type'  => ($row['PaymentType'] ?? '') !== '' ? (string)$row['PaymentType'] : null,
		'is_credit'     => !empty($row['Credit']) ? 1 : 0,
		'account_id'    => $row['AccountRef']['value'] ?? null,
		'account_name'  => $row['AccountRef']['name']  ?? null,
		'entity_id'     => $row['EntityRef']['value']  ?? null,
		'entity_type'   => $row['EntityRef']['type']   ?? null,
		'entity_name'   => $row['EntityRef']['name']   ?? null,
		'total_amt'     => round((float)($row['TotalAmt'] ?? 0), 2),
		'currency'      => $row['CurrencyRef']['value'] ?? null,
		'private_note'  => ($row['PrivateNote'] ?? '') !== '' ? (string)$row['PrivateNote'] : null,
		'qb_created_at' => qb_expense_dt($row['MetaData']['CreateTime'] ?? ''),
		'qb_updated_at' => qb_expense_dt($row['MetaData']['LastUpdatedTime'] ?? ''),
		'raw_json'      => json_encode($row),
	];

	$db->beginTransaction();
	try {
		if ($existingId) {
			$expenseId = (int)$existingId;
			$db->prepare("UPDATE qb_expenses SET
					sync_token=?, txn_date=?, doc_number=?, payment_type=?, is_credit=?,
					account_id=?, account_name=?, entity_id=?, entity_type=?, entity_name=?,
					total_amt=?, currency=?, private_note=?, qb_created_at=?, qb_updated_at=?,
					raw_json=?, deleted_at=NULL, synced_at=NOW()
				WHERE id = ?")
			   ->execute(array_merge(array_values($vals), [$expenseId]));
			$action = 'update';
		} else {
			$db->prepare("INSERT INTO qb_expenses
					(realm_id, qb_id, sync_token, txn_date, doc_number, payment_type, is_credit,
					 account_id, account_name, entity_id, entity_type, entity_name,
					 total_amt, currency, private_note, qb_created_at, qb_updated_at,
					 raw_json, deleted_at, synced_at)
				VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NOW())
				ON DUPLICATE KEY UPDATE
					sync_token=VALUES(sync_token), txn_date=VALUES(txn_date), doc_number=VALUES(doc_number),
					payment_type=VALUES(payment_type), is_credit=VALUES(is_credit),
					account_id=VALUES(account_id), account_name=VALUES(account_name),
					entity_id=VALUES(entity_id), entity_type=VALUES(entity_type), entity_name=VALUES(entity_name),
					total_amt=VALUES(total_amt), currency=VALUES(currency), private_note=VALUES(private_note),
					qb_created_at=VALUES(qb_created_at), qb_updated_at=VALUES(qb_updated_at),
					raw_json=VALUES(raw_json), deleted_at=NULL, synced_at=NOW()")
			   ->execute(array_merge([$realmId, $qbId], array_values($vals)));
			$expenseId = (int)$db->lastInsertId();
			if (!$expenseId) {   // ON DUPLICATE fired (concurrent run) — find the row we just wrote
				$sel->execute([$realmId, $qbId]);
				$expenseId = (int)$sel->fetchColumn();
			}
			$action = 'insert';
		}

		$db->prepare("DELETE FROM qb_expense_lines WHERE expense_id = ?")->execute([$expenseId]);
		$insL = $db->prepare("INSERT INTO qb_expense_lines
			(expense_id, line_num, detail_type, amount, description, account_id, account_name,
			 account_type, account_subtype, is_expense, item_id, item_name)
			VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

		foreach (($row['Line'] ?? []) as $i => $line) {
			$type = (string)($line['DetailType'] ?? '');
			$acct = $line[$type]['AccountRef'] ?? null;          // AccountBasedExpenseLineDetail -> the CATEGORY
			$item = $line[$type]['ItemRef']    ?? null;          // ItemBasedExpenseLineDetail

			// Classify the category account: real spend vs money movement.
			$acctId  = $acct['value'] ?? null;
			$ref     = ($acctId !== null && isset($acctMap[$acctId])) ? $acctMap[$acctId] : null;
			$acctTy  = $ref['type']    ?? null;
			$acctSub = $ref['subtype'] ?? null;

			$insL->execute([
				$expenseId,
				isset($line['LineNum']) ? (int)$line['LineNum'] : $i + 1,
				$type !== '' ? $type : null,
				round((float)($line['Amount'] ?? 0), 2),
				($line['Description'] ?? '') !== '' ? (string)$line['Description'] : null,
				$acctId,
				$acct['name']  ?? null,
				$acctTy,
				$acctSub,
				qb_line_is_expense($acctTy),
				$item['value'] ?? null,
				$item['name']  ?? null,
			]);
		}

		$db->commit();
		return $action;
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		throw $e;
	}
}

/* ---- sync -------------------------------------------------------------- */

/**
 * The nightly job. Empty table -> pull all history once (so we have spend data
 * from before go-live); every run after -> rolling 90 days.
 *
 * Returns ['error'=>..., 'window'=>..., 'fetched'=>, 'inserted'=>, 'updated'=>,
 *          'deleted'=>, 'lines'=>, 'secs'=>].
 */
function qb_expenses_sync($db, $windowDays = QB_EXPENSES_WINDOW_DAYS) {
	$t0  = microtime(true);
	$out = ['error' => null, 'window' => 'all history', 'fetched' => 0, 'inserted' => 0,
	        'updated' => 0, 'deleted' => 0, 'lines' => 0, 'accounts' => 0, 'balances' => 0, 'secs' => 0.0];

	if (!qb_is_connected()) {
		$out['error'] = 'QuickBooks is not connected.';
		$out['secs']  = round(microtime(true) - $t0, 1);
		return $out;
	}

	ensure_qb_expenses_tables($db);
	$realmId = qb_expenses_realm_id();
	if ($realmId === '') {
		$out['error'] = 'QuickBooks is not connected.';
		$out['secs']  = round(microtime(true) - $t0, 1);
		return $out;
	}

	// Refresh the chart of accounts first — the line classifier needs account
	// types, and a category added in QuickBooks since the last run would
	// otherwise import unclassified (is_expense = 0) and understate spend.
	$acc = qb_refresh_accounts_ref($db, $realmId);
	if (!empty($acc['error'])) {
		$out['error'] = 'Account list fetch failed: ' . $acc['error'];
		$out['secs']  = round(microtime(true) - $t0, 1);
		return $out;                                     // no map = no classification; don't import blind
	}
	$out['accounts'] = $acc['count'];
	$out['balances'] = $acc['balances'] ?? 0;            // captured from that same fetch
	$acctMap = qb_accounts_ref_map($db, $realmId);

	// Pick the window. No flag, no stored state — the table's emptiness is the flag.
	$isEmpty = (int)$db->query("SELECT COUNT(*) FROM qb_expenses")->fetchColumn() === 0;
	$cutoff  = $isEmpty ? null : date('Y-m-d', strtotime('-' . (int)$windowDays . ' days'));
	$where   = $cutoff ? "WHERE TxnDate >= '$cutoff'" : "";
	$out['window'] = $cutoff ? ($cutoff . ' .. today') : 'all history (first run)';

	// Fetch + upsert, paged. 90 days normally fits one page; paging is for the backfill.
	$seen = [];
	$pos  = 1;
	$page = QB_EXPENSES_PAGE;
	do {
		$sql = "SELECT * FROM Purchase $where ORDERBY Id ASC STARTPOSITION $pos MAXRESULTS $page";
		$r   = qb_query($sql);
		if (!empty($r['error'])) {                       // ABORT — never let an API failure reach the sweep
			qb_log('expenses', 'fetch failed: ' . $r['error']);
			$out['error'] = $r['error'];
			$out['secs']  = round(microtime(true) - $t0, 1);
			return $out;
		}
		$rows = $r['Purchase'] ?? [];
		foreach ($rows as $row) {
			try {
				$action = qb_expense_upsert($db, $row, $realmId, $acctMap);
				if ($action === 'insert') $out['inserted']++;
				elseif ($action === 'update') $out['updated']++;
				$out['lines'] += count($row['Line'] ?? []);
				$seen[] = (string)$row['Id'];
			} catch (Throwable $e) {
				qb_log('expenses', 'upsert failed for Purchase ' . ($row['Id'] ?? '?') . ': ' . $e->getMessage());
				$out['error'] = 'One or more rows failed to save: ' . $e->getMessage();
			}
		}
		$out['fetched'] += count($rows);
		$pos += $page;
	} while (count($rows) === $page);

	// Soft-delete sweep. Anything in the window we queried that QBO no longer
	// returned is gone. Three guards, because this is the one place this design
	// can do real damage:
	//   - a row-level failure above means $seen is incomplete -> skip
	//   - zero rows back is far more likely a bad day at Intuit than a wiped ledger
	//   - the first (full-history) run has no cutoff to scope the sweep to
	if (!$out['error'] && $out['fetched'] > 0 && $cutoff !== null) {
		$out['deleted'] = qb_expenses_sweep($db, $realmId, $cutoff, $seen);
	}

	$out['secs'] = round(microtime(true) - $t0, 1);
	return $out;
}

/**
 * Mark rows inside the queried window that QBO didn't return. Scoped to
 * txn_date >= the SAME cutoff used in the query — without that, a 90-day pull
 * would flag every historical row as deleted.
 *
 * Diffs in PHP rather than emitting a giant `NOT IN (...)`, so a full-history
 * backfill can't build an unbounded statement.
 */
function qb_expenses_sweep($db, $realmId, $cutoff, array $seen) {
	$s = $db->prepare("SELECT qb_id FROM qb_expenses
		WHERE realm_id = ? AND deleted_at IS NULL AND txn_date >= ?");
	$s->execute([$realmId, $cutoff]);
	$have = $s->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

	$missing = array_values(array_diff($have, $seen));
	if (!$missing) return 0;

	$n = 0;
	foreach (array_chunk($missing, 500) as $chunk) {
		$in = implode(',', array_fill(0, count($chunk), '?'));
		$u  = $db->prepare("UPDATE qb_expenses SET deleted_at = NOW()
			WHERE realm_id = ? AND deleted_at IS NULL AND txn_date >= ? AND qb_id IN ($in)");
		$u->execute(array_merge([$realmId, $cutoff], $chunk));
		$n += $u->rowCount();
	}
	return $n;
}
