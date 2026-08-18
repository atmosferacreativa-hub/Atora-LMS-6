(function () {
	'use strict';

	function limitListItems(container, selector, maxItems) {
		if (!container) return;
		var items = container.querySelectorAll(selector);
		if (!items || items.length <= maxItems) return;

		for (var i = maxItems; i < items.length; i++) {
			items[i].hidden = true;
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var dashboard = document.querySelector('.clms-sd');
		if (!dashboard) return;

		limitListItems(dashboard, '.clms-sd-list-card', 5);
	});
})();

