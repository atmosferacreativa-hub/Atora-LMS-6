/**
 * ATORA-LMS — Template Builder
 *
 * Metabox "Diseño de página": drag-and-drop de secciones, toggle activar/desactivar,
 * variante por sección, selector de tema, galería de plantillas, aplicar y restablecer.
 *
 * Config inyectada por wp_localize_script como window.ATORA_TB:
 *   {
 *     postId:   number,
 *     ctxList:  string[],          // ['course_commercial', 'course_overview', …]
 *     presets:  { [key]: { label, sections_enabled[] } },
 *     i18n:     { [key]: string }
 *   }
 *
 * El HTML del metabox es generado por PHP (CLMS_UI_Admin_Builder).
 * Este script solo añade comportamiento interactivo.
 */
/* global Sortable, ATORA_TB, jQuery */
(function () {
	'use strict';

	// ── Config ──────────────────────────────────────────────────────────────────

	const cfg          = window.ATORA_TB || {};
	const CTX_LIST     = Array.isArray(cfg.ctxList) ? cfg.ctxList : [];
	const PRESETS_BY_CTX = cfg.presetsByCtx || {};

	function t(key) {
		return (cfg.i18n && cfg.i18n[key]) ? cfg.i18n[key] : key;
	}

	// ── DOM helpers ─────────────────────────────────────────────────────────────

	function getList(ctx) {
		return document.getElementById('atora-uib-list-' + ctx);
	}

	function getInput(ctx) {
		return document.getElementById('atora-uib-schema-' + ctx);
	}

	function getPresetGallery(ctx) {
		return document.querySelector('[data-atora-template-gallery="' + ctx + '"]');
	}

	function getSelectedPreset(ctx) {
		var gallery = getPresetGallery(ctx);
		if (gallery) {
			var checked = gallery.querySelector('input[type="radio"]:checked');
			if (checked && checked.value) {
				return checked.value;
			}
		}

		var presetSelect = document.getElementById('atora-uib-preset-' + ctx);
		return presetSelect ? presetSelect.value : 'default';
	}

	function syncPresetGallery(ctx, presetKey) {
		var gallery = getPresetGallery(ctx);
		if (!gallery) return;

		gallery.querySelectorAll('[data-preset-card]').forEach(function (card) {
			var input = card.querySelector('input[type="radio"]');
			var value = input ? input.value : card.getAttribute('data-preset-card');
			var active = String(value) === String(presetKey);

			card.classList.toggle('is-active', active);
			if (input) {
				input.checked = active;
			}
		});
	}

	// ── Schema builder ──────────────────────────────────────────────────────────

	/**
	 * Reconstruye el JSON del schema v2 a partir del estado actual del DOM
	 * para el contexto dado.
	 *
	 * @param {string} ctx
	 * @returns {object|null}
	 */
	function buildSchema(ctx) {
		var list = getList(ctx);
		if (!list) return null;

		// Secciones: orden, enabled, variant
		var sections = [];
		list.querySelectorAll('.atora-uib-item').forEach(function (item) {
			var id      = item.getAttribute('data-id') || '';
			var toggle  = item.querySelector('.atora-uib-toggle');
			var varSel  = item.querySelector('.atora-uib-variant-sel');
			var enabled = toggle ? toggle.checked : true;
			var variant = (varSel && varSel.value) ? varSel.value : 'default';

			if (!id) return;
			sections.push({
				id:         id,
				enabled:    enabled,
				variant:    variant,
				props:      {},
				visibility: item.getAttribute('data-visibility') || 'always',
				roles:      [],
				style:      {}
			});
		});

		// Límites
		var limits    = {};
		var limitsWrap = document.getElementById('atora-uib-limits-' + ctx);
		if (limitsWrap) {
			limitsWrap.querySelectorAll('.atora-uib-limit-input').forEach(function (inp) {
				var key = inp.getAttribute('data-limit-key');
				var val = parseInt(inp.value, 10);
				if (key) { limits[key] = isNaN(val) ? 0 : Math.max(0, val); }
			});
		}

		// Tema
		var themeSelect = document.getElementById('atora-uib-theme-' + ctx);
		var theme       = themeSelect ? themeSelect.value : 'light';

		// Preset seleccionado
		var preset       = getSelectedPreset(ctx);

		return {
			version: 2,
			preset:  preset,
			theme:   theme,
			variant: 'default',
			props:   {},
			sections: sections,
			limits:   limits
		};
	}

	/**
	 * Serializa el schema actual y lo escribe en el hidden input del contexto.
	 * Input vacío → PHP borrará el override (Restablecer).
	 *
	 * @param {string} ctx
	 */
	function writeSchema(ctx) {
		var input = getInput(ctx);
		if (!input) return;
		var schema = buildSchema(ctx);
		input.value = schema ? JSON.stringify(schema) : '';
	}

	// ── Item style sync ─────────────────────────────────────────────────────────

	function syncItemStyle(item) {
		var toggle = item.querySelector('.atora-uib-toggle');
		if (!toggle) return;
		item.classList.toggle('is-disabled', !toggle.checked);

		// La variant select se deshabilita si la sección está desactivada
		var varSel = item.querySelector('.atora-uib-variant-sel');
		if (varSel) { varSel.disabled = !toggle.checked; }
	}

	// ── Sortable ─────────────────────────────────────────────────────────────────

	function initSortables() {
		CTX_LIST.forEach(function (ctx) {
			var list = getList(ctx);
			if (!list) return;
			if (list.dataset.uibSortableInit === '1') return;

			// Primario: SortableJS si está disponible.
			if (typeof Sortable !== 'undefined') {
				Sortable.create(list, {
					handle:     '.atora-uib-handle',
					animation:  150,
					ghostClass: 'sortable-ghost',
					chosenClass: 'sortable-chosen',
					onEnd: function () { writeSchema(ctx); }
				});
				list.dataset.uibSortableInit = '1';
				return;
			}

			// Fallback: jQuery UI Sortable (core de WordPress admin).
			if (window.jQuery && window.jQuery.fn && typeof window.jQuery.fn.sortable === 'function') {
				window.jQuery(list).sortable({
					handle: '.atora-uib-handle',
					placeholder: 'atora-uib-sortable-placeholder',
					tolerance: 'pointer',
					update: function () { writeSchema(ctx); }
				});
				list.dataset.uibSortableInit = '1';
			}
		});
	}

	// ── Events: toggles, variants, limits, theme ────────────────────────────────

	CTX_LIST.forEach(function (ctx) {
		var gallery = getPresetGallery(ctx);

		if (gallery) {
			if (gallery.dataset.galleryInit !== '1') {
				gallery.addEventListener('change', function (e) {
					var tgt = e.target;
					if (!tgt || tgt.type !== 'radio') {
						return;
					}

					syncPresetGallery(ctx, tgt.value || 'default');
					writeSchema(ctx);
				});
				gallery.dataset.galleryInit = '1';
			}

			syncPresetGallery(ctx, getSelectedPreset(ctx));
		}

		// List: toggle + variant change
		var list = getList(ctx);
		if (list) {
			list.addEventListener('change', function (e) {
				var tgt  = e.target;
				var item = tgt.closest('.atora-uib-item');

				if (tgt.classList.contains('atora-uib-toggle') && item) {
					syncItemStyle(item);
				}
				writeSchema(ctx);
			});
		}

		// Limits inputs
		var limitsWrap = document.getElementById('atora-uib-limits-' + ctx);
		if (limitsWrap) {
			limitsWrap.addEventListener('input', function (e) {
				if (e.target.classList.contains('atora-uib-limit-input')) {
					writeSchema(ctx);
				}
			});
		}

		// Theme selector
		var themeSelect = document.getElementById('atora-uib-theme-' + ctx);
		if (themeSelect) {
			themeSelect.addEventListener('change', function () { writeSchema(ctx); });
		}
	});

	// ── Tabs ─────────────────────────────────────────────────────────────────────

	document.querySelectorAll('.atora-uib-wrap').forEach(function (wrap) {
		wrap.querySelectorAll('.atora-uib-tab').forEach(function (tab) {
			tab.addEventListener('click', function () {
				wrap.querySelectorAll('.atora-uib-tab').forEach(function (t) { t.classList.remove('is-active'); });
				wrap.querySelectorAll('.atora-uib-panel').forEach(function (p) { p.classList.remove('is-active'); });
				tab.classList.add('is-active');

				var ctx   = tab.getAttribute('data-ctx');
				var panel = wrap.querySelector('[data-panel="' + ctx + '"]');
				if (panel) { panel.classList.add('is-active'); }
			});
		});
	});

	// ── Apply preset ──────────────────────────────────────────────────────────────

	document.querySelectorAll('.atora-uib-apply-preset').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var ctx    = btn.getAttribute('data-ctx');
			var key    = getSelectedPreset(ctx);
			var preset = (PRESETS_BY_CTX[ctx] || {})[key];
			if (!preset) return;

			var list = getList(ctx);
			if (!list) return;

			// Construir lookup id → spec cuando el preset usa formato completo
			var specMap = {};
			if (Array.isArray(preset.sections) && preset.sections.length > 0) {
				preset.sections.forEach(function (s) {
					if (s && s.id) { specMap[s.id] = s; }
				});
			}
			var hasSpec    = Object.keys(specMap).length > 0;
			var legacyList = preset.sections_enabled || [];
			var legacyOrder = preset.sections_order || [];
			var legacyRef   = legacyOrder.length > 0 ? legacyOrder : legacyList;

			list.querySelectorAll('.atora-uib-item').forEach(function (item) {
				var id     = item.getAttribute('data-id');
				var toggle = item.querySelector('.atora-uib-toggle');
				var varSel = item.querySelector('.atora-uib-variant-sel');
				var spec   = specMap[id] || null;
				var on;

				if (hasSpec) {
					// Formato nuevo: activo solo si está en la spec
					on = spec ? (spec.enabled !== false) : false;
				} else {
					// Formato legado: sections_enabled vacío = todas activas
					on = (legacyRef.length === 0) || (legacyRef.indexOf(id) !== -1);
				}

				if (toggle) { toggle.checked = on; }
				item.classList.toggle('is-disabled', !on);

				if (varSel) {
					varSel.disabled = !on;
					// Aplicar variant del preset; si no especifica, resetear a 'default'
					varSel.value = (spec && spec.variant) ? spec.variant : 'default';
				}
			});

			// Aplicar tema del preset si el selector existe
			var themeSelect = document.getElementById('atora-uib-theme-' + ctx);
			if (themeSelect && preset.theme) {
				themeSelect.value = preset.theme;
			}

			syncPresetGallery(ctx, key);
			writeSchema(ctx);
		});
	});

	// ── Reset ─────────────────────────────────────────────────────────────────────

	document.querySelectorAll('.atora-uib-reset').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(t('¿Restablecer el diseño al valor por defecto del plugin?'))) {
				return;
			}

			var ctx   = btn.getAttribute('data-ctx');
			var input = getInput(ctx);
			// Input vacío → PHP borra el override en save_post
			if (input) { input.value = ''; }

			// Resetear UI: todas las secciones activas, variant=default
			var list = getList(ctx);
			if (list) {
				list.querySelectorAll('.atora-uib-item').forEach(function (item) {
					var toggle = item.querySelector('.atora-uib-toggle');
					var varSel = item.querySelector('.atora-uib-variant-sel');
					if (toggle) { toggle.checked = true; }
					if (varSel) { varSel.value = 'default'; varSel.disabled = false; }
					item.classList.remove('is-disabled');
				});
			}

			// Resetear tema y preset
			var themeSelect  = document.getElementById('atora-uib-theme-' + ctx);
			if (themeSelect)  { themeSelect.value  = 'light'; }
			syncPresetGallery(ctx, 'default');

			// Actualizar badge de estado
			var badge = document.querySelector('[data-badge-ctx="' + ctx + '"]');
			if (badge) {
				badge.textContent = t('Por defecto');
				badge.classList.add('is-default');
			}
		});
	});

	// ── Init ──────────────────────────────────────────────────────────────────────

	initSortables();
	window.addEventListener('load', initSortables);

})();
