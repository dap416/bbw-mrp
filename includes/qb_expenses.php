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
		item_id      VARCHAR(32) NULL,
		item_name    VARCHAR(160) NULL,
		CONSTRAINT fk_qbexp FOREIGN KEY (expense_id) REFERENCES qb_expenses(id) ON DELETE CASCADE,
		KEY idx_expense (expense_id),
		KEY idx_account (account_name)
	) ENGINE=InnoDB");
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
function qb_expense_upsert($db, array $row, $realmId) {
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
			(expense_id, line_num, detail_type, amount, description, account_id, account_name, item_id, item_name)
			VALUES (?,?,?,?,?,?,?,?,?)");

		foreach (($row['Line'] ?? []) as $i => $line) {
			$type = (string)($line['DetailType'] ?? '');
			$acct = $line[$type]['AccountRef'] ?? null;          // AccountBasedExpenseLineDetail -> the CATEGORY
			$item = $line[$type]['ItemRef']    ?? null;          // ItemBasedExpenseLineDetail
			$insL->execute([
				$expenseId,
				isset($line['LineNum']) ? (int)$line['LineNum'] : $i + 1,
				$type !== '' ? $type : null,
				round((float)($line['Amount'] ?? 0), 2),
				($line['Description'] ?? '') !== '' ? (string)$line['Description'] : null,
				$acct['value'] ?? null,
				$acct['name']  ?? null,
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
	        'updated' => 0, 'deleted' => 0, 'lines' => 0, 'secs' => 0.0];

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
				$action = qb_expense_upsert($db, $row, $realmId);
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
