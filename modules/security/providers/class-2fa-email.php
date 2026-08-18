<?php
/**
 * ATORA LMS v5 — Proveedor 2FA por Email
 *
 * Envía el código de verificación al email del usuario usando la pasarela
 * central de correo de ATORA (ATORA_Email_Gateway).
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

namespace ATORA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Two_FA_Email
 *
 * @since 5.0.0
 */
class Two_FA_Email {

	/**
	 * Envía el código 2FA por email al usuario.
	 *
	 * @param \WP_User $user Usuario.
	 * @param string   $code Código de 6 dígitos.
	 * @return bool
	 */
	public static function send( \WP_User $user, string $code ): bool {
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$site_name  = get_bloginfo( 'name' );
		$expiry_min = Two_FA_Manager::TOKEN_EXPIRY_MINUTES;

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Tu código de verificación — %s', 'atora-lms' ),
			$site_name
		);

		$body  = '<p>' . sprintf(
			/* translators: %s: display name */
			esc_html__( 'Hola %s,', 'atora-lms' ),
			esc_html( $user->display_name )
		) . '</p>';

		$body .= '<p>' . esc_html__( 'Ingresa el siguiente código para completar tu inicio de sesión:', 'atora-lms' ) . '</p>';

		$body .= sprintf(
			'<div style="font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;
			             padding:16px 24px;background:#f1f5f9;border-radius:8px;margin:16px 0;">%s</div>',
			esc_html( $code )
		);

		$body .= '<p>' . sprintf(
			/* translators: %d: minutes */
			esc_html__( 'Este código expira en %d minutos.', 'atora-lms' ),
			$expiry_min
		) . '</p>';

		$body .= '<p style="color:#9ca3af;font-size:12px;">'
			. esc_html__( 'Si no intentaste iniciar sesión, ignora este mensaje y considera cambiar tu contraseña.', 'atora-lms' )
			. '</p>';

		$args = array(
			'headline'    => __( 'Verificación de dos factores', 'atora-lms' ),
			'preheader'   => sprintf( __( 'Tu código: %s', 'atora-lms' ), $code ),
			'button_text' => '',
			'button_url'  => '',
		);

		if ( class_exists( 'ATORA_Email_Gateway' ) && method_exists( 'ATORA_Email_Gateway', 'send' ) ) {
			return (bool) \ATORA_Email_Gateway::send( $user->user_email, $subject, $body, $args );
		}

		// Fallback conservador: CLMS_Email si la pasarela no está disponible.
		if ( class_exists( 'CLMS_Email' ) && method_exists( 'CLMS_Email', 'send' ) ) {
			return (bool) \CLMS_Email::send( $user->user_email, $subject, $body, $args );
		}

		return false;
	}
}
