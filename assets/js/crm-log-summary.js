(function () {
	'use strict';

	function prettyKey(rawKey) {
		var key = String(rawKey || '').replace(/_/g, ' ');
		return key.replace(/\b\w/g, function (letter) {
			return letter.toUpperCase();
		});
	}

	function toEntries(values) {
		var entries = [];
		var key;
		var total;

		for (key in values) {
			if (!Object.prototype.hasOwnProperty.call(values, key)) {
				continue;
			}
			total = parseInt(values[key], 10) || 0;
			if (total <= 0) {
				continue;
			}
			entries.push([key, total]);
		}

		entries.sort(function (left, right) {
			return right[1] - left[1];
		});

		return entries;
	}

	function renderPanel(panel) {
		var rawConfig = panel.getAttribute('data-summary');
		if (!rawConfig) {
			return;
		}

		var config = {};
		try {
			config = JSON.parse(rawConfig);
		} catch (err) {
			return;
		}

		var itemClass = panel.getAttribute('data-summary-item-class') || '';
		var emptyClass = panel.getAttribute('data-summary-empty-class') || itemClass;
		var keyClass = panel.getAttribute('data-summary-key-class') || '';
		var valueClass = panel.getAttribute('data-summary-value-class') || '';
		var maxItems = parseInt(panel.getAttribute('data-summary-max-items') || '4', 10);
		if (!maxItems || maxItems < 1) {
			maxItems = 4;
		}

		var totalNode = panel.querySelector('[data-summary-total]');
		var loadingNode = panel.querySelector('[data-summary-loading]');
		var errorNode = panel.querySelector('[data-summary-error]');

		function setError(message) {
			if (!errorNode) {
				return;
			}
			errorNode.textContent = message || '';
			errorNode.hidden = !message;
		}

		function hideLoading() {
			if (loadingNode) {
				loadingNode.hidden = true;
			}
		}

		function renderBucket(bucket, values) {
			var listNode = panel.querySelector('[data-summary-list="' + bucket + '"]');
			if (!listNode) {
				return;
			}

			var entries = toEntries(values || {});
			listNode.innerHTML = '';

			if (!entries.length) {
				var emptyItem = document.createElement('li');
				emptyItem.className = emptyClass;
				emptyItem.textContent = (config.empty && config.empty[bucket]) ? config.empty[bucket] : 'Sin datos';
				listNode.appendChild(emptyItem);
				return;
			}

			var labels = (config.labels && config.labels[bucket]) ? config.labels[bucket] : {};
			var limit = Math.min(entries.length, maxItems);
			var i;
			for (i = 0; i < limit; i++) {
				var item = document.createElement('li');
				item.className = itemClass;

				var keyNode = document.createElement('span');
				keyNode.className = keyClass;
				keyNode.textContent = labels[entries[i][0]] || prettyKey(entries[i][0]);
				item.appendChild(keyNode);

				var valueNode = document.createElement('span');
				valueNode.className = valueClass;
				valueNode.textContent = String(entries[i][1]);
				item.appendChild(valueNode);

				listNode.appendChild(item);
			}
		}

		if (!window.fetch || !config.endpoint) {
			hideLoading();
			setError(config.error || 'No disponible');
			return;
		}

		setError('');
		window.fetch(config.endpoint, {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce || ''
			}
		})
			.then(function (response) {
				if (!response.ok) {
					throw new Error('http_' + response.status);
				}
				return response.json();
			})
			.then(function (payload) {
				hideLoading();
				if (totalNode) {
					totalNode.textContent = String(parseInt(payload.total, 10) || 0);
				}
				renderBucket('channel', payload.by_channel || {});
				renderBucket('status', payload.by_status || {});
				renderBucket('event_type', payload.by_event_type || {});
			})
			.catch(function () {
				hideLoading();
				setError(config.error || 'No disponible');
			});
	}

	function init() {
		var panels = document.querySelectorAll('[data-atora-log-summary][data-summary]');
		if (!panels.length) {
			return;
		}

		var i;
		for (i = 0; i < panels.length; i++) {
			renderPanel(panels[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
