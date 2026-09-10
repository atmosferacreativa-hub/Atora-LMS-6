<?php
/**
 * Epic 7 — Microsoft (Entra SSO + Teams/Outlook).
 *
 * @package ATORA_LMS
 * @since   6.20.0
 */

namespace ATORA\Microsoft;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Microsoft_Module {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_options(): array {
		$opt = get_option( defined( 'CLMS_Settings::OPTION_MICROSOFT' ) ? \CLMS_Settings::OPTION_MICROSOFT : 'atora_microsoft_options', array() );
		$opt = is_array( $opt ) ? $opt : array();

		return array(
			'enabled'        => ! empty( $opt['enabled'] ) ? 1 : 0,
			'tenant'         => sanitize_text_field( (string) ( $opt['tenant'] ?? 'common' ) ),
			'client_id'      => sanitize_text_field( (string) ( $opt['client_id'] ?? '' ) ),
			'client_secret'  => sanitize_text_field( (string) ( $opt['client_secret'] ?? '' ) ),
			'allowed_domain' => sanitize_text_field( (string) ( $opt['allowed_domain'] ?? '' ) ),
		);
	}

	public static function is_enabled(): bool {
		$opt = self::get_options();
		return ! empty( $opt['enabled'] ) && ! empty( $opt['client_id'] ) && ! empty( $opt['client_secret'] );
	}

	public static function get_redirect_uri(): string {
		return (string) rest_url( 'atora/v1/microsoft/oauth/callback' );
	}

	public static function register_rest_routes(): void {
		// OAuth start/callback: flujos de redirect, por eso se responden con 302.
		register_rest_route( 'atora/v1', '/microsoft/oauth/start', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( '\ATORA\Microsoft\Microsoft_Identity', 'rest_oauth_start' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'atora/v1', '/microsoft/oauth/callback', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( '\ATORA\Microsoft\Microsoft_Identity', 'rest_oauth_callback' ),
			'permission_callback' => '__return_true',
		) );

		// Link/unlink: solo con sesión iniciada (regla de seguridad).
		register_rest_route( 'atora/v1', '/microsoft/link', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( '\ATORA\Microsoft\Microsoft_Identity', 'rest_link_account' ),
			'permission_callback' => static function(): bool {
				return is_user_logged_in();
			},
		) );

		register_rest_route( 'atora/v1', '/microsoft/unlink', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( '\ATORA\Microsoft\Microsoft_Identity', 'rest_unlink_account' ),
			'permission_callback' => static function(): bool {
				return is_user_logged_in();
			},
		) );

		// Outlook: feed iCal (token o sesión con permisos).
		register_rest_route( 'atora/v1', '/microsoft/outlook/ical-url', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( '\ATORA\Microsoft\Microsoft_Outlook', 'rest_get_ical_url' ),
			'permission_callback' => static function( \WP_REST_Request $r ): bool {
				$course_id = absint( $r->get_param( 'course_id' ) );
				if ( ! $course_id ) {
					return false;
				}
				if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_can_manage_lms' ) ) {
					return (bool) \CLMS_Helper::user_can_manage_lms( $course_id );
				}
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'course_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
			),
		) );

		register_rest_route( 'atora/v1', '/microsoft/outlook/ical', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( '\ATORA\Microsoft\Microsoft_Outlook', 'rest_ical_feed' ),
			'permission_callback' => array( '\ATORA\Microsoft\Microsoft_Outlook', 'rest_can_access_ical' ),
			'args'                => array(
				'course_id' => array( 'sanitize_callback' => 'absint', 'required' => true ),
				'token'     => array( 'sanitize_callback' => 'sanitize_text_field', 'required' => true ),
			),
		) );
	}
}

