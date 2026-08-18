<?php
/**
 * Partial: Related items section — single-course-commercial
 * Variables: $related_items (array), $related_type_labels (array)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="cc-section">
	<h2 class="cc-section-title"><?php esc_html_e( 'Complementos recomendados', 'atora-lms' ); ?></h2>
	<div class="cc-related-grid">
		<?php foreach ( $related_items as $item ) : ?>
			<?php
			$type_raw   = isset( $item['type'] ) ? (string) $item['type'] : '';
			$type_key   = strtolower( $type_raw );
			$type_label = isset( $related_type_labels[ $type_key ] ) ? $related_type_labels[ $type_key ] : ucfirst( $type_raw );
			?>
			<div class="cc-related-card">
				<p class="cc-related-type"><?php echo esc_html( $type_label ); ?></p>
				<h3 class="cc-related-title"><?php echo esc_html( $item['title'] ); ?></h3>
				<?php if ( ! empty( $item['subtitle'] ) ) : ?>
					<p class="cc-related-copy"><?php echo esc_html( $item['subtitle'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $item['price'] ) ) : ?>
					<p class="cc-related-meta"><?php echo esc_html( $item['price'] ); ?></p>
				<?php endif; ?>
				<a class="cc-related-link" href="<?php echo esc_url( $item['url'] ); ?>">
					<?php esc_html_e( 'Ver detalle', 'atora-lms' ); ?>
				</a>
			</div>
		<?php endforeach; ?>
	</div>
</div>
