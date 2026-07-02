<?php
/**
 * Dashboard "good morning/afternoon/evening" briefing. Pulls the most
 * time-sensitive items from Tasks, inventory reorder needs, and (for
 * admins) the cash-flow plan, and asks the AI for a short spoken-style
 * summary of what to do now / soon. Cached ~2h per user; ?refresh=1 forces.
 */
	require_once(__DIR__."/../includes/fns.php");
	require_once(__DIR__."/../includes/anthropic.php");
	require_login();
	header('Content-Type: application/json');

	$db      = db_connect();
	$uid     = (int)($_SESSION['user_id'] ?? 0);
	$role    = $_SESSION['user_role'] ?? '';
	$isAdmin = in_array($role, ['admin', 'master'], true);
	$name    = $_SESSION['user_name'] ?? '';
	$h       = (int)date('G');
	$partOfDay = $h < 12 ? 'morning' : ($h < 17 ? 'afternoon' : 'evening');

	if (!anthropic_is_configured()) {
		echo json_encode(['ok' => true, 'html' => '<div class="text-muted small">Good ' . $partOfDay . '. (Add an Anthropic API key on Integrations to enable the AI briefing.)</div>', 'cached' => false]);
		exit;
	}

	// ── Cache (data_cache) ────────────────────────────────────────────────────
	$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");
	$cacheKey = 'briefing_' . $uid . '_' . $partOfDay;
	$force = !empty($_GET['refresh']);
	// The welcome message defaults to a WEEKLY update, but regenerates early
	// whenever an important event (task done / payment / delivery) bumps the
	// global 'briefing_dirty' marker via briefing_touch().
	$dirtyAt = null;
	try { $s = $db->prepare("SELECT updated_at FROM data_cache WHERE ckey = 'briefing_dirty'"); $s->execute(); $dr = $s->fetch(); if ($dr) $dirtyAt = strtotime($dr['updated_at']); } catch (Throwable $e) {}
	if (!$force) {
		try {
			$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$cacheKey]);
			$row = $s->fetch();
			if ($row) {
				$cacheTs = strtotime($row['updated_at']);
				$fresh   = (time() - $cacheTs) < 604800;                // weekly refresh
				$clean   = ($dirtyAt === null || $cacheTs >= $dirtyAt);  // no notable event since last build
				if ($fresh && $clean) {
					echo json_encode(['ok' => true, 'html' => $row['cval'], 'cached' => true, 'as_of' => $row['updated_at']]);
					exit;
				}
			}
		} catch (Throwable $e) {}
	}

	// ── Gather context ────────────────────────────────────────────────────────
	$ctx = ['now' => date('Y-m-d H:i'), 'weekday' => date('l'), 'part_of_day' => $partOfDay, 'user' => $name];

	// Open tasks (with days-until-due)
	try {
		tasks_ensure_table($db);
		$tasks = [];
		foreach ($db->query("SELECT title, due_date FROM tasks WHERE completed = 0" . ($isAdmin ? "" : " AND assigned_to = " . (int)$uid) . " ORDER BY (due_date IS NULL), due_date ASC LIMIT 40") as $t) {
			$due  = ($t['due_date'] && $t['due_date'] !== '0000-00-00') ? $t['due_date'] : null;
			$days = $due ? (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400) : null;
			$tasks[] = ['title' => $t['title'], 'due' => $due, 'days_until_due' => $days];
		}
		$ctx['open_tasks'] = $tasks;
	} catch (Throwable $e) { $ctx['open_tasks'] = []; }

	// Structured tasks assigned to THIS user → live stats woven into the message.
	try {
		$assignments = [];
		$as = $db->prepare("SELECT id, title, due_date, task_type, task_meta FROM tasks WHERE completed = 0 AND assigned_to = ? AND task_type IN ('tradeshow','inv_count') ORDER BY (due_date IS NULL), due_date ASC LIMIT 10");
		$as->execute([$uid]);
		foreach ($as->fetchAll() as $t) {
			$meta = json_decode($t['task_meta'] ?? '', true) ?: [];
			$due  = ($t['due_date'] && $t['due_date'] !== '0000-00-00') ? $t['due_date'] : null;
			if ($t['task_type'] === 'tradeshow' && !empty($meta['shows'])) {
				require_once(__DIR__."/../includes/planning.php");
				$prep = fp_show_prep($db, $meta['shows'], $due);
				$assignments[] = [
					'type' => 'tradeshow_build_pack', 'task' => $t['title'], 'due' => $due,
					'shows' => $prep['shows'] ?? $meta['shows'], 'error' => $prep['error'] ?? null,
					'products' => $prep['rows'] ?? [],
					'action_link' => ['label' => 'Open Packaging', 'url' => '/build.php'],
				];
			} elseif ($t['task_type'] === 'inv_count' && !empty($meta['part_id'])) {
				$pq = $db->prepare("SELECT partno, `desc`, qoh FROM parts WHERE id = ?");
				$pq->execute([(int)$meta['part_id']]);
				if ($p = $pq->fetch()) {
					$assignments[] = [
						'type' => 'inventory_count', 'task' => $t['title'], 'due' => $due,
						'part' => $p['partno'], 'description' => $p['desc'], 'current_qoh' => (int)$p['qoh'],
						'count_link' => ['label' => 'Enter count for ' . $p['partno'], 'url' => '/physical_inventory.php?part=' . (int)$meta['part_id']],
					];
				}
			}
		}
		if ($assignments) $ctx['my_assignments'] = $assignments;
	} catch (Throwable $e) {}

	// Recent wins to acknowledge (last 7 days): tasks completed, payments made, deliveries received.
	$recentEvents = [];
	try {
		foreach ($db->query("SELECT title, completed_at FROM tasks WHERE completed = 1 AND completed_at > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY completed_at DESC LIMIT 15") as $t)
			$recentEvents[] = ['kind' => 'task_completed', 'what' => $t['title'], 'when' => $t['completed_at']];
	} catch (Throwable $e) {}
	try {
		foreach ($db->query("SELECT p.amount, p.date, o.orderref, pt.partno FROM payments p JOIN orders o ON o.id = p.ordid LEFT JOIN parts pt ON pt.id = o.partid WHERE p.date > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY p.date DESC LIMIT 15") as $r)
			$recentEvents[] = ['kind' => 'payment_made', 'amount' => round((float)$r['amount']), 'order' => $r['orderref'], 'part' => $r['partno'], 'when' => $r['date']];
	} catch (Throwable $e) {}
	try {
		foreach ($db->query("SELECT t.qty, t.date, t.postref, p.partno, p.`desc` FROM trans t JOIN parts p ON p.id = t.partid WHERE t.type = 'POST' AND t.date > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY t.date DESC LIMIT 15") as $r)
			$recentEvents[] = ['kind' => 'delivery_received', 'part' => $r['partno'], 'desc' => $r['desc'], 'qty' => (int)$r['qty'], 'ref' => $r['postref'], 'when' => $r['date']];
	} catch (Throwable $e) {}
	$ctx['recent_events'] = $recentEvents;
	$ctx['has_recent_events'] = !empty($recentEvents);

	// Parts needing attention (out of stock / below BSL)
	try {
		$attn = [];
		foreach ($db->query("SELECT partno, qoh, bsl FROM parts WHERE qoh = 0 OR (bsl > 0 AND qoh < bsl) ORDER BY qoh ASC LIMIT 12") as $p) {
			$attn[] = ['part' => $p['partno'], 'qoh' => (int)$p['qoh'], 'bsl' => (int)$p['bsl'], 'out' => ((int)$p['qoh'] === 0)];
		}
		$ctx['parts_needing_attention'] = $attn;
		$ctx['parts_needing_attention_count'] = count($attn);
	} catch (Throwable $e) {}

	// Raw-material reorder suggestions (DB-only heuristic)
	require_once(__DIR__."/../includes/cashflow.php");
	try {
		$reorder = [];
		foreach (cashflow_reorder_suggestions($db, 10) as $r) {
			$reorder[] = ['part' => $r['part'], 'order_qty' => $r['order'], 'est_cost' => $r['cost'], 'lead_days' => $r['lead_days']];
		}
		$ctx['raw_material_reorder'] = $reorder;
	} catch (Throwable $e) {}

	// Finished-product (animator) BUILD recommendations so Shopify inventory never runs out.
	if (has_access('build') || $isAdmin) {
		require_once(__DIR__."/../includes/planning.php");
		try {
			$plan = fp_build_plan($db, date('Y-m-d', strtotime('+45 days')), 0);
			if (empty($plan['error'])) {
				$fpRecs = [];
				foreach ($plan['rows'] as $r) {
					if ($r['recommend'] <= 0) continue;
					$fpRecs[] = [
						'product'             => $r['product'],
						'sku'                 => $r['sku'],
						'shopify_stock'       => $r['fp_stock'],       // negative = already oversold (urgent)
						'demand_next_45d'     => $r['demand'],
						'build_units'         => $r['recommend'],      // units to build to cover demand
						'can_build_now'       => $r['buildable'],      // from raw materials on hand
						'short_need_raw'      => $r['short'],          // can't build now → order raw materials
						'limited_by_part'     => $r['limit_part'],
					];
				}
				$ctx['fp_build_recommendations'] = array_slice($fpRecs, 0, 12);
				$ctx['fp_build_window_days'] = $plan['meta']['window_days'] ?? 45;
			}
		} catch (Throwable $e) {}
	}

	// Cash-flow: this month's payments to make (admins only)
	if ($isAdmin) {
		try {
			$data     = build_cashflow_data($db);
			$forecast = build_cashflow_forecast($db, $data, 12, 0.0);
			$events   = load_cash_events($db);
			$md       = build_month_blocks($db, $data, $forecast, $events);
			$cur = null; foreach ($md['blocks'] as $b) { if (empty($b['is_past'])) { $cur = $b; break; } }
			if ($cur) {
				$cardPayments = [];
				foreach ($cur['card_payments'] as $cp) {
					if ($cp['amount'] <= 0) continue;
					$cardPayments[] = ['card' => $cp['label'], 'amount' => round($cp['amount']), 'focus' => !empty($cp['is_target']), 'apr' => $cp['apr']];
				}
				$outItems = [];
				foreach ($cur['cash_out'] as $o) $outItems[] = ['item' => $o['label'], 'amount' => round($o['amount'])];
				$ctx['cash_flow_this_month'] = [
					'month'        => $cur['label'],
					'cash_on_hand' => round((float)$data['eff_cash']),
					'buffer'       => round((float)$md['buffer']),
					'projected_end_cash' => $cur['end_cash'] === null ? null : round($cur['end_cash']),
					'card_payments'=> $cardPayments,
					'cash_out'     => $outItems,
					'advice'       => array_map(fn($a) => $a['text'], $cur['advice'] ?? []),
				];
				$plan = [];
				foreach (array_slice($md['po_card_plan'] ?? [], 0, 5) as $p) $plan[] = ['part' => $p['part'], 'est' => round($p['cost']), 'put_on' => $p['card'] ?: 'needs credit room'];
				$ctx['po_to_card_plan'] = $plan;
			}
		} catch (Throwable $e) { $ctx['cash_flow_error'] = $e->getMessage(); }
	}

	// ── Ask the AI ────────────────────────────────────────────────────────────
	$system =
"You write the WEEKLY welcome message for " . ($name !== '' ? $name : 'the owner') . " of Blue Bird Waterfowl (a small waterfowl motion-decoy manufacturer), shown on their dashboard. You are given a JSON snapshot of what's on their plate.

Write it like you're speaking to them:
- Open with 'Good " . $partOfDay . "' (optionally their first name). One short warm sentence framing the week.
- If has_recent_events is true, add a brief **Nice work** line FIRST that celebrates what just got done — recent_events lists tasks they checked off, payments they made, and deliveries that arrived (name them with amounts/parts). This is the whole point of updating the message on those events, so make it feel acknowledged. If has_recent_events is false, skip this and just give the normal weekly update.
- Then **This week — do now**: genuinely time-sensitive items — overdue/today tasks, cash-flow payments due this month (name the card + amount, note the FOCUS/highest-APR card), and parts that are OUT or must be ordered given lead times.
- Include a **Builds to make** note whenever fp_build_recommendations is non-empty: these are finished animator products at risk of running out on Shopify over the next fp_build_window_days days. For each, say how many units to BUILD (build_units) so it doesn't run out, and how many you can build right now (can_build_now); if short_need_raw > 0, flag that you must order the raw material (limited_by_part) to build the rest. Treat any product with shopify_stock <= 0 (already oversold) as urgent and put it under 'do now'. Never let a product run out — that's the whole point of this section.
- If my_assignments is present, add a **Your assignments** section written directly to this person — these are tasks assigned specifically to them. For each tradeshow_build_pack item, name the show(s), then list each product as 'Product — sold N last year · M on hand · build/pack K' (sold_last_year / on_hand / build_pack); if short_raw > 0, note they must first order the limiting raw material (limit_part) before they can build the rest; then include the action_link as a Markdown link exactly like [Open Packaging](/build.php). For each inventory_count item, tell them to count that part (give part, description and current_qoh) and include count_link as a Markdown link using its url verbatim. Use the EXACT numbers and URLs provided — never invent, round, or alter them. Treat assignments as time-sensitive if their due date is near.
- Then **Coming up**: tasks due later this week, reorders to prepare for, payments to plan.
- If a section has nothing, say something reassuring instead of inventing work.
- Be concrete with numbers and names. Keep the WHOLE thing tight — a glanceable weekly brief, not an essay. Use short Markdown (a heading is optional; bullets are good). Never output JSON or code fences.
- POs are always paid on a real credit card (never cash/LOC) — reflect that if you mention paying POs.";

	$res = anthropic_chat($system . "\n\nSnapshot (JSON):\n" . json_encode($ctx, JSON_UNESCAPED_SLASHES), [['role' => 'user', 'content' => 'Write my briefing.']], 900);
	if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }

	// Render Markdown → minimal HTML (headings, bold, bullets).
	$html = briefing_markdown_to_html($res['text']);

	try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$cacheKey, $html]); } catch (Throwable $e) {}

	echo json_encode(['ok' => true, 'html' => $html, 'cached' => false, 'as_of' => date('Y-m-d H:i:s')]);


	/** Tiny, safe Markdown subset → HTML for the briefing card. */
	function briefing_markdown_to_html($md) {
		$lines = preg_split('/\r?\n/', trim((string)$md));
		$out = ''; $inList = false;
		foreach ($lines as $ln) {
			$t = trim($ln);
			if ($t === '') { if ($inList) { $out .= '</ul>'; $inList = false; } continue; }
			$esc = htmlspecialchars($t, ENT_QUOTES);
			$esc = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $esc);
			$esc = preg_replace('/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/', '<em>$1</em>', $esc);
			if (preg_match('/^#{1,6}\s*(.+)$/', $t, $m)) {
				if ($inList) { $out .= '</ul>'; $inList = false; }
				$htxt = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', htmlspecialchars($m[1], ENT_QUOTES));
				$out .= '<div class="fw-bold mt-2 mb-1" style="font-size:0.85rem;">' . briefing_linkify($htxt) . '</div>';
			} elseif (preg_match('/^[-*]\s+(.+)$/', $t, $m)) {
				if (!$inList) { $out .= '<ul class="mb-1 ps-3">'; $inList = true; }
				$item = htmlspecialchars($m[1], ENT_QUOTES);
				$item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
				$out .= '<li>' . briefing_linkify($item) . '</li>';
			} else {
				if ($inList) { $out .= '</ul>'; $inList = false; }
				$out .= '<div class="mb-1">' . briefing_linkify($esc) . '</div>';
			}
		}
		if ($inList) $out .= '</ul>';
		return $out;
	}


	/** Convert [text](url) → <a> for the briefing. Input is already HTML-escaped;
	 *  only site-relative (/…) or http(s) URLs are allowed. */
	function briefing_linkify($s) {
		return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
			$url = html_entity_decode($m[2], ENT_QUOTES);
			if (!preg_match('#^(/(?!/)|https?://)#i', $url)) return $m[0];
			return '<a href="' . $m[2] . '" style="text-decoration:underline;">' . $m[1] . '</a>';
		}, $s);
	}