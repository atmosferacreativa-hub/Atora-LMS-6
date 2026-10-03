/* global atoraEnrollmentsAdmin, jQuery */

(function ($) {
	'use strict';

	function debounce(fn, wait) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout(t);
			t = setTimeout(function () {
				fn.apply(null, args);
			}, wait);
		};
	}

	function post(type, q) {
		return $.post(atoraEnrollmentsAdmin.ajax_url, {
			action: 'atora_enrollments_search',
			_ajax_nonce: atoraEnrollmentsAdmin.nonce,
			type: type,
			q: q
		});
	}

	function renderSuggestions($container, items, renderItem) {
		$container.empty();
		if (!items || !items.length) {
			return;
		}
		items.forEach(function (item) {
			$container.append(renderItem(item));
		});
	}

	function mountAutocomplete($input) {
		var type = $input.data('atoraEnrollSearch');
		var resultsSel = $input.data('atoraEnrollResults');
		var targetSel = $input.data('atoraEnrollTarget');
		if (!type || !resultsSel) {
			return;
		}
		var $results = $(resultsSel);
		var $target = targetSel ? $(targetSel) : null;

		var run = debounce(function () {
			var q = String($input.val() || '').trim();
			if (q.length < 2) {
				$results.empty();
				return;
			}
			post(type, q).done(function (resp) {
				var items = resp && resp.success ? (resp.data || []) : [];
				renderSuggestions($results, items, function (item) {
					var label = '';
					var value = '';

					if (type === 'user') {
						value = item.email || '';
						label = (item.display_name || item.login || value) + ' <' + value + '> #' + item.id;
					}
					if (type === 'program' || type === 'course') {
						value = String(item.id || '');
						label = value + ' — ' + (item.title || '') + (item.status ? (' (' + item.status + ')') : '');
					}

					var $btn = $('<button type="button" class="button"></button>');
					$btn.text(label);
					$btn.on('click', function () {
						if (type === 'user') {
							$input.val(value);
						}
						if ((type === 'program' || type === 'course') && $target && $target.length) {
							$target.val(value);
							$input.val(item.title || '');
						}
						$results.empty();
					});
					return $btn;
				});
			});
		}, 200);

		$input.on('input', run);
		$input.on('blur', function () {
			setTimeout(function () {
				$results.empty();
			}, 200);
		});
	}

	$(function () {
		if (!window.atoraEnrollmentsAdmin) {
			return;
		}
		$('[data-atora-enroll-search]').each(function () {
			mountAutocomplete($(this));
		});
	});
})(jQuery);

