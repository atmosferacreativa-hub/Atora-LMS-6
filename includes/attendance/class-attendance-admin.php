<?php
/**
 * Asistencia presencial en la web (6.40.0): la misma lista que la app, visible y editable por el docente.
 *
 * @package ATORA_LMS
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Attendance_Admin {

	const SLUG = 'atora-attendance';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Asistencia', 'atora-lms' ), __( 'Asistencia', 'atora-lms' ), 'edit_lm_lessons', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/** @return array<int,string> cursos del docente (post => título) */
	private static function courses( int $user_id ): array {
		$out = array();
		foreach ( ATORA_Teacher_Scope::course_ids( $user_id ) as $wp ) {
			$out[ (int) $wp ] = (string) get_the_title( (int) $wp );
		}
		asort( $out );
		return $out;
	}

	public static function render(): void {
		$user_id = get_current_user_id();
		$courses = self::courses( $user_id );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtros de lectura.
		$course = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : (int) array_key_first( $courses );
		$date   = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['date'] ) ) : wp_date( 'Y-m-d' );
		$lesson = isset( $_GET['lesson'] ) ? absint( $_GET['lesson'] ) : 0;
		// phpcs:enable
		$notice = null;

		if ( $course && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['atora_attendance_nonce'] ) ) {
			check_admin_referer( 'atora_attendance_' . $course, 'atora_attendance_nonce' );
			$marks = array();
			$now   = gmdate( 'c' );
			foreach ( (array) wp_unslash( $_POST['status'] ?? array() ) as $student => $status ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el servicio valida cada marca.
				if ( '' === (string) $status ) {
					continue;
				}
				$marks[] = array(
					'student_id' => absint( $student ),
					'status'     => sanitize_key( (string) $status ),
					'note'       => sanitize_text_field( (string) wp_unslash( $_POST['note'][ $student ] ?? '' ) ),
					'marked_at'  => $now,
				);
			}
			$result = $marks ? ATORA_Attendance_Service::record_for( $user_id, $course, $lesson, $date, $marks ) : new WP_Error( 'empty', __( 'Marca al menos un estudiante.', 'atora-lms' ) );
			$notice = is_wp_error( $result )
				? array( 'error', $result->get_error_message() )
				/* translators: %d: count */
				: array( 'success', sprintf( __( 'Asistencia guardada (%d marcas).', 'atora-lms' ), (int) $result['applied'] ) );
		}

		$roster    = $course ? ATORA_Attendance_Service::roster_for( $user_id, $course, $lesson, $date ) : null;
		$labels    = array(
			'presente'    => __( 'Presente', 'atora-lms' ),
			'tarde'       => __( 'Tarde', 'atora-lms' ),
			'ausente'     => __( 'Ausente', 'atora-lms' ),
			'justificado' => __( 'Justificado', 'atora-lms' ),
		);
		$in_person = $course ? ATORA_Attendance_Service::in_person_lessons( $course ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Asistencia presencial', 'atora-lms' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> inline"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $courses ) : ?>
				<p><?php esc_html_e( 'No tienes cursos asignados.', 'atora-lms' ); ?></p>
			<?php else : ?>
				<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:12px 0;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<label><?php esc_html_e( 'Curso', 'atora-lms' ); ?><br>
						<select name="course">
							<?php foreach ( $courses as $id => $title ) : ?>
								<option value="<?php echo (int) $id; ?>" <?php selected( $course, $id ); ?>><?php echo esc_html( $title ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Fecha', 'atora-lms' ); ?><br><input type="date" name="date" value="<?php echo esc_attr( $date ); ?>"></label>
					<label><?php esc_html_e( 'Encuentro', 'atora-lms' ); ?><br>
						<select name="lesson">
							<option value="0"><?php esc_html_e( 'Clase del día (sin lección)', 'atora-lms' ); ?></option>
							<?php foreach ( $in_person as $wp_lesson ) : ?>
								<option value="<?php echo (int) $wp_lesson; ?>" <?php selected( $lesson, $wp_lesson ); ?>><?php echo esc_html( get_the_title( $wp_lesson ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button class="button"><?php esc_html_e( 'Ver lista', 'atora-lms' ); ?></button>
				</form>

				<?php if ( is_wp_error( $roster ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( $roster->get_error_message() ); ?></p></div>
				<?php elseif ( is_array( $roster ) ) : ?>
					<form method="post">
						<?php wp_nonce_field( 'atora_attendance_' . $course, 'atora_attendance_nonce' ); ?>
						<table class="widefat striped" style="max-width:1000px;">
							<thead><tr><th><?php esc_html_e( 'Estudiante', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Nota (tarde o justificado)', 'atora-lms' ); ?></th></tr></thead>
							<tbody>
								<?php foreach ( $roster['students'] as $student ) : ?>
									<tr>
										<td><?php echo esc_html( $student['name'] ); ?></td>
										<td>
											<?php foreach ( $labels as $value => $label ) : ?>
												<label style="margin-right:10px;white-space:nowrap;"><input type="radio" name="status[<?php echo (int) $student['id']; ?>]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $student['status'], $value ); ?>> <?php echo esc_html( $label ); ?></label>
											<?php endforeach; ?>
										</td>
										<td><input type="text" class="regular-text" name="note[<?php echo (int) $student['id']; ?>]" value="<?php echo esc_attr( $student['note'] ); ?>"></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button class="button button-primary"><?php esc_html_e( 'Guardar asistencia', 'atora-lms' ); ?></button></p>
					</form>
					<h2><?php esc_html_e( 'Sesiones recientes', 'atora-lms' ); ?></h2>
					<table class="widefat striped" style="max-width:1000px;">
						<thead><tr><th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th><th><?php esc_html_e( 'Encuentro', 'atora-lms' ); ?></th><th><?php echo esc_html( implode( ' · ', $labels ) ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( ATORA_Attendance_Service::recent_sessions( $course ) as $session ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'course' => $course, 'date' => $session['date'], 'lesson' => $session['lesson_id'] ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $session['date'] ); ?></a></td>
									<td><?php echo esc_html( $session['title'] ); ?></td>
									<td><?php echo esc_html( implode( ' · ', array_map( 'intval', $session['counts'] ) ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
