/**
 * Editor de flujos de automatización (6.38.0). Sin dependencias.
 *
 * Estado único = la definición del flujo. Cada cambio vuelve a dibujar el lienzo y copia el JSON a los campos
 * del formulario (que es lo que se guarda). Editar el JSON a mano en «Avanzado» también actualiza todo.
 */
(function () {
	'use strict';

	var cfg = window.atoraFlowEditor || {};
	var L = cfg.labels || { actions: {}, fields: {}, ops: {}, ui: {} };
	var U = L.ui || {};
	var form = document.getElementById('atora-flow-form');
	if (!form) { return; }

	var defBox = document.getElementById('atora-flow-definition');
	var condBox = document.getElementById('atora-flow-conditions');
	var canvas = document.getElementById('atora-flow-canvas');
	var stepsBox = document.getElementById('atora-flow-steps');
	var entryBox = document.getElementById('atora-flow-entry');
	var goalBox = document.getElementById('atora-flow-goal');
	var reentry = document.getElementById('atora-flow-reentry');

	var def = normalize(cfg.definition || {});
	var entry = normalizeRules(cfg.entry);

	function normalize(d) {
		d = d && typeof d === 'object' ? d : {};
		d.version = 2;
		d.steps = Array.isArray(d.steps) ? d.steps : [];
		d.goal = normalizeRules(d.goal);
		if (!d.start && d.steps.length) { d.start = d.steps[0].id; }
		return d;
	}

	function normalizeRules(r) {
		r = r && typeof r === 'object' && !Array.isArray(r) ? r : {};
		return { operator: r.operator === 'OR' ? 'OR' : 'AND', rules: Array.isArray(r.rules) ? r.rules : [] };
	}

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
		(children || []).forEach(function (child) {
			if (child) { node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child); }
		});
		return node;
	}

	function byId(id) {
		for (var i = 0; i < def.steps.length; i++) { if (def.steps[i].id === id) { return def.steps[i]; } }
		return null;
	}

	function newId(prefix) {
		var n = def.steps.length + 1;
		while (byId(prefix + n)) { n++; }
		return prefix + n;
	}

	function select(options, value, attrs, onChange) {
		var s = el('select', attrs || {});
		Object.keys(options).forEach(function (key) {
			var o = el('option', { value: key, text: options[key] });
			if (String(key) === String(value === null || value === undefined ? '' : value)) { o.selected = true; }
			s.appendChild(o);
		});
		s.addEventListener('change', function () { onChange(s.value); });
		return s;
	}

	function input(value, attrs, onChange) {
		var i = el('input', Object.assign({ type: 'text', value: value === undefined || value === null ? '' : String(value) }, attrs || {}));
		i.addEventListener('input', function () { onChange(i.value); });
		i.addEventListener('change', function () { onChange(i.value); render(); });
		return i;
	}

	function field(label, control) {
		return el('label', { 'class': 'atora-flow-field' }, [el('span', { text: label }), control]);
	}

	function targets(selfId) {
		var opts = { '': U.end };
		def.steps.forEach(function (s) { if (s.id !== selfId) { opts[s.id] = s.id + ' · ' + stepTitle(s); } });
		return opts;
	}

	function stepTitle(s) {
		if (s.type === 'wait') {
			if (s.until) { return U.wait + ' → ' + s.until; }
			if (s.until_field) { return U.wait + ' → {{' + s.until_field + '}}'; }
			return U.wait + ' ' + (s.minutes || 0) + ' min';
		}
		if (s.type === 'condition') { return U.condition; }
		var a = s.action || {};
		var label = L.actions[a.type] || a.type || U.action;
		var extra = a.template || a.tag || a.title || '';
		return label + (extra ? ': ' + extra : '');
	}

	// ── Sincronización ─────────────────────────────────────────────────────
	function sync() {
		if (reentry) { def.allow_reentry = reentry.checked; }
		var clean = JSON.parse(JSON.stringify(def));
		if (!clean.goal.rules.length) { delete clean.goal; }
		defBox.value = JSON.stringify(clean, null, 2);
		condBox.value = JSON.stringify(entry.rules.length ? entry : {});
	}

	function render() {
		renderCanvas();
		renderSteps();
		renderRules(entryBox, entry);
		renderRules(goalBox, def.goal);
		if (reentry) { reentry.checked = !!def.allow_reentry; }
		sync();
	}

	// ── Lienzo ─────────────────────────────────────────────────────────────
	function renderCanvas() {
		canvas.innerHTML = '';
		var seen = {};
		canvas.appendChild(el('div', { 'class': 'atora-flow-node atora-flow-start', text: U.start }));
		canvas.appendChild(chain(def.start, seen));
		var orphans = def.steps.filter(function (s) { return !seen[s.id]; });
		if (orphans.length) {
			canvas.appendChild(el('p', { 'class': 'atora-flow-warning', text: U.unreached + ': ' + orphans.map(function (s) { return s.id; }).join(', ') }));
		}
	}

	function chain(id, seen) {
		var list = el('ol', { 'class': 'atora-flow-chain' });
		var guard = 0;
		while (id && guard++ < 200) {
			var step = byId(id);
			if (!step) { break; }
			if (seen[id]) {
				list.appendChild(el('li', { 'class': 'atora-flow-jump', text: U.goesTo + ' ' + id }));
				return list;
			}
			seen[id] = true;
			var node = el('li', { 'class': 'atora-flow-node atora-flow-' + step.type }, [
				el('code', { text: step.id }), ' ', el('span', { text: stepTitle(step) })
			]);
			list.appendChild(node);
			if (step.type === 'condition') {
				var branches = el('div', { 'class': 'atora-flow-branches' });
				[['yes', U.yes], ['no', U.no]].forEach(function (b) {
					var col = el('div', { 'class': 'atora-flow-branch atora-flow-branch-' + b[0] }, [el('strong', { text: b[1] })]);
					if (step[b[0]]) { col.appendChild(chain(step[b[0]], seen)); } else { col.appendChild(el('div', { 'class': 'atora-flow-node atora-flow-end', text: U.end })); }
					branches.appendChild(col);
				});
				node.appendChild(branches);
				return list;
			}
			id = step.next;
		}
		list.appendChild(el('li', { 'class': 'atora-flow-node atora-flow-end', text: U.end }));
		return list;
	}

	// ── Pasos ──────────────────────────────────────────────────────────────
	function renderSteps() {
		stepsBox.innerHTML = '';
		def.steps.forEach(function (step) {
			var card = el('fieldset', { 'class': 'atora-flow-step atora-flow-' + step.type });
			var legend = el('legend', {}, [el('code', { text: step.id }), ' ' + (U[step.type] || step.type) + (def.start === step.id ? ' · ' + U.start : '')]);
			card.appendChild(legend);

			if (step.type === 'action') {
				step.action = step.action || { type: 'send_email' };
				card.appendChild(field(U.action, select(L.actions, step.action.type, {}, function (v) { step.action = { type: v }; render(); })));
				actionFields(step.action).forEach(function (f) { card.appendChild(f); });
				card.appendChild(field(U.next, select(targets(step.id), step.next || '', {}, function (v) { step.next = v || null; render(); })));
			} else if (step.type === 'wait') {
				card.appendChild(field(U.minutes, input(step.minutes || '', { type: 'number', min: '0' }, function (v) { step.minutes = parseInt(v, 10) || 0; sync(); })));
				card.appendChild(field(U.until, input(step.until || '', { placeholder: '2026-12-01 09:00' }, function (v) { if (v) { step.until = v; } else { delete step.until; } sync(); })));
				card.appendChild(field(U.untilField, input(step.until_field || '', { placeholder: 'due_at' }, function (v) { if (v) { step.until_field = v; } else { delete step.until_field; } sync(); })));
				card.appendChild(field(U.offset, input(step.offset_minutes || '', { type: 'number' }, function (v) { step.offset_minutes = parseInt(v, 10) || 0; sync(); })));
				card.appendChild(field(U.next, select(targets(step.id), step.next || '', {}, function (v) { step.next = v || null; render(); })));
			} else {
				step.rules = normalizeRules(step.rules);
				var box = el('div');
				renderRules(box, step.rules);
				card.appendChild(box);
				card.appendChild(field(U.yes, select(targets(step.id), step.yes || '', {}, function (v) { step.yes = v || null; render(); })));
				card.appendChild(field(U.no, select(targets(step.id), step.no || '', {}, function (v) { step.no = v || null; render(); })));
			}

			var tools = el('p', { 'class': 'atora-flow-tools' });
			if (def.start !== step.id) {
				tools.appendChild(el('button', { type: 'button', 'class': 'button button-small', text: U.setStart, on: { click: function () { def.start = step.id; render(); } } }));
			}
			tools.appendChild(el('button', { type: 'button', 'class': 'button button-small button-link-delete', text: U.remove, on: { click: function () { removeStep(step.id); } } }));
			card.appendChild(tools);
			stepsBox.appendChild(card);
		});
	}

	function actionFields(a) {
		var out = [];
		function text(key, label, attrs) {
			out.push(field(label, input(a[key], attrs, function (v) { a[key] = v; sync(); })));
		}
		switch (a.type) {
			case 'send_email':
				var opts = {};
				Object.keys(cfg.templates || {}).forEach(function (k) { opts[k] = cfg.templates[k] + ' (' + k + ')'; });
				if (!a.template) { a.template = Object.keys(opts)[0] || ''; }
				out.push(field(U.value, select(opts, a.template, {}, function (v) { a.template = v; render(); })));
				break;
			case 'add_tag':
			case 'remove_tag':
				text('tag', U.value);
				break;
			case 'inbox_message':
			case 'teacher_task':
			case 'internal_notification':
				text('title', 'Título');
				text('message', 'Mensaje');
				break;
			case 'webhook':
				text('url', 'URL', { type: 'url', placeholder: 'https://' });
				text('payload', 'Payload JSON');
				break;
			case 'send_whatsapp':
			case 'send_telegram':
				text('template', U.value);
				break;
			case 'enroll_course':
				text('course_id', 'ID del curso', { type: 'number' });
				break;
			case 'start_email_sequence':
				text('sequence_id', 'ID de la secuencia', { type: 'number' });
				break;
			case 'move_pipeline_stage':
				text('pipeline', 'Embudo (sales / academic)');
				text('stage', 'Etapa');
				break;
			case 'set_custom_field':
				text('field_key', U.field);
				text('value', U.value);
				break;
		}
		return out;
	}

	function renderRules(box, rules) {
		box.innerHTML = '';
		box.appendChild(field('', select({ AND: U.all, OR: U.any }, rules.operator, {}, function (v) { rules.operator = v; sync(); })));
		rules.rules.forEach(function (rule, index) {
			var fieldOpts = Object.assign({}, L.fields);
			if (rule.field && !fieldOpts[rule.field]) { fieldOpts[rule.field] = rule.field; }
			var row = el('div', { 'class': 'atora-flow-rule' }, [
				select(fieldOpts, rule.field, { 'aria-label': U.field }, function (v) { rule.field = v; sync(); }),
				select(L.ops, rule.operator || 'equals', { 'aria-label': U.operator }, function (v) { rule.operator = v; sync(); }),
				input(rule.value, { 'aria-label': U.value, placeholder: U.value }, function (v) { rule.value = v; sync(); }),
				el('button', { type: 'button', 'class': 'button button-small', 'aria-label': U.remove, text: '×', on: { click: function () { rules.rules.splice(index, 1); render(); } } })
			]);
			box.appendChild(row);
		});
		box.appendChild(el('button', { type: 'button', 'class': 'button button-small', text: U.addRule, on: { click: function () {
			rules.rules.push({ field: Object.keys(L.fields)[0] || 'grade', operator: 'equals', value: '' });
			render();
		} } }));
	}

	function removeStep(id) {
		def.steps = def.steps.filter(function (s) { return s.id !== id; });
		def.steps.forEach(function (s) { ['next', 'yes', 'no'].forEach(function (k) { if (s[k] === id) { s[k] = null; } }); });
		if (def.start === id) { def.start = def.steps.length ? def.steps[0].id : null; }
		render();
	}

	form.querySelectorAll('[data-add]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var type = btn.getAttribute('data-add');
			var id = newId(type === 'action' ? 'accion' : (type === 'wait' ? 'espera' : 'cond'));
			var step = { id: id, type: type };
			if (type === 'action') { step.action = { type: 'send_email' }; step.next = null; }
			if (type === 'wait') { step.minutes = 1440; step.next = null; }
			if (type === 'condition') { step.rules = { operator: 'AND', rules: [] }; step.yes = null; step.no = null; }
			// Se engancha al final del último paso que no tenía siguiente.
			var last = def.steps.filter(function (s) { return s.type !== 'condition' && !s.next; }).pop();
			if (last) { last.next = id; }
			def.steps.push(step);
			if (!def.start) { def.start = id; }
			render();
		});
	});

	if (reentry) { reentry.addEventListener('change', sync); }
	defBox.addEventListener('change', function () {
		try { def = normalize(JSON.parse(defBox.value)); render(); } catch (e) { /* JSON a medio escribir: se espera. */ }
	});
	condBox.addEventListener('change', function () {
		try { entry = normalizeRules(JSON.parse(condBox.value)); render(); } catch (e) { /* idem */ }
	});
	form.addEventListener('submit', sync);

	render();
}());
