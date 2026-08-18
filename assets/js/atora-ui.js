(function (window, document) {
	'use strict';

	if (!window.ATORA) {
		window.ATORA = {};
	}
	if (window.ATORA.ui) {
		return;
	}

	var ui = {};

	ui.getRestConfig = function () {
		if (window.ATORA && window.ATORA.rest) {
			return window.ATORA.rest;
		}
		return {};
	};

	ui.t = function (key, fallback) {
		var dict = window.ATORA && window.ATORA.i18n ? window.ATORA.i18n : {};
		if (dict && Object.prototype.hasOwnProperty.call(dict, key)) {
			return dict[key];
		}
		return fallback || '';
	};

	ui.getProfileEndpoint = function (container) {
		if (container && container.getAttribute('data-atora-profile-endpoint')) {
			return container.getAttribute('data-atora-profile-endpoint');
		}
		var rest = ui.getRestConfig();
		if (rest.root) {
			return rest.root.replace(/\/$/, '') + '/me/profile';
		}
		return '';
	};

	ui.getProfileNonce = function (container) {
		if (container && container.getAttribute('data-atora-profile-nonce')) {
			return container.getAttribute('data-atora-profile-nonce');
		}
		var rest = ui.getRestConfig();
		return rest.nonce || '';
	};

	ui.postProfileUpdate = function (endpoint, nonce, payload) {
		if (!endpoint) {
			return Promise.reject(new Error('missing_endpoint'));
		}
		return fetch(endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce || ''
			},
			body: JSON.stringify(payload || {})
		}).then(function (r) {
			var isJson = r.headers.get('content-type') && r.headers.get('content-type').indexOf('application/json') !== -1;
			if (!r.ok) {
				if (!isJson) {
					throw new Error(ui.t('session_unavailable'));
				}
				return r.json().then(function (json) {
					if (json && json.code === 'rest_cookie_invalid_nonce') {
						throw new Error(ui.t('session_expired'));
					}
					var message = json && json.message ? json.message : ui.t('request_invalid');
					throw new Error(message);
				});
			}
			if (!isJson) {
				throw new Error(ui.t('response_invalid'));
			}
			return r.json();
		});
	};

	ui.setLoading = function (el, isLoading, label) {
		if (!el) return;
		var loading = !!isLoading;
		el.classList.toggle('atora-is-loading', loading);
		el.setAttribute('aria-busy', loading ? 'true' : 'false');

		// Auto-inject a spinner if the element is a button and has none yet
		if (el.tagName === 'BUTTON' || el.tagName === 'INPUT') {
			var spinner = el.querySelector('.atora-spinner');
			if (!spinner) {
				spinner = document.createElement('span');
				spinner.className = 'atora-spinner atora-spinner--sm';
				spinner.setAttribute('aria-hidden', 'true');
				spinner.setAttribute('hidden', '');
				el.appendChild(spinner);
			}
			if (loading) {
				spinner.removeAttribute('hidden');
			} else {
				spinner.setAttribute('hidden', '');
			}
		}

		var loader = el.querySelector('[data-atora-loader]');
		if (loader) {
			if (loading) {
				loader.removeAttribute('hidden');
			} else {
				loader.setAttribute('hidden', '');
			}
		}

		if (label) {
			var status = el.querySelector('[data-atora-status]');
			if (status) {
				status.textContent = label;
			}
		}
	};

	ui.showStatus = function (el, message, isError) {
		if (!el) return;
		var target = el.matches && el.matches('[data-atora-status]') ? el : el.querySelector('[data-atora-status]');
		if (!target) return;
		target.textContent = message || '';
		target.classList.toggle('atora-status-error', !!isError);
	};

	ui.postForm = function (url, formData, opts) {
		var options = opts || {};
		var headers = options.headers || {};
		var body = formData instanceof FormData ? formData : new FormData();

		return fetch(url, {
			method: options.method || 'POST',
			credentials: options.credentials || 'same-origin',
			headers: headers,
			body: body
		}).then(function (r) {
			var isJson = r.headers.get('content-type') && r.headers.get('content-type').indexOf('application/json') !== -1;
			if (!r.ok) {
				if (!isJson) {
					throw new Error(ui.t('request_invalid'));
				}
				return r.json().then(function (json) {
					var message = json && json.message ? json.message : ui.t('request_invalid');
					throw new Error(message);
				});
			}
			return isJson ? r.json() : r.text();
		});
	};

	ui.bindActionButtons = function (selector) {
		var nodes = document.querySelectorAll(selector || '[data-atora-action]');
		nodes.forEach(function (btn) {
			if (btn.dataset.atoraBound) return;
			btn.dataset.atoraBound = '1';
			btn.addEventListener('click', function (e) {
				var action = btn.getAttribute('data-atora-action');
				var url = btn.getAttribute('data-atora-url');
				var target = btn.getAttribute('data-atora-target');
				if (!action || !url) return;

				e.preventDefault();
				ui.setLoading(btn, true);

				var body = 'action=' + encodeURIComponent(action);
				fetch(url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
					body: body
				})
					.then(function (r) { return r.text(); })
					.then(function (html) {
						if (target) {
							var el = document.querySelector(target);
							if (el) el.innerHTML = html;
						}
					})
					.catch(function () {})
					.finally(function () {
						ui.setLoading(btn, false);
					});
			});
		});
	};

	ui.bindRowLinks = function (selector) {
		var rows = document.querySelectorAll(selector || '[data-atora-row-link]');
		rows.forEach(function (row) {
			if (row.dataset.atoraRowBound) return;
			row.dataset.atoraRowBound = '1';
			row.addEventListener('click', function (e) {
				if (!row.dataset.atoraRowLink) return;
				var target = e.target;
				if (target && target.closest && target.closest('a, button, input, select, textarea')) return;
				window.location.href = row.dataset.atoraRowLink;
			});
			row.addEventListener('keydown', function (e) {
				if (!row.dataset.atoraRowLink) return;
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					window.location.href = row.dataset.atoraRowLink;
				}
			});
		});
	};

	ui.bindStudentProfile = function (selector) {
		var blocks = document.querySelectorAll(selector || '[data-atora-student-profile]');
		blocks.forEach(function (block) {
			if (block.dataset.atoraProfileBound) return;
			block.dataset.atoraProfileBound = '1';

			var saveBtn = block.querySelector('[data-atora-profile-save]');
			var status = block.querySelector('[data-atora-status]');
			var endpoint = ui.getProfileEndpoint(block);
			var nonce = ui.getProfileNonce(block);

			var setStatus = function (text, isError) {
				if (!status) return;
				status.textContent = text || '';
				status.classList.toggle('atora-status-error', !!isError);
			};

			if (!endpoint || !nonce) {
				if (saveBtn) {
					saveBtn.disabled = true;
					saveBtn.setAttribute('aria-disabled', 'true');
				}
					setStatus(ui.t('profile_init_error'), true);
					return;
				}

			var getPayload = function () {
				var payload = {};
				var fields = block.querySelectorAll('[data-atora-profile-field]');
				fields.forEach(function (field) {
					var key = field.getAttribute('data-atora-profile-field');
					if (!key) return;
					if (field.type === 'checkbox') {
						payload[key] = field.checked ? 1 : 0;
					} else {
						payload[key] = field.value;
					}
				});
				return payload;
			};

			if (saveBtn) {
				saveBtn.addEventListener('click', function () {
					var payload = getPayload();
					saveBtn.disabled = true;
					ui.setLoading(saveBtn, true);
					setStatus(ui.t('saving'), false);

					ui.postProfileUpdate(endpoint, nonce, payload)
						.then(function () {
							setStatus(ui.t('profile_saved'), false);
						})
						.catch(function (err) {
							setStatus(err && err.message ? err.message : ui.t('profile_save_error'), true);
						})
						.finally(function () {
							ui.setLoading(saveBtn, false);
							saveBtn.disabled = false;
							window.setTimeout(function () {
								setStatus('', false);
							}, 3500);
						});
				});
			}
		});
	};

	ui.bindStudentBio = function (selector) {
		var wrap = document.querySelector(selector || '[data-atora-bio-form]');
		var editBtn = document.getElementById('clms-sp-edit-bio-btn');
		var form = document.getElementById('clms-sp-bio-form');
		var input = document.getElementById('clms-sp-bio-input');
		var saveBtn = document.getElementById('clms-sp-bio-save');
		var cancelBtn = document.getElementById('clms-sp-bio-cancel');
		var bioText = document.getElementById('clms-sp-bio-text');

		if (!editBtn || !form || !input || !saveBtn || !cancelBtn) return;

		var endpoint = ui.getProfileEndpoint(wrap);
		var nonce = ui.getProfileNonce(wrap);
		var status = wrap ? wrap.querySelector('[data-atora-status]') : null;
		var emptyLabel = bioText ? bioText.getAttribute('data-atora-bio-empty') : '';
		var editLabel = editBtn ? editBtn.getAttribute('data-label-edit') : '';
		var addLabel = editBtn ? editBtn.getAttribute('data-label-add') : '';
		var setStatus = function (text, isError) {
			if (!status) return;
			status.textContent = text || '';
			status.classList.toggle('atora-status-error', !!isError);
		};

		if (!endpoint || !nonce) {
			editBtn.disabled = true;
			editBtn.setAttribute('aria-disabled', 'true');
			setStatus(ui.t('bio_unavailable'), true);
			return;
		}

		editBtn.addEventListener('click', function () {
			form.style.display = 'block';
			editBtn.style.display = 'none';
			input.focus();
		});

		cancelBtn.addEventListener('click', function () {
			form.style.display = 'none';
			editBtn.style.display = '';
			setStatus('', false);
		});

		saveBtn.addEventListener('click', function () {
			var bio = input.value.trim();
			saveBtn.disabled = true;
			ui.setLoading(saveBtn, true);
			setStatus(ui.t('saving'), false);

			ui.postProfileUpdate(endpoint, nonce, { bio: bio })
				.then(function () {
					if (bioText) {
						bioText.textContent = bio || emptyLabel || ui.t('bio_empty');
						bioText.classList.toggle('clms-sp-hero__bio--placeholder', !bio);
					}
					form.style.display = 'none';
					editBtn.style.display = '';
					editBtn.textContent = bio ? (editLabel || ui.t('bio_edit')) : (addLabel || ui.t('bio_add'));
					setStatus(ui.t('bio_saved'), false);
				})
				.catch(function (err) {
					setStatus(err && err.message ? err.message : ui.t('profile_save_error'), true);
				})
				.finally(function () {
					ui.setLoading(saveBtn, false);
					saveBtn.disabled = false;
					window.setTimeout(function () {
						setStatus('', false);
					}, 3500);
				});
		});
	};

	ui.formatBytes = function (bytes) {
		if (!bytes && bytes !== 0) return '';
		var sizes = ['B', 'KB', 'MB', 'GB'];
		var i = 0;
		var val = bytes;
		while (val >= 1024 && i < sizes.length - 1) {
			val = val / 1024;
			i++;
		}
		return (i === 0 ? val : val.toFixed(1)) + ' ' + sizes[i];
	};

	ui.bindSubmissionUploads = function (selector) {
		var blocks = document.querySelectorAll(selector || '[data-atora-submission-upload]');
		blocks.forEach(function (block) {
			if (block.dataset.atoraUploadBound) return;
			block.dataset.atoraUploadBound = '1';

			var input = block.querySelector('[data-atora-file-input]');
			var dropzone = block.querySelector('[data-atora-dropzone]');
			var list = block.querySelector('[data-atora-file-list]');
			var status = block.querySelector('[data-atora-status]');

			if (!input) return;

			var maxFiles = parseInt(block.dataset.atoraMaxFiles || '0', 10);
			var maxSize = parseInt(block.dataset.atoraMaxSize || '0', 10);
			var allowed = (block.dataset.atoraAllowed || '').split(',').map(function (item) {
				return item.trim().toLowerCase();
			}).filter(Boolean);

			var msgTooMany = block.dataset.atoraMsgTooMany || '';
			var msgTooLarge = block.dataset.atoraMsgTooLarge || '';
			var msgInvalidType = block.dataset.atoraMsgInvalidType || '';
			var msgReady = block.dataset.atoraMsgReady || '';
			var msgEmpty = block.dataset.atoraMsgEmpty || '';

			var setStatus = function (text, isError) {
				if (!status) return;
				status.textContent = text || '';
				status.classList.toggle('atora-status-error', !!isError);
			};

			var updateList = function (files) {
				if (!list) return;
				list.innerHTML = '';
				if (!files || !files.length) {
					list.setAttribute('hidden', '');
					return;
				}
				list.removeAttribute('hidden');
				files.forEach(function (file) {
					var row = document.createElement('div');
					row.className = 'clms-submission-file';
					var name = document.createElement('span');
					name.className = 'clms-submission-file-name';
					name.textContent = file.name || '';
					var size = document.createElement('span');
					size.className = 'clms-submission-file-size';
					size.textContent = ui.formatBytes(file.size || 0);
					row.appendChild(name);
					row.appendChild(size);
					list.appendChild(row);
				});
			};

			var applyFiles = function (files) {
				var fileList = files ? Array.prototype.slice.call(files) : [];
				var accepted = [];
				var errors = [];

				if (maxFiles && fileList.length > maxFiles && msgTooMany) {
					errors.push(msgTooMany.replace('%d', maxFiles));
				}

				fileList.forEach(function (file) {
					var ext = '';
					if (file && file.name) {
						var parts = file.name.split('.');
						ext = parts.length > 1 ? parts.pop().toLowerCase() : '';
					}

					if (allowed.length && ext && allowed.indexOf(ext) === -1) {
						if (msgInvalidType) errors.push(msgInvalidType);
						return;
					}

					if (maxSize && file.size > maxSize) {
						if (msgTooLarge) errors.push(msgTooLarge);
						return;
					}

					accepted.push(file);
				});

				if (maxFiles && accepted.length > maxFiles) {
					accepted = accepted.slice(0, maxFiles);
				}

				if (window.DataTransfer) {
					var dt = new DataTransfer();
					accepted.forEach(function (file) {
						dt.items.add(file);
					});
					input.files = dt.files;
				}

				updateList(accepted);

				if (errors.length) {
					setStatus(errors[0], true);
				} else if (accepted.length) {
					var readyMsg = msgReady.replace('%d', accepted.length);
					setStatus(readyMsg, false);
				} else if (msgEmpty) {
					setStatus(msgEmpty, true);
				}
			};

			input.addEventListener('change', function () {
				applyFiles(input.files);
			});

			if (dropzone) {
				dropzone.addEventListener('click', function () {
					input.click();
				});
				dropzone.addEventListener('keydown', function (e) {
					if (e.key === 'Enter' || e.key === ' ') {
						e.preventDefault();
						input.click();
					}
				});
				dropzone.addEventListener('dragover', function (e) {
					e.preventDefault();
					dropzone.classList.add('is-dragover');
				});
				dropzone.addEventListener('dragleave', function () {
					dropzone.classList.remove('is-dragover');
				});
				dropzone.addEventListener('drop', function (e) {
					e.preventDefault();
					dropzone.classList.remove('is-dragover');
					if (e.dataTransfer && e.dataTransfer.files) {
						applyFiles(e.dataTransfer.files);
					}
				});
			}
		});
	};

	ui.bindLessonSwipe = function () {
		var nav = document.querySelector('.atora-lesson-nav, .cp-nav-row');
		if (!nav) return;
		var startX = 0;
		document.addEventListener('touchstart', function (e) {
			startX = e.touches[0].clientX;
		}, { passive: true });
		document.addEventListener('touchend', function (e) {
			var dx = e.changedTouches[0].clientX - startX;
			if (Math.abs(dx) < 60) return;
			var sel = dx < 0 ? '.atora-lesson-nav-next, .cp-nav-next' : '.atora-lesson-nav-prev, .cp-nav-prev';
			var target = document.querySelector(sel);
			if (target) target.click();
		}, { passive: true });
	};

	document.addEventListener('DOMContentLoaded', function () {
		ui.bindActionButtons();
		ui.bindRowLinks();
		ui.bindStudentProfile();
		ui.bindStudentBio();
		ui.bindSubmissionUploads();
		ui.bindLessonSwipe();
	});

	window.ATORA.ui = ui;
})(window, document);
