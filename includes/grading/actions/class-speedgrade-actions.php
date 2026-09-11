<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_SpeedGrade_Actions {

	/**
	 * Acciones admitidas por el formulario de SpeedGrade.
	 *
	 * @return array
	 */
	public static function allowed_submit_actions() {
		return array(
			'save_draft',
			'publish',
			'submit_moderation',
			'approve_moderation',
			'request_moderation_changes',
			'return_revision',
			'approve_evidence',
			'insert_plan',
			'insert_competency_recommendation',
			'accept_ai_draft',
			'save_next',
		);
	}

	/**
	 * Sanitiza acción enviada desde SpeedGrade.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function normalize_submit_action( $value ) {
		$action = sanitize_key( (string) $value );
		return in_array( $action, self::allowed_submit_actions(), true ) ? $action : 'save_draft';
	}
}
