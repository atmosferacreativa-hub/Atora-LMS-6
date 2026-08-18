<?php
/**
 * Guardado incremental para pasos avanzados del Asistente Académico.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Wizard_Step_Save_Service {

	/**
	 * Aplica guardado para pasos del wizard.
	 *
	 * @param int   $step      Paso.
	 * @param int   $course_id Curso.
	 * @param array $input     Datos POST saneables.
	 * @return array<string,mixed>
	 */
	public function save_step( $step, $course_id, $input = array() ) {
		$step      = absint( $step );
		$course_id = absint( $course_id );
		$input     = is_array( $input ) ? $input : array();

		$result = array(
			'saved'   => false,
			'message' => '',
		);

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return $result;
		}

		if ( 4 === $step ) {
			return $this->save_step_4_lessons( $course_id, $input );
		}

		if ( 5 === $step ) {
			return $this->save_step_5_evidences( $course_id, $input );
		}

		return $result;
	}

	/**
	 * Paso 4: asociar lecciones huérfanas al curso.
	 *
	 * @param int   $course_id Curso.
	 * @param array $input     Input.
	 * @return array<string,mixed>
	 */
	protected function save_step_4_lessons( $course_id, $input ) {
		$course_id = absint( $course_id );
		$input     = is_array( $input ) ? $input : array();

		$lesson_ids = isset( $input['wizard_attach_lessons'] ) ? (array) $input['wizard_attach_lessons'] : array();
		$lesson_ids = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
		if ( empty( $lesson_ids ) ) {
			return array(
				'saved'   => false,
				'message' => '',
			);
		}

		$assigned = 0;
		foreach ( $lesson_ids as $lesson_id ) {
			if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
				continue;
			}
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'set_course_id_for_lesson' ) ) {
				CLMS_Helper::set_course_id_for_lesson( $lesson_id, $course_id, true );
			} else {
				update_post_meta( $lesson_id, '_clms_course_id', $course_id );
				update_post_meta( $lesson_id, '_clms_parent_course', $course_id );
			}
			$assigned++;
		}

		return array(
			'saved'   => $assigned > 0,
			'message' => $assigned > 0
				? sprintf(
					/* translators: %d: total */
					__( 'Se asociaron %d lecciones al curso.', 'atora-lms' ),
					$assigned
				)
				: '',
		);
	}

	/**
	 * Paso 5: aplicar configuración de evidencia a lecciones seleccionadas.
	 *
	 * @param int   $course_id Curso.
	 * @param array $input     Input.
	 * @return array<string,mixed>
	 */
	protected function save_step_5_evidences( $course_id, $input ) {
		$course_id = absint( $course_id );
		$input     = is_array( $input ) ? $input : array();

		$allowed_types = array( 'practice', 'assignment', 'partial_exam', 'final_exam', 'certifiable_evidence', 'required', 'read_only' );

		$valid_lessons = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course_id ) : array();
		$valid_lessons = array_values( array_filter( array_map( 'absint', $valid_lessons ) ) );
		$valid_map = array_fill_keys( $valid_lessons, true );

		$updated = 0;
		$messages = array();

		$row_updated = $this->save_step_5_rows( $input, $valid_map, $allowed_types );
		if ( $row_updated > 0 ) {
			$updated += $row_updated;
			$messages[] = sprintf(
				/* translators: %d: total */
				__( 'Se guardaron cambios por fila en %d actividades.', 'atora-lms' ),
				$row_updated
			);
		}

		$selected_lessons = isset( $input['wizard_evidence_selected'] ) ? (array) $input['wizard_evidence_selected'] : array();
		$selected_lessons = array_values( array_unique( array_filter( array_map( 'absint', $selected_lessons ) ) ) );
		if ( ! empty( $selected_lessons ) ) {
			$evidence_type = isset( $input['wizard_evidence_type'] ) ? sanitize_key( (string) $input['wizard_evidence_type'] ) : 'practice';
			if ( ! in_array( $evidence_type, $allowed_types, true ) ) {
				$evidence_type = 'practice';
			}
			$is_required = ! empty( $input['wizard_evidence_required'] ) ? '1' : '0';
			$minimum     = isset( $input['wizard_evidence_minimum_grade'] ) ? max( 0, min( 100, absint( $input['wizard_evidence_minimum_grade'] ) ) ) : 0;
			$competency_ids = isset( $input['wizard_evidence_competency_ids'] ) ? (array) $input['wizard_evidence_competency_ids'] : array();
			$competency_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $competency_ids ) ) ) );

			$batch_updated = 0;
			foreach ( $selected_lessons as $lesson_id ) {
				if ( ! isset( $valid_map[ $lesson_id ] ) ) {
					continue;
				}

				$this->update_evidence_meta( $lesson_id, $evidence_type, $is_required, $minimum, $competency_ids );
				$batch_updated++;
			}

			if ( $batch_updated > 0 ) {
				$updated += $batch_updated;
				$messages[] = sprintf(
					/* translators: %d: total */
					__( 'Se actualizó la configuración de evidencia en %d actividades.', 'atora-lms' ),
					$batch_updated
				);
			}
		}

		return array(
			'saved'   => $updated > 0,
			'message' => implode( ' ', array_filter( $messages ) ),
		);
	}

	/**
	 * Guardado de edición por fila en paso 5.
	 *
	 * @param array $input         Input.
	 * @param array $valid_map     Mapa de lecciones válidas del curso.
	 * @param array $allowed_types Tipos permitidos.
	 * @return int
	 */
	protected function save_step_5_rows( $input, $valid_map, $allowed_types ) {
		$input         = is_array( $input ) ? $input : array();
		$valid_map     = is_array( $valid_map ) ? $valid_map : array();
		$allowed_types = is_array( $allowed_types ) ? $allowed_types : array();

		$apply_rows = isset( $input['wizard_evidence_rows_apply'] ) ? (array) $input['wizard_evidence_rows_apply'] : array();
		$apply_rows = array_values( array_unique( array_filter( array_map( 'absint', $apply_rows ) ) ) );
		if ( empty( $apply_rows ) ) {
			return 0;
		}

		$rows = isset( $input['wizard_evidence_rows'] ) && is_array( $input['wizard_evidence_rows'] ) ? $input['wizard_evidence_rows'] : array();
		$updated = 0;
		foreach ( $apply_rows as $lesson_id ) {
			if ( ! isset( $valid_map[ $lesson_id ] ) || ! isset( $rows[ (string) $lesson_id ] ) || ! is_array( $rows[ (string) $lesson_id ] ) ) {
				continue;
			}
			$row = $rows[ (string) $lesson_id ];
			$type = isset( $row['type'] ) ? sanitize_key( (string) $row['type'] ) : 'practice';
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'practice';
			}
			$required = ! empty( $row['required'] ) ? '1' : '0';
			$minimum = isset( $row['minimum_grade'] ) ? max( 0, min( 100, absint( $row['minimum_grade'] ) ) ) : 0;
			$competency_ids = isset( $row['competency_ids'] ) ? (array) $row['competency_ids'] : array();
			$competency_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $competency_ids ) ) ) );

			$this->update_evidence_meta( $lesson_id, $type, $required, $minimum, $competency_ids );
			$updated++;
		}

		return $updated;
	}

	/**
	 * Actualiza metadatos de evidencia en una lección.
	 *
	 * @param int    $lesson_id       Lección.
	 * @param string $type            Tipo.
	 * @param string $required        Requerida.
	 * @param int    $minimum         Nota mínima.
	 * @param array  $competency_ids  Competencias.
	 * @return void
	 */
	protected function update_evidence_meta( $lesson_id, $type, $required, $minimum, $competency_ids ) {
		$lesson_id = absint( $lesson_id );
		$type      = sanitize_key( (string) $type );
		$required  = '1' === (string) $required ? '1' : '0';
		$minimum   = max( 0, min( 100, absint( $minimum ) ) );
		$competency_ids = is_array( $competency_ids ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', $competency_ids ) ) ) ) : array();

		if ( ! $lesson_id ) {
			return;
		}

		update_post_meta( $lesson_id, '_clms_evidence_type', $type );
		update_post_meta( $lesson_id, '_clms_evidence_required_for_certificate', $required );
		update_post_meta( $lesson_id, '_clms_evidence_competency_ids', $competency_ids );
		update_post_meta( $lesson_id, '_clms_evidence_minimum_grade', $minimum );
	}
}
