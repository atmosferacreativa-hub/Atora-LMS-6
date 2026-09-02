<?php
/**
 * ATORA_Token_Crypto — P10.1 (sprint 6.13.0), bloqueante
 *
 * Cifra en reposo los tokens OAuth (Calendar/Meet/Drive/Identidad) que
 * hoy `Calendar_Sync::save_tokens()` escribe en texto plano en
 * atora_calendar_sync. Este release multiplica la superficie de tokens
 * (Calendar + Meet + Drive + Identidad), así que se cifra antes de añadir
 * nada nuevo.
 *
 * AES-256-GCM con clave derivada de la constante `ATORA_TOKEN_KEY` en
 * wp-config.php (SHA-256 de la constante → 32 bytes). Sin
 * ATORA_TOKEN_KEY definida, cae a AUTH_KEY (ya presente en todo
 * wp-config.php estándar) y se avisa en el panel de seguridad — no se
 * bloquea el guardado, porque WordPress siempre tiene AUTH_KEY.
 *
 * Migración transparente: un valor ya guardado en texto plano (de antes
 * de 6.13.0) no lleva el prefijo de marca `self::PREFIX` — decrypt() lo
 * detecta y lo devuelve tal cual en vez de fallar. La próxima vez que
 * ese token se reescriba (refresh normal de OAuth), queda cifrado. No
 * hace falta ninguna migración en batch.
 *
 * C6 (6.13.1): huella de clave. Formato desde 6.13.1:
 * `aegcm1:<fp8>:<base64>`, donde fp8 son los 4 primeros bytes hex del
 * SHA-256 de la clave usada para cifrar. Si un administrador define
 * ATORA_TOKEN_KEY después de que ya existan tokens cifrados bajo
 * AUTH_KEY (o rota la clave), decrypt() detecta el desajuste y lo
 * distingue de "token vacío/corrupto" — antes ambos casos devolvían ''
 * indistinguiblemente y todas las conexiones de Google fallaban sin
 * explicación. El formato de 6.13.0 (sin huella, `aegcm1:<base64>`)
 * se sigue leyendo tal cual — no se fuerza a reescribir.
 *
 * @package ATORA_LMS
 * @since   6.13.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_Token_Crypto {

	/** Prefijo de marca: distingue un valor cifrado por esta clase de texto plano legado. */
	const PREFIX = 'aegcm1:';

	/**
	 * @return bool true si ATORA_TOKEN_KEY está definida (recomendado).
	 * Usado por el panel de seguridad para avisar del fallback a AUTH_KEY.
	 */
	public static function has_dedicated_key(): bool {
		return defined( 'ATORA_TOKEN_KEY' ) && '' !== (string) ATORA_TOKEN_KEY;
	}

	/**
	 * @return string Clave de 32 bytes derivada de ATORA_TOKEN_KEY o, en su
	 * defecto, de AUTH_KEY.
	 */
	private static function derive_key(): string {
		$source = self::has_dedicated_key()
			? (string) ATORA_TOKEN_KEY
			: ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : 'atora-lms-fallback-key-insecure' );

		return hash( 'sha256', $source, true );
	}

	/**
	 * @param string $key Clave derivada (32 bytes binarios).
	 * @return string Huella: 4 primeros bytes hex del SHA-256 de la clave.
	 */
	private static function key_fingerprint( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 8 );
	}

	/**
	 * @param string $plaintext Token en claro. '' se devuelve tal cual (nada que cifrar).
	 * @return string Valor a guardar en BD: PREFIX + fp8 + ':' + base64(iv . tag . ciphertext).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::derive_key();
		$iv  = random_bytes( 12 ); // GCM: IV de 96 bits es el estándar recomendado.
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		if ( false === $ciphertext ) {
			// No debería ocurrir con OpenSSL disponible (requisito de WP
			// desde hace años) — fail-safe: no perder el token, guardarlo
			// en claro antes que perderlo, mismo criterio de disponibilidad
			// que el resto del plugin.
			// C6 (6.13.1): pero un cifrado fallido guardando en claro es en
			// sí mismo un hallazgo de auditoría — queda registrado, no en
			// silencio.
			if ( class_exists( 'CLMS_Audit_Log_Service' ) ) {
				CLMS_Audit_Log_Service::log( get_current_user_id(), 'token_encrypt_failed', 'token_crypto', 0, array() );
			}
			return $plaintext;
		}

		return self::PREFIX . self::key_fingerprint( $key ) . ':' . base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @param string $stored Valor tal como está en BD — cifrado (con
	 *                        PREFIX, con o sin huella) o texto plano legado.
	 * @return string Token en claro. '' si $stored está vacío, corrupto,
	 * o cifrado bajo una clave distinta a la actual (ver key_mismatch()
	 * para distinguir este último caso).
	 */
	public static function decrypt( string $stored ): string {
		return self::decrypt_detailed( $stored )['plaintext'];
	}

	/**
	 * @param string $stored
	 * @return bool true si $stored lleva huella de clave (6.13.1+) y esa
	 * huella no coincide con la clave activa — la conexión necesita
	 * reautorización, no está simplemente vacía o corrupta.
	 */
	public static function key_mismatch( string $stored ): bool {
		return self::decrypt_detailed( $stored )['key_mismatch'];
	}

	/**
	 * @param string $stored
	 * @return array{plaintext:string,key_mismatch:bool}
	 */
	private static function decrypt_detailed( string $stored ): array {
		if ( '' === $stored ) {
			return array( 'plaintext' => '', 'key_mismatch' => false );
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			// Migración transparente: valor legado en texto plano (pre-6.13.0).
			return array( 'plaintext' => $stored, 'key_mismatch' => false );
		}

		$rest = substr( $stored, strlen( self::PREFIX ) );
		$key  = self::derive_key();

		// Formato con huella (6.13.1+): "<fp8>:<base64>". Formato de
		// 6.13.0 (sin huella) no matchea este patrón — $rest es base64 puro.
		if ( preg_match( '/^([0-9a-f]{8}):(.+)$/', $rest, $m ) ) {
			if ( $m[1] !== self::key_fingerprint( $key ) ) {
				return array( 'plaintext' => '', 'key_mismatch' => true );
			}
			$b64 = $m[2];
		} else {
			$b64 = $rest;
		}

		$raw = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 28 ) { // 12 (iv) + 16 (tag) mínimo.
			return array( 'plaintext' => '', 'key_mismatch' => false );
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		return array( 'plaintext' => false === $plaintext ? '' : $plaintext, 'key_mismatch' => false );
	}
}
