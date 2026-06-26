<?php

	require_once(__DIR__."/fns.php");
	require_once(__DIR__."/shopify.php"); // for setting_get/setting_set

	/**
	 * Minimal Claude (Anthropic) Messages API client via curl.
	 * API key + model are stored in the settings table (entered on the
	 * Integrations page). Docs: https://docs.claude.com — Messages API.
	 */

	function anthropic_config() {
		static $cfg = null;
		if ($cfg !== null) return $cfg;
		$cfg = ['api_key' => '', 'model' => 'claude-opus-4-8'];
		try {
			$db = db_connect();
			if ($db) {
				$k = setting_get($db, 'anthropic_api_key');
				$m = setting_get($db, 'anthropic_model');
				if ($k) $cfg['api_key'] = $k;
				if ($m) $cfg['model']   = $m;
			}
		} catch (Throwable $e) { /* settings not ready */ }
		// allow config.local.php fallback
		$file = app_config('anthropic') ?: [];
		if (empty($cfg['api_key']) && !empty($file['api_key'])) $cfg['api_key'] = $file['api_key'];
		if (!empty($file['model'])) $cfg['model'] = $cfg['model'] ?: $file['model'];
		return $cfg;
	}

	function anthropic_is_configured() {
		$c = anthropic_config();
		return !empty($c['api_key']) && strpos($c['api_key'], 'CHANGE_ME') === false;
	}

	/**
	 * Send a single-turn message. Returns ['text' => string] or ['error' => string].
	 * $system is the system prompt; $userText is the user message content.
	 */
	function anthropic_message($system, $userText, $maxTokens = 4096) {
		$c = anthropic_config();
		if (empty($c['api_key'])) {
			return ['error' => 'No Anthropic API key configured. Add one on the Integrations page.'];
		}

		$payload = json_encode([
			'model'      => $c['model'] ?: 'claude-opus-4-8',
			'max_tokens' => $maxTokens,
			'system'     => $system,
			'messages'   => [
				['role' => 'user', 'content' => $userText],
			],
		]);

		$ch = curl_init('https://api.anthropic.com/v1/messages');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $payload,
			CURLOPT_HTTPHEADER     => [
				'content-type: application/json',
				'x-api-key: ' . $c['api_key'],
				'anthropic-version: 2023-06-01',
			],
			CURLOPT_TIMEOUT        => 120,
		]);
		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($body === false) return ['error' => 'Could not reach Anthropic: ' . $err];

		$j = json_decode($body, true);
		if ($code === 401) return ['error' => 'Anthropic rejected the API key (HTTP 401). Check the key on the Integrations page.'];
		if ($code === 400) {
			$msg = $j['error']['message'] ?? 'Bad request';
			return ['error' => 'Anthropic error (HTTP 400): ' . $msg];
		}
		if ($code === 429) return ['error' => 'Anthropic rate limit hit (HTTP 429). Try again in a moment.'];
		if (!is_array($j)) return ['error' => 'Unexpected response from Anthropic (HTTP ' . $code . ').'];
		if (!empty($j['error'])) return ['error' => 'Anthropic error: ' . ($j['error']['message'] ?? 'unknown')];

		if (($j['stop_reason'] ?? '') === 'refusal') {
			return ['error' => 'The model declined to answer this request.'];
		}

		// Collect text blocks (skip thinking blocks)
		$text = '';
		foreach (($j['content'] ?? []) as $block) {
			if (($block['type'] ?? '') === 'text') $text .= $block['text'];
		}
		if ($text === '') return ['error' => 'Empty response from Anthropic.'];

		return ['text' => $text];
	}
