<?php
/**
 * URL_Store_Service — Acortamiento y tracking granular de URLs en emails (Fase 10)
 *
 * Flujo:
 *   1. Al construir el email, cada CTA pasa por URL_Store_Service::store()
 *      que devuelve la URL de tracking: /wp-json/atora-crm/v2/email/click/{short_key}
 *   2. Al hacer clic, el endpoint registra el clic en atora_url_clicks
 *      y redirige al original_url.
 *
 * @package ATORA_LMS\CRM_V2\Services
 * @since   5.29.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class URL_Store_Service {

	/**
	 * Almacena una URL y devuelve la URL de tracking.
	 *
	 * @param string $original_url URL de destino.
	 * @param int    $campaign_id  Campaña relacionada (0 si es secuencia).
	 * @param int    $sequence_id  Secuencia relacionada (0 si es campaña).
	 * @return string URL de tracking lista para insertar en el email.
	 */
	public static function store( string $original_url, int $campaign_id = 0, int $sequence_id = 0 ): string {
		global $wpdb;

		$original_url = esc_url_raw( $original_url );
		if ( '' === $original_url ) {
			return $original_url;
		}

		$table = $wpdb->prefix . 'atora_url_store';
		if ( ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $table ) ) {
			return $original_url; // fallback sin tracking si la tabla no existe
		}

		// Reusar short_key si ya existe esa URL + campaign
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT short_key FROM {$table}
				 WHERE original_url = %s AND campaign_id = %d AND sequence_id = %d
				 LIMIT 1",
				$original_url,
				$campaign_id,
				$sequence_id
			)
		);

		if ( $existing ) {
			return self::build_tracking_url( (string) $existing );
		}

		// Generar short_key único
		$short_key = self::unique_short_key();

		$wpdb->insert(
			$table,
			array(
				'short_key'    => $short_key,
				'original_url' => $original_url,
				'campaign_id'  => $campaign_id,
				'sequence_id'  => $sequence_id,
			),
			array( '%s', '%s', '%d', '%d' )
		);

		return self::build_tracking_url( $short_key );
	}

	/**
	 * Resuelve una short_key y registra el clic.
	 *
	 * @param string $short_key    Clave corta del enlace.
	 * @param int    $contact_id   Contacto que hizo clic.
	 * @param int    $recipient_id ID en campaign_recipients (0 si es secuencia).
	 * @return string|null URL de destino o null si no existe.
	 */
	public static function resolve_and_track( string $short_key, int $contact_id = 0, int $recipient_id = 0 ): ?string {
		global $wpdb;

		$short_key = sanitize_key( $short_key );
		$table     = $wpdb->prefix . 'atora_url_store';
		$clicks    = $wpdb->prefix . 'atora_url_clicks';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, original_url FROM {$table} WHERE short_key = %s LIMIT 1",
				$short_key
			),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return null;
		}

		// Registrar el clic si la tabla existe
		if ( \ATORA\CRM_V2\Services\DB_Service::table_exists( $clicks ) ) {
			$wpdb->insert(
				$clicks,
				array(
					'url_id'       => absint( $row['id'] ),
					'recipient_id' => $recipient_id,
					'contact_id'   => $contact_id,
					'user_id'      => get_current_user_id(),
					'clicked_at'   => current_time( 'mysql', true ),
					// PT-8 (6.5.8): REMOTE_ADDR directo registraba la IP del
					// proxy (no la del visitante real) en instalaciones
					// detrás de uno — dato analítico, no una decisión de
					// seguridad, pero se centraliza igual por consistencia.
					'ip_address'   => class_exists( 'ATORA_Client_IP' ) ? \ATORA_Client_IP::get() : sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
					'user_agent'   => sanitize_text_field( (string) substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
		}

		do_action( 'atora/url/clicked', $short_key, $row['id'], $contact_id );

		return esc_url_raw( (string) $row['original_url'] );
	}

	/**
	 * Obtiene las URLs más clicadas de una campaña.
	 *
	 * @param int $campaign_id
	 * @param int $limit
	 * @return array
	 */
	public static function get_top_urls( int $campaign_id, int $limit = 10 ): array {
		global $wpdb;

		$store  = $wpdb->prefix . 'atora_url_store';
		$clicks = $wpdb->prefix . 'atora_url_clicks';

		if ( ! \ATORA\CRM_V2\Services\DB_Service::table_exists( $clicks ) ) {
			return array();
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.original_url, s.short_key, COUNT(c.id) AS click_count
				 FROM {$store} s
				 LEFT JOIN {$clicks} c ON c.url_id = s.id
				 WHERE s.campaign_id = %d
				 GROUP BY s.id
				 ORDER BY click_count DESC
				 LIMIT %d",
				$campaign_id,
				$limit
			),
			ARRAY_A
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	private static function build_tracking_url( string $short_key ): string {
		return rest_url( 'atora-crm/v2/url/click/' . $short_key );
	}

	private static function unique_short_key( int $length = 8 ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_url_store';

		do {
			$key    = substr( str_replace( array( '+', '/', '=' ), array( 'a', 'b', 'c' ), base64_encode( random_bytes( 8 ) ) ), 0, $length );
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE short_key = %s", $key )
			);
		} while ( $exists > 0 );

		return $key;
	}
}
