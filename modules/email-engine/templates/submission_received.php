<?php
/**
 * Plantilla: Entrega recibida
 *
 * @package ATORA_LMS\EmailEngine
 */

$display_name_text = isset( $display_name ) ? (string) $display_name : '';
$lesson_title_text = isset( $lesson_title ) ? trim( (string) $lesson_title ) : '';
$lesson_label = '' !== $lesson_title_text ? $lesson_title_text : __( 'tu actividad', 'atora-lms' );
$submitted_label_text = isset( $submitted_label ) ? trim( wp_strip_all_tags( (string) $submitted_label ) ) : '';
$student_message_text = isset( $student_message ) ? trim( wp_strip_all_tags( (string) $student_message ) ) : '';
$retry_hint_text = isset( $retry_hint ) ? trim( wp_strip_all_tags( (string) $retry_hint ) ) : '';
$deadline_text = isset( $next_deadline ) ? trim( wp_strip_all_tags( (string) $next_deadline ) ) : '';
$evaluation_mode_key = sanitize_key( (string) ( $evaluation_mode ?? 'manual' ) );
$cta_url = ! empty( $lesson_url ) ? (string) $lesson_url : (string) ( $dashboard_url ?? home_url( '/' ) );

$evaluation_mode_label = __( 'Revisión docente', 'atora-lms' );
if ( 'ai_auto_grade' === $evaluation_mode_key ) {
	$evaluation_mode_label = __( 'Evaluación automática', 'atora-lms' );
} elseif ( 'ai_assisted' === $evaluation_mode_key ) {
	$evaluation_mode_label = __( 'Asistencia AI con revisión docente', 'atora-lms' );
} elseif ( 'peer_review' === $evaluation_mode_key ) {
	$evaluation_mode_label = __( 'Revisión entre pares', 'atora-lms' );
}
?>
<h1 style="margin:0 0 12px;color:#0f172a;font-size:24px;">
	<?php esc_html_e( 'Recibimos tu entrega', 'atora-lms' ); ?>
</h1>
<p style="margin:0 0 14px;color:#334155;">
	<?php
	printf(
		/* translators: 1: student name, 2: lesson title */
		esc_html__( 'Hola %1$s, tu entrega de %2$s quedó registrada correctamente.', 'atora-lms' ),
		esc_html( $display_name_text ),
		esc_html( $lesson_label )
	);
	?>
</p>

<div style="border:1px solid #dbeafe;border-radius:12px;padding:16px;background:#f8fbff;margin:0 0 14px;">
	<p style="margin:0 0 8px;color:#334155;">
		<strong><?php esc_html_e( 'Estado:', 'atora-lms' ); ?></strong>
		<?php esc_html_e( 'Recibida', 'atora-lms' ); ?>
	</p>
	<p style="margin:0 0 8px;color:#334155;">
		<strong><?php esc_html_e( 'Tipo de evaluación:', 'atora-lms' ); ?></strong>
		<?php echo esc_html( $evaluation_mode_label ); ?>
	</p>
	<?php if ( '' !== $submitted_label_text ) : ?>
		<p style="margin:0;color:#334155;">
			<strong><?php esc_html_e( 'Fecha de recepción:', 'atora-lms' ); ?></strong>
			<?php echo esc_html( $submitted_label_text ); ?>
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

<?php if ( '' !== $deadline_text ) : ?>
	<p style="margin:0 0 16px;color:#475569;">
		<?php
		printf(
			/* translators: %s: deadline date/time */
			esc_html__( 'Fecha límite: %s', 'atora-lms' ),
			esc_html( $deadline_text )
		);
		?>
	</p>
<?php endif; ?>

<p style="margin:0;">
	<a href="<?php echo esc_url( $cta_url ); ?>" style="display:inline-block;background:#0ea5e9;color:#ffffff;padding:11px 18px;border-radius:8px;text-decoration:none;font-weight:600;">
		<?php esc_html_e( 'Ver actividad', 'atora-lms' ); ?>
	</a>
</p>
