<?php
/**
 * Presentación QR local, sin enviar datos del alumno a terceros.
 *
 * @package ATORA_LMS
 * @since 6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CLMS_Credential_QR {
	public function __construct() {
		add_shortcode( 'atora_credential_qr', array( $this, 'shortcode' ) );
	}

	public static function verification_url( $uuid ): string {
		$uuid = strtolower( sanitize_text_field( (string) $uuid ) );
		return rest_url( 'clms/v1/credentials/verify/' . rawurlencode( $uuid ) );
	}

	public function shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => '', 'size' => 164 ), $atts, 'atora_credential_qr' );
		$uuid = strtolower( sanitize_text_field( (string) $atts['id'] ) );
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid ) ) {
			return '';
		}
		wp_enqueue_script( 'atora-qrcodegen', CLMS_PLUGIN_URL . 'assets/vendor/qrcodegen.js', array(), CLMS_VERSION, true );
		wp_enqueue_script( 'atora-credential-qr', CLMS_PLUGIN_URL . 'assets/js/credential-qr.js', array( 'atora-qrcodegen' ), CLMS_VERSION, true );
		return sprintf( '<div class="atora-credential-qr" data-atora-qr="%1$s" data-atora-qr-size="%2$d"><a href="%1$s">%3$s</a></div>', esc_url( self::verification_url( $uuid ) ), min( 512, max( 96, absint( $atts['size'] ) ) ), esc_html__( 'Verificar credencial', 'atora-lms' ) );
	}
}
