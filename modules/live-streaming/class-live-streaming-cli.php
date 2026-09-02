<?php
/**
 * Live_Streaming_CLI — P6.3 (sprint 6.13.0)
 *
 * `wp atora live-streaming migrate`
 *
 * Mismo patrón que `wp atora lms cutover`: comando explícito en vez de
 * correr el volcado automáticamente en cada carga — un despliegue con
 * miles de lecciones live no debería pagar ese costo en cada request.
 *
 * @package ATORA_LMS\LiveStreaming
 * @since   6.13.0
 */

namespace ATORA\LiveStreaming;

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class Live_Streaming_CLI {

	public static function init(): void {
		\WP_CLI::add_command( 'atora live-streaming migrate', array( __CLASS__, 'migrate' ) );
	}

	/**
	 * Vuelca sesiones y asistencia de postmeta/usermeta hacia
	 * atora_live_sessions / atora_attendance. Idempotente — se puede
	 * correr repetidamente sin duplicar filas.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora live-streaming migrate
	 */
	public static function migrate(): void {
		if ( ! class_exists( '\ATORA\LiveStreaming\Live_Streaming_Migrator' ) ) {
			\WP_CLI::error( 'Live_Streaming_Migrator no disponible. ¿Está el módulo live-streaming activo?' );
			return;
		}

		$result = Live_Streaming_Migrator::migrate_all();

		\WP_CLI::success( sprintf(
			'%d sesiones y %d registros de asistencia migrados.',
			$result['sessions'],
			$result['attendance']
		) );
	}
}
