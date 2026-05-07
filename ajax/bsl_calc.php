<?php

	require_once(__DIR__."/../includes/fns.php");

	$dbLink = $mysqli = db_connect();

	$currentMonth  = (int)date('n'); // 1–12
	$safetyBuffer  = 30;             // extra days beyond lead time to prevent stockouts

	$parts = $dbLink->query("SELECT `id`, `lead_time`, `omit` FROM `parts`");

	while ($part = $parts->fetch()) {

		$partId      = $part['id'];
		$leadTime    = max(1, (int)($part['lead_time'] ?? 45));
		$windowDays  = $leadTime + $safetyBuffer;
		$partOmit    = max(0, (int)($part['omit'] ?? 0));

		// Use only the last 12 months of build demand for monthly averages.
		// This excludes stale anomalies (one-time large batches in prior years) while
		// still capturing a full seasonal cycle.
		$cutoff = date('Y-m-d H:i:s', strtotime('-12 months'));

		$monthTotals = $dbLink->query("
			SELECT MONTH(`date`) AS `mo`, SUM(`qty`) * -1 AS `demand`
			FROM `trans`
			WHERE `type` = 'BUILD' AND `partid` = '$partId'
			  AND `date` > '$cutoff'
			GROUP BY MONTH(`date`)
		");

		$avgByMonth = array_fill(1, 12, 0.0);
		while ($row = $monthTotals->fetch()) {
			$avgByMonth[(int)$row['mo']] = (float)$row['demand'];
		}

		// Find the peak rolling-window demand over the next 12 months.
		// Starting from each of the next 12 months, project windowDays of demand
		// using last year's seasonal actuals, and take the highest result.
		// This correctly triggers pre-season ordering (e.g. ordering LD in April
		// because the October window will be the highest demand stretch).
		$monthsForward = $windowDays / 30.0;
		$peakDemand    = 0.0;

		for ($startOffset = 0; $startOffset < 12; $startOffset++) {
			$windowDemand = 0.0;
			for ($i = 0; $i < ceil($monthsForward); $i++) {
				$futureMonth   = (($currentMonth - 1 + $startOffset + $i) % 12) + 1;
				$weight        = min(1.0, $monthsForward - $i);
				$windowDemand += $avgByMonth[$futureMonth] * $weight;
			}
			$peakDemand = max($peakDemand, $windowDemand);
		}

		// Subtract the omit quantity from BSL — those units are considered on-hand
		// overstock that already satisfies part of the stocking target.
		$bsl = max(0, (int)round($peakDemand) - $partOmit);

		$dbLink->query("UPDATE `parts` SET `bsl` = '$bsl' WHERE `id` = '$partId'");
	}

	// Redirect only when called directly, not when included by stock_calc.php
	if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
		header("Location: /orders/stock_calc.php");
	}
