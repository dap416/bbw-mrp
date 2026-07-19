<?php
/* ============================================================
   TITAN DOP — Component render helpers (BBW MRP port)
   Optional PHP helpers for the repetitive Titan atoms/patterns.
   These emit the exact class contract from titan-bbw.css, so
   you can also just hand-write the HTML — these are a shortcut,
   not a framework.

   require_once __DIR__.'/titan-icons.php';
   require_once __DIR__.'/titan-components.php';

   All helpers RETURN a string (echo it yourself), except the
   shell open/close helpers which are named *_open / *_close.
   User-supplied text is escaped; pass pre-built HTML via the
   $html-suffixed params where noted.
   ============================================================ */

require_once __DIR__ . '/titan-icons.php';

if (!function_exists('t_e')) {
	function t_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---- Button --------------------------------------------------
   $variant: 'ghost' (default) | 'solid'
   $icon: glyph name or '' ; $href: renders an <a> if set
------------------------------------------------------------------*/
if (!function_exists('t_button')) {
	function t_button($label, $opts = array()) {
		$variant = isset($opts['variant']) ? $opts['variant'] : 'ghost';
		$icon    = isset($opts['icon']) ? $opts['icon'] : '';
		$href    = isset($opts['href']) ? $opts['href'] : '';
		$sm      = !empty($opts['sm']) ? ' sm' : '';
		$attrs   = isset($opts['attrs']) ? ' ' . $opts['attrs'] : '';
		$cls = 't-btn' . ($variant === 'solid' ? ' solid' : '') . $sm;
		$inner = ($icon ? titan_icon($icon, 15) : '') . '<span>' . t_e($label) . '</span>';
		if ($href !== '') {
			return '<a class="' . $cls . '" href="' . t_e($href) . '"' . $attrs . '>' . $inner . '</a>';
		}
		return '<button type="button" class="' . $cls . '"' . $attrs . '>' . $inner . '</button>';
	}
}

/* ---- Chip ($tone: good|warn|crit|accent|ghost) --------------- */
if (!function_exists('t_chip')) {
	function t_chip($label, $tone = 'ghost', $icon = '') {
		return '<span class="t-chip ' . t_e($tone) . '">'
			. ($icon ? titan_icon($icon, 12) : '')
			. t_e($label) . '</span>';
	}
}

/* ---- Status dot ($tone; $pulse for overdue/live) ------------- */
if (!function_exists('t_dot')) {
	function t_dot($tone = 'accent', $pulse = false, $halo = true) {
		$cls = 't-dot ' . t_e($tone) . ($pulse ? ' pulse' : '') . ($halo ? '' : ' nohalo');
		return '<span class="' . $cls . '"></span>';
	}
}

/* ---- Signal LED ($level: ok|warn|crit|accent|idle, $label) --- */
if (!function_exists('t_led')) {
	function t_led($level = 'ok', $label = null) {
		$cls = 't-led' . ($level === 'ok' ? '' : ' ' . t_e($level));
		$dot = '<span class="' . $cls . '"></span>';
		if ($label === null) return $dot;
		return '<span style="display:inline-flex;align-items:center;gap:7px">' . $dot
			. '<span class="mono" style="font-size:9.5px;color:var(--tx-lo);text-transform:uppercase;letter-spacing:.06em">'
			. t_e($label) . '</span></span>';
	}
}

/* ---- Delta ($dir: up|down|flat) ------------------------------ */
if (!function_exists('t_delta')) {
	function t_delta($text, $dir = 'flat') {
		$mark = $dir === 'up' ? '&#9650; ' : ($dir === 'down' ? '&#9660; ' : '');
		return '<span class="t-delta ' . t_e($dir) . '">' . $mark . t_e($text) . '</span>';
	}
}

/* ---- Stat readout (dot + number + mono caption) -------------- */
if (!function_exists('t_stat')) {
	function t_stat($value, $label, $tone = '', $pulse = false) {
		$cls = 't-stat' . ($tone ? ' ' . t_e($tone) : '');
		$dot = $tone ? '<span class="t-dot ' . t_e($tone) . ' nohalo' . ($pulse ? ' pulse' : '') . '"></span>' : '';
		return '<div class="' . $cls . '">' . $dot
			. '<div><div class="t-stat-val num">' . t_e($value) . '</div>'
			. '<div class="t-stat-lbl mono">' . t_e($label) . '</div></div></div>';
	}
}

/* ---- KPI tile ------------------------------------------------
   $opts: sev (good|warn|crit|accent), icon, sub, href
------------------------------------------------------------------*/
if (!function_exists('t_kpi')) {
	function t_kpi($label, $value, $opts = array()) {
		$sev  = isset($opts['sev']) ? $opts['sev'] : 'accent';
		$icon = isset($opts['icon']) ? $opts['icon'] : '';
		$sub  = isset($opts['sub']) ? $opts['sub'] : '';
		$href = isset($opts['href']) ? $opts['href'] : '';
		$tag  = $href ? 'a' : 'div';
		$hrefAttr = $href ? ' href="' . t_e($href) . '"' : '';
		return '<' . $tag . ' class="t-kpi" data-sev="' . t_e($sev) . '"' . $hrefAttr . '>'
			. ($icon ? '<span class="t-kpi-icon">' . titan_icon($icon, 20) . '</span>' : '')
			. '<div class="t-kpi-label">' . t_e($label) . '</div>'
			. '<div class="t-kpi-value">' . t_e($value) . '</div>'
			. ($sub ? '<div class="t-kpi-sub">' . t_e($sub) . '</div>' : '')
			. '</' . $tag . '>';
	}
}

/* ---- Panel (card with a mono header) -------------------------
   $bodyHtml is pre-built HTML (NOT escaped). $actionHtml optional.
------------------------------------------------------------------*/
if (!function_exists('t_panel')) {
	function t_panel($title, $bodyHtml, $actionHtml = '') {
		return '<div class="t-panel"><div class="t-panel-body">'
			. '<div class="t-panel-head"><h6 class="t-panel-title">' . t_e($title) . '</h6>'
			. ($actionHtml ? '<div>' . $actionHtml . '</div>' : '')
			. '</div>' . $bodyHtml . '</div></div>';
	}
}

/* ---- Close-circuit lifecycle tracker -------------------------
   $steps = [ ['label'=>'Assigned','state'=>'done'], ... ]
   state: done | current | todo | crit
------------------------------------------------------------------*/
if (!function_exists('t_lifecycle')) {
	function t_lifecycle($steps) {
		$out = '<div class="t-track">';
		$n = count($steps);
		foreach ($steps as $i => $s) {
			$state = isset($s['state']) ? $s['state'] : 'todo';
			$inner = $state === 'done' ? titan_icon('check', 12) : ($i + 1);
			$out .= '<div class="t-track-node ' . t_e($state) . '">'
				. '<div class="t-track-dot">' . $inner . '</div>'
				. '<div class="t-track-lbl">' . t_e($s['label']) . '</div></div>';
			if ($i < $n - 1) {
				$doneLink = ($state === 'done') ? ' done' : '';
				$out .= '<div class="t-track-link' . $doneLink . '"></div>';
			}
		}
		return $out . '</div>';
	}
}

/* ---- Segmented control ---------------------------------------
   $options = ['Today'=>'today', ...] (label=>value); $value = active
   Wire selection yourself (jQuery) — this renders markup + .on state.
------------------------------------------------------------------*/
if (!function_exists('t_segmented')) {
	function t_segmented($options, $value, $name = '') {
		$out = '<div class="t-seg" role="tablist"' . ($name ? ' data-seg="' . t_e($name) . '"' : '') . '>';
		foreach ($options as $label => $val) {
			$on = ($val === $value) ? ' on' : '';
			$out .= '<button type="button" class="' . trim($on) . '" data-val="' . t_e($val) . '">' . t_e($label) . '</button>';
		}
		return $out . '</div>';
	}
}

/* ============================================================
   MODULE SHELL — sidebar + top bar
   Call t_shell_open() … your content … t_shell_close().
   $theme: 'dark' (default) | 'light'  (theme.js will override
   from localStorage once loaded).
   ============================================================ */
if (!function_exists('t_shell_open')) {
	/**
	 * @param string $title    top-bar title
	 * @param array  $nav      groups: [ 'MRP' => [ ['label','icon','href','on'=>bool,'badge'=>'','led'=>'crit'], ... ], ... ]
	 * @param string $statsHtml pre-built stat readouts for the top bar (use t_stat)
	 * @param string $theme
	 */
	function t_shell_open($title, $nav = array(), $statsHtml = '', $theme = 'dark') {
		$out = '<div class="titan-app" data-theme="' . t_e($theme) . '"><div class="t-shell">';
		// sidebar
		$out .= '<aside class="t-side">'
			. '<div class="t-side-brand"><span class="t-brand-mark">' . titan_icon('brand', 18) . '</span>'
			. '<span><span class="t-brand-name">BBW</span><span class="t-brand-sub">MRP · Titan</span></span></div>';
		foreach ($nav as $caption => $items) {
			$out .= '<div class="t-side-cap">' . t_e($caption) . '</div>';
			foreach ($items as $it) {
				$on = !empty($it['on']) ? ' on' : '';
				$led = !empty($it['led']) ? '<span class="t-led ' . t_e($it['led']) . '" style="margin-left:auto"></span>' : '';
				$badge = (!$led && isset($it['badge'])) ? '<span class="t-nav-badge">' . t_e($it['badge']) . '</span>' : '';
				$out .= '<a class="t-nav-item' . $on . '" href="' . t_e(isset($it['href']) ? $it['href'] : '#') . '">'
					. (isset($it['icon']) ? titan_icon($it['icon'], 17) : '')
					. '<span>' . t_e($it['label']) . '</span>' . $led . $badge . '</a>';
			}
		}
		$out .= '</aside>';
		// main + topbar
		$out .= '<div class="t-main"><header class="t-topbar"><h1>' . t_e($title) . '</h1>'
			. '<div class="t-topbar-stats">' . $statsHtml
			. '<button class="t-theme-btn" data-titan-theme-toggle aria-label="Toggle theme"></button>'
			. '</div></header><div class="t-content">';
		return $out;
	}
	function t_shell_close() {
		return '</div></div></div></div>';
	}
}
