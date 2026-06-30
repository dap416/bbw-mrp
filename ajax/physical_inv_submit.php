<?php

	require_once(__DIR__."/../includes/fns.php");
	require_login();
	require_can(can_edit('inventory'), 'You do not have permission to submit a physical count.');

	header('Content-Type: application/json');

	$db          = db_connect();
	$now         = date("Y-m-d H:i:s");
	$userId      = $_SESSION['user_id'] ?? null;
	$userName    = $_SESSION['user_name'] ?? '';
	$warehouseId = (int)($_POST['warehouse_id'] ?? 0);
	$counts      = $_POST['counts'] ?? [];   // [part_id => counted_qty]

	if (!$warehouseId || empty($counts)) { echo json_encode(['error' => 'Invalid submission.']); exit; }

	phys_inv_ensure_tables($db);

	// Warehouse name snapshot (for the report).
	$whName = '';
	foreach (get_warehouses($db) as $wh) { if ((int)$wh['id'] === $warehouseId) { $whName = $wh['name']; break; } }

	// Build line items (snapshot current warehouse qty as the "before" value).
	$lines = [];
	$varianceParts = 0;
	foreach ($counts as $partId => $countedQty) {
		$partId     = (int)$partId;
		$countedQty = (int)$countedQty;
		if ($partId <= 0) continue;
		$currentQty = wh_get_qty($db, $partId, $warehouseId);
		$diff       = $countedQty - $currentQty;
		if ($diff !== 0) $varianceParts++;
		$lines[] = ['part_id' => $partId, 'qoh' => $currentQty, 'counted' => $countedQty, 'diff' => $diff];
	}

	if (empty($lines)) { echo json_encode(['error' => 'No valid counts submitted.']); exit; }

	// Pull part numbers for the report in one query.
	$ids = array_map(fn($l) => $l['part_id'], $lines);
	$partNos = [];
	$in = implode(',', array_fill(0, count($ids), '?'));
	$ps = $db->prepare("SELECT id, partno, `desc` FROM parts WHERE id IN ($in)");
	$ps->execute($ids);
	foreach ($ps->fetchAll() as $r) $partNos[(int)$r['id']] = ['partno' => $r['partno'], 'desc' => $r['desc']];

	// Stage the batch — DOES NOT change inventory. Awaits confirmation.
	try {
		$db->beginTransaction();

		$bs = $db->prepare("INSERT INTO phys_inv_batches
			(warehouse_id, warehouse_name, user_id, user_name, status, total_parts, variance_parts, created_at)
			VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)");
		$bs->execute([$warehouseId, $whName, $userId, $userName, count($lines), $varianceParts, $now]);
		$batchId = (int)$db->lastInsertId();

		$is = $db->prepare("INSERT INTO phys_inv_batch_items
			(batch_id, part_id, partno, pdesc, qoh_at_count, counted, diff)
			VALUES (?, ?, ?, ?, ?, ?, ?)");
		foreach ($lines as $l) {
			$is->execute([$batchId, $l['part_id'], $partNos[$l['part_id']]['partno'] ?? ('#'.$l['part_id']),
			              $partNos[$l['part_id']]['desc'] ?? '', $l['qoh'], $l['counted'], $l['diff']]);
		}

		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		echo json_encode(['error' => 'Could not save the count: ' . $e->getMessage()]);
		exit;
	}

	echo json_encode([
		'ok'          => true,
		'staged'      => true,
		'batch_id'    => $batchId,
		'variances'   => $varianceParts,
		'total'       => count($lines),
		'report_url'  => '/physical_inv_report.php?batch=' . $batchId,
	]);
