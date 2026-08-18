(function () {
	'use strict';

	var root = document.getElementById('clms-drip-timeline');
	if (!root) {
		return;
	}

	var config = window.CLMS_TL || {};
	var i18n = config.i18n || {};

	function __(key, fallback) {
		if (Object.prototype.hasOwnProperty.call(i18n, key) && i18n[key]) {
			return i18n[key];
		}
		return fallback;
	}

	function sprintf(template, value) {
		return String(template || '').replace('%d', String(value));
	}

	function escHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function parseLessons() {
		var raw = root.getAttribute('data-lessons') || '[]';
		try {
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function parseDate(value) {
		if (!value) {
			return null;
		}
		var date = new Date(String(value) + 'T00:00:00');
		if (Number.isNaN(date.getTime())) {
			return null;
		}
		return date;
	}

	function toISODate(date) {
		var year = date.getFullYear();
		var month = String(date.getMonth() + 1).padStart(2, '0');
		var day = String(date.getDate()).padStart(2, '0');
		return year + '-' + month + '-' + day;
	}

	function formatShortDate(date) {
		return date.getDate() + '/' + (date.getMonth() + 1);
	}

	function addDays(date, days) {
		var copy = new Date(date.getTime());
		copy.setDate(copy.getDate() + days);
		return copy;
	}

	function resolveStartDate(lessons) {
		var minDate = null;
		lessons.forEach(function (lesson) {
			if (lesson && lesson.drip_type === 'date') {
				var parsed = parseDate(lesson.drip_date);
				if (parsed && (!minDate || parsed.getTime() < minDate.getTime())) {
					minDate = parsed;
				}
			}
		});

		if (minDate) {
			return minDate;
		}

		var now = new Date();
		now.setHours(0, 0, 0, 0);
		return now;
	}

	function clamp(value, min, max) {
		return Math.max(min, Math.min(max, value));
	}

	function asInt(value) {
		var parsed = parseInt(value, 10);
		return Number.isFinite(parsed) ? parsed : 0;
	}

	function normalizeLessons(lessons) {
		return lessons.map(function (lesson) {
			var item = lesson || {};
			var dripType = String(item.drip_type || 'none');
			if (['none', 'date', 'days_enrolled', 'days_after_previous'].indexOf(dripType) === -1) {
				dripType = 'none';
			}
			return {
				id: asInt(item.id),
				title: String(item.title || ''),
				menu_order: asInt(item.menu_order),
				module: String(item.module || ''),
				drip_type: dripType,
				drip_value: asInt(item.drip_value),
				drip_date: String(item.drip_date || '')
			};
		});
	}

	var lessons = normalizeLessons(parseLessons());
	if (!lessons.length) {
		return;
	}

	var state = {
		mode: 'relative',
		zoom: 'day',
		totalDays: 90,
		startDate: resolveStartDate(lessons),
		maxDay: 90,
		dripDays: []
	};

	root.innerHTML = '' +
		'<div class="clms-timeline-wrap">' +
			'<div class="clms-timeline-toolbar">' +
				'<div class="clms-timeline-mode-toggle">' +
					'<button type="button" class="clms-timeline-mode active" data-mode="relative">' + escHtml(__('modeRelative', 'Días desde inscripción')) + '</button>' +
					'<button type="button" class="clms-timeline-mode" data-mode="absolute">' + escHtml(__('modeAbsolute', 'Calendario')) + '</button>' +
				'</div>' +
				'<div class="clms-timeline-zoom">' +
					'<button type="button" data-zoom="week">' + escHtml(__('zoomWeeks', 'Semanas')) + '</button>' +
					'<button type="button" data-zoom="day" class="active">' + escHtml(__('zoomDays', 'Días')) + '</button>' +
				'</div>' +
				'<div class="clms-timeline-start-date" hidden>' +
					'<label for="clms-timeline-start">' + escHtml(__('startDate', 'Fecha de inicio:')) + '</label>' +
					'<input type="date" id="clms-timeline-start" value="' + escHtml(toISODate(state.startDate)) + '">' +
				'</div>' +
			'</div>' +
			'<div class="clms-timeline-canvas">' +
				'<div class="clms-timeline-header" id="clms-timeline-header"></div>' +
				'<div class="clms-timeline-body" id="clms-timeline-body"></div>' +
			'</div>' +
			'<div class="clms-timeline-legend">' +
				'<span><i class="clms-tl-dot clms-tl-dot--none"></i>' + escHtml(__('legendNone', 'Sin restricción')) + '</span>' +
				'<span><i class="clms-tl-dot clms-tl-dot--date"></i>' + escHtml(__('legendDate', 'Fecha fija')) + '</span>' +
				'<span><i class="clms-tl-dot clms-tl-dot--days"></i>' + escHtml(__('legendDays', 'Días post-inscripción')) + '</span>' +
				'<span><i class="clms-tl-dot clms-tl-dot--prev"></i>' + escHtml(__('legendPrev', 'Días post-anterior')) + '</span>' +
			'</div>' +
		'</div>';

	var headerEl = document.getElementById('clms-timeline-header');
	var bodyEl = document.getElementById('clms-timeline-body');
	var startDateWrap = root.querySelector('.clms-timeline-start-date');
	var startDateInput = document.getElementById('clms-timeline-start');

	function calculateDripDays() {
		var result = [];
		var previousDay = 0;

		lessons.forEach(function (lesson) {
			var day = 0;
			var type = lesson.drip_type || 'none';
			if (type === 'date') {
				var dateValue = parseDate(lesson.drip_date);
				if (dateValue) {
					day = Math.round((dateValue.getTime() - state.startDate.getTime()) / 86400000);
				} else {
					day = previousDay;
				}
			} else if (type === 'days_enrolled') {
				day = asInt(lesson.drip_value);
			} else if (type === 'days_after_previous') {
				day = previousDay + asInt(lesson.drip_value);
			}
			day = Math.max(0, day);
			result.push(day);
			previousDay = day;
		});

		return result;
	}

	function calculateMaxDay(dripDays) {
		var maxDrip = 0;
		dripDays.forEach(function (value) {
			maxDrip = Math.max(maxDrip, asInt(value));
		});
		return Math.max(state.totalDays, maxDrip + 14);
	}

	function getDayLabel(day) {
		if (day <= 0) {
			return sprintf(__('dayLabel', 'Día %d'), 0);
		}
		return sprintf(__('dayLabel', 'Día %d'), day);
	}

	function renderHeader(maxDay) {
		var step = state.zoom === 'week' ? 7 : 1;
		var approxTicks = Math.ceil(maxDay / step);
		if (approxTicks > 60) {
			step = Math.ceil(maxDay / 60);
		}
		if (state.zoom === 'week' && step < 7) {
			step = 7;
		}

		var html = '' +
			'<div class="clms-timeline-header-spacer"></div>' +
			'<div class="clms-timeline-header-track">';

		for (var day = 0; day <= maxDay; day += step) {
			var left = (day / maxDay) * 100;
			var label = '';
			if (state.mode === 'absolute') {
				label = formatShortDate(addDays(state.startDate, day));
			} else if (state.zoom === 'week') {
				label = sprintf(__('weekLabel', 'Sem %d'), Math.floor(day / 7));
			} else {
				label = 'D' + day;
			}
			html += '<span class="clms-tl-tick" style="left:' + left + '%">' + escHtml(label) + '</span>';
		}

		html += '</div>';
		headerEl.innerHTML = html;
	}

	function renderRows(maxDay, dripDays) {
		var html = '';

		lessons.forEach(function (lesson, index) {
			var dayStart = asInt(dripDays[index]);
			var barLeft = (dayStart / maxDay) * 100;
			var barWidth = Math.max(2, (7 / maxDay) * 100);
			var typeClass = 'clms-tl-bar--' + (lesson.drip_type || 'none');

			html += '<div class="clms-timeline-row" data-lesson-id="' + lesson.id + '" data-index="' + index + '">' +
				'<div class="clms-timeline-label">' +
					'<span class="clms-timeline-order">' + (index + 1) + '</span>' +
					'<span class="clms-timeline-name" title="' + escHtml(lesson.title) + '">' + escHtml(lesson.title || '(Sin título)') + '</span>' +
					'<span class="clms-timeline-drip-badge">' + escHtml(getDayLabel(dayStart)) + '</span>' +
				'</div>' +
				'<div class="clms-timeline-track">' +
					'<div class="clms-timeline-bar ' + typeClass + '"' +
						' data-day="' + dayStart + '"' +
						' data-type="' + escHtml(lesson.drip_type) + '"' +
						' style="left:' + barLeft + '%;width:' + barWidth + '%"></div>' +
				'</div>' +
			'</div>';
		});

		bodyEl.innerHTML = html;
	}

	function render() {
		state.dripDays = calculateDripDays();
		state.maxDay = calculateMaxDay(state.dripDays);
		renderHeader(state.maxDay);
		renderRows(state.maxDay, state.dripDays);
		bindBarDrag();
	}

	var activeDrag = null;

	function updateRowLabel(row, day) {
		var badge = row ? row.querySelector('.clms-timeline-drip-badge') : null;
		if (!badge) {
			return;
		}
		badge.textContent = getDayLabel(day);
	}

	function findBarElement(target) {
		if (!target || !target.classList || !target.classList.contains('clms-timeline-bar')) {
			return null;
		}
		return target;
	}

	function onMouseMove(event) {
		if (!activeDrag) {
			return;
		}

		var trackWidth = activeDrag.track.offsetWidth || 1;
		var deltaX = event.clientX - activeDrag.startX;
		var deltaPercent = (deltaX / trackWidth) * 100;
		var newLeft = clamp(activeDrag.startLeft + deltaPercent, 0, 95);
		activeDrag.bar.style.left = newLeft + '%';

		var previewDay = Math.round((newLeft / 100) * state.maxDay);
		updateRowLabel(activeDrag.row, previewDay);
	}

	function resolveDripPayload(newDay, lessonIndex) {
		if (newDay <= 0) {
			return {
				drip_type: 'none',
				drip_value: 0
			};
		}

		if (state.mode === 'absolute') {
			var targetDate = toISODate(addDays(state.startDate, newDay));
			return {
				drip_type: 'date',
				drip_value: targetDate,
				drip_date: targetDate
			};
		}

		var previousDay = lessonIndex > 0 ? asInt(state.dripDays[lessonIndex - 1]) : 0;
		if (lessonIndex > 0 && newDay >= previousDay) {
			return {
				drip_type: 'days_after_previous',
				drip_value: Math.max(0, newDay - previousDay)
			};
		}

		return {
			drip_type: 'days_enrolled',
			drip_value: newDay
		};
	}

	function applyLocalDrip(lessonIndex, payload) {
		var lesson = lessons[lessonIndex];
		if (!lesson) {
			return;
		}

		lesson.drip_type = payload.drip_type || 'none';
		if (lesson.drip_type === 'date') {
			lesson.drip_date = String(payload.drip_date || payload.drip_value || '');
			lesson.drip_value = 0;
		} else {
			lesson.drip_date = '';
			lesson.drip_value = asInt(payload.drip_value);
		}
	}

	function markSaved(bar) {
		if (!bar) {
			return;
		}
		bar.classList.add('clms-tl-saved');
		window.setTimeout(function () {
			bar.classList.remove('clms-tl-saved');
		}, 1200);
	}

	function showInlineError(message) {
		var errorId = 'clms-drip-timeline-error';
		var current = document.getElementById(errorId);
		if (!current) {
			current = document.createElement('p');
			current.id = errorId;
			current.className = 'clms-timeline-error';
			root.insertBefore(current, root.firstChild);
		}
		current.textContent = message;
		window.setTimeout(function () {
			if (current && current.parentNode) {
				current.parentNode.removeChild(current);
			}
		}, 3000);
	}

	function saveDripChange(lessonId, lessonIndex, newDay, bar) {
		var payload = resolveDripPayload(newDay, lessonIndex);
		var previous = {
			drip_type: lessons[lessonIndex] ? lessons[lessonIndex].drip_type : 'none',
			drip_value: lessons[lessonIndex] ? lessons[lessonIndex].drip_value : 0,
			drip_date: lessons[lessonIndex] ? lessons[lessonIndex].drip_date : ''
		};
		applyLocalDrip(lessonIndex, payload);

		fetch(config.restBase + 'lessons/' + lessonId + '/quick-edit', {
			method: 'PATCH',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify(payload)
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (!data || data.success !== true) {
					applyLocalDrip(lessonIndex, previous);
					showInlineError((data && data.message) || __('saveError', 'Error al guardar cambios de liberación.'));
					render();
					return;
				}
				render();
				var currentBar = bodyEl.querySelector('[data-lesson-id="' + lessonId + '"] .clms-timeline-bar');
				markSaved(currentBar || bar);
			})
			.catch(function () {
				applyLocalDrip(lessonIndex, previous);
				showInlineError(__('saveError', 'Error al guardar cambios de liberación.'));
				render();
			});
	}

	function onMouseUp() {
		if (!activeDrag) {
			return;
		}

		var finalLeft = parseFloat(activeDrag.bar.style.left) || 0;
		var finalDay = Math.round((finalLeft / 100) * state.maxDay);
		var lessonId = asInt(activeDrag.row.getAttribute('data-lesson-id'));
		var lessonIndex = asInt(activeDrag.row.getAttribute('data-index'));
		var bar = activeDrag.bar;

		bar.classList.remove('is-dragging');
		activeDrag = null;

		saveDripChange(lessonId, lessonIndex, finalDay, bar);
	}

	function bindBarDrag() {
		var bars = bodyEl.querySelectorAll('.clms-timeline-bar');
		bars.forEach(function (bar) {
			bar.addEventListener('mousedown', function (event) {
				var targetBar = findBarElement(event.currentTarget);
				if (!targetBar) {
					return;
				}

				var row = targetBar.closest('.clms-timeline-row');
				var track = targetBar.parentElement;
				if (!row || !track) {
					return;
				}

				activeDrag = {
					bar: targetBar,
					row: row,
					track: track,
					startX: event.clientX,
					startLeft: parseFloat(targetBar.style.left) || 0
				};

				targetBar.classList.add('is-dragging');
				event.preventDefault();
			});
		});
	}

	function bindToolbar() {
		root.querySelectorAll('.clms-timeline-mode').forEach(function (button) {
			button.addEventListener('click', function () {
				var mode = button.getAttribute('data-mode');
				if (mode !== 'relative' && mode !== 'absolute') {
					return;
				}
				state.mode = mode;

				root.querySelectorAll('.clms-timeline-mode').forEach(function (btn) {
					btn.classList.remove('active');
				});
				button.classList.add('active');
				startDateWrap.hidden = mode !== 'absolute';

				render();
			});
		});

		root.querySelectorAll('[data-zoom]').forEach(function (button) {
			button.addEventListener('click', function () {
				var zoom = button.getAttribute('data-zoom');
				if (zoom !== 'day' && zoom !== 'week') {
					return;
				}
				state.zoom = zoom;

				root.querySelectorAll('[data-zoom]').forEach(function (btn) {
					btn.classList.remove('active');
				});
				button.classList.add('active');

				render();
			});
		});

		startDateInput.addEventListener('change', function () {
			var parsed = parseDate(startDateInput.value);
			if (!parsed) {
				return;
			}
			state.startDate = parsed;
			render();
		});
	}

	document.addEventListener('mousemove', onMouseMove);
	document.addEventListener('mouseup', onMouseUp);

	bindToolbar();
	render();
})();
