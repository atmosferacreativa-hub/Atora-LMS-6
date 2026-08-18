<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Helper_Core_Trait {
	/**
	 * Devuelve un módulo ya cargado.
	 *
	 * @param string $class Nombre de clase.
	 * @return object|null
	 */
	public static function module( $class ) {
		if ( ! is_string( $class ) || '' === trim( $class ) ) {
			return null;
		}

		if ( ! function_exists( 'clms_core' ) ) {
			return null;
		}

		$core = clms_core();

		if ( ! $core || ! method_exists( $core, 'get_module' ) ) {
			return null;
		}

		return $core->get_module( trim( $class ) );
	}

	/**
	 * Devuelve el servicio de modularidad si está cargado.
	 *
	 * @return object|null
	 */
	public static function modular_service() {
		return self::module( 'CLMS_Modularity_Config_Service' );
	}

	/**
	 * Aplica filtro modular por clave con fallback seguro.
	 *
	 * @param string $key   Clave modular.
	 * @param mixed  $value Valor.
	 * @param mixed  ...$args Contexto adicional.
	 * @return mixed
	 */
	public static function modular_apply( $key, $value, ...$args ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			return $value;
		}

		$service = self::modular_service();
		if ( $service && method_exists( $service, 'apply' ) ) {
			return $service->apply( $key, $value, ...$args );
		}

		return apply_filters( 'clms_modularity_' . $key, $value, ...$args );
	}

	/**
	 * Devuelve las cadenas básicas de UI usadas por atora-ui.js.
	 *
	 * @return array<string,string>
	 */
	public static function get_ui_i18n_strings() {
		$strings = array(
			'session_unavailable'  => __( 'Tu sesión expiró o el servicio no está disponible.', 'atora-lms' ),
			'session_expired'      => __( 'Tu sesión expiró. Recarga la página e intenta de nuevo.', 'atora-lms' ),
			'request_invalid'      => __( 'Solicitud no válida.', 'atora-lms' ),
			'response_invalid'     => __( 'Respuesta inválida del servidor.', 'atora-lms' ),
			'profile_init_error'   => __( 'No se pudo inicializar el perfil. Recarga la página.', 'atora-lms' ),
			'profile_save_error'   => __( 'No se pudo guardar.', 'atora-lms' ),
			'profile_saved'        => __( 'Perfil actualizado.', 'atora-lms' ),
			'saving'               => __( 'Guardando…', 'atora-lms' ),
			'bio_unavailable'      => __( 'Presentación no disponible. Recarga la página.', 'atora-lms' ),
			'bio_saved'            => __( 'Presentación actualizada.', 'atora-lms' ),
			'bio_empty'            => __( 'Añade una pequeña presentación sobre ti.', 'atora-lms' ),
			'bio_edit'             => __( 'Editar presentación', 'atora-lms' ),
			'bio_add'              => __( 'Añadir presentación', 'atora-lms' ),
		);

		return apply_filters( 'clms_ui_i18n_strings', $strings );
	}

	/**
	 * Inyecta las cadenas de UI en JS de forma segura y única.
	 *
	 * @param string $handle Script handle donde adjuntar el inline script.
	 * @return void
	 */
	public static function add_ui_i18n_script( $handle = 'atora-ui' ) {
		if ( self::$ui_i18n_injected ) {
			return;
		}

		if ( ! function_exists( 'wp_add_inline_script' ) ) {
			return;
		}

		$strings = self::get_ui_i18n_strings();
		$script  = 'window.ATORA = window.ATORA || {}; window.ATORA.i18n = window.ATORA.i18n || ' . wp_json_encode( $strings ) . ';';
		wp_add_inline_script( $handle, $script, 'before' );

		self::$ui_i18n_injected = true;
	}

	/**
	 * Verifica si el usuario actual puede gestionar el LMS.
	 *
	 * Sin $post_id: comprueba si el usuario tiene alguna capacidad LMS general.
	 * Con $post_id:
	 *   - Si es lm_course: verifica edit_lm_courses + autoría para edit_others_lm_courses.
	 *   - Si es lm_lesson: verifica edit_lm_lessons + autoría para edit_others_lm_lessons.
	 *   - Otros tipos de post: deniega.
	 *
	 * Jerarquía:
	 *   1. manage_options (administrador WP) → acceso total.
	 *   2. clms_manage_courses | clms_manage_lessons | clms_manage_submissions |
	 *      clms_grade_submissions → gestor LMS sin post específico.
	 *   3. Con post: capacidades primitivas del CPT + restricción de autoría.
	 *
	 * @param int $post_id ID de post opcional (lm_course o lm_lesson).
	 * @return bool
	 */
	public static function user_can_manage_lms( $post_id = 0 ) {
		$post_id = absint( $post_id );

		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Administrador WordPress: acceso total.
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Sin post específico: basta con cualquier capacidad LMS de gestión.
		if ( ! $post_id ) {
			return current_user_can( 'clms_manage_courses' )
				|| current_user_can( 'clms_manage_lessons' )
				|| current_user_can( 'clms_manage_submissions' )
				|| current_user_can( 'clms_grade_submissions' );
		}

		// Con post: validar tipo y capacidades específicas.
		$post_type = get_post_type( $post_id );

		if ( 'lm_course' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_courses',
				'edit_lm_courses',
				'edit_others_lm_courses'
			);
		}

		if ( 'lm_lesson' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_lessons',
				'edit_lm_lessons',
				'edit_others_lm_lessons'
			);
		}

		if ( 'lm_program' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_courses',
				'edit_lm_courses',
				'edit_others_lm_courses'
			);
		}

		if ( 'lm_cohort' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_courses',
				'edit_lm_courses',
				'edit_others_lm_courses'
			);
		}

		if ( 'clms_rubric' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_lessons',
				'edit_clms_rubrics',
				'edit_others_clms_rubrics'
			);
		}

		if ( 'clms_submission' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_submissions',
				'edit_clms_submissions',
				'edit_others_clms_submissions'
			);
		}

		if ( 'clms_peer_review' === $post_type ) {
			return self::can_edit_cpt_post(
				$post_id,
				'clms_manage_submissions',
				'edit_clms_peer_reviews',
				'edit_others_clms_peer_reviews'
			);
		}

		// Tipo de post no gestionado por el LMS.
		return false;
	}

	/**
	 * Comprueba si el usuario actual puede editar un post de un CPT LMS.
	 *
	 * Lógica:
	 *   - Necesita la capacidad LMS general ($lms_cap) Y la primitiva del CPT ($edit_own_cap).
	 *   - Si el post no le pertenece, necesita además $edit_others_cap.
	 *   - Posts en estado publish/private son editables si tiene edit_published / edit_private
	 *     (WordPress resuelve esto vía map_meta_cap; aquí aplicamos la restricción de autoría).
	 *
	 * @param int    $post_id          ID del post.
	 * @param string $lms_cap          Capacidad LMS general (clms_manage_courses|clms_manage_lessons).
	 * @param string $edit_own_cap     Capacidad para editar propios (edit_lm_courses|edit_lm_lessons).
	 * @param string $edit_others_cap  Capacidad para editar ajenos (edit_others_lm_courses|...).
	 * @return bool
	 */
	protected static function can_edit_cpt_post( $post_id, $lms_cap, $edit_own_cap, $edit_others_cap ) {
		// Necesita la capacidad LMS general.
		if ( ! current_user_can( $lms_cap ) ) {
			return false;
		}

		// Necesita la capacidad primitiva del CPT.
		if ( ! current_user_can( $edit_own_cap ) ) {
			return false;
		}

		$current_user_id = get_current_user_id();
		$post            = get_post( $post_id );

		if ( ! $post ) {
			return false;
		}

		// Post propio: permitido.
		if ( (int) $post->post_author === $current_user_id ) {
			return true;
		}

		// Post ajeno: necesita capacidad de edición de otros.
		return current_user_can( $edit_others_cap );
	}
}
