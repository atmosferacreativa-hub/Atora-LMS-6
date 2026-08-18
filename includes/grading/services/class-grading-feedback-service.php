<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Grading_Feedback_Service {

	/**
	 * Sanitiza feedback editable manteniendo HTML permitido en WP.
	 *
	 * @param string $feedback
	 * @return string
	 */
	public function sanitize_feedback( $feedback ) {
		return wp_kses_post( wp_unslash( (string) $feedback ) );
	}

	/**
	 * Sanitiza comentarios cortos por criterio.
	 *
	 * @param string $comment
	 * @return string
	 */
	public function sanitize_rubric_comment( $comment ) {
		return sanitize_textarea_field( wp_unslash( (string) $comment ) );
	}
}
