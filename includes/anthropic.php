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
	 * Low-level POST to the Messages API. $body is the full request array
	 * (model/max_tokens/system/messages). Returns ['text'=>...] or ['error'=>...].
	 */
	function anthropic_request($body) {
		$c = anthropic_config();
		if (empty($c['api_key'])) {
			return ['error' => 'No Anthropic API key configured. Add one on the Integrations page.'];
		}

		$ch = curl_init('https://api.anthropic.com/v1/messages');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($body),
			CURLOPT_HTTPHEADER     => [
				'content-type: application/json',
				'x-api-key: ' . $c['api_key'],
				'anthropic-version: 2023-06-01',
			],
			CURLOPT_TIMEOUT        => 120,
		]);
		$resp = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err  = curl_error($ch);
		curl_close($ch);

		if ($resp === false) return ['error' => 'Could not reach Anthropic: ' . $err];

		$j = json_decode($resp, true);
		if ($code === 401) return ['error' => 'Anthropic rejected the API key (HTTP 401). Check the key on the Integrations page.'];
		if ($code === 400) return ['error' => 'Anthropic error (HTTP 400): ' . ($j['error']['message'] ?? 'Bad request')];
		if ($code === 429) return ['error' => 'Anthropic rate limit hit (HTTP 429). Try again in a moment.'];
		if (!is_array($j)) return ['error' => 'Unexpected response from Anthropic (HTTP ' . $code . ').'];
		if (!empty($j['error'])) return ['error' => 'Anthropic error: ' . ($j['error']['message'] ?? 'unknown')];
		if (($j['stop_reason'] ?? '') === 'refusal') return ['error' => 'The model declined to answer this request.'];

		$text = '';
		foreach (($j['content'] ?? []) as $block) {
			if (($block['type'] ?? '') === 'text') $text .= $block['text'];
		}
		if ($text === '') return ['error' => 'Empty response from Anthropic.'];
		return ['text' => $text];
	}

	/** Single-turn message. */
	function anthropic_message($system, $userText, $maxTokens = 4096) {
		$c = anthropic_config();
		return anthropic_request([
			'model'      => $c['model'] ?: 'claude-opus-4-8',
			'max_tokens' => $maxTokens,
			'system'     => $system,
			'messages'   => [['role' => 'user', 'content' => $userText]],
		]);
	}

	/**
	 * Multi-turn chat. $system is the (large, stable) system prompt — it's sent
	 * with a cache breakpoint so repeated follow-ups within ~5 min read it from
	 * cache instead of re-billing the full snapshot. $messages is the running
	 * [{role, content}] history.
	 */
	function anthropic_chat($system, $messages, $maxTokens = 3000) {
		$c = anthropic_config();
		return anthropic_request([
			'model'      => $c['model'] ?: 'claude-opus-4-8',
			'max_tokens' => $maxTokens,
			'system'     => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
			'messages'   => $messages,
		]);
	}
