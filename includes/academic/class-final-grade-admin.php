<?php
/**
 * Página «Notas finales» (6.41.0): Control académico ve la nota final calculada y la ajustada de cada estudiante de
 * un curso de su institución, la corrige con motivo obligatorio o quita la corrección, y ve el historial.
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Final_Grade_Admin {

	const SLUG   = 'atora-final-grades';
	const ACTION = 'atora_final_grade';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Notas finales', 'atora-lms' ), __( 'Notas finales', 'atora-lms' ), 'atora_view_academic_records', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** Cursos de la institución de quien mira. @return array<int,string> */
	public static function courses( int $user_id ): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'lm_course', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC', 'fields' => 'ids' ) ) as $id ) {
			if ( ATORA_Teacher_Scope::views_institution( $user_id, (int) $id ) ) {
				$out[ (int) $id ] = get_the_title( $id );
			}
		}
		return $out;
	}

	/** Filas del curso: estudiante, calculada, ajustada. */
	public static function rows( int $wp_course_id ): array {
		$rows = array();
		foreach ( array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( $wp_course_id ) ) as $student ) {
			$user = get_userdata( $student );
			if ( ! $user ) {
				continue;
			}
			$rows[] = array(
				'id'         => $student,
				'name'       => (string) $user->display_name,
				'calculated' => ATORA_Final_Grade_Service::calculated( $student, $wp_course_id ),
				'adjusted'   => ATORA_Final_Grade_Service::current( $student, $wp_course_id ),
			);
		}
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return $rows;
	}

	public static function handle(): void {
		$course  = absint( $_POST['course'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$student = absint( $_POST['student'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( self::ACTION . '_' . $course . '_' . $student );
		$remove = ! empty( $_POST['remove'] );
		$grade  = $remove ? null : ( isset( $_POST['grade'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['grade'] ) ) ) : '' );
		$result = ATORA_Final_Grade_Service::adjust( get_current_user_id(), $student, $course, '' === $grade ? 'x' : $grade, sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
		$notice = is_wp_error( $result ) ? 'error:' . $result->get_error_message() : ( $remove ? 'removed' : 'saved' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'course' => $course, 'notice' => rawurlencode( $notice ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		$user    = get_current_user_id();
		$courses = self::courses( $user );
		$course  = absint( $_GET['course'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $courses[ $course ] ) ) {
			$course = 0;
		}
		$notice = sanitize_text_field( rawurldecode( (string) ( $_GET['notice'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap"><h1>' . esc_html__( 'Notas finales', 'atora-lms' ) . '</h1>';
		echo '<p>' . esc_html__( 'La nota final se calcula con las entregas y cuestionarios del curso. Una corrección reemplaza a la calculada para el estudiante, el boletín, los certificados y los informes. Las notas parciales se corrigen desde la calificación del docente.', 'atora-lms' ) . '</p>';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Nota final corregida.', 'atora-lms' ) . '</p></div>';
		} elseif ( 'removed' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Corrección quitada: vuelve a regir la nota calculada.', 'atora-lms' ) . '</p></div>';
		} elseif ( 0 === strpos( $notice, 'error:' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( substr( $notice, 6 ) ) . '</p></div>';
		}
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><label>' . esc_html__( 'Curso', 'atora-lms' ) . ' <select name="course" onchange="this.form.submit()"><option value="0">—</option>';
		foreach ( $courses as $id => $title ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $course, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select></label> <button class="button">' . esc_html__( 'Ver', 'atora-lms' ) . '</button></form>';
		if ( ! $course ) {
			echo '</div>';
			return;
		}
		$can = ATORA_Final_Grade_Service::can_adjust( $user, $course );
		echo '<table class="widefat striped" style="margin-top:16px"><thead><tr><th>' . esc_html__( 'Estudiante', 'atora-lms' ) . '</th><th>' . esc_html__( 'Calculada', 'atora-lms' ) . '</th><th>' . esc_html__( 'Corregida', 'atora-lms' ) . '</th><th>' . esc_html__( 'Corregir', 'atora-lms' ) . '</th></tr></thead><tbody>';
		foreach ( self::rows( $course ) as $row ) {
			$adjusted = $row['adjusted'];
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( null === $row['calculated'] ? '—' : number_format_i18n( $row['calculated'], 0 ) ) . '</td>';
			echo '<td>' . ( $adjusted ? esc_html( number_format_i18n( (float) $adjusted['grade'], 2 ) ) . '<br><small>' . esc_html( $adjusted['reason'] ) . ' · ' . esc_html( $adjusted['by'] ) . '</small>' : '—' ) . '</td><td>';
			if ( $can ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( self::ACTION . '_' . $course . '_' . $row['id'] );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="course" value="' . esc_attr( (string) $course ) . '"><input type="hidden" name="student" value="' . esc_attr( (string) $row['id'] ) . '">';
				echo '<input type="text" inputmode="decimal" name="grade" size="5" aria-label="' . esc_attr__( 'Nota final', 'atora-lms' ) . '"> ';
				echo '<input type="text" name="reason" size="30" required minlength="5" placeholder="' . esc_attr__( 'Motivo (obligatorio)', 'atora-lms' ) . '" aria-label="' . esc_attr__( 'Motivo', 'atora-lms' ) . '"> ';
				echo '<button class="button button-primary">' . esc_html__( 'Corregir', 'atora-lms' ) . '</button>';
				if ( $adjusted ) {
					echo ' <button class="button" name="remove" value="1">' . esc_html__( 'Quitar corrección', 'atora-lms' ) . '</button>';
				}
				echo '</form>';
				$history = ATORA_Final_Grade_Service::history( $row['id'], $course );
				if ( $history ) {
					echo '<details><summary>' . esc_html__( 'Historial', 'atora-lms' ) . '</summary><ul>';
					foreach ( $history as $item ) {
						/* translators: 1: date, 2: who, 3: previous, 4: new, 5: reason */
						echo '<li>' . esc_html( sprintf( __( '%1$s · %2$s: %3$s → %4$s (%5$s)', 'atora-lms' ), $item['at'], $item['by'], null === $item['previous_grade'] ? '—' : $item['previous_grade'], $item['removed'] ? __( 'calculada', 'atora-lms' ) : $item['grade'], $item['reason'] ) ) . '</li>';
					}
					echo '</ul></details>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
