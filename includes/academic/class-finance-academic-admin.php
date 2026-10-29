<?php
/**
 * Página «Estado académico» para administración (6.41.0).
 *
 * El Auxiliar administrativo ve, en solo lectura, la nota final y el estado de cada estudiante por curso de su
 * institución, con la lista de quienes **no aprobaron y deben repetir** (la institución privada vuelve a cobrar el
 * curso). No puede cambiar notas ni matrículas. Exporta CSV.
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Finance_Academic_Admin {

	const SLUG = 'atora-academic-status';
	const CSV  = 'atora_academic_status_csv';


	public static function label( string $status ): string {
		switch ( $status ) {
			case 'approved':
				return __( 'Aprobado', 'atora-lms' );
			case 'not_approved':
				return __( 'No aprobado · debe repetir', 'atora-lms' );
			case 'at_risk':
				return __( 'En riesgo', 'atora-lms' );
			case 'in_progress':
				return __( 'En curso', 'atora-lms' );
			case 'not_started':
				return __( 'Sin iniciar', 'atora-lms' );
		}
		return $status;
	}

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 31 );
		add_action( 'admin_post_' . self::CSV, array( __CLASS__, 'csv' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Estado académico', 'atora-lms' ), __( 'Estado académico', 'atora-lms' ), 'atora_view_finance', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Estudiantes del curso con nota final y estado (solo lectura).
	 *
	 * @return array<int,array{id:int,name:string,email:string,final_grade:?float,status:string,adjusted:bool}>
	 */
	public static function rows( int $user_id, int $wp_course_id, bool $only_repeat = false ): array {
		if ( ! user_can( $user_id, 'atora_view_finance' ) || ! ATORA_Teacher_Scope::views_institution( $user_id, $wp_course_id ) ) {
			return array();
		}
		$rows = array();
		foreach ( array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( $wp_course_id ) ) as $student ) {
			$user = get_userdata( $student );
			if ( ! $user ) {
				continue;
			}
			$summary = CLMS_Student_Grades_Service::course_summary( $student, $wp_course_id );
			if ( $only_repeat && 'not_approved' !== $summary['status'] ) {
				continue;
			}
			$rows[] = array(
				'id'          => $student,
				'name'        => (string) $user->display_name,
				'email'       => (string) $user->user_email,
				'final_grade' => $summary['final_grade'],
				'status'      => (string) $summary['status'],
				'adjusted'    => ! empty( $summary['adjusted'] ),
			);
		}
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $rows;
	}

	public static function csv(): void {
		$course = absint( $_GET['course'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( self::CSV . '_' . $course );
		$user = get_current_user_id();
		if ( ! user_can( $user, 'atora_view_finance' ) || ! ATORA_Teacher_Scope::views_institution( $user, $course ) ) {
			wp_die( esc_html__( 'No tienes acceso a este curso.', 'atora-lms' ), '', array( 'response' => 403 ) );
		}
		$only = ! empty( $_GET['repeat'] ); // phpcs:ignore WordPress.Security.NonceVerification
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="estado-academico-' . $course . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( __( 'Estudiante', 'atora-lms' ), __( 'Correo', 'atora-lms' ), __( 'Curso', 'atora-lms' ), __( 'Nota final', 'atora-lms' ), __( 'Estado', 'atora-lms' ) ) );
		foreach ( self::rows( $user, $course, $only ) as $row ) {
			fputcsv( $out, array( $row['name'], $row['email'], get_the_title( $course ), null === $row['final_grade'] ? '' : $row['final_grade'], self::label( $row['status'] ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function render(): void {
		$user    = get_current_user_id();
		$courses = class_exists( 'ATORA_Final_Grade_Admin' ) ? ATORA_Final_Grade_Admin::courses( $user ) : array();
		$course  = absint( $_GET['course'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$only    = ! empty( $_GET['repeat'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $courses[ $course ] ) ) {
			$course = 0;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Estado académico', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'Solo lectura. Las notas las corrige Control académico.', 'atora-lms' ) . '</p>';
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label>' . esc_html__( 'Curso', 'atora-lms' ) . ' <select name="course"><option value="0">—</option>';
		foreach ( $courses as $id => $title ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $course, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select></label> <label><input type="checkbox" name="repeat" value="1"' . checked( $only, true, false ) . '> ' . esc_html__( 'Solo quienes deben repetir', 'atora-lms' ) . '</label> <button class="button">' . esc_html__( 'Ver', 'atora-lms' ) . '</button></form>';
		if ( ! $course ) {
			echo '</div>';
			return;
		}
		$csv = wp_nonce_url( add_query_arg( array( 'action' => self::CSV, 'course' => $course, 'repeat' => $only ? 1 : 0 ), admin_url( 'admin-post.php' ) ), self::CSV . '_' . $course );
		echo '<p><a class="button" href="' . esc_url( $csv ) . '">' . esc_html__( 'Exportar CSV', 'atora-lms' ) . '</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th><th>' . esc_html__( 'Nota final', 'atora-lms' ) . '</th><th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th></tr></thead><tbody>';
		foreach ( self::rows( $user, $course, $only ) as $row ) {
			$label = self::label( $row['status'] );
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( null === $row['final_grade'] ? '—' : number_format_i18n( (float) $row['final_grade'], 1 ) ) . ( $row['adjusted'] ? ' <small>' . esc_html__( '(corregida)', 'atora-lms' ) . '</small>' : '' ) . '</td><td>' . ( 'not_approved' === $row['status'] ? '<strong>' . esc_html( $label ) . '</strong>' : esc_html( $label ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
