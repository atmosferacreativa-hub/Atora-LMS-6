/**
 * ATORA LMS — Followup_Panel / Followup_List_Row, versión JS (PT-1.2/1.3, sprint 6.9.0)
 *
 * Espejo cliente de includes/ui/class-ui-followup-panel.php y
 * class-ui-list-row.php — mismo marcado (mismas clases `.atora-ui-*`),
 * para los casos donde el panel se llena vía REST sin recargar la
 * página (calendario académico/comercial, "Hoy"). El renderizado en sí
 * es genérico y no sabe nada de REST endpoints específicos: quien abre
 * el panel (followup-plans.js, today.js) le pasa los datos ya
 * resueltos y un callback `onAction` — este archivo no hace ningún
 * fetch.
 *
 * Expone `window.AtoraUI = { renderRow, renderEmpty, Panel }`.
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */
(function () {
	'use strict';

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.textContent = str || '';
		return div.innerHTML;
	}

	function initial(name) {
		name = (name || '').trim();
		return name ? name.charAt(0).toUpperCase() : '•';
	}

	/**
	 * Espejo de CLMS_UI_List_Row::render().
	 *
	 * @param {Object} item {id, name, meta, urgency?, tone?, badges?, url?, actions?, done?}
	 * @return {string}
	 */
	function renderRow(item) {
		item = item || {};
		var id = item.id || 0;
		var name = item.name || '';
		var meta = item.meta || '';
		var urgency = item.urgency || '';
		var tone = item.tone || '';
		var badges = item.badges || [];
		var url = item.url || '';
		var actions = item.actions || [];
		var done = !!item.done;

		var stateClass = done ? ' atora-ui-row--done' : '';
		if (['alta', 'media', 'baja'].indexOf(urgency) !== -1) {
			stateClass += ' atora-ui-row--urgency-' + urgency;
		} else if (tone === 'positivo') {
			stateClass += ' atora-ui-row--tone-positivo';
		}

		var badgesHtml = badges.map(function (b) {
			return b ? '<span class="atora-ui-row-badge">' + escapeHtml(b) + '</span>' : '';
		}).join('');

		var actionsHtml = actions.map(function (a) {
			if (!a || !a.action_id) { return ''; }
			var label = (done && a.done_label) ? a.done_label : (a.label || '');
			return '<button type="button" class="atora-ui-row-action" data-action-id="' + escapeHtml(a.action_id) + '" data-id="' + escapeHtml(String(id)) + '">' + escapeHtml(label) + '</button>';
		}).join('');

		var inner = '<span class="atora-ui-row-avatar" aria-hidden="true">' + escapeHtml(initial(name)) + '</span>' +
			'<span class="atora-ui-row-info">' +
				'<span class="atora-ui-row-name">' + escapeHtml(name) + '</span>' +
				(meta ? '<span class="atora-ui-row-meta">' + escapeHtml(meta) + '</span>' : '') +
				(badgesHtml ? '<span class="atora-ui-row-badges">' + badgesHtml + '</span>' : '') +
			'</span>';

		if (actionsHtml) {
			return '<div class="atora-ui-row' + stateClass + '" data-id="' + escapeHtml(String(id)) + '">' + inner + '<span class="atora-ui-row-actions">' + actionsHtml + '</span></div>';
		}
		if (url) {
			return '<a class="atora-ui-row' + stateClass + '" href="' + escapeHtml(url) + '" data-id="' + escapeHtml(String(id)) + '">' + inner + '<span class="atora-ui-row-arrow" aria-hidden="true">→</span></a>';
		}
		return '<div class="atora-ui-row' + stateClass + '" data-id="' + escapeHtml(String(id)) + '">' + inner + '</div>';
	}

	/**
	 * @param {string} title
	 * @param {string} sub
	 * @return {string}
	 */
	function renderEmpty(title, sub) {
		return '<div class="atora-ui-panel-empty">' +
			'<p class="atora-ui-panel-empty-title">' + escapeHtml(title || '') + '</p>' +
			(sub ? '<p class="atora-ui-panel-empty-sub">' + escapeHtml(sub) + '</p>' : '') +
			'</div>';
	}

	/* ============================================================
	 *  PANEL CONTROLLER
	 *  Opera sobre el marcado generado por
	 *  CLMS_UI_Followup_Panel::render_shell($domId) — espera los IDs
	 *  {domId}-backdrop/-close/-title/-subtitle/-actions/-rows ya en
	 *  el DOM. No hace fetch: quien llama a open() ya trae los datos.
	 * ============================================================ */
	var _wired = {}; // domId -> true, para no duplicar listeners de cerrar.

	function wireOnce(domId, onClose) {
		if (_wired[domId]) { return; }
		_wired[domId] = true;

		var closeBtn = document.getElementById(domId + '-close');
		var backdrop = document.getElementById(domId + '-backdrop');
		if (closeBtn) { closeBtn.addEventListener('click', function () { closePanel(domId, onClose); }); }
		if (backdrop) { backdrop.addEventListener('click', function () { closePanel(domId, onClose); }); }
	}

	function closePanel(domId, onClose) {
		var panel = document.getElementById(domId);
		if (!panel) { return; }
		panel.hidden = true;
		panel.setAttribute('aria-hidden', 'true');
		if (typeof onClose === 'function') { onClose(); }
	}

	/**
	 * @param {string} domId Debe existir en el DOM (render_shell()).
	 * @param {Object} config {
	 *   title, subtitle, items, actions,
	 *   emptyTitle, emptySub,
	 *   onAction: function(actionId, itemId|null, scope),
	 *   onClose: function()
	 * }
	 */
	function open(domId, config) {
		config = config || {};
		var panel = document.getElementById(domId);
		if (!panel) { return; }

		wireOnce(domId, config.onClose);

		var titleEl = document.getElementById(domId + '-title');
		var subtitleEl = document.getElementById(domId + '-subtitle');
		var actionsEl = document.getElementById(domId + '-actions');
		var rowsEl = document.getElementById(domId + '-rows');

		if (titleEl) { titleEl.textContent = config.title || ''; }
		if (subtitleEl) { subtitleEl.textContent = config.subtitle || ''; }

		var items = config.items || [];
		var actions = config.actions || [];
		var itemActions = actions.filter(function (a) { return a && a.scope === 'item'; });
		var bulkActions = actions.filter(function (a) { return a && a.scope === 'bulk'; });

		if (actionsEl) {
			actionsEl.innerHTML = (items.length && bulkActions.length) ? bulkActions.map(function (a) {
				return '<button type="button" class="button atora-ui-panel-bulk-action" data-action-id="' + escapeHtml(a.action_id) + '">' + escapeHtml(a.label || '') + '</button>';
			}).join('') : '';
		}

		if (rowsEl) {
			var noticeHtml = config.notice ? '<p class="atora-ui-panel-notice">' + escapeHtml(config.notice) + '</p>' : '';
			if (!items.length) {
				rowsEl.innerHTML = noticeHtml + renderEmpty(config.emptyTitle || 'Nada por acá.', config.emptySub || '');
			} else {
				rowsEl.innerHTML = noticeHtml + items.map(function (item) {
					if (itemActions.length && !item.actions) { item = Object.assign({}, item, { actions: itemActions }); }
					return renderRow(item);
				}).join('');
			}
		}

		// Un solo listener delegado por panel (re-asignado en cada open(),
		// así siempre apunta al `config.onAction` actual sin acumular).
		if (rowsEl) {
			rowsEl.onclick = function (e) {
				var btn = e.target.closest('.atora-ui-row-action');
				if (btn && typeof config.onAction === 'function') {
					config.onAction(btn.getAttribute('data-action-id'), btn.getAttribute('data-id'), 'item');
				}
			};
		}
		if (actionsEl) {
			actionsEl.onclick = function (e) {
				var btn = e.target.closest('.atora-ui-panel-bulk-action');
				if (btn && typeof config.onAction === 'function') {
					config.onAction(btn.getAttribute('data-action-id'), null, 'bulk');
				}
			};
		}

		panel.hidden = false;
		panel.setAttribute('aria-hidden', 'false');
	}

	window.AtoraUI = window.AtoraUI || {};
	window.AtoraUI.renderRow = renderRow;
	window.AtoraUI.renderEmpty = renderEmpty;
	window.AtoraUI.Panel = { open: open, close: closePanel };
})();
