<?php
/**
 * Administración de IA (6.32.0): uso del mes por función, los 10 usuarios con
 * más consumo, funciones activadas y límites.
 *
 * @package ATORA_LMS
 * @since 6.32.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_AI_Usage_Admin {

	const PAGE = 'atora-ai-usage';

	public static function boot(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_atora_ai_settings', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Uso de IA', 'atora-lms' ), __( 'Uso de IA', 'atora-lms' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		check_admin_referer( 'atora_ai_settings' );
		ATORA_AI_Usage_Service::set_features( array(
			ATORA_AI_Usage_Service::ASSISTANT  => ! empty( $_POST['feature_assistant'] ),
			ATORA_AI_Usage_Service::SUGGESTION => ! empty( $_POST['feature_suggestion'] ),
		) );
		$cap = isset( $_POST['monthly_cost_cap'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['monthly_cost_cap'] ) ) ) : '';
		ATORA_AI_Usage_Service::set_limits( array(
			'student_daily'    => isset( $_POST['student_daily'] ) ? absint( $_POST['student_daily'] ) : 30,
			'teacher_daily'    => isset( $_POST['teacher_daily'] ) ? absint( $_POST['teacher_daily'] ) : 100,
			'monthly_cost_cap' => '' === $cap ? '' : (float) str_replace( ',', '.', $cap ),
		) );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		$features = ATORA_AI_Usage_Service::features();
		$limits   = ATORA_AI_Usage_Service::limits();
		$report   = ATORA_AI_Usage_Service::month_report();
		$labels   = array( 'assistant' => __( 'Asistente del estudiante', 'atora-lms' ), 'grading_suggestion' => __( 'Sugerencia de calificación', 'atora-lms' ) );
		$results  = array( 'ok' => __( 'respondidas', 'atora-lms' ), 'error' => __( 'con error', 'atora-lms' ), 'limit' => __( 'frenadas por límite', 'atora-lms' ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Uso de IA', 'atora-lms' ) . '</h1>';
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Ajustes guardados.', 'atora-lms' ) . '</p></div>';
		}
		if ( ! ATORA_AI_Usage_Service::provider_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No hay un proveedor de IA configurado: las funciones no estarán disponibles aunque se activen.', 'atora-lms' ) . '</p></div>';
		}

		echo '<h2>' . esc_html( sprintf( __( 'Este mes · costo estimado %s', 'atora-lms' ), number_format_i18n( $report['total'], 4 ) ) ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Función', 'atora-lms' ) . '</th><th>' . esc_html__( 'Resultado', 'atora-lms' ) . '</th><th>' . esc_html__( 'Llamadas', 'atora-lms' ) . '</th><th>' . esc_html__( 'Tokens (entrada / salida)', 'atora-lms' ) . '</th><th>' . esc_html__( 'Costo estimado', 'atora-lms' ) . '</th></tr></thead><tbody>';
		if ( ! $report['features'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Sin uso este mes.', 'atora-lms' ) . '</td></tr>';
		}
		foreach ( $report['features'] as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td><td>%s / %s</td><td>%s</td></tr>',
				esc_html( $labels[ $row['feature'] ] ?? $row['feature'] ),
				esc_html( $results[ $row['result'] ] ?? $row['result'] ),
				(int) $row['calls'],
				esc_html( number_format_i18n( (int) $row['tokens_in'] ) ),
				esc_html( number_format_i18n( (int) $row['tokens_out'] ) ),
				esc_html( number_format_i18n( (float) $row['cost'], 4 ) )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Usuarios con más consumo', 'atora-lms' ) . '</h2><ol>';
		foreach ( $report['top'] as $row ) {
			$user = get_userdata( (int) $row['user_id'] );
			printf( '<li>%s — %d %s · %s</li>', esc_html( $user ? $user->display_name : '#' . (int) $row['user_id'] ), (int) $row['calls'], esc_html__( 'llamadas', 'atora-lms' ), esc_html( number_format_i18n( (float) $row['cost'], 4 ) ) );
		}
		echo '</ol>';

		echo '<h2>' . esc_html__( 'Funciones y límites', 'atora-lms' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'atora_ai_settings' );
		echo '<input type="hidden" name="action" value="atora_ai_settings">';
		printf( '<p><label><input type="checkbox" name="feature_assistant" value="1" %s> %s</label></p>', checked( $features['assistant'], true, false ), esc_html__( 'Asistente del estudiante en la app', 'atora-lms' ) );
		printf( '<p><label><input type="checkbox" name="feature_suggestion" value="1" %s> %s</label></p>', checked( $features['grading_suggestion'], true, false ), esc_html__( 'Sugerencia de calificación para el docente (app y SpeedGrader)', 'atora-lms' ) );
		printf( '<p><label>%s <input type="number" min="0" name="student_daily" value="%d"></label></p>', esc_html__( 'Preguntas por estudiante y día', 'atora-lms' ), (int) $limits['student_daily'] );
		printf( '<p><label>%s <input type="number" min="0" name="teacher_daily" value="%d"></label></p>', esc_html__( 'Sugerencias por docente y día', 'atora-lms' ), (int) $limits['teacher_daily'] );
		printf( '<p><label>%s <input type="text" name="monthly_cost_cap" value="%s" placeholder="%s"></label></p>', esc_html__( 'Tope mensual de costo estimado (vacío = sin tope)', 'atora-lms' ), esc_attr( (string) $limits['monthly_cost_cap'] ), esc_attr__( 'sin tope', 'atora-lms' ) );
		submit_button( __( 'Guardar', 'atora-lms' ) );
		echo '</form></div>';
	}
}
