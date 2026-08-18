<?php
/**
 * ATORA LMS v5 — Proveedor TOTP (Google Authenticator / Authy)
 *
 * Implementa TOTP (RFC 6238) sin dependencias externas.
 * Compatible con Google Authenticator, Authy, Microsoft Authenticator, etc.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

namespace ATORA\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Two_FA_TOTP
 *
 * @since 5.0.0
 */
class Two_FA_TOTP {

	/** Longitud del secreto Base32 en bytes antes de codificar. */
	const SECRET_BYTES = 20;

	/** Ventana de tolerancia: número de intervalos de 30 s que se aceptan. */
	const WINDOW = 1;

	/**
	 * Genera un secreto TOTP nuevo para un usuario.
	 *
	 * @return string Secreto en Base32.
	 */
	public static function generate_secret(): string {
		return self::base32_encode( random_bytes( self::SECRET_BYTES ) );
	}

	/**
	 * Genera la URL otpauth:// para el QR code.
	 *
	 * @param string $secret     Secreto Base32.
	 * @param string $user_email Email del usuario.
	 * @param string $issuer     Nombre de la academia (label en el autenticador).
	 * @return string
	 */
	public static function get_qr_url( string $secret, string $user_email, string $issuer = 'ATORA LMS' ): string {
		$issuer_enc = rawurlencode( $issuer );
		$email_enc  = rawurlencode( $user_email );

		$otpauth = sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
			$issuer_enc,
			$email_enc,
			$secret,
			$issuer_enc
		);

		// Usar Google Charts como generador de QR (externo) o API propia si está disponible.
		return 'https://chart.googleapis.com/chart?cht=qr&chs=200x200&chl=' . rawurlencode( $otpauth );
	}

	/**
	 * Verifica un código TOTP de 6 dígitos.
	 *
	 * @param string $secret Secreto Base32.
	 * @param string $code   Código ingresado.
	 * @return bool
	 */
	public static function verify( string $secret, string $code ): bool {
		$code = preg_replace( '/\D/', '', $code );

		if ( strlen( $code ) !== 6 ) {
			return false;
		}

		$key       = self::base32_decode( $secret );
		$timestamp = (int) floor( time() / 30 );

		for ( $offset = -self::WINDOW; $offset <= self::WINDOW; $offset++ ) {
			if ( self::compute_code( $key, $timestamp + $offset ) === $code ) {
				return true;
			}
		}

		return false;
	}

	// ── Algoritmo TOTP ────────────────────────────────────────────────────────

	/**
	 * Calcula el código OTP para un contador dado.
	 *
	 * @param string $key     Clave binaria.
	 * @param int    $counter Contador de tiempo.
	 * @return string Código de 6 dígitos.
	 */
	private static function compute_code( string $key, int $counter ): string {
		$counter_bin = pack( 'N*', 0 ) . pack( 'N*', $counter );
		$hash        = hash_hmac( 'sha1', $counter_bin, $key, true );
		$offset      = ord( $hash[19] ) & 0x0F;
		$code        = (
			( ord( $hash[ $offset ] ) & 0x7F ) << 24 |
			( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 |
			( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 |
			( ord( $hash[ $offset + 3 ] ) & 0xFF )
		) % 1000000;

		return str_pad( (string) $code, 6, '0', STR_PAD_LEFT );
	}

	// ── Base32 ────────────────────────────────────────────────────────────────

	/**
	 * Codifica bytes en Base32 (RFC 4648).
	 *
	 * @param string $bytes Datos binarios.
	 * @return string
	 */
	private static function base32_encode( string $bytes ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$output   = '';
		$buffer   = 0;
		$bits_left = 0;

		foreach ( str_split( $bytes ) as $char ) {
			$buffer    = ( $buffer << 8 ) | ord( $char );
			$bits_left += 8;
			while ( $bits_left >= 5 ) {
				$bits_left -= 5;
				$output   .= $alphabet[ ( $buffer >> $bits_left ) & 0x1F ];
			}
		}

		if ( $bits_left > 0 ) {
			$output .= $alphabet[ ( $buffer << ( 5 - $bits_left ) ) & 0x1F ];
		}

		return $output;
	}

	/**
	 * Decodifica Base32 a bytes.
	 *
	 * @param string $input Cadena Base32.
	 * @return string
	 */
	private static function base32_decode( string $input ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$input    = strtoupper( $input );
		$output   = '';
		$buffer   = 0;
		$bits_left = 0;

		foreach ( str_split( $input ) as $char ) {
			$pos = strpos( $alphabet, $char );
			if ( false === $pos ) {
				continue;
			}
			$buffer    = ( $buffer << 5 ) | $pos;
			$bits_left += 5;
			if ( $bits_left >= 8 ) {
				$bits_left -= 8;
				$output   .= chr( ( $buffer >> $bits_left ) & 0xFF );
			}
		}

		return $output;
	}
}
