/**
 * ATORA LMS — Búsqueda persistente (PT-2, sprint 6.9.0)
 *
 * Ícono de lupa siempre visible en la misma posición (ver
 * render_atora_search_bar() en trait-admin-menu-hubs.php), debounce de
 * 350ms (PT-2.5), cancela el fetch en vuelo si el usuario sigue
 * escribiendo, resultados agrupados por tipo vía AtoraUI.renderRow
 * (PT-2.3) y un clic lleva directo a la URL de la ficha/acción — nunca
 * un listado intermedio (PT-2.4).
 *
 * El alcance/permisos de qué aparece en los resultados NO se decide
 * acá — lo resuelve por completo el REST endpoint (clms/v1/search,
 * CLMS_UI_Search_Service), reutilizando los mecanismos de alcance ya
 * existentes. Este archivo solo pinta lo que el servidor ya decidió
 * que el usuario puede ver.
 *
 * @package ATORA_LMS
 * @since   6.9.0
 */
(function () {
	'use strict';

	var cfg = window.atoraSearch || {};
	var REST = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';

	var DEBOUNCE_MS = 350;
	var debounceTimer = null;
	var activeController = null;

	function search(term) {
		if (activeController) { activeController.abort(); }
		activeController = ('AbortController' in window) ? new AbortController() : null;

		var url = REST + '/search?q=' + encodeURIComponent(term) + '&limit=5';
		return fetch(url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': NONCE },
			signal: activeController ? activeController.signal : undefined
		}).then(function (res) { return res.json(); });
	}

	function renderGroups(groups, resultsEl) {
		var keys = Object.keys(groups || {});
		if (!keys.length) {
			resultsEl.innerHTML = '<p class="atora-ui-search-empty">Sin resultados.</p>';
			return;
		}

		resultsEl.innerHTML = keys.map(function (key) {
			var group = groups[key];
			var rows = (group.items || []).map(function (item) {
				return window.AtoraUI.renderRow({
					id: item.id,
					name: item.name,
					meta: item.meta,
					badges: item.badges,
					url: item.url
				});
			}).join('');
			return '<div class="atora-ui-search-group">' +
				'<h4 class="atora-ui-search-group-title">' + (group.label || '') + '</h4>' +
				rows +
				'</div>';
		}).join('');
	}

	document.addEventListener('DOMContentLoaded', function () {
		var toggle = document.getElementById('atora-ui-search-toggle');
		var box = document.getElementById('atora-ui-search-box');
		var input = document.getElementById('atora-ui-search-input');
		var results = document.getElementById('atora-ui-search-results');
		if (!toggle || !box || !input || !results || !window.AtoraUI) { return; }

		function openBox() {
			box.hidden = false;
			toggle.setAttribute('aria-expanded', 'true');
			input.focus();
		}
		function closeBox() {
			box.hidden = true;
			toggle.setAttribute('aria-expanded', 'false');
			results.hidden = true;
		}

		toggle.addEventListener('click', function () {
			if (box.hidden) { openBox(); } else { closeBox(); }
		});

		document.addEventListener('click', function (e) {
			if (!box.hidden && !box.contains(e.target) && e.target !== toggle) { closeBox(); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !box.hidden) { closeBox(); }
		});

		input.addEventListener('input', function () {
			var term = input.value.trim();
			if (debounceTimer) { clearTimeout(debounceTimer); }

			if (term.length < 2) {
				results.hidden = true;
				results.innerHTML = '';
				return;
			}

			debounceTimer = setTimeout(function () {
				search(term).then(function (data) {
					renderGroups(data.groups || {}, results);
					results.hidden = false;
				}).catch(function (err) {
					if (err && 'AbortError' === err.name) { return; } // cancelado por una tecla nueva -- no es un error real.
				});
			}, DEBOUNCE_MS);
		});
	});
})();
