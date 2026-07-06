<?php
/**
 * Charles's written briefing — a plain-English state-of-the-business analysis.
 * Cached ~24h in data_cache ('charles_brief'); ?refresh=1 (or a data sync) regenerates.
 * OWNER ONLY.
 */
require_once(__DIR__."/../../includes/fns.php");
require_once(__DIR__."/../../includes/charles.php");
require_login();
header('Content-Type: application/json');

if (!is_owner()) { http_response_code(403); echo json_encode(['error' => 'Private.']); exit; }

$db = db_connect();
$db->exec("CREATE TABLE IF NOT EXISTS data_cache (ckey VARCHAR(64) PRIMARY KEY, cval LONGTEXT, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB");

$force = !empty($_POST['refresh']) || !empty($_GET['refresh']);
$key   = 'charles_brief';

if (!$force) {
	try {
		$s = $db->prepare("SELECT cval, updated_at FROM data_cache WHERE ckey = ?"); $s->execute([$key]);
		if ($row = $s->fetch()) {
			if ((time() - strtotime($row['updated_at'])) < 86400) {
				echo json_encode(['ok' => true, 'text' => $row['cval'], 'as_of' => $row['updated_at'], 'cached' => true]); exit;
			}
		}
	} catch (Throwable $e) {}
}

if (!anthropic_is_configured()) { echo json_encode(['error' => 'AI is not configured — add an Anthropic key on Integrations.']); exit; }

$snap   = charles_snapshot($db);
$system = charles_system_prompt($db, $snap);
$ask =
"Write my financial briefing for today. Plain English, skimmable, short sections with clear headers (use '## ' for headers and '- ' for bullets). Cover:
1. Where we stand right now — cash in the bank, card room, the line of credit, what we owe and what we're owed, in one tight paragraph.
2. Cash runway — are we safe? If not, which month gets tight and why, and the fix.
3. The 2-4 most important money moves right now, ranked, each with the dollar impact — explicitly consider using the low-rate line of credit to relieve the highest-APR cards (show the interest saved).
4. What to build/order next and how to pay for it (which card, or hold).
End with one line: the single most important thing to do this week.
Do NOT include any JSON or propose tasks here — this is just the written read.";

$res = anthropic_message($system, $ask, 2500);
if (!empty($res['error'])) { echo json_encode(['error' => $res['error']]); exit; }
$text = trim((string)$res['text']);

try { $db->prepare("INSERT INTO data_cache (ckey,cval,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE cval=VALUES(cval), updated_at=NOW()")->execute([$key, $text]); } catch (Throwable $e) {}
charles_memory_append($db, 'briefing', 'Refreshed briefing.');

echo json_encode(['ok' => true, 'text' => $text, 'as_of' => date('Y-m-d H:i:s'), 'cached' => false]);
