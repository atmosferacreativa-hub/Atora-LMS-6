/**
 * ATORA LMS — CRM v2 Fase 3
 * Builder visual de campañas por bloques.
 *
 * @package ATORA_LMS\CRM_V2
 */
(function () {
	'use strict';

	var cfg = window.atoraCrmV2 || {};
	var REST = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';
	var I18N = cfg.i18n || {};
	var BUILDER = window.atoraEmailBuilder || {};
	var EMAIL_BLOCKS = BUILDER.EMAIL_BLOCKS || {
		text: { label: 'Parrafo', defaultContent: 'Escribe tu mensaje aqui...' },
		heading: { label: 'Encabezado', defaultContent: 'Titulo de seccion' },
		button: { label: 'Boton CTA', defaultContent: 'Ver ahora', defaultUrl: '' },
		divider: { label: 'Separador', defaultContent: '' },
		image: { label: 'Imagen (URL)', defaultContent: '' },
		spacer: { label: 'Espacio', defaultContent: '' }
	};
	var DYNAMIC_VARS = BUILDER.DYNAMIC_VARS || [];

	var meta = { templates: [], channels: [], identities: [], statuses: [] };
	var allCampaigns = [];
	var historyFilter = '';
	var currentStep = 1;
	var TOTAL_STEPS = 4;
	var selectedBlockIndex = -1;
	var visualBlocks = [];

	var campaign = {
		id: null,
		name: '',
		template_key: '',
		channel: 'email',
		execution_mode: 'simulate',
		identity: '',
		subject: '',
		message: '',
		blocks_json: '',
		cta_url: '',
		scheduled_at: '',
		status_filter: '',
		tag: '',
		search: '',
		course_id: 0
	};

	function api(path, options) {
		options = options || {};
		var method = options.method || 'GET';
		var url = REST + '/' + path.replace(/^\/+/, '');

		if (options.params) {
			var qs = Object.keys(options.params)
				.filter(function (k) { return options.params[k] !== undefined && options.params[k] !== ''; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(options.params[k]); })
				.join('&');
			if (qs) { url += '?' + qs; }
		}

		var headers = { 'X-WP-Nonce': NONCE };
		var body;
		if (options.body !== undefined) {
			headers['Content-Type'] = 'application/json';
			body = JSON.stringify(options.body);
		}

		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			body: body
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) { throw new Error(data.message || ('HTTP ' + res.status)); }
				return data;
			});
		});
	}

	var _toastEl = null;
	var _toastTimer = null;
	function toast(message, type, duration) {
		if (!_toastEl) { _toastEl = document.getElementById('crm-cb-toast'); }
		if (!_toastEl) { return { dismiss: function () {} }; }
		if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
		_toastEl.className = 'crm-toast crm-toast--' + (type || 'info');
		_toastEl.textContent = message;
		_toastEl.removeAttribute('hidden');
		void _toastEl.offsetWidth;
		_toastEl.classList.add('crm-toast--show');
		if (type !== 'loading') {
			_toastTimer = setTimeout(function () {
				_toastEl.classList.remove('crm-toast--show');
				setTimeout(function () { _toastEl.setAttribute('hidden', ''); }, 300);
			}, typeof duration === 'number' ? duration : 3500);
		}
		return {
			dismiss: function () {
				if (_toastTimer) { clearTimeout(_toastTimer); }
				_toastEl.classList.remove('crm-toast--show');
				setTimeout(function () { _toastEl.setAttribute('hidden', ''); }, 300);
			}
		};
	}

	function defaultBlock(type) {
		var spec = EMAIL_BLOCKS[type] || EMAIL_BLOCKS.text;
		return {
			type: type,
			content: spec.defaultContent || '',
			url: spec.defaultUrl || ''
		};
	}

	function parseBlocks(raw) {
		if (Array.isArray(raw)) {
			return raw.filter(function (item) { return item && typeof item === 'object'; });
		}
		if (typeof raw !== 'string' || !raw.trim()) { return []; }
		try {
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parseBlocks(parsed) : [];
		} catch (e) {
			return [];
		}
	}

	function blocksToPlainText() {
		return visualBlocks.map(function (block) {
			if (!block) { return ''; }
			if (block.type === 'divider' || block.type === 'spacer') { return ''; }
			if (block.type === 'button') { return (block.content || '') + (block.url ? ' -> ' + block.url : ''); }
			return block.content || '';
		}).filter(Boolean).join('\n\n').trim();
	}

	function syncBlockFields() {
		var plain = blocksToPlainText();
		var json = JSON.stringify(visualBlocks);
		setVal('cb-message', plain);
		setVal('cb-blocks-json', json);
		campaign.message = plain;
		campaign.blocks_json = json;
	}

	function ensureVisualBlocks() {
		if (!visualBlocks.length) {
			visualBlocks = [defaultBlock('text')];
		}
		if (selectedBlockIndex < 0 || selectedBlockIndex >= visualBlocks.length) {
			selectedBlockIndex = 0;
		}
	}

	function goToStep(n) {
		if (n < 1 || n > TOTAL_STEPS) { return; }
		currentStep = n;

		document.querySelectorAll('.crm-cb-panel').forEach(function (el) {
			el.hidden = (parseInt(el.getAttribute('data-panel'), 10) !== n);
		});
		document.querySelectorAll('.crm-cb-step').forEach(function (btn) {
			var s = parseInt(btn.getAttribute('data-step'), 10);
			btn.classList.toggle('is-active', s === n);
			btn.classList.toggle('is-completed', s < n);
		});

		el('cb-btn-prev').disabled = (n === 1);
		el('cb-btn-next').hidden = (n === TOTAL_STEPS);
		el('cb-btn-launch').hidden = (n !== TOTAL_STEPS);
		if (n === TOTAL_STEPS) { renderSummary(); }
	}

	function readForm() {
		campaign.name = val('cb-name');
		campaign.status_filter = val('cb-status-filter');
		campaign.tag = val('cb-tag');
		campaign.search = val('cb-search');
		campaign.subject = val('cb-subject');
		campaign.cta_url = val('cb-cta-url');
		campaign.channel = val('cb-channel');
		campaign.identity = val('cb-identity');
		campaign.execution_mode = val('cb-execution-mode');
		campaign.scheduled_at = val('cb-scheduled-at');
		syncBlockFields();
	}

	function populateForm() {
		setVal('cb-name', campaign.name);
		setVal('cb-status-filter', campaign.status_filter);
		setVal('cb-tag', campaign.tag);
		setVal('cb-search', campaign.search);
		setVal('cb-subject', campaign.subject);
		setVal('cb-cta-url', campaign.cta_url);
		setVal('cb-channel', campaign.channel);
		setVal('cb-identity', campaign.identity);
		setVal('cb-execution-mode', campaign.execution_mode);
		setVal('cb-scheduled-at', campaign.scheduled_at);

		visualBlocks = parseBlocks(campaign.blocks_json || campaign.message);
		if (!visualBlocks.length && campaign.message) {
			visualBlocks = [{ type: 'text', content: campaign.message, url: '' }];
		}
		ensureVisualBlocks();
		highlightTemplate(campaign.template_key);
		renderBlockCanvas();
		renderBlockEditor();
		updatePreview();
		updateCharCount();
	}

	function loadMeta() {
		return api('campaigns/meta').then(function (data) {
			meta.templates = data.templates || [];
			meta.channels = data.channels || [];
			meta.identities = data.identities || [];
			meta.statuses = data.statuses || [];
			renderTemplateGrid();
			populateSelect('cb-channel', meta.channels, 'key', 'label');
			populateSelect('cb-identity', meta.identities, 'key', 'label');
			populateSelect('cb-status-filter', meta.statuses, 'key', 'label');
			renderBlockLibrary();
			renderVarChips();
		});
	}

	function renderTemplateGrid() {
		var grid = el('cb-template-grid');
		if (!grid) { return; }
		grid.innerHTML = meta.templates.map(function (t) {
			return '<button type="button" class="crm-cb-tpl-card" data-tpl-key="' + escHtml(t.key) + '">' +
				'<strong>' + escHtml(t.label) + '</strong>' +
				'<small>' + escHtml(t.subject) + '</small>' +
				'</button>';
		}).join('');

		grid.querySelectorAll('.crm-cb-tpl-card').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var key = btn.getAttribute('data-tpl-key');
				var tpl = meta.templates.find(function (item) { return item.key === key; });
				if (!tpl) { return; }
				campaign.template_key = key;
				if (!val('cb-subject')) { setVal('cb-subject', tpl.subject || ''); }
				if (!visualBlocks.length || !blocksToPlainText()) {
					visualBlocks = [{ type: 'text', content: tpl.body || '', url: '' }];
					selectedBlockIndex = 0;
					renderBlockCanvas();
					renderBlockEditor();
				}
				highlightTemplate(key);
				updatePreview();
				updateCharCount();
			});
		});
	}

	function highlightTemplate(key) {
		document.querySelectorAll('.crm-cb-tpl-card').forEach(function (btn) {
			btn.classList.toggle('is-active', btn.getAttribute('data-tpl-key') === key);
		});
	}

	function populateSelect(id, items, keyProp, labelProp) {
		var sel = el(id);
		if (!sel) { return; }
		sel.innerHTML = items.map(function (item) {
			return '<option value="' + escHtml(item[keyProp]) + '">' + escHtml(item[labelProp]) + '</option>';
		}).join('');
	}

	function renderBlockLibrary() {
		var wrap = el('cb-block-library');
		if (!wrap) { return; }
		wrap.innerHTML = Object.keys(EMAIL_BLOCKS).map(function (type) {
			var spec = EMAIL_BLOCKS[type];
			return '<button type="button" class="atora-email-block-btn" data-block-type="' + escHtml(type) + '">' + escHtml(spec.label || type) + '</button>';
		}).join('');
		wrap.querySelectorAll('[data-block-type]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				addBlock(btn.getAttribute('data-block-type'));
			});
		});
	}

	function renderVarChips() {
		var wrap = el('cb-var-chips');
		if (!wrap) { return; }
		wrap.innerHTML = DYNAMIC_VARS.map(function (item) {
			return '<button type="button" class="atora-email-var-chip" data-var-token="' + escHtml(item.key) + '">' + escHtml(item.key) + '</button>';
		}).join('');
		wrap.querySelectorAll('[data-var-token]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				insertVar(btn.getAttribute('data-var-token'));
			});
		});
	}

	function addBlock(type) {
		visualBlocks.push(defaultBlock(type));
		selectedBlockIndex = visualBlocks.length - 1;
		renderBlockCanvas();
		renderBlockEditor();
		updatePreview();
		updateCharCount();
	}

	function removeBlock(index) {
		if (visualBlocks.length <= 1) { return; }
		visualBlocks.splice(index, 1);
		if (selectedBlockIndex >= visualBlocks.length) { selectedBlockIndex = visualBlocks.length - 1; }
		renderBlockCanvas();
		renderBlockEditor();
		updatePreview();
		updateCharCount();
	}

	function moveBlock(index, delta) {
		var target = index + delta;
		if (target < 0 || target >= visualBlocks.length) { return; }
		var temp = visualBlocks[index];
		visualBlocks[index] = visualBlocks[target];
		visualBlocks[target] = temp;
		selectedBlockIndex = target;
		renderBlockCanvas();
		renderBlockEditor();
		updatePreview();
	}

	function selectBlock(index) {
		selectedBlockIndex = index;
		renderBlockCanvas();
		renderBlockEditor();
	}

	function renderBlockCanvas() {
		ensureVisualBlocks();
		syncBlockFields();
		var wrap = el('cb-email-canvas');
		if (!wrap) { return; }
		wrap.innerHTML = visualBlocks.map(function (block, index) {
			var label = (EMAIL_BLOCKS[block.type] && EMAIL_BLOCKS[block.type].label) || block.type;
			var preview = block.content || block.url || '&nbsp;';
			return '<article class="atora-email-canvas__block' + (index === selectedBlockIndex ? ' is-selected' : '') + '" data-block-index="' + index + '">' +
				'<div class="crm-cb-canvas-block__top"><strong>' + escHtml(label) + '</strong><span>#' + (index + 1) + '</span></div>' +
				'<div class="crm-cb-canvas-block__content">' + escHtml(preview).slice(0, 120) + '</div>' +
				'<div class="crm-cb-canvas-block__actions">' +
				'<button type="button" data-block-action="up" data-block-index="' + index + '">↑</button>' +
				'<button type="button" data-block-action="down" data-block-index="' + index + '">↓</button>' +
				'<button type="button" data-block-action="delete" data-block-index="' + index + '">×</button>' +
				'</div>' +
				'</article>';
		}).join('');

		wrap.querySelectorAll('.atora-email-canvas__block').forEach(function (blockEl) {
			blockEl.addEventListener('click', function (event) {
				if (event.target && event.target.getAttribute('data-block-action')) { return; }
				selectBlock(absint(blockEl.getAttribute('data-block-index')));
			});
		});

		wrap.querySelectorAll('[data-block-action]').forEach(function (btn) {
			btn.addEventListener('click', function (event) {
				event.stopPropagation();
				var action = btn.getAttribute('data-block-action');
				var index = absint(btn.getAttribute('data-block-index'));
				if (action === 'delete') { removeBlock(index); }
				if (action === 'up') { moveBlock(index, -1); }
				if (action === 'down') { moveBlock(index, 1); }
			});
		});
	}

	function renderBlockEditor() {
		var wrap = el('cb-block-editor');
		if (!wrap) { return; }
		if (!visualBlocks.length || selectedBlockIndex < 0 || !visualBlocks[selectedBlockIndex]) {
			wrap.innerHTML = '<p class="crm-cb-empty">Selecciona un bloque para editarlo.</p>';
			return;
		}

		var block = visualBlocks[selectedBlockIndex];
		var typeOptions = Object.keys(EMAIL_BLOCKS).map(function (type) {
			return '<option value="' + escHtml(type) + '"' + (type === block.type ? ' selected' : '') + '>' + escHtml(EMAIL_BLOCKS[type].label || type) + '</option>';
		}).join('');

		wrap.innerHTML =
			'<div class="crm-cb-block-editor__grid">' +
			'<label class="crm-cb-label">Tipo<select id="cb-block-type" class="crm-cb-input">' + typeOptions + '</select></label>' +
			'<label class="crm-cb-label">Contenido<textarea id="cb-block-content" class="crm-cb-input crm-cb-textarea" rows="5">' + escHtml(block.content || '') + '</textarea></label>' +
			((block.type === 'button' || block.type === 'image') ? '<label class="crm-cb-label">URL<input id="cb-block-url" class="crm-cb-input" type="url" value="' + escHtml(block.url || '') + '"></label>' : '') +
			'</div>';

		el('cb-block-type').addEventListener('change', function () {
			var newType = this.value;
			var current = visualBlocks[selectedBlockIndex] || defaultBlock('text');
			var spec = EMAIL_BLOCKS[newType] || EMAIL_BLOCKS.text;
			current.type = newType;
			if (!current.content) { current.content = spec.defaultContent || ''; }
			if (newType !== 'button' && newType !== 'image') { current.url = ''; }
			visualBlocks[selectedBlockIndex] = current;
			renderBlockCanvas();
			renderBlockEditor();
			updatePreview();
			updateCharCount();
		});

		el('cb-block-content').addEventListener('input', function () {
			visualBlocks[selectedBlockIndex].content = this.value;
			renderBlockCanvas();
			updatePreview();
			updateCharCount();
		});

		var urlField = el('cb-block-url');
		if (urlField) {
			urlField.addEventListener('input', function () {
				visualBlocks[selectedBlockIndex].url = this.value;
				renderBlockCanvas();
				updatePreview();
			});
		}
	}

	function insertVar(token) {
		ensureVisualBlocks();
		if (!visualBlocks[selectedBlockIndex]) { return; }
		visualBlocks[selectedBlockIndex].content = (visualBlocks[selectedBlockIndex].content || '') + token;
		renderBlockCanvas();
		renderBlockEditor();
		updatePreview();
		updateCharCount();
	}

	function renderPreviewBlock(block) {
		var content = escHtml(block.content || '');
		if (block.type === 'heading') {
			return '<h2 style="margin:0 0 12px;color:#0f172a;font-size:20px;font-weight:600;">' + content + '</h2>';
		}
		if (block.type === 'button') {
			return '<p style="margin:16px 0;"><a href="' + escHtml(block.url || '#') + '" style="display:inline-block;background:#0ea5e9;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">' + content + '</a></p>';
		}
		if (block.type === 'divider') {
			return '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">';
		}
		if (block.type === 'image') {
			return block.url ? '<p style="margin:12px 0;"><img src="' + escHtml(block.url) + '" style="max-width:100%;border-radius:6px;" alt=""></p>' : '';
		}
		if (block.type === 'spacer') {
			return '<div style="height:24px;"></div>';
		}
		return '<p style="margin:0 0 14px;color:#334155;font-size:15px;line-height:1.6;">' + escHtml(block.content || '').replace(/\n/g, '<br>') + '</p>';
	}

	function updatePreview() {
		syncBlockFields();
		var subjNode = el('cb-preview-subject');
		var bodyNode = el('cb-preview-message');
		if (subjNode) { subjNode.textContent = val('cb-subject') || '(Sin asunto)'; }
		if (bodyNode) {
			bodyNode.innerHTML = visualBlocks.map(renderPreviewBlock).join('') || '<p style="color:#94a3b8">(Sin contenido)</p>';
			if (val('cb-cta-url')) {
				bodyNode.innerHTML += '<p style="margin:16px 0;"><a href="' + escHtml(val('cb-cta-url')) + '" style="display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">' + escHtml(I18N.ctaLabel || 'Ver detalle') + '</a></p>';
			}
		}
	}

	function updateCharCount() {
		syncBlockFields();
		var message = val('cb-message');
		var cnt = el('cb-char-count');
		var warn = el('cb-char-warn');
		if (cnt) { cnt.textContent = message.length.toLocaleString(); }
		if (warn) { warn.hidden = (message.length <= 3000); }
	}

	var audienceDebounce = null;
	function scheduleAudienceEstimate() {
		clearTimeout(audienceDebounce);
		var spinner = el('cb-audience-spinner');
		if (spinner) { spinner.removeAttribute('hidden'); }

		audienceDebounce = setTimeout(function () {
			readForm();
			api('campaigns/audience', {
				params: {
					status: campaign.status_filter,
					tag: campaign.tag,
					search: campaign.search,
					course_id: campaign.course_id
				}
			}).then(function (data) {
				var aud = data.audience || {};
				if (el('cb-audience-total')) { el('cb-audience-total').textContent = Number(aud.total || 0).toLocaleString(); }
				if (el('cb-audience-email')) { el('cb-audience-email').textContent = '~' + Number(aud.email_est || 0).toLocaleString() + ' con email'; }
			}).catch(function () {
				return null;
			}).finally(function () {
				if (spinner) { spinner.setAttribute('hidden', ''); }
			});
		}, 500);
	}

	function renderSummary() {
		readForm();
		var wrap = el('cb-summary');
		if (!wrap) { return; }
		var modeLabel = campaign.execution_mode === 'queue' ? 'Encolar - envio real' : 'Simular - sin envios';
		var channelLabel = (meta.channels.find(function (c) { return c.key === campaign.channel; }) || {}).label || campaign.channel;
		var identLabel = (meta.identities.find(function (i) { return i.key === campaign.identity; }) || {}).label || campaign.identity;
		var statusLabel = (meta.statuses.find(function (s) { return s.key === campaign.status_filter; }) || {}).label || 'Todos';
		wrap.innerHTML =
			'<dl class="crm-cb-summary__dl">' +
			row('Nombre', campaign.name || '—') +
			row('Asunto', campaign.subject || '—') +
			row('Estado contacto', statusLabel) +
			row('Canal', channelLabel || '—') +
			row('Identidad', identLabel || '—') +
			row('Modo', modeLabel) +
			row('Bloques', String(visualBlocks.length)) +
			(campaign.scheduled_at ? row('Programada', campaign.scheduled_at) : '') +
			'</dl>';
		if (el('cb-result')) { el('cb-result').hidden = true; }
	}

	function saveDraft(btn) {
		readForm();
		if (!campaign.name) {
			toast('Indica el nombre de la campana.', 'error');
			setFocus('cb-name');
			return Promise.reject(new Error('no-name'));
		}
		if (!campaign.subject) {
			toast('El asunto es obligatorio.', 'error');
			return Promise.reject(new Error('no-subject'));
		}
		if (!campaign.message && !visualBlocks.length) {
			toast('Debes agregar contenido al email.', 'error');
			return Promise.reject(new Error('no-message'));
		}

		setBusy(btn, true, 'Guardando...');
		var t = toast('Guardando borrador...', 'loading');
		var body = {
			name: campaign.name,
			template_key: campaign.template_key,
			channel: campaign.channel,
			execution_mode: campaign.execution_mode,
			identity: campaign.identity,
			subject: campaign.subject,
			message: campaign.message,
			blocks_json: visualBlocks,
			cta_url: campaign.cta_url,
			scheduled_at: campaign.scheduled_at,
			status_filter: campaign.status_filter,
			tag: campaign.tag,
			search: campaign.search,
			course_id: campaign.course_id
		};

		var promise = campaign.id ? api('campaigns/' + campaign.id, { method: 'POST', body: body }) : api('campaigns', { method: 'POST', body: body });
		return promise.then(function (data) {
			t.dismiss();
			if (data.campaign) {
				Object.assign(campaign, data.campaign);
				campaign.id = data.campaign.id;
				campaign.blocks_json = data.campaign.blocks_json || body.blocks_json;
			}
			toast(data.message || 'Borrador guardado.', 'success');
			loadHistory();
			return data;
		}).catch(function (err) {
			t.dismiss();
			toast(err.message || 'Error al guardar.', 'error');
			throw err;
		}).finally(function () {
			setBusy(btn, false);
		});
	}

	function launchCampaign(btn) {
		readForm();
		if (!campaign.id) {
			saveDraft(null).then(function () { launchCampaign(btn); }).catch(function () {});
			return;
		}
		if (!window.confirm('¿Lanzar la campana en el modo seleccionado?')) { return; }

		setBusy(btn, true, 'Lanzando...');
		var t = toast('Lanzando campana...', 'loading');
		api('campaigns/' + campaign.id + '/launch', {
			method: 'POST',
			body: { mode: campaign.execution_mode }
		}).then(function (data) {
			t.dismiss();
			toast(data.message || 'Campana lanzada.', 'success', 6000);
			if (el('cb-result')) { el('cb-result').hidden = false; }
			if (el('cb-result-kpis')) {
				el('cb-result-kpis').innerHTML =
					kpi('Encolados', data.queued || 0, 'success') +
					kpi('Simulados', data.simulated || 0, 'info') +
					kpi('Omitidos', data.skipped || 0, (data.skipped || 0) > 0 ? 'warning' : '');
			}
			if (el('cb-result-msg')) { el('cb-result-msg').textContent = data.message || ''; }
			loadHistory();
		}).catch(function (err) {
			t.dismiss();
			toast(err.message || 'Error al lanzar.', 'error');
		}).finally(function () {
			setBusy(btn, false);
		});
	}

	function loadHistory() {
		return api('campaigns').then(function (data) {
			allCampaigns = data.campaigns || [];
			renderHistory();
		}).catch(function () {
			if (el('cb-campaign-list')) { el('cb-campaign-list').innerHTML = '<p class="crm-cb-empty">Error al cargar campanas.</p>'; }
		});
	}

	function renderMetrics(metrics) {
		if (!metrics || typeof metrics !== 'object') { return ''; }
		return '<div class="crm-cb-item__metrics">' +
			'<span>E ' + escHtml(metrics.sent || 0) + '</span>' +
			'<span>A ' + escHtml(metrics.opened || 0) + ' (' + escHtml(metrics.open_rate || 0) + '%)</span>' +
			'<span>C ' + escHtml(metrics.clicked || 0) + ' (' + escHtml(metrics.click_rate || 0) + '%)</span>' +
			'</div>';
	}

	function renderHistory() {
		var list = el('cb-campaign-list');
		if (!list) { return; }
		var filtered = allCampaigns.filter(function (c) {
			if (!historyFilter) { return true; }
			return historyFilter.split(',').indexOf(c.status) !== -1;
		});

		if (!filtered.length) {
			list.innerHTML = '<p class="crm-cb-empty">Sin campanas para este filtro.</p>';
			return;
		}

		list.innerHTML = filtered.map(function (c) {
			var isActive = campaign.id && String(campaign.id) === String(c.id);
			return '<div class="crm-cb-item' + (isActive ? ' is-active' : '') + '" data-cid="' + c.id + '">' +
				'<div class="crm-cb-item__main">' +
				'<strong>' + escHtml(c.name || '') + '</strong>' +
				'<small>' + escHtml(c.subject || '') + '</small>' +
				'<span class="crm-cb-status crm-cb-status--' + escHtml(c.status || 'draft') + '">' + escHtml(c.status_label || c.status || 'draft') + '</span>' +
				renderMetrics(c.metrics) +
				'</div>' +
				'<div class="crm-cb-item__actions">' +
				'<button class="crm-cb-item-btn" data-action="clone" data-cid="' + c.id + '">⎘</button>' +
				(['draft', 'scheduled'].indexOf(c.status) !== -1 ? '<button class="crm-cb-item-btn crm-cb-item-btn--warn" data-action="pause" data-cid="' + c.id + '">⏸</button>' : '') +
				'</div></div>';
		}).join('');

		list.querySelectorAll('.crm-cb-item[data-cid]').forEach(function (item) {
			item.addEventListener('click', function (e) {
				if (e.target.closest('.crm-cb-item-btn')) { return; }
				loadCampaign(absint(item.getAttribute('data-cid')));
			});
		});

		list.querySelectorAll('.crm-cb-item-btn').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var action = btn.getAttribute('data-action');
				var cid = absint(btn.getAttribute('data-cid'));
				if (action === 'clone') { cloneCampaign(cid, btn); }
				if (action === 'pause') { pauseCampaign(cid, btn); }
			});
		});
	}

	function loadCampaign(cid) {
		var t = toast('Cargando campana...', 'loading');
		api('campaigns/' + cid).then(function (data) {
			t.dismiss();
			Object.assign(campaign, data.campaign || {});
			campaign.id = (data.campaign || {}).id || cid;
			populateForm();
			goToStep(1);
			scheduleAudienceEstimate();
			renderHistory();
		}).catch(function (err) {
			t.dismiss();
			toast(err.message || 'Error al cargar la campana.', 'error');
		});
	}

	function cloneCampaign(cid, btn) {
		if (btn) { btn.disabled = true; }
		var t = toast('Clonando campana...', 'loading');
		api('campaigns/' + cid + '/clone', { method: 'POST', body: {} }).then(function (data) {
			t.dismiss();
			toast(data.message || 'Campana clonada.', 'success');
			if (data.campaign) {
				Object.assign(campaign, data.campaign);
				campaign.id = data.campaign.id;
				populateForm();
			}
			goToStep(1);
			loadHistory();
		}).catch(function (err) {
			t.dismiss();
			toast(err.message || 'Error al clonar.', 'error');
		}).finally(function () {
			if (btn) { btn.disabled = false; }
		});
	}

	function pauseCampaign(cid, btn) {
		if (!window.confirm('¿Pausar esta campana?')) { return; }
		if (btn) { btn.disabled = true; }
		api('campaigns/' + cid + '/pause', { method: 'POST', body: {} }).then(function (data) {
			toast(data.message || 'Campana pausada.', 'success');
			loadHistory();
		}).catch(function (err) {
			toast(err.message || 'Error al pausar.', 'error');
		}).finally(function () {
			if (btn) { btn.disabled = false; }
		});
	}

	function resetBuilder() {
		campaign = {
			id: null,
			name: '',
			template_key: '',
			channel: 'email',
			execution_mode: 'simulate',
			identity: '',
			subject: '',
			message: '',
			blocks_json: '',
			cta_url: '',
			scheduled_at: '',
			status_filter: '',
			tag: '',
			search: '',
			course_id: 0
		};
		visualBlocks = [defaultBlock('text')];
		selectedBlockIndex = 0;
		populateForm();
		goToStep(1);
		scheduleAudienceEstimate();
		renderHistory();
	}

	function bindHistoryFilters() {
		document.querySelectorAll('.crm-cb-filter-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				historyFilter = btn.getAttribute('data-status') || '';
				document.querySelectorAll('.crm-cb-filter-btn').forEach(function (node) {
					node.classList.toggle('is-active', node === btn);
				});
				renderHistory();
			});
		});
	}

	function bindTestSend() {
		var btn = el('cb-test-send');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			readForm();
			if (!val('cb-test-email')) { toast('Indica el email de prueba.', 'error'); return; }
			if (!campaign.subject) { toast('El asunto es obligatorio.', 'error'); return; }
			if (!visualBlocks.length) { toast('El mensaje esta vacio.', 'error'); return; }

			setBusy(btn, true, 'Enviando...');
			var t = toast('Enviando prueba...', 'loading');
			api('campaigns/test', {
				method: 'POST',
				body: {
					email: val('cb-test-email'),
					subject: campaign.subject,
					message: JSON.stringify(visualBlocks),
					cta_url: campaign.cta_url,
					identity: campaign.identity
				}
			}).then(function (data) {
				t.dismiss();
				toast(data.message || 'Email de prueba enviado.', 'success');
			}).catch(function (err) {
				t.dismiss();
				toast(err.message || 'Error al enviar la prueba.', 'error');
			}).finally(function () {
				setBusy(btn, false);
			});
		});
	}

	function bindEvents() {
		if (el('cb-btn-prev')) { el('cb-btn-prev').addEventListener('click', function () { goToStep(currentStep - 1); }); }
		if (el('cb-btn-next')) { el('cb-btn-next').addEventListener('click', function () { readForm(); goToStep(currentStep + 1); }); }
		document.querySelectorAll('.crm-cb-step').forEach(function (btn) {
			btn.addEventListener('click', function () {
				goToStep(absint(btn.getAttribute('data-step')));
			});
		});
		if (el('cb-btn-save')) { el('cb-btn-save').addEventListener('click', function () { saveDraft(el('cb-btn-save')); }); }
		if (el('cb-btn-launch')) { el('cb-btn-launch').addEventListener('click', function () { launchCampaign(el('cb-btn-launch')); }); }
		if (el('cb-btn-new')) { el('cb-btn-new').addEventListener('click', resetBuilder); }
		if (el('cb-add-text-block')) { el('cb-add-text-block').addEventListener('click', function () { addBlock('text'); }); }

		['cb-status-filter', 'cb-tag', 'cb-search'].forEach(function (id) {
			var node = el(id);
			if (!node) { return; }
			node.addEventListener('change', scheduleAudienceEstimate);
			node.addEventListener('input', scheduleAudienceEstimate);
		});

		['cb-subject', 'cb-cta-url', 'cb-channel', 'cb-identity', 'cb-execution-mode', 'cb-scheduled-at'].forEach(function (id) {
			var node = el(id);
			if (!node) { return; }
			node.addEventListener('input', function () { readForm(); updatePreview(); });
			node.addEventListener('change', function () { readForm(); updatePreview(); });
		});
	}

	function row(label, value) {
		return '<dt>' + escHtml(label) + '</dt><dd>' + escHtml(value) + '</dd>';
	}

	function kpi(label, value, type) {
		var color = type === 'success' ? '#059669' : type === 'warning' ? '#d97706' : '#1d4ed8';
		return '<div class="crm-cb-kpi"><strong style="color:' + color + '">' + Number(value).toLocaleString() + '</strong><span>' + escHtml(label) + '</span></div>';
	}

	function el(id) { return document.getElementById(id); }
	function val(id) { var node = el(id); return node ? String(node.value || '').trim() : ''; }
	function setVal(id, value) { var node = el(id); if (node) { node.value = value || ''; } }
	function setFocus(id) { var node = el(id); if (node) { node.focus(); } }
	function absint(value) { return Math.max(0, parseInt(value, 10) || 0); }
	function setBusy(btn, busy, label) {
		if (!btn) { return; }
		if (busy) {
			btn.dataset.orig = btn.textContent;
			btn.textContent = label || '...';
			btn.disabled = true;
		} else {
			btn.textContent = btn.dataset.orig || btn.textContent;
			btn.disabled = false;
		}
	}
	function escHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}


	/* ── Toggle A/B (Fase 8) ── */
	function bindABToggle() {
		var toggle = el('cb-ab-toggle');
		var fields = el('cb-ab-fields');
		if (!toggle || !fields) { return; }
		toggle.addEventListener('change', function() { fields.hidden = !toggle.checked; });
	}

	function init() {
		visualBlocks = [defaultBlock('text')];
		selectedBlockIndex = 0;
		loadMeta().then(function () {
			loadHistory();
			populateForm();
			scheduleAudienceEstimate();
			goToStep(1);
		});
		bindEvents();
		bindHistoryFilters();
		bindTestSend();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
