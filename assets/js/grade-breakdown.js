/**
 * ATORA Grade Breakdown — Componente vanilla JS para la vista de notas.
 *
 * Se monta automáticamente sobre cualquier elemento con
 * data-atora-grade-breakdown, data-user-id y data-course-id.
 *
 * Consume: GET /wp-json/clms/v1/grades/breakdown/{user_id}?course_id=X
 *
 * @since 4.22
 */
(function (window, document) {
	'use strict';

	if (window.ATORA && window.ATORA.gradeBreakdown) {
		return;
	}

	if (!window.ATORA) {
		window.ATORA = {};
	}

	// ── Utilidades ────────────────────────────────────────────────────────────

	function getRestBase() {
		return window.ATORA && window.ATORA.rest && window.ATORA.rest.base
			? window.ATORA.rest.base.replace(/\/$/, '')
			: '/wp-json/clms/v1';
	}

	function getNonce() {
		return window.ATORA && window.ATORA.rest && window.ATORA.rest.nonce
			? window.ATORA.rest.nonce
			: '';
	}

	function esc(str) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(String(str)));
		return d.innerHTML;
	}

	function t(key, fallback) {
		var dict = window.ATORA && window.ATORA.gradeBreakdownI18n ? window.ATORA.gradeBreakdownI18n : {};
		return dict[key] || fallback;
	}

	function fetchGradeBreakdown(userId, courseId, callback) {
		var url = getRestBase() + '/grades/breakdown/' + userId + '?course_id=' + courseId;
		var xhr = new XMLHttpRequest();
		xhr.open('GET', url, true);
		xhr.setRequestHeader('X-WP-Nonce', getNonce());
		xhr.setRequestHeader('Content-Type', 'application/json');
		xhr.onreadystatechange = function () {
			if (xhr.readyState !== 4) return;
			if (xhr.status === 200) {
				try {
					callback(null, JSON.parse(xhr.responseText));
				} catch (e) {
					callback(e, null);
				}
			} else {
				callback(new Error('HTTP ' + xhr.status), null);
			}
		};
		xhr.send();
	}

	// ── Renderizado ───────────────────────────────────────────────────────────

	function getScoreClass(score) {
		if (score >= 90) return 'atora-gb__score--excellent';
		if (score >= 80) return 'atora-gb__score--good';
		if (score >= 70) return 'atora-gb__score--fair';
		return 'atora-gb__score--low';
	}

	function renderLoading() {
		return '<div class="atora-gb__loading" role="status">' +
			'<span class="atora-gb__spinner"></span>' +
			'<span>' + esc(t('loading_grades', 'Cargando calificaciones…')) + '</span>' +
			'</div>';
	}

	function renderError(message) {
		return '<div class="atora-gb__error" role="alert">' + esc(message) + '</div>';
	}

	function renderSummary(data) {
		return '<div class="atora-gb__hero">' +
			'<div class="atora-gb__grade-badge atora-gb__grade-badge--letter">' + esc(data.letter_grade) + '</div>' +
			'<div class="atora-gb__grade-info">' +
			'<span class="atora-gb__grade-numeric">' + esc(data.numeric_score) + '</span>' +
			'<span class="atora-gb__grade-label">/ 100</span>' +
			(data.percentile ? '<span class="atora-gb__percentile">' + esc(t('top_prefix', 'Top')) + ' ' + esc(100 - data.percentile) + '% ' + esc(t('of_course', 'del curso')) + '</span>' : '') +
			'</div>' +
			'</div>';
	}

	function renderComponents(components) {
		if (!components || !Object.keys(components).length) {
			return '<p class="atora-gb__empty">' + esc(t('no_components', 'Sin componentes registrados.')) + '</p>';
		}

		var rows = '';
		Object.keys(components).forEach(function (key) {
			var c = components[key];
			var pct = Math.min(100, Math.max(0, c.completion_percentage || 0));
			rows += '<tr>' +
				'<td class="atora-gb__td atora-gb__td--name">' + esc(c.name) + '</td>' +
				'<td class="atora-gb__td"><span class="atora-gb__score ' + getScoreClass(c.score) + '">' + esc(c.score.toFixed(1)) + '%</span></td>' +
				'<td class="atora-gb__td">' + esc(c.weight) + '%</td>' +
				'<td class="atora-gb__td">' + esc(c.contribution.toFixed(2)) + '</td>' +
				'<td class="atora-gb__td">' +
				'<div class="atora-gb__bar"><div class="atora-gb__bar-fill" style="width:' + pct + '%" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100"></div></div>' +
				'<span class="atora-gb__progress-text">' + esc(c.progress) + '</span>' +
				'</td>' +
				'</tr>';
		});

		return '<table class="atora-gb__table" aria-label="' + esc(t('aria_grade_components', 'Componentes de calificación')) + '">' +
			'<thead><tr>' +
			'<th class="atora-gb__th">' + esc(t('component', 'Componente')) + '</th>' +
			'<th class="atora-gb__th">' + esc(t('score', 'Puntaje')) + '</th>' +
			'<th class="atora-gb__th">' + esc(t('weight', 'Peso')) + '</th>' +
			'<th class="atora-gb__th">' + esc(t('contribution', 'Contribución')) + '</th>' +
			'<th class="atora-gb__th">' + esc(t('progress', 'Progreso')) + '</th>' +
			'</tr></thead>' +
			'<tbody>' + rows + '</tbody>' +
			'</table>';
	}

	function renderRecommendations(recs) {
		if (!recs || !recs.priority_actions || !recs.priority_actions.length) {
			return '<p class="atora-gb__rec-message">' + esc(recs && recs.message ? recs.message : t('no_recommendations', 'Sin recomendaciones disponibles.')) + '</p>';
		}

		var cards = '';
		recs.priority_actions.forEach(function (action) {
			var actionBtns = '';
			if (action.actions && action.actions.length) {
				action.actions.forEach(function (step) {
					actionBtns += '<li><span class="atora-gb__action-tag">' + esc(step.title) + '</span></li>';
				});
			}
			cards += '<div class="atora-gb__rec-card">' +
				'<div class="atora-gb__rec-header">' +
				'<span class="atora-gb__rec-label">' + esc(action.label || action.component) + '</span>' +
				'<span class="atora-gb__rec-impact">+' + esc(action.potential_impact.toFixed(1)) + ' pts</span>' +
				'</div>' +
				'<p class="atora-gb__rec-current">' + esc(t('current', 'Actual')) + ': ' + esc(action.current_score) + '%</p>' +
				(actionBtns ? '<ul class="atora-gb__rec-actions">' + actionBtns + '</ul>' : '') +
				'</div>';
		});

		return '<p class="atora-gb__rec-message">' + esc(recs.message) + '</p>' + cards;
	}

	function renderTabs(activeTab) {
		var tabs = [
			{ id: 'summary',    label: t('tab_summary', 'Resumen') },
			{ id: 'components', label: t('tab_components', 'Componentes') },
			{ id: 'improve',    label: t('tab_improve', 'Cómo mejorar') },
		];
		return '<div class="atora-gb__tabs" role="tablist">' +
			tabs.map(function (t) {
				var active = t.id === activeTab ? ' atora-gb__tab--active" aria-selected="true' : '" aria-selected="false';
				return '<button class="atora-gb__tab' + active + '" role="tab" data-tab="' + esc(t.id) + '">' + esc(t.label) + '</button>';
			}).join('') +
			'</div>';
	}

	function renderPanel(data, activeTab) {
		if (activeTab === 'components') {
			return '<div class="atora-gb__panel" id="atora-gb-panel-components" role="tabpanel">' +
				renderComponents(data.components) +
				'</div>';
		}
		if (activeTab === 'improve') {
			return '<div class="atora-gb__panel" id="atora-gb-panel-improve" role="tabpanel">' +
				renderRecommendations(data.recommendations) +
				'</div>';
		}
		// summary
		var summary = data.summary || {};
		return '<div class="atora-gb__panel" id="atora-gb-panel-summary" role="tabpanel">' +
			'<div class="atora-gb__summary-stats">' +
			'<div class="atora-gb__stat"><span class="atora-gb__stat-label">' + esc(t('components', 'Componentes')) + '</span>' +
			'<span class="atora-gb__stat-value">' + esc((summary.completed_components || 0)) + ' / ' + esc((summary.components_count || 0)) + '</span></div>' +
			'<div class="atora-gb__stat"><span class="atora-gb__stat-label">' + esc(t('total_weight', 'Peso total')) + '</span>' +
			'<span class="atora-gb__stat-value">' + esc(data.total_weight_used || 100) + '%</span></div>' +
			'</div>' +
			'</div>';
	}

	// ── Montaje ───────────────────────────────────────────────────────────────

	function mount(container, userId, courseId) {
		var activeTab = 'summary';

		container.innerHTML = renderLoading();

		fetchGradeBreakdown(userId, courseId, function (err, data) {
			if (err || !data) {
				container.innerHTML = renderError(t('load_error', 'No se pudo cargar la calificación. Inténtalo de nuevo.'));
				return;
			}

			function render() {
				container.innerHTML =
					'<div class="atora-gb">' +
					renderSummary(data) +
					renderTabs(activeTab) +
					renderPanel(data, activeTab) +
					'</div>';

				container.querySelectorAll('.atora-gb__tab').forEach(function (btn) {
					btn.addEventListener('click', function () {
						activeTab = btn.getAttribute('data-tab');
						render();
					});
				});
			}

			render();
		});
	}

	// ── CSS inline ────────────────────────────────────────────────────────────

	function injectStyles() {
		if (document.getElementById('atora-gb-styles')) return;
		var style = document.createElement('style');
		style.id = 'atora-gb-styles';
		style.textContent = [
			'.atora-gb{font-family:inherit;color:var(--clms-ink,#0f172a)}',
			'.atora-gb__loading,.atora-gb__error{padding:1.5rem;text-align:center}',
			'.atora-gb__spinner{display:inline-block;width:1.2rem;height:1.2rem;border:2px solid #e2e8f0;border-top-color:#6366f1;border-radius:50%;animation:atora-spin .7s linear infinite;margin-right:.5rem;vertical-align:middle}',
			'@keyframes atora-spin{to{transform:rotate(360deg)}}',
			'.atora-gb__hero{display:flex;align-items:center;gap:1.25rem;padding:1.25rem 0 1rem}',
			'.atora-gb__grade-badge{font-size:2.5rem;font-weight:700;color:#6366f1;min-width:3rem;text-align:center}',
			'.atora-gb__grade-numeric{font-size:1.75rem;font-weight:700}',
			'.atora-gb__grade-label{color:#64748b;font-size:.9rem;margin-left:.25rem}',
			'.atora-gb__percentile{display:block;font-size:.8rem;color:#16a34a;margin-top:.25rem}',
			'.atora-gb__tabs{display:flex;gap:.5rem;border-bottom:2px solid #e2e8f0;margin-bottom:1rem}',
			'.atora-gb__tab{padding:.5rem 1rem;border:none;background:none;cursor:pointer;font-size:.875rem;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s}',
			'.atora-gb__tab--active,.atora-gb__tab:hover{color:#6366f1;border-bottom-color:#6366f1}',
			'.atora-gb__table{width:100%;border-collapse:collapse;font-size:.875rem}',
			'.atora-gb__th{text-align:left;padding:.5rem .75rem;border-bottom:1px solid #e2e8f0;color:#64748b;font-weight:600}',
			'.atora-gb__td{padding:.6rem .75rem;border-bottom:1px solid #f1f5f9}',
			'.atora-gb__td--name{font-weight:500}',
			'.atora-gb__score{display:inline-block;padding:.15rem .5rem;border-radius:.25rem;font-weight:600}',
			'.atora-gb__score--excellent{background:#f0fdf4;color:#16a34a}',
			'.atora-gb__score--good{background:#eff6ff;color:#1d4ed8}',
			'.atora-gb__score--fair{background:#fef3c7;color:#b45309}',
			'.atora-gb__score--low{background:#fef2f2;color:#dc2626}',
			'.atora-gb__bar{height:6px;background:#f1f5f9;border-radius:3px;min-width:60px}',
			'.atora-gb__bar-fill{height:100%;background:#6366f1;border-radius:3px;transition:width .4s ease}',
			'.atora-gb__progress-text{font-size:.75rem;color:#64748b;margin-left:.5rem}',
			'.atora-gb__summary-stats{display:flex;gap:2rem;padding:1rem 0}',
			'.atora-gb__stat{display:flex;flex-direction:column}',
			'.atora-gb__stat-label{font-size:.75rem;color:#64748b}',
			'.atora-gb__stat-value{font-size:1.25rem;font-weight:600}',
			'.atora-gb__rec-message{margin:0 0 1rem;color:#334155;font-size:.9rem}',
			'.atora-gb__rec-card{border:1px solid #e2e8f0;border-radius:.5rem;padding:1rem;margin-bottom:.75rem}',
			'.atora-gb__rec-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem}',
			'.atora-gb__rec-label{font-weight:600;font-size:.9rem}',
			'.atora-gb__rec-impact{font-size:.8rem;background:#f0fdf4;color:#16a34a;padding:.2rem .5rem;border-radius:.25rem;font-weight:600}',
			'.atora-gb__rec-current{font-size:.8rem;color:#64748b;margin:.25rem 0}',
			'.atora-gb__rec-actions{list-style:none;padding:0;margin:.5rem 0 0;display:flex;flex-wrap:wrap;gap:.4rem}',
			'.atora-gb__action-tag{display:inline-block;padding:.2rem .6rem;background:#f1f5f9;border-radius:1rem;font-size:.78rem;color:#334155}',
		].join('');
		document.head.appendChild(style);
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	function init() {
		injectStyles();

		var containers = document.querySelectorAll('[data-atora-grade-breakdown]');
		containers.forEach(function (container) {
			var userId   = parseInt(container.getAttribute('data-user-id'), 10);
			var courseId = parseInt(container.getAttribute('data-course-id'), 10);
			if (userId && courseId) {
				mount(container, userId, courseId);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	window.ATORA.gradeBreakdown = { mount: mount };

})(window, document);
