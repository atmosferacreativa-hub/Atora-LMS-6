(function () {
  'use strict';

  var pendingChanges = {};
  var nonce = (window.clmsGradebook && window.clmsGradebook.nonce) ? window.clmsGradebook.nonce : '';
  var restBase = (window.clmsGradebook && window.clmsGradebook.rest_url) ? window.clmsGradebook.rest_url : '/wp-json/clms/v1/';
  var courseId = (window.clmsGradebook && window.clmsGradebook.course_id) ? window.clmsGradebook.course_id : 0;

  /* ── Export CSV ─────────────────────────────────────────────────────── */
  document.addEventListener('click', function (event) {
    var exportBtn = event.target.closest('[data-clms-gradebook-export="csv"]');
    if (!exportBtn) return;
    event.preventDefault();

    if (!courseId) { alert('Selecciona un curso antes de exportar.'); return; }

    var params = new URLSearchParams(window.location.search);
    var query = 'cohort_id=' + (params.get('cohort_id') || '0')
      + '&student_search=' + encodeURIComponent(params.get('student_search') || '')
      + '&activity_search=' + encodeURIComponent(params.get('activity_search') || '')
      + '&status=' + encodeURIComponent(params.get('status') || '');

    exportBtn.textContent = 'Exportando…';
    exportBtn.disabled = true;

    fetch(restBase + 'gradebook/export/' + courseId + '?' + query, {
      headers: { 'X-WP-Nonce': nonce }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.csv) {
        var blob = new Blob(["﻿" + data.csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'gradebook-' + courseId + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      } else {
        alert('Error al generar el CSV.');
      }
      exportBtn.textContent = 'Exportar';
      exportBtn.disabled = false;
    })
    .catch(function () {
      alert('Error de red al exportar.');
      exportBtn.textContent = 'Exportar';
      exportBtn.disabled = false;
    });
  });

  /* ── Edición de celdas ──────────────────────────────────────────────── */
  document.addEventListener('dblclick', function (event) {
    var cell = event.target.closest('[data-clms-cell]');
    if (!cell || cell.dataset.clmsCellLocked) return;

    var current = cell.querySelector('.clms-gradebook-cell__main');
    if (!current || cell.querySelector('.clms-cell-input')) return;

    var currentVal = current.dataset.grade || '';
    var input = document.createElement('input');
    input.type = 'number';
    input.min = '0';
    input.max = cell.dataset.clmsMaxPoints || '100';
    input.step = '1';
    input.value = currentVal;
    input.className = 'clms-cell-input';
    input.style.cssText = 'width:60px;font-size:13px;text-align:center;padding:2px 4px;';

    current.style.display = 'none';
    cell.insertBefore(input, current);
    input.focus();
    input.select();

    function commitEdit() {
      var val = input.value.trim();
      var key = cell.dataset.clmsCell;
      current.style.display = '';
      if (cell.contains(input)) cell.removeChild(input);

      if (val === currentVal) return;

      pendingChanges[key] = {
        student_id: parseInt(cell.dataset.clmsStudentId, 10),
        lesson_id: parseInt(cell.dataset.clmsLessonId, 10),
        submission_id: parseInt(cell.dataset.clmsSubmissionId || '0', 10),
        grade: val !== '' ? parseInt(val, 10) : '',
        _prev: currentVal
      };

      current.textContent = val !== '' ? val + '%' : '—';
      current.dataset.grade = val;
      cell.classList.add('is-dirty');
      updateSaveBar();
    }

    input.addEventListener('blur', commitEdit);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
      if (e.key === 'Escape') {
        input.value = currentVal;
        cell.classList.remove('is-dirty');
        commitEdit();
      }
    });
  });

  /* ── Barra de guardado ──────────────────────────────────────────────── */
  function updateSaveBar() {
    var bar = document.getElementById('clms-gradebook-save-bar');
    var count = Object.keys(pendingChanges).length;

    if (!bar) {
      bar = document.createElement('div');
      bar.id = 'clms-gradebook-save-bar';
      bar.style.cssText = 'position:fixed;bottom:0;left:0;right:0;background:#1f2933;color:#fff;padding:10px 20px;display:flex;align-items:center;gap:12px;z-index:9999;font-size:13px;';
      document.body.appendChild(bar);
    }

    if (count === 0) {
      bar.style.display = 'none';
      return;
    }

    bar.style.display = 'flex';
    bar.innerHTML =
      '<span id="clms-save-count">' + count + ' cambio' + (count === 1 ? '' : 's') + ' pendiente' + (count === 1 ? '' : 's') + '</span>' +
      '<button id="clms-save-btn" style="background:#3f98ee;color:#fff;border:none;border-radius:4px;padding:5px 14px;cursor:pointer;font-size:13px;">Guardar cambios</button>' +
      '<button id="clms-discard-btn" style="background:transparent;color:#d0d8e0;border:1px solid #4a5568;border-radius:4px;padding:5px 12px;cursor:pointer;font-size:13px;">Descartar</button>' +
      '<span id="clms-save-status"></span>';

    document.getElementById('clms-save-btn').addEventListener('click', saveChanges);
    document.getElementById('clms-discard-btn').addEventListener('click', discardChanges);
  }

  function saveChanges() {
    var updates = Object.values(pendingChanges);
    if (!updates.length) return;

    if (!courseId) { alert('No se detectó el ID del curso.'); return; }

    setSaveStatus('guardando', 'Guardando…');

    fetch(restBase + 'gradebook/batch-update', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: JSON.stringify({ course_id: courseId, updates: updates })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && typeof data.saved !== 'undefined') {
        setSaveStatus('saved', data.saved + ' guardado' + (data.saved === 1 ? '' : 's'));
        if (data.failed > 0) {
          setSaveStatus('error', data.saved + ' guardados, ' + data.failed + ' error' + (data.failed === 1 ? '' : 'es'));
        }

        if (data.results) {
          data.results.forEach(function (r) {
            var key = r.student_id + '_' + r.lesson_id;
            if (r.success) {
              delete pendingChanges[key];
              var cell = document.querySelector('[data-clms-cell="' + key + '"]');
              if (cell) cell.classList.remove('is-dirty');
            }
          });
        }

        if (!Object.keys(pendingChanges).length) {
          setTimeout(function () {
            var bar = document.getElementById('clms-gradebook-save-bar');
            if (bar) bar.style.display = 'none';
          }, 1500);
        } else {
          updateSaveBar();
        }
      } else {
        setSaveStatus('error', 'Error al guardar');
      }
    })
    .catch(function () {
      setSaveStatus('error', 'Error de red');
    });
  }

  function discardChanges() {
    Object.keys(pendingChanges).forEach(function (key) {
      var change = pendingChanges[key];
      var cell = document.querySelector('[data-clms-cell="' + key + '"]');
      if (cell) {
        var main = cell.querySelector('.clms-gradebook-cell__main');
        if (main) {
          var prev = change._prev;
          main.textContent = prev !== '' ? prev + '%' : '—';
          main.dataset.grade = prev;
        }
        cell.classList.remove('is-dirty');
      }
    });
    pendingChanges = {};
    updateSaveBar();
  }

  function setSaveStatus(state, text) {
    var el = document.getElementById('clms-save-status');
    if (el) {
      el.textContent = text;
      el.style.color = state === 'error' ? '#f08080' : state === 'saved' ? '#6ee7b7' : '#d0d8e0';
    }
  }

  /* ── Panel de celda (Sprint 10) ─────────────────────────────────────── */
  document.addEventListener('click', function (event) {
    var cellBtn = event.target.closest('[data-clms-cell-detail]');
    if (!cellBtn) return;
    event.preventDefault();

    var submissionId = cellBtn.dataset.clmsSubmissionId || 0;
    var lessonId = cellBtn.dataset.clmsLessonId || 0;
    var studentId = cellBtn.dataset.clmsStudentId || 0;

    fetch(restBase + 'gradebook/cell-detail?submission_id=' + submissionId
      + '&lesson_id=' + lessonId + '&student_id=' + studentId + '&course_id=' + courseId, {
      headers: { 'X-WP-Nonce': nonce }
    })
    .then(function (r) { return r.json(); })
    .then(function (data) { showCellPanel(data); })
    .catch(function () {});
  });

  function showCellPanel(data) {
    var existing = document.getElementById('clms-cell-panel');
    if (existing) existing.remove();

    var panel = document.createElement('div');
    panel.id = 'clms-cell-panel';
    panel.style.cssText = 'position:fixed;top:0;right:0;bottom:0;width:340px;background:#fff;border-left:1px solid #d8dee8;z-index:10000;overflow-y:auto;padding:16px;box-shadow:-4px 0 16px rgba(0,0,0,0.1);';

    var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">'
      + '<strong style="font-size:14px;">Detalle de entrega</strong>'
      + '<button id="clms-cell-panel-close" style="background:none;border:none;cursor:pointer;font-size:18px;color:#64748b;">×</button>'
      + '</div>';

    if (!data || !data.found) {
      html += '<p style="color:#64748b;">No hay entrega registrada para esta actividad.</p>';
    } else {
      var grade = data.grade !== '' ? data.grade + '%' : '—';
      var status = data.status || '—';
      html += '<p><strong>Nota:</strong> ' + grade + '</p>'
        + '<p><strong>Estado:</strong> ' + status + '</p>';
      if (data.feedback) {
        html += '<p><strong>Retroalimentación:</strong><br>' + data.feedback + '</p>';
      }
      if (data.speedgrade_url) {
        html += '<a href="' + data.speedgrade_url + '" class="button button-primary" style="margin-top:8px;display:inline-block;">Abrir en SpeedGrade</a>';
      }
    }

    panel.innerHTML = html;
    document.body.appendChild(panel);
    panel.querySelector('#clms-cell-panel-close').addEventListener('click', function () { panel.remove(); });
  }

})();
