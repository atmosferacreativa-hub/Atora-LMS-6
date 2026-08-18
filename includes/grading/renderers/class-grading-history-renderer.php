<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Grading_History_Renderer {

	/**
	 * Renderiza el panel visual de historial académico en SpeedGrade.
	 *
	 * @param array $context Contexto de entrega.
	 * @param array $callbacks Formateadores opcionales.
	 * @return string
	 */
	public static function render( $context, $callbacks = array() ) {
		$context   = is_array( $context ) ? $context : array();
		$callbacks = is_array( $callbacks ) ? $callbacks : array();

		$format_risk = isset( $callbacks['format_risk_label'] ) && is_callable( $callbacks['format_risk_label'] )
			? $callbacks['format_risk_label']
			: static function( $value ) {
				return (string) $value;
			};

		$format_cert = isset( $callbacks['format_certificate_status_label'] ) && is_callable( $callbacks['format_certificate_status_label'] )
			? $callbacks['format_certificate_status_label']
			: static function( $value ) {
				return (string) $value;
			};

		$format_assessment = isset( $callbacks['format_assessment_label'] ) && is_callable( $callbacks['format_assessment_label'] )
			? $callbacks['format_assessment_label']
			: static function( $value ) {
				return (string) $value;
			};

		ob_start();
		?>
		<div class="clms-sg-meta-grid">
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></span>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: progress percent, 2: completed lessons, 3: total lessons */
							__( '%1$s%% (%2$s/%3$s lecciones)', 'atora-lms' ),
							absint( $context['student_snapshot']['progress_percent'] ?? 0 ),
							absint( $context['student_snapshot']['completed_lessons'] ?? 0 ),
							absint( $context['student_snapshot']['total_lessons'] ?? 0 )
						)
					);
					?>
				</strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Última actividad', 'atora-lms' ); ?></span>
				<strong><?php echo esc_html( ! empty( $context['student_snapshot']['last_activity'] ) ? (string) $context['student_snapshot']['last_activity'] : __( 'Sin actividad reciente', 'atora-lms' ) ); ?></strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Rúbrica asociada', 'atora-lms' ); ?></span>
				<strong><?php echo esc_html( ! empty( $context['rubric_title'] ) ? (string) $context['rubric_title'] : __( 'Sin rúbrica', 'atora-lms' ) ); ?></strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Validación IA', 'atora-lms' ); ?></span>
				<strong><?php echo ! empty( $context['ai_pending_validation'] ) ? esc_html__( 'Pendiente de validación docente', 'atora-lms' ) : esc_html__( 'Sin pendiente', 'atora-lms' ); ?></strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Riesgo académico', 'atora-lms' ); ?></span>
				<strong><?php echo esc_html( (string) call_user_func( $format_risk, (string) ( $context['risk_level'] ?? '' ) ) ); ?></strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Estado de certificado', 'atora-lms' ); ?></span>
				<strong><?php echo esc_html( (string) call_user_func( $format_cert, (string) ( $context['certificate_status'] ?? '' ) ) ); ?></strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Evidencia obligatoria', 'atora-lms' ); ?></span>
				<strong><?php echo ! empty( $context['evidence']['is_required_for_certificate'] ) ? esc_html__( 'Sí', 'atora-lms' ) : esc_html__( 'No', 'atora-lms' ); ?></strong>
			</div>
			<div class="clms-sg-meta-item">
				<span><?php esc_html_e( 'Tipo de evidencia', 'atora-lms' ); ?></span>
				<strong><?php echo esc_html( (string) call_user_func( $format_assessment, (string) ( $context['evidence']['evidence_type'] ?? '' ) ) ); ?></strong>
			</div>
		</div>

		<?php if ( ! empty( $context['last_feedback_previous'] ) ) : ?>
			<p class="clms-sg-copy"><strong><?php esc_html_e( 'Feedback anterior:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) $context['last_feedback_previous'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $context['improvement_plan']['next_action'] ) ) : ?>
			<p class="clms-sg-copy"><strong><?php esc_html_e( 'Plan de mejora sugerido:', 'atora-lms' ); ?></strong> <?php echo esc_html( (string) $context['improvement_plan']['next_action'] ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $context['configuration_warnings'] ) && is_array( $context['configuration_warnings'] ) ) : ?>
			<p class="clms-sg-copy"><strong><?php esc_html_e( 'Avisos de configuración académica:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', array_map( 'sanitize_text_field', $context['configuration_warnings'] ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $context['missing_certificate_requirements'] ) && is_array( $context['missing_certificate_requirements'] ) ) : ?>
			<p class="clms-sg-copy"><strong><?php esc_html_e( 'Requisitos de certificado afectados:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', array_map( 'sanitize_text_field', $context['missing_certificate_requirements'] ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $context['competency_focus'] ) && is_array( $context['competency_focus'] ) ) : ?>
			<div class="clms-sg-history-list">
				<?php foreach ( $context['competency_focus'] as $comp_item ) : ?>
					<?php $comp_item = is_array( $comp_item ) ? $comp_item : array(); ?>
					<article class="clms-sg-history-item">
						<div>
							<strong><?php echo esc_html( (string) ( $comp_item['title'] ?? '' ) ); ?></strong>
							<p class="clms-sg-copy">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: estado, 2: puntaje */
										__( 'Estado: %1$s · Puntaje: %2$s%%', 'atora-lms' ),
										call_user_func( $format_assessment, (string) ( $comp_item['status'] ?? '' ) ),
										absint( $comp_item['score'] ?? 0 )
									)
								);
								?>
							</p>
							<?php if ( ! empty( $comp_item['recommendation'] ) ) : ?>
								<p class="clms-sg-copy"><?php echo esc_html( (string) $comp_item['recommendation'] ); ?></p>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ( empty( $context['recent_history'] ) ) : ?>
			<p class="clms-sg-copy"><?php esc_html_e( 'No hay entregas previas del estudiante en este curso.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<div class="clms-sg-history-list">
				<?php foreach ( $context['recent_history'] as $history ) : ?>
					<?php $history = is_array( $history ) ? $history : array(); ?>
					<article class="clms-sg-history-item">
						<div>
							<strong><?php echo esc_html( (string) ( $history['lesson_title'] ?? '' ) ); ?></strong>
							<p class="clms-sg-copy">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: status label, 2: updated datetime */
										__( '%1$s · %2$s', 'atora-lms' ),
										(string) ( $history['status_label'] ?? '' ),
										(string) ( $history['updated_at'] ?? '' )
									)
								);
								?>
								<?php if ( '' !== (string) ( $history['grade'] ?? '' ) ) : ?>
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: grade value */
											__( ' · Nota %s/100', 'atora-lms' ),
											absint( $history['grade'] )
										)
									);
									?>
								<?php endif; ?>
								<?php if ( ! empty( $history['is_same_activity'] ) ) : ?>
									<?php esc_html_e( ' · Misma actividad', 'atora-lms' ); ?>
								<?php endif; ?>
							</p>
						</div>
						<a class="clms-sg-btn clms-sg-btn--secondary" href="<?php echo esc_url( (string) ( $history['speedgrade_url'] ?? '#' ) ); ?>"><?php esc_html_e( 'Abrir', 'atora-lms' ); ?></a>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}
