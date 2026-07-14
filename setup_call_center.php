<?php
/**
 * One-time setup for the Call Center.
 *   1. Adds the users.access_call_center permission column (DEFAULT 0 — nobody gets it).
 *   2. Creates the call_tickets table.
 *   3. Grants Edit to Sarah only. Admin/master already bypass the area check.
 * Idempotent — safe to run more than once.
 */
require_once(__DIR__.'/includes/fns.php');
require_login();
if (($_SESSION['user_role'] ?? '') !== 'master') { http_response_code(403); exit('Master admins only.'); }

$db  = db_connect();
$log = [];

function cc_run($db, $sql, &$log, $desc) {
	try { $db->exec($sql); $log[] = ['ok', $desc]; }
	catch (Throwable $e) {
		$msg = $e->getMessage();
		// A duplicate column just means this already ran — that is expected and safe.
		$dup = stripos($msg, 'duplicate') !== false;
		$log[] = [$dup ? 'skip' : 'warn', $desc . ($dup ? ' — already there' : ' — ' . $msg)];
	}
}

// 1 — permission column. DEFAULT 0 = no access, so every existing user is auto-denied
//     and the Call Center checkbox starts unticked for everyone.
cc_run($db, "ALTER TABLE `users` ADD COLUMN `access_call_center` TINYINT NOT NULL DEFAULT 0",
	$log, "Add the access_call_center permission (defaults to No access for every user)");

// 2 — the ticket table.
try { call_center_ensure_tables($db); $log[] = ['ok', 'Create the call_tickets table']; }
catch (Throwable $e) { $log[] = ['warn', 'Create the call_tickets table — ' . $e->getMessage()]; }

// 3 — grant Sarah, and only Sarah. Admin/master never need the column (access_level()
//     returns 2 for them regardless), so we deliberately do NOT set it for anyone else.
$granted = [];
try {
	$sarah = $db->query("SELECT id, name, role FROM users WHERE active = 1 AND (name LIKE 'Sarah%' OR username LIKE 'sarah%')")->fetchAll();
	foreach ($sarah as $u) {
		$db->prepare("UPDATE users SET access_call_center = 2 WHERE id = ?")->execute([(int)$u['id']]);
		$granted[] = $u['name'];
	}
	if ($granted) $log[] = ['ok', 'Grant Call Center (Edit) to: ' . implode(', ', $granted)];
	else          $log[] = ['warn', 'No active user whose name starts with "Sarah" was found — grant it by hand in User Management → Manage → Site Access.'];
} catch (Throwable $e) {
	$log[] = ['warn', 'Could not grant Sarah automatically — ' . $e->getMessage()];
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Call Center setup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<div class="container" style="max-width:720px;margin-top:48px;">
	<h3 class="fw-bold mb-1">Call Center setup</h3>
	<p class="text-muted">Run once. Safe to re-run.</p>
	<ul class="list-group mb-4">
		<?php foreach ($log as [$kind, $msg]): ?>
		<li class="list-group-item">
			<span class="me-2"><?php echo $kind === 'ok' ? '✅' : ($kind === 'skip' ? '⏭️' : '⚠️'); ?></span>
			<?php echo htmlspecialchars($msg); ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<div class="alert alert-info">
		<strong>Everyone else is denied by default.</strong> The Call Center only appears for Sarah and
		Admin/Master users. To give it to somebody else, go to
		<a href="/users.php">User Management</a> → <em>Manage</em> → <em>Site Access</em>.
		<div class="mt-2 small">Anyone already signed in must sign out and back in before a new permission takes effect.</div>
	</div>
	<a href="/call_center.php" class="btn btn-primary">Open the Call Center</a>
	<a href="/users.php" class="btn btn-light">User Management</a>
</div>
