<?php
/**
 * Plantilla: Calificación publicada
 *
 * @package ATORA_LMS\EmailEngine
 */

$grade_value = isset( $grade ) && '' !== (string) $grade ? (int) max( 0, min( 100, round( (float) $grade ) ) ) : 0;
$best_score_value = isset( $best_score ) && '' !== (string) $best_score ? (int) max( 0, min( 100, round( (float) $best_score ) ) ) : $grade_value;
$latest_score_value = isset( $latest_score ) && '' !== (string) $latest_score ? (int) max( 0, min( 100, round( (float) $latest_score ) ) ) : $grade_value;
$attempts_value = isset( $attempts ) ? absint( $attempts ) : 0;
$remaining_attempts_value = isset( $remaining_attempts ) ? (int) $remaining_attempts : -1;
$is_quiz_eval = ! empty( $is_quiz ) || 'quiz' === sanitize_key( (string) ( $evaluation_type ?? '' ) );
$display_name_text = isset( $display_name ) ? (string) $display_name : '';
$lesson_title_text = isset( $lesson_title ) ? trim( (string) $lesson_title ) : '';
$lesson_label = '' !== $lesson_title_text ? $lesson_title_text : __( 'esta evaluación', 'atora-lms' );
$student_message_text = isset( $student_message ) ? trim( wp_strip_all_tags( (string) $student_message ) ) : '';
$retry_hint_text = isset( $retry_hint ) ? trim( wp_strip_all_tags( (string) $retry_hint ) ) : '';
$deadline_text = isset( $next_deadline ) ? trim( wp_strip_all_tags( (string) $next_deadline ) ) : '';
$cta_url = ! empty( $lesson_url ) ? (string) $lesson_url : (string) ( $dashboard_url ?? home_url( '/' ) );
$score_color = '#0ea5e9';

if ( $grade_value >= 90 ) {
	$score_color = '#16a34a';
} elseif ( $grade_value < 70 ) {
	$score_color = '#dc2626';
}
?>
<h1 style="margin:0 0 12px;color:#0f172a;font-size:24px;">
	<?php esc_html_e( 'Tu calificación ya está disponible', 'atora-lms' ); ?>
</h1>
<p style="margin:0 0 14px;color:#334155;">
	<?php
	printf(
		/* translators: 1: student name, 2: lesson title */
		esc_html__( 'Hola %1$s, ya puedes revisar tu resultado en %2$s.', 'atora-lms' ),
		esc_html( $display_name_text ),
		esc_html( $lesson_label )
	);
	?>
</p>

<div style="border:1px solid #dbeafe;border-radius:12px;padding:16px;background:#f8fbff;margin:0 0 14px;">
	<p style="margin:0 0 8px;font-size:13px;color:#475569;"><?php esc_html_e( 'Calificación publicada', 'atora-lms' ); ?></p>
	<p style="margin:0;font-size:30px;line-height:1.1;font-weight:700;color:<?php echo esc_attr( $score_color ); ?>;">
		<?php echo esc_html( $grade_value ); ?>/100
	</p>
	<?php if ( $is_quiz_eval ) : ?>
		<p style="margin:10px 0 0;color:#334155;font-size:14px;">
			<?php
			printf(
				/* translators: 1: best score, 2: latest score */
				esc_html__( 'Mejor resultado: %1$s%% · Último intento: %2$s%%', 'atora-lms' ),
				esc_html( $best_score_value ),
				esc_html( $latest_score_value )
			);
			?>
			<?php if ( $attempts_value > 0 ) : ?>
				<?php
				printf(
					/* translators: %d: attempt number */
					esc_html__( ' · Intento #%d', 'atora-lms' ),
					esc_html( $attempts_value )
				);
				?>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>

<?php if ( '' !== $student_message_text ) : ?>
	<div style="border-left:4px solid #38bdf8;background:#f0f9ff;padding:12px 14px;margin:0 0 12px;color:#0f172a;">
		<?php echo esc_html( $student_message_text ); ?>
	</div>
<?php endif; ?>

<?php if ( '' !== $retry_hint_text ) : ?>
	<p style="margin:0 0 10px;color:#334155;"><?php echo esc_html( $retry_hint_text ); ?></p>
<?php endif; ?>

<?php if ( $remaining_attempts_value >= 0 && ! empty( $retry_available ) ) : ?>
	<p style="margin:0 0 10px;color:#334155;">
		<?php
		printf(
			/* translators: %d: remaining attempts */
			esc_html__( 'Intentos disponibles: %d.', 'atora-lms' ),
			esc_html( $remaining_attempts_value )
		);
		?>
	</p>
<?php endif; ?>

<?php if ( '' !== $deadline_text ) : ?>
	<p style="margin:0 0 16px;color:#475569;">
		<?php
		printf(
			/* translators: %s: deadline date/time */
			esc_html__( 'Fecha límite para nuevos intentos: %s', 'atora-lms' ),
			esc_html( $deadline_text )
		);
		?>
	</p>
<?php endif; ?>

<p style="margin:0;">
	<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;background:#0ea5e9;color:#ffffff;padding:11px 18px;border-radius:8px;text-decoration:none;font-weight:600;">
		<?php esc_html_e( 'Ver evaluación', 'atora-lms' ); ?>
	</a>
</p>
