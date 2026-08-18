/**
 * ATORA LMS — CRM v2 Fase 4
 * crm-contact-360.js
 *
 * Responsabilidades:
 *   1. Búsqueda live de contactos (debounce 400ms, REST GET /contacts/autocomplete)
 *   2. Filtro de estado en la lista lateral (sin recarga)
 *   3. Panel de acciones: 5 handlers REST sin recarga de página
 *      send_email | create_task | save_note | move_stage | add_tag
 *   4. Checkbox de completar tarea → PATCH /tasks/{id}/complete
 *   5. Filtro de timeline por tipo (JS puro sobre items del DOM)
 *   6. Autocomplete de tags en el input de tag
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.28.0
 */
(function () {
    'use strict';

    var cfg   = window.atoraCrmV2 || {};
    var REST  = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
    var NONCE = typeof cfg.nonce === 'string' ? cfg.nonce : '';
    var I18N  = cfg.i18n || {};

    /* ── API helper ─────────────────────────────────────────── */
    function api(path, options) {
        options = options || {};
        var method = options.method || 'GET';
        var url    = REST + '/' + path.replace(/^\/+/, '');
        if (options.params) {
            var qs = Object.keys(options.params)
                .filter(function (k) { return options.params[k] !== '' && options.params[k] !== undefined; })
                .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(options.params[k]); })
                .join('&');
            if (qs) { url += '?' + qs; }
        }
        var headers = { 'X-WP-Nonce': NONCE };
        var body;
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(options.body);
        }
        return fetch(url, { method: method, credentials: 'same-origin', headers: headers, body: body })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) { throw new Error(data.message || ('HTTP ' + res.status)); }
                    return data;
                });
            });
    }

    /* ── Toast (reutiliza el elemento del DOM) ──────────────── */
    var _toastEl    = null;
    var _toastTimer = null;

    function toast(message, type, duration) {
        if (!_toastEl) { _toastEl = document.getElementById('crm-toast'); }
        if (!_toastEl) { return { dismiss: function () {} }; }
        if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
        _toastEl.className   = 'crm-toast crm-toast--' + (type || 'info');
        _toastEl.textContent = message;
        _toastEl.removeAttribute('hidden');
        void _toastEl.offsetWidth;
        _toastEl.classList.add('crm-toast--show');
        if (type !== 'loading') {
            var ms = typeof duration === 'number' ? duration : (type === 'error' ? 5000 : 3200);
            _toastTimer = setTimeout(function () {
                _toastEl.classList.remove('crm-toast--show');
                setTimeout(function () { _toastEl.setAttribute('hidden', ''); }, 300);
            }, ms);
        }
        return { dismiss: function () {
            if (_toastTimer) { clearTimeout(_toastTimer); _toastTimer = null; }
            _toastEl.classList.remove('crm-toast--show');
            setTimeout(function () { _toastEl.setAttribute('hidden', ''); }, 300);
        }};
    }

    /* ── Helpers ─────────────────────────────────────────────── */
    function el(id)   { return document.getElementById(id); }
    function val(id)  { var e = el(id); return e ? (e.value || '').trim() : ''; }
    function setVal(id, v) { var e = el(id); if (e) { e.value = v || ''; } }
    function setBusy(btn, busy, label) {
        if (!btn) { return; }
        if (busy) { btn.dataset.orig = btn.textContent; btn.textContent = label || '…'; btn.disabled = true; }
        else       { btn.textContent = btn.dataset.orig || btn.textContent; btn.disabled = false; }
    }
    function getContactId() {
        var panel = el('crm-actions-panel');
        return panel ? absint(panel.getAttribute('data-contact-id') || '0') : 0;
    }
    function absint(v) { return Math.max(0, parseInt(v, 10) || 0); }

    /* ── 1. Búsqueda live de contactos ───────────────────────── */
    var searchDebounce = null;

    function bindContactSearch() {
        var searchEl = el('crm-contact-search');
        var statusEl = el('crm-status-filter');
        if (!searchEl) { return; }

        function doSearch() {
            var q      = searchEl.value.trim();
            var status = statusEl ? statusEl.value : '';

            if (q.length < 2 && !status) {
                var rows = document.querySelectorAll('.crm-contact-row');
                rows.forEach(function (r) { r.style.display = ''; });
                return;
            }

            api('contacts/autocomplete', { params: { q: q, status: status, limit: 30 } })
                .then(function (data) {
                    renderContactList(data.contacts || []);
                }).catch(function () {});
        }

        searchEl.addEventListener('input', function () {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(doSearch, 400);
        });

        if (statusEl) {
            statusEl.addEventListener('change', doSearch);
        }
    }

    function renderContactList(contacts) {
        var body = el('crm-contact-list-body');
        if (!body) { return; }

        if (contacts.length === 0) {
            body.innerHTML = '<p class="atora-crm-v2-empty atora-crm-v2-empty--mini">' +
                (I18N.noContacts || 'Sin resultados.') + '</p>';
            return;
        }

        var activeId = getContactId();
        body.innerHTML = contacts.map(function (c) {
            var isActive = (c.id === activeId);
            var initials = (c.name || 'X').charAt(0).toUpperCase();
            return '<a class="crm-contact-row' + (isActive ? ' is-active' : '') + '" ' +
                'href="' + escAttr(crmUrl('atora-crm-v2-contacts') + '&contact_id=' + c.id) + '" ' +
                'data-contact-id="' + c.id + '">' +
                '<span class="crm-contact-row__avatar">' + escHtml(initials) + '</span>' +
                '<span class="crm-contact-row__info">' +
                    '<strong>' + escHtml(c.name || '—') + '</strong>' +
                    '<small>' + escHtml(c.email || '—') + '</small>' +
                '</span>' +
                '<span class="crm-contact-row__status crm-status--' + escAttr(c.status || '') + '">' +
                    escHtml((c.status || '').toUpperCase()) +
                '</span></a>';
        }).join('');
    }

    /* ── 2. Dispatch de acciones en el panel derecho ─────────── */
    function bindActionPanel() {
        var panel = el('crm-actions-panel');
        if (!panel) { return; }

        panel.addEventListener('click', function (e) {
            var btn = e.target.closest('.crm-action-btn');
            if (!btn || btn.disabled) { return; }
            var action = btn.getAttribute('data-action');
            
    function handleAISummary(btn) {
        var cid = absint(btn.getAttribute('data-contact-id') || String(getContactId()));
        if (!cid) { return; }
        var output = document.getElementById('crm-ai-summary-output');
        setBusy(btn, true, '…');
        if (output) { output.style.display = 'none'; output.textContent = ''; }
        api('contacts/' + cid + '/ai-summary', { method: 'POST', body: {} })
            .then(function(data) {
                if (output) {
                    output.textContent = data.summary || data.message || 'Sin resumen disponible.';
                    output.style.display = 'block';
                }
                toast(I18N.saved || 'Resumen generado.', 'success');
            }).catch(function(err) {
                toast(err.message || I18N.error || 'Error al generar resumen.', 'error');
            }).finally(function() { setBusy(btn, false); });
    }

    function handleStopSequence(btn) {
        var seqId = absint(btn.getAttribute('data-sequence-id') || '0');
        var cid   = absint(btn.getAttribute('data-contact-id') || String(getContactId()));
        if (!seqId || !cid) { return; }
        if (!window.confirm(I18N.confirmStopSeq || '¿Detener esta secuencia para el contacto?')) { return; }
        setBusy(btn, true, '...');
        var t = toast(I18N.stopping || 'Deteniendo secuencia…', 'loading');
        api('sequences/' + seqId + '/stop', { method: 'POST', body: { contact_id: cid } })
            .then(function(data) {
                t.dismiss();
                toast(data.message || I18N.saved || 'Secuencia detenida.', 'success');
                btn.closest('.crm-360-seq-item') && btn.closest('.crm-360-seq-item').remove();
            }).catch(function(err) {
                t.dismiss();
                toast(err.message || I18N.error, 'error');
            }).finally(function() { setBusy(btn, false); });
    }

    var handlers = {
                send_email:  handleSendEmail,
                create_task: handleCreateTask,
                save_note:   handleSaveNote,
                move_stage:  handleMoveStage,
                add_tag:       handleAddTag,
            stop_sequence: handleStopSequence,
            ai_summary:    handleAISummary,
            };
            if (handlers[action]) {
                e.preventDefault();
                handlers[action](btn);
            }
        });
    }

    function handleSendEmail(btn) {
        var cid      = getContactId();
        var subject  = val('crm-email-subject');
        var message  = val('crm-email-message');
        var identity = val('crm-email-identity') || 'teacher';
        var ctaUrl   = val('crm-email-cta');

        if (!subject) { toast(I18N.subjectRequired || 'El asunto es obligatorio.', 'error'); return; }
        if (!message) { toast(I18N.messageRequired  || 'El mensaje está vacío.', 'error');   return; }

        setBusy(btn, true, I18N.sending || 'Enviando…');
        var t = toast(I18N.sending || 'Enviando email…', 'loading');

        api('contacts/' + cid + '/email', {
            method: 'POST',
            body:   { subject: subject, message: message, identity: identity, cta_url: ctaUrl },
        }).then(function (data) {
            t.dismiss();
            toast(data.message || I18N.saved || 'Email encolado.', 'success');
            setVal('crm-email-subject', '');
            setVal('crm-email-message', '');
            setVal('crm-email-cta', '');
            refreshTimeline(cid);
        }).catch(function (err) {
            t.dismiss();
            toast(err.message || I18N.error, 'error');
        }).finally(function () { setBusy(btn, false); });
    }

    function handleCreateTask(btn) {
        var cid      = getContactId();
        var title    = val('crm-task-title');
        var taskType = val('crm-task-type');
        var priority = val('crm-task-priority');
        var dueAt    = val('crm-task-due');
        var notes    = val('crm-task-notes');

        if (!title) { toast(I18N.titleRequired || 'El título es obligatorio.', 'error'); return; }

        setBusy(btn, true, I18N.creating || 'Creando…');
        var t = toast(I18N.creating || 'Creando tarea…', 'loading');

        api('tasks', {
            method: 'POST',
            body:   { title: title, task_type: taskType, priority: priority, due_at: dueAt, notes: notes, contact_id: cid },
        }).then(function (data) {
            t.dismiss();
            toast(data.message || I18N.saved || 'Tarea creada.', 'success');
            setVal('crm-task-title', '');
            setVal('crm-task-notes', '');
            setVal('crm-task-due', '');
            refreshTimeline(cid);
        }).catch(function (err) {
            t.dismiss();
            toast(err.message || I18N.error, 'error');
        }).finally(function () { setBusy(btn, false); });
    }

    function handleSaveNote(btn) {
        var cid  = getContactId();
        var note = val('crm-note-text');
        if (!note) { toast(I18N.noteRequired || 'La nota no puede estar vacía.', 'error'); return; }

        setBusy(btn, true, I18N.saving || 'Guardando…');
        var t = toast(I18N.saving || 'Guardando nota…', 'loading');

        api('contacts/' + cid + '/note', {
            method: 'POST',
            body:   { note: note },
        }).then(function (data) {
            t.dismiss();
            toast(data.message || I18N.saved || 'Nota guardada.', 'success');
            setVal('crm-note-text', '');
            prependNoteToDOM(note);
            refreshTimeline(cid);
        }).catch(function (err) {
            t.dismiss();
            toast(err.message || I18N.error, 'error');
        }).finally(function () { setBusy(btn, false); });
    }

    function handleMoveStage(btn) {
        var cid      = getContactId();
        var pipeline = val('crm-stage-pipeline') || 'sales';
        var stage    = val('crm-stage-target');
        if (!stage) { toast(I18N.stageRequired || 'Selecciona la etapa de destino.', 'error'); return; }

        setBusy(btn, true, I18N.moving || 'Moviendo…');
        var t = toast(I18N.moving || 'Actualizando etapa…', 'loading');

        api('contacts/' + cid + '/stage', {
            method: 'POST',
            body:   { pipeline: pipeline, stage: stage },
        }).then(function (data) {
            t.dismiss();
            toast(data.message || I18N.saved || 'Etapa actualizada.', 'success');
            refreshTimeline(cid);
        }).catch(function (err) {
            t.dismiss();
            toast(err.message || I18N.error, 'error');
        }).finally(function () { setBusy(btn, false); });
    }

    function handleAddTag(btn) {
        var cid = getContactId();
        var tag = val('crm-tag-input');
        if (!tag) { toast(I18N.tagRequired || 'Escribe un tag.', 'error'); return; }

        setBusy(btn, true, I18N.saving || 'Añadiendo…');
        var t = toast(I18N.saving || 'Añadiendo tag…', 'loading');

        api('contacts/' + cid + '/tag', {
            method: 'POST',
            body:   { tag: tag },
        }).then(function (data) {
            t.dismiss();
            toast(data.message || I18N.saved || 'Tag añadido.', 'success');
            setVal('crm-tag-input', '');
            updateTagsDOM(data.tags || []);
        }).catch(function (err) {
            t.dismiss();
            toast(err.message || I18N.error, 'error');
        }).finally(function () { setBusy(btn, false); });
    }

    /* ── 3. Completar tarea desde checkbox ───────────────────── */
    function bindTaskComplete() {
        var tasksList = el('crm-tasks-list');
        if (!tasksList) { return; }

        tasksList.addEventListener('change', function (e) {
            var cb = e.target;
            if (!cb.classList.contains('crm-task-complete-cb')) { return; }
            var taskId = absint(cb.getAttribute('data-task-id') || '0');
            if (!taskId) { return; }

            var t = toast(I18N.completing || 'Completando tarea…', 'loading');
            api('tasks/' + taskId + '/complete', { method: 'POST', body: {} })
                .then(function (data) {
                    t.dismiss();
                    toast(data.message || I18N.saved || 'Tarea completada.', 'success', 2000);
                    var li = cb.closest('.crm-task-item');
                    if (li) { li.classList.add('is-done'); }
                }).catch(function (err) {
                    t.dismiss();
                    toast(err.message || I18N.error, 'error');
                    cb.checked = false;
                });
        });
    }

    /* ── 4. Filtro de timeline por tipo (JS puro) ────────────── */
    function bindTimelineFilter() {
        var filterEl   = el('crm-timeline-filter');
        var timelineEl = el('crm-timeline-list');
        if (!filterEl || !timelineEl) { return; }

        filterEl.addEventListener('change', function () {
            var type  = filterEl.value;
            var items = timelineEl.querySelectorAll('.crm-360-timeline__item');
            items.forEach(function (item) {
                if (!type) {
                    item.style.display = '';
                } else {
                    item.style.display = item.classList.contains('crm-tl--' + type) ? '' : 'none';
                }
            });
        });
    }

    /* ── 5. Autocomplete de tags ─────────────────────────────── */
    function bindTagAutocomplete() {
        var tagInput   = el('crm-tag-input');
        var tagList    = el('crm-tag-datalist');
        if (!tagInput || !tagList) { return; }

        var tagDebounce = null;
        tagInput.addEventListener('input', function () {
            clearTimeout(tagDebounce);
            tagDebounce = setTimeout(function () {
                var q = tagInput.value.trim();
                if (q.length < 1) { return; }
                api('contacts/tags/suggest', { params: { q: q } })
                    .then(function (data) {
                        tagList.innerHTML = (data.tags || []).map(function (t) {
                            return '<option value="' + escAttr(t) + '">';
                        }).join('');
                    }).catch(function () {});
            }, 350);
        });
    }

    /* ── 6. Pipeline select: filtrar opciones por tipo ───────── */
    function bindPipelineSelect() {
        var pipelineEl = el('crm-stage-pipeline');
        var stageEl    = el('crm-stage-target');
        if (!pipelineEl || !stageEl) { return; }

        function updateStageOptions() {
            var pipeline = pipelineEl.value;
            stageEl.querySelectorAll('option').forEach(function (opt) {
                var optPipeline = opt.getAttribute('data-pipeline') || '';
                if (!optPipeline) { return; }
                opt.hidden = (optPipeline !== pipeline);
            });
            stageEl.querySelectorAll('optgroup').forEach(function (grp) {
                var grpPipeline = grp.getAttribute('data-pipeline') || '';
                grp.hidden = (grpPipeline !== pipeline);
            });
            var firstVisible = stageEl.querySelector('option:not([hidden])');
            if (firstVisible) { stageEl.value = firstVisible.value; }
        }

        pipelineEl.addEventListener('change', updateStageOptions);
        updateStageOptions();
    }

    /* ── Helpers de DOM reactivo ─────────────────────────────── */
    function refreshTimeline(contactId) {
        var timelineEl = el('crm-timeline-list');
        if (!timelineEl || !contactId) { return; }

        api('contacts/' + contactId + '/timeline', { params: { limit: 15 } })
            .then(function (data) {
                var items = data.items || [];
                if (items.length === 0) {
                    timelineEl.innerHTML = '<li class="atora-crm-v2-empty atora-crm-v2-empty--mini">' +
                        (I18N.noActivity || 'Sin actividad registrada.') + '</li>';
                    return;
                }
                timelineEl.innerHTML = items.map(function (item) {
                    return '<li class="crm-360-timeline__item crm-tl--' + escAttr(item.activity_type) + '">' +
                        '<span class="crm-360-timeline__dot"></span>' +
                        '<div><strong>' + escHtml(item.activity_type) + '</strong>' +
                        '<small>' + escHtml(item.created_at) + '</small></div></li>';
                }).join('');
            }).catch(function () {});
    }

    function prependNoteToDOM(noteText) {
        var notesList = el('crm-notes-list');
        if (!notesList) { return; }
        var li = document.createElement('li');
        li.className = 'crm-360-note';
        var now = new Date().toISOString().replace('T', ' ').substr(0, 19);
        li.innerHTML = '<p>' + escHtml(noteText) + '</p><small>' + escHtml(now) + '</small>';
        notesList.insertBefore(li, notesList.firstChild);
    }

    function updateTagsDOM(tags) {
        var tagsWrap = el('crm-contact-tags');
        if (!tagsWrap) { return; }
        if (tags.length === 0) {
            tagsWrap.innerHTML = '<span class="atora-crm-v2-muted">' +
                (I18N.noTags || 'Sin etiquetas') + '</span>';
            return;
        }
        tagsWrap.innerHTML = tags.map(function (t) {
            return '<span class="crm-tag-pill">' + escHtml(t) + '</span>';
        }).join('');
    }

    /* ── Utilidades ──────────────────────────────────────────── */
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }
    function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#039;'); }
    function crmUrl(page) {
        return (cfg.adminUrl || '') + 'admin.php?page=' + encodeURIComponent(page);
    }

    /* ── Init ────────────────────────────────────────────────── */
    function init() {
        bindContactSearch();
        bindActionPanel();
        bindTaskComplete();
        bindTimelineFilter();
        bindTagAutocomplete();
        bindPipelineSelect();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
