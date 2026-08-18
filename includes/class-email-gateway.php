<?php
/**
 * Gateway central de email de ATORA.
 * Enruta emails a través de Email Engine (con queue y templates) cuando está disponible,
 * con fallback a CLMS_Email y luego a wp_mail.
 *
 * Flujo:
 *   Módulo de negocio
 *       ↓
 *   ATORA_Email_Gateway::send() o ::enqueue()
 *       ↓
 *   ATORA\EmailEngine\Email_Queue  (si disponible)
 *       ↓
 *   CLMS_Email::send()  (si no hay Email Engine)
 *       ↓
 *   wp_mail()  (fallback final — solo en providers o wrappers)
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ATORA_Email_Gateway {

	/**
	 * Envía un email inmediato pasando por la capa centralizada.
	 * Usar para emails críticos que no deben encolarse (2FA, recuperación de contraseña).
	 *
	 * @param string $to      Destinatario.
	 * @param string $subject Asunto.
	 * @param string $body    Cuerpo HTML.
	 * @param array  $args    Args adicionales para CLMS_Email::send().
	 * @return bool
	 */
	public static function send( string $to, string $subject, string $body, array $args = array() ): bool {
		$to      = sanitize_email( $to );
		$subject = sanitize_text_field( $subject );

		if ( ! $to || ! is_email( $to ) ) {
			return false;
		}

		if ( class_exists( 'CLMS_Email' ) && method_exists( 'CLMS_Email', 'send' ) ) {
			return (bool) \CLMS_Email::send( $to, $subject, $body, $args );
		}

		// Fallback: wp_mail con cabeceras HTML.
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $args['reply_to'] ) && is_email( $args['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . sanitize_email( $args['reply_to'] );
		}

		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Encola un email con template para envío asíncrono.
	 * Usar para emails no urgentes (bienvenida, calificación, newsletter).
	 *
	 * @param string $template Nombre del template.
	 * @param int    $user_id  ID del destinatario.
	 * @param array  $metadata Metadatos del email (course_id, lesson_id, grade, etc.).
	 * @param string $priority Prioridad: 'high', 'medium', 'low' (default: 'medium').
	 * @return bool True si encoló o si se envió directamente como fallback.
	 */
	public static function enqueue( string $template, int $user_id, array $metadata = array(), string $priority = 'medium' ): bool {
		if ( class_exists( 'ATORA\EmailEngine\Email_Queue' ) ) {
			$queued = \ATORA\EmailEngine\Email_Queue::enqueue( array(
				'template' => sanitize_key( $template ),
				'user_id'  => $user_id,
				'priority' => sanitize_key( $priority ),
				'metadata' => $metadata,
			) );
			return false !== $queued;
		}

		// Fallback: intentar envío directo con CLMS_Email si el template es simple.
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = $site_name . ' — ' . sanitize_text_field( $template );
		$body      = '<p>' . sprintf(
			/* translators: %s: template name */
			esc_html__( 'Tienes una notificación de %s.', 'atora-lms' ),
			esc_html( $site_name )
		) . '</p>';

		return self::send( $user->user_email, $subject, $body );
	}

	/**
	 * Verifica si el Email Engine con cola está disponible.
	 *
	 * @return bool
	 */
	public static function has_email_engine(): bool {
		return class_exists( 'ATORA\EmailEngine\Email_Queue' );
	}

	/**
	 * Verifica si CLMS_Email está disponible.
	 *
	 * @return bool
	 */
	public static function has_clms_email(): bool {
		return class_exists( 'CLMS_Email' ) && method_exists( 'CLMS_Email', 'send' );
	}
}
