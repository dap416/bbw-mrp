/* ============================================================
   Cash Flow module — client interactions (jQuery).
   Depends on the CF data island printed by cash_flow.php.
   ============================================================ */
(function ($) {
	'use strict';

	const MONTHS = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

	/* ---- helpers ---- */
	function money(n){ const neg = n < -0.5; const s = '$' + Math.round(Math.abs(Number(n)||0)).toLocaleString('en-US'); return neg ? '−' + s : s; }
	function addMonths(ym, n){ let [y,m] = ym.split('-').map(Number); let idx = y*12 + (m-1) + n; return Math.floor(idx/12) + '-' + String((idx%12)+1).padStart(2,'0'); }
	function monthDiff(a, b){ let [ay,am]=a.split('-').map(Number), [by,bm]=b.split('-').map(Number); return (by-ay)*12 + (bm-am); }
	function mLabel(i){ const c = CF.cols[i]; return c ? (c.name + ' ' + c.year) : ''; }
	function mLabelAbs(ym){ const [y,m] = ym.split('-').map(Number); return MONTHS[m-1] + " '" + String(y).slice(2); }
	function posts(r, i){ const cell = addMonths(CF.hs, i); if (cell < r.start_ym) return false; const d = monthDiff(r.start_ym, cell); const k = r.recurrence; if (k==='monthly') return true; if (k==='quarterly') return d%3===0; if (k==='annual') return d%12===0; return d===0; }
	function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
	function payLabel(p){ if (!p || p === 'cash') return 'Cash'; const o = CF.payOptions.find(x => x.v === p); return o ? o.label : p; }
	function recLabel(k){ const o = CF.recOptions.find(x => x[0] === k); return o ? o[1] : k; }

	function openModal(html){ const $b = $('<div class="t-backdrop"></div>').html(html); $('#cfModalRoot').empty().append($b); $b.on('mousedown', function(e){ if (e.target === this) closeModal(); }); return $b; }
	function closeModal(){ $('#cfModalRoot').empty(); $('.cf-pay-menu').remove(); }

	function post(url, data){ return $.post(url, data); }

	/* ---- view switching ---- */
	// Nearly every save in here ends in location.reload(), which would otherwise
	// drop you back on the Cash Flow tab. Remember the open view for the tab's
	// lifetime so editing a balance leaves you where you were working.
	const VIEW_KEY = 'cfView';
	function showView(v){
		if (!v || !$('#view-' + v).length) return false;
		$('#cfViews button').removeClass('on').filter('[data-view="' + v + '"]').addClass('on');
		$('.cf-view').removeClass('on'); $('#view-' + v).addClass('on');
		return true;
	}
	$('#cfViews button').on('click', function(){
		const v = $(this).data('view');
		try { sessionStorage.setItem(VIEW_KEY, v); } catch (e) {}
		showView(v);
	});
	try {
		const saved = sessionStorage.getItem(VIEW_KEY);
		if (saved && !showView(saved)) sessionStorage.removeItem(VIEW_KEY);
	} catch (e) {}

	/* ---- chart toggle ---- */
	$('#cfChartToggle').on('click', function(){
		const $c = $('#cfChart'); $c.toggle();
		$(this).find('span').text($c.is(':visible') ? 'Hide chart' : 'Show chart');
	});

	/* ---- income caret (expand channel rows) ---- */
	$('#cfIncCaret').on('click', function(){
		const $rows = $('.cf-child'); const open = $rows.first().is(':visible');
		$rows.toggle(!open); $(this).html(open ? '▸' : '▾');
	});

	/* ---- control-bar knobs (save + reload) ---- */
	function saveSetting(payload){ return post('/ajax/cashflow/save_settings.php', payload); }
	$('#cfBuffer').on('change', function(){ saveSetting({ cash_buffer: $(this).val() }).always(() => location.reload()); });
	$('#cfTax').on('change', function(){ saveSetting({ avg_sales_tax_pct: $(this).val() }).always(() => location.reload()); });
	$('#cfAvailDebt').on('change', function(){ saveSetting({ cf_avail_debt: $(this).val() }).always(() => location.reload()); });

	/* ============ RECORDS MODAL (managed cells) ============ */
	let modalCtx = null;   // {row, month, chan}
	let formState = null;  // active add/edit form

	$(document).on('click', '.cf-cb', function(){
		modalCtx = { row: $(this).data('row'), month: parseInt($(this).data('m'), 10), chan: $(this).data('chan') || '' };
		formState = null;
		renderRecordsModal();
	});

	function recordsFor(){
		const list = (CF.records[modalCtx.row] || []).filter(r => posts(r, modalCtx.month));
		return modalCtx.chan ? list.filter(r => r.sub === modalCtx.chan) : list;
	}

	function renderRecordsModal(){
		const labelMap = { income:'Sales income', operating:'Operating', purchase:'Purchases' };
		const isIncome = modalCtx.row === 'income';
		const title = labelMap[modalCtx.row] + (modalCtx.chan ? ' · ' + modalCtx.chan : '');
		const recs = recordsFor();
		let total = 0; recs.forEach(r => total += Number(r.amount) || 0);

		let rowsHtml = recs.map(r => {
			return '<tr data-id="' + r.id + '">'
				+ (isIncome ? '<td>' + esc(r.sub || '') + '</td>' : '')
				+ '<td>' + esc(r.description || '') + '</td>'
				+ (!isIncome ? '<td class="id">' + esc(payLabel(r.pay)) + '</td>' : '')
				+ '<td class="num" style="text-align:right">' + money(r.amount) + '</td>'
				+ '<td class="id">' + esc(recLabel(r.recurrence)) + '</td>'
				+ '<td style="text-align:right;white-space:nowrap">'
				+ '<button class="t-btn sm icon cf-rec-edit" data-id="' + r.id + '">edit</button> '
				+ '<button class="t-btn sm icon cf-rec-del" data-id="' + r.id + '">×</button></td></tr>';
		}).join('');
		if (!recs.length) rowsHtml = '<tr><td colspan="6" style="color:var(--tx-lo);padding:16px">No records posting in ' + mLabel(modalCtx.month) + ' yet.</td></tr>';

		const head = '<thead><tr>'
			+ (isIncome ? '<th>Category</th>' : '')
			+ '<th>Description</th>'
			+ (!isIncome ? '<th>Payment</th>' : '')
			+ '<th style="text-align:right">Amount</th><th>Recurrence</th><th></th></tr></thead>';

		const html = '<div class="t-modal" style="max-width:640px"><div class="cf-modal-body">'
			+ '<div class="cf-modal-head"><div><h6 class="t-panel-title">' + esc(title) + '</h6>'
			+ '<div class="t-eyebrow" style="margin-top:3px">' + mLabel(modalCtx.month) + '</div></div>'
			+ '<button class="cf-x" id="cfModalX">&times;</button></div>'
			+ '<div class="t-scroll" style="max-height:300px"><table class="t-table">' + head + '<tbody>' + rowsHtml + '</tbody>'
			+ '<tfoot><tr><td colspan="' + (isIncome ? 2 : 3) + '"></td><td class="num" style="text-align:right;color:var(--tx-hi)">' + money(total) + '</td><td colspan="2"></td></tr></tfoot></table></div>'
			+ '<div id="cfFormSlot"></div>'
			+ '<div style="margin-top:14px"><button class="t-btn solid" id="cfAddRec">+ Add record</button></div>'
			+ '</div></div>';
		openModal(html);
		if (formState) renderForm();
	}

	$(document).on('click', '#cfModalX', closeModal);
	$(document).on('click', '#cfAddRec', function(){ formState = { id: 0, sub: modalCtx.chan || 'Online', description: '', amount: '', note: '', recurrence: 'once', pay: 'cash', start_ym: CF.cols[modalCtx.month].ym }; renderForm(); });
	$(document).on('click', '.cf-rec-edit', function(){ const id = $(this).data('id'); const r = (CF.records[modalCtx.row] || []).find(x => x.id === id); if (r){ formState = Object.assign({}, r); renderForm(); } });
	$(document).on('click', '.cf-rec-del', function(){ const id = $(this).data('id'); if (!confirm('Delete this record?')) return; post('/ajax/cash_flow/delete_record.php', { id }).always(() => location.reload()); });

	function renderForm(){
		const isIncome = modalCtx.row === 'income';
		const f = formState;
		let h = '<hr class="t-rule"><div class="t-field-group" style="display:flex;flex-direction:column;gap:12px">';
		if (isIncome){
			h += '<div class="t-field"><span class="t-label">Category</span><div class="cf-seg2" id="cfFsub">'
				+ CF.subOptions.map(s => '<button class="' + (s === f.sub ? 'on' : '') + '" data-v="' + s + '">' + s + '</button>').join('') + '</div></div>';
		}
		h += '<div class="t-field"><span class="t-label">Description</span><div class="t-input"><input id="cfFdesc" value="' + esc(f.description) + '" placeholder="What is this?"></div></div>';
		h += '<div class="cf-fieldrow">';
		h += '<div class="t-field"><span class="t-label">Amount</span><div class="t-input"><span style="color:var(--tx-lo)">$</span><input id="cfFamt" value="' + esc(f.amount) + '"></div></div>';
		if (!isIncome){
			h += '<div class="t-field"><span class="t-label">Payment method</span><button type="button" class="t-btn" id="cfFpay" data-pay="' + esc(f.pay) + '" style="justify-content:space-between">' + esc(payLabel(f.pay)) + '</button></div>';
		}
		h += '</div>';
		h += '<div class="t-field"><span class="t-label">Recurrence</span><div class="cf-seg2" id="cfFrec">'
			+ CF.recOptions.map(o => '<button class="' + (o[0] === f.recurrence ? 'on' : '') + '" data-v="' + o[0] + '">' + o[1] + '</button>').join('') + '</div>'
			+ '<div class="cf-note-inline" id="cfRecNotice"></div></div>';
		h += '<div class="t-field"><span class="t-label">Note</span><div class="t-input"><input id="cfFnote" value="' + esc(f.note || '') + '"></div></div>';
		h += '<div style="display:flex;gap:8px;justify-content:flex-end"><button class="t-btn" id="cfFcancel">Cancel</button><button class="t-btn solid" id="cfFsave">Save</button></div>';
		h += '</div>';
		$('#cfFormSlot').html(h);
		$('#cfAddRec').hide();
		updateRecNotice();
	}
	function updateRecNotice(){ const rec = formState.recurrence; const mi = monthDiff(CF.hs, formState.start_ym); const l = mLabelAbs(formState.start_ym);
		let t; if (rec === 'once') t = 'Posts once in ' + l + '.';
		else { const step = rec === 'monthly' ? 1 : rec === 'quarterly' ? 3 : 12; const next = mLabelAbs(addMonths(formState.start_ym, step)); t = 'Starts ' + l + ', then ' + rec + ' — next ' + next + '.'; }
		$('#cfRecNotice').text(t);
	}
	$(document).on('click', '#cfFsub button', function(){ $('#cfFsub button').removeClass('on'); $(this).addClass('on'); formState.sub = $(this).data('v'); });
	$(document).on('click', '#cfFrec button', function(){ $('#cfFrec button').removeClass('on'); $(this).addClass('on'); formState.recurrence = $(this).data('v'); updateRecNotice(); });
	$(document).on('click', '#cfFcancel', function(){ formState = null; $('#cfFormSlot').empty(); $('#cfAddRec').show(); });
	$(document).on('click', '#cfFsave', function(){
		const amt = parseFloat(($('#cfFamt').val() || '').replace(/[^0-9.\-]/g, '')) || 0;
		const payload = { id: formState.id || 0, rtype: modalCtx.row, sub: (modalCtx.row === 'income' ? formState.sub : ''),
			amount: amt, description: $('#cfFdesc').val() || '', note: $('#cfFnote').val() || '',
			recurrence: formState.recurrence, start_ym: formState.start_ym, pay: (modalCtx.row === 'income' ? 'cash' : ($('#cfFpay').data('pay') || 'cash')) };
		const $b = $(this).prop('disabled', true);
		post('/ajax/cash_flow/save_record.php', payload)
			.done(r => { if (r && r.ok) location.reload(); else { alert((r && r.error) || 'Save failed'); $b.prop('disabled', false); } })
			.fail(() => { alert('Save failed'); $b.prop('disabled', false); });
	});

	/* ---- payment-method menu ---- */
	$(document).on('click', '#cfFpay', function(e){
		e.stopPropagation(); $('.cf-pay-menu').remove();
		const rect = this.getBoundingClientRect();
		const cur = $(this).data('pay');
		const $m = $('<div class="cf-pay-menu"></div>');
		CF.payOptions.forEach(o => { $m.append('<button data-v="' + esc(o.v) + '">' + esc(o.label) + (o.v === cur ? ' ✓' : '') + '</button>'); });
		// Must live INSIDE .titan-app: the design tokens (--bg-2, --tx-mid, …) are
		// scoped to that element, so a menu parented to <body> resolved them to
		// nothing and rendered transparent with unstyled text. Positioned fixed, so
		// the viewport rect is the right coordinate space either way.
		$m.css({ top: (rect.bottom + 4) + 'px', left: rect.left + 'px' });
		$(document.querySelector('.titan-app') || document.body).append($m);
		$m.on('click', 'button', function(){ formState.pay = $(this).data('v'); $('#cfFpay').data('pay', formState.pay).text(payLabel(formState.pay)); $m.remove(); });
	});
	$(document).on('click', function(){ $('.cf-pay-menu').remove(); });

	/* ============ DEBT: planned + assist ============ */
	$(document).on('change', '.cf-planned-inp', function(){
		const id = $(this).data('id'); const val = parseFloat(($(this).val() || '').replace(/[^0-9.\-]/g, '')) || 0;
		post('/ajax/cash_flow/save_planned.php', { id, planned: val }).always(() => location.reload());
	});
	$('#cfFocus').on('click', function(){
		const debts = CF.suggest; let focusLabel = '', focusApr = '';
		(CF.accounts.cards || []).concat([]).forEach(() => {});
		// reveal suggestions
		$('.cf-suggest-cell').each(function(){ const k = $(this).data('key'); if (CF.suggest[k] != null) $(this).text(money(CF.suggest[k])); });
		$('.cf-use').show(); $('#cfUseAll').show();
		$('#cfAssistTitle').text('Where to focus');
		$('#cfAssistText').text('Avalanche: every debt gets its minimum, and the rest of your monthly amount goes to the highest-APR balance first. Suggested amounts appear beside each debt — use one, or Use all.');
		$('#cfAssist').prop('hidden', false);
	});
	$('#cfAssistX').on('click', function(){ $('#cfAssist').prop('hidden', true); });
	$(document).on('click', '.cf-use', function(){ const id = $(this).data('id'); const k = $(this).data('key'); const v = CF.suggest[k]; if (v == null) return; post('/ajax/cash_flow/save_planned.php', { id, planned: Math.round(v) }).always(() => location.reload()); });
	$('#cfUseAll').on('click', function(){
		const calls = [];
		$('.cf-planned-inp').each(function(){ const id = $(this).data('id'); const k = 'card_' + id; }); // map below by row
		$('#view-debt tbody tr').each(function(){ const id = $(this).data('id'); const k = $(this).data('key'); if (CF.suggest[k] != null) calls.push(post('/ajax/cash_flow/save_planned.php', { id, planned: Math.round(CF.suggest[k]) })); });
		$.when.apply($, calls).always(() => location.reload());
	});
	$('#cfAfford').on('click', function(){
		const a = CF.afford;
		const over = a.current > a.amount;
		const html = '<div class="t-modal" style="max-width:460px"><div class="cf-modal-body">'
			+ '<div class="cf-modal-head"><h6 class="t-panel-title">What you can afford</h6><button class="cf-x" id="cfModalX">&times;</button></div>'
			+ '<div style="font-family:var(--font-num);font-size:34px;font-weight:700;color:var(--tx-hi)">' + money(a.amount) + '<span style="font-size:14px;color:var(--tx-lo)"> / mo</span></div>'
			+ '<div style="font-size:12.5px;color:var(--tx-mid);margin:8px 0 14px">The steady monthly debt payment that keeps ending cash at or above your ' + money(a.buffer) + ' buffer every month.</div>'
			+ '<table class="t-table"><tbody>'
			+ '<tr><td>Cash buffer held</td><td class="num" style="text-align:right">' + money(a.buffer) + '</td></tr>'
			+ '<tr><td>Tightest month</td><td class="num" style="text-align:right">' + mLabel(a.tight) + '</td></tr>'
			+ '<tr><td>Currently planned</td><td class="num" style="text-align:right">' + money(a.current) + ' / mo</td></tr>'
			+ '</tbody></table>'
			+ (over ? '<div class="t-chip crit" style="margin-top:12px">Your current plan exceeds this — it dips below buffer in tight months</div>' : '')
			+ '<div class="t-eyebrow" style="margin-top:12px">Computed from current projections</div>'
			+ '<div style="display:flex;justify-content:flex-end;margin-top:14px"><button class="t-btn solid" id="cfUseAfford">Use ' + money(a.amount) + ' / mo</button></div>'
			+ '</div></div>';
		openModal(html);
	});
	$(document).on('click', '#cfUseAfford', function(){ saveSetting({ cf_avail_debt: Math.round(CF.afford.amount) }).always(() => location.reload()); });

	/* ============ ACCOUNTS MODAL ============ */
	const ACCT_FIELDS = {
		banks: [['label','Account name','text'],['balance','Balance','money'],['as_of','As of','text']],
		cards: [['label','Card','text'],['balance','Balance','money'],['limit','Credit limit','money'],['apr','APR','pct'],['as_of','As of','text']],
		locs:  [['label','Loan / label','text'],['facility','Line of credit (shared across its loans)','text'],['drawn','Drawn','money'],['ceiling','Ceiling of the line (shared)','money'],['apr','APR','pct'],['payment','Monthly payment','money'],['due_day','Due day','text'],['as_of','As of','text']],
	};
	function acctRecord(group, id){ const arr = CF.accounts[group] || []; return arr.find(a => a.id === id) || null; }
	$(document).on('click', '.cf-acct-add', function(){ openAcctModal($(this).data('group'), null); });
	$(document).on('click', '.cf-acct-edit', function(){ openAcctModal($(this).data('group'), acctRecord($(this).data('group'), $(this).data('id'))); });

	function openAcctModal(group, rec){
		const isEdit = !!rec;
		const gl = { banks:'cash account', cards:'card', locs:'facility' }[group];
		const fields = ACCT_FIELDS[group];
		let body = '<div class="cf-fieldrow">';
		fields.forEach(f => {
			const [k, lbl, type] = f;
			let val = rec ? (rec[k] != null ? rec[k] : '') : '';
			const pre = type === 'money' ? '<span style="color:var(--tx-lo)">$</span>' : '';
			const suf = type === 'pct' ? '<span style="color:var(--tx-lo)">%</span>' : '';
			body += '<div class="t-field"><span class="t-label">' + lbl + '</span><div class="t-input">' + pre + '<input data-k="' + k + '" value="' + esc(val) + '">' + suf + '</div></div>';
		});
		body += '</div>';
		// QuickBooks link — when set, this account's balance is auto-synced nightly.
		const curQb = rec ? (rec.qb_id || '') : '';
		if ((CF.qbAccounts || []).length){
			body += '<div class="t-field" style="margin-top:12px"><span class="t-label">QuickBooks account · auto-sync balance</span><div class="t-input"><select data-k="qb_account_id">'
				+ '<option value="">— Manual (no sync) —</option>'
				+ CF.qbAccounts.map(a => '<option value="' + esc(a.id) + '"' + (a.id === curQb ? ' selected' : '') + '>' + esc(a.name) + '</option>').join('')
				+ '</select></div><div class="cf-note-inline">Linked accounts refresh their balance from QuickBooks every night — you won\'t hand-enter them.</div></div>';
		} else {
			body += '<input type="hidden" data-k="qb_account_id" value="' + esc(curQb) + '"><div class="cf-note-inline" style="margin-top:10px">QuickBooks isn\'t connected — balances stay manual.</div>';
		}
		const html = '<div class="t-modal" style="max-width:520px"><div class="cf-modal-body">'
			+ '<div class="cf-modal-head"><h6 class="t-panel-title">' + (isEdit ? 'Edit ' : 'New ') + gl + '</h6><button class="cf-x" id="cfModalX">&times;</button></div>'
			+ body
			+ '<div style="display:flex;justify-content:space-between;margin-top:16px">'
			+ (isEdit ? '<button class="t-btn" id="cfAcctDel" data-id="' + rec.id + '" style="color:var(--crit);border-color:var(--crit-line)">Delete</button>' : '<span></span>')
			+ '<button class="t-btn solid" id="cfAcctSave" data-group="' + group + '" data-id="' + (rec ? rec.id : 0) + '">Save</button></div>'
			+ '</div></div>';
		openModal(html);
	}
	$(document).on('click', '#cfAcctSave', function(){
		const group = $(this).data('group'); const id = $(this).data('id');
		const v = {}; $('#cfModalRoot [data-k]').each(function(){ v[$(this).data('k')] = $(this).val(); });
		const payload = {
			id: id || 0, group,
			label: v.label || '',
			loc_name: v.facility != null ? v.facility : '',
			balance: (v.balance != null ? v.balance : (v.drawn || '')),
			credit_limit: (v.limit != null ? v.limit : (v.ceiling || '')),
			apr: v.apr || '', monthly_payment: v.payment || '', due_day: v.due_day || '', as_of: v.as_of || '',
			qb_account_id: v.qb_account_id || '',
		};
		const $b = $(this).prop('disabled', true);
		post('/ajax/cash_flow/save_account.php', payload)
			.done(r => { if (r && r.ok) location.reload(); else { alert((r && r.error) || 'Save failed'); $b.prop('disabled', false); } })
			.fail(x => { alert('Save failed: ' + (x.responseText || x.status)); $b.prop('disabled', false); });
	});
	$(document).on('click', '#cfAcctDel', function(){ const id = $(this).data('id'); if (!confirm('Remove this account?')) return; post('/ajax/cashflow/delete_balance.php', { id }).always(() => location.reload()); });

})(jQuery);
