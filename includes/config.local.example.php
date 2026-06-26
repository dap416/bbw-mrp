<?php

	// Template for includes/config.local.php
	// Copy this file to config.local.php and fill in real values.
	// config.local.php is gitignored — never commit real secrets.

	return [
		'db' => [
			'host' => 'localhost',
			'name' => 'bbw_raw_inv',
			'user' => 'bbwadmin',
			'pass' => 'CHANGE_ME',
		],
		'dev' => [
			'login_email'    => 'you@example.com',
			'login_password' => 'CHANGE_ME',
		],

		// Shopify Admin API — used by the Research page to read live store
		// inventory. Easiest path: enter these on the in-app Integrations page
		// (stored in the DB) instead of here. Shopify now uses the Dev Dashboard
		// (dev.shopify.com/dashboard): create an app, add the read_products and
		// read_inventory scopes, install it on your store, then copy the
		// Client ID and Client secret from the app's Settings tab. The MRP
		// exchanges them for a short-lived access token automatically.
		'shopify' => [
			'domain'        => 'your-store.myshopify.com', // the *.myshopify.com domain
			'client_id'     => 'CHANGE_ME',
			'client_secret' => 'CHANGE_ME',
			'api_version'   => '2025-01',
		],
	];
