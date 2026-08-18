<?php
/**
 * Partial: Curriculum (lista completa con estados de acceso) — single-course (overview)
 * Variables: $course_id, $lesson_ids, $completed, $user_id, $is_enrolled, $is_admin, $type_labels
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-curriculum">
	<h2 class="cov-section-title"><?php esc_html_e( 'Contenido del curso', 'atora-lms' ); ?></h2>

	<?php if ( empty( $lesson_ids ) ) : ?>
		<p class="cov-empty"><?php esc_html_e( 'Este curso aún no tiene lecciones publicadas.', 'atora-lms' ); ?></p>
	<?php else : ?>

		<ol class="cov-lesson-list" aria-label="<?php esc_attr_e( 'Lecciones del curso', 'atora-lms' ); ?>">
			<?php foreach ( $lesson_ids as $index => $lesson_id ) :
				$lesson = get_post( $lesson_id );
				if ( ! $lesson || 'publish' !== $lesson->post_status ) {
					continue;
				}

				$l_title    = get_the_title( $lesson_id );
				$l_subtitle = get_post_meta( $lesson_id, '_clms_lesson_subtitle', true );
				$l_type     = get_post_meta( $lesson_id, 'lm_activity_type', true ) ?: 'lectura';
				$l_duration = get_post_meta( $lesson_id, '_clms_lesson_duration', true );
				$l_done     = in_array( $lesson_id, $completed, true );

				$l_can_access = $is_admin || (
					$user_id && $is_enrolled && class_exists( 'CLMS_Helper' )
						? CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id )
						: false
				);

				$type_label = isset( $type_labels[ $l_type ] ) ? $type_labels[ $l_type ] : __( 'Lección', 'atora-lms' );

				$icon = 'lectura' === $l_type
					? '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 2h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z" stroke="currentColor" stroke-width="1.5"/><path d="M5 6h6M5 9h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
					: ( 'tarea' === $l_type
						? '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M11 2H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1z" stroke="currentColor" stroke-width="1.5"/><path d="M6 7l1.5 1.5L10 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
						: '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
					);

				$item_class = 'cov-lesson-item';
				if ( $l_done ) {
					$item_class .= ' is-done';
				} elseif ( ! $l_can_access ) {
					$item_class .= ' is-locked';
				}
			?>
			<li class="<?php echo esc_attr( $item_class ); ?>">
				<?php if ( $l_can_access ) : ?>
				<a class="cov-lesson-link" href="<?php echo esc_url( get_permalink( $lesson_id ) ); ?>" aria-label="<?php echo esc_attr( $l_title ); ?>">
				<?php endif; ?>

					<span class="cov-lesson-num" aria-hidden="true">
						<?php if ( $l_done ) : ?>
							<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-label="<?php esc_attr_e( 'Completada', 'atora-lms' ); ?>"><path d="M3 8l3.5 3.5L13 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
						<?php else : ?>
							<?php echo esc_html( $index + 1 ); ?>
						<?php endif; ?>
					</span>

					<span class="cov-lesson-info">
						<span class="cov-lesson-title"><?php echo esc_html( $l_title ); ?></span>
						<?php if ( $l_subtitle ) : ?>
							<span class="cov-lesson-subtitle"><?php echo esc_html( $l_subtitle ); ?></span>
						<?php endif; ?>
						<span class="cov-lesson-badges">
							<span class="cov-badge cov-badge-type"><?php echo $icon; // phpcs:ignore ?> <?php echo esc_html( $type_label ); ?></span>
							<?php if ( $l_duration ) : ?>
								<span class="cov-badge cov-badge-duration">
									<svg width="12" height="12" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.5"/><path d="M8 5v3l2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
									<?php echo esc_html( $l_duration ); ?>
								</span>
							<?php endif; ?>
						</span>
					</span>

					<?php if ( $l_can_access && ! $l_done ) : ?>
						<span class="cov-lesson-arrow" aria-hidden="true">
							<svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M6 3l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
						</span>
					<?php elseif ( ! $l_can_access ) : ?>
						<span class="cov-lesson-lock" aria-label="<?php esc_attr_e( 'Bloqueada', 'atora-lms' ); ?>">
							<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><rect x="3" y="7" width="10" height="8" rx="1.5" stroke="currentColor" stroke-width="1.5"/><path d="M5 7V5a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
						</span>
					<?php endif; ?>

				<?php if ( $l_can_access ) : ?>
				</a>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ol>

	<?php endif; ?>
</div><!-- .cov-curriculum -->
