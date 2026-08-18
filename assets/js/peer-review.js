/* CLMS Peer Review — front-end submission handler */
(function ($) {
	'use strict';

	function t(key, fallback) {
		var dict = window.clmsPR && window.clmsPR.i18n ? window.clmsPR.i18n : {};
		return dict[key] || fallback;
	}

	$(document).on('submit', '.clms-pr-form', function (e) {
		e.preventDefault();

		var $form       = $(this);
		var $btn        = $form.find('.clms-pr-submit-btn');
		var $msg        = $form.find('.clms-pr-form__msg');
		var assignmentId = $form.data('assignment');

		// Collect scores from radio / number inputs.
		var scores = {};
		$form.find('[name^="scores["]').each(function () {
			var input = $(this);
			// For radios, only use the checked one.
			if (input.is('[type="radio"]') && !input.is(':checked')) {
				return;
			}
			var name   = input.attr('name');          // scores[criterion_key]
			var match  = name.match(/scores\[(.+?)\]/);
			if (match) {
				scores[match[1]] = parseInt(input.val(), 10) || 0;
			}
		});

		var comment = $form.find('.clms-pr-comment').val() || '';

		$btn.prop('disabled', true).text(t('sending', 'Enviando…'));
		$msg.removeClass('is-error').text('');

		$.ajax({
			url    : clmsPR.ajaxUrl,
			method : 'POST',
			data   : {
				action        : 'clms_pr_submit',
				nonce         : clmsPR.nonce,
				assignment_id : assignmentId,
				scores        : scores,
				comment       : comment,
			},
			success: function (res) {
				if (res.success) {
					$msg.text(t('review_sent_success', '✔ Revisión enviada correctamente.'));
					$form.closest('.clms-pr-card')
						.addClass('is-done')
						.find('.clms-pr-card__badge')
						.removeClass('is-pending')
						.addClass('is-done')
						.text(t('completed', 'Completada'));
					$form.slideUp(300);
				} else {
					$msg.addClass('is-error').text(res.data && res.data.message ? res.data.message : t('send_error', 'Error al enviar.'));
					$btn.prop('disabled', false).text(t('send_review', 'Enviar revisión'));
				}
			},
			error: function () {
				$msg.addClass('is-error').text(t('network_error_retry', 'Error de red. Intenta de nuevo.'));
				$btn.prop('disabled', false).text(t('send_review', 'Enviar revisión'));
			},
		});
	});

	$(document).on('click', '.clms-pr-training-btn', function () {
		var $btn = $(this);
		var $card = $btn.closest('[data-clms-pr-training-card="1"]');
		var $msg = $card.find('.clms-pr-training__msg');

		$btn.prop('disabled', true).text(t('sending', 'Enviando…'));
		$msg.removeClass('is-error').text('');

		$.ajax({
			url: clmsPR.ajaxUrl,
			method: 'POST',
			data: {
				action: 'clms_pr_training_complete',
				nonce: clmsPR.trainingNonce || '',
			},
			success: function (res) {
				if (res && res.success) {
					$msg.text(t('training_done', 'Entrenamiento completado'));
					$btn.text(t('training_done', 'Entrenamiento completado'));
					window.setTimeout(function () {
						$card.slideUp(220);
					}, 700);
				} else {
					$msg.addClass('is-error').text((res && res.data && res.data.message) ? res.data.message : t('training_error', 'No se pudo completar el entrenamiento.'));
					$btn.prop('disabled', false).text(t('complete_training', 'Completar entrenamiento'));
				}
			},
			error: function () {
				$msg.addClass('is-error').text(t('network_error_retry', 'Error de red. Intenta de nuevo.'));
				$btn.prop('disabled', false).text(t('complete_training', 'Completar entrenamiento'));
			},
		});
	});
}(jQuery));
