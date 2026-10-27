<?php
/**
 * Template email: boletín (6.38.0). El cuerpo lo arma el módulo de boletín a partir de sus secciones.
 *
 * Variables: int $newsletter_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$newsletter_id = isset( $newsletter_id ) ? absint( $newsletter_id ) : 0;
if ( $newsletter_id && class_exists( '\\ATORA\\Newsletter\\Newsletter' ) ) {
	echo \ATORA\Newsletter\Newsletter::render_body( $newsletter_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_body escapa cada parte.
}
