/**
 * ATORA LMS — CRM v2 Fase 1
 * crm-v2.js
 *
 * Responsabilidades:
 *   1. API helper centralizado (fetch + nonce + error handling)
 *   2. Sistema de toast (éxito / error / info / carga)
 *   3. Recolector de estado del formulario (readFormState)
 *   4. Manejadores por acción, todos sin recarga de página:
 *      save_draft | reset_draft | save_segment | apply_segment |
 *      delete_segment | preview | run_bulk_action | launch_campaign
 *   5. Actualización reactiva de KPIs y tabla de muestra tras cada acción
 *   6. Exposición de window.atoraCrmV2Api para compatibilidad con crm-kanban.js
 *
 * @package ATORA_LMS\CRM_V2
 * @since   5.22.0
 */
(function () {
	'use strict';

	/* ============================================================
	 *  CONFIG — provisto por wp_localize_script('atoraCrmV2', ...)
	 * ============================================================ */
	var cfg    = window.atoraCrmV2 || {};
	var REST   = (typeof cfg.restBase === 'string' ? cfg.restBase : '').replace(/\/+$/, '');
	var NONCE  = typeof cfg.nonce === 'string' ? cfg.nonce : '';
	var I18N   = cfg.i18n || {};

	/* ============================================================
	 *  API HELPER
	 * ============================================================ */
	function api(path, body) {
		if (!REST) {
			return Promise.reject(new Error('REST base no disponible'));
		}
		return fetch(REST + '/' + path.replace(/^\/+/, ''), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
			},
			body: JSON.stringify(body || {}),
		}).then(function (res) {
			return res.json().then(function (data) {
				if (!res.ok) {
					throw new Error(data.message || ('HTTP ' + res.status));
				}
				return data;
			});
		});
	}

	/* ============================================================
	 *  TOAST
	 *
	 *  Tipos: 'success' | 'error' | 'info' | 'loading'
	 *  Para 'loading' la toast no cierra automáticamente;
	 *  llamar toast.dismiss() cuando resuelva la promesa.
	 * ============================================================ */
	var toastEl  = null;
	var toastTimer = null;

	function toast(message, type, duration) {
		if (!toastEl) {
			toastEl = document.getElementById('crm-toast');
		}
		if (!toastEl) { return { dismiss: function() {} }; }

		if (toastTimer) {
			clearTimeout(toastTimer);
			toastTimer = null;
		}

		toastEl.className    = 'crm-toast crm-toast--' + (type || 'info');
		toastEl.textContent  = message;
		toastEl.removeAttribute('hidden');

		// forzar reflow para reiniciar la animación
		void toastEl.offsetWidth;
		toastEl.classList.add('crm-toast--show');

		if (type !== 'loading') {
			var ms = typeof duration === 'number' ? duration : (type === 'error' ? 5000 : 3500);
			toastTimer = setTimeout(function () {
				toastEl.classList.remove('crm-toast--show');
				setTimeout(function () { toastEl.setAttribute('hidden', ''); }, 300);
			}, ms);
		}

		return {
			dismiss: function () {
				if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }
				toastEl.classList.remove('crm-toast--show');
				setTimeout(function () { toastEl.setAttribute('hidden', ''); }, 300);
			},
		};
	}

	/* ============================================================
	 *  ESTADO DEL FORMULARIO
	 *
	 *  Lee todos los .crm-field del DOM y construye el objeto
	 *  crm_v2 que el REST controller espera.
	 * ============================================================ */
	function readFormState() {
		var state = {};
		var fields = document.querySelectorAll('.crm-field');
		fields.forEach(function (el) {
			var name = el.getAttribute('name');
			if (!name) { return; }
			if (el.type === 'checkbox') {
				state[name] = el.checked ? '1' : '';
			} else {
				state[name] = el.value || '';
			}
		});
		return state;
	}

	/* ============================================================
	 *  ACTUALIZACIÓN REACTIVA DE UI
	 * ============================================================ */

	/** Reescribe los KPIs del segmento con los datos devueltos por la API */
	function updateStats(preview) {
		if (!preview) { return; }
		var stats = preview.stats || {};
		var set = function (id, val) {
			var el = document.getElementById(id);
			if (el) { el.textContent = String(Number(val) || 0); }
		};
		set('crm-stat-total',  preview.total || 0);
		set('crm-stat-email',  stats.email_ready     || 0);
		set('crm-stat-msg',    stats.messaging_ready  || 0);
		set('crm-stat-linked', stats.linked_users     || 0);
	}

	/** Reconstruye la tabla de muestra del segmento */
	function updatePreviewTable(preview) {
		var wrap = document.getElementById('crm-preview-body');
		if (!wrap) { return; }

		var contacts = (preview && Array.isArray(preview.contacts)) ? preview.contacts : [];

		if (contacts.length === 0) {
			wrap.innerHTML = '<p class="atora-crm-v2__empty">' +
				(I18N.noContacts || 'Sin contactos para la configuración actual.') + '</p>';
			return;
		}

		var rows = contacts.map(function (row) {
			var name     = escHtml(row.name || '(Sin nombre)');
			var email    = escHtml(row.email || row.phone || '—');
			var status   = escHtml(row.status || '');
			var tags     = Array.isArray(row.tags) ? escHtml(row.tags.slice(0, 4).join(', ')) : 'Sin tags';
			var chs      = row.channels || {};
			var chList   = [];
			if (chs.email)    { chList.push('Email'); }
			if (chs.whatsapp) { chList.push('WhatsApp'); }
			if (chs.telegram) { chList.push('Telegram'); }
			var chStr    = chList.length ? escHtml(chList.join(' · ')) : 'No disponible';
			var updatedAt = escHtml(row.updated_at || '—');

			return '<tr>' +
				'<td><strong>' + name + '</strong><br><small>' + email + '</small></td>' +
				'<td><span class="atora-crm-v2__badge">' + status + '</span></td>' +
				'<td>' + tags + '</td>' +
				'<td>' + chStr + '</td>' +
				'<td>' + updatedAt + '</td>' +
				'</tr>';
		}).join('');

		wrap.innerHTML = '<table class="widefat striped atora-crm-v2__table">' +
			'<thead><tr>' +
			'<th>Contacto</th><th>Estado</th><th>Tags</th><th>Canales</th><th>Actualizado</th>' +
			'</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	/** Actualiza el select de segmentos guardados */
	function updateSegmentSelect(segments) {
		var sel = document.getElementById('crm-saved-segment');
		if (!sel || !segments) { return; }

		// conservar primera opción
		var first = sel.options[0];
		sel.innerHTML = '';
		sel.appendChild(first.cloneNode(true));

		Object.keys(segments).forEach(function (id) {
			var opt = document.createElement('option');
			opt.value       = id;
			opt.textContent = segments[id].name || id;
			sel.appendChild(opt);
		});
	}

	/** Aplica el draft devuelto por reset/apply al formulario */
	function applyDraftToForm(draft) {
		if (!draft) { return; }
		Object.keys(draft).forEach(function (key) {
			var el = document.querySelector('.crm-field[name="' + key + '"]');
			if (!el) { return; }
			if (el.type === 'checkbox') {
				el.checked = !!draft[key];
			} else {
				el.value = draft[key] !== undefined ? String(draft[key]) : '';
			}
		});
	}

	/* ============================================================
	 *  BOTONES CON ESTADO DE CARGA (spinner textual)
	 * ============================================================ */
	function setButtonBusy(btn, busy) {
		if (!btn) { return; }
		if (busy) {
			btn.dataset.originalText = btn.textContent;
			btn.textContent = I18N.loading || 'Procesando…';
			btn.disabled    = true;
		} else {
			btn.textContent = btn.dataset.originalText || btn.textContent;
			btn.disabled    = false;
		}
	}

	/* ============================================================
	 *  MANEJADORES DE ACCIONES
	 * ============================================================ */

	function handleSaveDraft(btn) {
		setButtonBusy(btn, true);
		var t = toast(I18N.saving || 'Guardando borrador…', 'loading');
		api('draft/save', { crm_v2: readFormState() })
			.then(function (data) {
				t.dismiss();
				toast(data.message || I18N.saved || 'Borrador guardado.', 'success');
				updateStats(data.preview);
				updatePreviewTable(data.preview);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error || 'No fue posible guardar.', 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handleResetDraft(btn) {
		if (!window.confirm(I18N.confirmReset || '¿Restaurar la configuración base? Perderás el borrador actual.')) { return; }
		setButtonBusy(btn, true);
		var t = toast(I18N.resetting || 'Restaurando…', 'loading');
		api('draft/reset', {})
			.then(function (data) {
				t.dismiss();
				toast(data.message || 'Borrador restaurado.', 'success');
				if (data.draft) { applyDraftToForm(data.draft); }
				updateStats(data.preview);
				updatePreviewTable(data.preview);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handleSaveSegment(btn) {
		var nameEl = document.getElementById('crm-segment-name');
		var name   = nameEl ? nameEl.value.trim() : '';
		if (!name) {
			toast(I18N.segmentNameRequired || 'Indica un nombre para el segmento.', 'error');
			if (nameEl) { nameEl.focus(); }
			return;
		}
		setButtonBusy(btn, true);
		var t = toast(I18N.saving || 'Guardando segmento…', 'loading');
		api('draft/segment/save', { crm_v2: Object.assign(readFormState(), { segment_name: name }) })
			.then(function (data) {
				t.dismiss();
				toast(data.message || 'Segmento guardado.', 'success');
				updateSegmentSelect(data.segments);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handleApplySegment(btn) {
		var sel = document.getElementById('crm-saved-segment');
		var id  = sel ? sel.value : '';
		if (!id) {
			toast(I18N.segmentSelectRequired || 'Selecciona un segmento para aplicar.', 'error');
			return;
		}
		setButtonBusy(btn, true);
		var t = toast(I18N.applying || 'Aplicando segmento…', 'loading');
		api('draft/segment/apply', { segment_id: id })
			.then(function (data) {
				t.dismiss();
				toast(data.message || 'Segmento aplicado.', 'success');
				if (data.draft) { applyDraftToForm(data.draft); }
				updateStats(data.preview);
				updatePreviewTable(data.preview);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handleDeleteSegment(btn) {
		var sel = document.getElementById('crm-saved-segment');
		var id  = sel ? sel.value : '';
		if (!id) {
			toast(I18N.segmentSelectRequired || 'Selecciona un segmento para eliminar.', 'error');
			return;
		}
		if (!window.confirm(I18N.confirmDelete || '¿Eliminar el segmento seleccionado?')) { return; }
		setButtonBusy(btn, true);
		var t = toast(I18N.deleting || 'Eliminando…', 'loading');
		api('draft/segment/delete', { segment_id: id })
			.then(function (data) {
				t.dismiss();
				toast(data.message || 'Segmento eliminado.', 'success');
				updateSegmentSelect(data.segments);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handlePreview(btn) {
		setButtonBusy(btn, true);
		var t = toast(I18N.refreshing || 'Actualizando muestra…', 'loading');
		api('draft/preview', { crm_v2: readFormState() })
			.then(function (data) {
				t.dismiss();
				updateStats(data.preview);
				updatePreviewTable(data.preview);
				toast(I18N.previewUpdated || 'Muestra actualizada.', 'info', 2000);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handleBulkAction(btn) {
		var actionEl = document.getElementById('crm-bulk-action');
		var action   = actionEl ? actionEl.value : 'none';
		if (action === 'none') {
			toast(I18N.bulkSelectRequired || 'Selecciona una acción masiva.', 'error');
			return;
		}
		var label = actionEl ? (actionEl.options[actionEl.selectedIndex] || {}).text : action;
		if (!window.confirm(
			(I18N.confirmBulk || '¿Ejecutar la acción masiva "%s" sobre el segmento actual?').replace('%s', label)
		)) { return; }

		setButtonBusy(btn, true);
		var t = toast(I18N.running || 'Ejecutando acción masiva…', 'loading');
		api('draft/bulk', { crm_v2: readFormState() })
			.then(function (data) {
				t.dismiss();
				toast(data.message || 'Acción ejecutada.', 'success');
				updateStats(data.preview);
				updatePreviewTable(data.preview);
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	function handleLaunchCampaign(btn) {
		var nameEl = document.getElementById('crm-campaign-name');
		var name   = nameEl ? nameEl.value.trim() : '';
		if (!name) {
			toast(I18N.campaignNameRequired || 'Indica el nombre interno de la campaña antes de ejecutar.', 'error');
			if (nameEl) { nameEl.focus(); }
			return;
		}
		if (!window.confirm(I18N.confirmCampaign || '¿Lanzar la campaña sobre el segmento actual?')) { return; }

		setButtonBusy(btn, true);
		var t = toast(I18N.launching || 'Lanzando campaña…', 'loading');
		api('draft/campaign', { crm_v2: readFormState() })
			.then(function (data) {
				t.dismiss();
				toast(data.message || 'Campaña lanzada.', 'success');
				// Actualizar historial de campañas si viene en la respuesta
				if (data.campaigns) { renderCampaignsHistory(data.campaigns); }
			})
			.catch(function (err) {
				t.dismiss();
				toast(err.message || I18N.error, 'error');
			})
			.finally(function () { setButtonBusy(btn, false); });
	}

	/** Repinta el historial de campañas (respuesta de launch_campaign) */
	function renderCampaignsHistory(campaigns) {
		var wrap = document.getElementById('crm-campaigns-body');
		if (!wrap || !Array.isArray(campaigns)) { return; }

		if (campaigns.length === 0) {
			wrap.innerHTML = '<p class="atora-crm-v2__empty">' +
				(I18N.noCampaigns || 'Aún no hay campañas registradas.') + '</p>';
			return;
		}

		var rows = campaigns.map(function (c) {
			return '<tr>' +
				'<td><strong>' + escHtml(c.name || c.id || '') + '</strong><br>' +
				'<small>' + escHtml(c.subject || c.preview || '') + '</small></td>' +
				'<td>' + escHtml(c.channel ? capitalize(c.channel) : 'Email') + '</td>' +
				'<td>' + escHtml(c.status || '') + '</td>' +
				'<td>Total: ' + Number(c.contacts_total || 0) +
				' · Email: ' + Number(c.queued_email || 0) +
				' · Msg: ' + Number(c.queued_message || 0) + '</td>' +
				'<td>' + escHtml(c.created_at || '') + '</td>' +
				'</tr>';
		}).join('');

		wrap.innerHTML = '<table class="widefat striped atora-crm-v2__table">' +
			'<thead><tr><th>Campaña</th><th>Canal</th><th>Estado</th><th>Impacto</th><th>Fecha</th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table>';
	}

	/* ============================================================
	 *  DISPATCH — router de botones
	 * ============================================================ */
	var actionHandlers = {
		save_draft:       handleSaveDraft,
		reset_draft:      handleResetDraft,
		save_segment:     handleSaveSegment,
		apply_segment:    handleApplySegment,
		delete_segment:   handleDeleteSegment,
		preview:          handlePreview,
		run_bulk_action:  handleBulkAction,
		launch_campaign:  handleLaunchCampaign,
	};

	function bindButtons() {
		var root = document.getElementById('atora-crm-v2-segmentor');
		if (!root) { return; }

		root.addEventListener('click', function (e) {
			var btn = e.target.closest('.crm-action-btn');
			if (!btn || btn.disabled) { return; }
			var action = btn.getAttribute('data-action');
			if (!action) { return; }
			var handler = actionHandlers[action];
			if (typeof handler === 'function') {
				e.preventDefault();
				handler(btn);
			}
		});
	}

	/* ============================================================
	 *  PREVIEW EN TIEMPO REAL (debounce en campos de segmento)
	 * ============================================================ */
	var previewDebounce = null;

	function schedulePreview() {
		clearTimeout(previewDebounce);
		previewDebounce = setTimeout(function () {
			api('draft/preview', { crm_v2: readFormState() })
				.then(function (data) {
					updateStats(data.preview);
					updatePreviewTable(data.preview);
				})
				.catch(function () { /* silencioso — preview en background */ });
		}, 700);
	}

	function bindLivePreview() {
		var segmentFields = ['crm-audience', 'crm-status-filter', 'crm-course'];
		segmentFields.forEach(function (id) {
			var el = document.getElementById(id);
			if (el) { el.addEventListener('change', schedulePreview); }
		});

		// búsqueda y tags con debounce más largo
		['crm-tags', 'crm-search'].forEach(function (id) {
			var el = document.getElementById(id);
			if (el) { el.addEventListener('input', schedulePreview); }
		});
	}

	/* ============================================================
	 *  UTILIDADES
	 * ============================================================ */
	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function capitalize(str) {
		return str.charAt(0).toUpperCase() + str.slice(1);
	}

	/* ============================================================
	 *  API PÚBLICA (compatibilidad con crm-kanban.js)
	 * ============================================================ */
	window.atoraCrmV2Api = {
		request: function (path, options) {
			var body = {};
			if (options && options.body) {
				try { body = JSON.parse(options.body); } catch (e) { body = {}; }
			}
			return api(path, body);
		},
	};

	/* ============================================================
	 *  INIT
	 * ============================================================ */
	function init() {
		bindButtons();
		bindLivePreview();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
