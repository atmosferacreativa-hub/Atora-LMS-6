<?php
/**
 * Entregabilidad (6.38.0, Orden C · Comunicación).
 *
 * - Métricas por día y por proveedor: enviados, entregados, rebotes, quejas, aperturas y clics.
 * - Verificador DNS del dominio remitente (SPF, DKIM, DMARC) con la corrección concreta para el proveedor.
 *   El resolvedor se inyecta para las pruebas; por defecto usa dns_get_record.
 *
 * @package ATORA_LMS
 * @since 6.38.0
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Email_Deliverability {

	/** SPF que publica cada proveedor (alguno debe aparecer en el registro). */
	const SPF_INCLUDES = array(
		'sendgrid' => array( 'include:sendgrid.net' ),
		'mailgun'  => array( 'include:mailgun.org', 'include:eu.mailgun.org' ),
		'postmark' => array( 'include:spf.mtasv.net' ),
		'brevo'    => array( 'include:spf.brevo.com', 'include:spf.sendinblue.com' ),
		'ses'      => array( 'include:amazonses.com' ),
	);

	/** Selectores DKIM habituales por proveedor, si no se configuró uno. */
	const DKIM_SELECTORS = array(
		'sendgrid' => array( 's1', 's2' ),
		'mailgun'  => array( 'mta', 'k1', 'smtp', 'mailo', 'pic' ),
		'postmark' => array( 'pm', 'default' ),
		'brevo'    => array( 'mail', 'brevo1', 'brevo2' ),
		'ses'      => array(),
		'smtp'     => array( 'default', 'google', 'selector1', 'selector2', 'k1', 'dkim', 'mail' ),
	);

	/**
	 * @return array<int,array{day:string,provider:string,sent:int,delivered:int,bounced:int,complained:int,opened:int,clicked:int}>
	 */
	public static function daily_stats( int $days = 14 ): array {
		global $wpdb;
		$days  = max( 1, min( 90, $days ) );
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$q     = $wpdb->prefix . 'atora_email_queue';
		$e     = $wpdb->prefix . 'atora_email_events';
		// phpcs:ignore WordPress.DB
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(q.sent_at) AS day, q.provider AS provider,
			        COUNT(*) AS sent,
			        SUM( EXISTS( SELECT 1 FROM {$e} d WHERE d.queue_id = q.id AND d.event_type = 'delivered' ) ) AS delivered,
			        SUM( EXISTS( SELECT 1 FROM {$e} b WHERE b.queue_id = q.id AND b.event_type = 'bounced' ) OR q.status = 'bounced' ) AS bounced,
			        SUM( EXISTS( SELECT 1 FROM {$e} c WHERE c.queue_id = q.id AND c.event_type = 'complained' ) ) AS complained,
			        SUM( q.opened_at IS NOT NULL ) AS opened,
			        SUM( q.clicked_at IS NOT NULL ) AS clicked
			 FROM {$q} q
			 WHERE q.sent_at IS NOT NULL AND q.sent_at >= %s
			 GROUP BY DATE(q.sent_at), q.provider
			 ORDER BY day DESC, provider ASC",
			$since
		), ARRAY_A );
		return array_map(
			static function ( array $row ): array {
				foreach ( array( 'sent', 'delivered', 'bounced', 'complained', 'opened', 'clicked' ) as $k ) {
					$row[ $k ] = (int) $row[ $k ];
				}
				return $row;
			},
			(array) $rows
		);
	}

	/**
	 * @param callable|null $resolver (host, 'TXT'|'CNAME') => string[] ; null = DNS real.
	 * @return array<int,array{check:string,status:string,found:string,fix:string}> status: ok|warning|missing
	 */
	public static function dns_check( string $domain, string $provider, string $selector = '', ?callable $resolver = null ): array {
		$domain   = strtolower( trim( $domain ) );
		$provider = sanitize_key( $provider );
		$resolver = $resolver ?? array( __CLASS__, 'resolve' );
		if ( 1 !== preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain ) ) {
			return array( array( 'check' => 'domain', 'status' => 'missing', 'found' => $domain, 'fix' => __( 'Escribe un dominio válido (p. ej. tudominio.com).', 'atora-lms' ) ) );
		}
		return array( self::spf( $domain, $provider, $resolver ), self::dkim( $domain, $provider, $selector, $resolver ), self::dmarc( $domain, $resolver ) );
	}

	private static function spf( string $domain, string $provider, callable $resolver ): array {
		$records  = array_values( array_filter( (array) $resolver( $domain, 'TXT' ), static fn( $t ) => 0 === stripos( trim( (string) $t ), 'v=spf1' ) ) );
		$includes = self::SPF_INCLUDES[ $provider ] ?? array();
		$suggest  = 'v=spf1 ' . ( $includes ? $includes[0] . ' ' : '' ) . '~all';
		if ( ! $records ) {
			return array( 'check' => 'SPF', 'status' => 'missing', 'found' => '', 'fix' => sprintf( /* translators: 1: domain 2: record */ __( 'Crea un registro TXT en %1$s con: %2$s', 'atora-lms' ), $domain, $suggest ) );
		}
		if ( count( $records ) > 1 ) {
			return array( 'check' => 'SPF', 'status' => 'warning', 'found' => implode( ' | ', $records ), 'fix' => __( 'Hay más de un registro SPF y eso lo invalida: únelos en uno solo.', 'atora-lms' ) );
		}
		$record = strtolower( (string) $records[0] );
		if ( false !== strpos( $record, '+all' ) ) {
			return array( 'check' => 'SPF', 'status' => 'warning', 'found' => $records[0], 'fix' => __( '"+all" deja enviar a cualquiera en tu nombre: cámbialo por "~all" o "-all".', 'atora-lms' ) );
		}
		foreach ( $includes as $include ) {
			if ( false !== strpos( $record, strtolower( $include ) ) ) {
				return array( 'check' => 'SPF', 'status' => 'ok', 'found' => $records[0], 'fix' => '' );
			}
		}
		if ( $includes ) {
			return array( 'check' => 'SPF', 'status' => 'warning', 'found' => $records[0], 'fix' => sprintf( /* translators: %s: include */ __( 'Falta autorizar a tu proveedor: agrega %s antes del "all".', 'atora-lms' ), $includes[0] ) );
		}
		return array( 'check' => 'SPF', 'status' => 'ok', 'found' => $records[0], 'fix' => __( 'Verifica que incluya el servidor SMTP que usas.', 'atora-lms' ) );
	}

	private static function dkim( string $domain, string $provider, string $selector, callable $resolver ): array {
		$selectors = '' !== $selector ? array( sanitize_key( $selector ) ) : ( self::DKIM_SELECTORS[ $provider ] ?? self::DKIM_SELECTORS['smtp'] );
		foreach ( $selectors as $sel ) {
			$host = $sel . '._domainkey.' . $domain;
			foreach ( (array) $resolver( $host, 'TXT' ) as $txt ) {
				if ( false !== stripos( (string) $txt, 'p=' ) ) {
					return array( 'check' => 'DKIM', 'status' => 'ok', 'found' => $host, 'fix' => '' );
				}
			}
			$cname = (array) $resolver( $host, 'CNAME' );
			if ( $cname ) {
				return array( 'check' => 'DKIM', 'status' => 'ok', 'found' => $host . ' → ' . (string) $cname[0], 'fix' => '' );
			}
		}
		return array(
			'check'  => 'DKIM',
			'status' => 'missing',
			'found'  => '',
			'fix'    => '' !== $selector
				? sprintf( /* translators: %s: host */ __( 'No hay clave DKIM en %s. Copia en tu DNS el registro que te da el proveedor.', 'atora-lms' ), $selectors[0] . '._domainkey.' . $domain )
				: __( 'No se encontró DKIM con los selectores habituales. Copia en tu DNS los registros DKIM que te da el proveedor y escribe aquí su selector.', 'atora-lms' ),
		);
	}

	private static function dmarc( string $domain, callable $resolver ): array {
		$records = array_values( array_filter( (array) $resolver( '_dmarc.' . $domain, 'TXT' ), static fn( $t ) => 0 === stripos( trim( (string) $t ), 'v=dmarc1' ) ) );
		$suggest = 'v=DMARC1; p=none; rua=mailto:dmarc@' . $domain;
		if ( ! $records ) {
			return array( 'check' => 'DMARC', 'status' => 'missing', 'found' => '', 'fix' => sprintf( /* translators: 1: host 2: record */ __( 'Crea un registro TXT en %1$s con: %2$s (empieza con p=none y súbelo a quarantine cuando los informes estén limpios).', 'atora-lms' ), '_dmarc.' . $domain, $suggest ) );
		}
		if ( 1 === preg_match( '/;\s*p\s*=\s*none/i', (string) $records[0] ) ) {
			return array( 'check' => 'DMARC', 'status' => 'warning', 'found' => $records[0], 'fix' => __( 'Publicado en modo observación (p=none). Cuando SPF y DKIM pasen, cámbialo a p=quarantine.', 'atora-lms' ) );
		}
		return array( 'check' => 'DMARC', 'status' => 'ok', 'found' => $records[0], 'fix' => '' );
	}

	/** @return string[] */
	public static function resolve( string $host, string $type ): array {
		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}
		$records = @dns_get_record( $host, 'CNAME' === $type ? DNS_CNAME : DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- un dominio sin registro devuelve aviso.
		if ( ! is_array( $records ) ) {
			return array();
		}
		$out = array();
		foreach ( $records as $record ) {
			if ( 'CNAME' === $type && ! empty( $record['target'] ) ) {
				$out[] = (string) $record['target'];
			} elseif ( 'TXT' === $type ) {
				$out[] = isset( $record['entries'] ) ? implode( '', (array) $record['entries'] ) : (string) ( $record['txt'] ?? '' );
			}
		}
		return $out;
	}
}
