<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PT-2 (6.11.0): ficha de estudiante -- resumen académico (curso puntual)
 * + timeline de intervenciones (CLMS_Contacts_Core_Service), con un form
 * para dejar una nota manual. No existía ninguna vista de este tipo antes
 * de este sprint (ver docs/DEUDA-TECNICA.md, "no existe una ficha de
 * estudiante/sección en ningún lado"). Página oculta (sin entrada de menú
 * visible): se llega por link, no por navegación.
 */
trait CLMS_Admin_Menu_Student_Profile_Trait {

	/**
	 * Gate de capacidad: mismo criterio corregido en 6.9.1 (PT-1) para
	 * get_rubrics() -- edit_others_lm_courses/manage_options ven todo, o
	 * el usuario es el docente/coordinador efectivo de ESTE estudiante en
	 * ESTE curso.
	 *
	 * @param int $student_id Estudiante.
	 * @param int $course_id  Curso.
	 * @return bool
	 */
	protected function current_user_can_view_student_profile( int $student_id, int $course_id ): bool {
		if ( current_user_can( 'edit_others_lm_courses' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$instructor  = \ATORA\LMS\Section_Service::get_effective_instructor( $student_id, $course_id );
		$coordinator = \ATORA\LMS\Section_Service::get_effective_coordinator( $student_id, $course_id );

		return ( $instructor && (int) $instructor === (int) $user_id )
			|| ( $coordinator && (int) $coordinator === (int) $user_id );
	}

	/** Render de admin.php?page=atora-student-profile&student_id=X&course_id=Y */
	public function render_student_profile_page(): void {
		$student_id = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$course_id  = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $student_id || ! get_userdata( $student_id ) ) {
			wp_die( esc_html__( 'Estudiante no encontrado.', 'atora-lms' ) );
		}

		if ( ! $this->current_user_can_view_student_profile( $student_id, $course_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$student = get_userdata( $student_id );
		$status  = null;
		if ( $course_id && class_exists( 'CLMS_Helper' ) ) {
			$service = clms_core( 'CLMS_Academic_Status_Service' );
			if ( $service && method_exists( $service, 'get_student_course_status' ) ) {
				$status = $service->get_student_course_status( $student_id, $course_id );
			}
		}

		$timeline = array();
		if ( class_exists( 'CLMS_Helper' ) ) {
			$contacts_core = clms_core( 'CLMS_Contacts_Core_Service' );
			if ( $contacts_core && method_exists( $contacts_core, 'get_timeline_for_student' ) ) {
				$timeline = $contacts_core->get_timeline_for_student( $student_id, 50 );
			}
		}

		echo '<div class="wrap"><h1>' . esc_html( sprintf( __( 'Ficha de estudiante — %s', 'atora-lms' ), $student->display_name ) ) . '</h1>';

		if ( $course_id ) {
			echo '<h2>' . esc_html( sprintf( __( 'Curso: %s', 'atora-lms' ), get_the_title( $course_id ) ) ) . '</h2>';
			if ( is_array( $status ) ) {
				echo '<p>';
				echo esc_html( sprintf( __( 'Promedio: %s', 'atora-lms' ), isset( $status['final_average'] ) && null !== $status['final_average'] ? $status['final_average'] . '%' : '—' ) ) . '<br>';
				echo esc_html( sprintf( __( 'Progreso: %s', 'atora-lms' ), isset( $status['progress_percent'] ) ? $status['progress_percent'] . '%' : '—' ) );
				echo '</p>';
			}
		}

		echo '<h2>' . esc_html__( 'Línea de tiempo', 'atora-lms' ) . '</h2>';
		if ( empty( $timeline ) ) {
			echo '<p>' . esc_html__( 'Todavía no hay actividad registrada para este estudiante.', 'atora-lms' ) . '</p>';
		} else {
			echo '<ul class="atora-student-timeline">';
			foreach ( $timeline as $entry ) {
				$data = json_decode( (string) ( $entry->activity_data ?? '' ), true );
				echo '<li><strong>' . esc_html( $entry->activity_type ?? '' ) . '</strong> — ' . esc_html( $entry->created_at ?? '' );
				if ( 'academic_note' === ( $entry->activity_type ?? '' ) && ! empty( $data['note'] ) ) {
					echo '<br>' . esc_html( $data['note'] );
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '<h2>' . esc_html__( 'Agregar nota', 'atora-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'atora_student_profile_add_note_' . $student_id . '_' . $course_id, 'atora_student_profile_nonce' );
		echo '<input type="hidden" name="action" value="atora_student_profile_add_note">';
		echo '<input type="hidden" name="student_id" value="' . esc_attr( $student_id ) . '">';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( $course_id ) . '">';
		echo '<textarea name="note" rows="4" class="large-text"></textarea>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Guardar nota', 'atora-lms' ) . '</button></p>';
		echo '</form>';

		echo '</div>';
	}

	/** Handler de admin-post.php?action=atora_student_profile_add_note */
	public function handle_student_profile_add_note(): void {
		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
		$course_id  = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;

		check_admin_referer( 'atora_student_profile_add_note_' . $student_id . '_' . $course_id, 'atora_student_profile_nonce' );

		if ( ! $student_id || ! $this->current_user_can_view_student_profile( $student_id, $course_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$note = isset( $_POST['note'] ) ? wp_unslash( (string) $_POST['note'] ) : '';
		if ( '' !== trim( $note ) && class_exists( 'CLMS_Helper' ) ) {
			$contacts_core = clms_core( 'CLMS_Contacts_Core_Service' );
			if ( $contacts_core && method_exists( $contacts_core, 'log_academic_note' ) ) {
				$contacts_core->log_academic_note( $student_id, $course_id, $note, get_current_user_id() );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=atora-student-profile&student_id=' . $student_id . '&course_id=' . $course_id ) );
		exit;
	}
}
