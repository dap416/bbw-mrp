<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/shopify.php");
	require_login();

	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo json_encode(['error'=>'denied']); exit; }

	header('Content-Type: application/json');

	if (!shopify_is_configured()) {
		echo json_encode(['error' => 'Connect Shopify first (Integrations page).']);
		exit;
	}

	// A "PO candidate" is an order that looks like wholesale/bulk: it came through
	// a wholesale channel (draft/Collective) OR moved a large quantity of units.
	$BIG_QTY = (int)($_POST['min_qty'] ?? 30);
	$cutoff  = date('Y-m-d', strtotime('-24 months'));

	$query = '
	query($cursor: String, $q: String!) {
	  orders(first: 100, after: $cursor, query: $q, sortKey: CREATED_AT, reverse: true) {
	    pageInfo { hasNextPage endCursor }
	    edges { node {
	      name
	      createdAt
	      sourceName
	      cancelledAt
	      customer { displayName }
	      lineItems(first: 20) { edges { node { title sku quantity } } }
	    } }
	  }
	}';

	$q = "created_at:>=$cutoff";
	$cursor = null; $pages = 0;
	$candidates = [];

	do {
		$res = shopify_graphql($query, ['cursor' => $cursor, 'q' => $q]);
		if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }
		$o = $res['data']['orders'] ?? null;
		if ($o === null) { echo json_encode(['error' => 'Malformed Shopify response.']); exit; }

		foreach ($o['edges'] as $oe) {
			$n = $oe['node'];
			if (!empty($n['cancelledAt'])) continue;

			$chan = shopify_channel_label($n['sourceName'] ?? '');
			$isWholesaleChan = (strpos($chan, 'wholesale') !== false);

			$qty = 0; $parts = [];
			foreach ($n['lineItems']['edges'] as $le) {
				$li = $le['node'];
				$liQty = (int)($li['quantity'] ?? 0);
				if ($liQty <= 0) continue;
				$qty += $liQty;
				$label = $li['sku'] ? $li['sku'] : ($li['title'] ?? '?');
				$parts[] = $liQty . 'x ' . $label;
			}

			if (!$isWholesaleChan && $qty < $BIG_QTY) continue; // not a PO-sized order

			$who  = $n['customer']['displayName'] ?? '';
			$name = trim(($who !== '' ? $who : $chan) . ' ' . ($n['name'] ?? ''));

			$candidates[] = [
				'date'    => substr($n['createdAt'] ?? '', 0, 10),
				'label'   => $name,
				'channel' => $chan,
				'qty'     => $qty,
				'details' => implode(', ', array_slice($parts, 0, 12)),
			];
		}

		$cursor  = $o['pageInfo']['endCursor']  ?? null;
		$hasNext = $o['pageInfo']['hasNextPage'] ?? false;
		$pages++;
	} while ($hasNext && $pages < 15 && count($candidates) < 60);

	// Largest first
	usort($candidates, fn($a, $b) => $b['qty'] <=> $a['qty']);

	echo json_encode(['candidates' => array_slice($candidates, 0, 40)]);
