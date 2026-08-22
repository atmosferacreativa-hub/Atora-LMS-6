<?php
/**
 * ATORA_Client_IP — resolución centralizada de IP de cliente, consciente de proxies confiables.
 *
 * Sprint 6.5.5 (Prioridad 1): extraído de la implementación ya
 * correcta de Student_Assistant::resolve_client_ip() (REMOTE_ADDR como
 * fuente de verdad; las cabeceras reenviadas solo se usan si
 * REMOTE_ADDR pertenece a un proxy de confianza configurado). Se
 * centraliza acá para que Forms_Builder y cualquier otro módulo dejen
 * de replicar su propia versión (algunas de ellas, como
 * Extended_Registration::get_client_ip() y la versión previa de
 * Forms_Builder::get_client_ip(), confiaban en X-Forwarded-For sin
 * verificar el origen — evadible con una cabecera falsificada).
 *
 * @package ATORA_LMS
 * @since   6.5.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ATORA_Client_IP
 *
 * @since 6.5.5
 */
class ATORA_Client_IP {

	/**
	 * IP de cliente en texto plano, respetando proxies confiables.
	 * Cadena vacía si no se pudo determinar.
	 *
	 * @return string
	 */
	public static function get(): string {
		return self::resolve();
	}

	/**
	 * Igual que get(), pero como hash (sha256 + salt de WP) para casos
	 * que necesitan una clave estable sin persistir la IP en claro
	 * (p.ej. contadores de rate limit en transients).
	 *
	 * @return string
	 */
	public static function get_hashed(): string {
		$ip = self::resolve();
		if ( '' === $ip ) {
			$ip = '0.0.0.0';
		}
		return hash( 'sha256', $ip . '|' . wp_salt( 'auth' ) );
	}

	/**
	 * Resuelve IP de cliente respetando proxies confiables: REMOTE_ADDR
	 * es la fuente de verdad; las cabeceras reenviadas (CF-Connecting-IP,
	 * X-Real-IP, X-Forwarded-For, X-Forwarded) solo se consultan si
	 * REMOTE_ADDR coincide con un proxy conocido/configurado.
	 *
	 * @return string
	 */
	private static function resolve(): string {
		$remote_addr = self::normalize_ip_candidate( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' === $remote_addr ) {
			return '';
		}

		if ( ! self::is_trusted_proxy_ip( $remote_addr ) ) {
			return $remote_addr;
		}

		$forwarded_headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_REAL_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
		);

		foreach ( $forwarded_headers as $header_key ) {
			$raw = isset( $_SERVER[ $header_key ] ) ? wp_unslash( $_SERVER[ $header_key ] ) : '';
			if ( '' === $raw ) {
				continue;
			}

			$candidate = self::extract_forwarded_ip( (string) $raw );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return $remote_addr;
	}

	/**
	 * Selecciona la IP de cliente real desde una cadena X-Forwarded-For
	 * (o cabecera equivalente de un solo valor, que igual funciona con
	 * este mismo recorrido).
	 *
	 * PT-2 (6.5.7): hallazgo real — la versión anterior recorría la
	 * cadena de IZQUIERDA a DERECHA y devolvía la primera que no fuera
	 * un proxy de confianza. Eso es exactamente al revés de lo seguro:
	 * el cliente controla el extremo IZQUIERDO de la cadena (puede
	 * escribir cualquier valor ahí), y cada proxy de confianza real
	 * solo puede APPENDEAR al final (derecha). Con REMOTE_ADDR
	 * confiable y XFF = "1.2.3.4, 203.0.113.20" (el proxy añadió la IP
	 * real del cliente a la derecha de lo que el propio cliente ya
	 * había mandado), la versión anterior devolvía "1.2.3.4" —
	 * exactamente el valor que el atacante puso — en vez de
	 * "203.0.113.20".
	 *
	 * Algoritmo correcto: recorrer de DERECHA a IZQUIERDA (el salto más
	 * cercano a REMOTE_ADDR primero) saltando cada hop que sea, a su
	 * vez, un proxy de confianza; el primer hop NO confiable hallado en
	 * ese recorrido es el cliente real. Solo si la cadena entera
	 * resultara ser proxies de confianza (caso degenerado) se cae al
	 * hop más a la derecha como mejor esfuerzo.
	 *
	 * @param string $raw
	 * @return string
	 */
	private static function extract_forwarded_ip( string $raw ): string {
		$candidates = array_reverse( array_map( 'trim', explode( ',', $raw ) ) );
		$fallback   = '';

		foreach ( $candidates as $candidate ) {
			$ip = self::normalize_ip_candidate( $candidate );
			if ( '' === $ip ) {
				continue;
			}

			if ( '' === $fallback ) {
				$fallback = $ip;
			}

			if ( ! self::is_trusted_proxy_ip( $ip ) ) {
				return $ip;
			}
		}

		return $fallback;
	}

	/**
	 * Determina si una IP de salto intermedio corresponde a proxy confiable.
	 *
	 * Filtro `atora_client_ip_trusted_proxies` (nuevo, centralizado) y,
	 * por compatibilidad, `clms_student_assistant_trusted_proxies`
	 * (el filtro que ya podía estar configurado en sitios existentes
	 * antes de esta centralización) — ambos se combinan.
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function is_trusted_proxy_ip( string $ip ): bool {
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$default_trusted = array(
			'127.0.0.1',
			'::1',
			'10.0.0.0/8',
			'172.16.0.0/12',
			'192.168.0.0/16',
			'fc00::/7',
		);

		$trusted = apply_filters( 'atora_client_ip_trusted_proxies', $default_trusted );
		$legacy  = apply_filters( 'clms_student_assistant_trusted_proxies', $default_trusted );
		// PT-2 (6.5.7): alias con el nombre que documenta la OT de este
		// sprint — mismo filtro, para quien ya lo use con ese nombre.
		$cidrs   = apply_filters( 'atora_trusted_proxy_cidrs', $default_trusted );

		$rules = array_merge( (array) $trusted, (array) $legacy, (array) $cidrs );

		foreach ( $rules as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}

			if ( false !== strpos( $rule, '/' ) ) {
				if ( self::ip_in_cidr( $ip, $rule ) ) {
					return true;
				}
				continue;
			}

			if ( $ip === $rule ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Comprueba si una IP pertenece a un rango CIDR (IPv4/IPv6).
	 *
	 * @param string $ip
	 * @param string $cidr
	 * @return bool
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		list( $subnet, $prefix ) = $parts;
		$subnet = trim( $subnet );
		$prefix = absint( $prefix );

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bits = strlen( $ip_bin ) * 8;
		if ( $prefix < 0 || $prefix > $bits ) {
			return false;
		}

		$bytes     = intdiv( $prefix, 8 );
		$remainder = $prefix % 8;

		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		if ( 0 === $remainder ) {
			return true;
		}

		$mask = chr( ( 0xFF << ( 8 - $remainder ) ) & 0xFF );

		return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
	}

	/**
	 * Normaliza un candidato de IP removiendo puertos y formatos no válidos.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private static function normalize_ip_candidate( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		// Formato: [IPv6]:port
		if ( preg_match( '/^\[([0-9a-fA-F:]+)\](?::\d+)?$/', $value, $matches ) ) {
			$value = $matches[1];
		} elseif ( 1 === substr_count( $value, ':' ) && false !== strpos( $value, '.' ) ) {
			// Formato IPv4:port.
			$parts = explode( ':', $value );
			$value = $parts[0];
		}

		return filter_var( $value, FILTER_VALIDATE_IP ) ? $value : '';
	}
}
