<?php
/**
 * LMS CLI — F4 task 1.6
 *
 * `wp atora lms cutover --status | --run | --rollback`
 *
 * Reutiliza el mismo gate que el panel admin (LMS_Parity::cutover_ready())
 * y el mismo punto único de escritura del flip (LMS_Read_Router::set_source()),
 * para despliegues sin acceso al panel de administración.
 *
 * @package ATORA_LMS\LMS
 * @since   6.3.0
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class LMS_CLI {

	public static function init(): void {
		\WP_CLI::add_command( 'atora lms cutover', array( __CLASS__, 'cutover' ) );
	}

	/**
	 * Gestiona el cutover de lectura F4 (legacy → tablas).
	 *
	 * ## OPTIONS
	 *
	 * [--status]
	 * : Muestra el estado del gate y la fuente de lectura activa. Default si no se pasa ninguna acción.
	 *
	 * [--run]
	 * : Ejecuta el flip a 'tables' si el gate está listo. Falla con los motivos si no.
	 *
	 * [--rollback]
	 * : Revuelve la fuente de lectura a 'legacy'. Siempre disponible, instantáneo.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora lms cutover --status
	 *     wp atora lms cutover --run
	 *     wp atora lms cutover --rollback
	 *
	 * @param array $args       Argumentos posicionales (sin uso).
	 * @param array $assoc_args Argumentos con nombre (--status, --run, --rollback).
	 */
	public static function cutover( array $args, array $assoc_args ): void {
		if ( ! class_exists( LMS_Parity::class ) || ! class_exists( LMS_Read_Router::class ) ) {
			\WP_CLI::error( 'LMS_Parity / LMS_Read_Router no disponibles. ¿Está el plugin activo?' );
			return;
		}

		if ( isset( $assoc_args['run'] ) ) {
			self::run();
			return;
		}

		if ( isset( $assoc_args['rollback'] ) ) {
			self::rollback();
			return;
		}

		self::status();
	}

	private static function status(): void {
		$source = LMS_Read_Router::source();
		$gate   = LMS_Parity::cutover_ready();

		\WP_CLI::log( "Fuente de lectura actual: {$source}" );
		\WP_CLI::log( 'Gate de cutover: ' . ( $gate['ready'] ? 'LISTO' : 'NO LISTO' ) );

		if ( ! $gate['ready'] ) {
			foreach ( $gate['reasons'] as $reason ) {
				\WP_CLI::log( " - {$reason}" );
			}
		}
	}

	private static function run(): void {
		if ( LMS_Read_Router::is_tables() ) {
			\WP_CLI::success( 'Ya está en modo tables. Nada que hacer.' );
			return;
		}

		$gate = LMS_Parity::cutover_ready();
		if ( ! $gate['ready'] ) {
			\WP_CLI::log( 'Gate de cutover no superado:' );
			foreach ( $gate['reasons'] as $reason ) {
				\WP_CLI::log( " - {$reason}" );
			}
			\WP_CLI::error( 'Flip abortado.' );
			return;
		}

		self::append_log( 'flip', LMS_Read_Router::source(), 'tables' );
		update_option( 'atora_lms_cutover_at', current_time( 'mysql', true ), false );
		LMS_Read_Router::set_source( 'tables' );

		\WP_CLI::success( 'Cutover ejecutado: atora_lms_read_source = tables.' );
	}

	private static function rollback(): void {
		if ( ! LMS_Read_Router::is_tables() ) {
			\WP_CLI::success( 'Ya está en modo legacy. Nada que hacer.' );
			return;
		}

		self::append_log( 'rollback', LMS_Read_Router::source(), 'legacy' );
		LMS_Read_Router::set_source( 'legacy' );

		\WP_CLI::success( 'Rollback ejecutado: atora_lms_read_source = legacy.' );
	}

	private static function append_log( string $action, string $from, string $to ): void {
		$snapshot   = get_option( 'atora_lms_cutover_log', array() );
		$snapshot[] = array(
			'action'     => $action,
			'from'       => $from,
			'to'         => $to,
			'at'         => current_time( 'mysql', true ),
			'by_user_id' => 0,
			'via'        => 'wp-cli',
		);
		update_option( 'atora_lms_cutover_log', $snapshot, false );
	}
}
