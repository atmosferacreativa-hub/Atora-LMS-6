/**
 * ATORA Email Editor — Fase II S7
 *
 * Editor visual de 5 bloques contenteditable con toolbar.
 * Bloques: header, texto, imagen, botón CTA, pie.
 * Serializa como HTML en el campo #cb-email-html del Campaign Builder.
 */
(function () {
	'use strict';

	// ── Config ───────────────────────────────────────────────────────────────
	var BLOCK_TYPES = ['header', 'text', 'image', 'cta', 'footer'];

	var BLOCK_DEFAULT = {
		header: '<h1 style="font-family:sans-serif;font-size:24px;color:#0f172a;margin:0 0 8px">Título del email</h1>',
		text:   '<p style="font-family:sans-serif;font-size:15px;color:#475569;line-height:1.6;margin:0 0 12px">Escribe tu mensaje aquí…</p>',
		image:  '<img src="" alt="Imagen" style="width:100%;border-radius:8px;display:block">',
		cta:    '<a href="#" style="display:inline-block;padding:12px 28px;background:#1d4ed8;color:#fff;font-family:sans-serif;font-size:15px;font-weight:600;border-radius:8px;text-decoration:none">Llamada a la acción</a>',
		footer: '<p style="font-family:sans-serif;font-size:12px;color:#94a3b8;text-align:center;margin:16px 0 0">© <?php echo date("Y"); ?> Tu Empresa. <a href="#" style="color:#64748b">Desuscribirse</a></p>',
	};

	var TOOLBAR_HTML =
		'<div class="atora-ee-toolbar" style="display:flex;gap:4px;padding:6px 8px;background:#1e3a8a;border-radius:8px 8px 0 0;flex-wrap:wrap">' +
		'<button type="button" class="atora-ee-cmd" data-cmd="bold"        title="Negrita"    style="' + btnStyle() + '"><b>B</b></button>' +
		'<button type="button" class="atora-ee-cmd" data-cmd="italic"      title="Cursiva"    style="' + btnStyle() + '"><i>I</i></button>' +
		'<button type="button" class="atora-ee-cmd" data-cmd="underline"   title="Subrayado"  style="' + btnStyle() + '"><u>U</u></button>' +
		'<button type="button" class="atora-ee-cmd" data-cmd="link"        title="Enlace"     style="' + btnStyle() + '">🔗</button>' +
		'<button type="button" class="atora-ee-cmd" data-cmd="foreColor"   title="Color"      style="' + btnStyle() + '">🎨</button>' +
		'<span style="flex:1"></span>' +
		BLOCK_TYPES.map(function (t) {
			return '<button type="button" class="atora-ee-add-block" data-type="' + t + '" title="Añadir ' + t + '" style="' + btnStyle('#0f172a') + '">' + blockIcon(t) + '</button>';
		}).join('') +
		'</div>';

	function btnStyle(bg) {
		return 'background:' + (bg || 'rgba(255,255,255,.12)') + ';color:#fff;border:none;border-radius:5px;padding:4px 9px;cursor:pointer;font-size:13px;';
	}

	function blockIcon(type) {
		var map = { header: 'H', text: 'T', image: '🖼', cta: '🔘', footer: '—' };
		return map[type] || type;
	}

	// ── Editor constructor ────────────────────────────────────────────────────
	function AtoraEmailEditor(container, outputField) {
		this.container   = container;
		this.outputField = outputField;
		this.blocks      = [];
		this._init();
	}

	AtoraEmailEditor.prototype._init = function () {
		var self = this;

		// Toolbar
		this.container.insertAdjacentHTML('beforebegin', TOOLBAR_HTML);
		var toolbar = this.container.previousElementSibling;

		// Toolbar commands
		toolbar.querySelectorAll('.atora-ee-cmd').forEach(function (btn) {
			btn.addEventListener('mousedown', function (e) {
				e.preventDefault();
				var cmd = btn.getAttribute('data-cmd');
				if ('link' === cmd) {
					var url = window.prompt('URL del enlace:', 'https://');
					if (url) { document.execCommand('createLink', false, url); }
				} else if ('foreColor' === cmd) {
					var color = window.prompt('Color HEX:', '#1d4ed8');
					if (color) { document.execCommand('foreColor', false, color); }
				} else {
					document.execCommand(cmd, false, null);
				}
				self._serialize();
			});
		});

		// Add block buttons
		toolbar.querySelectorAll('.atora-ee-add-block').forEach(function (btn) {
			btn.addEventListener('click', function () {
				self._addBlock(btn.getAttribute('data-type'));
			});
		});

		// Container styles
		this.container.style.cssText += 'min-height:300px;padding:12px;background:#fff;border:.5px solid #e2e8f0;border-radius:0 0 8px 8px;';

		// Init with default blocks if empty
		if (!this.container.querySelector('.atora-ee-block')) {
			this._addBlock('header');
			this._addBlock('text');
			this._addBlock('cta');
			this._addBlock('footer');
		}

		// Serialize on any input
		this.container.addEventListener('input', function () { self._serialize(); });
	};

	AtoraEmailEditor.prototype._addBlock = function (type) {
		var self  = this;
		var block = document.createElement('div');
		block.className = 'atora-ee-block';
		block.setAttribute('data-block-type', type);
		block.setAttribute('contenteditable', type !== 'image' ? 'true' : 'false');
		block.style.cssText = 'position:relative;margin-bottom:8px;padding:8px;border:.5px dashed #e2e8f0;border-radius:6px;outline:none;';
		block.innerHTML = BLOCK_DEFAULT[type] || '';

		// Image: click to change URL
		if ('image' === type) {
			block.style.cursor = 'pointer';
			block.addEventListener('click', function () {
				var img = block.querySelector('img');
				if (!img) { return; }
				var url = window.prompt('URL de la imagen:', img.src || '');
				if (url) { img.src = url; self._serialize(); }
			});
		}

		// Delete control
		var del = document.createElement('button');
		del.type = 'button';
		del.textContent = '×';
		del.style.cssText = 'position:absolute;top:4px;right:4px;background:#fee2e2;color:#991b1b;border:none;border-radius:4px;padding:0 6px;cursor:pointer;font-size:14px;opacity:.7;';
		del.addEventListener('click', function (e) {
			e.stopPropagation();
			block.remove();
			self._serialize();
		});
		block.appendChild(del);

		// Drag handle
		block.setAttribute('draggable', 'true');
		block.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', ''); self._dragSrc = block; });
		block.addEventListener('dragover',  function (e) { e.preventDefault(); });
		block.addEventListener('drop',      function (e) {
			e.preventDefault();
			if (self._dragSrc && self._dragSrc !== block) {
				self.container.insertBefore(self._dragSrc, block);
				self._serialize();
			}
		});

		this.container.appendChild(block);
		this._serialize();
	};

	AtoraEmailEditor.prototype._serialize = function () {
		if (!this.outputField) { return; }
		var blocks = this.container.querySelectorAll('.atora-ee-block');
		var html = '<table width="600" cellpadding="0" cellspacing="0" style="margin:auto;font-family:sans-serif">';
		blocks.forEach(function (b) {
			var del = b.querySelector('button[type="button"]');
			var inner = b.cloneNode(true);
			var delClone = inner.querySelector('button[type="button"]');
			if (delClone) { delClone.remove(); }
			html += '<tr><td style="padding:8px 24px">' + inner.innerHTML + '</td></tr>';
		});
		html += '</table>';
		this.outputField.value = html;
	};

	// ── Auto-init ─────────────────────────────────────────────────────────────
	document.addEventListener('DOMContentLoaded', function () {
		var editorEl = document.getElementById('atora-email-editor');
		var outputEl = document.getElementById('cb-email-html');
		if (editorEl) {
			window.atoraEmailEditor = new AtoraEmailEditor(editorEl, outputEl || null);
		}
	});

	// Exponer para uso externo
	window.AtoraEmailEditor = AtoraEmailEditor;
})();
