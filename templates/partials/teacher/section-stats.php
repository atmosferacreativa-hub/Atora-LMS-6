<?php
/**
 * Partial: teacher stats
 * Variables disponibles: $data, $section, $schema, $ctx
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $data @var array $section @var array $schema @var object $ctx */
	?>
	<section class="tp-stats">
		<div class="tp-stats__inner">
			<dl class="tp-stats__grid">
				<?php if ( ! empty( $data['published_courses_n'] ) && (int) $data['published_courses_n'] > 0 ) : ?>
					<div class="tp-stat">
						<dt class="tp-stat__value"><?php echo esc_html( number_format_i18n( (int) $data['published_courses_n'] ) ); ?></dt>
						<dd class="tp-stat__label"><?php echo esc_html( _n( 'Curso', 'Cursos', (int) $data['published_courses_n'], 'atora-lms' ) ); ?></dd>
					</div>
				<?php endif; ?>
				<?php
				// Estudiantes: suma de inscritos en todos sus cursos (si CLMS_Enrollment existe)
				$students = 0;
				if ( class_exists( 'CLMS_Enrollment' ) && ! empty( $data['published_courses'] ) && is_iterable( $data['published_courses'] ) ) {
					foreach ( $data['published_courses'] as $course ) {
						if ( ! is_object( $course ) || empty( $course->ID ) ) {
							continue;
						}
						$students += (int) get_post_meta( absint( $course->ID ), '_clms_enrollment_count', true );
					}
				}
				?>
			<?php if ( $students > 0 ) : ?>
				<div class="tp-stat">
					<dt class="tp-stat__value"><?php echo esc_html( number_format_i18n( $students ) ); ?></dt>
					<dd class="tp-stat__label"><?php esc_html_e( 'Estudiantes', 'atora-lms' ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
	</div>
</section>
