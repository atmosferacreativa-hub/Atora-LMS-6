/**
 * ATORA LMS — CRM v2, Planes de seguimiento (PT-4, sprint 6.6.0;
 * generalizado a dos dominios — académico y comercial — en 6.7.0)
 *
 * followup-plans.js
 *
 * Misma arquitectura que crm-calendar.js (Fase 2) — helper api() con
 * fetch + nonce, toast() con fallback local, FullCalendar 6 con
 * editable:true/eventDrop para drag-and-drop — deliberadamente, para
 * no inventar un segundo patrón de integración con el mismo REST
 * namespace (atora-crm/v2) y el mismo objeto localizado
 * window.atoraCrmV2 que ya usa ese archivo.
 *
 * Responsabilidades:
 *   1. Calendario mensual con bloques por plan de seguimiento
 *      (PT-4.1) — color por urgencia usando los tokens del sistema de
 *      diseño. Académico: --atora-blue-700 programado, --atora-warning
 *      pendiente con estudiantes sin contactar, --atora-success
 *      completado. Comercial (PT-3, 6.7.0): color por score del
 *      contacto de mayor urgencia en la ocurrencia — mismos tokens,
 *      nunca una paleta nueva.
 *   2. Arrastrar para reprogramar (PT-4.3) → POST .../reschedule
 *   3. Panel lateral al click en un bloque (PT-4.4): estudiantes/
 *      contactos de esa ocurrencia, marcar contactado individual/lote,
 *      posponer, saltar, excluir. En comercial, cada fila muestra su
 *      score y, si corresponde, su indicación de secuencia automática
 *      activa (PT-4.2 de 6.7.0 — coordina, nunca oculta).
 *   4. Asistente de 4 pasos para aplicar un plan (PT-4.2), con vista
 *      previa en vivo (PT-2.2) antes de guardar. El paso 3 muestra
 *      controles distintos según el dominio actual: secciones+etapas
 *      académicas, o etapas de pipeline (+ filtro de secuencia) o un
 *      buscador de contactos para "Cuenta clave" en comercial.
 *   5. Selector de dominio (PT-5.1, 6.7.0) — pestañas simples, solo
 *      visibles para quien tiene planes académicos Y comerciales.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   6.6.0
 */
(function () {
	'use strict';

	var cfg   = window.atoraCrmV2 || {};
	var REST  = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';

	var appEl = document.getElementById('atora-fu-app');
	var currentDomain = (appEl && appEl.getAttribute('data-default-domain')) || 'academic';
	var canAcademic   = !appEl || appEl.getAttribute('data-can-academic') !== '0';
	var canCommercial = !!appEl && appEl.getAttribute('data-can-commercial') === '1';

	var STAGE_COLORS = {
		completed_all:       'var(--atora-success, #1D9E75)',
		pending:             'var(--atora-blue-700, #1d4ed8)',
		pending_uncontacted: 'var(--atora-warning, #BA7517)',
		urgency_high:        'var(--atora-danger, #E24B4A)',
		urgency_medium:      'var(--atora-warning, #BA7517)',
		urgency_low:         'var(--atora-success, #1D9E75)'
	};

	var DOMAIN_LABELS = {
		academic:   { subtitle: 'Tu ritmo de contacto con estudiantes en riesgo, en un calendario.', entity: 'estudiante', entityPlural: 'estudiantes' },
		commercial: { subtitle: 'Tu ritmo de contacto con contactos y deals, en un calendario.', entity: 'contacto', entityPlural: 'contactos' }
	};

	/* ============================================================
	 *  API HELPER
	 * ============================================================ */
	function api(path, options) {
		options = options || {};
		var method = options.method || 'GET';
		var body   = options.body ? JSON.stringify(options.body) : undefined;
		var url    = REST + '/' + path.replace(/^\/+/, '');

		if (options.params) {
			var qs = Object.keys(options.params)
				.filter(function (k) { return options.params[k] !== undefined && options.params[k] !== ''; })
				.map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(options.params[k]); })
				.join('&');
			if (qs) { url += '?' + qs; }
		}

		return fetch(url, {
			method: method,
			credentials: 'same-origin',
			headers: Object.assign({ 'X-WP-Nonce': NONCE }, body ? { 'Content-Type': 'application/json' } : {}),
			body: body
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) { throw new Error(data.message || ('HTTP ' + res.status)); }
				return data;
			});
		});
	}

	/* ============================================================
	 *  TOAST — mismo fallback que crm-calendar.js
	 * ============================================================ */
	var _toastEl = null;
	var _toastTimer = null;

	function toast(message, type, duration) {
		if (!_toastEl) { _toastEl = document.getElementById('crm-toast'); }
		if (!_toastEl) { return { dismiss: function () {} }; }

		if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }

		_toastEl.className = 'crm-toast crm-toast--' + (type || 'info');
		_toastEl.textContent = message;
		_toastEl.removeAttribute('hidden');
		void _toastEl.offsetWidth;
		_toastEl.classList.add('crm-toast--show');

		if (type !== 'loading') {
			var ms = typeof duration === 'number' ? duration : (type === 'error' ? 5000 : 3000);
			_toastTimer = setTimeout(function () {
				_toastEl.classList.remove('crm-toast--show');
				setTimeout(function () { _toastEl.setAttribute('hidden', ''); }, 300);
			}, ms);
		}

		return {
			dismiss: function () {
				if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
				_toastEl.classList.remove('crm-toast--show');
				setTimeout(function () { _toastEl.setAttribute('hidden', ''); }, 300);
			}
		};
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	/* ============================================================
	 *  SELECTOR DE DOMINIO (PT-5.1, 6.7.0)
	 * ============================================================ */
	function setDomain(domain) {
		currentDomain = domain;

		document.querySelectorAll('.atora-fu-domain-tab').forEach(function (tab) {
			var active = tab.getAttribute('data-domain') === domain;
			tab.classList.toggle('is-active', active);
			tab.setAttribute('aria-selected', active ? 'true' : 'false');
		});

		var subtitleEl = document.getElementById('atora-fu-subtitle');
		if (subtitleEl && DOMAIN_LABELS[domain]) { subtitleEl.textContent = DOMAIN_LABELS[domain].subtitle; }

		if (calendar) { calendar.refetchEvents(); }
	}

	/* ============================================================
	 *  CALENDARIO
	 * ============================================================ */
	var calendar = null;

	function eventColor(props) {
		if (props.domain === 'commercial' && props.urgency) {
			if (props.empty) { return STAGE_COLORS.completed_all; }
			return STAGE_COLORS['urgency_' + props.urgency] || STAGE_COLORS.pending;
		}
		if (props.empty) { return STAGE_COLORS.completed_all; }
		if (props.all_contacted) { return STAGE_COLORS.completed_all; }
		if (props.any_uncontacted) { return STAGE_COLORS.pending_uncontacted; }
		return STAGE_COLORS.pending;
	}

	function initCalendar() {
		var mountEl = document.getElementById('atora-fu-mount');
		if (!mountEl || typeof FullCalendar === 'undefined') { return; }

		calendar = new FullCalendar.Calendar(mountEl, {
			initialView: 'dayGridMonth',
			locale: 'es',
			firstDay: 1,
			height: 'auto',
			headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
			editable: true,
			eventStartEditable: true,
			eventDurationEditable: false,
			dayMaxEvents: 3,

			events: function (info, successCb, failureCb) {
				var params = { start: info.startStr, end: info.endStr };
				if (canAcademic && canCommercial) { params.domain = currentDomain; }
				api('followup-plans/calendar-events', { params: params }).then(function (data) {
					successCb((data.events || []).map(function (ev) {
						ev.color = eventColor(ev.extendedProps || {});
						return ev;
					}));
				}).catch(function (err) {
					toast(err.message || 'Error al cargar el calendario.', 'error');
					failureCb(err);
				});
			},

			eventDrop: function (info) {
				var eventId = info.event.extendedProps.event_id;
				if (!eventId) { info.revert(); return; }
				var newDate = info.event.startStr.slice(0, 10);
				var t = toast('Reprogramando…', 'loading');
				api('followup-plans/occurrence/' + eventId + '/reschedule', {
					method: 'POST',
					body: { date: newDate }
				}).then(function () {
					t.dismiss();
					toast('Reprogramado.', 'success');
				}).catch(function (err) {
					t.dismiss();
					toast(err.message || 'No se pudo reprogramar.', 'error');
					info.revert();
				});
			},

			eventClick: function (info) {
				info.jsEvent.preventDefault();
				var eventId = info.event.extendedProps.event_id;
				if (eventId) { openPanel(eventId); }
			}
		});

		calendar.render();
	}

	/* ============================================================
	 *  PANEL LATERAL DE OCURRENCIA (PT-4.4, 6.6.0/6.7.0)
	 *  Retrofit a Followup_Panel/Followup_List_Row compartidos
	 *  (PT-1.4, 6.9.0, includes/ui/assets/followup-panel.js) — este
	 *  archivo ya no construye el marcado de filas a mano, solo
	 *  arma la config (items/actions/onAction) y AtoraUI.Panel la
	 *  renderiza. El comportamiento (abrir, cerrar, marcar
	 *  contactado, excluir, saltar, posponer, lote) es idéntico al
	 *  de antes del retrofit.
	 * ============================================================ */
	var currentOccurrenceId = null;
	var currentOccurrenceData = null;

	var EMPTY_REASONS = {
		sin_configuracion: 'Este plan todavía no tiene a quién seguir configurado.',
		sin_estudiantes_en_secciones: 'No hay estudiantes activos en las secciones de este plan.',
		nadie_en_esas_etapas_hoy: 'Nadie está en las etapas de este plan hoy — buena señal.',
		nadie_cumple_el_filtro_hoy: 'Nadie cumple el filtro de este plan hoy.',
		todos_en_secuencia_activa: 'Todos los contactos de hoy ya están en una secuencia automática — nada que revisar a mano.',
		dominio_no_disponible: 'Este plan pertenece a un módulo que no está disponible ahora.',
		crm_no_disponible: 'El CRM comercial no está disponible ahora.'
	};

	function scoreBadgeLabel(meta) {
		if (!meta || !meta.score_label) { return null; }
		return meta.score_label + ' (' + meta.score + ')';
	}

	function studentToRowItem(s, domain) {
		var meta = s.meta || {};
		var badges = [];
		var urgency;

		if ('commercial' === domain) {
			var score = typeof meta.score === 'number' ? meta.score : 100;
			urgency = score <= 39 ? 'alta' : (score < 70 ? 'media' : 'baja');
			var scoreBadge = scoreBadgeLabel(meta);
			if (scoreBadge) { badges.push(scoreBadge); }
			if (meta.sequence && meta.sequence.active) {
				badges.push('✉ ' + meta.sequence.sequence_name + ' · paso ' + meta.sequence.current_step + '/' + meta.sequence.total_steps);
			}
			if (typeof meta.days_stalled === 'number' && meta.days_stalled > 0) {
				badges.push(meta.days_stalled + ' días sin avanzar');
			}
		} else {
			urgency = ['at_risk', 'intervention', 'needs_support'].indexOf(s.stage) !== -1 ? 'alta' : 'media';
		}

		return {
			id: s.user_id,
			name: s.display_name,
			meta: s.stage_label,
			urgency: urgency,
			badges: badges,
			done: !!s.contacted,
			actions: [
				{ label: 'Marcar contactado', done_label: '✓ Contactado', action_id: 'contact' },
				{ label: 'Excluir', action_id: 'exclude' }
			]
		};
	}

	function onOccurrencePanelAction(actionId, itemId) {
		if ('contact' === actionId) { return markContacted(itemId); }
		if ('exclude' === actionId) { return excludeStudent(itemId); }
		if ('postpone' === actionId) { return postponeOccurrence(); }
		if ('skip' === actionId) { return skipOccurrence(); }
		if ('contact_all' === actionId) { return markAllContacted(); }
	}

	function renderOccurrencePanel(data) {
		currentOccurrenceData = data;
		var domain = data.domain || 'academic';
		var items = (data.students || []).map(function (s) { return studentToRowItem(s, domain); });

		window.AtoraUI.Panel.open('atora-fu-panel', {
			title: data.title || 'Ocurrencia',
			subtitle: data.date || '',
			items: items,
			notice: data.filtered_out_count > 0
				? (data.filtered_out_count + ' contacto' + (data.filtered_out_count === 1 ? '' : 's') +
					' más está' + (data.filtered_out_count === 1 ? '' : 'n') +
					' en secuencia automática y no se muestra' + (data.filtered_out_count === 1 ? '' : 'n') + ' acá.')
				: '',
			emptyTitle: EMPTY_REASONS[data.empty_reason] || 'No hay nadie para esta ocurrencia hoy.',
			actions: [
				{ label: 'Posponer un día', action_id: 'postpone', scope: 'bulk' },
				{ label: 'Saltar esta vez', action_id: 'skip', scope: 'bulk' },
				{ label: 'Marcar a todos contactados', action_id: 'contact_all', scope: 'bulk' },
				{ label: 'Marcar contactado', action_id: 'contact', scope: 'item' },
				{ label: 'Excluir', action_id: 'exclude', scope: 'item' }
			],
			onAction: onOccurrencePanelAction,
			onClose: function () { currentOccurrenceId = null; currentOccurrenceData = null; }
		});
	}

	function openPanel(eventId) {
		currentOccurrenceId = eventId;
		api('followup-plans/occurrence/' + eventId).then(function (data) {
			renderOccurrencePanel(data);
		}).catch(function (err) {
			toast(err.message || 'No se pudo cargar la ocurrencia.', 'error');
		});
	}

	function closePanel() {
		window.AtoraUI.Panel.close('atora-fu-panel', function () {
			currentOccurrenceId = null;
			currentOccurrenceData = null;
		});
	}

	function markContacted(userId) {
		if (!currentOccurrenceId) { return; }
		api('followup-plans/occurrence/' + currentOccurrenceId + '/contact', {
			method: 'POST',
			body: { user_id: userId }
		}).then(function () {
			toast('Contacto registrado.', 'success');
			openPanel(currentOccurrenceId); // refresca la lista
		}).catch(function (err) {
			toast(err.message || 'No se pudo registrar el contacto.', 'error');
		});
	}

	function excludeStudent(userId) {
		if (!currentOccurrenceId) { return; }
		if (!window.confirm('¿Excluir de esta ocurrencia?')) { return; }
		api('followup-plans/occurrence/' + currentOccurrenceId + '/exclude', {
			method: 'POST',
			body: { user_id: userId }
		}).then(function () {
			toast('Excluido de esta ocurrencia.', 'success');
			openPanel(currentOccurrenceId);
		}).catch(function (err) {
			toast(err.message || 'No se pudo excluir.', 'error');
		});
	}

	function skipOccurrence() {
		if (!currentOccurrenceId) { return; }
		if (!window.confirm('¿Saltar esta ocurrencia? El resto del plan sigue igual.')) { return; }
		api('followup-plans/occurrence/' + currentOccurrenceId + '/skip', { method: 'POST' }).then(function () {
			toast('Ocurrencia saltada.', 'success');
			closePanel();
			if (calendar) { calendar.refetchEvents(); }
		}).catch(function (err) {
			toast(err.message || 'No se pudo saltar la ocurrencia.', 'error');
		});
	}

	function postponeOccurrence() {
		if (!currentOccurrenceId) { return; }
		var tomorrow = new Date();
		tomorrow.setDate(tomorrow.getDate() + 1);
		var iso = tomorrow.toISOString().slice(0, 10);
		api('followup-plans/occurrence/' + currentOccurrenceId + '/reschedule', {
			method: 'POST',
			body: { date: iso }
		}).then(function () {
			toast('Pospuesto un día.', 'success');
			closePanel();
			if (calendar) { calendar.refetchEvents(); }
		}).catch(function (err) {
			toast(err.message || 'No se pudo posponer.', 'error');
		});
	}

	function markAllContacted() {
		var students = (currentOccurrenceData && currentOccurrenceData.students) || [];
		var uncontacted = students.filter(function (s) { return !s.contacted; });
		if (!uncontacted.length) { toast('Ya están todos contactados.', 'info'); return; }
		var promises = uncontacted.map(function (s) {
			return api('followup-plans/occurrence/' + currentOccurrenceId + '/contact', {
				method: 'POST',
				body: { user_id: s.user_id }
			});
		});
		Promise.all(promises).then(function () {
			toast('Todos marcados como contactados.', 'success');
			openPanel(currentOccurrenceId);
		}).catch(function (err) {
			toast(err.message || 'Hubo un problema marcando a todos.', 'error');
		});
	}

	/* ============================================================
	 *  ASISTENTE DE 4 PASOS (PT-4.2)
	 * ============================================================ */
	function freshWizardState() {
		return {
			step: 1,
			domain: currentDomain,
			template_key: null,
			manual_mode: false,
			section_ids: [],
			stage_filter: [],
			domain_config: {},
			recurrence_rule: 'WEEKLY;BYDAY=MO',
			selected_contacts: []
		};
	}
	var wizardState = freshWizardState();

	function applyDomainBlocks() {
		var academicBlock   = document.getElementById('atora-fu-academic-scope');
		var commercialBlock = document.getElementById('atora-fu-commercial-scope');
		var manualBlock     = document.getElementById('atora-fu-commercial-manual');

		var isCommercial = wizardState.domain === 'commercial';
		if (academicBlock)   { academicBlock.hidden = isCommercial; }
		if (commercialBlock) { commercialBlock.hidden = !isCommercial || wizardState.manual_mode; }
		if (manualBlock)     { manualBlock.hidden = !isCommercial || !wizardState.manual_mode; }
	}

	function openWizard() {
		wizardState = freshWizardState();
		var wizard = document.getElementById('atora-fu-wizard');
		if (!wizard) { return; }
		wizard.hidden = false;
		wizard.setAttribute('aria-hidden', 'false');
		document.getElementById('atora-fu-selected-contacts').innerHTML = '';
		document.getElementById('atora-fu-exclude-sequence').checked = false;
		applyDomainBlocks();
		loadTemplates();
		goToStep(1);
	}

	function closeWizard() {
		var wizard = document.getElementById('atora-fu-wizard');
		if (!wizard) { return; }
		wizard.hidden = true;
		wizard.setAttribute('aria-hidden', 'true');
	}

	function loadTemplates() {
		var grid = document.getElementById('atora-fu-template-grid');
		if (!grid) { return; }
		api('followup-plans/templates', { params: { domain: wizardState.domain } }).then(function (data) {
			var templates = data.templates || {};
			grid.innerHTML = Object.keys(templates).map(function (key) {
				var t = templates[key];
				return '<button type="button" class="atora-fu-template-card" data-template-key="' + key + '">' +
					'<strong>' + escapeHtml(t.name) + '</strong>' +
					'<span>' + escapeHtml(t.description) + '</span>' +
					'</button>';
			}).join('');

			grid.querySelectorAll('.atora-fu-template-card').forEach(function (card) {
				card.addEventListener('click', function () {
					var key = card.getAttribute('data-template-key');
					var t = templates[key];
					wizardState.template_key = key;
					wizardState.stage_filter = t.stage_filter || [];
					wizardState.recurrence_rule = t.recurrence_rule || 'WEEKLY;BYDAY=MO';
					wizardState.domain_config = t.domain_config || {};
					wizardState.manual_mode = wizardState.domain === 'commercial' && (t.stage_filter || []).length === 0;
					applyDomainBlocks();
					applyStageSelectionToChips();
					goToStep(2);
					updatePreview();
				});
			});
		}).catch(function (err) {
			toast(err.message || 'No se pudieron cargar las plantillas.', 'error');
		});
	}

	function applyStageSelectionToChips() {
		var chipSelector = wizardState.domain === 'commercial' ? '#atora-fu-deal-stage-chips' : '#atora-fu-stage-chips';
		document.querySelectorAll(chipSelector + ' input[type="checkbox"]').forEach(function (cb) {
			cb.checked = wizardState.stage_filter.indexOf(cb.value) !== -1;
		});
		document.querySelectorAll('#atora-fu-frequency-chips input[type="radio"]').forEach(function (radio) {
			radio.checked = radio.value === wizardState.recurrence_rule;
		});
		var excludeSeq = document.getElementById('atora-fu-exclude-sequence');
		if (excludeSeq) { excludeSeq.checked = !!wizardState.domain_config.exclude_active_sequence; }
	}

	function collectSelectedSections() {
		var ids = [];
		document.querySelectorAll('#atora-fu-section-chips input:checked').forEach(function (cb) { ids.push(parseInt(cb.value, 10)); });
		return ids;
	}

	function collectSelectedStages() {
		var chipSelector = wizardState.domain === 'commercial' ? '#atora-fu-deal-stage-chips' : '#atora-fu-stage-chips';
		var stages = [];
		document.querySelectorAll(chipSelector + ' input:checked').forEach(function (cb) { stages.push(cb.value); });
		return stages;
	}

	function collectDomainConfig() {
		var config = Object.assign({}, wizardState.domain_config);
		if (wizardState.domain === 'commercial') {
			var excludeSeq = document.getElementById('atora-fu-exclude-sequence');
			config.exclude_active_sequence = !!(excludeSeq && excludeSeq.checked);
		}
		return config;
	}

	function currentEntityScopeIds() {
		if (wizardState.domain === 'commercial') {
			return wizardState.manual_mode ? wizardState.selected_contacts.map(function (c) { return c.id; }) : [];
		}
		return collectSelectedSections();
	}

	function currentStageFilter() {
		if (wizardState.domain === 'commercial' && wizardState.manual_mode) { return []; }
		return wizardState.stage_filter.length ? wizardState.stage_filter : collectSelectedStages();
	}

	function updatePreview() {
		var box = document.getElementById('atora-fu-preview-box');
		if (!box) { return; }

		var entityScopeIds = currentEntityScopeIds();
		var stageFilter = currentStageFilter();
		var isManual = wizardState.domain === 'commercial' && wizardState.manual_mode;
		var isCommercialByStage = wizardState.domain === 'commercial' && !wizardState.manual_mode;

		if (isManual && !entityScopeIds.length) {
			box.innerHTML = '<p>Buscá y elegí al menos un contacto para ver la vista previa.</p>';
			return;
		}
		if (isCommercialByStage && !stageFilter.length) {
			box.innerHTML = '<p>Elegí al menos una etapa para ver la vista previa.</p>';
			return;
		}
		if (!isManual && !isCommercialByStage && (!entityScopeIds.length || !stageFilter.length)) {
			box.innerHTML = '<p>Elegí al menos una sección y una etapa para ver la vista previa.</p>';
			return;
		}

		box.innerHTML = '<p>Calculando…</p>';
		api('followup-plans/preview', {
			method: 'POST',
			body: {
				section_ids: entityScopeIds,
				stage_filter: stageFilter,
				domain: wizardState.domain,
				domain_config: collectDomainConfig()
			}
		}).then(function (data) {
			var labels = DOMAIN_LABELS[wizardState.domain] || DOMAIN_LABELS.academic;
			var entityWord = data.count === 1 ? labels.entity : labels.entityPlural;
			var scopeWord = wizardState.domain === 'commercial' ? '' :
				' en ' + data.sections.length + ' sección' + (data.sections.length === 1 ? '' : 'es');
			box.innerHTML = '<p class="atora-fu-preview-count"><strong>Hoy esto tocaría a ' + data.count +
				' ' + entityWord + '</strong>' + scopeWord + '.</p>';
		}).catch(function (err) {
			box.innerHTML = '<p>No se pudo calcular la vista previa.</p>';
			toast(err.message || 'Error al calcular la vista previa.', 'error');
		});
	}

	function goToStep(step) {
		wizardState.step = step;
		document.querySelectorAll('.atora-fu-wizard-step').forEach(function (section) {
			section.hidden = parseInt(section.getAttribute('data-step'), 10) !== step;
		});
		document.querySelectorAll('.atora-fu-step-dot').forEach(function (dot) {
			dot.classList.toggle('is-active', parseInt(dot.getAttribute('data-step'), 10) === step);
		});
		document.getElementById('atora-fu-wizard-back').hidden = step === 1;
		document.getElementById('atora-fu-wizard-next').hidden = step === 4;
		document.getElementById('atora-fu-wizard-apply').hidden = step !== 4;

		if (step === 3) { applyDomainBlocks(); updatePreview(); }
	}

	/* ── Buscador de contactos ("Cuenta clave", comercial) ────────── */
	var _contactSearchTimer = null;

	function renderSelectedContacts() {
		var wrap = document.getElementById('atora-fu-selected-contacts');
		if (!wrap) { return; }
		wrap.innerHTML = wizardState.selected_contacts.map(function (c) {
			return '<span class="atora-fu-chip atora-fu-chip--removable" data-contact-id="' + c.id + '">' +
				escapeHtml(c.name) + ' <button type="button" class="atora-fu-chip-remove" data-contact-id="' + c.id + '" aria-label="Quitar">&times;</button>' +
				'</span>';
		}).join('');
	}

	function addSelectedContact(id, name) {
		id = parseInt(id, 10);
		if (!id || wizardState.selected_contacts.some(function (c) { return c.id === id; })) { return; }
		wizardState.selected_contacts.push({ id: id, name: name });
		renderSelectedContacts();
		if (wizardState.step === 3) { updatePreview(); }
	}

	function removeSelectedContact(id) {
		id = parseInt(id, 10);
		wizardState.selected_contacts = wizardState.selected_contacts.filter(function (c) { return c.id !== id; });
		renderSelectedContacts();
		if (wizardState.step === 3) { updatePreview(); }
	}

	function searchContacts(term) {
		var results = document.getElementById('atora-fu-contact-search-results');
		if (!results) { return; }
		if (!term) { results.hidden = true; results.innerHTML = ''; return; }

		api('contacts/search', { params: { q: term, limit: 8 } }).then(function (data) {
			var items = data.items || [];
			if (!items.length) {
				results.innerHTML = '<p class="atora-fu-search-empty">Sin resultados.</p>';
				results.hidden = false;
				return;
			}
			results.innerHTML = items.map(function (c) {
				return '<button type="button" class="atora-fu-search-result" data-contact-id="' + c.id + '" data-contact-name="' + escapeHtml(c.name || '') + '">' +
					escapeHtml(c.name || '(sin nombre)') + (c.email ? ' — ' + escapeHtml(c.email) : '') +
					'</button>';
			}).join('');
			results.hidden = false;
		}).catch(function () {
			results.hidden = true;
		});
	}

	function applyPlan() {
		var name = document.getElementById('atora-fu-plan-name').value.trim();
		if (!name) { toast('Ponele un nombre al plan.', 'error'); return; }

		var entityScopeIds = currentEntityScopeIds();
		var stageFilter = currentStageFilter();
		var isManual = wizardState.domain === 'commercial' && wizardState.manual_mode;
		var isCommercialByStage = wizardState.domain === 'commercial' && !wizardState.manual_mode;
		var frequencyInput = document.querySelector('#atora-fu-frequency-chips input:checked');
		var recurrenceRule = frequencyInput ? frequencyInput.value : wizardState.recurrence_rule;

		if (isManual && !entityScopeIds.length) {
			toast('Elegí al menos un contacto.', 'error');
			return;
		}
		if (isCommercialByStage && !stageFilter.length) {
			toast('Falta elegir a quién seguir.', 'error');
			return;
		}
		if (!isManual && !isCommercialByStage && (!entityScopeIds.length || !stageFilter.length)) {
			toast('Faltan secciones o etapas.', 'error');
			return;
		}

		var t = toast('Aplicando plan…', 'loading');
		api('followup-plans', {
			method: 'POST',
			body: {
				domain: wizardState.domain,
				name: name,
				template_key: wizardState.template_key,
				section_ids: entityScopeIds,
				stage_filter: stageFilter,
				domain_config: collectDomainConfig(),
				recurrence_rule: recurrenceRule,
				action_type: 'checkin'
			}
		}).then(function () {
			t.dismiss();
			toast('Plan aplicado.', 'success');
			closeWizard();
			if (calendar) { calendar.refetchEvents(); }
		}).catch(function (err) {
			t.dismiss();
			toast(err.message || 'No se pudo aplicar el plan.', 'error');
		});
	}

	/* ============================================================
	 *  BOOTSTRAP
	 * ============================================================ */
	document.addEventListener('DOMContentLoaded', function () {
		initCalendar();

		document.querySelectorAll('.atora-fu-domain-tab').forEach(function (tab) {
			tab.addEventListener('click', function () { setDomain(tab.getAttribute('data-domain')); });
		});

		var openBtn = document.getElementById('atora-fu-open-wizard');
		if (openBtn) { openBtn.addEventListener('click', openWizard); }

		var closeWizardBtn = document.getElementById('atora-fu-wizard-close');
		var wizardBackdrop  = document.getElementById('atora-fu-wizard-backdrop');
		if (closeWizardBtn) { closeWizardBtn.addEventListener('click', closeWizard); }
		if (wizardBackdrop) { wizardBackdrop.addEventListener('click', closeWizard); }

		var blankBtn = document.getElementById('atora-fu-blank-template');
		if (blankBtn) {
			blankBtn.addEventListener('click', function () {
				wizardState.template_key = null;
				wizardState.stage_filter = [];
				wizardState.manual_mode = false;
				applyDomainBlocks();
				goToStep(3);
			});
		}

		var nextBtn = document.getElementById('atora-fu-wizard-next');
		var backBtn = document.getElementById('atora-fu-wizard-back');
		var applyBtn = document.getElementById('atora-fu-wizard-apply');
		if (nextBtn) { nextBtn.addEventListener('click', function () { goToStep(Math.min(4, wizardState.step + 1)); }); }
		if (backBtn) { backBtn.addEventListener('click', function () { goToStep(Math.max(1, wizardState.step - 1)); }); }
		if (applyBtn) { applyBtn.addEventListener('click', applyPlan); }

		document.querySelectorAll('#atora-fu-section-chips input, #atora-fu-stage-chips input, #atora-fu-deal-stage-chips input, #atora-fu-exclude-sequence').forEach(function (input) {
			input.addEventListener('change', function () {
				if (wizardState.step === 3) { updatePreview(); }
			});
		});

		var contactSearchInput = document.getElementById('atora-fu-contact-search');
		if (contactSearchInput) {
			contactSearchInput.addEventListener('input', function () {
				var term = contactSearchInput.value.trim();
				if (_contactSearchTimer) { clearTimeout(_contactSearchTimer); }
				_contactSearchTimer = setTimeout(function () { searchContacts(term); }, 300);
			});
		}

		var contactResults = document.getElementById('atora-fu-contact-search-results');
		if (contactResults) {
			contactResults.addEventListener('click', function (e) {
				var btn = e.target.closest('.atora-fu-search-result');
				if (!btn) { return; }
				addSelectedContact(btn.getAttribute('data-contact-id'), btn.getAttribute('data-contact-name'));
				contactSearchInput.value = '';
				contactResults.hidden = true;
				contactResults.innerHTML = '';
			});
		}

		var selectedContactsWrap = document.getElementById('atora-fu-selected-contacts');
		if (selectedContactsWrap) {
			selectedContactsWrap.addEventListener('click', function (e) {
				var btn = e.target.closest('.atora-fu-chip-remove');
				if (btn) { removeSelectedContact(btn.getAttribute('data-contact-id')); }
			});
		}

		// PT-1.4 (6.9.0): abrir/cerrar, acciones en lote y por fila del
		// panel de ocurrencia ya no se cablean acá a mano -- las maneja
		// AtoraUI.Panel internamente (wireOnce() para cerrar/backdrop,
		// un listener delegado por open() para las acciones). Ver
		// renderOccurrencePanel()/onOccurrencePanelAction() más arriba.

		// PT-3.2 (6.8.0, "Hoy"): un enlace con ?event_id=N (el que ya
		// construye CLMS_Academic_Messaging_Bridge desde 6.6.0 y ahora
		// también el agregador "Hoy") debe abrir directo el panel de esa
		// ocurrencia -- "clic lleva a la acción específica", no solo a
		// esta pantalla para que el usuario tenga que volver a buscar.
		var deepLinkEventId = parseInt((new URLSearchParams(window.location.search)).get('event_id'), 10);
		if (deepLinkEventId) { openPanel(deepLinkEventId); }
	});
})();
