<?php

	// ── ONE-TIME MAINTENANCE SCRIPT ──────────────────────────────────────────
	// Adds performance indexes to the live database. Safe to run more than once:
	// indexes that already exist are skipped. Delete this file after running.

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
		"CREATE INDEX idx_trans_type_partid_date ON trans (type, partid, date)",
		"CREATE INDEX idx_trans_partid_date      ON trans (partid, date)",
		"CREATE INDEX idx_orders_partid          ON orders (partid)",
		"CREATE INDEX idx_parts_partno           ON parts (partno)",
		"CREATE INDEX idx_notes_ordid            ON notes (ordid)",
		"CREATE INDEX idx_payments_ordid         ON payments (ordid)",
		"CREATE INDEX idx_ordpost_ordid          ON ordpost (ordid)",
		"CREATE INDEX idx_build_prodid           ON build (prodid)",
		"CREATE INDEX idx_pwq_part               ON part_warehouse_qty (part_id)",
		"CREATE INDEX idx_intransit_prodid       ON intransit (prodid)",
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
	echo "<p style='color:#6c757d;font-size:0.9rem;'>You can close this page. This maintenance file will be removed.</p>";
	echo "</body></html>";
