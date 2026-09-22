<?php
/**
 * Runner para Lab sin WP-CLI: reconciliación de matrícula curso → alumno.
 *
 * Uso (dry-run):
 *   docker exec atora-wordpress php /tmp/enrollment-reconcile.php
 *
 * Uso (write):
 *   docker exec atora-wordpress php /tmp/enrollment-reconcile.php --yes
 */

define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

require_once WP_CONTENT_DIR . '/plugins/atora-lms/modules/lms/class-lms-enrollment-reconciler.php';

$write = in_array( '--yes', $argv ?? array(), true );

$plan = \ATORA\LMS\LMS_Enrollment_Reconciler::plan();
echo "Cursos escaneados: " . (int) ( $plan['courses_scanned'] ?? 0 ) . "\n";
echo "Pares a corregir (curso → alumno): " . (int) ( $plan['pairs_to_fix'] ?? 0 ) . "\n";
echo "Pares fantasma a podar (usuario inexistente): " . (int) ( $plan['phantom_pairs_to_prune'] ?? 0 ) . "\n";

if ( ! $write ) {
	echo "Dry-run: no se escribió nada.\n";
	exit( 0 );
}

$result = \ATORA\LMS\LMS_Enrollment_Reconciler::apply();
echo "Corregidos: " . (int) ( $result['fixed'] ?? 0 ) . "\n";
echo "Podados (usuario inexistente): " . (int) ( $result['pruned'] ?? 0 ) . "\n";
if ( ! empty( $result['errors'] ) ) {
	foreach ( (array) $result['errors'] as $err ) {
		echo "ERROR: {$err}\n";
	}
	exit( 1 );
}
echo "OK\n";
