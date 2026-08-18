<?php
/**
 * Partial: FAQ section — single-course-commercial
 * Variables: $faq_items (array of ['q','a'])
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Preguntas frecuentes', 'atora-lms' ); ?></h2>
	<div class="cc-faq">
		<?php foreach ( $faq_items as $item ) : ?>
			<div class="cc-faq-item">
				<p class="cc-faq-q"><?php echo esc_html( $item['q'] ); ?></p>
				<p class="cc-faq-a"><?php echo esc_html( $item['a'] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
</div>
