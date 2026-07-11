<?php
/**
 * FP stock diagnostic (read-only). Shows, for a Shopify SKU, the inventory reported by
 * EVERY location (available / committed / on-hand), plus the variant total, so we can see
 * exactly why "Have" in Research differs from a single-warehouse figure. Admin / master only.
 */
require_once(__DIR__."/includes/fns.php");
require_once(__DIR__."/includes/shopify.php");
require_login();

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'master'], true) && !is_owner()) { http_response_code(403); echo 'Admins only.'; exit; }

$sku = trim($_GET['sku'] ?? 'CASE-HRD');

$rows = []; $variantQty = null; $err = null;
if ($sku !== '' && shopify_is_configured()) {
	$q = 'query($q: String!) { productVariants(first: 10, query: $q) { edges { node {
		sku title inventoryQuantity
		product { title }
		inventoryItem { inventoryLevels(first: 50) { edges { node {
			location { id name }
			quantities(names: ["available","committed","on_hand","incoming","reserved","damaged"]) { name quantity }
		} } } }
	} } } }';
	$res = shopify_graphql($q, ['q' => 'sku:' . $sku]);
	if (!empty($res['error'])) { $err = $res['error']; }
	else {
		foreach (($res['data']['productVariants']['edges'] ?? []) as $ve) {
			$v = $ve['node'];
			if (strcasecmp(trim((string)($v['sku'] ?? '')), $sku) !== 0) continue;
			$variantQty = (int)($v['inventoryQuantity'] ?? 0);
			foreach (($v['inventoryItem']['inventoryLevels']['edges'] ?? []) as $le) {
				$n = $le['node']; $qm = [];
				foreach (($n['quantities'] ?? []) as $qn) $qm[$qn['name']] = (int)($qn['quantity'] ?? 0);
				$rows[] = ['loc' => $n['location']['name'] ?? '?', 'id' => $n['location']['id'] ?? '',
				           'available' => $qm['available'] ?? 0, 'committed' => $qm['committed'] ?? 0,
				           'on_hand' => $qm['on_hand'] ?? 0, 'incoming' => $qm['incoming'] ?? 0];
			}
		}
	}
}

$oregonId = shopify_oregon_location_id();
$sumAvail = array_sum(array_map(fn($r) => $r['available'], $rows));
$sumOnHand = array_sum(array_map(fn($r) => $r['on_hand'], $rows));
$sumComm  = array_sum(array_map(fn($r) => $r['committed'], $rows));

require_once(__DIR__."/includes/header.php");
?>
<h2 class="fw-bold mb-1">FP stock diagnostic</h2>
<p class="text-muted">Per-location inventory for one SKU — to see exactly what "Have" in Research is summing.</p>

<form method="get" class="d-flex align-items-center gap-2 mb-3">
	<label class="fw-semibold small">SKU:</label>
	<input type="text" name="sku" value="<?php echo htmlspecialchars($sku, ENT_QUOTES); ?>" class="form-control form-control-sm" style="width:220px;">
	<button class="btn btn-sm btn-primary">Look up</button>
</form>

<?php if ($err): ?><div class="alert alert-danger">Shopify error: <?php echo htmlspecialchars($err); ?></div><?php endif; ?>
<?php if (!$err && empty($rows)): ?><div class="alert alert-warning">No inventory levels found for <code><?php echo htmlspecialchars($sku); ?></code>.</div><?php endif; ?>

<?php if (!empty($rows)): ?>
<div class="card mb-3"><div class="card-body">
	<table class="table table-sm align-middle mb-0">
		<thead><tr style="background:#f1f3f5;"><th>Location</th><th class="text-end">Available</th><th class="text-end">Committed</th><th class="text-end">On hand</th><th class="text-end">Incoming</th><th></th></tr></thead>
		<tbody>
		<?php foreach ($rows as $r): $isOre = $oregonId !== '' && $r['id'] === $oregonId; ?>
			<tr>
				<td class="fw-semibold"><?php echo htmlspecialchars($r['loc']); ?> <?php echo $isOre ? '<span class="badge bg-info text-dark" style="font-size:0.5rem;">OREGON</span>' : ''; ?></td>
				<td class="text-end"><?php echo $r['available']; ?></td>
				<td class="text-end text-muted"><?php echo $r['committed']; ?></td>
				<td class="text-end text-muted"><?php echo $r['on_hand']; ?></td>
				<td class="text-end text-muted"><?php echo $r['incoming']; ?></td>
				<td class="small text-muted" style="font-size:0.66rem;"><?php echo htmlspecialchars($r['id']); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
		<tfoot><tr class="fw-bold border-top">
			<td>TOTAL (all locations)</td>
			<td class="text-end" style="color:#e64545;"><?php echo $sumAvail; ?></td>
			<td class="text-end"><?php echo $sumComm; ?></td>
			<td class="text-end"><?php echo $sumOnHand; ?></td>
			<td colspan="2"></td>
		</tr></tfoot>
	</table>
</div></div>

<div class="card"><div class="card-body">
	<div class="mb-1">Variant <code>inventoryQuantity</code> (Shopify's total sellable): <strong><?php echo $variantQty === null ? '—' : $variantQty; ?></strong></div>
	<div class="mb-1">Sum of <strong>available</strong> across all locations (what Research "Have" currently uses): <strong style="color:#e64545;"><?php echo $sumAvail; ?></strong></div>
	<div class="text-muted small">If this total is higher than the one warehouse you expect, one or more of the locations above (e.g. an Amazon/FBA or 3PL location) is being counted. Tell me which location(s) should count as real FP stock and I'll scope "Have" to just those.</div>
</div></div>
<?php endif; ?>

<?php require_once(__DIR__."/includes/footer.php"); ?>
