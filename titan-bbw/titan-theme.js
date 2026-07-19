/* ============================================================
   TITAN DOP — Theme toggle (BBW MRP port)
   Sun/moon toggle that flips [data-theme] on the .titan-app
   root, matching the design system's dark/light contract.
   Persists the choice per-browser in localStorage.

   Default is DARK (the Titan default). The module opens dark
   unless the user has previously chosen light.

   USAGE:
     Put the toggle button anywhere inside .titan-app:
       <button class="t-theme-btn" data-titan-theme-toggle
               aria-label="Toggle theme"></button>
     Load this script (after the markup, or defer). It:
       - reads localStorage 'titan-theme' (or falls back to dark)
       - applies it to every .titan-app on the page
       - wires every [data-titan-theme-toggle] button
       - swaps the button's icon (sun in dark, moon in light)

   To avoid a flash of the wrong theme, you may also set the
   attribute server-side/inline before this runs, e.g.:
     <div class="titan-app" data-theme="dark"> … </div>
   ============================================================ */
(function () {
	'use strict';
	var KEY = 'titan-theme';
	var SUN = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/></svg>';
	var MOON = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';

	function get() {
		try { var v = localStorage.getItem(KEY); if (v === 'light' || v === 'dark') return v; } catch (e) {}
		return 'dark';
	}
	function apply(theme) {
		document.querySelectorAll('.titan-app').forEach(function (app) { app.setAttribute('data-theme', theme); });
		// show the icon of the theme you'd switch TO
		document.querySelectorAll('[data-titan-theme-toggle]').forEach(function (btn) {
			btn.innerHTML = theme === 'dark' ? SUN : MOON;
		});
	}
	function set(theme) {
		try { localStorage.setItem(KEY, theme); } catch (e) {}
		apply(theme);
	}
	function toggle() { set(get() === 'dark' ? 'light' : 'dark'); }

	function init() {
		apply(get());
		document.querySelectorAll('[data-titan-theme-toggle]').forEach(function (btn) {
			if (btn._titanBound) return;
			btn._titanBound = true;
			btn.addEventListener('click', toggle);
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();

	// expose for programmatic control
	window.TitanTheme = { get: get, set: set, toggle: toggle };
})();
