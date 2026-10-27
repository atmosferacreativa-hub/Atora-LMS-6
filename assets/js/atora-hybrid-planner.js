/**
 * Planificación híbrida (6.37.0). Sin dependencias. Funciona sin IA.
 *
 * Guardado en dos niveles, siempre visibles por separado:
 * - borrador en este navegador (localStorage) mientras se escribe, para no perder nada si se corta;
 * - guardado en el servidor con número de revisión (409 si otra persona guardó antes).
 */
(function () {
	'use strict';

	var cfg = window.atoraPlanner || {};
	var T = cfg.i18n || {};
	var root = document.getElementById('atora-planner');
	if (!root) { return; }

	var STEPS = ['objective', 'inPerson', 'remote', 'resources', 'evidence', 'support', 'preview'];
	var STEP_LABELS = { objective: T.stepObjective, inPerson: T.stepInPerson, remote: T.stepRemote, resources: T.stepResources, evidence: T.stepEvidence, support: T.stepSupport, preview: T.stepPreview };

	var state = { courses: [], aiAvailable: false, courseId: 0, plans: [], plan: null, data: null, step: 0, dirty: false, status: '', statusKind: '' };

	// ── Utilidades ─────────────────────────────────────────────────────────
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			var value = attrs[key];
			if (key === 'text') { node.textContent = value; }
			else if (key === 'on') { Object.keys(value).forEach(function (ev) { node.addEventListener(ev, value[ev]); }); }
			else if (value === true) { node.setAttribute(key, key); }
			else if (value !== false && value !== null && value !== undefined) { node.setAttribute(key, value); }
		});
		(children || []).forEach(function (child) { if (child) { node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child); } });
		return node;
	}
	function fmt(text, value) { return String(text || '').replace('%s', value); }
	function clone(obj) { return JSON.parse(JSON.stringify(obj)); }
	function now() { return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
	function store() { try { return window.localStorage; } catch (e) { return null; } }
	function localKey(planId) { return 'atora_plan_draft_' + (cfg.userId || 0) + '_' + planId; }

	function api(path, method, body) {
		return fetch(cfg.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (json) {
				if (!res.ok) { var err = new Error(json && json.message ? json.message : T.error); err.status = res.status; err.data = json; throw err; }
				return json;
			});
		});
	}

	function setStatus(text, kind) { state.status = text; state.statusKind = kind || ''; var node = document.getElementById('atora-planner-status'); if (node) { node.textContent = text; node.className = 'atora-planner-status ' + (kind || ''); } }

	// ── Borrador local ─────────────────────────────────────────────────────
	var localTimer = null;
	function saveLocalSoon() {
		state.dirty = true;
		clearTimeout(localTimer);
		localTimer = setTimeout(function () {
			var s = store();
			if (!s || !state.plan) { return; }
			try { s.setItem(localKey(state.plan.id), JSON.stringify({ revision: state.plan.revision, savedAt: Date.now(), data: state.data })); } catch (e) { /* sin espacio */ }
			setStatus(fmt(T.savedLocal, now()), 'local');
		}, 600);
	}
	function readLocal(planId) { var s = store(); if (!s) { return null; } try { return JSON.parse(s.getItem(localKey(planId)) || 'null'); } catch (e) { return null; } }
	function clearLocal(planId) { var s = store(); if (s) { try { s.removeItem(localKey(planId)); } catch (e) { /* nada */ } } }

	// ── Pantallas ──────────────────────────────────────────────────────────
	function render() {
		root.innerHTML = '';
		if (!state.courses.length) { root.appendChild(el('p', { text: T.noCourses })); return; }
		if (state.plan) { renderEditor(); return; }
		renderList();
	}

	function renderList() {
		var select = el('select', { id: 'atora-planner-course', on: { change: function () { state.courseId = parseInt(this.value, 10) || 0; loadPlans(); } } },
			[el('option', { value: '0', text: T.chooseCourse })].concat(state.courses.map(function (c) { return el('option', { value: String(c.id), selected: c.id === state.courseId, text: c.title }); })));
		root.appendChild(el('p', {}, [el('label', { 'for': 'atora-planner-course', text: T.course + ' ' }), select]));
		if (!state.courseId) { return; }
		root.appendChild(el('h2', { text: T.plans }));
		root.appendChild(el('p', {}, [el('button', { type: 'button', 'class': 'button button-primary', text: T.newPlan, on: { click: createPlan } })]));
		if (!state.plans.length) { root.appendChild(el('p', { text: T.noPlans })); return; }
		var list = el('ul', { 'class': 'atora-planner-list' });
		state.plans.forEach(function (p) {
			var status = p.status === 'published' ? (p.has_unpublished_changes ? T.unpublished : T.published) : T.draft;
			list.appendChild(el('li', {}, [
				el('strong', { text: p.title || '—' }), ' · ', el('span', { text: status }), ' ',
				el('button', { type: 'button', 'class': 'button', text: T.open, on: { click: function () { openPlan(p.id); } } }), ' ',
				el('button', { type: 'button', 'class': 'button', text: T.duplicate, on: { click: function () { duplicatePlan(p.id); } } })
			]));
		});
		root.appendChild(list);
	}

	function field(label, input) { return el('p', { 'class': 'atora-planner-field' }, [el('label', {}, [el('span', { text: label }), input])]); }
	function textInput(value, onInput, type) { return el('input', { type: type || 'text', value: value || '', on: { input: function () { onInput(this.value); saveLocalSoon(); } } }); }
	function area(value, onInput, rows) { var t = el('textarea', { rows: String(rows || 5), on: { input: function () { onInput(this.value); saveLocalSoon(); } } }); t.value = value || ''; return t; }

	function aiButton(fieldKey, apply) {
		if (!state.aiAvailable) { return document.createTextNode(''); }
		var box = el('div', { 'class': 'atora-planner-ai' });
		var btn = el('button', { type: 'button', 'class': 'button', text: T.aiSuggest, on: { click: function () {
			btn.disabled = true;
			box.querySelectorAll('.atora-planner-ai-result').forEach(function (n) { n.remove(); });
			api('/' + state.plan.id + '/suggest', 'POST', { field: fieldKey }).then(function (res) {
				var text = res.text || '';
				box.appendChild(el('div', { 'class': 'atora-planner-ai-result' }, [
					el('p', { 'class': 'description', text: T.aiNote }),
					el('pre', { text: text }),
					el('button', { type: 'button', 'class': 'button', text: T.aiUse, on: { click: function () { apply(text); saveLocalSoon(); render(); } } })
				]));
			}).catch(function () { box.appendChild(el('p', { 'class': 'atora-planner-ai-result', text: T.aiFailed })); }).then(function () { btn.disabled = false; });
		} } });
		box.appendChild(btn);
		return box;
	}

	function renderStep(name) {
		var d = state.data;
		var wrap = el('div', { 'class': 'atora-planner-step' });
		if (name === 'objective') {
			wrap.appendChild(field(T.title, textInput(d.title, function (v) { d.title = v; })));
			wrap.appendChild(field(T.weekLabel, textInput(d.week_label, function (v) { d.week_label = v; })));
			wrap.appendChild(field(T.objective, area(d.objective, function (v) { d.objective = v; })));
		} else if (name === 'inPerson') {
			wrap.appendChild(field(T.date, textInput(d.in_person.date, function (v) { d.in_person.date = v; }, 'date')));
			wrap.appendChild(field(T.time, textInput(d.in_person.time, function (v) { d.in_person.time = v; }, 'time')));
			wrap.appendChild(field(T.place, textInput(d.in_person.place, function (v) { d.in_person.place = v; })));
			wrap.appendChild(field(T.activities, area(d.in_person.activities, function (v) { d.in_person.activities = v; }, 7)));
			wrap.appendChild(aiButton('in_person', function (text) { d.in_person.activities = (d.in_person.activities ? d.in_person.activities + '\n\n' : '') + text; }));
		} else if (name === 'remote') {
			wrap.appendChild(field(T.before, area(d.remote.before, function (v) { d.remote.before = v; }, 6)));
			wrap.appendChild(field(T.after, area(d.remote.after, function (v) { d.remote.after = v; }, 6)));
			wrap.appendChild(aiButton('remote', function (text) { d.remote.before = (d.remote.before ? d.remote.before + '\n\n' : '') + text; }));
		} else if (name === 'resources') {
			d.resources.forEach(function (r, i) {
				var type = el('select', { on: { change: function () { r.type = this.value; saveLocalSoon(); render(); } } }, [
					['link', T.typeLink], ['pdf', T.typePdf], ['archivo', T.typeFile], ['audio', T.typeAudio], ['video', T.typeVideo]
				].map(function (o) { return el('option', { value: o[0], selected: r.type === o[0], text: o[1] }); }));
				var fileLabel = el('span', { 'class': 'atora-planner-file', text: r.file_id ? '#' + r.file_id : '' });
				var pick = el('button', { type: 'button', 'class': 'button', text: T.chooseFile, on: { click: function () {
					if (!window.wp || !window.wp.media) { return; }
					var frame = window.wp.media({ title: T.chooseFile, multiple: false, library: r.type === 'audio' ? { type: 'audio' } : {} });
					frame.on('select', function () { var att = frame.state().get('selection').first().toJSON(); r.file_id = att.id; if (!r.title) { r.title = att.title || ''; } saveLocalSoon(); render(); });
					frame.open();
				} } });
				var card = el('fieldset', { 'class': 'atora-planner-resource' }, [
					field(T.type, type),
					field(T.title, textInput(r.title, function (v) { r.title = v; })),
					r.type === 'link' ? field(T.url, textInput(r.url, function (v) { r.url = v; }, 'url')) : el('p', {}, [el('span', { text: T.file + ' ' }), fileLabel, ' ', pick]),
					r.type === 'audio' ? field(T.transcript, area(r.transcript, function (v) { r.transcript = v; }, 4)) : null,
					el('button', { type: 'button', 'class': 'button-link-delete', text: T.remove, on: { click: function () { d.resources.splice(i, 1); saveLocalSoon(); render(); } } })
				]);
				wrap.appendChild(card);
			});
			wrap.appendChild(el('button', { type: 'button', 'class': 'button', text: T.addResource, on: { click: function () { d.resources.push({ type: 'link', title: '', url: '', file_id: 0, transcript: '' }); saveLocalSoon(); render(); } } }));
		} else if (name === 'evidence') {
			var locked = !!state.plan.evidence_locked;
			if (locked) { wrap.appendChild(el('p', { 'class': 'notice notice-info inline', text: T.evidenceLocked })); }
			var ev = d.evidence;
			var check = el('input', { type: 'checkbox', checked: !!ev.enabled, disabled: locked, on: { change: function () { ev.enabled = this.checked; saveLocalSoon(); render(); } } });
			wrap.appendChild(el('p', {}, [el('label', {}, [check, ' ', T.evidenceEnabled])]));
			if (ev.enabled) {
				var dis = function (node) { if (locked) { node.querySelectorAll('input,textarea,select').forEach(function (n) { n.disabled = true; }); } return node; };
				wrap.appendChild(dis(field(T.evidenceTitle, textInput(ev.title, function (v) { ev.title = v; }))));
				wrap.appendChild(dis(field(T.instructions, area(ev.instructions, function (v) { ev.instructions = v; }, 6))));
				wrap.appendChild(dis(field(T.dueDate, textInput(ev.due_date, function (v) { ev.due_date = v; }, 'date'))));
				wrap.appendChild(dis(field(T.time, textInput(ev.due_time, function (v) { ev.due_time = v; }, 'time'))));
				var rubric = el('select', { on: { change: function () { ev.rubric_id = parseInt(this.value, 10) || 0; saveLocalSoon(); } } },
					[el('option', { value: '0', text: T.noRubric })].concat((cfg.rubrics || []).map(function (r) { return el('option', { value: String(r.id), selected: r.id === ev.rubric_id, text: r.title }); })));
				wrap.appendChild(dis(field(T.rubric, rubric)));
				wrap.appendChild(dis(field(T.criteria, area(ev.criteria, function (v) { ev.criteria = v; }, 5))));
				if (!locked) {
					wrap.appendChild(aiButton('criteria', function (text) { ev.criteria = (ev.criteria ? ev.criteria + '\n\n' : '') + text; }));
				}
			}
		} else if (name === 'support') {
			wrap.appendChild(field(T.supportHow, area(d.support.how, function (v) { d.support.how = v; }, 5)));
			wrap.appendChild(field(T.supportWhen, textInput(d.support.when, function (v) { d.support.when = v; })));
			wrap.appendChild(aiButton('support', function (text) { d.support.how = (d.support.how ? d.support.how + '\n\n' : '') + text; }));
		} else if (name === 'preview') {
			wrap.appendChild(el('div', { id: 'atora-planner-preview' }, [el('button', { type: 'button', 'class': 'button', text: T.preview, on: { click: loadPreview } })]));
		}
		return wrap;
	}

	function renderEditor() {
		var p = state.plan;
		var status = p.status === 'published' ? (p.has_unpublished_changes || state.dirty ? T.unpublished : T.published) : T.draft;
		root.appendChild(el('p', {}, [el('button', { type: 'button', 'class': 'button-link', text: T.back, on: { click: backToList } })]));
		root.appendChild(el('h2', { text: (state.data.title || '—') + ' · ' + status }));
		var nav = el('ol', { 'class': 'atora-planner-steps' });
		STEPS.forEach(function (name, i) {
			nav.appendChild(el('li', { 'class': i === state.step ? 'is-current' : '' }, [el('button', { type: 'button', 'class': 'button-link', 'aria-current': i === state.step ? 'step' : false, text: (i + 1) + '. ' + STEP_LABELS[name], on: { click: function () { state.step = i; render(); } } })]));
		});
		root.appendChild(nav);
		root.appendChild(renderStep(STEPS[state.step]));

		var bar = el('div', { 'class': 'atora-planner-bar' }, [
			el('button', { type: 'button', 'class': 'button', disabled: state.step === 0, text: T.prev, on: { click: function () { state.step = Math.max(0, state.step - 1); render(); } } }),
			el('button', { type: 'button', 'class': 'button button-primary', text: T.saveServer, on: { click: saveServer } }),
			el('button', { type: 'button', 'class': 'button', disabled: state.step === STEPS.length - 1, text: T.next, on: { click: function () { state.step = Math.min(STEPS.length - 1, state.step + 1); render(); } } })
		]);
		root.appendChild(bar);
		root.appendChild(el('p', { id: 'atora-planner-status', 'class': 'atora-planner-status ' + state.statusKind, role: 'status', text: state.status }));
	}

	// ── Acciones ───────────────────────────────────────────────────────────
	function loadPlans() {
		state.plans = [];
		if (!state.courseId) { render(); return; }
		api('?course_id=' + state.courseId).then(function (res) { state.plans = res.plans || []; render(); }).catch(function (e) { setStatus(e.message, 'error'); render(); });
	}

	function openPlan(id) {
		api('/' + id).then(function (plan) {
			state.plan = plan; state.data = clone(plan.data); state.step = 0; state.dirty = false;
			setStatus(fmt(T.savedServer, plan.updated_at || ''), 'server');
			render();
			var local = readLocal(plan.id);
			if (local && local.data && local.revision === plan.revision && JSON.stringify(local.data) !== JSON.stringify(plan.data)) {
				var box = el('div', { 'class': 'notice notice-warning inline' }, [
					el('p', { text: T.recoverQ }),
					el('button', { type: 'button', 'class': 'button', text: T.recover, on: { click: function () { state.data = local.data; state.dirty = true; box.remove(); render(); setStatus(fmt(T.savedLocal, now()), 'local'); } } }), ' ',
					el('button', { type: 'button', 'class': 'button-link', text: T.discard, on: { click: function () { clearLocal(plan.id); box.remove(); } } })
				]);
				root.insertBefore(box, root.firstChild);
			}
		}).catch(function (e) { setStatus(e.message, 'error'); });
	}

	function createPlan() {
		api('', 'POST', { course_id: state.courseId, data: clone(cfg.empty || {}) }).then(function (plan) { openPlan(plan.id); }).catch(function (e) { setStatus(e.message, 'error'); render(); });
	}

	function duplicatePlan(id) {
		api('/' + id + '/duplicate', 'POST', {}).then(function () { loadPlans(); }).catch(function (e) { setStatus(e.message, 'error'); render(); });
	}

	function backToList() { state.plan = null; state.data = null; loadPlans(); }

	function saveServer() {
		setStatus(T.saving, '');
		return api('/' + state.plan.id, 'PUT', { data: state.data, revision: state.plan.revision }).then(function (plan) {
			state.plan = plan; state.data = clone(plan.data); state.dirty = false;
			clearLocal(plan.id);
			render();
			setStatus(fmt(T.savedServer, now()), 'server');
			return plan;
		}).catch(function (e) {
			setStatus(e.status === 409 ? T.conflict : T.saveFailed, 'error');
			throw e;
		});
	}

	function loadPreview() {
		var box = document.getElementById('atora-planner-preview');
		if (state.dirty) { box.innerHTML = ''; box.appendChild(el('p', { text: T.saveFirst })); box.appendChild(el('button', { type: 'button', 'class': 'button button-primary', text: T.saveServer, on: { click: function () { saveServer().then(loadPreview).catch(function () {}); } } })); return; }
		api('/' + state.plan.id + '/preview').then(function (res) {
			box.innerHTML = '';
			box.appendChild(el('p', { text: T.previewIntro }));
			var list = el('ul', { 'class': 'atora-planner-preview' });
			(res.items || []).forEach(function (item) {
				var verb = item.action === 'create' ? T.willCreate : (item.action === 'update' ? T.willUpdate : T.willKeep);
				var li = el('li', {}, [el('strong', { text: verb + ': ' }), item.title]);
				if (item.content) { var details = el('details', {}, [el('summary', { text: T.preview })]); var body = el('div'); body.innerHTML = item.content; details.appendChild(body); li.appendChild(details); }
				list.appendChild(li);
			});
			box.appendChild(list);
			if (res.can_publish) {
				var btn = el('button', { type: 'button', 'class': 'button button-primary', text: T.publish, on: { click: function () {
					btn.disabled = true; setStatus(T.publishing, '');
					api('/' + state.plan.id + '/publish', 'POST', { revision: res.revision }).then(function (out) {
						state.plan = out.plan; state.data = clone(out.plan.data);
						var titles = (out.lessons || []).map(function (l) { return l.title; }).join(' · ');
						var notes = (out.lessons || []).some(function (l) { return (l.notes || []).indexOf('rubric_kept') !== -1; });
						render();
						setStatus(fmt(T.publishedOk, titles) + (notes ? ' ' + T.rubricKept : ''), 'server');
					}).catch(function (e) { btn.disabled = false; setStatus(e.message || T.error, 'error'); });
				} } });
				box.appendChild(btn);
			} else {
				box.appendChild(el('p', { 'class': 'notice notice-info inline', text: T.cannotPublish }));
			}
		}).catch(function (e) { setStatus(e.message, 'error'); });
	}

	// ── Inicio ─────────────────────────────────────────────────────────────
	api('/courses').then(function (res) {
		state.courses = res.courses || [];
		state.aiAvailable = !!res.ai_available;
		if (state.courses.length === 1) { state.courseId = state.courses[0].id; loadPlans(); return; }
		render();
	}).catch(function (e) { root.innerHTML = ''; root.appendChild(el('p', { text: e.message || T.error })); });
})();
