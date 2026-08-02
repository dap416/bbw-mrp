<?php
/* ============================================================
   QUICKBOOKS CASH RECEIVED — nightly import.

   NOT a sales feed. Shopify is the master for sales; this answers
   the narrower question "how much of that cash has actually
   landed", for reconciliation.

   Three QBO entities into one pair of tables:
     Deposit       — money arriving in a bank account
     SalesReceipt  — a paid-on-the-spot customer sale
     RefundReceipt — money going back out to a customer

   Same mechanics as includes/qb_expenses.php: paged, rolling
   90-day window on MetaData.LastUpdatedTime, upsert on
   (realm_id, qb_type, qb_id), lines replaced wholesale, and a
   soft-delete sweep scoped to the window it queried.

   is_sales_cash is the point of the table — see
   qb_cash_line_is_sales(). Only a minority of deposit lines are
   customer money; the rest are loan proceeds, capital
   contributions and transfers, and counting those as sales would
   overstate revenue.
   ============================================================ */

require_once __DIR__ . '/fns.php';
require_once __DIR__ . '/quickbooks.php';
require_once __DIR__ . '/qb_expenses.php';   // qb_accounts_ref_map(), qb_expenses_realm_id(), QB_EXPENSES_PAGE

/** How far back each nightly run re-pulls. */
const QB_CASH_IN_WINDOW_DAYS = 90;

/** The entities that represent cash in (or, for refunds, cash back out). */
function qb_cash_in_types() {
	return ['Deposit', 'SalesReceipt', 'RefundReceipt'];
}

/* ---- schema ------------------------------------------------------------ */

function ensure_qb_cash_in_tables($db) {
	$db->exec("CREATE TABLE IF NOT EXISTS qb_cash_in (
		id            BIGINT AUTO_INCREMENT PRIMARY KEY,
		realm_id      VARCHAR(32) NOT NULL,
		qb_type       VARCHAR(20) NOT NULL,   -- Deposit | SalesReceipt | RefundReceipt
		qb_id         VARCHAR(32) NOT NULL,
		txn_date      DATE NULL,
		total_amt     DECIMAL(14,2) NOT NULL DEFAULT 0,
		deposit_to_id VARCHAR(32) NULL,
		deposit_to    VARCHAR(160) NULL,      -- 'BASIC BUS Chkg (1183)', 'Shopify Clearing Account'
		deleted_at    DATETIME NULL,
		raw_json      LONGTEXT NULL,
		synced_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY uq_cash (realm_id, qb_type, qb_id),
		KEY idx_date (txn_date)
	) ENGINE=InnoDB");

	$db->exec("CREATE TABLE IF NOT EXISTS qb_cash_in_lines (
		id           BIGINT AUTO_INCREMENT PRIMARY KEY,
		cash_in_id   BIGINT NOT NULL,
		line_num     INT NULL,
		detail_type  VARCHAR(40) NULL,
		amount       DECIMAL(14,2) NOT NULL DEFAULT 0,
		description  TEXT NULL,
		source_name  VARCHAR(200) NULL,   -- Entity or AccountRef on the line: 'Shopify', 'Intuit Loan', ...
		source_id    VARCHAR(32) NULL,
		account_type VARCHAR(60) NULL,    -- resolved via qb_accounts_ref
		is_sales_cash TINYINT NOT NULL DEFAULT 0,
		CONSTRAINT fk_cashline FOREIGN KEY (cash_in_id) REFERENCES qb_cash_in(id) ON DELETE CASCADE,
		KEY idx_cash (cash_in_id),
		KEY idx_sales (is_sales_cash)
	) ENGINE=InnoDB");

	ensure_qb_accounts_ref_table($db);
}

/* ---- classification ---------------------------------------------------- */

/**
 * Account types that mean "this money is customer revenue".
 * A deposit line pointing at an Income account is a sale; one pointing at a
 * liability (loan proceeds) or Equity (owner contribution) is financing.
 */
function qb_cash_sales_account_types() {
	return ['Income', 'Other Income'];
}

/**
 * Payment processors whose name on a line means customer sales cash. Used ONLY
 * as a fallback when the line carries no resolvable account — a deposit line's
 * Entity is free text and can say anything.
 */
function qb_cash_sales_sources() {
	return ['shopify', 'stripe', 'paypal', 'square', 'shop pay', 'shoppay'];
}

/**
 * Is this line customer sales cash?
 *
 * Resolve the line's own account type first — that is the auditable signal, and
 * the same principle as is_expense on the spend side. Only when the line has no
 * account to resolve (a SalesReceipt line points at an Item, not an account) do
 * we fall back to the source name, and even then a bare Deposit stays 0: an
 * unrecognised deposit is far more likely to be financing or a transfer than a
 * sale, and understating sales cash is the safer error for a reconciliation
 * table.
 *
 * SalesReceipt / RefundReceipt are customer transactions by definition, so their
 * lines default to 1 when nothing contradicts it.
 */
function qb_cash_line_is_sales($qbType, $acctType, $sourceName) {
	$acctType = (string)$acctType;
	if ($acctType !== '') {
		if (in_array($acctType, qb_cash_sales_account_types(), true)) return 1;
		// A real account type that isn't income settles it — financing, transfer,
		// or A/R settlement. Don't let a name override a resolved type.
		return 0;
	}

	$name = strtolower(trim((string)$sourceName));
	if ($name !== '') {
		foreach (qb_cash_sales_sources() as $needle) {
			if (strpos($name, $needle) !== false) return 1;
		}
	}

	return in_array($qbType, ['SalesReceipt', 'RefundReceipt'], true) ? 1 : 0;
}

/* ---- upsert ------------------------------------------------------------ */

/**
 * Pull both the line's ACCOUNT and its human source, which are different things
 * and are used for different jobs.
 *
 * A deposit line carries an AccountRef (where the money came from — 'Shopify
 * Clearing Account', 'Shareholder Loan') and often also an Entity (a customer or
 * vendor name). The account is what gets classified, because it resolves to a
 * type; the entity is the better label. Taking whichever appeared first would
 * mean a Shopify deposit that happens to name a customer loses its account and
 * silently classifies as non-sales.
 */
function qb_cash_line_source($line, $detailType) {
	$d = $line[$detailType] ?? [];

	$acctId   = $d['AccountRef']['value'] ?? null;
	$acctName = $d['AccountRef']['name']  ?? null;

	$entId = $entName = null;
	foreach (['Entity', 'ItemRef', 'CustomerRef'] as $k) {
		if (!empty($d[$k])) { $entId = $d[$k]['value'] ?? null; $entName = $d[$k]['name'] ?? null; break; }
	}

	return [
		'account_id' => $acctId,
		'id'         => $entId   ?? $acctId,     // label the line by whoever it names
		'name'       => $entName ?? $acctName,
	];
}

/**
 * Write one cash-in transaction (header + lines) in a single transaction.
 * Returns 'insert' or 'update'.
 */
function qb_cash_in_upsert($db, $qbType, array $row, $realmId, array $acctMap) {
	$qbId = (string)($row['Id'] ?? '');
	if ($qbId === '') return null;

	$vals = [
		'txn_date'      => qb_expense_date($row['TxnDate'] ?? ''),
		'total_amt'     => round((float)($row['TotalAmt'] ?? 0), 2),
		'deposit_to_id' => $row['DepositToAccountRef']['value'] ?? null,
		'deposit_to'    => $row['DepositToAccountRef']['name']  ?? null,
		'raw_json'      => json_encode($row),
	];

	$sel = $db->prepare("SELECT id FROM qb_cash_in WHERE realm_id = ? AND qb_type = ? AND qb_id = ?");
	$sel->execute([$realmId, $qbType, $qbId]);
	$existingId = $sel->fetchColumn();

	$db->beginTransaction();
	try {
		if ($existingId) {
			$rowId = (int)$existingId;
			$db->prepare("UPDATE qb_cash_in SET
					txn_date=?, total_amt=?, deposit_to_id=?, deposit_to=?, raw_json=?,
					deleted_at=NULL, synced_at=NOW()
				WHERE id = ?")
			   ->execute(array_merge(array_values($vals), [$rowId]));
			$action = 'update';
		} else {
			$db->prepare("INSERT INTO qb_cash_in
					(realm_id, qb_type, qb_id, txn_date, total_amt, deposit_to_id, deposit_to,
					 raw_json, deleted_at, synced_at)
				VALUES (?,?,?,?,?,?,?,?,NULL,NOW())
				ON DUPLICATE KEY UPDATE
					txn_date=VALUES(txn_date), total_amt=VALUES(total_amt),
					deposit_to_id=VALUES(deposit_to_id), deposit_to=VALUES(deposit_to),
					raw_json=VALUES(raw_json), deleted_at=NULL, synced_at=NOW()")
			   ->execute(array_merge([$realmId, $qbType, $qbId], array_values($vals)));
			$rowId = (int)$db->lastInsertId();
			if (!$rowId) { $sel->execute([$realmId, $qbType, $qbId]); $rowId = (int)$sel->fetchColumn(); }
			$action = 'insert';
		}

		$db->prepare("DELETE FROM qb_cash_in_lines WHERE cash_in_id = ?")->execute([$rowId]);
		$insL = $db->prepare("INSERT INTO qb_cash_in_lines
			(cash_in_id, line_num, detail_type, amount, description, source_name, source_id,
			 account_type, is_sales_cash)
			VALUES (?,?,?,?,?,?,?,?,?)");

		$lines = 0;
		foreach (($row['Line'] ?? []) as $i => $line) {
			$type = (string)($line['DetailType'] ?? '');
			// Subtotal rows are a rendering artifact, not a line — storing them would
			// double every SUM(amount) taken off this table.
			if ($type === 'SubTotalLineDetail') continue;

			$src      = qb_cash_line_source($line, $type);
			$acctType = $src['account_id'] !== null
				? ($acctMap[(string)$src['account_id']]['type'] ?? null)
				: null;

			$insL->execute([
				$rowId,
				isset($line['LineNum']) ? (int)$line['LineNum'] : $i + 1,
				$type !== '' ? $type : null,
				round((float)($line['Amount'] ?? 0), 2),
				($line['Description'] ?? '') !== '' ? (string)$line['Description'] : null,
				$src['name'],
				$src['id'],
				$acctType,
				qb_cash_line_is_sales($qbType, $acctType, $src['name']),
			]);
			$lines++;
		}

		$db->commit();
		return ['action' => $action, 'lines' => $lines];
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		throw $e;
	}
}

/* ---- sync -------------------------------------------------------------- */

/**
 * The nightly job. Empty table -> pull all history once; every run after ->
 * rolling 90 days on MetaData.LastUpdatedTime (a deposit can be edited or
 * re-categorised long after its TxnDate).
 *
 * Returns ['error'=>, 'window'=>, 'fetched'=>, 'inserted'=>, 'updated'=>,
 *          'deleted'=>, 'lines'=>, 'secs'=>].
 */
function qb_cash_in_sync($db, $windowDays = QB_CASH_IN_WINDOW_DAYS) {
	$t0  = microtime(true);
	$out = ['error' => null, 'window' => '', 'fetched' => 0, 'inserted' => 0,
	        'updated' => 0, 'deleted' => 0, 'lines' => 0, 'secs' => 0.0];

	if (!qb_is_connected()) {
		$out['error']  = 'QuickBooks is not connected.';
		$out['window'] = 'skipped';
		$out['secs']   = round(microtime(true) - $t0, 1);
		return $out;
	}

	ensure_qb_cash_in_tables($db);
	$realmId = qb_expenses_realm_id();
	if ($realmId === '') {
		$out['error']  = 'QuickBooks is not connected.';
		$out['window'] = 'skipped';
		$out['secs']   = round(microtime(true) - $t0, 1);
		return $out;
	}

	// The chart of accounts is refreshed by the expense sync earlier in the same
	// run; this reads the mirror rather than re-fetching it.
	$acctMap = qb_accounts_ref_map($db, $realmId);

	$isEmpty = (int)$db->query("SELECT COUNT(*) FROM qb_cash_in")->fetchColumn() === 0;
	$cutoff  = $isEmpty ? null : date('Y-m-d', strtotime('-' . (int)$windowDays . ' days'));
	$where   = $cutoff ? "WHERE MetaData.LastUpdatedTime >= '$cutoff'" : "";
	$out['window'] = $cutoff ? ($cutoff . ' .. today (by LastUpdatedTime)') : 'all history (first run)';

	// Each entity is swept against only the ids seen for THAT entity — the unique
	// key is (realm_id, qb_type, qb_id) and ids are only unique within a type.
	$seen = [];
	foreach (qb_cash_in_types() as $qbType) {
		$seen[$qbType] = [];
		$pos = 1;
		do {
			$sql = "SELECT * FROM $qbType $where ORDERBY Id ASC STARTPOSITION $pos MAXRESULTS " . QB_EXPENSES_PAGE;
			$r   = qb_query($sql);
			if (!empty($r['error'])) {                    // ABORT — a fetch failure must never reach the sweep
				qb_log('cash_in', $qbType . ' fetch failed: ' . $r['error']);
				$out['error'] = $r['error'];
				$out['secs']  = round(microtime(true) - $t0, 1);
				return $out;
			}
			$rows = $r[$qbType] ?? [];
			foreach ($rows as $row) {
				try {
					$res = qb_cash_in_upsert($db, $qbType, $row, $realmId, $acctMap);
					if (!$res) continue;
					if ($res['action'] === 'insert') $out['inserted']++; else $out['updated']++;
					$out['lines'] += $res['lines'];
					$seen[$qbType][] = (string)$row['Id'];
				} catch (Throwable $e) {
					qb_log('cash_in', 'upsert failed for ' . $qbType . ' ' . ($row['Id'] ?? '?') . ': ' . $e->getMessage());
					$out['error'] = 'One or more rows failed to save: ' . $e->getMessage();
				}
			}
			$out['fetched'] += count($rows);
			$pos += QB_EXPENSES_PAGE;
		} while (count($rows) === QB_EXPENSES_PAGE);
	}

	// Same three guards as the expense import.
	if (!$out['error'] && $out['fetched'] > 0 && $cutoff !== null) {
		foreach (qb_cash_in_types() as $qbType) {
			$out['deleted'] += qb_cash_in_sweep($db, $realmId, $qbType, $cutoff, $seen[$qbType]);
		}
	}

	$out['secs'] = round(microtime(true) - $t0, 1);
	return $out;
}

/**
 * Mark rows of one entity inside the queried window that QBO didn't return.
 *
 * NOTE the asymmetry with the expense sweep: the window was queried on
 * LastUpdatedTime, but rows are compared on txn_date, which is all we store. A
 * transaction whose TxnDate is inside the window necessarily has a
 * LastUpdatedTime at or after it, so anything eligible for sweeping was eligible
 * for fetching — the sweep can't outrun the query.
 */
function qb_cash_in_sweep($db, $realmId, $qbType, $cutoff, array $seen) {
	$s = $db->prepare("SELECT qb_id FROM qb_cash_in
		WHERE realm_id = ? AND qb_type = ? AND deleted_at IS NULL AND txn_date >= ?");
	$s->execute([$realmId, $qbType, $cutoff]);
	$have = $s->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

	$missing = array_values(array_diff($have, $seen));
	if (!$missing) return 0;

	$n = 0;
	foreach (array_chunk($missing, 500) as $chunk) {
		$in = implode(',', array_fill(0, count($chunk), '?'));
		$u  = $db->prepare("UPDATE qb_cash_in SET deleted_at = NOW()
			WHERE realm_id = ? AND qb_type = ? AND deleted_at IS NULL AND txn_date >= ? AND qb_id IN ($in)");
		$u->execute(array_merge([$realmId, $qbType, $cutoff], $chunk));
		$n += $u->rowCount();
	}
	return $n;
}
