<?php
/**
 * Partial: Testimonios — single-course (overview)
 * Variables: $testimonial_items (array of ['name','role','text','rating'])
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-testimonials">
	<h2 class="cov-section-title"><?php esc_html_e( 'Testimonios', 'atora-lms' ); ?></h2>
	<div class="cov-testimonials-grid">
		<?php foreach ( $testimonial_items as $t ) : ?>
			<blockquote class="cov-testimonial-card">
				<?php if ( ! empty( $t['rating'] ) ) : ?>
					<div class="cov-testimonial-rating" aria-label="<?php echo esc_attr( sprintf( __( '%d de 5 estrellas', 'atora-lms' ), $t['rating'] ) ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<span class="cov-star<?php echo $i <= $t['rating'] ? ' is-filled' : ''; ?>" aria-hidden="true">&#9733;</span>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
				<p class="cov-testimonial-quote"><?php echo esc_html( $t['text'] ); ?></p>
				<footer class="cov-testimonial-meta">
					<strong class="cov-testimonial-name"><?php echo esc_html( $t['name'] ); ?></strong>
					<?php if ( $t['role'] ) : ?>
						<span class="cov-testimonial-role"><?php echo esc_html( $t['role'] ); ?></span>
					<?php endif; ?>
				</footer>
			</blockquote>
		<?php endforeach; ?>
	</div>
</div>
