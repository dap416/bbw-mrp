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
		// inventory. Create a Custom App in your Shopify admin
		// (Settings → Apps and sales channels → Develop apps → Create an app),
		// grant the Admin API scopes `read_products` and `read_inventory`,
		// install it, then copy the Admin API access token (starts with shpat_).
		'shopify' => [
			'domain'      => 'your-store.myshopify.com', // the *.myshopify.com domain, not the public domain
			'token'       => 'CHANGE_ME',                // Admin API access token (shpat_...)
			'api_version' => '2025-01',
		],
	];
