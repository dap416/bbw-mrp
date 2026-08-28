<?php

	/**
	 * Single sign-on bridge to the Ads Dashboard (Meta, Google, Microsoft).
	 *
	 * That dashboard is a Next.js app, not an MRP page. Apache reverse-proxies
	 * /meta to it on localhost:3100, so it shares this origin — but it cannot
	 * read a PHP session. This page is the translation: it checks the session
	 * the normal MRP way, then mints a short-lived HMAC-signed cookie that the
	 * app's middleware can verify on its own.
	 *
	 * The signing secret is shared with the app through config.local.php here
	 * and META_SSO_SECRET in its .env.local on the server. If it is missing the
	 * gate refuses rather than redirecting into an app that would only bounce
	 * the user back, which would loop.
	 */

	require_once(__DIR__."/includes/fns.php");
	require_login();

	if (!has_access('meta')) {
		http_response_code(403);
		require_once(__DIR__."/includes/header.php");
		echo '<div class="page-block"><div class="alert alert-warning">You do not have access to the Ads Dashboard.</div></div>';
		exit;
	}

	$secret = $GLOBALS['APP_CONFIG']['meta']['sso_secret'] ?? '';
	if ($secret === '') {
		http_response_code(500);
		exit('Meta dashboard is not configured: missing meta.sso_secret in includes/config.local.php.');
	}

	// Eight hours: long enough to spend a working day in the dashboard without
	// re-gating, short enough that a leaked cookie is not a standing key. The
	// app re-issues silently on expiry as long as the MRP session is alive.
	$expiry  = time() + 8 * 3600;

	// The permission level travels in the token. The dashboard is one app but two
	// things: the figures, which View covers, and Setup and Adjustments, which can
	// overwrite the stored API tokens and restate revenue. Signing the level here
	// means the app can tell those apart without a second call back to MRP.
	$level   = access_level('meta');
	$payload = $expiry . '.' . (int)($_SESSION['user_id'] ?? 0) . '.' . $level;
	$token   = $payload . '.' . hash_hmac('sha256', $payload, $secret);

	setcookie('meta_sso', $token, [
		'expires'  => $expiry,
		'path'     => '/meta',
		'secure'   => !empty($_SERVER['HTTPS']),
		'httponly' => true,
		'samesite' => 'Lax',
	]);

	// Only ever redirect back inside the dashboard: `next` arrives from the
	// app's own middleware, but it reaches us through the user's browser and so
	// cannot be trusted to stay on /meta.
	$next = (string)($_GET['next'] ?? '');
	$dest = '/meta';
	if ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//')) {
		$path = '/meta' . $next;
		if (str_starts_with($path, '/meta/')) $dest = $path;
	}

	header('Location: ' . $dest);
	exit;
