/**
 * ATORA-LMS — Curriculum Builder
 *
 * Sortable drag-and-drop curriculum panel for the course editor.
 * Adds quick-edit inline + lesson presets workflows.
 *
 * Config injected via wp_localize_script as window.ATORA_CB.
 */
/* global Sortable, ATORA_CB */
(function () {
	'use strict';

	// ─── Config ────────────────────────────────────────────────────────────────

	const cfg = window.ATORA_CB || {};
	const REST = (cfg.restUrl || '').replace(/\/$/, '');
	const NONCE = cfg.nonce || '';
	const COURSE = parseInt(cfg.courseId, 10) || 0;
	const ADMIN = (cfg.adminUrl || '').replace(/\/$/, '');
	const I18N = cfg.i18n || {};
	const RUBRICS = Array.isArray(cfg.rubrics) ? cfg.rubrics : [];
	const EVAL_MODES = cfg.evaluationModes || {
		manual: 'Manual',
		ai_assisted: 'AI assisted',
		ai_auto_grade: 'AI auto grade',
		peer_review: 'Peer review',
		hybrid: 'Hybrid'
	};
	const ACTIVITY_MODES = cfg.activityModes || {
		lectura: 'Lectura',
		tarea: 'Tarea',
		quiz: 'Quiz',
		interactiva: 'Interactiva'
	};
	const DRIP_TYPES = cfg.dripTypes || {
		none: 'Sin restriccion',
		date: 'Fecha especifica',
		days_enrolled: 'Dias desde inscripcion',
		days_after_previous: 'Dias despues de la anterior'
	};
	const STATUS_OPTIONS = cfg.statusOptions || {
		draft: 'Borrador',
		publish: 'Publicado',
		private: 'Privado'
	};

	const NO_MODULE = '';

	// ─── State ─────────────────────────────────────────────────────────────────

	let modules = [];
	let presets = [];
	let saving = false;
	let pendingSave = false;
	const selectedLessonIds = new Set();

	// ─── DOM refs ──────────────────────────────────────────────────────────────

	let container;
	let moduleListEl;
	let statusEl;
	let addModuleBtn;
	let addLessonBtn;
	let globalForm;
	let presetSelectEl;
	let applyPresetBtn;
	let savePresetBtn;
	let deletePresetBtn;

	let moduleSortable = null;
	const lessonSortables = new Map();

	// ─── Init ──────────────────────────────────────────────────────────────────

	function init() {
		container = document.getElementById('atora-curriculum-builder');
		if (!container || !COURSE) {
			return;
		}

		container.innerHTML = '<div class="cb-loading">' + i18n('Cargando malla curricular…') + '</div>';

		Promise.all([fetchLessons(), fetchPresetsSafe()])
			.then(function (results) {
				const lessons = results[0];
				const presetItems = results[1];
				modules = buildModules(lessons);
				presets = presetItems;
				pruneSelectedLessonIds();
				render();
				initSortables();
			})
			.catch(function (err) {
				container.innerHTML =
					'<div class="cb-empty">' + i18n('No se pudo cargar la malla curricular.') +
					' <button class="cb-empty-cta" id="cb-retry-load">' + i18n('Reintentar') + '</button></div>';
				const retryBtn = document.getElementById('cb-retry-load');
				if (retryBtn) {
					retryBtn.addEventListener('click', init);
				}
				console.error('[ATORA-CB] init error:', err);
			});
	}

	// ─── Data fetching ─────────────────────────────────────────────────────────

	function fetchLessons() {
		return apiFetch(REST + '/clms/v1/lessons?course_id=' + COURSE + '&status=all&per_page=200', { method: 'GET' })
			.then(function (res) {
				if (Array.isArray(res)) {
					return res;
				}
				if (res && Array.isArray(res.items)) {
					return res.items;
				}
				return [];
			});
	}

	function fetchPresetsSafe() {
		return apiFetch(REST + '/clms/v1/lesson-presets', { method: 'GET' })
			.then(function (res) {
				if (res && Array.isArray(res.items)) {
					return res.items;
				}
				return [];
			})
			.catch(function () {
				return [];
			});
	}

	function fetchLessonDetail(lessonId) {
		return apiFetch(REST + '/clms/v1/lessons/' + parseInt(lessonId, 10), { method: 'GET' });
	}

	// ─── State helpers ─────────────────────────────────────────────────────────

	function buildModules(lessons) {
		const moduleMap = new Map();
		const moduleOrder = [];

		lessons.forEach(function (lesson) {
			const modName = (lesson.module !== undefined ? String(lesson.module) : '').trim();
			if (!moduleMap.has(modName)) {
				moduleMap.set(modName, []);
				moduleOrder.push(modName);
			}
			moduleMap.get(modName).push(lesson);
		});

		moduleMap.forEach(function (lessonList) {
			lessonList.sort(function (a, b) {
				return (parseInt(a.menu_order, 10) || 0) - (parseInt(b.menu_order, 10) || 0);
			});
		});

		const named = moduleOrder.filter(function (name) { return name !== NO_MODULE; });
		const hasUngrouped = moduleOrder.includes(NO_MODULE);
		const out = named.map(function (name) {
			return { name: name, lessons: moduleMap.get(name) || [] };
		});
		if (hasUngrouped) {
			out.push({ name: NO_MODULE, lessons: moduleMap.get(NO_MODULE) || [] });
		}
		return out;
	}

	function findLesson(id) {
		const lessonId = parseInt(id, 10) || 0;
		for (let i = 0; i < modules.length; i++) {
			const mod = modules[i];
			for (let j = 0; j < mod.lessons.length; j++) {
				if ((parseInt(mod.lessons[j].id, 10) || 0) === lessonId) {
					return mod.lessons[j];
				}
			}
		}
		return null;
	}

	function getAllLessonIds() {
		const ids = [];
		modules.forEach(function (mod) {
			mod.lessons.forEach(function (lesson) {
				ids.push(parseInt(lesson.id, 10) || 0);
			});
		});
		return ids.filter(Boolean);
	}

	function pruneSelectedLessonIds() {
		const valid = new Set(getAllLessonIds());
		Array.from(selectedLessonIds).forEach(function (id) {
			if (!valid.has(id)) {
				selectedLessonIds.delete(id);
			}
		});
	}

	function getFirstAvailableLessonId() {
		for (let i = 0; i < modules.length; i++) {
			if (modules[i].lessons.length > 0) {
				return parseInt(modules[i].lessons[0].id, 10) || 0;
			}
		}
		return 0;
	}

	function syncStateFromDOM() {
		const newModules = [];
		const moduleNodes = moduleListEl.querySelectorAll(':scope > .cb-module');

		moduleNodes.forEach(function (modNode) {
			const name = modNode.dataset.moduleName || '';
			const lessonNodes = modNode.querySelectorAll('.cb-lesson');
			const lessons = [];

			lessonNodes.forEach(function (lessonNode) {
				const lessonId = parseInt(lessonNode.dataset.lessonId, 10) || 0;
				const existing = findLesson(lessonId);
				if (existing) {
					lessons.push(Object.assign({}, existing, { module: name }));
				}
			});

			newModules.push({ name: name, lessons: lessons });
		});

		modules = newModules;
		pruneSelectedLessonIds();
	}

	// ─── Rendering ─────────────────────────────────────────────────────────────

	function render() {
		container.innerHTML = '';

		container.appendChild(renderPresetToolbar());

		moduleListEl = document.createElement('div');
		moduleListEl.className = 'cb-module-list';

		if (modules.length === 0) {
			renderEmptyState();
		} else {
			modules.forEach(function (mod) {
				moduleListEl.appendChild(renderModule(mod));
			});
		}
		container.appendChild(moduleListEl);

		const toolbar = document.createElement('div');
		toolbar.className = 'cb-toolbar';

		addModuleBtn = document.createElement('button');
		addModuleBtn.type = 'button';
		addModuleBtn.className = 'cb-btn';
		addModuleBtn.textContent = '+ ' + i18n('Agregar módulo');
		addModuleBtn.addEventListener('click', handleAddModule);

		addLessonBtn = document.createElement('button');
		addLessonBtn.type = 'button';
		addLessonBtn.className = 'cb-btn cb-btn--accent';
		addLessonBtn.textContent = '+ ' + i18n('Nueva lección');
		addLessonBtn.addEventListener('click', handleAddLesson);

		toolbar.appendChild(addModuleBtn);
		toolbar.appendChild(addLessonBtn);
		container.appendChild(toolbar);

		globalForm = buildGlobalAddForm();
		container.appendChild(globalForm);

		statusEl = document.createElement('div');
		statusEl.className = 'cb-status cb-status--hidden';
		statusEl.setAttribute('aria-live', 'polite');
		container.appendChild(statusEl);

		updatePresetControls();
	}

	function renderPresetToolbar() {
		const toolbar = document.createElement('div');
		toolbar.className = 'cb-toolbar cb-toolbar--presets';

		presetSelectEl = document.createElement('select');
		presetSelectEl.className = 'cb-preset-select';
		presetSelectEl.setAttribute('aria-label', i18n('Aplicar plantilla...'));
		renderPresetOptions();
		presetSelectEl.addEventListener('change', updatePresetControls);

		applyPresetBtn = document.createElement('button');
		applyPresetBtn.type = 'button';
		applyPresetBtn.className = 'cb-btn';
		applyPresetBtn.addEventListener('click', applySelectedPreset);

		savePresetBtn = document.createElement('button');
		savePresetBtn.type = 'button';
		savePresetBtn.className = 'cb-btn';
		savePresetBtn.textContent = i18n('Guardar como plantilla...');
		savePresetBtn.addEventListener('click', saveCurrentAsPreset);

		deletePresetBtn = document.createElement('button');
		deletePresetBtn.type = 'button';
		deletePresetBtn.className = 'cb-btn cb-btn--danger';
		deletePresetBtn.textContent = i18n('Eliminar plantilla');
		deletePresetBtn.addEventListener('click', deleteSelectedPreset);

		toolbar.appendChild(presetSelectEl);
		toolbar.appendChild(applyPresetBtn);
		toolbar.appendChild(savePresetBtn);
		toolbar.appendChild(deletePresetBtn);

		return toolbar;
	}

	function renderPresetOptions() {
		if (!presetSelectEl) {
			return;
		}
		const current = presetSelectEl.value;
		presetSelectEl.innerHTML = '';

		const empty = document.createElement('option');
		empty.value = '';
		empty.textContent = i18n('Aplicar plantilla...');
		presetSelectEl.appendChild(empty);

		presets.forEach(function (preset) {
			const option = document.createElement('option');
			option.value = String(preset.id || '');
			option.textContent = String(preset.name || preset.id || '');
			presetSelectEl.appendChild(option);
		});

		if (current) {
			presetSelectEl.value = current;
		}
	}

	function updatePresetControls() {
		if (!applyPresetBtn || !presetSelectEl || !deletePresetBtn) {
			return;
		}
		const selectedCount = selectedLessonIds.size;
		applyPresetBtn.textContent = i18n('Aplicar a seleccionadas (%d)').replace('%d', String(selectedCount));
		applyPresetBtn.disabled = !presetSelectEl.value || selectedCount === 0;
		deletePresetBtn.disabled = !presetSelectEl.value;
	}

	function renderEmptyState() {
		const empty = document.createElement('div');
		empty.className = 'cb-empty';
		empty.innerHTML =
			i18n('Este curso todavía no tiene lecciones.') + '<br>' +
			'<button class="cb-empty-cta" id="cb-empty-add">+ ' + i18n('Crear primera lección') + '</button>';
		moduleListEl.appendChild(empty);

		const addBtn = document.getElementById('cb-empty-add');
		if (addBtn) {
			addBtn.addEventListener('click', handleAddLesson);
		}
	}

	function renderModule(mod) {
		const isUngrouped = mod.name === NO_MODULE;
		const modEl = document.createElement('div');
		modEl.className = 'cb-module';
		modEl.dataset.moduleName = mod.name;

		const header = document.createElement('div');
		header.className = 'cb-module-header';

		const dragHandle = document.createElement('span');
		dragHandle.className = 'cb-module-drag-handle';
		dragHandle.setAttribute('title', i18n('Arrastrar para reordenar módulo'));
		dragHandle.innerHTML = '&#8942;&#8942;';

		const nameEl = document.createElement('span');
		nameEl.className = 'cb-module-name';
		nameEl.textContent = isUngrouped ? i18n('Sin módulo') : mod.name;

		if (!isUngrouped) {
			nameEl.setAttribute('title', i18n('Clic para renombrar'));
			nameEl.addEventListener('click', function () {
				startRenameModule(nameEl, modEl, mod);
			});
		}

		const hint = document.createElement('span');
		hint.className = 'cb-module-name-hint';
		hint.textContent = mod.lessons.length + ' ' + (mod.lessons.length === 1 ? i18n('lección') : i18n('lecciones'));

		header.appendChild(dragHandle);
		header.appendChild(nameEl);
		header.appendChild(hint);
		modEl.appendChild(header);

		const lessonList = document.createElement('ul');
		lessonList.className = 'cb-lesson-list';
		lessonList.dataset.moduleName = mod.name;

		if (mod.lessons.length === 0) {
			const hintEl = document.createElement('li');
			hintEl.className = 'cb-lesson-list-empty-hint';
			hintEl.textContent = i18n('Arrastra lecciones aquí.');
			lessonList.appendChild(hintEl);
		} else {
			mod.lessons.forEach(function (lesson, idx) {
				lessonList.appendChild(renderLesson(lesson, idx + 1));
			});
		}

		modEl.appendChild(lessonList);

		const addBtn = document.createElement('button');
		addBtn.type = 'button';
		addBtn.className = 'cb-btn cb-btn--inline-add';
		addBtn.textContent = '+ ' + i18n('Lección en este módulo');
		addBtn.addEventListener('click', function () {
			openGlobalForm(mod.name);
		});
		modEl.appendChild(addBtn);

		return modEl;
	}

	function renderLesson(lesson, order) {
		const lessonId = parseInt(lesson.id, 10) || 0;
		const li = document.createElement('li');
		li.className = 'cb-lesson';
		li.dataset.lessonId = String(lessonId);

		const main = document.createElement('div');
		main.className = 'cb-lesson-main';

		const selector = document.createElement('input');
		selector.type = 'checkbox';
		selector.className = 'cb-lesson-select';
		selector.value = String(lessonId);
		selector.checked = selectedLessonIds.has(lessonId);
		selector.addEventListener('change', function () {
			if (selector.checked) {
				selectedLessonIds.add(lessonId);
			} else {
				selectedLessonIds.delete(lessonId);
			}
			updatePresetControls();
		});

		const handle = document.createElement('span');
		handle.className = 'cb-drag-handle';
		handle.setAttribute('title', i18n('Arrastrar para reordenar'));
		handle.innerHTML = '&#9776;';

		const orderBadge = document.createElement('span');
		orderBadge.className = 'cb-lesson-order';
		orderBadge.textContent = String(order);

		const title = document.createElement('span');
		title.className = 'cb-lesson-title';
		title.textContent = lesson.title || i18n('(Sin título)');
		title.setAttribute('title', lesson.title || '');

		const status = lesson.status || 'draft';
		const badge = document.createElement('span');
		badge.className = 'cb-badge cb-badge--' + status;
		badge.textContent = statusLabel(status);

		const quickBtn = document.createElement('button');
		quickBtn.type = 'button';
		quickBtn.className = 'cb-qe-toggle';
		quickBtn.setAttribute('aria-expanded', 'false');
		quickBtn.setAttribute('title', i18n('Quick-edit'));
		quickBtn.textContent = '▸';

		const editLink = document.createElement('a');
		editLink.className = 'cb-edit-link';
		editLink.href = ADMIN + '/post.php?post=' + lessonId + '&action=edit';
		editLink.target = '_blank';
		editLink.rel = 'noopener';
		editLink.textContent = i18n('Editar') + ' ↗';

		main.appendChild(selector);
		main.appendChild(handle);
		main.appendChild(orderBadge);
		main.appendChild(title);
		main.appendChild(badge);
		main.appendChild(quickBtn);
		main.appendChild(editLink);

		const panel = document.createElement('div');
		panel.className = 'cb-qe-panel';
		panel.hidden = true;

		quickBtn.addEventListener('click', function () {
			toggleQuickEditPanel(li, panel, quickBtn, lesson);
		});

		li.appendChild(main);
		li.appendChild(panel);
		return li;
	}

	// ─── Quick edit ────────────────────────────────────────────────────────────

	function toggleQuickEditPanel(row, panel, button, lesson) {
		if (!panel || !button) {
			return;
		}

		const isHidden = panel.hidden;
		if (!isHidden) {
			panel.hidden = true;
			button.textContent = '▸';
			button.setAttribute('aria-expanded', 'false');
			return;
		}

		panel.hidden = false;
		button.textContent = '▾';
		button.setAttribute('aria-expanded', 'true');

		if (panel.dataset.loaded === '1') {
			return;
		}

		panel.innerHTML = '<div class="cb-qe-loading">' + i18n('Cargando ajuste rápido…') + '</div>';
		fetchLessonDetail(lesson.id)
			.then(function (detail) {
				panel.dataset.loaded = '1';
				row.dataset.quickLoaded = '1';
				renderQuickEditContent(row, panel, lesson, detail || {});
			})
			.catch(function (err) {
				console.error('[ATORA-CB] quick edit load error:', err);
				panel.dataset.loaded = '';
				panel.innerHTML = '<div class="cb-qe-error">' + i18n('No se pudo cargar el formulario rápido.') + '</div>';
			});
	}

	function renderQuickEditContent(row, panel, lesson, detail) {
		const evalMode = detail.evaluation_mode || 'manual';
		const rubricId = parseInt(detail.rubric_id, 10) || 0;
		const activityMode = detail.activity_mode || detail.activity_type || 'lectura';
		const dripType = detail.drip_type || 'none';
		const peerReview = !!detail.peer_review_enabled;
		const status = detail.status || lesson.status || 'draft';
		const dripValue = resolveDripValue(detail, dripType);

		const html = [
			'<div class="cb-qe-grid">',
			quickFieldSelectHtml('evaluation_mode', i18n('Evaluación'), EVAL_MODES, evalMode, false, ''),
			quickFieldSelectHtml('rubric_id', i18n('Rúbrica'), rubricOptionsAsMap(), String(rubricId), true, i18n('Sin rúbrica')),
			quickFieldSelectHtml('activity_mode', i18n('Actividad'), ACTIVITY_MODES, activityMode, false, ''),
			quickFieldSelectHtml('drip_type', i18n('Drip'), DRIP_TYPES, dripType, false, ''),
			'<div class="cb-qe-field cb-qe-field--drip-value"></div>',
			quickFieldSelectHtml('post_status', i18n('Estado'), STATUS_OPTIONS, status, false, ''),
			'<label class="cb-qe-check"><span>' + escapeHtml(i18n('Peer review')) + '</span><input type="checkbox" name="peer_review_enabled" ' + (peerReview ? 'checked' : '') + '></label>',
			'</div>',
			'<div class="cb-qe-actions">',
			'<button type="button" class="cb-btn cb-btn--accent cb-qe-save">' + i18n('Guardar ✓') + '</button>',
			'</div>',
			'<div class="cb-qe-message" aria-live="polite"></div>'
		].join('');

		panel.innerHTML = html;
		syncDripValueInput(panel, dripType, dripValue);

		const dripTypeField = panel.querySelector('select[name="drip_type"]');
		if (dripTypeField) {
			dripTypeField.addEventListener('change', function () {
				syncDripValueInput(panel, dripTypeField.value, '');
			});
		}

		const saveBtn = panel.querySelector('.cb-qe-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				saveQuickEdit(row, panel, lesson, saveBtn);
			});
		}
	}

	function saveQuickEdit(row, panel, lesson, saveBtn) {
		if (!panel || !lesson) {
			return;
		}

		const payload = collectQuickEditPayload(panel);
		const msgEl = panel.querySelector('.cb-qe-message');

		if (msgEl) {
			msgEl.textContent = i18n('Guardando cambios…');
			msgEl.className = 'cb-qe-message';
		}
		if (saveBtn) {
			saveBtn.disabled = true;
		}

		apiFetch(REST + '/clms/v1/lessons/' + parseInt(lesson.id, 10) + '/quick-edit', {
			method: 'PATCH',
			body: JSON.stringify(payload)
		})
			.then(function (res) {
				const updated = res && res.lesson ? res.lesson : null;
				if (updated) {
					lesson.status = updated.status || lesson.status;
					lesson.module = updated.module !== undefined ? String(updated.module) : lesson.module;
					lesson.activity_mode = updated.activity_mode || lesson.activity_mode;
					lesson.evaluation_mode = updated.evaluation_mode || lesson.evaluation_mode;
					lesson.rubric_id = parseInt(updated.rubric_id, 10) || 0;
				}

				updateLessonStatusBadge(row, lesson.status || 'draft');
				panel.classList.add('cb-qe-saved');
				setTimeout(function () {
					panel.classList.remove('cb-qe-saved');
				}, 1400);

				if (msgEl) {
					msgEl.textContent = i18n('Cambios guardados ✓');
					msgEl.className = 'cb-qe-message cb-qe-message--ok';
				}
			})
			.catch(function (err) {
				console.error('[ATORA-CB] quick edit save error:', err);
				if (msgEl) {
					msgEl.textContent = err && err.message ? err.message : i18n('Error guardando cambios.');
					msgEl.className = 'cb-qe-message cb-qe-message--error';
				}
			})
			.finally(function () {
				if (saveBtn) {
					saveBtn.disabled = false;
				}
			});
	}

	function collectQuickEditPayload(panel) {
		const payload = {};
		const evalField = panel.querySelector('select[name="evaluation_mode"]');
		const rubricField = panel.querySelector('select[name="rubric_id"]');
		const activityField = panel.querySelector('select[name="activity_mode"]');
		const dripField = panel.querySelector('select[name="drip_type"]');
		const peerField = panel.querySelector('input[name="peer_review_enabled"]');
		const statusField = panel.querySelector('select[name="post_status"]');
		const dripValueField = panel.querySelector('[name="drip_value"]');
		const dripDateField = panel.querySelector('[name="drip_date"]');

		if (evalField) {
			payload.evaluation_mode = evalField.value;
		}
		if (rubricField) {
			payload.rubric_id = parseInt(rubricField.value, 10) || 0;
		}
		if (activityField) {
			payload.activity_mode = activityField.value;
		}
		if (peerField) {
			payload.peer_review_enabled = !!peerField.checked;
		}
		if (statusField) {
			payload.post_status = statusField.value;
		}
		if (dripField) {
			payload.drip_type = dripField.value;
			if (dripField.value === 'date') {
				payload.drip_date = dripDateField ? dripDateField.value : '';
			} else if (dripField.value === 'days_enrolled' || dripField.value === 'days_after_previous') {
				payload.drip_value = dripValueField ? (parseInt(dripValueField.value, 10) || 0) : 0;
			}
		}

		return payload;
	}

	function syncDripValueInput(panel, dripType, currentValue) {
		const fieldWrap = panel.querySelector('.cb-qe-field--drip-value');
		if (!fieldWrap) {
			return;
		}

		if (!dripType || dripType === 'none') {
			fieldWrap.innerHTML = '';
			fieldWrap.classList.add('is-hidden');
			return;
		}

		fieldWrap.classList.remove('is-hidden');

		if (dripType === 'date') {
			fieldWrap.innerHTML =
				'<label><span>' + escapeHtml(i18n('Fecha de desbloqueo')) + '</span>' +
				'<input type="date" name="drip_date" value="' + escapeAttr(String(currentValue || '')) + '">' +
				'</label>';
			return;
		}

		fieldWrap.innerHTML =
			'<label><span>' + escapeHtml(i18n('Días de espera')) + '</span>' +
			'<input type="number" min="0" step="1" name="drip_value" value="' + escapeAttr(String(currentValue || 0)) + '">' +
			'</label>';
	}

	function quickFieldSelectHtml(name, label, optionsMap, selectedValue, includeEmpty, emptyLabel) {
		let optionsHtml = '';
		if (includeEmpty) {
			optionsHtml += '<option value="">' + escapeHtml(emptyLabel || '') + '</option>';
		}
		Object.keys(optionsMap || {}).forEach(function (key) {
			const value = String(key);
			const text = String(optionsMap[key]);
			const selected = String(selectedValue) === value ? ' selected' : '';
			optionsHtml += '<option value="' + escapeAttr(value) + '"' + selected + '>' + escapeHtml(text) + '</option>';
		});
		return (
			'<label class="cb-qe-field">' +
			'<span>' + escapeHtml(label) + '</span>' +
			'<select name="' + escapeAttr(name) + '">' + optionsHtml + '</select>' +
			'</label>'
		);
	}

	function rubricOptionsAsMap() {
		const out = {};
		RUBRICS.forEach(function (item) {
			const id = parseInt(item.id, 10) || 0;
			if (id) {
				out[String(id)] = String(item.title || ('#' + id));
			}
		});
		return out;
	}

	function resolveDripValue(detail, dripType) {
		if (dripType === 'date') {
			return detail && detail.drip_date ? detail.drip_date : '';
		}
		if (dripType === 'days_enrolled') {
			return parseInt(detail && detail.drip_days, 10) || 0;
		}
		if (dripType === 'days_after_previous') {
			return parseInt(detail && detail.drip_after_prev, 10) || 0;
		}
		return '';
	}

	function updateLessonStatusBadge(row, status) {
		if (!row) {
			return;
		}
		const badge = row.querySelector('.cb-badge');
		if (!badge) {
			return;
		}
		badge.className = 'cb-badge cb-badge--' + status;
		badge.textContent = statusLabel(status);
	}

	// ─── Presets ───────────────────────────────────────────────────────────────

	function applySelectedPreset() {
		const presetId = presetSelectEl ? String(presetSelectEl.value || '') : '';
		const lessonIds = Array.from(selectedLessonIds);

		if (!presetId) {
			alert(i18n('Debes seleccionar una plantilla.'));
			return;
		}
		if (!lessonIds.length) {
			alert(i18n('Debes seleccionar al menos una lección.'));
			return;
		}

		const confirmMsg = i18n('¿Aplicar la plantilla seleccionada a %d lecciones? Esto sobrescribirá su configuración actual.')
			.replace('%d', String(lessonIds.length));
		if (!window.confirm(confirmMsg)) {
			return;
		}

		showStatus('saving', i18n('Guardando…'));

		apiFetch(REST + '/clms/v1/lessons/apply-preset', {
			method: 'POST',
			body: JSON.stringify({
				preset_id: presetId,
				course_id: COURSE,
				lesson_ids: lessonIds
			})
		})
			.then(function (res) {
				const preset = presets.find(function (item) {
					return String(item.id) === presetId;
				});
				if (preset && preset.config) {
					lessonIds.forEach(function (lessonId) {
						applyPresetConfigToLessonState(lessonId, preset.config);
					});
				}

				lessonIds.forEach(function (lessonId) {
					const row = container.querySelector('.cb-lesson[data-lesson-id="' + lessonId + '"]');
					if (!row) {
						return;
					}
					const panel = row.querySelector('.cb-qe-panel');
					if (panel) {
						panel.dataset.loaded = '';
					}
				});

				showStatus('ok', (res && res.message) ? res.message : i18n('Plantilla aplicada ✓'));
				setTimeout(hideStatus, 2200);
			})
			.catch(function (err) {
				console.error('[ATORA-CB] apply preset error:', err);
				showStatus('error', err && err.message ? err.message : i18n('Error al aplicar la plantilla.'), true);
			});
	}

	function saveCurrentAsPreset() {
		let lessonId = Array.from(selectedLessonIds)[0];
		if (!lessonId) {
			lessonId = getFirstAvailableLessonId();
		}
		if (!lessonId) {
			alert(i18n('Debes seleccionar al menos una lección.'));
			return;
		}

		const name = window.prompt(i18n('Nombre de la plantilla'));
		if (!name || !name.trim()) {
			return;
		}

		showStatus('saving', i18n('Guardando…'));

		fetchLessonDetail(lessonId)
			.then(function (detail) {
				const config = buildPresetConfigFromLesson(detail || {});
				return apiFetch(REST + '/clms/v1/lesson-presets', {
					method: 'POST',
					body: JSON.stringify({
						name: name.trim(),
						config: config
					})
				});
			})
			.then(function (res) {
				if (res && res.item) {
					presets.push(res.item);
					renderPresetOptions();
					if (presetSelectEl) {
						presetSelectEl.value = String(res.item.id || '');
					}
					updatePresetControls();
				}
				showStatus('ok', (res && res.message) ? res.message : i18n('Plantilla guardada ✓'));
				setTimeout(hideStatus, 2200);
			})
			.catch(function (err) {
				console.error('[ATORA-CB] save preset error:', err);
				showStatus('error', err && err.message ? err.message : i18n('Error al guardar la plantilla.'), true);
			});
	}

	function deleteSelectedPreset() {
		const presetId = presetSelectEl ? String(presetSelectEl.value || '') : '';
		if (!presetId) {
			return;
		}
		if (!window.confirm(i18n('¿Eliminar esta plantilla?'))) {
			return;
		}

		showStatus('saving', i18n('Guardando…'));

		apiFetch(REST + '/clms/v1/lesson-presets/' + encodeURIComponent(presetId), {
			method: 'DELETE'
		})
			.then(function (res) {
				presets = presets.filter(function (item) {
					return String(item.id || '') !== presetId;
				});
				renderPresetOptions();
				if (presetSelectEl) {
					presetSelectEl.value = '';
				}
				updatePresetControls();
				showStatus('ok', (res && res.message) ? res.message : i18n('Plantilla eliminada.'));
				setTimeout(hideStatus, 2000);
			})
			.catch(function (err) {
				console.error('[ATORA-CB] delete preset error:', err);
				showStatus('error', err && err.message ? err.message : i18n('Error al eliminar la plantilla.'), true);
			});
	}

	function buildPresetConfigFromLesson(lesson) {
		const config = {
			evaluation_mode: lesson.evaluation_mode || 'manual',
			activity_mode: lesson.activity_mode || lesson.activity_type || 'lectura',
			rubric_id: parseInt(lesson.rubric_id, 10) || 0,
			peer_review_enabled: !!lesson.peer_review_enabled,
			drip_type: lesson.drip_type || 'none'
		};

		const threshold = parseFloat(lesson.ai_confidence_threshold);
		if (!Number.isNaN(threshold) && threshold > 0) {
			config.ai_confidence_threshold = threshold;
		}

		if (config.drip_type === 'date') {
			config.drip_date = lesson.drip_date || '';
		} else if (config.drip_type === 'days_enrolled') {
			config.drip_value = parseInt(lesson.drip_days, 10) || 0;
		} else if (config.drip_type === 'days_after_previous') {
			config.drip_value = parseInt(lesson.drip_after_prev, 10) || 0;
		}

		return config;
	}

	function applyPresetConfigToLessonState(lessonId, config) {
		const lesson = findLesson(lessonId);
		if (!lesson || !config) {
			return;
		}
		if (config.evaluation_mode !== undefined) {
			lesson.evaluation_mode = config.evaluation_mode;
		}
		if (config.activity_mode !== undefined) {
			lesson.activity_mode = config.activity_mode;
		}
		if (config.rubric_id !== undefined) {
			lesson.rubric_id = parseInt(config.rubric_id, 10) || 0;
		}
		if (config.drip_type !== undefined) {
			lesson.drip_type = config.drip_type;
		}
		if (config.peer_review_enabled !== undefined) {
			lesson.peer_review_enabled = !!config.peer_review_enabled;
		}
	}

	// ─── SortableJS ────────────────────────────────────────────────────────────

	function initSortables() {
		if (typeof Sortable === 'undefined' || !moduleListEl) {
			return;
		}

		if (moduleSortable) {
			moduleSortable.destroy();
			moduleSortable = null;
		}
		lessonSortables.forEach(function (sortable) {
			sortable.destroy();
		});
		lessonSortables.clear();

		moduleSortable = Sortable.create(moduleListEl, {
			handle: '.cb-module-drag-handle',
			animation: 150,
			ghostClass: 'cb-module--ghost',
			chosenClass: 'cb-module--chosen',
			onEnd: function () {
				syncStateFromDOM();
				scheduleAutoSave();
			}
		});

		const lessonLists = moduleListEl.querySelectorAll('.cb-lesson-list');
		lessonLists.forEach(function (listEl) {
			const sortable = Sortable.create(listEl, {
				group: {
					name: 'lessons',
					pull: true,
					put: true
				},
				handle: '.cb-drag-handle',
				animation: 150,
				ghostClass: 'cb-lesson--ghost',
				chosenClass: 'cb-lesson--chosen',
				dragClass: 'cb-lesson--drag',
				onAdd: function () {
					refreshEmptyHints();
				},
				onEnd: function () {
					refreshLessonOrders();
					syncStateFromDOM();
					scheduleAutoSave();
				}
			});
			lessonSortables.set(listEl, sortable);
		});
	}

	function refreshLessonOrders() {
		if (!moduleListEl) {
			return;
		}
		const moduleNodes = moduleListEl.querySelectorAll(':scope > .cb-module');
		let globalOrder = 1;
		moduleNodes.forEach(function (modNode) {
			const lessonNodes = modNode.querySelectorAll('.cb-lesson');
			lessonNodes.forEach(function (lessonNode) {
				const orderEl = lessonNode.querySelector('.cb-lesson-order');
				if (orderEl) {
					orderEl.textContent = String(globalOrder);
				}
				globalOrder++;
			});
		});
	}

	function refreshEmptyHints() {
		if (!moduleListEl) {
			return;
		}
		const moduleNodes = moduleListEl.querySelectorAll(':scope > .cb-module');
		moduleNodes.forEach(function (modNode) {
			const listEl = modNode.querySelector('.cb-lesson-list');
			if (!listEl) {
				return;
			}
			const hasLessons = listEl.querySelectorAll('.cb-lesson').length > 0;
			let hintEl = listEl.querySelector('.cb-lesson-list-empty-hint');

			if (hasLessons && hintEl) {
				hintEl.remove();
			} else if (!hasLessons && !hintEl) {
				hintEl = document.createElement('li');
				hintEl.className = 'cb-lesson-list-empty-hint';
				hintEl.textContent = i18n('Arrastra lecciones aquí.');
				listEl.appendChild(hintEl);
			}

			const countEl = modNode.querySelector('.cb-module-name-hint');
			if (countEl) {
				const count = listEl.querySelectorAll('.cb-lesson').length;
				countEl.textContent = count + ' ' + (count === 1 ? i18n('lección') : i18n('lecciones'));
			}
		});
	}

	// ─── Auto-save ─────────────────────────────────────────────────────────────

	let saveTimer = null;

	function scheduleAutoSave() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(performSave, 300);
	}

	async function performSave() {
		if (saving) {
			pendingSave = true;
			return;
		}

		saving = true;
		pendingSave = false;
		showStatus('saving', i18n('Guardando…'));

		const payload = buildSavePayload();

		try {
			await apiFetch(REST + '/clms/v1/lessons/reorder', {
				method: 'POST',
				body: JSON.stringify(payload)
			});
			showStatus('ok', i18n('Guardado ✓'));
			setTimeout(hideStatus, 2500);
		} catch (err) {
			console.error('[ATORA-CB] save error:', err);
			showStatus('error', i18n('Error al guardar.'), true);
		} finally {
			saving = false;
			if (pendingSave) {
				scheduleAutoSave();
			}
		}
	}

	function buildSavePayload() {
		const items = [];
		let order = 0;
		const modEls = moduleListEl ? moduleListEl.querySelectorAll(':scope > .cb-module') : [];
		modEls.forEach(function (modEl) {
			const modName = modEl.dataset.moduleName || '';
			const lessonEls = modEl.querySelectorAll('.cb-lesson');
			lessonEls.forEach(function (lessonEl) {
				items.push({
					id: parseInt(lessonEl.dataset.lessonId, 10) || 0,
					menu_order: order++,
					module: modName
				});
			});
		});
		return { course_id: COURSE, items: items };
	}

	// ─── Module actions ────────────────────────────────────────────────────────

	function handleAddModule() {
		const name = window.prompt(i18n('Nombre del nuevo módulo:'));
		if (name === null) {
			return;
		}
		const trimmed = name.trim();
		if (!trimmed) {
			return;
		}

		const exists = modules.find(function (mod) {
			return mod.name === trimmed;
		});
		if (exists) {
			alert(i18n('Ya existe un módulo con ese nombre.'));
			return;
		}

		const newMod = { name: trimmed, lessons: [] };
		const ungroupedIdx = modules.findIndex(function (m) { return m.name === NO_MODULE; });
		if (ungroupedIdx === -1) {
			modules.push(newMod);
		} else {
			modules.splice(ungroupedIdx, 0, newMod);
		}

		const modEl = renderModule(newMod);
		const ungroupedEl = moduleListEl.querySelector('.cb-module[data-module-name=""]');
		if (ungroupedEl) {
			moduleListEl.insertBefore(modEl, ungroupedEl);
		} else {
			moduleListEl.appendChild(modEl);
		}

		initSortables();

		const nameEl = modEl.querySelector('.cb-module-name');
		if (nameEl) {
			startRenameModule(nameEl, modEl, newMod);
		}
	}

	function startRenameModule(nameEl, modEl, mod) {
		if (!nameEl || nameEl.contentEditable === 'true') {
			return;
		}

		const originalName = mod.name;
		nameEl.contentEditable = 'true';
		nameEl.focus();

		const range = document.createRange();
		range.selectNodeContents(nameEl);
		const sel = window.getSelection();
		if (sel) {
			sel.removeAllRanges();
			sel.addRange(range);
		}

		function commit() {
			const newName = nameEl.textContent ? nameEl.textContent.trim() : '';
			nameEl.contentEditable = 'false';

			if (!newName || newName === originalName) {
				nameEl.textContent = originalName || i18n('Sin módulo');
				return;
			}

			const dup = modules.find(function (m) {
				return m !== mod && m.name === newName;
			});
			if (dup) {
				alert(i18n('Ya existe un módulo con ese nombre.'));
				nameEl.textContent = originalName;
				return;
			}

			modEl.dataset.moduleName = newName;
			const lessonList = modEl.querySelector('.cb-lesson-list');
			if (lessonList) {
				lessonList.dataset.moduleName = newName;
			}
			mod.name = newName;
			mod.lessons.forEach(function (lesson) {
				lesson.module = newName;
			});

			scheduleAutoSave();
		}

		function cancel() {
			nameEl.contentEditable = 'false';
			nameEl.textContent = originalName || i18n('Sin módulo');
		}

		function onKeydown(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				nameEl.removeEventListener('keydown', onKeydown);
				commit();
			} else if (e.key === 'Escape') {
				nameEl.removeEventListener('keydown', onKeydown);
				cancel();
			}
		}

		function onBlur() {
			nameEl.removeEventListener('blur', onBlur);
			commit();
		}

		nameEl.addEventListener('keydown', onKeydown);
		nameEl.addEventListener('blur', onBlur);
	}

	// ─── Lesson add form ───────────────────────────────────────────────────────

	function handleAddLesson() {
		openGlobalForm(NO_MODULE);
	}

	function buildGlobalAddForm() {
		const form = document.createElement('div');
		form.className = 'cb-global-add-form';

		const titleLabel = document.createElement('label');
		titleLabel.textContent = i18n('Título de la lección');
		const titleInput = document.createElement('input');
		titleInput.type = 'text';
		titleInput.placeholder = i18n('Ej. Introducción al tema');
		titleInput.id = 'cb-new-lesson-title';

		const moduleLabel = document.createElement('label');
		moduleLabel.textContent = i18n('Módulo');
		const moduleSelect = document.createElement('select');
		moduleSelect.id = 'cb-new-lesson-module';

		const row = document.createElement('div');
		row.className = 'cb-form-row';

		const titleWrap = document.createElement('div');
		titleWrap.appendChild(titleLabel);
		titleWrap.appendChild(titleInput);

		const moduleWrap = document.createElement('div');
		moduleWrap.appendChild(moduleLabel);
		moduleWrap.appendChild(moduleSelect);

		row.appendChild(titleWrap);
		row.appendChild(moduleWrap);
		form.appendChild(row);

		const actions = document.createElement('div');
		actions.className = 'cb-form-actions';

		const cancelBtn = document.createElement('button');
		cancelBtn.type = 'button';
		cancelBtn.className = 'cb-add-lesson-cancel';
		cancelBtn.textContent = i18n('Cancelar');
		cancelBtn.addEventListener('click', function () {
			form.classList.remove('cb-open');
		});

		const submitBtn = document.createElement('button');
		submitBtn.type = 'button';
		submitBtn.className = 'cb-add-lesson-submit';
		submitBtn.textContent = i18n('Crear lección');
		submitBtn.addEventListener('click', function () {
			submitNewLesson(titleInput, moduleSelect, form, submitBtn);
		});

		titleInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				submitNewLesson(titleInput, moduleSelect, form, submitBtn);
			} else if (e.key === 'Escape') {
				form.classList.remove('cb-open');
			}
		});

		actions.appendChild(cancelBtn);
		actions.appendChild(submitBtn);
		form.appendChild(actions);
		return form;
	}

	function openGlobalForm(preselectedModule) {
		if (!globalForm) {
			return;
		}
		const moduleSelect = globalForm.querySelector('#cb-new-lesson-module');
		if (!moduleSelect) {
			return;
		}

		moduleSelect.innerHTML = '';

		const noModule = document.createElement('option');
		noModule.value = NO_MODULE;
		noModule.textContent = i18n('Sin módulo');
		moduleSelect.appendChild(noModule);

		modules.forEach(function (mod) {
			if (mod.name === NO_MODULE) {
				return;
			}
			const option = document.createElement('option');
			option.value = mod.name;
			option.textContent = mod.name;
			moduleSelect.appendChild(option);
		});

		moduleSelect.value = preselectedModule;
		globalForm.classList.add('cb-open');

		const titleInput = globalForm.querySelector('#cb-new-lesson-title');
		if (titleInput) {
			titleInput.value = '';
			titleInput.focus();
		}
	}

	async function submitNewLesson(titleInput, moduleSelect, form, submitBtn) {
		const title = titleInput && titleInput.value ? titleInput.value.trim() : '';
		const module = moduleSelect ? moduleSelect.value : NO_MODULE;
		if (!title) {
			if (titleInput) {
				titleInput.focus();
			}
			return;
		}

		submitBtn.disabled = true;
		submitBtn.textContent = i18n('Creando…');
		showStatus('saving', i18n('Creando lección…'));

		let maxOrder = -1;
		modules.forEach(function (mod) {
			mod.lessons.forEach(function (lesson) {
				const ord = parseInt(lesson.menu_order, 10) || 0;
				if (ord > maxOrder) {
					maxOrder = ord;
				}
			});
		});
		const newOrder = maxOrder + 1;

		try {
			const created = await apiFetch(REST + '/clms/v1/lessons', {
				method: 'POST',
				body: JSON.stringify({
					title: title,
					course_id: COURSE,
					module: module,
					menu_order: newOrder,
					status: 'draft'
				})
			});

			const newLesson = {
				id: created.id,
				title: created.title || title,
				status: created.status || 'draft',
				menu_order: newOrder,
				module: module
			};

			let targetMod = modules.find(function (m) {
				return m.name === module;
			});
			if (!targetMod) {
				targetMod = { name: module, lessons: [] };
				const ungroupedIdx = modules.findIndex(function (m) { return m.name === NO_MODULE; });
				if (ungroupedIdx === -1) {
					modules.push(targetMod);
				} else {
					modules.splice(ungroupedIdx, 0, targetMod);
				}
			}
			targetMod.lessons.push(newLesson);

			const targetModEl = moduleListEl.querySelector('.cb-module[data-module-name="' + escAttr(module) + '"]');
			if (targetModEl) {
				const listEl = targetModEl.querySelector('.cb-lesson-list');
				if (listEl) {
					const hint = listEl.querySelector('.cb-lesson-list-empty-hint');
					if (hint) {
						hint.remove();
					}
					const currentCount = listEl.querySelectorAll('.cb-lesson').length;
					listEl.appendChild(renderLesson(newLesson, currentCount + 1));
				}
			} else {
				render();
			}

			refreshLessonOrders();
			refreshEmptyHints();
			initSortables();

			form.classList.remove('cb-open');
			titleInput.value = '';
			showStatus('ok', i18n('Lección creada ✓'));
			setTimeout(hideStatus, 2200);
		} catch (err) {
			console.error('[ATORA-CB] create lesson error:', err);
			showStatus('error', i18n('Error al crear la lección.'), true);
		} finally {
			submitBtn.disabled = false;
			submitBtn.textContent = i18n('Crear lección');
		}
	}

	// ─── Status bar ────────────────────────────────────────────────────────────

	function showStatus(type, message, showRetry) {
		if (!statusEl) {
			return;
		}
		statusEl.innerHTML = '';
		statusEl.className = 'cb-status cb-status--' + type;
		statusEl.appendChild(document.createTextNode(message));

		if (showRetry) {
			const retry = document.createElement('button');
			retry.type = 'button';
			retry.className = 'cb-retry-btn';
			retry.textContent = i18n('Reintentar');
			retry.addEventListener('click', scheduleAutoSave);
			statusEl.appendChild(retry);
		}
	}

	function hideStatus() {
		if (!statusEl) {
			return;
		}
		statusEl.className = 'cb-status cb-status--hidden';
	}

	// ─── Utilities ─────────────────────────────────────────────────────────────

	function apiFetch(url, options) {
		const opts = Object.assign({
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE
			}
		}, options || {});

		if (options && options.headers) {
			opts.headers = Object.assign({}, opts.headers, options.headers);
		}

		return fetch(url, opts).then(function (res) {
			if (!res.ok) {
				return res.json()
					.then(function (body) {
						const msg = (body && body.message) ? body.message : ('HTTP ' + res.status);
						throw new Error(msg);
					})
					.catch(function (parseErr) {
						if (parseErr instanceof Error && parseErr.message !== ('HTTP ' + res.status)) {
							throw parseErr;
						}
						throw new Error('HTTP ' + res.status);
					});
			}
			if (res.status === 204) {
				return null;
			}
			return res.json();
		});
	}

	function i18n(text) {
		if (I18N && Object.prototype.hasOwnProperty.call(I18N, text)) {
			return I18N[text];
		}
		return text;
	}

	function escAttr(str) {
		return String(str).replace(/"/g, '\\"');
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function escapeAttr(str) {
		return escapeHtml(str);
	}

	function statusLabel(status) {
		const labels = {
			publish: STATUS_OPTIONS.publish || 'Publicado',
			draft: STATUS_OPTIONS.draft || 'Borrador',
			pending: 'Pendiente',
			private: STATUS_OPTIONS.private || 'Privado',
			future: 'Programado',
			trash: 'Papelera'
		};
		return labels[status] || status;
	}

	// ─── Boot ──────────────────────────────────────────────────────────────────

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
