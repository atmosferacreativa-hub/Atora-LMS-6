<?php
/**
 * Vista: Player H5P dentro de una lección.
 *
 * Variables esperadas:
 * - int $lesson_id
 * - int $content_id
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$lesson_id  = isset( $lesson_id ) ? absint( $lesson_id ) : 0;
$content_id = isset( $content_id ) ? absint( $content_id ) : 0;

if ( ! $lesson_id || ! $content_id ) {
	return;
}

$h5p_available = shortcode_exists( 'h5p' ) || post_type_exists( 'h5p_content' );

if ( ! $h5p_available ) :
	?>
	<div class="clms-message clms-message-error">
		<?php esc_html_e( 'H5P no está instalado/activo. Pide a un admin instalar/activar el plugin H5P.', 'atora-lms' ); ?>
	</div>
	<?php
	return;
endif;

echo do_shortcode( '[h5p id="' . $content_id . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

