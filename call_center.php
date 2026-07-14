<?php
	require_once(__DIR__."/includes/fns.php");
	require_login();
	if (!has_access('call_center')) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}
	require_once(__DIR__."/includes/shopify.php");
	require_once(__DIR__."/includes/header.php");

	$db = db_connect();
	call_center_ensure_tables($db);

	$canEdit   = can_edit('call_center');
	$isManager = in_array($_SESSION['user_role'] ?? '', ['admin', 'master'], true);
	$shopOk    = shopify_is_configured();
	$agents    = active_users_list($db);
?>

<style>
/* Call Center — big targets, low reading load. Sarah is typing while someone talks. */
.cc-search      { font-size:1.05rem; padding:14px 16px; }
.cc-hit         { cursor:pointer; border:1px solid #e3e6ea; border-radius:10px; padding:10px 12px; transition:.12s; background:#fff; }
.cc-hit:hover   { border-color:#4680ff; background:#f5f9ff; }
.cc-hit.sel     { border-color:#4680ff; background:#eef5ff; box-shadow:0 0 0 2px rgba(70,128,255,.15); }
.cc-chip        { cursor:pointer; display:inline-block; border:1px solid #dfe3e8; border-radius:20px;
                  padding:6px 14px; margin:0 6px 6px 0; font-size:0.85rem; background:#fff; transition:.12s; user-select:none; }
.cc-chip:hover  { border-color:#4680ff; color:#2b6ae0; }
.cc-chip.on     { background:#4680ff; border-color:#4680ff; color:#fff; font-weight:600; }
.cc-step        { font-size:0.72rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#8a94a6; margin-bottom:8px; }
.cc-stat        { border-radius:10px; padding:14px 16px; background:#fff; border:1px solid #e9ecef; height:100%; }
.cc-stat .v     { font-size:1.6rem; font-weight:700; line-height:1.1; }
.cc-stat .l     { font-size:0.74rem; color:#8a94a6; text-transform:uppercase; letter-spacing:.05em; }
.cc-row         { cursor:pointer; }
.cc-row:hover   { background:#f7f9fc; }
.cc-ordbox      { border:1px solid #e3e6ea; border-radius:8px; padding:8px 10px; font-size:0.85rem; background:#fbfcfd; }
/* Confirmed caller, collapsed to a single line — keeps the screen on the call itself. */
.cc-caller-bar  { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
                  border:1px solid #cfe0ff; background:#eef5ff; border-radius:10px; padding:10px 14px; }
.cc-caller-bar .who  { font-weight:700; font-size:1rem; }
.cc-caller-bar .meta { color:#5b6673; font-size:0.85rem; }
</style>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
	<div>
		<h2 class="fw-bold mb-0"><i class="ti ti-headset me-1"></i>Call Center</h2>
		<div class="text-muted small">Log every customer call — who rang, what they needed, and whether it's done.</div>
	</div>
	<?php if (!$shopOk): ?>
	<span class="badge bg-secondary" style="font-size:0.75rem;padding:6px 10px;">Shopify not connected — lookup off</span>
	<?php endif; ?>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
	<?php if ($canEdit): ?>
	<li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-new" type="button"><i class="ti ti-phone-plus me-1"></i>Log a Call</button></li>
	<?php endif; ?>
	<li class="nav-item"><button class="nav-link <?php echo $canEdit ? '' : 'active'; ?>" data-bs-toggle="tab" data-bs-target="#pane-log" type="button"><i class="ti ti-list-details me-1"></i>Call Log</button></li>
</ul>

<div class="tab-content">

<!-- ═══ LOG A CALL ═══════════════════════════════════════════════════════════ -->
<?php if ($canEdit): ?>
<div class="tab-pane fade show active" id="pane-new" role="tabpanel">
<div class="card" style="border-top:3px solid #4680ff;">
<div class="card-body">

	<input type="hidden" id="ccId" value="0">
	<input type="hidden" id="ccCustId" value="">
	<input type="hidden" id="ccOrderId" value="">

	<!-- STEP 0 — the first thing Sarah asks the caller -->
	<div id="ccIntro" class="text-center py-4">
		<div class="fw-bold mb-1" style="font-size:1.35rem;">Have you ordered with us before?</div>
		<div class="text-muted small mb-3">Ask the caller, then pick one.</div>
		<div class="d-flex justify-content-center gap-2 flex-wrap">
			<button id="ccIntroYes" class="btn btn-primary btn-lg px-5"><i class="ti ti-user-check me-1"></i>Yes — look them up</button>
			<button id="ccIntroNew" class="btn btn-outline-primary btn-lg px-5"><i class="ti ti-user-plus me-1"></i>No — new customer</button>
		</div>
	</div>

	<!-- STEP 1 — who's calling (existing customers) -->
	<div id="ccSearchBox" style="display:none;">
		<div class="cc-step">Step 1 · Who's calling?</div>
		<div class="input-group mb-2">
			<span class="input-group-text bg-white"><i class="ti ti-search"></i></span>
			<input type="text" id="ccSearch" class="form-control cc-search"
				placeholder="Start typing a name, phone, email, or order number…" autocomplete="off" <?php echo $shopOk ? '' : 'disabled'; ?>>
			<span class="input-group-text bg-white" id="ccSpin" style="display:none;">
				<span class="spinner-border spinner-border-sm text-primary"></span>
			</span>
		</div>
		<div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
			<span id="ccSearchStatus" class="small text-muted"></span>
			<a href="#" id="ccManual" class="small text-decoration-none"><i class="ti ti-user-plus me-1"></i>Can't find them — enter by hand</a>
		</div>
		<div id="ccResults" class="row g-2 mb-3"></div>
	</div>

	<!-- STEP 2 — the caller (auto-filled, always editable) -->
	<div id="ccCallerBox" style="display:none;">
		<hr>
		<div class="cc-step"><span id="ccCallerStep">Step 2 · The caller</span></div>
		<div id="ccNewNote" class="alert alert-light border py-2 small mb-2" style="display:none;">
			<i class="ti ti-user-plus me-1"></i><strong>New customer.</strong>
			Just take their name and number and what they're after — George will call them back.
		</div>

		<!-- Confirmed customer — collapsed to one line so the call is the focus. -->
		<div id="ccCallerSummary" class="cc-caller-bar mb-2" style="display:none;"></div>

		<div id="ccCallerFields" class="row g-2 mb-2">
			<div class="col-12 col-md-4">
				<label class="form-label small fw-semibold mb-1">Name <span class="text-danger">*</span></label>
				<input id="ccName" class="form-control" placeholder="Who called">
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label small fw-semibold mb-1">Phone <span class="text-danger" id="ccPhoneReq" style="display:none;">*</span></label>
				<input id="ccPhone" class="form-control" placeholder="Phone">
			</div>
			<div class="col-6 col-md-5">
				<label class="form-label small fw-semibold mb-1">Email</label>
				<input id="ccEmail" class="form-control" placeholder="Email">
			</div>
		</div>
		<div id="ccOrders" class="mb-2"></div>
		<div id="ccPickedOrder" class="mb-2"></div>
	</div>

	<!-- STEP 3 — the call -->
	<div id="ccFormBox" style="display:none;">
		<hr>
		<div class="cc-step">Step 3 · What did they need?</div>

		<div class="mb-2">
			<label class="form-label small fw-semibold mb-1">Reason for the call</label>
			<div id="ccReasons">
				<?php foreach (call_reasons() as $k => $label): ?>
				<span class="cc-chip cc-reason" data-k="<?php echo $k; ?>"><?php echo htmlspecialchars($label); ?></span>
				<?php endforeach; ?>
			</div>
			<input type="hidden" id="ccReason" value="">
		</div>

		<div class="mb-3">
			<label class="form-label small fw-semibold mb-1">What did they say? <span class="text-muted fw-normal">(in your own words)</span></label>
			<textarea id="ccSummary" class="form-control" rows="3" placeholder="e.g. Wingz arrived with a bent rod. Wants a replacement, not a refund."></textarea>
		</div>

		<div class="mb-2">
			<label class="form-label small fw-semibold mb-1">What did you do?</label>
			<div id="ccActions">
				<?php foreach (call_actions() as $k => $label): ?>
				<span class="cc-chip cc-action" data-k="<?php echo $k; ?>"><?php echo htmlspecialchars($label); ?></span>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="row g-2 mb-3">
			<div class="col-6 col-md-3" id="ccRefundWrap" style="display:none;">
				<label class="form-label small fw-semibold mb-1">Refund amount</label>
				<div class="input-group"><span class="input-group-text">$</span>
					<input id="ccRefund" type="number" step="0.01" min="0" class="form-control" placeholder="0.00"></div>
			</div>
			<div class="col-12 col-md-9" id="ccExchangeWrap" style="display:none;">
				<label class="form-label small fw-semibold mb-1">Exchange / replacement details</label>
				<input id="ccExchange" class="form-control" placeholder="e.g. sending a new LDA rod, no charge">
			</div>
		</div>

		<!-- Outcome -->
		<div class="p-3 mb-3" style="background:#f7f9fc;border-radius:10px;">
			<label class="form-label small fw-semibold mb-2">Call outcome</label>
			<div class="d-flex gap-2 flex-wrap mb-2">
				<span class="cc-chip cc-status on" data-k="resolved"><i class="ti ti-check me-1"></i>Taken care of</span>
				<span class="cc-chip cc-status" data-k="open"><i class="ti ti-alert-circle me-1"></i>Still needs work</span>
			</div>
			<input type="hidden" id="ccStatus" value="resolved">
			<div id="ccResolutionWrap" style="display:none;" class="mb-2">
				<input id="ccResolution" class="form-control" placeholder="What's still outstanding? (e.g. waiting on the supplier to confirm)">
			</div>

			<div class="form-check form-switch">
				<input class="form-check-input" type="checkbox" id="ccCallback" style="transform:scale(1.15);">
				<label class="form-check-label fw-semibold" for="ccCallback">George needs to call back</label>
			</div>
			<div id="ccCallbackWrap" style="display:none;" class="mt-2 ps-4">
				<div class="row g-2 align-items-end">
					<div class="col-6 col-md-3">
						<label class="form-label small fw-semibold mb-1">Call back by</label>
						<input id="ccCallbackDue" type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
					</div>
					<div class="col-12 col-md-9">
						<div class="small text-muted">
							This adds it to George's <a href="/tasks.php">task list</a> with the caller's number and the reason.
							Untick it and the task goes away.
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="d-flex align-items-center gap-2">
			<button id="ccSave" class="btn btn-primary px-4"><i class="ti ti-device-floppy me-1"></i>Save call</button>
			<button id="ccReset" class="btn btn-light">Clear</button>
			<span id="ccSaveMsg" class="small"></span>
		</div>
	</div>

</div>
</div>
</div>
<?php endif; ?>

<!-- ═══ CALL LOG ═════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?php echo $canEdit ? '' : 'show active'; ?>" id="pane-log" role="tabpanel">

	<div id="ccStats" class="row g-2 mb-3"></div>

	<div class="card">
	<div class="card-body">
		<div class="row g-2 align-items-end mb-3">
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">From</label>
				<input id="fFrom" type="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>">
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">To</label>
				<input id="fTo" type="date" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>">
			</div>
			<div class="col-6 col-md-2">
				<label class="form-label small fw-semibold mb-1">Status</label>
				<select id="fStatus" class="form-select form-select-sm">
					<option value="">Any</option>
					<option value="open">Still needs work</option>
					<option value="resolved">Taken care of</option>
				</select>
			</div>
			<div class="col-6 col-md-3">
				<label class="form-label small fw-semibold mb-1">Reason</label>
				<select id="fReason" class="form-select form-select-sm">
					<option value="">Any reason</option>
					<?php foreach (call_reasons() as $k => $label): ?>
					<option value="<?php echo $k; ?>"><?php echo htmlspecialchars($label); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php if ($isManager): ?>
			<div class="col-6 col-md-3">
				<label class="form-label small fw-semibold mb-1">Taken by</label>
				<select id="fAgent" class="form-select form-select-sm">
					<option value="0">Anyone</option>
					<?php foreach ($agents as $a): ?>
					<option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>
			<div class="col-12 col-md-4">
				<label class="form-label small fw-semibold mb-1">Search</label>
				<input id="fQ" class="form-control form-control-sm" placeholder="Name, phone, order #, or words in the notes…">
			</div>
			<div class="col-12 col-md-2">
				<button id="fApply" class="btn btn-sm btn-primary w-100">Apply</button>
			</div>
		</div>

		<div id="ccLog"></div>
	</div>
	</div>
</div>

</div><!-- /tab-content -->

<script>
var CC_REASONS = <?php echo json_encode(call_reasons()); ?>;
var CC_ACTIONS = <?php echo json_encode(call_actions()); ?>;
var CC_CANEDIT = <?php echo $canEdit ? 'true' : 'false'; ?>;
var CC_MANAGER = <?php echo $isManager ? 'true' : 'false'; ?>;

function ccEsc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }
function ccMoney(n) { return '$' + Number(n || 0).toFixed(2); }

var CC_MODE   = 'existing';   // 'existing' = looked up in Shopify, 'new' = brand-new caller
var CC_EDITED = false;        // she has hand-typed a caller field — stop auto-filling over her

// Any manual edit to the caller wins over the type-ahead from then on.
$(document).on('input', '#ccName, #ccPhone, #ccEmail', function() { CC_EDITED = true; });

// ── Step 0: have you ordered with us before? ─────────────────────────────────
$('#ccIntroYes').on('click', function() {
	CC_MODE = 'existing';
	$('#ccIntro').hide();
	$('#ccSearchBox').show();
	$('#ccSearch').focus();
});
$('#ccIntroNew').on('click', function() {
	// Brand new caller: skip the lookup entirely. Take their details and what they
	// want, and default to George ringing them back — that's the whole point of this path.
	CC_MODE = 'new';
	$('#ccIntro, #ccSearchBox').hide();
	$('#ccNewNote').show();
	$('#ccCallerStep').text('New customer · Their details');
	$('#ccOrders, #ccPickedOrder').empty();
	$('#ccPhoneReq').show();          // George can't call them back without a number
	ccShowCaller();
	$('#ccCallback').prop('checked', true).trigger('change');
	$('#ccName').focus();
});

// ── Step 1: find who's calling — searches as she types ───────────────────────
var ccTimer = null, ccSeq = 0;

function ccSearch() {
	var term = $.trim($('#ccSearch').val());
	if (term.length < 3) { $('#ccResults').empty(); $('#ccSearchStatus').text(''); return; }

	var seq = ++ccSeq;                     // ignore replies that arrive out of order
	$('#ccSpin').show();
	$('#ccSearchStatus').removeClass('text-danger').text('');

	$.post('/ajax/call_center/lookup.php', { term: term }, function(res) {
		if (seq !== ccSeq) return;         // a newer keystroke already superseded this
		$('#ccSpin').hide();
		if (res.error) { $('#ccSearchStatus').addClass('text-danger').text(res.error); return; }
		$('#ccSearchStatus').text(res.note || '');

		var custs = res.customers || [], ords = res.orders || [];
		var h = '';
		custs.forEach(function(c) {
			h += '<div class="col-12 col-md-6"><div class="cc-hit cc-pick-cust" data-c=\'' + ccEsc(JSON.stringify(c)) + '\'>' +
				'<div class="fw-semibold">' + ccEsc(c.name || '(no name)') + '</div>' +
				'<div class="small text-muted">' + ccEsc([c.phone, c.email, c.city].filter(Boolean).join(' · ') || 'No contact details') + '</div>' +
				'<div class="small text-muted">' + c.orders + ' order' + (c.orders === 1 ? '' : 's') + ' · ' + ccMoney(c.spent) + ' lifetime</div>' +
				'</div></div>';
		});
		ords.forEach(function(o) {
			h += '<div class="col-12 col-md-6"><div class="cc-hit cc-pick-order" data-o=\'' + ccEsc(JSON.stringify(o)) + '\'>' +
				'<div class="fw-semibold">Order ' + ccEsc(o.name) + ' <span class="text-muted fw-normal small">' + ccEsc(o.date) + '</span></div>' +
				'<div class="small text-muted">' + ccEsc(o.customer.name || 'Guest') + ' · ' + ccMoney(o.total) + '</div>' +
				'<div class="small">' + ccStatusBadges(o) + '</div>' +
				'</div></div>';
		});
		$('#ccResults').html(h);

		// Exactly one match and nothing ambiguous → fill the contact details straight away,
		// so the caller is populated before she has finished typing the name. This does NOT
		// collapse the search box — she may still be typing, and the guess may be wrong; she
		// confirms by clicking the card. Never fill over details she has typed by hand, and
		// never re-apply a customer who is already applied.
		if (CC_EDITED) return;
		if (custs.length === 1 && !ords.length) {
			if ($('#ccCustId').val() !== custs[0].id) { ccApplyCustomer(custs[0], false); $('#ccResults .cc-hit').first().addClass('sel'); }
		} else if (!custs.length && ords.length === 1) {
			if ($('#ccOrderId').val() !== ords[0].id) { ccApplyOrder(ords[0], false); $('#ccResults .cc-hit').first().addClass('sel'); }
		}
	}, 'json').fail(function() {
		if (seq !== ccSeq) return;
		$('#ccSpin').hide();
		$('#ccSearchStatus').addClass('text-danger').text('Lookup failed — you can still fill the ticket in by hand.');
	});
}

// Type-ahead: wait for a short pause so we don't fire a Shopify call per keystroke.
$('#ccSearch').on('input', function() {
	clearTimeout(ccTimer);
	ccTimer = setTimeout(ccSearch, 350);
});
$('#ccSearch').on('keydown', function(e) {
	if (e.which === 13) { e.preventDefault(); clearTimeout(ccTimer); ccSearch(); }
});

function ccStatusBadges(o) {
	var b = '';
	if (o.cancelled) b += '<span class="badge bg-danger me-1">Cancelled</span>';
	if (o.payment)    b += '<span class="badge bg-light text-dark border me-1">' + ccEsc(o.payment) + '</span>';
	if (o.fulfilment) b += '<span class="badge bg-light text-dark border me-1">' + ccEsc(o.fulfilment) + '</span>';
	if (o.refunded > 0) b += '<span class="badge bg-warning text-dark me-1">Refunded ' + ccMoney(o.refunded) + '</span>';
	return b;
}

// Once we know who's calling, collapse Step 1 + the contact fields into one line and
// get the screen out of the way — the call itself is what matters now.
function ccCollapseCaller(extra) {
	var bits = [$('#ccPhone').val(), $('#ccEmail').val(), extra].filter(function(x){ return $.trim(x || ''); });
	$('#ccCallerSummary').html(
		'<i class="ti ti-user-check text-primary" style="font-size:1.3rem;"></i>' +
		'<div><div class="who">' + ccEsc($('#ccName').val() || '(no name)') + '</div>' +
		'<div class="meta">' + ccEsc(bits.join(' · ') || 'No contact details on file') + '</div></div>' +
		'<div class="ms-auto d-flex gap-2">' +
			'<button class="btn btn-sm btn-light border" id="ccEditCaller"><i class="ti ti-pencil me-1"></i>Edit details</button>' +
			'<button class="btn btn-sm btn-outline-primary" id="ccChangeCust"><i class="ti ti-repeat me-1"></i>Change customer</button>' +
		'</div>'
	).show();
	$('#ccSearchBox, #ccCallerFields').hide();
	$('#ccCallerStep').text('The caller');
}

// Correct a phone number without losing the customer.
$(document).on('click', '#ccEditCaller', function() {
	$('#ccCallerFields').show();
	$('#ccPhone').focus();
});

// Wrong person — back to the search box, everything about them cleared.
$(document).on('click', '#ccChangeCust', function() {
	CC_EDITED = false; CC_ORDER = null; ccSeq++;
	$('#ccCustId').val(''); $('#ccOrderId').val('');
	$('#ccName, #ccPhone, #ccEmail').val('');
	$('#ccCallerSummary').hide().empty();
	$('#ccOrders, #ccPickedOrder, #ccResults').empty();
	$('#ccCallerFields').show();
	$('#ccSearchBox').show();
	$('#ccSearch').val('').focus();
	$('#ccCallerStep').text('Step 2 · The caller');
});

// Apply a customer. `commit` = she clicked them, so we can collapse. The type-ahead calls
// this with commit=false: it fills the details in but leaves the search box open and
// focused, so she can keep typing to refine if the guess was wrong.
function ccApplyCustomer(c, commit) {
	$('#ccCustId').val(c.id);
	$('#ccName').val(c.name); $('#ccPhone').val(c.phone); $('#ccEmail').val(c.email);
	ccShowCaller();
	if (commit) ccCollapseCaller(c.city);
	$('#ccOrders').html('<div class="small text-muted">Loading their orders…</div>');
	$.post('/ajax/call_center/customer_orders.php', { customer_id: c.id }, function(res) {
		ccRenderOrders((res && res.orders) || [], res && res.note);
	}, 'json').fail(function(){ $('#ccOrders').empty(); });
}

function ccApplyOrder(o, commit) {
	$('#ccCustId').val(o.customer.id || '');
	$('#ccName').val(o.customer.name || ''); $('#ccPhone').val(o.customer.phone || ''); $('#ccEmail').val(o.customer.email || '');
	ccShowCaller();
	if (commit) ccCollapseCaller(o.ship_to);
	ccRenderOrders([o]);
	ccSelectOrder(o);
}

$(document).on('click', '.cc-pick-cust', function() {
	$('#ccResults .cc-hit').removeClass('sel'); $(this).addClass('sel');
	ccApplyCustomer(JSON.parse($(this).attr('data-c')), true);
});
$(document).on('click', '.cc-pick-order', function() {
	$('#ccResults .cc-hit').removeClass('sel'); $(this).addClass('sel');
	ccApplyOrder(JSON.parse($(this).attr('data-o')), true);
});

function ccRenderOrders(orders, note) {
	if (!orders.length) {
		$('#ccOrders').html('<div class="small text-muted">' + ccEsc(note || 'No orders found for this customer.') + '</div>');
		return;
	}
	var h = '<label class="form-label small fw-semibold mb-1">Which order is this about? <span class="text-muted fw-normal">(optional)</span></label><div class="row g-2">';
	orders.forEach(function(o) {
		h += '<div class="col-12 col-md-6"><div class="cc-hit cc-sel-order" data-o=\'' + ccEsc(JSON.stringify(o)) + '\'>' +
			'<div class="d-flex justify-content-between"><span class="fw-semibold">' + ccEsc(o.name) + '</span>' +
			'<span class="text-muted small">' + ccEsc(o.date) + '</span></div>' +
			'<div class="small">' + ccStatusBadges(o) + '</div>' +
			'<div class="small text-muted">' + ccMoney(o.total) + ' · ' +
				ccEsc(o.lines.map(function(l){ return l.qty + '× ' + (l.sku || l.title); }).join(', ') || 'no items') + '</div>' +
			'</div></div>';
	});
	$('#ccOrders').html(h + '</div>');
}

$(document).on('click', '.cc-sel-order', function() {
	$('.cc-sel-order').removeClass('sel'); $(this).addClass('sel');
	ccSelectOrder(JSON.parse($(this).attr('data-o')));
});

var CC_ORDER = null;
function ccSelectOrder(o) {
	CC_ORDER = o;
	$('#ccOrderId').val(o.id);
	var track = (o.tracking || []).map(function(t) {
		return t.url ? '<a href="' + ccEsc(t.url) + '" target="_blank" rel="noopener">' + ccEsc(t.number) + '</a>' : ccEsc(t.number);
	}).join(', ');
	$('#ccPickedOrder').html('<div class="cc-ordbox">' +
		'<div class="fw-semibold mb-1">Talking about order ' + ccEsc(o.name) + ' — ' + ccMoney(o.total) + ' ' + ccStatusBadges(o) + '</div>' +
		'<div class="text-muted">' + ccEsc(o.lines.map(function(l){ return l.qty + '× ' + (l.title || l.sku); }).join(' · ')) + '</div>' +
		(o.ship_to ? '<div class="text-muted">Ship to ' + ccEsc(o.ship_to) + '</div>' : '') +
		(track ? '<div class="text-muted">Tracking: ' + track + '</div>' : '<div class="text-muted">No tracking yet.</div>') +
		'<a href="#" class="cc-clear-order small">Not about this order</a>' +
		'</div>');
}
$(document).on('click', '.cc-clear-order', function(e) {
	e.preventDefault(); CC_ORDER = null; $('#ccOrderId').val('');
	$('#ccPickedOrder').empty(); $('.cc-sel-order').removeClass('sel');
});

function ccShowCaller() { $('#ccCallerBox, #ccFormBox').show(); }
function ccManualEntry() {
	CC_EDITED = true;                       // she's taking over — don't auto-fill over her
	$('#ccCustId').val(''); $('#ccOrderId').val(''); CC_ORDER = null;
	$('#ccResults').empty(); $('#ccPickedOrder').empty(); $('#ccOrders').empty();
	$('#ccCallerSummary').hide().empty();   // nothing to collapse — she's typing them in
	$('#ccCallerFields').show();
	ccShowCaller(); $('#ccName').focus();
}
$('#ccManual').on('click', function(e) { e.preventDefault(); ccManualEntry(); });

// ── Step 3: chips ────────────────────────────────────────────────────────────
$(document).on('click', '.cc-reason', function() {
	$('.cc-reason').removeClass('on'); $(this).addClass('on');
	$('#ccReason').val($(this).attr('data-k'));
});
$(document).on('click', '.cc-action', function() {
	$(this).toggleClass('on');
	var on = $('.cc-action.on').map(function(){ return $(this).attr('data-k'); }).get();
	$('#ccRefundWrap').toggle(on.indexOf('refund_issued') !== -1);
	$('#ccExchangeWrap').toggle(on.indexOf('exchange_sent') !== -1 || on.indexOf('replacement_sent') !== -1);
	// "Escalated to George" implies he has to ring them.
	if ($(this).attr('data-k') === 'escalated' && $(this).hasClass('on')) {
		$('#ccCallback').prop('checked', true).trigger('change');
	}
});
$(document).on('click', '.cc-status', function() {
	$('.cc-status').removeClass('on'); $(this).addClass('on');
	var v = $(this).attr('data-k');
	$('#ccStatus').val(v);
	$('#ccResolutionWrap').toggle(v !== 'resolved');
});
$('#ccCallback').on('change', function() { $('#ccCallbackWrap').toggle($(this).is(':checked')); });

// ── Save ─────────────────────────────────────────────────────────────────────
$('#ccSave').on('click', function() {
	var name  = $.trim($('#ccName').val());
	var phone = $.trim($('#ccPhone').val());
	if (!name) { $('#ccSaveMsg').addClass('text-danger').text('Who called? Please enter a name.'); $('#ccName').focus(); return; }
	// A new customer isn't in Shopify, so this number is the ONLY way to reach them back.
	if (CC_MODE === 'new' && !phone) {
		$('#ccSaveMsg').addClass('text-danger').text('Please get a phone number — it\'s the only way to call a new customer back.');
		$('#ccPhone').focus(); return;
	}
	// A brand-new caller just needs a name and a number — don't block the save on a reason chip.
	var reason = $('#ccReason').val();
	if (!reason) {
		if (CC_MODE !== 'new') { $('#ccSaveMsg').addClass('text-danger').text('Pick a reason for the call.'); return; }
		reason = 'other';
	}

	var $b = $(this).prop('disabled', true);
	$('#ccSaveMsg').removeClass('text-danger text-success').text('Saving…');

	var actions = $('.cc-action.on').map(function(){ return $(this).attr('data-k'); }).get();
	$.post('/ajax/call_center/save.php', {
		id: $('#ccId').val(),
		caller_name: name,
		caller_phone: phone,
		caller_email: $.trim($('#ccEmail').val()),
		shopify_customer_id: $('#ccCustId').val(),
		shopify_order_id: $('#ccOrderId').val(),
		order_number:  CC_ORDER ? CC_ORDER.name : '',
		order_total:   CC_ORDER ? CC_ORDER.total : '',
		order_status:  CC_ORDER ? [CC_ORDER.payment, CC_ORDER.fulfilment].filter(Boolean).join(' / ') : '',
		reason:  reason,
		summary: $.trim($('#ccSummary').val()),
		actions: JSON.stringify(actions),
		refund_amount:  $('#ccRefundWrap').is(':visible')   ? $('#ccRefund').val()   : '',
		exchange_notes: $('#ccExchangeWrap').is(':visible') ? $.trim($('#ccExchange').val()) : '',
		status:     $('#ccStatus').val(),
		resolution: $('#ccStatus').val() !== 'resolved' ? $.trim($('#ccResolution').val()) : '',
		callback_required: $('#ccCallback').is(':checked') ? 1 : 0,
		callback_due: $('#ccCallbackDue').val()
	}, function(res) {
		$b.prop('disabled', false);
		if (!res || !res.ok) { $('#ccSaveMsg').addClass('text-danger').text((res && res.error) || 'Save failed.'); return; }
		var extra = res.callback_task_id ? ' Callback task created.' : '';
		$('#ccSaveMsg').addClass('text-success').text('Call saved.' + extra);
		ccResetForm();
		ccLoadLog();
		setTimeout(function(){ $('#ccSaveMsg').text(''); }, 4000);
	}, 'json').fail(function() {
		$b.prop('disabled', false);
		$('#ccSaveMsg').addClass('text-danger').text('Save failed — please try again.');
	});
});

// Back to the very start — the next call begins with "Have you ordered with us before?"
function ccResetForm() {
	CC_MODE = 'existing'; CC_EDITED = false; CC_ORDER = null; ccSeq++;
	clearTimeout(ccTimer);
	$('#ccId').val(0); $('#ccCustId').val(''); $('#ccOrderId').val('');
	$('#ccSearch, #ccName, #ccPhone, #ccEmail, #ccSummary, #ccRefund, #ccExchange, #ccResolution').val('');
	$('#ccResults, #ccOrders, #ccPickedOrder, #ccSearchStatus').empty();
	$('.cc-reason, .cc-action').removeClass('on');
	$('#ccReason').val('');
	$('.cc-status').removeClass('on'); $('.cc-status[data-k=resolved]').addClass('on'); $('#ccStatus').val('resolved');
	$('#ccResolutionWrap, #ccRefundWrap, #ccExchangeWrap, #ccCallbackWrap').hide();
	$('#ccCallback').prop('checked', false);
	$('#ccSpin, #ccNewNote, #ccCallerBox, #ccFormBox, #ccSearchBox, #ccPhoneReq').hide();
	$('#ccCallerSummary').hide().empty();
	$('#ccCallerFields').show();
	$('#ccCallerStep').text('Step 2 · The caller');
	$('#ccIntro').show();
}
$('#ccReset').on('click', function(){ ccResetForm(); $('#ccSaveMsg').text(''); });

// ── Call log + roll-up ───────────────────────────────────────────────────────
function ccLoadLog() {
	$('#ccLog').html('<div class="text-muted small py-3">Loading…</div>');
	$.post('/ajax/call_center/list.php', {
		from: $('#fFrom').val(), to: $('#fTo').val(), status: $('#fStatus').val(),
		reason: $('#fReason').val(), agent: (CC_MANAGER ? $('#fAgent').val() : 0), q: $.trim($('#fQ').val())
	}, function(res) {
		if (!res || !res.ok) { $('#ccLog').html('<div class="alert alert-danger mb-0">' + ccEsc((res && res.error) || 'Could not load the call log.') + '</div>'); return; }
		ccRenderStats(res.stats);
		ccRenderLog(res.tickets);
	}, 'json').fail(function(){ $('#ccLog').html('<div class="alert alert-danger mb-0">Could not load the call log.</div>'); });
}
$('#fApply').on('click', ccLoadLog);
$('#fQ').on('keydown', function(e){ if (e.which === 13) { e.preventDefault(); ccLoadLog(); } });

function ccRenderStats(s) {
	var tiles = [
		['Calls',            s.calls,                       '#4680ff'],
		['Taken care of',    s.resolved,                    '#2ca87f'],
		['Needs work',       s.open,                        s.open ? '#e8a33d' : '#8a94a6'],
		['Callbacks due',    s.callbacks,                   s.callbacks ? '#e64545' : '#8a94a6'],
		['Refunded',         ccMoney(s.refund_total),       '#b91c1c'],
		['Exchanges',        s.exchanges,                   '#7e57c2']
	];
	var h = '';
	tiles.forEach(function(t) {
		h += '<div class="col-6 col-md-2"><div class="cc-stat">' +
			'<div class="v" style="color:' + t[2] + ';">' + ccEsc(t[1]) + '</div><div class="l">' + t[0] + '</div></div></div>';
	});
	// Top reasons — what people actually ring about.
	var reasons = Object.keys(s.by_reason || {});
	if (reasons.length) {
		var top = reasons.slice(0, 4).map(function(k) {
			return ccEsc(CC_REASONS[k] || k) + ' <strong>' + s.by_reason[k] + '</strong>';
		}).join(' · ');
		h += '<div class="col-12"><div class="small text-muted mt-1">Most common: ' + top + '</div></div>';
	}
	$('#ccStats').html(h);
}

function ccRenderLog(tickets) {
	if (!tickets.length) { $('#ccLog').html('<div class="alert alert-light border mb-0 text-muted">No calls match these filters.</div>'); return; }

	var h = '<div class="table-responsive"><table class="table align-middle" style="font-size:0.88rem;"><thead><tr>' +
		'<th>When</th><th>Caller</th><th>About</th><th>Order</th><th>Outcome</th><th>Taken by</th><th></th>' +
		'</tr></thead><tbody>';

	tickets.forEach(function(t, i) {
		var when = (t.called_at || '').replace(' ', ' · ').slice(0, 16);
		// 'waiting' is a legacy value — it always meant the same thing as open: not done.
		var st = t.status === 'resolved'
			? '<span class="badge bg-success">Taken care of</span>'
			: '<span class="badge bg-danger">Needs work</span>';
		var cb = '';
		if (t.callback_required) {
			cb = t.callback_done
				? ' <span class="badge bg-light text-dark border" title="Callback task completed">Called back</span>'
				: ' <span class="badge" style="background:#e64545;" title="Callback task still open">Callback due</span>';
		}
		var money = '';
		if (t.refund_amount > 0) money += ' <span class="badge bg-light text-dark border">Refund ' + ccMoney(t.refund_amount) + '</span>';
		if (t.actions.indexOf('exchange_sent') !== -1) money += ' <span class="badge bg-light text-dark border">Exchange</span>';

		h += '<tr class="cc-row" data-t="' + i + '">' +
			'<td class="text-muted small">' + ccEsc(when) + '</td>' +
			'<td><div class="fw-semibold">' + ccEsc(t.caller_name) + '</div>' +
				'<div class="text-muted small">' + ccEsc(t.caller_phone || t.caller_email || '') + '</div></td>' +
			'<td>' + ccEsc(CC_REASONS[t.reason] || t.reason) + money + '</td>' +
			'<td class="small">' + (t.order_number ? ccEsc(t.order_number) + '<div class="text-muted">' + ccMoney(t.order_total) + '</div>' : '<span class="text-muted">—</span>') + '</td>' +
			'<td>' + st + cb + '</td>' +
			'<td class="text-muted small">' + ccEsc(t.agent_name || '') + '</td>' +
			'<td class="text-end"><i class="ti ti-chevron-down text-muted"></i></td>' +
			'</tr>' +
			'<tr class="cc-detail" id="ccd' + i + '" style="display:none;"><td colspan="7" class="p-0">' + ccDetail(t) + '</td></tr>';
	});
	$('#ccLog').html(h + '</tbody></table></div>');
	window._ccTickets = tickets;
}

function ccDetail(t) {
	var acts = (t.actions || []).map(function(a){ return '<span class="badge bg-light text-dark border me-1">' + ccEsc(CC_ACTIONS[a] || a) + '</span>'; }).join('');
	var h = '<div class="p-3" style="background:#f8f9fb;">';
	h += '<div class="row g-3">';
	h += '<div class="col-12 col-md-7">';
	h += '<div class="small fw-semibold text-muted mb-1">What they said</div>' +
		'<div style="white-space:pre-wrap;">' + ccEsc(t.summary || '(nothing written down)') + '</div>';
	if (t.resolution) h += '<div class="small fw-semibold text-muted mt-2 mb-1">Outstanding</div><div>' + ccEsc(t.resolution) + '</div>';
	if (t.exchange_notes) h += '<div class="small fw-semibold text-muted mt-2 mb-1">Exchange / replacement</div><div>' + ccEsc(t.exchange_notes) + '</div>';
	h += '</div><div class="col-12 col-md-5">';
	h += '<div class="small fw-semibold text-muted mb-1">What was done</div><div class="mb-2">' + (acts || '<span class="text-muted small">Nothing recorded</span>') + '</div>';
	if (t.refund_amount > 0) h += '<div class="small">Refund: <strong>' + ccMoney(t.refund_amount) + '</strong></div>';
	if (t.order_number) h += '<div class="small">Order <strong>' + ccEsc(t.order_number) + '</strong> · ' + ccEsc(t.order_status || '') + '</div>';
	if (t.caller_email) h += '<div class="small text-muted">' + ccEsc(t.caller_email) + '</div>';
	if (t.callback_required) {
		h += '<div class="small mt-2">' + (t.callback_done
			? '<span class="text-success">Callback done.</span>'
			: '<span class="text-danger fw-semibold">Callback still outstanding</span> — see the <a href="/tasks.php">task list</a>.') + '</div>';
	}
	h += '</div></div>';
	if (CC_CANEDIT) {
		h += '<div class="mt-3"><button class="btn btn-sm btn-outline-danger cc-del" data-id="' + t.id + '">Delete this call</button></div>';
	}
	return h + '</div>';
}

$(document).on('click', '.cc-row', function() {
	var $d = $('#ccd' + $(this).attr('data-t'));
	$d.toggle($d.is(':hidden'));
	$(this).find('.ti-chevron-down, .ti-chevron-up').toggleClass('ti-chevron-down ti-chevron-up');
});

$(document).on('click', '.cc-del', function(e) {
	e.stopPropagation();
	if (!confirm('Delete this call from the log? Any callback task for it is removed too.')) return;
	$.post('/ajax/call_center/delete.php', { id: $(this).attr('data-id') }, function(res) {
		if (!res || !res.ok) { alert((res && res.error) || 'Could not delete.'); return; }
		ccLoadLog();
	}, 'json').fail(function(){ alert('Could not delete.'); });
});

ccLoadLog();
<?php if ($canEdit): ?>$('#ccIntroYes').focus();<?php endif; ?>
</script>

<?php require_once(__DIR__."/includes/footer.php"); ?>
