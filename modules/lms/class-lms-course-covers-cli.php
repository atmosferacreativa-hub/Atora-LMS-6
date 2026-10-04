<?php
/**
 * WP-CLI: `wp atora courses repair-covers [--dry-run]`
 *
 * @package ATORA_LMS\LMS
 * @since   6.27.3
 */
namespace ATORA\LMS;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class LMS_Course_Covers_CLI {
	public static function init(): void {
		\WP_CLI::add_command( 'atora courses repair-covers', array( __CLASS__, 'repair_covers' ) );
	}

	/**
	 * Rellena `thumbnail_url` de la tabla de cursos desde la imagen destacada del
	 * post del curso. Repetible: los cursos ya correctos no se tocan.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Solo cuenta lo que repararía, sin escribir.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora courses repair-covers --dry-run
	 *     wp atora courses repair-covers
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function repair_covers( array $args, array $assoc_args ): void {
		if ( ! class_exists( '\ATORA_Course_Cover_Resolver' ) ) {
			\WP_CLI::error( 'ATORA_Course_Cover_Resolver no disponible. ¿Está el plugin activo?' );
		}
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$result  = \ATORA_Course_Cover_Resolver::repair_all( ! $dry_run );
		\WP_CLI::log( 'Cursos escaneados: ' . absint( $result['scanned'] ) );
		\WP_CLI::log( ( $dry_run ? 'Cursos a reparar: ' : 'Cursos reparados: ' ) . absint( $result['repaired'] ) );
		\WP_CLI::success( $dry_run ? 'Dry-run: no se escribió nada.' : 'Portadas al día.' );
	}
}
