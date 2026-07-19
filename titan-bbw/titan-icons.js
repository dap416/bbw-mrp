/* ============================================================
   BBW DESIGN SYSTEM — Icons (JS renderer)
   The exact Titan 30-glyph set as inline SVG. Mirror of
   titan-icons.php for browser/React use (no PHP required).

   USAGE
     Declarative:  <i data-ti="wrench" data-s="18"></i>   (auto-mounted)
     Imperative:   el.innerHTML = titanIcon('gauge', 20);
     React:        dangerouslySetInnerHTML={{__html: titanIcon('parts',18)}}

   SVG inherits currentColor, so a glyph takes the color of its
   container (e.g. red inside a .t-chip.crit).
   ============================================================ */
(function (global) {
	'use strict';
	var P = {
		wrench:[1.9,'<path d="M14.7 6.3a4 4 0 0 0 5 5l-1.6 1.6a1 1 0 0 1-1.4 0l-7.9 7.9a2.1 2.1 0 0 1-3-3l7.9-7.9a1 1 0 0 1 0-1.4z"/>'],
		drum:[1.8,'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M5 9h14M5 15h14M10 3v18"/>'],
		flame:[1.8,'<path d="M12 2c1 3 4 4.5 4 8a4 4 0 0 1-8 0c0-1 .3-1.8.7-2.5C9 8 11 7 12 2z"/><path d="M8 14a4 4 0 0 0 8 0"/>'],
		gauge:[1.8,'<path d="M12 14l4-4M4.5 18a9 9 0 1 1 15 0z"/>'],
		shield:[1.8,'<path d="M12 3l7 3v5c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6z"/>'],
		file:[1.8,'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>'],
		leaf:[1.8,'<path d="M11 20A7 7 0 0 1 4 13c0-5 4-9 16-9 0 11-5 16-9 16z"/><path d="M8 17c3-4 6-5 9-6"/>'],
		clipboard:[1.8,'<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 10h6M9 14h6"/>'],
		parts:[1.9,'<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>'],
		van:[1.9,'<path d="M2 7h11v8H2zM13 10h4l3 3v2h-7z"/><circle cx="6.5" cy="17" r="1.6"/><circle cx="16.5" cy="17" r="1.6"/>'],
		hammer:[1.9,'<path d="m15 12-8.4 8.4a2.1 2.1 0 1 1-3-3L12 9"/><path d="M17.6 15 22 10.6"/><path d="M20.9 11.7 19.6 10.4c-.6-.6-.9-1.4-.9-2.2v-.9L16 4.6A5.6 5.6 0 0 0 12 3H9l.9.8A6.2 6.2 0 0 1 12 8.4V10l2 2h1.6c.8 0 1.6.3 2.2.9l1.3 1.3"/>'],
		ticket:[1.9,'<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>'],
		pen:[2,'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'],
		upload:[2,'<path d="M12 16V4M7 9l5-5 5 5M5 20h14"/>'],
		search:[2,'<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'],
		check:[3,'<path d="M4 12.5l5 5L20 6.5"/>'],
		user:[1.9,'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'],
		reopen:[2,'<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>'],
		arrow:[2.2,'<path d="M5 12h14M13 6l6 6-6 6"/>'],
		x:[2.2,'<path d="M6 6l12 12M18 6 6 18"/>'],
		clock:[2,'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
		plus:[2.4,'<path d="M12 5v14M5 12h14"/>'],
		chevronDown:[2.4,'<path d="M6 9l6 6 6-6"/>'],
		bell:[1.9,'<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>'],
		chat:[2,'<path d="M21 11.5a8 8 0 0 1-11.6 7.1L3 21l2.4-6.4A8 8 0 1 1 21 11.5z"/>'],
		alert:[2.4,'<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>'],
		sun:[2,'<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>'],
		moon:[2,'<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'],
		spark:[0,'<path d="M12 2l1.6 5.2L19 9l-5.4 1.8L12 16l-1.6-5.2L5 9l5.4-1.8z"/><path d="M19 14l.8 2.4L22 17l-2.2.8L19 20l-.8-2.2L16 17l2.2-.6z"/>'],
		brand:[2.2,'<path d="M12 2v4M12 18v4M2 12h4M18 12h4"/><circle cx="12" cy="12" r="4"/>']
	};
	function titanIcon(name, s, style){
		var g = P[name] || P.file, sw = g[0]; s = s || 16;
		style = style || 'display:block';
		if (sw === 0) return '<svg width="'+s+'" height="'+s+'" viewBox="0 0 24 24" fill="currentColor" style="'+style+'">'+g[1]+'</svg>';
		return '<svg width="'+s+'" height="'+s+'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="'+sw+'" stroke-linecap="round" stroke-linejoin="round" style="'+style+'">'+g[1]+'</svg>';
	}
	titanIcon.names = Object.keys(P);
	function mount(root){
		(root || document).querySelectorAll('[data-ti]').forEach(function(el){
			el.outerHTML = titanIcon(el.getAttribute('data-ti'), parseInt(el.getAttribute('data-s'))||16);
		});
	}
	titanIcon.mount = mount;
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ mount(); });
	else mount();
	global.titanIcon = titanIcon;
})(window);
