(function () {
	'use strict';

	var cfg = window.ATORA_SCORM_PLAYER;
	if (!cfg || !cfg.restBase) {
		return;
	}

	var iframe = document.getElementById('atora-scorm-iframe');
	var btnPrev = document.querySelector('[data-action="prev"]');
	var btnNext = document.querySelector('[data-action="next"]');

	var attemptId = 0;
	var scormVersion = '';
	var contentBaseUrl = '';
	var scoHrefs = [];
	var currentIndex = 0;

	var cmi = {};
	var dirty = {};
	var initialized = false;
	var terminated = false;
	var lastError = 0;

	function apiError(code) {
		lastError = code;
		return code;
	}

	function apiOk() {
		lastError = 0;
		return 0;
	}

	function apiGetLastError() {
		return String(lastError || 0);
	}

	function apiGetErrorString(code) {
		code = String(code);
		switch (code) {
			case '0':
				return 'No error';
			case '201':
				return 'Invalid argument error';
			case '301':
				return 'Not initialized';
			case '351':
				return 'Already terminated';
			default:
				return 'General exception';
		}
	}

	function apiGetDiagnostic(code) {
		return apiGetErrorString(code);
	}

	function queueKey() {
		return attemptId ? 'atora_scorm_queue_' + String(attemptId) : 'atora_scorm_queue_0';
	}

	function loadSco(index) {
		index = Math.max(0, Math.min(index, scoHrefs.length - 1));
		currentIndex = index;
		var href = scoHrefs[index] || '';
		iframe.src = contentBaseUrl.replace(/\/$/, '') + '/' + href.replace(/^\//, '');
		if (btnPrev) {
			btnPrev.disabled = index <= 0;
		}
		if (btnNext) {
			btnNext.disabled = index >= scoHrefs.length - 1;
		}
	}

	function readQueue() {
		try {
			var raw = window.localStorage.getItem(queueKey());
			if (!raw) {
				return [];
			}
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) {
			return [];
		}
	}

	function writeQueue(items) {
		try {
			if (!items || !items.length) {
				window.localStorage.removeItem(queueKey());
				return;
			}
			window.localStorage.setItem(queueKey(), JSON.stringify(items));
		} catch (e) {
			// ignore
		}
	}

	async function flushQueue() {
		var items = readQueue();
		if (!items.length) {
			return;
		}
		var remaining = [];
		for (var i = 0; i < items.length; i++) {
			var ok = await commitValues(items[i]);
			if (!ok) {
				remaining = items.slice(i);
				break;
			}
		}
		writeQueue(remaining);
	}

	async function commitValues(values) {
		values = values || {};
		var url = cfg.restBase.replace(/\/$/, '') + '/attempts/' + String(attemptId) + '/commit';
		try {
			var res = await fetch(url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce,
				},
				body: JSON.stringify({ values: values }),
			});
			return !!(res && res.ok);
		} catch (e) {
			return false;
		}
	}

	async function commitDirty() {
		if (!attemptId) {
			return true;
		}
		var values = dirty;
		dirty = {};

		if (!values || !Object.keys(values).length) {
			return true;
		}

		var ok = await commitValues(values);
		if (!ok) {
			var q = readQueue();
			q.push(values);
			writeQueue(q);
			Object.assign(dirty, values);
		}
		return ok;
	}

	function commitOnUnload() {
		if (!attemptId) {
			return;
		}
		var q = readQueue();
		if (Object.keys(dirty).length) {
			q.push(dirty);
			dirty = {};
		}
		if (!q.length) {
			return;
		}
		writeQueue(q);
		var payload = q[0];
		var url = cfg.restBase.replace(/\/$/, '') + '/attempts/' + String(attemptId) + '/commit?_wpnonce=' + encodeURIComponent(cfg.nonce);
		try {
			var blob = new Blob([JSON.stringify({ values: payload })], { type: 'application/json' });
			if (navigator.sendBeacon) {
				navigator.sendBeacon(url, blob);
			}
		} catch (e) {
			// ignore
		}
	}

	function registerApi() {
		var base = {
			GetLastError: apiGetLastError,
			GetErrorString: apiGetErrorString,
			GetDiagnostic: apiGetDiagnostic,
		};

		function ensureInitialized() {
			if (terminated) {
				apiError(351);
				return false;
			}
			if (!initialized) {
				apiError(301);
				return false;
			}
			apiOk();
			return true;
		}

		if (scormVersion === '2004') {
			window.API_1484_11 = Object.assign({}, base, {
				Initialize: function () {
					initialized = true;
					terminated = false;
					apiOk();
					return 'true';
				},
				Terminate: function () {
					if (!ensureInitialized()) {
						return 'false';
					}
					terminated = true;
					commitDirty();
					apiOk();
					return 'true';
				},
				GetValue: function (key) {
					if (!ensureInitialized()) {
						return '';
					}
					key = String(key || '');
					apiOk();
					return key in cmi ? String(cmi[key]) : '';
				},
				SetValue: function (key, value) {
					if (!ensureInitialized()) {
						return 'false';
					}
					key = String(key || '');
					if (!key) {
						apiError(201);
						return 'false';
					}
					cmi[key] = String(value == null ? '' : value);
					dirty[key] = cmi[key];
					apiOk();
					return 'true';
				},
				Commit: function () {
					if (!ensureInitialized()) {
						return 'false';
					}
					commitDirty();
					apiOk();
					return 'true';
				},
			});
			return;
		}

		window.API = Object.assign({}, base, {
			LMSInitialize: function () {
				initialized = true;
				terminated = false;
				apiOk();
				return 'true';
			},
			LMSFinish: function () {
				if (!ensureInitialized()) {
					return 'false';
				}
				terminated = true;
				commitDirty();
				apiOk();
				return 'true';
			},
			LMSGetValue: function (key) {
				if (!ensureInitialized()) {
					return '';
				}
				key = String(key || '');
				apiOk();
				return key in cmi ? String(cmi[key]) : '';
			},
			LMSSetValue: function (key, value) {
				if (!ensureInitialized()) {
					return 'false';
				}
				key = String(key || '');
				if (!key) {
					apiError(201);
					return 'false';
				}
				cmi[key] = String(value == null ? '' : value);
				dirty[key] = cmi[key];
				apiOk();
				return 'true';
			},
			LMSCommit: function () {
				if (!ensureInitialized()) {
					return 'false';
				}
				commitDirty();
				apiOk();
				return 'true';
			},
			LMSGetLastError: apiGetLastError,
			LMSGetErrorString: apiGetErrorString,
			LMSGetDiagnostic: apiGetDiagnostic,
		});
	}

	async function start() {
		var url = cfg.restBase.replace(/\/$/, '') + '/attempts/start';
		var res = await fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify({
				lesson_id: cfg.lessonId,
				library_item_id: cfg.libraryItemId,
				library_version_id: cfg.libraryVersionId,
			}),
		});
		if (!res.ok) {
			return;
		}
		var data = await res.json();
		attemptId = data.attempt_id || 0;
		scormVersion = data.scorm_version || '';
		contentBaseUrl = data.content_base_url || '';
		scoHrefs = Array.isArray(data.sco_hrefs) ? data.sco_hrefs : [];
		if (!scoHrefs.length && data.launch_href) {
			scoHrefs = [data.launch_href];
		}

		registerApi();
		await flushQueue();

		if (attemptId) {
			var valuesUrl = cfg.restBase.replace(/\/$/, '') + '/attempts/' + String(attemptId) + '/values';
			try {
				var vres = await fetch(valuesUrl, {
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': cfg.nonce,
					},
				});
				if (vres.ok) {
					var vdata = await vres.json();
					cmi = vdata && vdata.values ? vdata.values : {};
				}
			} catch (e) {
				// ignore
			}
		}
		loadSco(0);
	}

	if (btnPrev) {
		btnPrev.addEventListener('click', function () {
			commitDirty();
			loadSco(currentIndex - 1);
		});
	}
	if (btnNext) {
		btnNext.addEventListener('click', function () {
			commitDirty();
			loadSco(currentIndex + 1);
		});
	}

	window.addEventListener('beforeunload', commitOnUnload);
	start();
})();

