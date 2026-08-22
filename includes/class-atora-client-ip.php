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
 * Sprint 6.5.7 (Prioridad 2): corrige el sentido de recorrido de
 * X-Forwarded-For (derecha→izquierda, no izquierda→derecha).
 *
 * Sprint 6.5.8 (Prioridad 1): hallazgo real remanente — los rangos
 * RFC1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7)
 * estaban en la lista de proxies confiables POR DEFECTO. Una IP
 * privada no implica "proxy que reescribe cabeceras de identidad de
 * forma confiable" — puede ser un balanceador, un ingress de
 * Kubernetes, un contenedor, o simplemente otra máquina de la misma
 * LAN. PRIVATE IP ≠ TRUSTED PROXY. La lista por defecto pasa a ser
 * conservadora (solo loopback); cualquier otro rango debe
 * configurarse explícitamente vía filtro. Además, CF-Connecting-IP
 * ahora tiene su propia lista de confianza separada
 * (`atora_trusted_cloudflare_cidrs`, vacía por defecto) — un proxy
 * genérico confiable NO autoriza automáticamente ese header
 * específico de Cloudflare.
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
	 * es la fuente de verdad. CF-Connecting-IP solo se consulta si
	 * REMOTE_ADDR está en la lista Cloudflare configurada
	 * explícitamente (separada de la lista de proxies genéricos). Las
	 * demás cabeceras reenviadas (X-Real-IP, X-Forwarded-For,
	 * X-Forwarded) solo se consultan si REMOTE_ADDR está en la lista
	 * de proxies genéricos confiables.
	 *
	 * @return string
	 */
	private static function resolve(): string {
		$remote_addr = self::normalize_ip_candidate( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' );
		if ( '' === $remote_addr ) {
			return '';
		}

		// PT-1 (6.5.8): CF-Connecting-IP es un header de UN solo valor
		// (Cloudflare nunca lo emite como cadena) y solo tiene
		// autoridad si REMOTE_ADDR pertenece a un rango Cloudflare
		// realmente configurado — nunca por pertenecer a un proxy
		// genérico o a un rango privado.
		if ( self::is_trusted_cloudflare_ip( $remote_addr ) ) {
			$raw = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) : '';
			if ( '' !== $raw ) {
				$candidate = self::normalize_ip_candidate( (string) $raw );
				if ( '' !== $candidate ) {
					return $candidate;
				}
			}
		}

		if ( ! self::is_trusted_generic_proxy_ip( $remote_addr ) ) {
			return $remote_addr;
		}

		$forwarded_headers = array(
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
	 * Algoritmo: recorrer de DERECHA a IZQUIERDA (el salto más cercano
	 * a REMOTE_ADDR primero) saltando cada hop que sea, a su vez, un
	 * proxy genérico de confianza; el primer hop NO confiable hallado
	 * en ese recorrido es el cliente real. Solo si la cadena entera
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

			if ( ! self::is_trusted_generic_proxy_ip( $ip ) ) {
				return $ip;
			}
		}

		return $fallback;
	}

	/**
	 * Proxies genéricos de confianza — autorizan X-Real-IP/
	 * X-Forwarded-For/X-Forwarded. Lista por defecto conservadora
	 * (solo loopback): rangos privados (RFC1918/ULA) NO se consideran
	 * proxies confiables automáticamente — una IP privada puede ser un
	 * balanceador, un ingress, un contenedor, o simplemente otra
	 * máquina de la misma LAN, no necesariamente algo que reescribe
	 * cabeceras de identidad de forma confiable.
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function is_trusted_generic_proxy_ip( string $ip ): bool {
		$default = array( '127.0.0.1', '127.0.0.0/8', '::1' );

		$trusted = apply_filters( 'atora_client_ip_trusted_proxies', $default );
		$legacy  = apply_filters( 'clms_student_assistant_trusted_proxies', $default );
		$cidrs   = apply_filters( 'atora_trusted_proxy_cidrs', $default );

		return self::ip_matches_rules( $ip, array_merge( (array) $trusted, (array) $legacy, (array) $cidrs ) );
	}

	/**
	 * Proxies Cloudflare de confianza — autorizan específicamente
	 * CF-Connecting-IP. Vacío por defecto a propósito: solo un sitio
	 * que de verdad está detrás de Cloudflare (y así lo configura
	 * explícitamente con los rangos oficiales de Cloudflare) debe
	 * confiar en este header.
	 *
	 * @param string $ip
	 * @return bool
	 */
	private static function is_trusted_cloudflare_ip( string $ip ): bool {
		$rules = apply_filters( 'atora_trusted_cloudflare_cidrs', array() );

		return self::ip_matches_rules( $ip, (array) $rules );
	}

	/**
	 * @param string             $ip
	 * @param array<int,string>  $rules Lista de IPs exactas y/o CIDR.
	 * @return bool
	 */
	private static function ip_matches_rules( string $ip, array $rules ): bool {
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

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
