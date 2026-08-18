<?php
/**
 * Servicio para resolver el siguiente paso del estudiante.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Student_Next_Step_Service {

	/**
	 * Resuelve el siguiente paso con prioridad pedagógica.
	 *
	 * @param int   $user_id       Usuario estudiante.
	 * @param array $continue_item Lección en curso.
	 * @param array $pending_items Pendientes.
	 * @param array $lesson_items  Próximas lecciones.
	 * @param array $course_ids    Cursos inscritos.
	 * @return array<string,mixed>
	 */
	public function resolve_next_step( $user_id, $continue_item, $pending_items, $lesson_items, $course_ids = array() ) {
		$user_id       = absint( $user_id );
		$continue_item = is_array( $continue_item ) ? $continue_item : array();
		$pending_items = is_array( $pending_items ) ? array_values( $pending_items ) : array();
		$lesson_items  = is_array( $lesson_items ) ? array_values( $lesson_items ) : array();
		$course_ids    = is_array( $course_ids ) ? array_values( array_filter( array_map( 'absint', $course_ids ) ) ) : array();

		if ( ! $user_id ) {
			return $this->empty_step();
		}

		foreach ( $pending_items as $item ) {
			$item = is_array( $item ) ? $item : array();
			$status = isset( $item['status'] ) ? sanitize_key( (string) $item['status'] ) : '';
			if ( in_array( $status, array( 'returned', 'needs_revision' ), true ) ) {
				return $this->build_from_pending( $item, 'feedback', 'high' );
			}
		}

		if ( ! empty( $pending_items[0] ) && is_array( $pending_items[0] ) ) {
			return $this->build_from_pending( $pending_items[0], 'assignment', 'high' );
		}

		$certificate = $this->get_certificate_priority_step( $user_id, $course_ids );
		if ( ! empty( $certificate ) ) {
			return $certificate;
		}

		if ( ! empty( $continue_item ) ) {
			return $this->build_from_continue_item( $continue_item );
		}

		if ( ! empty( $lesson_items[0] ) && is_array( $lesson_items[0] ) ) {
			return $this->build_from_lesson_item( $lesson_items[0] );
		}

		if ( ! empty( $course_ids[0] ) ) {
			$course_id = absint( $course_ids[0] );
			return $this->build_step(
				'course',
				__( 'Comienza tu curso activo', 'atora-lms' ),
				__( 'Ingresa al curso para iniciar tu ruta de aprendizaje.', 'atora-lms' ),
				$course_id ? get_permalink( $course_id ) : '',
				__( 'Iniciar curso', 'atora-lms' ),
				'medium',
				array(
					'course_id' => $course_id,
					'meta'      => $course_id ? get_the_title( $course_id ) : '',
				)
			);
		}

		return $this->empty_step();
	}

	/**
	 * Construye paso desde pendiente.
	 *
	 * @param array  $item     Item de pendiente.
	 * @param string $type     Tipo de paso.
	 * @param string $priority Prioridad.
	 * @return array<string,mixed>
	 */
	protected function build_from_pending( $item, $type = 'assignment', $priority = 'high' ) {
		$item = is_array( $item ) ? $item : array();
		$type = sanitize_key( (string) $type );

		$title = ! empty( $item['lesson_title'] )
			? sanitize_text_field( (string) $item['lesson_title'] )
			: __( 'Actividad pendiente', 'atora-lms' );

		$description = ! empty( $item['description'] )
			? sanitize_text_field( (string) $item['description'] )
			: __( 'Tienes una actividad pendiente por completar.', 'atora-lms' );

		$button = 'feedback' === $type
			? __( 'Revisar feedback', 'atora-lms' )
			: __( 'Ir a la actividad', 'atora-lms' );

		return $this->build_step(
			$type,
			$title,
			$description,
			isset( $item['url'] ) ? (string) $item['url'] : '',
			$button,
			$priority,
			array(
				'lesson_id'     => absint( $item['lesson_id'] ?? 0 ),
				'course_id'     => absint( $item['course_id'] ?? 0 ),
				'meta'          => sanitize_text_field( (string) ( $item['course_title'] ?? '' ) ),
				'is_evaluable'  => ! empty( $item['is_evaluable'] ),
			)
		);
	}

	/**
	 * Construye paso desde lección en curso.
	 *
	 * @param array $continue_item Lección en curso.
	 * @return array<string,mixed>
	 */
	protected function build_from_continue_item( $continue_item ) {
		$continue_item = is_array( $continue_item ) ? $continue_item : array();

		return $this->build_step(
			'lesson',
			! empty( $continue_item['lesson_title'] ) ? sanitize_text_field( (string) $continue_item['lesson_title'] ) : __( 'Continuar lección', 'atora-lms' ),
			__( 'Continúa donde lo dejaste para mantener tu avance.', 'atora-lms' ),
			isset( $continue_item['url'] ) ? (string) $continue_item['url'] : '',
			__( 'Continuar mi ruta', 'atora-lms' ),
			'medium',
			array(
				'lesson_id'    => absint( $continue_item['lesson_id'] ?? 0 ),
				'course_id'    => absint( $continue_item['course_id'] ?? 0 ),
				'meta'         => sanitize_text_field( (string) ( $continue_item['course_title'] ?? '' ) ),
				'is_evaluable' => ! empty( $continue_item['is_evaluable'] ),
			)
		);
	}

	/**
	 * Construye paso desde lección disponible.
	 *
	 * @param array $item Item de lección.
	 * @return array<string,mixed>
	 */
	protected function build_from_lesson_item( $item ) {
		$item = is_array( $item ) ? $item : array();

		return $this->build_step(
			'lesson',
			! empty( $item['lesson_title'] ) ? sanitize_text_field( (string) $item['lesson_title'] ) : __( 'Siguiente lección', 'atora-lms' ),
			__( 'Avanza a tu próxima lección disponible.', 'atora-lms' ),
			isset( $item['url'] ) ? (string) $item['url'] : '',
			__( 'Ir a la lección', 'atora-lms' ),
			'medium',
			array(
				'lesson_id'    => absint( $item['lesson_id'] ?? 0 ),
				'course_id'    => absint( $item['course_id'] ?? 0 ),
				'meta'         => sanitize_text_field( (string) ( $item['course_title'] ?? '' ) ),
				'is_evaluable' => ! empty( $item['is_evaluable'] ),
			)
		);
	}

	/**
	 * Devuelve paso orientado a certificado cuando sea prioridad.
	 *
	 * @param int   $user_id    Estudiante.
	 * @param array $course_ids Cursos inscritos.
	 * @return array<string,mixed>
	 */
	protected function get_certificate_priority_step( $user_id, $course_ids ) {
		$certificates = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Certificates') : null;
		if ( ! $certificates || ! method_exists( $certificates, 'get_certificate_status_for_student_course' ) ) {
			return array();
		}

		foreach ( $course_ids as $course_id ) {
			$course_id = absint( $course_id );
			if ( ! $course_id ) {
				continue;
			}

			$status = (array) $certificates->get_certificate_status_for_student_course( $user_id, $course_id );
			$key    = isset( $status['status'] ) ? sanitize_key( (string) $status['status'] ) : 'pending';

			if ( in_array( $key, array( 'eligible', 'issued', 'valid' ), true ) ) {
				$view_url = '';
				if ( method_exists( $certificates, 'get_view_certificate_url' ) ) {
					$view_url = (string) $certificates->get_view_certificate_url( $user_id, $course_id );
				}
				if ( '' === $view_url ) {
					$view_url = get_permalink( $course_id );
				}

				return $this->build_step(
					'certificate',
					__( 'Certificado disponible', 'atora-lms' ),
					__( 'Ya cumples los requisitos para revisar tu certificado.', 'atora-lms' ),
					$view_url,
					__( 'Ver certificado', 'atora-lms' ),
					'high',
					array(
						'course_id' => $course_id,
						'meta'      => get_the_title( $course_id ),
					)
				);
			}
		}

		return array();
	}

	/**
	 * Construye estructura estándar y compatibilidad legacy.
	 *
	 * @param string $type         Tipo de siguiente paso.
	 * @param string $title        Título visible.
	 * @param string $description  Descripción visible.
	 * @param string $url          URL principal.
	 * @param string $button_label Etiqueta CTA.
	 * @param string $priority     Prioridad.
	 * @param array  $extra        Metadatos extra.
	 * @return array<string,mixed>
	 */
	protected function build_step( $type, $title, $description, $url, $button_label, $priority, $extra = array() ) {
		$extra = is_array( $extra ) ? $extra : array();

		$meta = isset( $extra['meta'] ) ? sanitize_text_field( (string) $extra['meta'] ) : '';

		return array(
			'type'         => sanitize_key( (string) $type ),
			'title'        => sanitize_text_field( (string) $title ),
			'description'  => sanitize_text_field( (string) $description ),
			'url'          => esc_url_raw( (string) $url ),
			'button_label' => sanitize_text_field( (string) $button_label ),
			'priority'     => sanitize_key( (string) $priority ),
			'meta'         => $meta,
			'lesson_id'    => absint( $extra['lesson_id'] ?? 0 ),
			'course_id'    => absint( $extra['course_id'] ?? 0 ),
			'is_evaluable' => ! empty( $extra['is_evaluable'] ),
		);
	}

	/**
	 * Paso vacío útil.
	 *
	 * @return array<string,mixed>
	 */
	protected function empty_step() {
		return array(
			'type'         => 'empty',
			'title'        => __( 'Todo al día', 'atora-lms' ),
			'description'  => __( 'No hay acciones urgentes por ahora. Revisa tus cursos para seguir avanzando.', 'atora-lms' ),
			'url'          => '',
			'button_label' => __( 'Ver mis cursos', 'atora-lms' ),
			'priority'     => 'low',
			'meta'         => '',
			'lesson_id'    => 0,
			'course_id'    => 0,
			'is_evaluable' => false,
		);
	}
}

