(function () {
	'use strict';

	var boards = document.querySelectorAll('[data-kanban]');
	if (!boards.length) {
		return;
	}

	function request(path, body) {
		if (!window.atoraCrmV2Api || typeof window.atoraCrmV2Api.request !== 'function') {
			return Promise.reject(new Error('API no disponible'));
		}

		return window.atoraCrmV2Api.request(path, {
			method: 'POST',
			body: JSON.stringify(body || {})
		});
	}

	boards.forEach(function (board) {
		var boardType = board.getAttribute('data-kanban');
		var dragged = null;
		var sourceZone = null;

		board.querySelectorAll('.atora-crm-v2-deal[draggable="true"]').forEach(function (card) {
			card.addEventListener('dragstart', function (event) {
				dragged = card;
				sourceZone = card.closest('[data-dropzone]');
				card.classList.add('is-dragging');
				if (event.dataTransfer) {
					event.dataTransfer.effectAllowed = 'move';
					event.dataTransfer.setData('text/plain', card.getAttribute('data-card-id') || '0');
				}
			});

			card.addEventListener('dragend', function () {
				card.classList.remove('is-dragging');
				dragged = null;
				sourceZone = null;
				board.querySelectorAll('.atora-crm-v2-kanban__column').forEach(function (column) {
					column.classList.remove('is-drop-target');
				});
			});
		});

		board.querySelectorAll('[data-dropzone]').forEach(function (zone) {
			zone.addEventListener('dragover', function (event) {
				event.preventDefault();
				var column = zone.closest('.atora-crm-v2-kanban__column');
				if (column) {
					column.classList.add('is-drop-target');
				}
			});

			zone.addEventListener('dragleave', function () {
				var column = zone.closest('.atora-crm-v2-kanban__column');
				if (column) {
					column.classList.remove('is-drop-target');
				}
			});

			zone.addEventListener('drop', function (event) {
				event.preventDefault();
				if (!dragged || !sourceZone) {
					return;
				}

				var targetColumn = zone.closest('.atora-crm-v2-kanban__column');
				if (!targetColumn) {
					return;
				}

				targetColumn.classList.remove('is-drop-target');
				if (zone === sourceZone) {
					return;
				}

				var cardId = parseInt(dragged.getAttribute('data-card-id') || '0', 10);
				var targetStage = targetColumn.getAttribute('data-stage') || '';
				if (!cardId || !targetStage) {
					return;
				}

				zone.appendChild(dragged);

				var path = '';
				var payload = {};
				if (boardType === 'sales') {
					path = 'pipeline/sales/move';
					payload = { deal_id: cardId, to_stage: targetStage };
					if (targetStage === 'lost') {
						payload.lost_reason = window.prompt('Motivo de pérdida (opcional):', '') || '';
					}
				} else {
					path = 'pipeline/academic/move';
					payload = { followup_id: cardId, to_stage: targetStage };
				}

				request(path, payload)
					.then(function (json) {
						if (!json || !json.success) {
							throw new Error((json && json.message) || 'No se pudo guardar.');
						}
					})
					.catch(function () {
						if (sourceZone) {
							sourceZone.appendChild(dragged);
						}
					});
			});
		});
	});
})();
