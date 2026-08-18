<?php
/**
 * Partial: Testimonials section — single-course-commercial
 * Variables: $testimonial_items (array of ['name','role','text','rating'])
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Lo que dicen nuestros alumnos', 'atora-lms' ); ?></h2>
	<div class="cc-testimonials">
		<?php foreach ( $testimonial_items as $t ) : ?>
			<div class="cc-testimonial">
				<?php if ( ! empty( $t['rating'] ) ) : ?>
					<div class="cc-testimonial-rating" aria-label="<?php echo esc_attr( sprintf( __( '%d de 5 estrellas', 'atora-lms' ), $t['rating'] ) ); ?>">
						<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
							<span class="cc-star<?php echo $i <= $t['rating'] ? ' is-filled' : ''; ?>" aria-hidden="true">&#9733;</span>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
				<p class="cc-testimonial-quote">"<?php echo esc_html( $t['text'] ); ?>"</p>
				<div class="cc-testimonial-author">
					<strong><?php echo esc_html( $t['name'] ); ?></strong>
					<?php if ( $t['role'] ) : ?>
						<span><?php echo esc_html( $t['role'] ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
