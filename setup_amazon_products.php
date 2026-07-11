<?php
/**
 * Create an [Amazon] twin of every animator product.
 *
 * For each animator (a product that has a BOM), this makes a duplicate named
 * "<name> [Amazon]" with the SAME bill of materials, EXCEPT the standard packaging
 * card (CD-<brand>) is swapped for the matching Amazon card (CDA-<brand>). Everything
 * else — cams, plates, rods, packaging — stays identical.
 *
 * Safe to run repeatedly: an animator that already has an [Amazon] twin is skipped.
 * Shows a PREVIEW first; nothing is written until you click Apply. Admin / master only.
 */
require_once(__DIR__."/includes/fns.php");
require_once(__DIR__."/includes/planning.php");   // column_exists(), is_amazon_product()
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true) && !is_owner()) { http_response_code(403); echo 'Admins only.'; exit; }

$db = db_connect();
$apply = ($_POST['apply'] ?? '') === '1';
$hasSku = column_exists($db, 'products', 'shopify_sku');

// ── Load parts (partno <-> id) and every product's BOM ───────────────────────
$partNoById = [];   // id => partno
$partIdByNo = [];   // lower(partno) => id
foreach ($db->query("SELECT id, partno FROM parts") as $r) {
	$partNoById[(int)$r['id']] = $r['partno'];
	$partIdByNo[strtolower(trim((string)$r['partno']))] = (int)$r['id'];
}

$products = $db->query("SELECT id, name" . ($hasSku ? ", shopify_sku" : "") . " FROM products ORDER BY name ASC")->fetchAll();
$existingNames = [];
foreach ($products as $p) $existingNames[strtolower(trim($p['name']))] = true;

$bomByProd = [];   // prodid => [ [partid,qty], ... ]
foreach ($db->query("SELECT prodid, partid, qty FROM build ORDER BY prodid, partid") as $b) {
	$bomByProd[(int)$b['prodid']][] = ['partid' => (int)$b['partid'], 'qty' => (int)$b['qty']];
}

/**
 * Candidate Amazon-card part numbers for a standard CD- card, most-specific first.
 * The naming isn't perfectly uniform: 'CD-LD' => 'CDA-LD', but 'CD-AX-L' => 'CDA-AXL'
 * (the inner hyphen is dropped). We generate both forms and match whichever exists.
 */
function amazon_card_candidates($partno) {
	if (!preg_match('/^CD-/i', $partno) || preg_match('/^CDA-/i', $partno)) return [];
	$rest = substr($partno, 3);                       // text after "CD-"
	$c = [
		'CDA-' . $rest,                               // CD-LD -> CDA-LD ; CD-AX-L -> CDA-AX-L
		'CDA-' . str_replace('-', '', $rest),         // CD-AX-L -> CDA-AXL  (hyphen dropped)
		'CDA' . $rest,                                // rare: CD-LD -> CDALD
	];
	return array_values(array_unique($c));
}

// ── Build the plan (what would be / was created) ─────────────────────────────
$plan = [];
foreach ($products as $p) {
	$pid  = (int)$p['id'];
	$name = trim($p['name']);
	if (is_amazon_product($name)) continue;                       // already an Amazon variant
	$bom = $bomByProd[$pid] ?? [];
	if (empty($bom)) continue;                                    // not an animator (no BOM)

	$twin        = $name . ' [Amazon]';
	$twinExists  = isset($existingNames[strtolower($twin)]);
	$sku         = $hasSku ? trim((string)($p['shopify_sku'] ?? '')) : '';
	$swaps = [];        // partid => new partid (card swaps)
	$cardLines = [];    // for display
	$missing = [];
	foreach ($bom as $line) {
		$pn = $partNoById[$line['partid']] ?? '';
		$cands = amazon_card_candidates($pn);
		if (empty($cands)) continue;                              // not a standard card
		$amzId = null; $amzPn = null;
		foreach ($cands as $c) { if (isset($partIdByNo[strtolower($c)])) { $amzId = $partIdByNo[strtolower($c)]; $amzPn = $c; break; } }
		if ($amzId) { $swaps[$line['partid']] = $amzId; $cardLines[] = ['from' => $pn, 'to' => $amzPn]; }
		else { $missing[] = $pn; $cardLines[] = ['from' => $pn, 'to' => implode(' / ', $cands) . ' (NOT FOUND)']; }
	}

	$plan[] = [
		'id' => $pid, 'name' => $name, 'twin' => $twin, 'sku' => $sku, 'exists' => $twinExists,
		'bom_count' => count($bom), 'card_lines' => $cardLines, 'missing' => $missing,
		'bom' => $bom, 'swaps' => $swaps,
	];
}

// ── Apply ────────────────────────────────────────────────────────────────────
$created = 0; $errors = [];
if ($apply) {
	$insP = $hasSku
		? $db->prepare("INSERT INTO products (name, shopify_sku) VALUES (?, ?)")
		: $db->prepare("INSERT INTO products (name) VALUES (?)");
	$insB = $db->prepare("INSERT INTO build (prodid, partid, qty) VALUES (?, ?, ?)");
	foreach ($plan as $row) {
		if ($row['exists']) continue;                             // idempotent
		if (!empty($row['missing'])) { $errors[] = $row['name'] . ' — missing Amazon card: ' . implode(', ', $row['missing']); continue; }
		try {
			$db->beginTransaction();
			if ($hasSku) $insP->execute([$row['twin'], $row['sku'] !== '' ? $row['sku'] : null]);
			else         $insP->execute([$row['twin']]);
			$newId = (int)$db->lastInsertId();
			foreach ($row['bom'] as $line) {
				$partid = $row['swaps'][$line['partid']] ?? $line['partid'];
				$insB->execute([$newId, $partid, $line['qty']]);
			}
			$db->commit();
			$created++;
		} catch (Throwable $e) {
			if ($db->inTransaction()) $db->rollBack();
			$errors[] = $row['name'] . ' — ' . $e->getMessage();
		}
	}
}

// ── Tallies for the summary ──────────────────────────────────────────────────
$toCreate = 0; $already = 0; $noCard = 0; $missingCard = 0;
foreach ($plan as $row) {
	if ($row['exists']) { $already++; continue; }
	if (!empty($row['missing'])) { $missingCard++; continue; }
	if (empty($row['card_lines'])) $noCard++;
	$toCreate++;
}

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-1">Animator → [Amazon] product builder</h2>
<p class="text-muted">Creates an <strong>[Amazon]</strong> twin of each animator with the same BOM, swapping the standard <code>CD-</code> packaging card for the matching <code>CDA-</code> Amazon card.</p>

<?php if ($apply): ?>
<div class="alert alert-<?php echo $created ? 'success' : 'info'; ?>">
	<strong><?php echo (int)$created; ?></strong> [Amazon] product<?php echo $created === 1 ? '' : 's'; ?> created.
	<?php if ($errors): ?><div class="mt-2 small"><strong>Skipped with errors:</strong><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
	<div class="mt-2"><a href="/products.php" class="btn btn-sm btn-primary">Open Products</a> <a href="/setup_amazon_products.php" class="btn btn-sm btn-light">Re-run preview</a></div>
</div>
<?php endif; ?>

<div class="card mb-3"><div class="card-body py-2 d-flex flex-wrap gap-4">
	<div><div class="text-muted small text-uppercase">Animators</div><div class="h5 fw-bold mb-0"><?php echo count($plan); ?></div></div>
	<div><div class="text-muted small text-uppercase"><?php echo $apply ? 'Were creatable' : 'Will create'; ?></div><div class="h5 fw-bold mb-0" style="color:#2ca01c;"><?php echo (int)$toCreate; ?></div></div>
	<div><div class="text-muted small text-uppercase">Already have twin</div><div class="h5 fw-bold mb-0 text-muted"><?php echo (int)$already; ?></div></div>
	<div><div class="text-muted small text-uppercase">Missing Amazon card</div><div class="h5 fw-bold mb-0" style="color:<?php echo $missingCard ? '#e64545' : '#6c757d'; ?>;"><?php echo (int)$missingCard; ?></div></div>
</div></div>

<?php if (!$apply && $toCreate > 0): ?>
<form method="post" class="mb-3" onsubmit="return confirm('Create <?php echo (int)$toCreate; ?> [Amazon] product(s)?');">
	<input type="hidden" name="apply" value="1">
	<button class="btn btn-success"><i class="ti ti-check me-1"></i>Apply — create <?php echo (int)$toCreate; ?> [Amazon] product<?php echo $toCreate === 1 ? '' : 's'; ?></button>
	<span class="text-muted small ms-2">Nothing is written until you click this.</span>
</form>
<?php endif; ?>

<div class="card"><div class="card-body p-0">
<table class="table table-sm align-middle mb-0">
	<thead><tr style="background:#f1f3f5;">
		<th>Animator</th><th>→ New product</th><th>SKU (follows)</th><th>Card swap</th><th class="text-center">BOM lines</th><th class="text-center">Status</th>
	</tr></thead>
	<tbody>
	<?php foreach ($plan as $row): ?>
		<tr>
			<td class="fw-semibold"><?php echo htmlspecialchars($row['name']); ?></td>
			<td><?php echo htmlspecialchars($row['twin']); ?></td>
			<td class="small"><?php echo $row['sku'] !== '' ? '<code>'.htmlspecialchars($row['sku']).'</code>' : '<span class="text-muted">— not linked —</span>'; ?></td>
			<td class="small">
				<?php if (empty($row['card_lines'])): ?><span class="text-muted">— no CD- card in BOM —</span>
				<?php else: foreach ($row['card_lines'] as $c): ?>
					<div><code><?php echo htmlspecialchars($c['from']); ?></code> → <code<?php echo strpos($c['to'],'NOT FOUND')!==false ? ' style="color:#e64545;"' : ''; ?>><?php echo htmlspecialchars($c['to']); ?></code></div>
				<?php endforeach; endif; ?>
			</td>
			<td class="text-center text-muted"><?php echo (int)$row['bom_count']; ?></td>
			<td class="text-center">
				<?php if ($row['exists']): ?><span class="badge bg-secondary">already exists</span>
				<?php elseif (!empty($row['missing'])): ?><span class="badge bg-danger">missing card</span>
				<?php else: ?><span class="badge bg-success"><?php echo $apply ? 'created' : 'will create'; ?></span><?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	<?php if (empty($plan)): ?><tr><td colspan="6" class="text-muted text-center py-3">No animator products found.</td></tr><?php endif; ?>
	</tbody>
</table>
</div></div>

<?php require_once(__DIR__."/includes/footer.php"); ?>
