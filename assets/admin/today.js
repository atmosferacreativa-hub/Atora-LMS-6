/**
 * ATORA LMS — "Hoy" (PT-1.5/PT-4.1, sprint 6.9.0)
 *
 * Intercepta el clic en un ítem de followup académico/comercial (los
 * que llevan `data-event-id`, ver CLMS_Today_Aggregator_Service::
 * render_items_html()) y abre el panel compartido en línea
 * (AtoraUI.Panel, assets/shared/followup-panel.js) en vez de navegar a
 * la página del calendario — mismo componente, mismo REST namespace
 * (atora-crm/v2/followup-plans/...) ya usado por
 * modules/crm-v2/assets/followup-plans.js desde 6.6.0/6.7.0. El href
 * normal sigue en el marcado como respaldo (clic derecho, JS
 * deshabilitado).
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */
(function () {
	'use strict';

	// Localizado propio (window.atoraToday), no window.atoraCrmV2 --
	// "Hoy" no carga el bootstrap completo de CRM v2 (kanban,
	// FullCalendar x6 scripts) solo para tener restBase/nonce, eso
	// violaría el principio de carga rápida de esta pantalla (6.8.0
	// §UX). Mismo REST namespace igual (atora-crm/v2), valor distinto
	// del objeto JS que lo transporta.
	var cfg = window.atoraToday || {};
	var REST = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';
	var PANEL_ID = 'atora-hoy-panel';

	function api(path, options) {
		options = options || {};
		var method = options.method || 'GET';
		var body = options.body ? JSON.stringify(options.body) : undefined;
		var url = REST + '/' + path.replace(/^\/+/, '');

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

	var EMPTY_REASONS = {
		sin_configuracion: 'Este plan todavía no tiene a quién seguir configurado.',
		sin_estudiantes_en_secciones: 'No hay estudiantes activos en las secciones de este plan.',
		nadie_en_esas_etapas_hoy: 'Nadie está en las etapas de este plan hoy — buena señal.',
		nadie_cumple_el_filtro_hoy: 'Nadie cumple el filtro de este plan hoy.',
		todos_en_secuencia_activa: 'Todos los contactos de hoy ya están en una secuencia automática — nada que revisar a mano.'
	};

	function studentToRowItem(s, domain) {
		var meta = s.meta || {};
		var badges = [];
		var urgency;

		if ('commercial' === domain) {
			var score = typeof meta.score === 'number' ? meta.score : 100;
			urgency = score <= 39 ? 'alta' : (score < 70 ? 'media' : 'baja');
			if (meta.score_label) { badges.push(meta.score_label + ' (' + meta.score + ')'); }
			if (meta.sequence && meta.sequence.active) {
				badges.push('✉ ' + meta.sequence.sequence_name + ' · paso ' + meta.sequence.current_step + '/' + meta.sequence.total_steps);
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
				{ label: 'Marcar contactado', done_label: '✓ Contactado', action_id: 'contact' }
			]
		};
	}

	function openOccurrence(eventId) {
		api('followup-plans/occurrence/' + eventId).then(function (data) {
			var domain = data.domain || 'academic';
			var items = (data.students || []).map(function (s) { return studentToRowItem(s, domain); });

			window.AtoraUI.Panel.open(PANEL_ID, {
				title: data.title || 'Ocurrencia',
				subtitle: data.date || '',
				items: items,
				emptyTitle: EMPTY_REASONS[data.empty_reason] || 'No hay nadie para esta ocurrencia hoy.',
				actions: [
					{ label: 'Marcar a todos contactados', action_id: 'contact_all', scope: 'bulk' },
					{ label: 'Marcar contactado', action_id: 'contact', scope: 'item' }
				],
				onAction: function (actionId, itemId) {
					if ('contact' === actionId) { return markContacted(eventId, itemId); }
					if ('contact_all' === actionId) { return markAllContacted(eventId, items); }
				}
			});
		}).catch(function () {
			// Sin toast propio en esta pantalla (PT-3 de 6.8.0 no trae uno) --
			// el href normal del ítem sigue funcionando como respaldo si el
			// fetch falla, así que se deja navegar en vez de mostrar un error mudo.
			window.location.href = window.location.href;
		});
	}

	function markContacted(eventId, userId) {
		api('followup-plans/occurrence/' + eventId + '/contact', {
			method: 'POST',
			body: { user_id: userId }
		}).then(function () { openOccurrence(eventId); });
	}

	function markAllContacted(eventId, items) {
		var pending = items.filter(function (i) { return !i.done; });
		if (!pending.length) { return; }
		Promise.all(pending.map(function (i) {
			return api('followup-plans/occurrence/' + eventId + '/contact', { method: 'POST', body: { user_id: i.id } });
		})).then(function () { openOccurrence(eventId); });
	}

	document.addEventListener('DOMContentLoaded', function () {
		if (!window.AtoraUI || !document.getElementById(PANEL_ID)) { return; }

		document.querySelectorAll('.atora-hoy-item-link[data-event-id]').forEach(function (link) {
			link.addEventListener('click', function (e) {
				e.preventDefault();
				openOccurrence(link.getAttribute('data-event-id'));
			});
		});
	});
})();
