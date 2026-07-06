/* Shared interactive Recommend panel (Packaging + FP Stock Order pages).
 * The server (ajax/build/recommend.php) returns per-product demand COMPONENTS
 * (online, per-show, large_po, committed, on_hand, pipeline, buildable). This panel
 * computes Demand and Build under a filter state. A conversational assistant
 * (recommend_adjust.php) holds the full dataset — it explains the reasoning and
 * changes the filters/buffer when asked; the browser does the final math.
 *
 * Page provides: #recUntil, #recBtn, #recMsg, #recResults (+ optional #recWarehouse)
 * and calls initRecommendPanel({ addEndpoint, addMode:'placed'|'pending',
 *   getWarehouse:fn, getWarehouseName:fn }).
 */
(function () {
	// Large prior-year POs are BYPASSED by default (they rarely repeat and any that
	// recur are already in committed). buffer_pct is an optional safety-stock %.
	function defaultFilters() { return { mode: 'all', excluded_shows: [], include_committed: true, include_large_po: false, buffer_pct: 0 }; }

	window.initRecommendPanel = function (cfg) {
		var components = [], meta = {}, shows = [];
		var filters = defaultFilters();
		var chatHistory = [];   // [{role:'user'|'assistant', content}]
		var pending = false;

		function fmt(n) { return Number(n || 0).toLocaleString(); }
		function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
		function whId() { return cfg.getWarehouse ? cfg.getWarehouse() : 0; }
		function whName() { return cfg.getWarehouseName ? cfg.getWarehouseName() : ''; }

		function demandFor(r) {
			var d;
			if (filters.mode === 'online_only') d = Number(r.online || 0);
			else {
				d = Number(r.online || 0);
				var sh = r.shows || {};
				for (var name in sh) { if (filters.excluded_shows.indexOf(name) === -1) d += Number(sh[name] || 0); }
				if (filters.include_large_po) d += Number(r.large_po || 0);
				if (filters.include_committed) d += Number(r.committed || 0);
			}
			var buf = Number(filters.buffer_pct || 0);
			if (buf) d = Math.round(d * (1 + buf / 100));
			return d;
		}
		function buildFor(r) { return Math.max(0, demandFor(r) - Number(r.on_hand || 0) - Number(r.pipeline || 0)); }

		function snapshot() {
			return {
				meta: meta,
				components: components.map(function (r) {
					return { product: r.product, sku: r.sku, online: Number(r.online || 0), shows: r.shows || {}, large_po: Number(r.large_po || 0), committed: Number(r.committed || 0), on_hand: Number(r.on_hand || 0), pipeline: Number(r.pipeline || 0), buildable: Number(r.buildable || 0), limit_part: r.limit_part || null };
				}),
				results: components.map(function (r) { return { product: r.product, demand: demandFor(r), build: buildFor(r) }; })
			};
		}

		function renderChat() {
			var h = '';
			chatHistory.forEach(function (m) {
				if (m.role === 'user') h += '<div class="text-end mb-2"><span style="display:inline-block;background:#6f42c1;color:#fff;padding:4px 9px;border-radius:10px;max-width:85%;text-align:left;">' + esc(m.content) + '</span></div>';
				else h += '<div class="mb-2"><span style="display:inline-block;background:#fff;border:1px solid #e6e0f5;padding:5px 10px;border-radius:10px;max-width:92%;">' + esc(m.content).replace(/\n/g, '<br>') + '</span></div>';
			});
			if (pending) h += '<div class="mb-1 text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Thinking…</div>';
			var $c = $('#recChat');
			$c.html(h || '<div class="text-muted small">Reading the data…</div>');
			if ($c[0]) $c.scrollTop($c[0].scrollHeight);
		}

		function render() {
			if (!components.length) { $('#recResults').html('<div class="text-muted small mt-2">Nothing needs building for this window — stock and pipeline already cover projected demand. 🎉</div>'); return; }
			var untilLabel = meta.until || '', poThresh = meta.large_po_threshold || 2000;
			var hasLargePo = components.some(function (r) { return Number(r.large_po || 0) > 0; });

			var controls = '';
			if (hasLargePo) {
				var poOrders = meta.large_po_orders || [];
				var poList = poOrders.slice(0, 6).map(function (o) { return esc(o.name) + ' ($' + fmt(o.value) + ')'; }).join(', ');
				controls = '<div class="mt-2 p-2 rounded" style="background:#fff7e6;border:1px solid #ffe1a8;font-size:0.8rem;">' +
					'<label style="cursor:pointer;margin:0;"><input type="checkbox" id="recInclLargePo"' + (filters.include_large_po ? ' checked' : '') + '> ' +
					'<strong>Include large POs (&gt;$' + fmt(poThresh) + ') from this window last year</strong></label>' +
					(poList ? '<div class="text-muted mt-1" style="font-size:0.73rem;"><strong>Bypassed by default</strong> — big one-off POs rarely repeat, and any that do are already counted in <em>committed</em> this year (so counting last year\'s too would double-build). Last year: ' + poList + (poOrders.length > 6 ? ', …' : '') + '. Check only to add them back into demand.</div>' : '') +
					'</div>';
			}

			var html = controls + '<div class="table-responsive mt-2"><table class="table dash-table align-middle" style="font-size:0.85rem;"><thead><tr>' +
				'<th>Product</th>' +
				'<th class="text-center">Demand<br><span class="text-muted fw-normal" style="font-size:0.7rem;">through ' + esc(untilLabel) + '</span></th>' +
				(hasLargePo ? '<th class="text-center">Large PO\'s<br><span class="text-muted fw-normal" style="font-size:0.7rem;">last yr &gt;$' + fmt(poThresh) + '</span></th>' : '') +
				'<th class="text-center">FP On-Hand</th>' +
				'<th class="text-center">Recommended Build</th>' +
				'<th class="text-center">Buildable Now</th>' +
				'</tr></thead><tbody>';
			var toBuild = [];
			components.forEach(function (r) {
				var demand = demandFor(r), build = buildFor(r);
				if (demand <= 0 && build <= 0) return;
				if (build > 0) toBuild.push({ prodid: r.prodid, qty: build, product: r.product });
				var short = Math.max(0, build - Number(r.buildable || 0)), buildCell;
				if (build <= 0) buildCell = '<span class="text-muted">—</span>';
				else if (short > 0) buildCell = '<span style="color:#e64545;font-weight:700;">' + fmt(r.buildable) + '</span><br><span class="text-danger" style="font-size:0.68rem;">short ' + fmt(short) + ' — need ' + esc(r.limit_part || 'raw materials') + '</span>';
				else buildCell = '<span style="color:#2ca01c;font-weight:700;">' + fmt(r.buildable) + '</span>';
				var poCell = '';
				if (hasLargePo) {
					var po = Number(r.large_po || 0);
					if (po <= 0) poCell = '<td class="text-center text-muted">—</td>';
					else if (filters.include_large_po) poCell = '<td class="text-center" style="color:#b8860b;font-weight:600;">' + fmt(po) + '</td>';
					else poCell = '<td class="text-center text-muted"><span class="text-decoration-line-through">' + fmt(po) + '</span></td>';
				}
				html += '<tr>' +
					'<td class="fw-semibold">' + esc(r.product) + (r.sku ? ' <span class="text-muted" style="font-size:0.7rem;">· ' + esc(r.sku) + '</span>' : '') + '</td>' +
					'<td class="text-center fw-semibold">' + fmt(demand) + '</td>' +
					poCell +
					'<td class="text-center">' + fmt(r.on_hand) + '</td>' +
					'<td class="text-center"><span style="color:#6f42c1;font-weight:800;font-size:1.25rem;">' + fmt(build) + '</span></td>' +
					'<td class="text-center">' + buildCell + '</td>' +
					'</tr>';
			});
			html += '</tbody></table></div>';

			var inc = [];
			if (filters.mode === 'online_only') inc.push('online only');
			else {
				inc.push('online');
				var incShows = shows.filter(function (s) { return filters.excluded_shows.indexOf(s) === -1; });
				if (incShows.length) inc.push('shows: ' + incShows.join(', '));
				if (hasLargePo && filters.include_large_po) inc.push('large POs');
				if (filters.include_committed) inc.push('committed');
			}
			if (Number(filters.buffer_pct || 0) > 0) inc.push('+' + filters.buffer_pct + '% buffer');
			var exclNote = filters.excluded_shows.length ? ' · excluded: ' + esc(filters.excluded_shows.join(', ')) : '';

			html += '<div class="text-muted mt-2" style="font-size:0.72rem;">Including: ' + esc(inc.join(' + ')) + exclNote + '. Recommended Build = Demand − On-Hand − Pipeline. <a href="#" id="recResetFilters">reset</a></div>';

			html += '<div class="mt-3"><div class="fw-semibold small mb-1"><i class="ti ti-message-2 me-1"></i>Ask about or adjust this build</div>' +
				'<div id="recChat" style="max-height:300px;overflow-y:auto;background:#f6f4fb;border:1px solid #e6e0f5;border-radius:6px;padding:8px;font-size:0.83rem;"></div>' +
				'<div class="mt-2 d-flex gap-2 align-items-center" style="max-width:760px;">' +
				'<input type="text" id="recChatInput" class="form-control form-control-sm" placeholder="Ask why a number is what it is, or change it — e.g. “why is LDA 578?”, “add a 10% safety buffer”, “drop Delta”, “ignore committed”">' +
				'<button id="recChatSend" class="btn btn-sm btn-primary"' + (pending ? ' disabled' : '') + '>Send</button></div></div>';

			window._recToBuild = toBuild;
			if (toBuild.length) {
				html += '<div class="mt-3 d-flex align-items-center gap-2 flex-wrap">' +
					'<button id="recAddBtn" class="btn btn-sm btn-success"><i class="ti ti-plus me-1"></i>Add ' + toBuild.length + ' ' + (cfg.addMode === 'pending' ? 'to pending order' : 'as packaging orders') + '</button>' +
					'<span class="text-muted small">into <strong>' + esc(whName() || 'current warehouse') + '</strong>' + (cfg.addMode === 'pending' ? ' — review below, then Send Order.' : ' — edit or remove them below.') + '</span>' +
					'<span id="recAddMsg" class="small ms-1"></span></div>';
			}
			$('#recResults').html(html);
			renderChat();
		}

		function loadComponents() {
			var until = $('#recUntil').val();
			if (!until) { alert('Choose a target date.'); return; }
			var $btn = $('#recBtn').prop('disabled', true).html('<i class="ti ti-loader me-1"></i>Analyzing…');
			$('#recMsg').removeClass('text-danger').addClass('text-muted').text('Pulling sales history, tradeshows & Shopify stock…');
			$('#recResults').html('');
			filters = defaultFilters();
			chatHistory = [];
			$.ajax({ url: '/ajax/build/recommend.php', method: 'POST', dataType: 'json', timeout: 120000, data: { until: until, warehouse_id: whId() } })
			.done(function (d) {
				if (!d || d.error) { $('#recMsg').removeClass('text-muted').addClass('text-danger').text(d && d.error ? d.error : 'Could not build a recommendation.'); return; }
				components = d.rows || []; meta = d.meta || {}; shows = meta.shows || [];
				$('#recMsg').removeClass('text-danger').addClass('text-muted').html('<strong>' + esc(meta.warehouse || 'All') + '</strong> — demand through ' + esc(meta.until) + ' (' + meta.window_days + ' days); baseline last year ' + esc(meta.prior_window) + '.');
				render();
				askAssistant('');   // auto reasoning summary
			})
			.fail(function (xhr, status) { $('#recMsg').removeClass('text-muted').addClass('text-danger').text(status === 'timeout' ? 'Timed out pulling Shopify data — try again.' : 'Request failed (' + (xhr.status || '?') + ').'); })
			.always(function () { $btn.prop('disabled', false).html('<i class="ti ti-bulb me-1"></i>Recommend'); });
		}

		function askAssistant(message) {
			var prior = chatHistory.slice(-12);
			if (message) chatHistory.push({ role: 'user', content: message });
			pending = true;
			renderChat();
			$('#recChatSend').prop('disabled', true);
			$.post('/ajax/build/recommend_adjust.php', {
				message: message,
				filters: JSON.stringify(filters),
				shows: JSON.stringify(shows),
				history: JSON.stringify(prior),
				data: JSON.stringify(snapshot())
			}, function (res) {
				pending = false;
				if (res && res.ok) {
					if (res.filters) filters = res.filters;
					chatHistory.push({ role: 'assistant', content: res.reply || '(no answer)' });
				} else {
					chatHistory.push({ role: 'assistant', content: (res && res.error) || 'Could not process that.' });
				}
				render();
			}, 'json').fail(function () {
				pending = false;
				chatHistory.push({ role: 'assistant', content: 'Request failed — try again.' });
				render();
			});
		}

		$(document).on('click', '#recBtn', loadComponents);
		$(document).on('change', '#recInclLargePo', function () { filters.include_large_po = $(this).is(':checked'); render(); });
		$(document).on('click', '#recChatSend', function () { if (pending) return; var m = $.trim($('#recChatInput').val()); if (!m) return; $('#recChatInput').val(''); askAssistant(m); });
		$(document).on('keypress', '#recChatInput', function (e) { if (e.which === 13) { e.preventDefault(); $('#recChatSend').click(); } });
		$(document).on('click', '#recResetFilters', function (e) { e.preventDefault(); filters = defaultFilters(); render(); askAssistant('reset to the default recommendation'); });

		$(document).on('click', '#recAddBtn', function () {
			var items = window._recToBuild || [];
			if (!items.length) return;
			var $btn = $(this);
			var lines = items.map(function (i) { return '  • ' + i.product + ': ' + fmt(i.qty); }).join('\n');
			if (!confirm('Create ' + items.length + ' order(s) in "' + (whName() || 'current warehouse') + '"?\n\n' + lines + '\n\n(No inventory is deducted until you finalize.)')) return;
			$btn.prop('disabled', true).html('<i class="ti ti-loader me-1"></i>Adding…');
			var until = $('#recUntil').val();
			if (cfg.addMode === 'pending') {
				(function next(i) {
					if (i >= items.length) { location.reload(); return; }
					$.post(cfg.addEndpoint, { prodid: items[i].prodid, qty: items[i].qty, warehouse_id: whId(), source: 'recommend', until: until }, function () { next(i + 1); })
					 .fail(function () { alert('Failed adding ' + items[i].product); $btn.prop('disabled', false).html('<i class="ti ti-plus me-1"></i>Add'); });
				})(0);
			} else {
				$.post(cfg.addEndpoint, { orders: JSON.stringify(items.map(function (i) { return { prodid: i.prodid, qty: i.qty }; })), warehouse_id: whId(), until: until, source: 'recommend' }, function (res) {
					if (typeof res === 'string' && res.indexOf('ok:') === 0) { location.reload(); }
					else { $('#recAddMsg').addClass('text-danger').text('Error: ' + res); $btn.prop('disabled', false).html('<i class="ti ti-plus me-1"></i>Add'); }
				}).fail(function (xhr) { $('#recAddMsg').addClass('text-danger').text('Failed: ' + (xhr.responseText || xhr.status)); $btn.prop('disabled', false).html('<i class="ti ti-plus me-1"></i>Add'); });
			}
		});
	};
})();
