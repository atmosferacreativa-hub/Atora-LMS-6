<?php
/**
 * WP-CLI: `wp atora enrollment reconcile [--yes]`
 *
 * @package ATORA_LMS\LMS
 * @since   6.26.6
 */
namespace ATORA\LMS;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

class LMS_Enrollment_CLI {
	public static function init(): void {
		\WP_CLI::add_command( 'atora enrollment reconcile', array( __CLASS__, 'reconcile' ) );
	}

	/**
	 * Reconciliar curso → alumno: por cada user en `_clms_enrolled_users` que no
	 * esté en `_clms_enrolled_courses`, backfillea usando `CLMS_Helper`.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Escribe los cambios. Sin `--yes` solo reporta (dry-run).
	 *
	 * ## EXAMPLES
	 *
	 *     wp atora enrollment reconcile
	 *     wp atora enrollment reconcile --yes
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function reconcile( array $args, array $assoc_args ): void {
		atora_lms_require_module( 'modules/lms/class-lms-enrollment-reconciler.php' );
		if ( ! class_exists( '\ATORA\LMS\LMS_Enrollment_Reconciler' ) ) {
			\WP_CLI::error( 'LMS_Enrollment_Reconciler no disponible. ¿Está el plugin activo?' );
		}

		$write = ! empty( $assoc_args['yes'] );

		$plan = LMS_Enrollment_Reconciler::plan();
		\WP_CLI::log( 'Cursos escaneados: ' . absint( $plan['courses_scanned'] ?? 0 ) );
		\WP_CLI::log( 'Pares a corregir (curso → alumno): ' . absint( $plan['pairs_to_fix'] ?? 0 ) );
		\WP_CLI::log( 'Pares fantasma a podar (usuario inexistente): ' . absint( $plan['phantom_pairs_to_prune'] ?? 0 ) );

		if ( ! $write ) {
			\WP_CLI::success( 'Dry-run: no se escribió nada.' );
			return;
		}

		\WP_CLI::confirm( 'Esto escribirá usermeta/postmeta de matrícula. ¿Continuar?', $assoc_args );
		$result = LMS_Enrollment_Reconciler::apply();

		\WP_CLI::log( 'Corregidos: ' . absint( $result['fixed'] ?? 0 ) );
		\WP_CLI::log( 'Podados (usuario inexistente): ' . absint( $result['pruned'] ?? 0 ) );
		if ( ! empty( $result['errors'] ) ) {
			foreach ( (array) $result['errors'] as $err ) {
				\WP_CLI::log( 'ERROR: ' . (string) $err );
			}
			\WP_CLI::error( 'Reconciliación incompleta: hay errores.' );
		}
		\WP_CLI::success( 'Reconciliación completada.' );
	}
}
