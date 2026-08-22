<?php
/**
 * ATORA_Security_Maintenance — limpieza periódica de las tablas de rate limit.
 *
 * Sprint 6.5.7 (Prioridad 4): las filas de atora_form_throttle,
 * atora_api_rate_limit y atora_rate_limit_counters no se borraban
 * nunca — cada IP/API-key/identificador nuevo agrega filas para
 * siempre. Un único evento de WP-Cron central se encarga de purgar
 * las ventanas vencidas de las tres tablas, en vez de que cada módulo
 * programe su propio cron de limpieza.
 *
 * @package ATORA_LMS
 * @since   6.5.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ATORA_Security_Maintenance
 *
 * @since 6.5.7
 */
class ATORA_Security_Maintenance {

	const CRON_HOOK = 'atora_security_maintenance';

	/** Formularios públicos: ventana de throttle de 15 min — conservar 48h de historial es más que suficiente para investigar abuso reciente. */
	const FORM_THROTTLE_RETENTION_SECONDS = 2 * DAY_IN_SECONDS;

	/** API/MCP: ventanas de 1 minuto — 24h de retención alcanza para auditoría sin crecer sin límite. */
	const API_RATE_LIMIT_RETENTION_SECONDS = DAY_IN_SECONDS;

	/** Contador genérico (Student Assistant y futuros usos) — mismo criterio que el de formularios. */
	const GENERIC_RATE_LIMIT_RETENTION_SECONDS = DAY_IN_SECONDS;

	/**
	 * @return void
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Ejecuta la limpieza sobre las tres tablas de rate limit. Devuelve
	 * el conteo de filas borradas por tabla — útil para diagnóstico y
	 * para que los tests verifiquen que el DELETE realmente corre.
	 *
	 * @return array{form_throttle:int,api_rate_limit:int,generic_rate_limit:int}
	 */
	public static function run(): array {
		if ( ! class_exists( 'ATORA_Rate_Limiter' ) ) {
			return array( 'form_throttle' => -1, 'api_rate_limit' => -1, 'generic_rate_limit' => -1 );
		}

		$form_throttle_deleted = ATORA_Rate_Limiter::purge_expired(
			'atora_form_throttle',
			'window_start',
			self::FORM_THROTTLE_RETENTION_SECONDS
		);

		$api_rate_limit_deleted = ATORA_Rate_Limiter::purge_expired_minute_key(
			'atora_api_rate_limit',
			'minute_key',
			self::API_RATE_LIMIT_RETENTION_SECONDS
		);

		$generic_rate_limit_deleted = ATORA_Rate_Limiter::purge_expired(
			'atora_rate_limit_counters',
			'window_start',
			self::GENERIC_RATE_LIMIT_RETENTION_SECONDS
		);

		return array(
			'form_throttle'      => $form_throttle_deleted,
			'api_rate_limit'     => $api_rate_limit_deleted,
			'generic_rate_limit' => $generic_rate_limit_deleted,
		);
	}
}
