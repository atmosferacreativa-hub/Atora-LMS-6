<?php
/**
 * Servicio base para asistente de configuración académica de cursos.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Wizard_Service {

	const USER_META_PROGRESS_PREFIX = '_clms_academic_wizard_progress_';

	/**
	 * Crea o valida curso para el wizard.
	 *
	 * @param int $course_id  Curso existente.
	 * @param int $author_id  Autor.
	 * @return int
	 */
	public function ensure_course( $course_id, $author_id ) {
		$course_id = absint( $course_id );
		$author_id = absint( $author_id );

		if ( $course_id && 'lm_course' === get_post_type( $course_id ) ) {
			return $course_id;
		}

		$new_course_id = wp_insert_post(
			array(
				'post_type'   => 'lm_course',
				'post_status' => 'draft',
				'post_title'  => __( 'Nuevo curso académico', 'atora-lms' ),
				'post_author' => $author_id ? $author_id : get_current_user_id(),
			),
			true
		);

		return is_wp_error( $new_course_id ) ? 0 : absint( $new_course_id );
	}

	/**
	 * Guarda información base del curso.
	 *
	 * @param int   $course_id Curso.
	 * @param array $data      Datos.
	 * @return bool
	 */
	public function save_basic_info( $course_id, $data ) {
		$course_id = absint( $course_id );
		$data      = is_array( $data ) ? $data : array();

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return false;
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';
		$subtitle = isset( $data['subtitle'] ) ? sanitize_text_field( (string) $data['subtitle'] ) : '';
		$objective = isset( $data['objective_general'] ) ? sanitize_textarea_field( (string) $data['objective_general'] ) : '';

		if ( '' !== $title ) {
			wp_update_post(
				array(
					'ID'         => $course_id,
					'post_title' => $title,
				)
			);
		}

		update_post_meta( $course_id, '_clms_course_subtitle', $subtitle );
		update_post_meta( $course_id, '_clms_course_objective_general', $objective );

		return true;
	}

	/**
	 * Guarda competencias desde líneas de texto.
	 *
	 * @param int    $course_id          Curso.
	 * @param string $competencies_lines Texto.
	 * @param bool   $mark_required      Marcar requeridas.
	 * @param int    $default_weight     Peso por defecto.
	 * @return bool
	 */
	public function save_competencies_from_lines( $course_id, $competencies_lines, $mark_required = false, $default_weight = 0 ) {
		$course_id = absint( $course_id );
		if ( ! $course_id || ! class_exists( 'CLMS_Helper' ) ) {
			return false;
		}

		$service = clms_core('CLMS_Competency_Service');
		if ( ! $service || ! method_exists( $service, 'save_course_competencies' ) ) {
			return false;
		}

		$default_weight = max( 0, min( 100, absint( $default_weight ) ) );
		$lines = preg_split( '/\r\n|\r|\n/', (string) $competencies_lines );
		$lines = is_array( $lines ) ? $lines : array();
		$rows  = array();

		foreach ( $lines as $line ) {
			$title = sanitize_text_field( (string) $line );
			if ( '' === $title ) {
				continue;
			}
			$rows[] = array(
				'title'    => $title,
				'required' => (bool) $mark_required,
				'weight'   => $default_weight,
			);
		}

		return (bool) $service->save_course_competencies( $course_id, $rows );
	}

	/**
	 * Obtiene estado de progreso del wizard.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso.
	 * @return array<string,mixed>
	 */
	public function get_progress_state( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		if ( ! $user_id || ! $course_id ) {
			return array();
		}

		$state = get_user_meta( $user_id, self::USER_META_PROGRESS_PREFIX . $course_id, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Guarda progreso del wizard por usuario/curso.
	 *
	 * @param int   $user_id   Usuario.
	 * @param int   $course_id Curso.
	 * @param array $state     Estado.
	 * @return bool
	 */
	public function save_progress_state( $user_id, $course_id, $state ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$state     = is_array( $state ) ? $state : array();

		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		$clean = array(
			'current_step'   => max( 1, absint( $state['current_step'] ?? 1 ) ),
			'completed_step' => max( 0, absint( $state['completed_step'] ?? 0 ) ),
			'updated_at'     => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, self::USER_META_PROGRESS_PREFIX . $course_id, $clean );
		return true;
	}
}

