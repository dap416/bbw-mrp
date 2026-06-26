<?php

	require_once(__DIR__."/../../includes/fns.php");
	require_once(__DIR__."/../../includes/shopify.php");
	require_login();

	// Admin/master only — these are credentials.
	$role = $_SESSION['user_role'] ?? '';
	if (!in_array($role, ['admin', 'master'], true)) { http_response_code(403); echo 'denied'; exit; }

	try {
		$db = db_connect();
		if (!$db) { http_response_code(500); echo 'Database connection failed.'; exit; }

		// Ensure the settings table exists so saving works even if the
		// one-time setup script was never run.
		$db->exec("CREATE TABLE IF NOT EXISTS settings (
			skey       VARCHAR(64) NOT NULL PRIMARY KEY,
			sval       TEXT,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
		) ENGINE=InnoDB");

		// ── Shopify fields (only when the Shopify form submits them) ──────────
		if (array_key_exists('domain', $_POST)) {
			$domain   = trim($_POST['domain']        ?? '');
			$version  = trim($_POST['api_version']   ?? '');
			$clientId = trim($_POST['client_id']     ?? '');
			$secret   = trim($_POST['client_secret'] ?? '');

			// Normalize domain — accept a pasted URL and reduce to the host.
			$domain = preg_replace('#^https?://#i', '', $domain);
			$domain = rtrim($domain, '/');

			setting_set($db, 'shopify_domain', $domain);
			setting_set($db, 'shopify_api_version', $version !== '' ? $version : '2025-01');
			setting_set($db, 'shopify_client_id', $clientId);

			// Only overwrite the secret when a new one is entered, so saving the
			// form without re-typing it doesn't wipe the stored secret.
			if ($secret !== '') {
				setting_set($db, 'shopify_client_secret', $secret);
			}

			// Credentials changed — drop any cached access token so the next call
			// fetches a fresh one with the new credentials/scopes.
			setting_set($db, 'shopify_token', '');
			setting_set($db, 'shopify_token_expires', '0');
		}

		// Anthropic (Claude) — for the planning assistant. Optional fields:
		// only present when the Integrations form submits them.
		if (array_key_exists('anthropic_model', $_POST)) {
			$model = trim($_POST['anthropic_model'] ?? '');
			setting_set($db, 'anthropic_model', $model !== '' ? $model : 'claude-opus-4-8');
		}
		$anthropicKey = trim($_POST['anthropic_api_key'] ?? '');
		if ($anthropicKey !== '') {
			setting_set($db, 'anthropic_api_key', $anthropicKey);
		}

		echo 'ok';
	} catch (Throwable $e) {
		http_response_code(500);
		echo 'Save failed: ' . $e->getMessage();
	}
