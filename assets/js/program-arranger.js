/**
 * ATORA-LMS — Program Course Arranger
 *
 * Sortable panel for reordering courses within a program.
 * Vanilla JS, shares CSS tokens with curriculum-builder.css.
 *
 * Config injected via wp_localize_script as window.ATORA_PA:
 *   { restUrl, nonce, programId, adminUrl, enrolledCourses, allCourses }
 *
 * enrolledCourses: [{ id, title }]  — current program courses in saved order
 * allCourses:      [{ id, title }]  — all lm_course posts for the add picker
 */
/* global Sortable, ATORA_PA */
(function () {
	'use strict';

	// ─── Config ────────────────────────────────────────────────────────────────

	const cfg      = window.ATORA_PA || {};
	const REST     = (cfg.restUrl  || '').replace(/\/$/, '');
	const NONCE    = cfg.nonce    || '';
	const PROGRAM  = parseInt(cfg.programId, 10) || 0;
	const ADMIN    = (cfg.adminUrl || '').replace(/\/$/, '');

	// ─── State ─────────────────────────────────────────────────────────────────

	/** Ordered list of courses currently in the program. { id, title } */
	let courses = (cfg.enrolledCourses || []).slice();

	/** Full catalog for the add picker. { id, title } */
	const allCourses = cfg.allCourses || [];

	// ─── DOM refs ──────────────────────────────────────────────────────────────

	let container, courseListEl, hiddenInputsEl, statusEl;
	let searchInput, searchResults;
	let sortableInstance = null;

	// ─── Init ──────────────────────────────────────────────────────────────────

	function init() {
		container = document.getElementById('atora-program-arranger');
		if (!container || !PROGRAM) { return; }

		render();
		initSortable();
		syncHiddenInputs();
	}

	// ─── Rendering ─────────────────────────────────────────────────────────────

	function render() {
		container.innerHTML = '';

		// ── Add-course panel ──
		const addPanel = document.createElement('div');
		addPanel.className = 'pa-add-panel';

		const searchWrap = document.createElement('div');
		searchWrap.className = 'pa-search-dropdown';

		searchInput = document.createElement('input');
		searchInput.type = 'text';
		searchInput.className = 'pa-search-input';
		searchInput.placeholder = i18n('Buscar y agregar curso\u2026');
		searchInput.setAttribute('autocomplete', 'off');

		searchResults = document.createElement('div');
		searchResults.className = 'pa-search-results';

		searchWrap.appendChild(searchInput);
		searchWrap.appendChild(searchResults);
		addPanel.appendChild(searchWrap);
		container.appendChild(addPanel);

		// Search events
		searchInput.addEventListener('input', handleSearch);
		searchInput.addEventListener('focus', handleSearch);
		searchInput.addEventListener('keydown', handleSearchKeydown);

		// Close dropdown on outside click
		document.addEventListener('click', function (e) {
			if (!searchWrap.contains(e.target)) {
				closeSearchResults();
			}
		});

		// ── Course list ──
		courseListEl = document.createElement('ul');
		courseListEl.className = 'pa-course-list';

		renderCourseList();
		container.appendChild(courseListEl);

		// ── Hidden inputs container (for classic WP form save fallback) ──
		hiddenInputsEl = document.createElement('div');
		hiddenInputsEl.id = 'pa-hidden-inputs';
		container.appendChild(hiddenInputsEl);

		// ── Status bar ──
		statusEl = document.createElement('div');
		statusEl.className = 'cb-status cb-status--hidden';
		statusEl.setAttribute('aria-live', 'polite');
		container.appendChild(statusEl);
	}

	function renderCourseList() {
		courseListEl.innerHTML = '';

		if (courses.length === 0) {
			const empty = document.createElement('li');
			empty.className = 'pa-course-list-empty';
			empty.textContent = i18n('Este programa todavía no tiene cursos. Usa el buscador para agregar.');
			courseListEl.appendChild(empty);
			return;
		}

		courses.forEach(function (course, idx) {
			courseListEl.appendChild(renderCourseRow(course, idx + 1));
		});
	}

	function renderCourseRow(course, order) {
		const li = document.createElement('li');
		li.className = 'pa-course-item';
		li.dataset.courseId = course.id;

		const handle = document.createElement('span');
		handle.className = 'pa-drag-handle';
		handle.setAttribute('title', i18n('Arrastrar para reordenar'));
		handle.innerHTML = '&#9776;'; // ☰

		const orderBadge = document.createElement('span');
		orderBadge.className = 'pa-course-order';
		orderBadge.textContent = order;

		const title = document.createElement('span');
		title.className = 'pa-course-title';
		title.textContent = course.title || i18n('(Sin título)');
		title.setAttribute('title', course.title || '');

		const editLink = document.createElement('a');
		editLink.className = 'pa-edit-link';
		editLink.href = ADMIN + '/post.php?post=' + course.id + '&action=edit';
		editLink.target = '_blank';
		editLink.rel = 'noopener';
		editLink.textContent = i18n('Editar') + ' \u2197';

		const removeBtn = document.createElement('button');
		removeBtn.type = 'button';
		removeBtn.className = 'pa-remove-btn';
		removeBtn.setAttribute('title', i18n('Quitar del programa'));
		removeBtn.innerHTML = '\u00D7'; // ×
		removeBtn.addEventListener('click', function () {
			handleRemoveCourse(course.id);
		});

		li.appendChild(handle);
		li.appendChild(orderBadge);
		li.appendChild(title);
		li.appendChild(editLink);
		li.appendChild(removeBtn);

		return li;
	}

	// ─── SortableJS ────────────────────────────────────────────────────────────

	function initSortable() {
		if (typeof Sortable === 'undefined') {
			console.error('[ATORA-PA] SortableJS not loaded.');
			return;
		}

		if (sortableInstance) {
			sortableInstance.destroy();
			sortableInstance = null;
		}

		sortableInstance = Sortable.create(courseListEl, {
			handle:     '.pa-drag-handle',
			animation:  150,
			ghostClass: 'pa-ghost',
			chosenClass:'pa-chosen',
			dragClass:  'pa-drag',
			onEnd: function () {
				syncStateFromDOM();
				refreshOrderNumbers();
				syncHiddenInputs();
				scheduleAutoSave();
			}
		});
	}

	function syncStateFromDOM() {
		const items = courseListEl.querySelectorAll('.pa-course-item');
		const newCourses = [];

		items.forEach(function (li) {
			const id = parseInt(li.dataset.courseId, 10);
			const existing = courses.find(function (c) { return c.id === id; });
			if (existing) { newCourses.push(existing); }
		});

		courses = newCourses;
	}

	function refreshOrderNumbers() {
		const items = courseListEl.querySelectorAll('.pa-course-item');
		items.forEach(function (li, idx) {
			const badge = li.querySelector('.pa-course-order');
			if (badge) { badge.textContent = idx + 1; }
		});
	}

	// ─── Search / Add ──────────────────────────────────────────────────────────

	let focusedResultIndex = -1;

	function handleSearch() {
		const query = searchInput.value.trim().toLowerCase();
		const enrolledIds = new Set(courses.map(function (c) { return c.id; }));

		const filtered = allCourses.filter(function (c) {
			return !query || c.title.toLowerCase().includes(query);
		});

		searchResults.innerHTML = '';
		focusedResultIndex = -1;

		if (filtered.length === 0) {
			const noRes = document.createElement('div');
			noRes.className = 'pa-no-results';
			noRes.textContent = i18n('Sin resultados.');
			searchResults.appendChild(noRes);
		} else {
			filtered.forEach(function (course) {
				const item = document.createElement('div');
				item.className = 'pa-search-result-item';
				item.dataset.courseId = course.id;

				const enrolled = enrolledIds.has(course.id);
				if (enrolled) {
					item.classList.add('pa-already-added');
					item.textContent = course.title + ' \u2014 ' + i18n('ya añadido');
				} else {
					item.textContent = course.title;
					item.addEventListener('click', function () {
						addCourse(course);
					});
				}

				searchResults.appendChild(item);
			});
		}

		searchResults.classList.add('pa-open');
	}

	function handleSearchKeydown(e) {
		const items = searchResults.querySelectorAll('.pa-search-result-item:not(.pa-already-added)');

		if (e.key === 'ArrowDown') {
			e.preventDefault();
			focusedResultIndex = Math.min(focusedResultIndex + 1, items.length - 1);
			updateFocusedResult(items);
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			focusedResultIndex = Math.max(focusedResultIndex - 1, 0);
			updateFocusedResult(items);
		} else if (e.key === 'Enter') {
			e.preventDefault();
			if (focusedResultIndex >= 0 && items[focusedResultIndex]) {
				const id = parseInt(items[focusedResultIndex].dataset.courseId, 10);
				const course = allCourses.find(function (c) { return c.id === id; });
				if (course) { addCourse(course); }
			}
		} else if (e.key === 'Escape') {
			closeSearchResults();
		}
	}

	function updateFocusedResult(items) {
		items.forEach(function (item, idx) {
			item.classList.toggle('pa-focused', idx === focusedResultIndex);
		});
	}

	function closeSearchResults() {
		searchResults.classList.remove('pa-open');
		focusedResultIndex = -1;
	}

	// ─── Add / Remove ──────────────────────────────────────────────────────────

	function addCourse(course) {
		// Prevent duplicates
		if (courses.find(function (c) { return c.id === course.id; })) {
			closeSearchResults();
			return;
		}

		courses.push(course);

		// Remove empty state if present
		const emptyEl = courseListEl.querySelector('.pa-course-list-empty');
		if (emptyEl) { emptyEl.remove(); }

		const row = renderCourseRow(course, courses.length);
		courseListEl.appendChild(row);

		// Re-init sortable so new row is draggable
		initSortable();

		syncHiddenInputs();
		scheduleAutoSave();

		// Clear search
		searchInput.value = '';
		closeSearchResults();
	}

	function handleRemoveCourse(courseId) {
		const course = courses.find(function (c) { return c.id === courseId; });
		const title  = course ? course.title : '#' + courseId;

		if (!confirm(i18n('¿Quitar "') + title + i18n('" del programa?'))) {
			return;
		}

		courses = courses.filter(function (c) { return c.id !== courseId; });

		// Remove from DOM
		const li = courseListEl.querySelector('.pa-course-item[data-course-id="' + courseId + '"]');
		if (li) { li.remove(); }

		// Show empty state if needed
		if (courses.length === 0) {
			const empty = document.createElement('li');
			empty.className = 'pa-course-list-empty';
			empty.textContent = i18n('Este programa todavía no tiene cursos. Usa el buscador para agregar.');
			courseListEl.appendChild(empty);
		} else {
			refreshOrderNumbers();
		}

		syncHiddenInputs();
		scheduleAutoSave();
	}

	// ─── Hidden inputs (classic WP form fallback) ──────────────────────────────

	/**
	 * Keeps hidden inputs in sync so the classic WP "Actualizar" button
	 * also saves the current ordered course list via save_meta_boxes().
	 */
	function syncHiddenInputs() {
		if (!hiddenInputsEl) { return; }
		hiddenInputsEl.innerHTML = '';

		courses.forEach(function (course) {
			const input = document.createElement('input');
			input.type  = 'hidden';
			input.name  = 'clms_program_course_ids[]';
			input.value = course.id;
			hiddenInputsEl.appendChild(input);
		});
	}

	// ─── Auto-save ─────────────────────────────────────────────────────────────

	let saveTimer = null;
	let saving    = false;
	let pendingSave = false;

	function scheduleAutoSave() {
		clearTimeout(saveTimer);
		saveTimer = setTimeout(performSave, 300);
	}

	async function performSave() {
		if (saving) {
			pendingSave = true;
			return;
		}

		saving     = true;
		pendingSave = false;
		showStatus('saving', i18n('Guardando\u2026'));

		const courseIds = courses.map(function (c) { return c.id; });

		try {
			await apiFetch(REST + '/clms/v1/programs/reorder', {
				method: 'POST',
				body: JSON.stringify({ program_id: PROGRAM, course_ids: courseIds })
			});

			showStatus('ok', i18n('Guardado \u2713'));
			setTimeout(hideStatus, 2500);
		} catch (err) {
			showStatus('error', i18n('Error al guardar.'), true);
			console.error('[ATORA-PA] save error:', err);
		} finally {
			saving = false;
			if (pendingSave) { scheduleAutoSave(); }
		}
	}

	// ─── Status bar ────────────────────────────────────────────────────────────

	function showStatus(type, message, showRetry) {
		if (!statusEl) { return; }
		statusEl.innerHTML = '';
		statusEl.className = 'cb-status cb-status--' + type;

		statusEl.appendChild(document.createTextNode(message));

		if (showRetry) {
			const btn = document.createElement('button');
			btn.className = 'cb-retry-btn';
			btn.textContent = i18n('Reintentar');
			btn.addEventListener('click', scheduleAutoSave);
			statusEl.appendChild(btn);
		}
	}

	function hideStatus() {
		if (!statusEl) { return; }
		statusEl.className = 'cb-status cb-status--hidden';
	}

	// ─── Utilities ─────────────────────────────────────────────────────────────

	function apiFetch(url, options) {
		const opts = Object.assign({
			method:  'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   NONCE
			}
		}, options || {});

		return fetch(url, opts).then(function (res) {
			if (!res.ok) {
				return res.json().then(function (body) {
					throw new Error((body && body.message) ? body.message : 'HTTP ' + res.status);
				}).catch(function (err) {
					if (err instanceof Error && err.message !== 'HTTP ' + res.status) { throw err; }
					throw new Error('HTTP ' + res.status);
				});
			}
			if (res.status === 204) { return null; }
			return res.json();
		});
	}

	function i18n(text) { return text; }

	// ─── Boot ──────────────────────────────────────────────────────────────────

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
