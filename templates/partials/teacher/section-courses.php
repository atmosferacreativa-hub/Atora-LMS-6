<?php
/**
 * Partial: teacher courses
 * Variables disponibles: $data, $courses, $section, $schema, $ctx
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $data @var WP_Post[] $courses @var array $section @var array $schema @var object $ctx */
?>
<section class="tp-courses">
	<div class="tp-courses__inner">
		<h2 class="tp-section-title"><?php esc_html_e( 'Cursos del docente', 'atora-lms' ); ?></h2>
		<div class="tp-courses__grid">
			<?php foreach ( $courses as $course ) : ?>
				<?php
				$course_id    = absint( $course->ID );
				$thumb_id     = (int) get_post_thumbnail_id( $course_id );
				$course_title = get_the_title( $course_id );
				$course_url   = get_permalink( $course_id );
				$excerpt      = wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $course_id ) ), 16, '…' );
				?>
				<article class="tp-course-card">
					<a class="tp-course-card__link" href="<?php echo esc_url( $course_url ); ?>" aria-label="<?php echo esc_attr( $course_title ); ?>">
						<div class="tp-course-card__thumb">
							<?php if ( $thumb_id ) : ?>
								<?php echo wp_get_attachment_image( $thumb_id, 'medium', false, array( 'alt' => esc_attr( $course_title ), 'loading' => 'lazy' ) ); ?>
							<?php else : ?>
								<span class="tp-course-card__thumb-fallback" aria-hidden="true"></span>
							<?php endif; ?>
						</div>
						<div class="tp-course-card__body">
							<h3 class="tp-course-card__title"><?php echo esc_html( $course_title ); ?></h3>
							<?php if ( $excerpt ) : ?>
								<p class="tp-course-card__excerpt"><?php echo esc_html( $excerpt ); ?></p>
							<?php endif; ?>
						</div>
					</a>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
