<?php
/**
 * Partial: Hero — single-course (overview)
 * Variables: $course_id, $user_id, $is_enrolled, $is_admin,
 *            $progress, $done, $total, $cta_lesson_id, $cta_label_key,
 *            $course_excerpt, $instructor_names, $course_thumbnail_id
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-hero<?php echo ( ! $user_id || ! $is_enrolled ) ? ' cov-hero--funnel' : ''; ?>">
	<div class="cov-hero-content">

		<p class="cov-kicker"><?php esc_html_e( 'Curso', 'atora-lms' ); ?></p>
		<h1 class="cov-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h1>

		<?php if ( $course_excerpt ) : ?>
			<p class="cov-desc"><?php echo esc_html( $course_excerpt ); ?></p>
		<?php endif; ?>

		<div class="cov-meta-row">
			<span class="cov-meta-item">
				<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 3h12v10H2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M5 7h6M5 10h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
				<?php echo esc_html( $total ); ?> <?php esc_html_e( 'lecciones', 'atora-lms' ); ?>
			</span>
			<?php if ( ! empty( $instructor_names ) ) : ?>
				<span class="cov-meta-item">
					<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M2 13c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
					<?php echo esc_html( implode( ', ', $instructor_names ) ); ?>
				</span>
			<?php endif; ?>
		</div>

		<?php if ( $user_id && $is_enrolled ) : ?>
			<div class="cov-progress-row">
				<div class="cov-progress-bar-wrap">
					<div class="cov-progress-bar" style="width:<?php echo esc_attr( $progress ); ?>%"></div>
				</div>
				<span class="cov-progress-label">
					<?php echo esc_html( $progress ); ?>% &mdash;
					<?php echo esc_html( $done ); ?>/<?php echo esc_html( $total ); ?>
					<?php esc_html_e( 'completadas', 'atora-lms' ); ?>
				</span>
			</div>
		<?php endif; ?>

		<?php if ( ! $user_id ) : ?>
			<a class="cov-btn cov-btn-primary" href="<?php echo esc_url( wp_login_url( get_permalink( $course_id ) ) ); ?>">
				<?php esc_html_e( 'Iniciar sesión para acceder', 'atora-lms' ); ?>
			</a>
		<?php elseif ( ! $is_enrolled ) : ?>
			<p class="cov-gate-msg"><?php esc_html_e( 'Debes inscribirte en este curso para acceder a las lecciones.', 'atora-lms' ); ?></p>
		<?php elseif ( $cta_lesson_id ) : ?>
			<a class="cov-btn cov-btn-primary" href="<?php echo esc_url( get_permalink( $cta_lesson_id ) ); ?>">
				<?php echo esc_html( __( $cta_label_key, 'atora-lms' ) ); // phpcs:ignore WordPress.WP.I18n ?>
			</a>
		<?php endif; ?>

	</div><!-- .cov-hero-content -->

	<?php if ( $course_thumbnail_id ) : ?>
		<div class="cov-hero-thumb">
			<?php echo wp_get_attachment_image( $course_thumbnail_id, 'large', false, array( 'class' => 'cov-thumb-img' ) ); ?>
		</div>
	<?php endif; ?>
</div><!-- .cov-hero -->
