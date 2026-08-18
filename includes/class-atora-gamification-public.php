<?php
/**
 * Gamificación pública — shortcodes visibles para estudiantes (Fase III S9)
 *
 * Shortcodes:
 *   [atora_leaderboard course_id="X"]  — top 10 de un curso
 *
 * @package ATORA_LMS
 * @since   5.30.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_Gamification_Public {

	public static function register(): void {
		add_shortcode( 'atora_leaderboard', array( __CLASS__, 'render_leaderboard' ) );
	}

	/**
	 * [atora_leaderboard course_id="X"] — Top 10 estudiantes de un curso.
	 */
	public static function render_leaderboard( array $atts ): string {
		$atts      = shortcode_atts( array( 'course_id' => 0, 'limit' => 10 ), $atts, 'atora_leaderboard' );
		$course_id = absint( $atts['course_id'] );
		$limit     = min( 50, absint( $atts['limit'] ) );

		global $wpdb;

		$enroll_table  = $wpdb->prefix . 'atora_enrollments';
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		$users_table    = $wpdb->users;

		// Datos de progreso desde atora_enrollments + puntos desde usermeta de gamificación
		$where = $course_id ? $wpdb->prepare( 'WHERE e.course_id = %d', $course_id ) : '';

		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT
					e.user_id,
					e.progress_pct,
					u.display_name,
					u.user_email,
					COALESCE(
						(SELECT CAST(JSON_EXTRACT(um.meta_value, '$.points') AS UNSIGNED)
						 FROM {$wpdb->usermeta} um
						 WHERE um.user_id = e.user_id
						   AND um.meta_key = '_clms_gamification_summary'
						 LIMIT 1), 0
					) AS total_points
				 FROM {$enroll_table} e
				 LEFT JOIN {$users_table} u ON u.ID = e.user_id
				 {$where}
				 ORDER BY total_points DESC, e.progress_pct DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return '<p class="atora-leaderboard-empty">' . esc_html__( 'Aún no hay datos de clasificación.', 'atora-lms' ) . '</p>';
		}

		ob_start();
		?>
		<div class="atora-leaderboard" style="font-family:sans-serif;max-width:600px">
			<table style="width:100%;border-collapse:collapse;font-size:14px">
				<thead>
					<tr style="background:#1e3a8a;color:#fff">
						<th style="padding:10px 12px;text-align:left;width:40px">#</th>
						<th style="padding:10px 12px;text-align:left"><?php esc_html_e( 'Estudiante', 'atora-lms' ); ?></th>
						<th style="padding:10px 12px;text-align:center"><?php esc_html_e( 'Puntos', 'atora-lms' ); ?></th>
						<th style="padding:10px 12px;text-align:center"><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $i => $row ) :
					$rank        = $i + 1;
					$name        = sanitize_text_field( (string) ( $row['display_name'] ?? __( 'Estudiante', 'atora-lms' ) ) );
					$points      = absint( $row['total_points'] ?? 0 );
					$progress    = absint( $row['progress_pct'] ?? 0 );
					$avatar_url  = get_avatar_url( absint( $row['user_id'] ?? 0 ), array( 'size' => 32 ) );
					$bg          = $rank <= 3 ? array( '#fef9c3', '#f1f5f9', '#fef2f2' )[ $rank - 1 ] : '#fff';
					$medal       = array( 1 => '🥇', 2 => '🥈', 3 => '🥉' )[ $rank ] ?? $rank;
				?>
					<tr style="background:<?php echo esc_attr( $bg ); ?>;border-bottom:.5px solid #e2e8f0">
						<td style="padding:10px 12px;font-weight:700;font-size:16px"><?php echo esc_html( (string) $medal ); ?></td>
						<td style="padding:10px 12px">
							<span style="display:flex;align-items:center;gap:8px">
								<img src="<?php echo esc_url( $avatar_url ); ?>" alt="" style="width:28px;height:28px;border-radius:50%;object-fit:cover">
								<?php echo esc_html( $name ); ?>
							</span>
						</td>
						<td style="padding:10px 12px;text-align:center;font-weight:600;color:#1d4ed8"><?php echo esc_html( number_format_i18n( $points ) ); ?></td>
						<td style="padding:10px 12px;text-align:center">
							<div style="background:#e2e8f0;border-radius:999px;height:6px;width:80px;display:inline-block;vertical-align:middle">
								<div style="width:<?php echo esc_attr( (string) $progress ); ?>%;background:#1d9e75;height:6px;border-radius:999px"></div>
							</div>
							<span style="font-size:12px;color:#64748b;margin-left:6px"><?php echo esc_html( $progress . '%' ); ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}
}

ATORA_Gamification_Public::register();
