<?php

	require_once(__DIR__."/includes/fns.php");
	require_login();

	// "Talk to Charles" — the AI CPA. OWNER ONLY (George). Not other admins/masters.
	if (!is_owner()) {
		require_once(__DIR__."/includes/header.php");
		deny_access();
	}

	require_once(__DIR__."/includes/header.php");
?>

<div class="d-flex align-items-center justify-content-between mb-1">
	<h2 class="fw-bold mb-0"><i class="ti ti-user-dollar me-1" style="color:#2ca01c;"></i>Talk to Charles</h2>
</div>
<p class="text-muted mb-4" style="max-width:820px;">Charles is your AI CPA &amp; business planner. He reads your QuickBooks, cash flow, cards &amp; line of credit, and everything in the MRP — then explains, in plain English, what to order when, which card (or the LOC) to use, and how to stay cash-safe through the season. He never moves money: his advice becomes tasks you approve and complete.</p>

<div class="card"><div class="card-body">
	<div class="text-muted">Charles is warming up… (full briefing, charts, and chat are being wired in.)</div>
</div></div>

<?php require_once(__DIR__."/includes/footer.php"); ?>
