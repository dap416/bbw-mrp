<?php
/**
 * Public Privacy Policy page (no login) — required by Intuit/QuickBooks to
 * publish a Privacy Policy URL for the production app. Intentionally simple
 * and publicly reachable so Intuit's reviewer can load it.
 */
$updated = 'June 28, 2026';
?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Privacy Policy — Blue Bird Waterfowl MRP</title>
	<link rel="icon" type="image/png" sizes="32x32" href="/images/favicon-32.png" />
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" />
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:820px;">
	<div class="text-center mb-4">
		<img src="/images/logo.png" alt="Blue Bird Waterfowl" style="max-height:90px;" />
	</div>
	<div class="card shadow-sm">
	<div class="card-body p-4 p-md-5">
		<h1 class="fw-bold h3 mb-1">Privacy Policy</h1>
		<p class="text-muted small mb-4">Last updated: <?php echo $updated; ?></p>

		<p>This Privacy Policy describes how the Blue Bird Waterfowl MRP application
		(the "App," at <strong>mrp.bbwmanager.com</strong>) handles information. The App
		is a private, internal business tool operated by Blue Bird Waterfowl
		("we," "us") for managing inventory, purchasing, and cash-flow planning.</p>

		<h2 class="h5 fw-bold mt-4">Information We Access</h2>
		<p>With your authorization, the App connects to third-party services to read
		business data on your behalf:</p>
		<ul>
			<li><strong>QuickBooks Online</strong> — we read accounting data such as account
			balances, bills, invoices, and transactions to display cash-flow and budgeting
			information inside the App.</li>
			<li><strong>Shopify</strong> — we read product, inventory, and order data to support
			demand planning.</li>
		</ul>
		<p>We request read-only access and only the data needed to provide these features.</p>

		<h2 class="h5 fw-bold mt-4">How We Use Information</h2>
		<p>Information is used solely to operate the App's internal features for our own
		business — inventory management, purchasing, reporting, and cash-flow planning.
		We do <strong>not</strong> sell, rent, or share this information with third parties for
		marketing or any other purpose.</p>

		<h2 class="h5 fw-bold mt-4">Storage and Security</h2>
		<p>Access tokens and business data are stored on our own secured server and
		transmitted over encrypted (HTTPS) connections. Access to the App is restricted
		to authorized users with individual accounts and role-based permissions. You may
		disconnect QuickBooks at any time from the App's Integrations page, which removes
		the stored access tokens.</p>

		<h2 class="h5 fw-bold mt-4">Data Retention</h2>
		<p>We retain connected-service data only as long as needed for the App's features.
		Disconnecting a service removes its stored authorization tokens from our system.</p>

		<h2 class="h5 fw-bold mt-4">Contact</h2>
		<p>Questions about this policy can be sent to
		<a href="mailto:bluebirdwaterfowl@gmail.com">bluebirdwaterfowl@gmail.com</a>.</p>

		<hr class="my-4" />
		<p class="text-muted small mb-0">Blue Bird Waterfowl · mrp.bbwmanager.com</p>
	</div>
	</div>
	<p class="text-center mt-3"><a href="/terms.php">Terms of Service</a></p>
</div>
</body>
</html>
