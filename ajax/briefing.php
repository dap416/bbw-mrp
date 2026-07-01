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
	if (!$force) {
		try {
			$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$cacheKey]);
			$row = $s->fetch();
			if ($row && (time() - strtotime($row['updated_at'])) < 7200) {
				echo json_encode(['ok' => true, 'html' => $row['cval'], 'cached' => true, 'as_of' => $row['updated_at']]);
				exit;
			}
		} catch (Throwable $e) {}
	}

	// ── Gather context ────────────────────────────────────────────────────────
	$ctx = ['now' => date('Y-m-d H:i'), 'weekday' => date('l'), 'part_of_day' => $partOfDay, 'user' => $name];

	// Open tasks (with days-until-due)
	try {
		tasks_ensure_table($db);
		$tasks = [];
		foreach ($db->query("SELECT title, due_date FROM tasks WHERE completed = 0 ORDER BY (due_date IS NULL), due_date ASC LIMIT 40") as $t) {
			$due  = ($t['due_date'] && $t['due_date'] !== '0000-00-00') ? $t['due_date'] : null;
			$days = $due ? (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400) : null;
			$tasks[] = ['title' => $t['title'], 'due' => $due, 'days_until_due' => $days];
		}
		$ctx['open_tasks'] = $tasks;
	} catch (Throwable $e) { $ctx['open_tasks'] = []; }

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
"You write a short, friendly daily briefing for " . ($name !== '' ? $name : 'the owner') . " of Blue Bird Waterfowl (a small waterfowl motion-decoy manufacturer). You are shown a JSON snapshot of what's on their plate.

Write it like you're speaking to them:
- Open with 'Good " . $partOfDay . "' (optionally their first name). One short warm sentence.
- Then a **Do now** section: only genuinely time-sensitive items — overdue/today tasks, cash-flow payments due this month (name the card + amount, note the FOCUS/highest-APR card), and parts that are OUT or must be ordered given lead times.
- Then a **Coming up** section: tasks due in the next several days, reorders to prepare for, payments to plan.
- If a section has nothing, say something reassuring instead of inventing work.
- Be concrete with numbers and names. Keep the WHOLE thing tight — a glanceable brief, not an essay. Use short Markdown (a heading is optional; bullets are good). Never output JSON or code fences.
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
				$out .= '<div class="fw-bold mt-2 mb-1" style="font-size:0.85rem;">' . $htxt . '</div>';
			} elseif (preg_match('/^[-*]\s+(.+)$/', $t, $m)) {
				if (!$inList) { $out .= '<ul class="mb-1 ps-3">'; $inList = true; }
				$item = htmlspecialchars($m[1], ENT_QUOTES);
				$item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $item);
				$out .= '<li>' . $item . '</li>';
			} else {
				if ($inList) { $out .= '</ul>'; $inList = false; }
				$out .= '<div class="mb-1">' . $esc . '</div>';
			}
		}
		if ($inList) $out .= '</ul>';
		return $out;
	}
