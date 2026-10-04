<?php
/**
 * Errores de escritura de la API móvil (6.28.2).
 *
 * `$wpdb->query()`, `insert()` y `update()` devuelven `false` ante un error
 * SQL. Tratarlo como "0 filas" hacía que la ruta respondiera 200 y la app
 * diera el evento por entregado. Un fallo se registra (sin datos personales)
 * y la ruta responde 503 para que la app reintente.
 *
 * @package ATORA_LMS
 * @since 6.28.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Mobile_Db_Errors {

	/** Registra el error SQL sin valores: los literales entre comillas se ocultan. */
	public static function log( string $context ): void {
		global $wpdb;
		$message = is_object( $wpdb ) && isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
		$message = (string) preg_replace( "/'[^']*'/", "'…'", $message );
		if ( function_exists( 'error_log' ) ) {
			error_log( '[ATORA mobile] ' . $context . ': ' . ( '' !== $message ? $message : 'error de base de datos' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	public static function unavailable( string $context ): WP_Error {
		self::log( $context );
		return new WP_Error(
			'atora_mobile_storage_unavailable',
			__( 'No se pudo guardar. Reintenta en unos segundos.', 'atora-lms' ),
			array( 'status' => 503 )
		);
	}
}
