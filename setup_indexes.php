<?php

	// ── ONE-TIME MAINTENANCE SCRIPT ──────────────────────────────────────────
	// Adds performance indexes to the live database. Safe to run more than once:
	// indexes that already exist are skipped, and indexes for a column/table
	// that doesn't exist are reported but do no harm. Re-run any time.

	require_once(__DIR__."/includes/fns.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if ($role !== 'admin' && $role !== 'master') {
		http_response_code(403);
		exit('Access denied — must be an admin to run this.');
	}

	$db = db_connect();
	if (!$db) { exit('Could not connect to the database.'); }

	$indexes = [
		// Transaction history lookups (part history, build/post reports)
		"CREATE INDEX idx_trans_type_partid_date ON trans (type, partid, date)",
		"CREATE INDEX idx_trans_partid_date      ON trans (partid, date)",
		"CREATE INDEX idx_trans_warehouse        ON trans (warehouse_id)",

		// Purchase orders
		"CREATE INDEX idx_orders_partid          ON orders (partid)",
		"CREATE INDEX idx_orders_warehouse       ON orders (warehouse_id)",
		"CREATE INDEX idx_ordpost_ordid          ON ordpost (ordid)",
		"CREATE INDEX idx_notes_ordid            ON notes (ordid)",
		"CREATE INDEX idx_payments_ordid         ON payments (ordid)",

		// Parts / inventory
		"CREATE INDEX idx_parts_partno           ON parts (partno)",
		"CREATE INDEX idx_pwq_part               ON part_warehouse_qty (part_id)",
		"CREATE INDEX idx_pwq_warehouse          ON part_warehouse_qty (warehouse_id)",

		// Bill of materials (Products page + Research BOM explosion)
		"CREATE INDEX idx_build_prodid           ON build (prodid)",
		"CREATE INDEX idx_build_partid           ON build (partid)",

		// Packaging / in-transit (Packaging + Orders pages, header stats)
		"CREATE INDEX idx_intransit_prodid       ON intransit (prodid)",
		"CREATE INDEX idx_intransit_warehouse    ON intransit (warehouse_id)",
		"CREATE INDEX idx_intransit_recdate      ON intransit (recdate)",
		"CREATE INDEX idx_intransit_orddate      ON intransit (orddate)",

		// Pick list (Packaging page — filtered on closedate, joined on ordid/prodid)
		"CREATE INDEX idx_picks_closedate        ON picks (closedate)",
		"CREATE INDEX idx_picks_ordid            ON picks (ordid)",
		"CREATE INDEX idx_picks_prodid           ON picks (prodid)",
	];

	header('Content-Type: text/html; charset=utf-8');
	echo "<!doctype html><html><head><title>Apply Indexes</title>";
	echo "<style>body{font-family:Inter,Arial,sans-serif;max-width:760px;margin:40px auto;padding:0 16px;color:#1a1a2e;}";
	echo "h1{font-size:1.4rem;} .ok{color:#2ca87f;} .skip{color:#e58a00;} .err{color:#dc2626;}";
	echo "li{margin:6px 0;font-size:0.95rem;} code{background:#f1f3f5;padding:1px 5px;border-radius:4px;}</style></head><body>";
	echo "<h1>Applying database indexes</h1><ul>";

	$added = 0; $skipped = 0; $failed = 0;

	foreach ($indexes as $sql) {
		// Pull the index name for a readable label
		preg_match('/CREATE INDEX\s+(\S+)/i', $sql, $m);
		$name = $m[1] ?? $sql;
		try {
			$db->exec($sql);
			echo "<li class='ok'>&#10003; Created <code>".htmlspecialchars($name)."</code></li>";
			$added++;
		} catch (PDOException $e) {
			$msg = $e->getMessage();
			if (stripos($msg, 'Duplicate key name') !== false) {
				echo "<li class='skip'>&#8226; Already exists, skipped <code>".htmlspecialchars($name)."</code></li>";
				$skipped++;
			} else {
				echo "<li class='err'>&#10007; Failed <code>".htmlspecialchars($name)."</code> — ".htmlspecialchars($msg)."</li>";
				$failed++;
			}
		}
	}

	echo "</ul>";
	echo "<p><strong>Done.</strong> Created: $added &nbsp; Already existed: $skipped &nbsp; Failed: $failed</p>";
	echo "<p style='color:#6c757d;font-size:0.9rem;'>Safe to re-run. A red \"Failed\" line just means that table/column doesn't exist on this database and can be ignored.</p>";
	echo "</body></html>";
