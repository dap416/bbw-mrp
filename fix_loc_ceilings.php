<?php
/**
 * One-time cleanup of the loc_ceilings setting: keep ONLY the line-of-credit facilities that a
 * current LOC loan actually draws on (matched by cash_balances.loc_name), and drop orphaned or
 * duplicate entries. Those orphans (left by old setups + earlier popup edits that clobbered
 * loc_name) inflated "available credit" because load_manual_balances sums every ceiling entry.
 * De-dups kept facilities by name (keeping the largest ceiling). Idempotent. Admin/master only.
 */
require_once(__DIR__."/includes/fns.php");
require_once(__DIR__."/includes/cashflow.php");   // db_connect, setting_get/set live here via shopify.php
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true) && !is_owner()) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();

// Facilities actually referenced by a LOC loan (by loc_name), case-insensitive.
$used = [];
foreach ($db->query("SELECT DISTINCT loc_name FROM cash_balances WHERE acct_type = 'loc' AND loc_name IS NOT NULL AND TRIM(loc_name) <> ''") as $r) {
	$n = trim((string)$r['loc_name']);
	if ($n !== '') $used[strtolower($n)] = $n;
}

// Current loc_ceilings.
$before = [];
$raw = setting_get($db, 'loc_ceilings');
if ($raw) { $dec = json_decode($raw, true); if (is_array($dec)) $before = $dec; }

$keptByName = []; $removed = [];
foreach ($before as $c) {
	$name = trim((string)($c['name'] ?? ''));
	$ceil = max(0.0, (float)($c['ceiling'] ?? 0));
	$k = strtolower($name);
	if ($name !== '' && isset($used[$k])) {
		// Keep, de-duping by name and keeping the largest ceiling seen.
		if (!isset($keptByName[$k]) || $ceil > $keptByName[$k]['ceiling']) $keptByName[$k] = ['name' => $used[$k], 'ceiling' => $ceil];
	} else {
		$removed[] = ['name' => $name, 'ceiling' => $ceil];
	}
}
$final = array_values($keptByName);

$beforeTotal = array_sum(array_map(fn($c) => max(0.0, (float)($c['ceiling'] ?? 0)), $before));
$afterTotal  = array_sum(array_map(fn($c) => (float)$c['ceiling'], $final));

setting_set($db, 'loc_ceilings', json_encode($final));

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-3">Line-of-credit ceilings — cleaned up ✓</h2>
<div class="card" style="max-width:680px;"><div class="card-body">
	<p>Pruned the shared <code>loc_ceilings</code> setting to the facilities your loans actually draw on.
		Total ceiling went from <strong>$<?php echo number_format($beforeTotal); ?></strong>
		to <strong style="color:#2ca01c;">$<?php echo number_format($afterTotal); ?></strong>.</p>

	<h6 class="fw-bold mt-3">Kept (facilities in use)</h6>
	<?php if ($final): ?>
	<ul class="mb-2">
		<?php foreach ($final as $c): ?><li><?php echo htmlspecialchars($c['name']); ?> — $<?php echo number_format($c['ceiling']); ?></li><?php endforeach; ?>
	</ul>
	<?php else: ?><p class="text-muted">No facilities in use.</p><?php endif; ?>

	<?php if ($removed): ?>
	<h6 class="fw-bold mt-3 text-danger">Removed (orphaned / no loan draws on them)</h6>
	<ul class="mb-2">
		<?php foreach ($removed as $c): ?><li><?php echo htmlspecialchars($c['name'] !== '' ? $c['name'] : '(unnamed)'); ?> — $<?php echo number_format($c['ceiling']); ?></li><?php endforeach; ?>
	</ul>
	<p class="text-muted small">If any of these was a real, untapped line of credit you want to keep, re-add its ceiling in Planning settings (or add a loan on it).</p>
	<?php else: ?><p class="text-muted">Nothing to remove — the setting was already clean.</p><?php endif; ?>

	<a href="/cash_flow.php" class="btn btn-sm btn-primary mt-2">Open Cash Flow</a>
	<a href="/cashflow.php" class="btn btn-sm btn-light mt-2">Open Cash Management</a>
</div></div>
<?php require_once(__DIR__."/includes/footer.php"); ?>
