<?php
/**
 * Reassign the manufacturer of ALL packaging cards (CD-* and CDA-*) and ALL clamshell
 * packaging to "Cassie" (Stardeal Plastics). Shows a PREVIEW first — nothing is written
 * until you click Apply. Admin / master only. Safe to re-run.
 */
require_once(__DIR__."/includes/fns.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true) && !is_owner()) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
$apply = ($_POST['apply'] ?? '') === '1';

// ── Find the target manufacturer ("Cassie") ──────────────────────────────────
$cassie = null;
foreach ($db->query("SELECT id, name FROM manufacturers WHERE name LIKE '%Cassie%' ORDER BY name ASC") as $m) { $cassie = $m; break; }

// Manufacturer id => name (to show each part's current manufacturer).
$mfgName = [0 => '— none —'];
foreach ($db->query("SELECT id, name FROM manufacturers") as $m) $mfgName[(int)$m['id']] = $m['name'];

// ── The parts to reassign: packaging cards + clamshell packaging ─────────────
// Cards: part number starts CD- or CDA-.  Clamshell: "clamshell" in the description.
$sql = "SELECT id, partno, `desc`, manufacturer, supplier,
               CASE WHEN partno REGEXP '^CDA?-' THEN 'Packaging Cards' ELSE 'Clamshell Packaging' END AS grp
        FROM parts
        WHERE partno REGEXP '^CDA?-' OR LOWER(`desc`) LIKE '%clamshell%'
        ORDER BY grp, partno ASC";
$parts = $db->query($sql)->fetchAll();

// ── Apply ────────────────────────────────────────────────────────────────────
$updated = 0; $err = null;
if ($apply && $cassie) {
	try {
		$ids = array_map(fn($p) => (int)$p['id'], $parts);
		if ($ids) {
			$in = implode(',', array_fill(0, count($ids), '?'));
			$stmt = $db->prepare("UPDATE parts SET manufacturer = ? WHERE id IN ($in)");
			$stmt->execute(array_merge([(int)$cassie['id']], $ids));
			$updated = $stmt->rowCount();
		}
	} catch (Throwable $e) { $err = $e->getMessage(); }
}

$cards = array_values(array_filter($parts, fn($p) => $p['grp'] === 'Packaging Cards'));
$clams = array_values(array_filter($parts, fn($p) => $p['grp'] === 'Clamshell Packaging'));

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-1">Reassign packaging manufacturer → Cassie</h2>
<p class="text-muted">All packaging <strong>cards</strong> (CD-* / CDA-*) and all <strong>clamshell</strong> packaging will be set to the Cassie (Stardeal Plastics) manufacturer.</p>

<?php if (!$cassie): ?>
<div class="alert alert-danger">No manufacturer matching <strong>Cassie</strong> was found. <a href="/manufacturers.php">Add it on the Manufacturers page</a>, then re-open this.</div>
<?php require_once(__DIR__."/includes/footer.php"); exit; endif; ?>

<?php if ($apply): ?>
<div class="alert alert-<?php echo $err ? 'danger' : 'success'; ?>">
	<?php if ($err): ?>Update failed: <?php echo htmlspecialchars($err); ?>
	<?php else: ?><strong><?php echo (int)$updated; ?></strong> part<?php echo $updated === 1 ? '' : 's'; ?> set to <strong><?php echo htmlspecialchars($cassie['name']); ?></strong>.<?php endif; ?>
	<div class="mt-2"><a href="/setup_cassie_cards.php" class="btn btn-sm btn-light">Re-check</a></div>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-4">
	<div><div class="text-muted small text-uppercase">Target manufacturer</div><div class="h6 fw-bold mb-0"><?php echo htmlspecialchars($cassie['name']); ?> <span class="text-muted small">#<?php echo (int)$cassie['id']; ?></span></div></div>
	<div><div class="text-muted small text-uppercase">Packaging cards</div><div class="h6 fw-bold mb-0"><?php echo count($cards); ?></div></div>
	<div><div class="text-muted small text-uppercase">Clamshell packaging</div><div class="h6 fw-bold mb-0"><?php echo count($clams); ?></div></div>
</div></div>

<?php if (!$apply && $parts): ?>
<form method="post" class="mb-3" onsubmit="return confirm('Set <?php echo count($parts); ?> part(s) to <?php echo htmlspecialchars($cassie['name'], ENT_QUOTES); ?>?');">
	<input type="hidden" name="apply" value="1">
	<button class="btn btn-success"><i class="ti ti-check me-1"></i>Apply — set <?php echo count($parts); ?> part<?php echo count($parts) === 1 ? '' : 's'; ?> to Cassie</button>
	<span class="text-muted small ms-2">Nothing is written until you click this.</span>
</form>
<?php endif; ?>

<?php foreach ([['Packaging Cards', $cards], ['Clamshell Packaging', $clams]] as [$label, $list]): ?>
<h5 class="fw-semibold mt-3 mb-2"><?php echo $label; ?> <span class="text-muted small">(<?php echo count($list); ?>)</span></h5>
<div class="card"><div class="card-body p-0">
<table class="table table-sm align-middle mb-0">
	<thead><tr style="background:#f1f3f5;"><th>Part</th><th>Description</th><th>Current manufacturer</th><th></th></tr></thead>
	<tbody>
	<?php foreach ($list as $p): $cur = $mfgName[(int)$p['manufacturer']] ?? ('#'.$p['manufacturer']); ?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($p['partno']); ?></td>
			<td class="small text-muted"><?php echo htmlspecialchars($p['desc']); ?></td>
			<td class="small"><?php echo htmlspecialchars($cur); ?><?php echo trim((string)$p['supplier']) !== '' ? ' <span class="text-muted">('.htmlspecialchars($p['supplier']).')</span>' : ''; ?></td>
			<td class="small text-muted">→ <?php echo htmlspecialchars($cassie['name']); ?></td>
		</tr>
	<?php endforeach; ?>
	<?php if (!$list): ?><tr><td colspan="4" class="text-muted text-center py-3">None matched.</td></tr><?php endif; ?>
	</tbody>
</table>
</div></div>
<?php endforeach; ?>

<p class="text-muted small mt-3">Clamshells are matched by the word &ldquo;clamshell&rdquo; in the part description. If some of yours use a different name/part-number pattern and aren't listed above, tell me the pattern and I'll widen the match.</p>

<?php require_once(__DIR__."/includes/footer.php"); ?>
