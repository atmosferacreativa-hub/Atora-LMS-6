<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PT-3 (6.11.0): pantalla mínima para asignar el coordinador de una
 * sección. Se agrega acá en vez de dentro del guardado del metabox de
 * cohorte (includes/class-metabox-cohort.php) a propósito -- ese metabox
 * guarda profesores como postmeta a nivel de cohorte (clms_cohort_teacher_ids),
 * no fila por fila en atora_section_teachers, y su save_post no toca esa
 * tabla; un formulario nuevo y aditivo evita arriesgar ese flujo existente.
 * Página oculta (sin entrada de menú visible), gateada a manage_options.
 */
trait CLMS_Admin_Menu_Section_Coordinator_Trait {

	/** Render de admin.php?page=atora-section-coordinator&section_id=X */
	public function render_section_coordinator_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$section_id = isset( $_GET['section_id'] ) ? absint( $_GET['section_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $section_id || ! class_exists( '\ATORA\LMS\Section_Service' ) ) {
			wp_die( esc_html__( 'Sección no encontrada.', 'atora-lms' ) );
		}

		$current_id = \ATORA\LMS\Section_Service::get_coordinator( $section_id );

		echo '<div class="wrap"><h1>' . esc_html__( 'Asignar coordinador de sección', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html( sprintf( __( 'Coordinador actual: %s', 'atora-lms' ), $current_id ? ( get_user_by( 'id', $current_id )->display_name ?? "ID {$current_id}" ) : '—' ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'atora_set_section_coordinator_' . $section_id, 'atora_section_coordinator_nonce' );
		echo '<input type="hidden" name="action" value="atora_set_section_coordinator">';
		echo '<input type="hidden" name="section_id" value="' . esc_attr( $section_id ) . '">';

		wp_dropdown_users(
			array(
				'name'            => 'coordinator_user_id',
				'selected'        => $current_id ?: 0,
				'show_option_none' => __( '— Sin coordinador —', 'atora-lms' ),
				'capability'      => 'edit_others_lm_courses',
			)
		);

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Guardar', 'atora-lms' ) . '</button></p>';
		echo '</form></div>';
	}

	/** Handler de admin-post.php?action=atora_set_section_coordinator */
	public function handle_set_section_coordinator(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$section_id = isset( $_POST['section_id'] ) ? absint( $_POST['section_id'] ) : 0;
		check_admin_referer( 'atora_set_section_coordinator_' . $section_id, 'atora_section_coordinator_nonce' );

		$coordinator_id = isset( $_POST['coordinator_user_id'] ) ? absint( $_POST['coordinator_user_id'] ) : 0;

		if ( $section_id && class_exists( '\ATORA\LMS\Section_Service' ) ) {
			if ( $coordinator_id ) {
				\ATORA\LMS\Section_Service::set_coordinator( $section_id, $coordinator_id );
			} else {
				// "— Sin coordinador —": quita cualquier fila coordinator existente.
				\ATORA\LMS\Section_Service::remove_teacher(
					$section_id,
					(int) \ATORA\LMS\Section_Service::get_coordinator( $section_id )
				);
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=atora-section-coordinator&section_id=' . $section_id ) );
		exit;
	}
}
