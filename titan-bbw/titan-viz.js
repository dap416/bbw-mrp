/* ============================================================
   TITAN DOP — Viz (BBW MRP port)
   Vanilla-JS 1:1 port of the Titan SVG viz components
   (components/viz/*.jsx). No React, no Chart.js — these render
   the EXACT same inline SVG the design system ships.

   Every function returns an SVG *string*. Two ways to use it:

   1) Declarative (recommended). Put a placeholder in markup and
      let auto-mount fill it on DOMContentLoaded:
        <div class="t-viz" data-viz="sparkline"
             data-data="[4,6,5,8,7,9,8,11]"
             data-color="var(--good)"></div>

   2) Imperative:
        el.innerHTML = TitanViz.gauge({ pct: 97.3, value: '97.3', label: 'CSI' });

   Colors accept CSS custom props (var(--good) etc.) so both
   themes work automatically. Feed data from PHP with
   data-data="<?php echo htmlspecialchars(json_encode($arr)); ?>".
   ============================================================ */
(function (global) {
	'use strict';

	var uid = function (p) { return p + Math.random().toString(36).slice(2, 7); };

	function pts(data, w, h, pad) {
		var min = Math.min.apply(null, data), max = Math.max.apply(null, data);
		var span = (max - min) || 1, iw = w - pad * 2, ih = h - pad * 2;
		return data.map(function (v, i) {
			return [pad + (i / (data.length - 1)) * iw, pad + ih - ((v - min) / span) * ih];
		});
	}
	function path(p) {
		return p.map(function (q, i) { return (i ? 'L' : 'M') + q[0].toFixed(1) + ' ' + q[1].toFixed(1); }).join(' ');
	}

	var TitanViz = {

		/* Sparkline — bare trend line + area fill + end dot. */
		sparkline: function (o) {
			o = o || {};
			var data = o.data, w = o.w || 110, h = o.h || 34,
				color = o.color || 'var(--good)', fill = o.fill !== false, sw = o.sw || 2, pad = 3;
			var p = pts(data, w, h, pad), d = path(p), id = uid('sg');
			var area = d + ' L ' + p[p.length - 1][0].toFixed(1) + ' ' + (h - pad) + ' L ' + p[0][0].toFixed(1) + ' ' + (h - pad) + ' Z';
			var last = p[p.length - 1];
			return '<svg width="' + w + '" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" style="display:block;overflow:visible">'
				+ '<defs><linearGradient id="' + id + '" x1="0" x2="0" y1="0" y2="1">'
				+ '<stop offset="0" stop-color="' + color + '" stop-opacity="0.28"/>'
				+ '<stop offset="1" stop-color="' + color + '" stop-opacity="0"/></linearGradient></defs>'
				+ (fill ? '<path d="' + area + '" fill="url(#' + id + ')"/>' : '')
				+ '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="' + sw + '" stroke-linecap="round" stroke-linejoin="round"/>'
				+ '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="2.6" fill="' + color + '"/></svg>';
		},

		/* TrendLine — line chart, baseline gridline, optional dashed objective. */
		trendline: function (o) {
			o = o || {};
			var data = o.data, w = o.w || 240, h = o.h || 76, color = o.color || 'var(--good)',
				objPct = (o.objPct == null ? null : o.objPct), pad = 6;
			var p = pts(data, w, h, pad), d = path(p), id = uid('tl'), last = p[p.length - 1];
			var area = d + ' L ' + p[p.length - 1][0].toFixed(1) + ' ' + (h - pad) + ' L ' + p[0][0].toFixed(1) + ' ' + (h - pad) + ' Z';
			var mid = pad + (h - pad * 2) * 0.5;
			var objY = objPct != null ? pad + (h - pad * 2) * (1 - objPct) : null;
			return '<svg width="100%" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="display:block">'
				+ '<defs><linearGradient id="' + id + '" x1="0" x2="0" y1="0" y2="1">'
				+ '<stop offset="0" stop-color="' + color + '" stop-opacity="0.24"/>'
				+ '<stop offset="1" stop-color="' + color + '" stop-opacity="0"/></linearGradient></defs>'
				+ '<line x1="' + pad + '" x2="' + (w - pad) + '" y1="' + mid + '" y2="' + mid + '" stroke="var(--grid-line)" stroke-width="1"/>'
				+ (objY != null ? '<line x1="' + pad + '" x2="' + (w - pad) + '" y1="' + objY + '" y2="' + objY + '" stroke="var(--tx-lo)" stroke-width="1" stroke-dasharray="3 3"/>' : '')
				+ '<path d="' + area + '" fill="url(#' + id + ')"/>'
				+ '<path d="' + d + '" fill="none" stroke="' + color + '" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/>'
				+ '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="3.2" fill="var(--bg-2)" stroke="' + color + '" stroke-width="2"/></svg>';
		},

		/* Gauge — radial arc, open-bottom dial, caps just over 100%. */
		gauge: function (o) {
			o = o || {};
			var pct = o.pct == null ? 100 : o.pct, color = o.color || 'var(--good)', size = o.size || 86,
				label = o.label, value = o.value;
			var r = size / 2 - 8, cx = size / 2, cy = size / 2, start = 135, sweep = 270;
			var frac = Math.max(0, Math.min(1.18, pct / 100)) / 1.18;
			function polar(deg) { var a = ((deg - 90) * Math.PI) / 180; return [cx + r * Math.cos(a), cy + r * Math.sin(a)]; }
			function arc(f0, f1) {
				var a0 = start + sweep * f0, a1 = start + sweep * f1, s = polar(a0), e = polar(a1), large = a1 - a0 > 180 ? 1 : 0;
				return 'M ' + s[0].toFixed(2) + ' ' + s[1].toFixed(2) + ' A ' + r + ' ' + r + ' 0 ' + large + ' 1 ' + e[0].toFixed(2) + ' ' + e[1].toFixed(2);
			}
			return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '" style="display:block">'
				+ '<path d="' + arc(0, 1) + '" fill="none" stroke="var(--bg-inset)" stroke-width="7" stroke-linecap="round"/>'
				+ '<path d="' + arc(0, frac) + '" fill="none" stroke="' + color + '" stroke-width="7" stroke-linecap="round"/>'
				+ (value != null ? '<text x="' + cx + '" y="' + (cy - 1) + '" text-anchor="middle" font-family="\'Space Grotesk\',sans-serif" font-size="' + (size * 0.26) + '" font-weight="700" fill="var(--tx-hi)" style="font-variant-numeric:tabular-nums">' + value + '</text>' : '')
				+ (label != null ? '<text x="' + cx + '" y="' + (cy + size * 0.17) + '" text-anchor="middle" font-family="\'JetBrains Mono\',monospace" font-size="' + (size * 0.12) + '" fill="var(--tx-lo)">' + label + '</text>' : '')
				+ '</svg>';
		},

		/* Donut — composition ring, optional center number. segs=[{v,color}]. */
		donut: function (o) {
			o = o || {};
			var segs = o.segs, size = o.size || 78, track = o.track || 'var(--bg-inset)', center = o.center;
			var r = size / 2 - 7, cx = size / 2, cy = size / 2, C = 2 * Math.PI * r;
			var total = segs.reduce(function (s, x) { return s + x.v; }, 0) || 1, off = 0, arcs = '';
			segs.forEach(function (s) {
				var len = (s.v / total) * C;
				arcs += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="' + s.color + '" stroke-width="9" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" stroke-linecap="butt"/>';
				off += len;
			});
			return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '" style="display:block">'
				+ '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="none" stroke="' + track + '" stroke-width="9"/>'
				+ '<g transform="rotate(-90 ' + cx + ' ' + cy + ')">' + arcs + '</g>'
				+ (center != null ? '<text x="' + cx + '" y="' + (cy + size * 0.1) + '" text-anchor="middle" font-family="\'Space Grotesk\',sans-serif" font-size="' + (size * 0.34) + '" font-weight="700" fill="var(--tx-hi)" style="font-variant-numeric:tabular-nums">' + center + '</text>' : '')
				+ '</svg>';
		},

		/* StackBar — single horizontal severity-split bar. segs=[{v,color,l?}]. */
		stackbar: function (o) {
			o = o || {};
			var segs = o.segs, h = o.h || 8, radius = o.radius || 5;
			var total = segs.reduce(function (s, x) { return s + x.v; }, 0) || 1, inner = '';
			segs.forEach(function (s) {
				var t = s.l != null ? (s.l + ': ' + s.v) : String(s.v);
				inner += '<div title="' + t + '" style="width:' + ((s.v / total) * 100) + '%;background:' + s.color + '"></div>';
			});
			return '<div style="display:flex;width:100%;height:' + h + 'px;border-radius:' + radius + 'px;overflow:hidden;background:var(--bg-inset);gap:1.5px">' + inner + '</div>';
		},

		/* ProgressBar — value vs objective fill on an inset track. */
		progressbar: function (o) {
			o = o || {};
			var pct = o.pct == null ? 100 : o.pct, color = o.color || 'var(--good)', h = o.h || 6;
			return '<div style="height:' + h + 'px;border-radius:' + h + 'px;background:var(--bg-inset);overflow:hidden;position:relative">'
				+ '<div style="position:absolute;left:0;top:0;bottom:0;width:' + Math.min(100, pct) + '%;background:' + color + ';border-radius:' + h + 'px"></div></div>';
		},

		/* DualBar — DOMAIN: produced (flag) vs worked (clock) hours. */
		dualbar: function (o) {
			o = o || {};
			var flag = o.flag, clk = o.clk, color = o.color || 'var(--warn)', m = o.max || Math.max(flag, clk);
			return '<div style="display:flex;flex-direction:column;gap:3px;flex:1">'
				+ '<div style="height:7px;border-radius:4px;background:var(--bg-inset);position:relative"><div style="position:absolute;inset:0;width:' + ((clk / m) * 100) + '%;background:var(--line-2);border-radius:4px"></div></div>'
				+ '<div style="height:7px;border-radius:4px;background:var(--bg-inset);position:relative"><div style="position:absolute;inset:0;width:' + ((flag / m) * 100) + '%;background:' + color + ';border-radius:4px"></div></div>'
				+ '</div>';
		},

		/* Columns — DOMAIN: paired promised(a) vs completed(b) columns. */
		columns: function (o) {
			o = o || {};
			var a = o.a, b = o.b, w = o.w || 300, h = o.h || 70, ca = o.ca || 'var(--accent)', cb = o.cb || 'var(--good)';
			var max = Math.max(Math.max.apply(null, a), Math.max.apply(null, b)) || 1, n = a.length, bw = (w / n) * 0.34, g = '';
			a.forEach(function (v, i) {
				var x = (i + 0.5) * (w / n), ha = (v / max) * (h - 10), hb = (b[i] / max) * (h - 10);
				g += '<g><rect x="' + (x - bw - 1) + '" y="' + (h - ha) + '" width="' + bw + '" height="' + ha + '" rx="1.5" fill="' + ca + '" opacity="0.45"/>'
					+ '<rect x="' + (x + 1) + '" y="' + (h - hb) + '" width="' + bw + '" height="' + hb + '" rx="1.5" fill="' + cb + '"/></g>';
			});
			return '<svg width="100%" height="' + h + '" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="display:block">' + g + '</svg>';
		}
	};

	/* ---- declarative auto-mount ---- */
	function num(v, d) { v = parseFloat(v); return isNaN(v) ? d : v; }
	function mount(el) {
		var kind = el.getAttribute('data-viz'); if (!kind || !TitanViz[kind]) return;
		var o = {};
		var data = el.getAttribute('data-data'); if (data) { try { o.data = JSON.parse(data); } catch (e) {} }
		var segs = el.getAttribute('data-segs'); if (segs) { try { o.segs = JSON.parse(segs); } catch (e) {} }
		['color', 'ca', 'cb', 'track', 'label', 'value', 'center'].forEach(function (k) {
			var v = el.getAttribute('data-' + k); if (v != null) o[k] = v;
		});
		['w', 'h', 'sw', 'size', 'pct', 'radius', 'flag', 'clk', 'max', 'objPct'].forEach(function (k) {
			var v = el.getAttribute('data-' + (k === 'objPct' ? 'obj-pct' : k)); if (v != null) o[k] = num(v);
		});
		if (el.getAttribute('data-a')) { try { o.a = JSON.parse(el.getAttribute('data-a')); } catch (e) {} }
		if (el.getAttribute('data-b')) { try { o.b = JSON.parse(el.getAttribute('data-b')); } catch (e) {} }
		if (el.getAttribute('data-fill') === 'false') o.fill = false;
		el.innerHTML = TitanViz[kind](o);
	}
	TitanViz.render = function (root) {
		(root || document).querySelectorAll('.t-viz[data-viz]').forEach(mount);
	};
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { TitanViz.render(); });
	} else {
		TitanViz.render();
	}

	global.TitanViz = TitanViz;
})(window);
