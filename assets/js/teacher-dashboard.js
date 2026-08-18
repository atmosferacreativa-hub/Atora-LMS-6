(function () {
	'use strict';

	function limitRiskItems() {
		var lists = document.querySelectorAll('.clms-td-list-plain');
		if (!lists || !lists.length) return;

		lists.forEach(function (list) {
			var items = list.querySelectorAll('li');
			if (!items || items.length <= 5) return;

			for (var i = 5; i < items.length; i++) {
				items[i].hidden = true;
			}
		});
	}

	document.addEventListener('DOMContentLoaded', limitRiskItems);
})();

