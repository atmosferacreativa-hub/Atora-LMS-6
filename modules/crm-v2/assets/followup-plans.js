/**
 * ATORA LMS — CRM v2, Planes de seguimiento (PT-4, sprint 6.6.0)
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
 *      diseño (--atora-blue-700 programado, --atora-warning
 *      pendiente con estudiantes sin contactar, --atora-success
 *      completado) — nunca una paleta genérica de librería.
 *   2. Arrastrar para reprogramar (PT-4.3) → POST .../reschedule
 *   3. Panel lateral al click en un bloque (PT-4.4): estudiantes de
 *      esa ocurrencia, marcar contactado individual/lote, posponer,
 *      saltar, excluir.
 *   4. Asistente de 4 pasos para aplicar un plan (PT-4.2), con vista
 *      previa en vivo (PT-2.2) antes de guardar.
 *
 * @package ATORA_LMS\CRM_V2
 * @since   6.6.0
 */
(function () {
	'use strict';

	var cfg   = window.atoraCrmV2 || {};
	var REST  = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';

	var STAGE_COLORS = {
		completed_all: 'var(--atora-success, #1D9E75)',
		pending:       'var(--atora-blue-700, #1d4ed8)',
		pending_uncontacted: 'var(--atora-warning, #BA7517)'
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

	/* ============================================================
	 *  CALENDARIO
	 * ============================================================ */
	var calendar = null;

	function eventColor(props) {
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
				api('followup-plans/calendar-events', {
					params: { start: info.startStr, end: info.endStr }
				}).then(function (data) {
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
	 *  PANEL LATERAL DE OCURRENCIA (PT-4.4)
	 * ============================================================ */
	var currentOccurrenceId = null;

	function openPanel(eventId) {
		currentOccurrenceId = eventId;
		var panel = document.getElementById('atora-fu-panel');
		if (!panel) { return; }

		panel.hidden = false;
		panel.setAttribute('aria-hidden', 'false');
		document.getElementById('atora-fu-panel-students').innerHTML = '<p>Cargando…</p>';

		api('followup-plans/occurrence/' + eventId).then(function (data) {
			document.getElementById('atora-fu-panel-title').textContent = data.title || 'Ocurrencia';
			document.getElementById('atora-fu-panel-date').textContent = data.date || '';
			renderStudents(data);
		}).catch(function (err) {
			toast(err.message || 'No se pudo cargar la ocurrencia.', 'error');
		});
	}

	function closePanel() {
		var panel = document.getElementById('atora-fu-panel');
		if (!panel) { return; }
		panel.hidden = true;
		panel.setAttribute('aria-hidden', 'true');
		currentOccurrenceId = null;
	}

	function renderStudents(data) {
		var container = document.getElementById('atora-fu-panel-students');
		var students = data.students || [];

		if (!students.length) {
			var reasons = {
				sin_configuracion: 'Este plan todavía no tiene secciones o etapas configuradas.',
				sin_estudiantes_en_secciones: 'No hay estudiantes activos en las secciones de este plan.',
				nadie_en_esas_etapas_hoy: 'Nadie está en las etapas de este plan hoy — buena señal.',
				nadie_cumple_el_filtro_hoy: 'Nadie cumple el filtro de este plan hoy.'
			};
			container.innerHTML = '<p class="atora-fu-empty-occurrence">' +
				(reasons[data.empty_reason] || 'No hay estudiantes para esta ocurrencia hoy.') + '</p>';
			return;
		}

		container.innerHTML = students.map(function (s) {
			var contactedClass = s.contacted ? ' is-contacted' : '';
			return '' +
				'<div class="atora-fu-student-row' + contactedClass + '" data-user-id="' + s.user_id + '">' +
					'<div class="atora-fu-student-info">' +
						'<span class="atora-fu-student-name">' + escapeHtml(s.display_name) + '</span>' +
						'<span class="atora-fu-student-stage">' + escapeHtml(s.stage_label) + '</span>' +
					'</div>' +
					'<div class="atora-fu-student-actions">' +
						'<button type="button" class="button atora-fu-btn-contact" data-user-id="' + s.user_id + '">' +
							(s.contacted ? '✓ Contactado' : 'Marcar contactado') +
						'</button>' +
						'<button type="button" class="button-link atora-fu-btn-exclude" data-user-id="' + s.user_id + '" title="Excluir de esta ocurrencia">Excluir</button>' +
					'</div>' +
				'</div>';
		}).join('');
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
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
		if (!window.confirm('¿Excluir a este estudiante solo de esta ocurrencia?')) { return; }
		api('followup-plans/occurrence/' + currentOccurrenceId + '/exclude', {
			method: 'POST',
			body: { user_id: userId }
		}).then(function () {
			toast('Estudiante excluido de esta ocurrencia.', 'success');
			openPanel(currentOccurrenceId);
		}).catch(function (err) {
			toast(err.message || 'No se pudo excluir al estudiante.', 'error');
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
		var rows = document.querySelectorAll('#atora-fu-panel-students .atora-fu-student-row:not(.is-contacted)');
		if (!rows.length) { toast('Ya están todos contactados.', 'info'); return; }
		var promises = [];
		rows.forEach(function (row) {
			var userId = row.getAttribute('data-user-id');
			promises.push(api('followup-plans/occurrence/' + currentOccurrenceId + '/contact', {
				method: 'POST',
				body: { user_id: userId }
			}));
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
	var wizardState = { step: 1, template_key: null, section_ids: [], stage_filter: [], recurrence_rule: 'WEEKLY;BYDAY=MO' };

	function openWizard() {
		wizardState = { step: 1, template_key: null, section_ids: [], stage_filter: [], recurrence_rule: 'WEEKLY;BYDAY=MO' };
		var wizard = document.getElementById('atora-fu-wizard');
		if (!wizard) { return; }
		wizard.hidden = false;
		wizard.setAttribute('aria-hidden', 'false');
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
		api('followup-plans/templates').then(function (data) {
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
		document.querySelectorAll('#atora-fu-stage-chips input[type="checkbox"]').forEach(function (cb) {
			cb.checked = wizardState.stage_filter.indexOf(cb.value) !== -1;
		});
		document.querySelectorAll('#atora-fu-frequency-chips input[type="radio"]').forEach(function (radio) {
			radio.checked = radio.value === wizardState.recurrence_rule;
		});
	}

	function collectSelectedSections() {
		var ids = [];
		document.querySelectorAll('#atora-fu-section-chips input:checked').forEach(function (cb) { ids.push(parseInt(cb.value, 10)); });
		return ids;
	}

	function collectSelectedStages() {
		var stages = [];
		document.querySelectorAll('#atora-fu-stage-chips input:checked').forEach(function (cb) { stages.push(cb.value); });
		return stages;
	}

	function updatePreview() {
		var box = document.getElementById('atora-fu-preview-box');
		if (!box) { return; }

		var sectionIds = collectSelectedSections();
		var stageFilter = wizardState.stage_filter.length ? wizardState.stage_filter : collectSelectedStages();

		if (!sectionIds.length || !stageFilter.length) {
			box.innerHTML = '<p>Elegí al menos una sección y una etapa para ver la vista previa.</p>';
			return;
		}

		box.innerHTML = '<p>Calculando…</p>';
		api('followup-plans/preview', {
			method: 'POST',
			body: { section_ids: sectionIds, stage_filter: stageFilter }
		}).then(function (data) {
			box.innerHTML = '<p class="atora-fu-preview-count"><strong>Hoy esto tocaría a ' + data.count +
				' estudiante' + (data.count === 1 ? '' : 's') + '</strong> en ' + data.sections.length +
				' sección' + (data.sections.length === 1 ? '' : 'es') + '.</p>';
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

		if (step === 3) { updatePreview(); }
	}

	function applyPlan() {
		var name = document.getElementById('atora-fu-plan-name').value.trim();
		if (!name) { toast('Ponele un nombre al plan.', 'error'); return; }

		var sectionIds = collectSelectedSections();
		var stageFilter = wizardState.stage_filter.length ? wizardState.stage_filter : collectSelectedStages();
		var frequencyInput = document.querySelector('#atora-fu-frequency-chips input:checked');
		var recurrenceRule = frequencyInput ? frequencyInput.value : wizardState.recurrence_rule;

		if (!sectionIds.length || !stageFilter.length) {
			toast('Faltan secciones o etapas.', 'error');
			return;
		}

		var t = toast('Aplicando plan…', 'loading');
		api('followup-plans', {
			method: 'POST',
			body: {
				name: name,
				template_key: wizardState.template_key,
				section_ids: sectionIds,
				stage_filter: stageFilter,
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
				goToStep(3);
			});
		}

		var nextBtn = document.getElementById('atora-fu-wizard-next');
		var backBtn = document.getElementById('atora-fu-wizard-back');
		var applyBtn = document.getElementById('atora-fu-wizard-apply');
		if (nextBtn) { nextBtn.addEventListener('click', function () { goToStep(Math.min(4, wizardState.step + 1)); }); }
		if (backBtn) { backBtn.addEventListener('click', function () { goToStep(Math.max(1, wizardState.step - 1)); }); }
		if (applyBtn) { applyBtn.addEventListener('click', applyPlan); }

		document.querySelectorAll('#atora-fu-section-chips input, #atora-fu-stage-chips input').forEach(function (input) {
			input.addEventListener('change', function () {
				if (wizardState.step === 3) { updatePreview(); }
			});
		});

		var panelClose = document.getElementById('atora-fu-panel-close');
		var panelBackdrop = document.getElementById('atora-fu-panel-backdrop');
		if (panelClose) { panelClose.addEventListener('click', closePanel); }
		if (panelBackdrop) { panelBackdrop.addEventListener('click', closePanel); }

		var skipBtn = document.getElementById('atora-fu-panel-skip');
		var postponeBtn = document.getElementById('atora-fu-panel-postpone');
		var contactAllBtn = document.getElementById('atora-fu-panel-contact-all');
		if (skipBtn) { skipBtn.addEventListener('click', skipOccurrence); }
		if (postponeBtn) { postponeBtn.addEventListener('click', postponeOccurrence); }
		if (contactAllBtn) { contactAllBtn.addEventListener('click', markAllContacted); }

		var studentsContainer = document.getElementById('atora-fu-panel-students');
		if (studentsContainer) {
			studentsContainer.addEventListener('click', function (e) {
				var contactBtn = e.target.closest('.atora-fu-btn-contact');
				var excludeBtn = e.target.closest('.atora-fu-btn-exclude');
				if (contactBtn) { markContacted(contactBtn.getAttribute('data-user-id')); }
				if (excludeBtn) { excludeStudent(excludeBtn.getAttribute('data-user-id')); }
			});
		}
	});
})();
