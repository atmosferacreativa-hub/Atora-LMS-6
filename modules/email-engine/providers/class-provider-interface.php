<?php
/**
 * ATORA LMS v5 — Interfaz de providers de email
 *
 * @package ATORA_LMS\EmailEngine\Providers
 * @since   5.0.0
 */

namespace ATORA\EmailEngine\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Provider_Interface
 *
 * @since 5.0.0
 */
interface Provider_Interface {

	/**
	 * Envía un email.
	 *
	 * @param string $to         Email del destinatario.
	 * @param string $subject    Asunto.
	 * @param string $body_html  Cuerpo HTML.
	 * @param string $body_text  Cuerpo plain text.
	 * @param array  $options    Opciones adicionales (metadata, headers…).
	 * @return bool
	 */
	public static function send( string $to, string $subject, string $body_html, string $body_text, array $options = array() ): bool;

	/**
	 * Verifica la conexión con el provider.
	 *
	 * @return bool
	 */
	public static function test_connection(): bool;

	/**
	 * Devuelve el nombre legible del provider.
	 *
	 * @return string
	 */
	public static function get_name(): string;
}
