<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Teacher_Assistant_Ajax_Trait {
	// ── Assets ────────────────────────────────────────────────────────────────

	public function register_assets() {
		if ( ! $this->current_user_can_use_assistant() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$allowed_bases = array( 'lm_lesson', 'lm_course', 'clms_rubric' );
		$allowed_pages = array( 'clms-dashboard', 'clms-ai-settings', 'clms-ai-hub', 'clms-messages', 'clms-instructor-profile' );
		$on_post_edit  = in_array( $screen->post_type, $allowed_bases, true ) && 'post' === $screen->base;
		$on_plugin_page = in_array( $screen->id, $allowed_pages, true )
			|| 0 === strpos( (string) $screen->id, 'atora' )
			|| 0 === strpos( (string) $screen->id, 'clms' )
			|| false !== strpos( (string) $screen->id, 'clms' );

		if ( ! $on_post_edit && ! $on_plugin_page ) {
			return;
		}

		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;
		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}
	}

	// ── AJAX: run (chat + tools) ──────────────────────────────────────────────

	public function ajax_run() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'clms_ta_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! $this->current_user_can_use_assistant() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos para usar el asistente.', 'atora-lms' ) ), 403 );
		}

		$uid = get_current_user_id();
		if ( ! $this->check_rate_limit( $uid, 'ta_run', 12, 60 ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Demasiadas solicitudes. Espera un momento antes de continuar.', 'atora-lms' ) ),
				429
			);
		}

		$tool      = isset( $_POST['tool'] )      ? sanitize_key( wp_unslash( $_POST['tool'] ) ) : 'chat';
		$message   = isset( $_POST['message'] )   ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$params    = isset( $_POST['params'] ) && is_array( $_POST['params'] )
			? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['params'] ) )
			: array();

		$allowed_tools = defined( 'static::TOOLS' ) ? (array) static::TOOLS : array( 'chat' );
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'modular_apply' ) ) {
			$allowed_tools = (array) CLMS_Helper::modular_apply( 'teacher_assistant_tools', $allowed_tools, $lesson_id, $course_id, $uid );
		}
		if ( ! in_array( $tool, $allowed_tools, true ) ) {
			$tool = 'chat';
		}

		if ( '' === $message && empty( $params ) ) {
			wp_send_json_error( array( 'message' => __( 'El mensaje está vacío.', 'atora-lms' ) ), 400 );
		}

		$user_id = get_current_user_id();
		$context = $this->build_context( $lesson_id, $course_id, $user_id );
		$history = $this->get_history( $user_id );

		$result = $this->dispatch_tool( $tool, $message, $params, $context, $history );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Persist turn in history
		$history[] = array(
			'role'    => 'user',
			'content' => $message ?: $this->describe_tool_call( $tool, $params ),
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => isset( $result['text'] ) ? $result['text'] : '',
		);

		$this->save_history( $user_id, $history );

		wp_send_json_success( $result );
	}

	// ── AJAX: save artifact ───────────────────────────────────────────────────

	public function ajax_save_artifact() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'clms_ta_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! $this->current_user_can_use_assistant() ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
		}

		$type      = isset( $_POST['type'] )      ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		$content   = isset( $_POST['content'] )   ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
		$raw       = isset( $_POST['raw'] )        ? wp_unslash( $_POST['raw'] ) : '';

		switch ( $type ) {
			case 'rubric':
				$result = $this->save_rubric_artifact( $raw, $course_id );
				break;

			case 'quiz':
				$result = $this->save_quiz_artifact( $raw, $lesson_id );
				break;

			case 'lesson_notes':
				$result = $this->save_lesson_notes_artifact( $content, $lesson_id );
				break;

			case 'presentation':
				$result = $this->save_presentation_artifact( $content, $lesson_id );
				break;

			case 'study_guide':
				$result = $this->save_study_guide_artifact( $content, $lesson_id );
				break;

			default:
				$result = new WP_Error( 'unknown_artifact_type', __( 'Tipo de artefacto desconocido.', 'atora-lms' ) );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	// ── AJAX: clear history ───────────────────────────────────────────────────

	public function ajax_clear_history() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'clms_ta_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		delete_user_meta( get_current_user_id(), self::USER_META_HISTORY );
		wp_send_json_success( array( 'message' => __( 'Historial borrado.', 'atora-lms' ) ) );
	}

}
