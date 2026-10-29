/* global ATORA_FORUMS_WEB */

(function () {
	function debounce(fn, wait) {
		let t;
		return function (...args) {
			clearTimeout(t);
			t = setTimeout(() => fn.apply(this, args), wait);
		};
	}

	function renderSimilar(container, items) {
		if (!container) return;
		if (!items || !items.length) {
			container.textContent = '';
			return;
		}
		const ul = document.createElement('ul');
		ul.className = 'atora-forum-similar-list';
		items.forEach((it) => {
			const li = document.createElement('li');
			const a = document.createElement('a');
			a.href = new URL(window.location.href);
			a.searchParams.set('topic', String(it.id));
			a.textContent = it.title || ('#' + it.id);
			li.appendChild(a);
			ul.appendChild(li);
		});
		container.textContent = '';
		const p = document.createElement('p');
		p.textContent = 'Parecidos:';
		container.appendChild(p);
		container.appendChild(ul);
	}

	async function fetchSimilar(forumId, q) {
		const url = new URL(ATORA_FORUMS_WEB.ajaxUrl);
		url.searchParams.set('action', 'atora_forum_similar');
		url.searchParams.set('nonce', ATORA_FORUMS_WEB.nonce);
		url.searchParams.set('forum_id', String(forumId));
		url.searchParams.set('q', q);
		const res = await fetch(url.toString(), { credentials: 'same-origin' });
		const json = await res.json();
		if (!json || !json.success) return [];
		return json.data.items || [];
	}

	function init() {
		if (!window.ATORA_FORUMS_WEB) return;
		const title = document.getElementById('atora-forum-title');
		const container = document.querySelector('.atora-forum-similar');
		if (!title || !container) return;

		const run = debounce(async () => {
			const q = (title.value || '').trim();
			if (q.length < 4) {
				renderSimilar(container, []);
				return;
			}
			try {
				const items = await fetchSimilar(ATORA_FORUMS_WEB.forumId, q);
				renderSimilar(container, items);
			} catch (e) {
				renderSimilar(container, []);
			}
		}, 250);

		title.addEventListener('input', run);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

