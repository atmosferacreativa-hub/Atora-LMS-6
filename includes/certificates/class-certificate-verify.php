<?php
/**
 * Verificación pública de certificados (6.33.0): `/verificar/{codigo}`.
 *
 * - Válido: a quién se emitió (nombre tal como figura en el certificado), qué
 *   curso o programa, la institución y la fecha.
 * - Revocado: solo "Revocado", sin más datos. Sustituido por otro: solo eso.
 * - Código inventado: "No encontrado" (404).
 * - Nunca muestra correo ni otro dato del estudiante.
 *
 * El código es el UUID de la credencial institucional; también se aceptan los
 * códigos de verificación anteriores (20 caracteres aleatorios).
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Certificate_Verify {

	const QUERY_VAR       = 'atora_verificar';
	const REWRITE_VERSION = '6.33.0';
	const REWRITE_OPTION  = 'atora_verify_rewrite_version';

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', static function ( $vars ) {
			$vars[] = self::QUERY_VAR;
			return $vars;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	public static function rewrite(): void {
		add_rewrite_rule( '^verificar/?$', 'index.php?' . self::QUERY_VAR . '=__form', 'top' );
		add_rewrite_rule( '^verificar/([A-Za-z0-9-]{8,64})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
		if ( get_option( self::REWRITE_OPTION ) !== self::REWRITE_VERSION ) {
			update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, false );
			flush_rewrite_rules( false );
		}
	}

	/** URL pública de verificación de un código. */
	public static function url( string $code ): string {
		$code = rawurlencode( $code );
		return get_option( 'permalink_structure' )
			? home_url( '/verificar/' . $code . '/' )
			: add_query_arg( self::QUERY_VAR, $code, home_url( '/' ) );
	}

	/**
	 * Estado de un código.
	 *
	 * @return array{state:string,holder?:string,achievement?:string,target_type?:string,issuer?:string,issued_at?:string}
	 */
	public static function lookup( string $code ): array {
		$code = trim( $code );
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $code ) ) {
			return array( 'state' => 'not_found' );
		}
		if ( preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $code ) ) {
			return self::from_credential( $code );
		}
		// Código de verificación anterior a las credenciales.
		$certs  = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Certificates' ) : null;
		$found  = $certs && method_exists( $certs, 'verification_lookup' ) ? (array) $certs->verification_lookup( $code ) : array();
		$record = (array) ( $found['record'] ?? array() );
		if ( ! $record ) {
			return array( 'state' => 'not_found' );
		}
		if ( ! empty( $record['credential_uuid'] ) ) {
			return self::from_credential( (string) $record['credential_uuid'] );
		}
		if ( 'revoked' === sanitize_key( (string) ( $record['status'] ?? '' ) ) ) {
			return array( 'state' => 'revoked' );
		}
		return array(
			'state'       => 'valid',
			'holder'      => sanitize_text_field( (string) ( $record['student_name'] ?? '' ) ),
			'achievement' => sanitize_text_field( (string) ( $record['target_title'] ?? '' ) ),
			'target_type' => 'program' === ( $found['target_type'] ?? '' ) ? 'program' : 'course',
			'issuer'      => sanitize_text_field( (string) ( $record['academy'] ?? get_bloginfo( 'name' ) ) ),
			'issued_at'   => (string) ( $record['issued_at'] ?? '' ),
		);
	}

	private static function from_credential( string $uuid ): array {
		if ( ! class_exists( 'CLMS_Credential_Service' ) ) {
			return array( 'state' => 'not_found' );
		}
		$result = ( new CLMS_Credential_Service() )->verify( $uuid );
		if ( is_wp_error( $result ) ) {
			return array( 'state' => 'not_found' );
		}
		$status = (string) $result['status'];
		if ( 'revoked' === $status ) {
			return array( 'state' => 'revoked' );
		}
		if ( 'superseded' === $status ) {
			return array( 'state' => 'superseded' );
		}
		// Válido (también con una revocación solicitada y aún sin decidir).
		global $wpdb;
		$type = (string) $wpdb->get_var( $wpdb->prepare( "SELECT target_type FROM {$wpdb->prefix}atora_credentials WHERE credential_uuid = %s", strtolower( $uuid ) ) ); // phpcs:ignore WordPress.DB
		return array(
			'state'       => 'valid',
			'holder'      => (string) $result['holder_name'],
			'achievement' => (string) $result['achievement_name'],
			'target_type' => 'program' === $type ? 'program' : 'course',
			'issuer'      => (string) $result['issuer_name'],
			'issued_at'   => get_date_from_gmt( (string) $result['issued_at'] ),
		);
	}

	/** Límite por IP (sin guardar la IP): evita recorrer códigos a fuerza bruta. */
	private static function throttled(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'atora_verify_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
		return $hits >= 60;
	}

	public static function maybe_render(): void {
		$code = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $code ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( '__form' === $code ) {
			$code = isset( $_GET['codigo'] ) ? sanitize_text_field( wp_unslash( $_GET['codigo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( '' === $code ) {
				self::page( array( 'state' => 'form' ) );
			}
		}
		if ( self::throttled() ) {
			status_header( 429 );
			self::page( array( 'state' => 'throttled' ) );
		}
		$result = self::lookup( $code );
		if ( 'not_found' === $result['state'] ) {
			status_header( 404 );
		}
		self::page( $result );
	}

	/** Contenido de la verificación (sin la página), para la página y las pruebas. */
	public static function content( array $r ): string {
		$academy = esc_html( get_bloginfo( 'name' ) );
		switch ( $r['state'] ) {
			case 'form':
				return '<h1>' . esc_html__( 'Verificar un certificado', 'atora-lms' ) . '</h1><form method="get" action="' . esc_url( home_url( '/verificar/' ) ) . '"><label>' . esc_html__( 'Código de verificación', 'atora-lms' ) . '<br><input name="codigo" required></label> <button>' . esc_html__( 'Verificar', 'atora-lms' ) . '</button></form>';
			case 'throttled':
				return '<h1>' . esc_html__( 'Demasiadas consultas', 'atora-lms' ) . '</h1><p>' . esc_html__( 'Intenta de nuevo en unos minutos.', 'atora-lms' ) . '</p>';
			case 'not_found':
				return '<div class="state bad">' . esc_html__( 'No encontrado', 'atora-lms' ) . '</div><p>' . esc_html__( 'No existe un certificado con ese código. Revisa que esté completo.', 'atora-lms' ) . '</p>';
			case 'revoked':
				return '<div class="state bad">' . esc_html__( 'Revocado', 'atora-lms' ) . '</div><p>' . esc_html__( 'Este certificado fue revocado por la institución y ya no es válido.', 'atora-lms' ) . '</p>';
			case 'superseded':
				return '<div class="state bad">' . esc_html__( 'Sustituido', 'atora-lms' ) . '</div><p>' . esc_html__( 'Este certificado fue reemplazado por una versión más reciente.', 'atora-lms' ) . '</p>';
		}
		$date = $r['issued_at'] && strtotime( $r['issued_at'] ) ? wp_date( get_option( 'date_format' ), strtotime( $r['issued_at'] ) ) : '';
		return '<div class="state ok">' . esc_html__( 'Válido', 'atora-lms' ) . '</div><dl>'
			. '<dt>' . esc_html__( 'Emitido a', 'atora-lms' ) . '</dt><dd>' . esc_html( $r['holder'] ) . '</dd>'
			. '<dt>' . esc_html( 'program' === $r['target_type'] ? __( 'Programa', 'atora-lms' ) : __( 'Curso', 'atora-lms' ) ) . '</dt><dd>' . esc_html( $r['achievement'] ) . '</dd>'
			. '<dt>' . esc_html__( 'Institución', 'atora-lms' ) . '</dt><dd>' . esc_html( $r['issuer'] ?: $academy ) . '</dd>'
			. '<dt>' . esc_html__( 'Fecha de emisión', 'atora-lms' ) . '</dt><dd>' . esc_html( $date ) . '</dd></dl>';
	}

	private static function page( array $r ): void {
		require_once dirname( __DIR__ ) . '/frontend/class-academy-public-page.php';
		CLMS_Academy_Public_Page::render( __( 'Verificación de certificado', 'atora-lms' ), self::content( $r ) );
		exit;
	}
}
