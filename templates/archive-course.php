<?php
/**
 * Archive/List Courses Template
 * ATORA-LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$user_id   = get_current_user_id();
$completed = array();

if ( $user_id ) {
	$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
	$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();
}

$type_labels = array(
	'lectura'    => __( 'Lección', 'atora-lms' ),
	'tarea'      => __( 'Tarea', 'atora-lms' ),
	'evaluacion' => __( 'Evaluación', 'atora-lms' ),
);
?>

<div class="atora-archive-courses">
	<div class="atora-container">

		<!-- ── Hero ──────────────────────────────────────────────────────────── -->
		<div class="atora-archive-hero">
			<p class="atora-archive-hero__kicker"><?php esc_html_e( 'Academia', 'atora-lms' ); ?></p>
			<h1 class="atora-archive-hero__title">
				<?php post_type_archive_title(); ?>
			</h1>
			<p class="atora-archive-hero__sub">
				<?php esc_html_e( 'Explora todo el contenido disponible y sigue aprendiendo a tu ritmo.', 'atora-lms' ); ?>
			</p>
		</div>

		<!-- ── Grid de cursos ────────────────────────────────────────────────── -->
		<div class="atora-courses-grid">
			<?php if ( have_posts() ) : ?>
				<?php while ( have_posts() ) : the_post(); ?>
					<?php
					$course_id  = get_the_ID();
					$lesson_ids = array();

					if ( class_exists( 'CLMS_Helper' ) ) {
						$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );
						$lesson_ids = is_array( $lesson_ids )
							? array_values( array_map( 'absint', $lesson_ids ) )
							: array();
					}

					$lesson_count = count( $lesson_ids );
					$is_enrolled  = false;
					$progress     = 0;

					if ( $user_id && class_exists( 'CLMS_Helper' ) ) {
						$is_admin    = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
						$is_enrolled = $is_admin || CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id );
					}

					if ( $is_enrolled && $lesson_count > 0 ) {
						$done     = count( array_intersect( $lesson_ids, $completed ) );
						$progress = (int) round( $done / $lesson_count * 100 );
					}

					$is_complete = $is_enrolled && $progress >= 100;

					$thumb_html = '';
					if ( has_post_thumbnail() ) {
						$thumb_html = get_the_post_thumbnail( $course_id, 'large', array( 'loading' => 'lazy' ) );
					}
					?>
					<article class="atora-course-card">

						<!-- Thumbnail -->
						<a href="<?php the_permalink(); ?>" class="atora-course-card__thumb" tabindex="-1" aria-hidden="true">
							<?php if ( $thumb_html ) : ?>
								<?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php else : ?>
								<div class="atora-course-card__thumb-placeholder">📚</div>
							<?php endif; ?>

							<!-- Badges overlay -->
							<div class="atora-course-card__badges">
								<?php if ( $is_complete ) : ?>
									<span class="atora-badge atora-badge-green"><?php esc_html_e( '✓ Completado', 'atora-lms' ); ?></span>
								<?php elseif ( $is_enrolled ) : ?>
									<span class="atora-badge atora-badge-brand"><?php esc_html_e( 'En progreso', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>
						</a>

						<!-- Body -->
						<div class="atora-course-card__body">
							<h2 class="atora-course-card__title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h2>

							<?php $excerpt = get_the_excerpt(); ?>
							<?php if ( $excerpt ) : ?>
								<p class="atora-course-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
							<?php endif; ?>

							<!-- Meta -->
							<div class="atora-course-card__meta">
								<?php if ( $lesson_count > 0 ) : ?>
									<span class="atora-course-card__meta-item">
										<svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
											<path d="M2 3h12v10H2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
											<path d="M5 7h6M5 10h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
										</svg>
										<?php echo esc_html( $lesson_count ); ?>
										<?php esc_html_e( 'lecciones', 'atora-lms' ); ?>
									</span>
								<?php endif; ?>
							</div>

							<!-- Progress -->
							<?php if ( $is_enrolled && $lesson_count > 0 ) : ?>
								<div class="atora-course-card__progress-wrap">
									<div class="atora-course-card__progress-label">
										<span><?php esc_html_e( 'Progreso', 'atora-lms' ); ?></span>
										<strong><?php echo esc_html( $progress ); ?>%</strong>
									</div>
									<div class="atora-progress">
										<div
											class="atora-progress-fill <?php echo $is_complete ? 'is-complete' : ''; ?>"
											style="width:<?php echo esc_attr( $progress ); ?>%"
										></div>
									</div>
								</div>
							<?php endif; ?>
						</div>

						<!-- Footer CTA -->
						<div class="atora-course-card__footer">
							<a href="<?php the_permalink(); ?>" class="atora-btn atora-btn-primary" style="width:100%;">
								<?php if ( $is_complete ) : ?>
									<?php esc_html_e( 'Revisar curso', 'atora-lms' ); ?>
								<?php elseif ( $is_enrolled ) : ?>
									<?php esc_html_e( 'Continuar', 'atora-lms' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Ver curso', 'atora-lms' ); ?>
								<?php endif; ?>
							</a>
						</div>

					</article>
				<?php endwhile; ?>

			<?php else : ?>
				<div class="atora-courses-empty">
					<div class="atora-courses-empty__icon">🎓</div>
					<p><?php esc_html_e( 'No hay cursos disponibles en este momento.', 'atora-lms' ); ?></p>
				</div>
			<?php endif; ?>
		</div>

		<!-- Pagination -->
		<div class="atora-pagination">
			<?php the_posts_pagination(); ?>
		</div>

	</div>
</div>

<?php get_footer(); ?>
