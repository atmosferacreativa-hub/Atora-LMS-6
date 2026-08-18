/**
 * ATORA LMS — CRM v2 Fase 2
 * crm-calendar.js
 *
 * Responsabilidades:
 *   1. Inicializar FullCalendar 6 en #crm-cal-mount
 *   2. Cargar eventos desde REST GET /calendar/events con filtros de rango + tipo
 *   3. Botones de navegación y vista (Mes / Semana / Lista) conectados al calendario
 *   4. Filtros de capa: Tareas / Campañas (oculta/muestra sin nueva petición)
 *   5. Drag-and-drop de tareas → POST /calendar/task/{id}/reschedule
 *   6. Click en evento → Popover con detalle REST + botón Completar
 *   7. Formulario sidebar → POST /tasks con toast feedback
 *   8. Toast reutiliza el sistema de Fase 1 (crm-v2.js debe cargarse antes)
 *
 * Dependencias:
 *   - FullCalendar @fullcalendar/core, daygrid, timegrid, list, interaction (UMD globals)
 *   - FullCalendar locale es (atora-crm-calendar-es handle)
 *   - window.atoraCrmV2 (nonce, restBase) — provisto por wp_localize_script
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.22.0
 */
(function () {
	'use strict';

	/* ============================================================
	 *  CONFIG
	 * ============================================================ */
	var cfg   = window.atoraCrmV2 || {};
	var REST  = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';
	var I18N  = cfg.i18n || {};

	// Estado de filtros de capa (visibilidad en el DOM, no re-request)
	var filterState = { tasks: true, campaigns: true };

	/* ============================================================
	 *  API HELPER — compartido con crm-v2.js
	 * ============================================================ */
	function api(path, options) {
		options = options || {};
		var method  = options.method || 'GET';
		var body    = options.body ? JSON.stringify(options.body) : undefined;
		var url     = REST + '/' + path.replace(/^\/+/, '');

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
			body: body,
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) { throw new Error(data.message || ('HTTP ' + res.status)); }
				return data;
			});
		});
	}

	/* ============================================================
	 *  TOAST — si crm-v2.js no está cargado, fallback inline
	 * ============================================================ */
	var _toastEl    = null;
	var _toastTimer = null;

	function toast(message, type, duration) {
		if (!_toastEl) { _toastEl = document.getElementById('crm-cal-toast') || document.getElementById('crm-toast'); }
		if (!_toastEl) { return { dismiss: function () {} }; }

		if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }

		_toastEl.className   = 'crm-toast crm-toast--' + (type || 'info');
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
			},
		};
	}

	/* ============================================================
	 *  INSTANCIA DE FULLCALENDAR
	 * ============================================================ */
	var calendar = null;

	function buildTypes() {
		var t = [];
		if (filterState.tasks)     { t.push('tasks'); }
		if (filterState.campaigns) { t.push('campaigns'); }
		return t.join(',');
	}

	function initCalendar() {
		var mountEl = document.getElementById('crm-cal-mount');
		if (!mountEl) { return; }

		// Verificar que FullCalendar esté disponible
		if (typeof FullCalendar === 'undefined') {
			mountEl.innerHTML = '<p style="padding:20px;color:#64748b">' +
				(I18N.fcNotLoaded || 'FullCalendar no cargado. Verifica la conexión a internet.') + '</p>';
			return;
		}

		calendar = new FullCalendar.Calendar(mountEl, {
			// ── Plugins y vista inicial ─────────────────────────────
			initialView: 'dayGridMonth',
			headerToolbar: false,          // usamos nuestra propia toolbar PHP
			locale: 'es',
			firstDay: 1,                   // lunes
			height: 'auto',
			fixedWeekCount: false,
			dayMaxEvents: 4,               // "+N more" a partir del 5to

			// ── Interacción ─────────────────────────────────────────
			editable: true,                // activa drag-and-drop global
			droppable: false,
			eventStartEditable: true,      // solo mover, no redimensionar
			eventDurationEditable: false,

			// ── Fuente de eventos: REST ─────────────────────────────
			events: function (info, successCb, failureCb) {
				var types = buildTypes();
				if (!types) {
					// Nada visible: devolver vacío sin petición
					successCb([]);
					return;
				}

				api('calendar/events', {
					params: {
						start: info.startStr,
						end:   info.endStr,
						types: types,
					},
				}).then(function (data) {
					successCb(data.events || []);
					updateTitle();
				}).catch(function (err) {
					toast(err.message || (I18N.error || 'Error al cargar eventos.'), 'error');
					failureCb(err);
				});
			},

			// ── Drag-and-drop: reprogramar tarea ───────────────────
			eventDrop: function (info) {
				var props   = info.event.extendedProps || {};
				var taskId  = props.task_id;

				// Campañas no son editables — no debería ocurrir, pero por si acaso
				if (props.type !== 'task' || !taskId) {
					info.revert();
					return;
				}

				var newDate = info.event.start;
				if (!newDate) { info.revert(); return; }

				var iso = newDate.toISOString();
				var t   = toast(I18N.rescheduling || 'Reprogramando tarea…', 'loading');

				api('calendar/task/' + taskId + '/reschedule', {
					method: 'POST',
					body:   { due_at: iso },
				}).then(function (data) {
					t.dismiss();
					toast(data.message || I18N.saved || 'Tarea reprogramada.', 'success');
				}).catch(function (err) {
					t.dismiss();
					toast(err.message || I18N.error, 'error');
					info.revert();
				});
			},

			// ── Click en evento → Popover ──────────────────────────
			eventClick: function (info) {
				info.jsEvent.preventDefault();
				var props = info.event.extendedProps || {};
				openPopover(props, info.event);
			},

			// ── Click en día vacío → pre-rellenar el formulario ────
			dateClick: function (info) {
				var dueEl = document.getElementById('crm-task-due');
				if (!dueEl) { return; }
				// Formato datetime-local: YYYY-MM-DDTHH:MM
				var d = info.date;
				var pad = function (n) { return String(n).padStart(2, '0'); };
				dueEl.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' +
					pad(d.getDate()) + 'T09:00';
				document.getElementById('crm-task-title') &&
					document.getElementById('crm-task-title').focus();
			},

			// ── Renderizado de evento ──────────────────────────────
			eventDidMount: function (info) {
				// Tooltip nativo al hover
				info.el.title = info.event.title;
			},
		});

		calendar.render();
		updateTitle();
	}

	/* ============================================================
	 *  BARRA DE CONTROLES PROPIA
	 * ============================================================ */
	function updateTitle() {
		if (!calendar) { return; }
		var titleEl = document.getElementById('crm-cal-title');
		if (titleEl) { titleEl.textContent = calendar.view.title; }

		// Actualizar botón activo de vista
		var viewBtns = document.querySelectorAll('.crm-cal-view-btn');
		var curView  = calendar.view.type;
		viewBtns.forEach(function (btn) {
			btn.classList.toggle('is-active', btn.getAttribute('data-view') === curView);
		});
	}

	function bindToolbar() {
		var prev  = document.getElementById('crm-cal-prev');
		var next  = document.getElementById('crm-cal-next');
		var today = document.getElementById('crm-cal-today');

		if (prev)  { prev.addEventListener('click',  function () { calendar && calendar.prev();  updateTitle(); }); }
		if (next)  { next.addEventListener('click',  function () { calendar && calendar.next();  updateTitle(); }); }
		if (today) { today.addEventListener('click', function () { calendar && calendar.today(); updateTitle(); }); }

		document.querySelectorAll('.crm-cal-view-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!calendar) { return; }
				calendar.changeView(btn.getAttribute('data-view'));
				updateTitle();
			});
		});
	}

	/* ============================================================
	 *  FILTROS DE CAPA (tareas / campañas)
	 * ============================================================ */
	function bindFilters() {
		var filterTasks     = document.getElementById('crm-cal-filter-tasks');
		var filterCampaigns = document.getElementById('crm-cal-filter-campaigns');

		function applyFilter() {
			filterState.tasks     = filterTasks     ? filterTasks.checked     : true;
			filterState.campaigns = filterCampaigns ? filterCampaigns.checked : true;
			if (!calendar) { return; }
			calendar.refetchEvents();
		}

		if (filterTasks)     { filterTasks.addEventListener('change',     applyFilter); }
		if (filterCampaigns) { filterCampaigns.addEventListener('change', applyFilter); }
	}

	/* ============================================================
	 *  POPOVER DE DETALLE
	 * ============================================================ */
	var popoverEl  = null;
	var overlayEl  = null;

	function openPopover(props, event) {
		popoverEl  = popoverEl  || document.getElementById('crm-cal-popover');
		overlayEl  = overlayEl  || document.getElementById('crm-cal-overlay');
		var bodyEl   = document.getElementById('crm-cal-popover-body');
		var actionsEl = document.getElementById('crm-cal-popover-actions');

		if (!popoverEl || !bodyEl) { return; }

		// Mostrar con estado de carga
		bodyEl.innerHTML = '<p class="atora-crm-v2-muted">' + (I18N.loading || 'Cargando...') + '</p>';
		actionsEl.innerHTML = '';
		popoverEl.removeAttribute('hidden');
		overlayEl && overlayEl.removeAttribute('hidden');

		if (props.type === 'task') {
			api('calendar/task/' + props.task_id)
				.then(function (data) {
					renderTaskPopover(data.task || {}, bodyEl, actionsEl, event);
				})
				.catch(function (err) {
					bodyEl.innerHTML = '<p style="color:#dc2626">' + escHtml(err.message) + '</p>';
				});
		} else if (props.type === 'campaign') {
			renderCampaignPopover(props, bodyEl, actionsEl);
		}
	}

	function renderTaskPopover(task, bodyEl, actionsEl, event) {
		var priorityLabels = { low: 'Baja', medium: 'Media', high: 'Alta', urgent: 'Urgente' };
		var statusLabels   = { pending: 'Pendiente', in_progress: 'En progreso', completed: 'Completada', canceled: 'Cancelada' };

		bodyEl.innerHTML =
			'<h4 style="margin:0 0 8px;font-size:15px">' + escHtml(task.title || '') + '</h4>' +
			'<dl class="crm-cal-popover__dl">' +
			  '<dt>Tipo</dt><dd>' + escHtml(task.task_type_label || task.task_type || '') + '</dd>' +
			  '<dt>Prioridad</dt><dd>' + escHtml(priorityLabels[task.priority] || task.priority || '') + '</dd>' +
			  '<dt>Estado</dt><dd>' + escHtml(statusLabels[task.status] || task.status || '') + '</dd>' +
			  (task.contact_name ? '<dt>Contacto</dt><dd>' + escHtml(task.contact_name) + '</dd>' : '') +
			  (task.notes ? '<dt>Notas</dt><dd>' + escHtml(task.notes) + '</dd>' : '') +
			'</dl>';

		// Botones de acción
		var actions = '';

		if (task.status !== 'completed' && task.task_id) {
			actions += '<button class="button button-primary crm-cal-pop-action" ' +
				'data-action="complete" data-task-id="' + escHtml(String(task.id)) + '">' +
				(I18N.completeTask || 'Marcar completada') + '</button>';
		}

		if (task.contact_id) {
			actions += '<a class="button" href="' +
				escHtml(crmAdminUrl('atora-crm-v2-contacts') + '&contact_id=' + task.contact_id) + '">' +
				(I18N.viewContact || 'Ver contacto') + '</a>';
		}

		actionsEl.innerHTML = actions;

		// Bind del botón completar
		var completeBtn = actionsEl.querySelector('[data-action="complete"]');
		if (completeBtn) {
			completeBtn.addEventListener('click', function () {
				var tid = absint(this.getAttribute('data-task-id') || task.id);
				completeTaskFromPopover(tid, event);
			});
		}
	}

	function renderCampaignPopover(props, bodyEl, actionsEl) {
		var statusLabels = { draft: 'Borrador', scheduled: 'Programada', queued: 'Encolada', sent: 'Enviada' };
		bodyEl.innerHTML =
			'<h4 style="margin:0 0 8px;font-size:15px">' + escHtml(props.campaign_id ? 'Campaña #' + props.campaign_id : 'Campaña') + '</h4>' +
			'<dl class="crm-cal-popover__dl">' +
			  '<dt>Canal</dt><dd>' + escHtml(String(props.channel || 'email').toUpperCase()) + '</dd>' +
			  '<dt>Estado</dt><dd>' + escHtml(statusLabels[props.status] || props.status || '') + '</dd>' +
			  (props.subject ? '<dt>Asunto</dt><dd>' + escHtml(props.subject) + '</dd>' : '') +
			'</dl>';

		actionsEl.innerHTML = '<a class="button" href="' +
			escHtml(crmAdminUrl('atora-crm-v2-campaigns')) + '">' +
			(I18N.viewCampaigns || 'Gestionar campañas') + '</a>';
	}

	function completeTaskFromPopover(taskId, fcEvent) {
		var t = toast(I18N.completing || 'Completando tarea…', 'loading');
		api('tasks/' + taskId + '/complete', { method: 'POST', body: {} })
			.then(function (data) {
				t.dismiss();
				toast(data.message || I18N.saved || 'Tarea completada.', 'success');
				closePopover();
				// Actualizar el evento visualmente en el calendario
				if (fcEvent) {
					fcEvent.setProp('backgroundColor', '#94a3b8');
					fcEvent.setProp('borderColor', '#94a3b8');
				}
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			});
	}

	function closePopover() {
		if (popoverEl) { popoverEl.setAttribute('hidden', ''); }
		if (overlayEl) { overlayEl.setAttribute('hidden', ''); }
	}

	function bindPopoverClose() {
		var closeBtn = document.getElementById('crm-cal-popover-close');
		if (closeBtn) { closeBtn.addEventListener('click', closePopover); }

		var overlay = document.getElementById('crm-cal-overlay');
		if (overlay) { overlay.addEventListener('click', closePopover); }

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { closePopover(); }
		});
	}

	/* ============================================================
	 *  FORMULARIO DE NUEVA TAREA (sidebar)
	 * ============================================================ */
	function bindTaskForm() {
		var submitBtn = document.getElementById('crm-task-submit');
		if (!submitBtn) { return; }

		submitBtn.addEventListener('click', function () {
			var title    = (document.getElementById('crm-task-title')    || {}).value || '';
			var taskType = (document.getElementById('crm-task-type')     || {}).value || 'followup_email';
			var priority = (document.getElementById('crm-task-priority') || {}).value || 'medium';
			var dueAt    = (document.getElementById('crm-task-due')      || {}).value || '';
			var notes    = (document.getElementById('crm-task-notes')    || {}).value || '';

			if (!title.trim()) {
				toast(I18N.titleRequired || 'Indica el título de la tarea.', 'error');
				document.getElementById('crm-task-title') && document.getElementById('crm-task-title').focus();
				return;
			}

			submitBtn.disabled    = true;
			submitBtn.textContent = I18N.creating || 'Creando…';
			var t = toast(I18N.creating || 'Creando tarea…', 'loading');

			api('tasks', {
				method: 'POST',
				body: {
					title:     title.trim(),
					task_type: taskType,
					priority:  priority,
					due_at:    dueAt,
					notes:     notes.trim(),
				},
			}).then(function (data) {
				t.dismiss();
				toast(data.message || I18N.saved || 'Tarea creada.', 'success');
				// Limpiar formulario
				['crm-task-title', 'crm-task-notes'].forEach(function (id) {
					var el = document.getElementById(id);
					if (el) { el.value = ''; }
				});
				var dueEl = document.getElementById('crm-task-due');
				if (dueEl) { dueEl.value = ''; }
				// Refrescar el calendario para mostrar el nuevo evento
				calendar && calendar.refetchEvents();
			}).catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			}).finally(function () {
				submitBtn.disabled    = false;
				submitBtn.textContent = I18N.createTask || 'Crear tarea';
			});
		});
	}

	/* ============================================================
	 *  UTILIDADES
	 * ============================================================ */
	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;')
			.replace(/>/g, '&gt;').replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function absint(val) {
		return Math.max(0, parseInt(val, 10) || 0);
	}

	function crmAdminUrl(page) {
		return (cfg.adminUrl || '') + 'admin.php?page=' + encodeURIComponent(page);
	}

	/* ============================================================
	 *  API PÚBLICA — compatibilidad con crm-kanban.js
	 * ============================================================ */
	window.atoraCrmV2Api = window.atoraCrmV2Api || {
		request: function (path, options) {
			var body = {};
			if (options && options.body) {
				try { body = JSON.parse(options.body); } catch (e) { body = {}; }
			}
			return api(path, Object.assign({}, options, { body: body }));
		},
	};

	/* ============================================================
	 *  INIT
	 * ============================================================ */
	function init() {
		initCalendar();
		bindToolbar();
		bindFilters();
		bindPopoverClose();
		bindTaskForm();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
