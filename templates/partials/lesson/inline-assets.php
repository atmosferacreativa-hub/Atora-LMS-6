<script>
(function () {
	'use strict';

	function initLitePlayers() {
		document.querySelectorAll('.atora-lite-player[data-src]').forEach(function (wrap) {
			if (wrap.getAttribute('data-atora-lite-init') === '1') return;
			wrap.setAttribute('data-atora-lite-init', '1');

			wrap.addEventListener('click', function () {
				var src = wrap.getAttribute('data-src');
				if (!src) return;

				var iframe = document.createElement('iframe');
				iframe.src = src;
				iframe.setAttribute('allowfullscreen', '');
				iframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture');
				iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
				iframe.setAttribute('loading', 'lazy');

				// Limpia miniatura/overlay/botón y monta el iframe.
				wrap.innerHTML = '';
				wrap.appendChild(iframe);
				wrap.classList.add('is-playing');
			});
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initLitePlayers, { once: true });
	} else {
		initLitePlayers();
	}
})();
</script>
