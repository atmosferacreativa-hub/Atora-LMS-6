<?php
/**
 * WP-CLI: `wp atora lms align-tables [--dry-run]`
 *
 * @package ATORA_LMS\LMS
 * @since   6.27.4
 */
namespace ATORA\LMS;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class LMS_Editor_Sync_CLI {
	public static function init(): void {
		\WP_CLI::add_command( 'atora lms align-tables', array( __CLASS__, 'align_tables' ) );
	}

	/**
	 * Alinea `atora_courses` y `atora_lessons` con los posts del editor:
	 * inserta lo que falta, actualiza lo que difiere (subiendo `revision`) y
	 * marca como borradas las filas sin post. Repetible.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Solo cuenta lo que cambiaría, sin escribir.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora lms align-tables --dry-run
	 *     wp atora lms align-tables
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function align_tables( array $args, array $assoc_args ): void {
		if ( ! class_exists( '\ATORA\LMS\LMS_Editor_Sync' ) ) {
			\WP_CLI::error( 'LMS_Editor_Sync no disponible. ¿Está el plugin activo?' );
		}
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$result  = LMS_Editor_Sync::repair_all( ! $dry_run );
		foreach ( $result as $key => $count ) {
			\WP_CLI::log( str_pad( $key, 18 ) . absint( $count ) );
		}
		\WP_CLI::success( $dry_run ? 'Dry-run: no se escribió nada.' : 'Tablas alineadas con el editor.' );
	}
}
