<?php
/**
 * Microsoft_Teams — canal de notificaciones (Incoming Webhook).
 *
 * @package ATORA_LMS
 * @since   6.20.0
 */

namespace ATORA\Microsoft;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Microsoft_Teams {

	public static function init(): void {
		add_action( 'atora/notification_added', array( __CLASS__, 'on_notification_added' ), 10, 2 );
	}

	/**
	 * @param int   $user_id
	 * @param array $item
	 * @return void
	 */
	public static function on_notification_added( int $user_id, array $item ): void {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) || empty( $settings['webhook_url'] ) ) {
			return;
		}

		$type = sanitize_key( (string) ( $item['type'] ?? '' ) );
		if ( '' === $type ) {
			return;
		}

		$send_types = isset( $settings['send_types'] ) && is_array( $settings['send_types'] ) ? $settings['send_types'] : array();
		if ( ! in_array( $type, $send_types, true ) ) {
			return;
		}

		$notification_id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
		if ( '' !== $notification_id ) {
			$lock = 'atora_teams_sent_' . md5( $notification_id );
			if ( get_transient( $lock ) ) {
				return;
			}
			set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
		}

		$title   = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $item['message'] ?? '' ) );
		$link    = esc_url_raw( (string) ( $item['link'] ?? '' ) );

		$course_id = absint( $item['course_id'] ?? 0 );
		$course_title = $course_id ? (string) get_the_title( $course_id ) : '';

		$text  = $title ? ( '**' . $title . '**' ) : __( '**Notificación**', 'atora-lms' );
		if ( $course_title ) {
			$text .= "\n\n" . sprintf( __( 'Curso: %s', 'atora-lms' ), $course_title );
		}
		if ( $message ) {
			$text .= "\n\n" . $message;
		}
		if ( $link ) {
			$text .= "\n\n" . sprintf( __( 'Abrir: %s', 'atora-lms' ), $link );
		}

		/**
		 * Permite modificar o bloquear envíos a Teams por tipo/rol.
		 *
		 * @param bool  $allowed
		 * @param int   $user_id
		 * @param array $item
		 */
		$allowed = (bool) apply_filters( 'atora/microsoft/teams/should_send', true, $user_id, $item );
		if ( ! $allowed ) {
			return;
		}

		$payload = array(
			'text' => $text,
		);

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_post_wp_remote_post
		$resp = wp_remote_post(
			(string) $settings['webhook_url'],
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			self::maybe_log( 'teams_send_error', array( 'error' => $resp->get_error_message(), 'type' => $type ) );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			self::maybe_log( 'teams_send_error', array( 'status' => $code, 'type' => $type ) );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function get_settings(): array {
		if ( defined( 'CLMS_Settings::OPTION_TEAMS' ) ) {
			$opt = get_option( \CLMS_Settings::OPTION_TEAMS, array() );
		} else {
			$opt = get_option( 'atora_teams_options', array() );
		}
		$opt = is_array( $opt ) ? $opt : array();

		return array(
			'enabled'     => ! empty( $opt['enabled'] ) ? 1 : 0,
			'webhook_url' => esc_url_raw( (string) ( $opt['webhook_url'] ?? '' ) ),
			'send_types'  => isset( $opt['send_types'] ) && is_array( $opt['send_types'] )
				? array_values( array_filter( array_map( 'sanitize_key', (array) $opt['send_types'] ) ) )
				: array( 'early_warning' ),
		);
	}

	/**
	 * Log mínimo condicionado por el flag de debug.
	 *
	 * @param string $event
	 * @param array  $data
	 * @return void
	 */
	private static function maybe_log( string $event, array $data = array() ): void {
		$adv = get_option( defined( 'CLMS_Settings::OPTION_ADV' ) ? \CLMS_Settings::OPTION_ADV : 'clms_advanced_settings', array() );
		$adv = is_array( $adv ) ? $adv : array();
		if ( empty( $adv['enable_debug_log'] ) ) {
			return;
		}
		error_log( '[atora][microsoft][' . sanitize_key( $event ) . '] ' . wp_json_encode( $data ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

