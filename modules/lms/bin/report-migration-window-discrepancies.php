<?php
/**
 * Reporte forense — candidatos a "robo de identidad" durante la
 * ventana de cutover — PT-3.2 (sprint 6.5.3)
 *
 * Script de una sola vez, no funcionalidad permanente. Lista cursos
 * de atora_courses cuyo wp_post_id apunta a un CPT lm_course cuyo
 * instructor_id real (autor/_clms_instructor_ids) NO coincide con el
 * instructor_id guardado en la fila — la señal más confiable
 * disponible de que esa fila pudo haberse creado por una vía distinta
 * al migrador (p.ej. REST, antes de que 6.5.1/6.5.2/6.5.3 cerraran
 * los gates de propiedad y de escritura de wp_post_id) durante la
 * ventana en la que el CPT ya existía pero el migrador todavía no lo
 * había procesado.
 *
 * Esto es SOLO un reporte para revisión manual — no corrige nada. La
 * corrección de datos ya comprometidos la decide una persona.
 *
 * Uso desde raíz de WordPress:
 *   php wp-content/plugins/atora-lms/modules/lms/bin/report-migration-window-discrepancies.php
 *
 * O via WP-CLI:
 *   wp eval-file wp-content/plugins/atora-lms/modules/lms/bin/report-migration-window-discrepancies.php
 *
 * @package ATORA_LMS\LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = dirname( __DIR__, 5 ); // plugin -> plugins -> wp-content -> wp root
	$wp_load = $wp_root . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		$dir = __DIR__;
		for ( $i = 0; $i < 8; $i++ ) {
			$dir = dirname( $dir );
			if ( file_exists( $dir . '/wp-load.php' ) ) {
				$wp_load = $dir . '/wp-load.php';
				break;
			}
		}
	}
	require_once $wp_load;
}

global $wpdb;

echo "\n=== REPORTE: candidatos a robo de identidad durante la ventana de cutover (" . current_time( 'mysql' ) . ") ===\n";
echo "Solo lectura — no modifica ningún dato. Para revisión manual.\n\n";

$courses_table = $wpdb->prefix . 'atora_courses';
$has_table      = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $courses_table ) ) === $courses_table;
if ( ! $has_table ) {
	echo "ERROR: {$courses_table} no existe. Activar el plugin primero.\n";
	exit( 1 );
}

$rows = $wpdb->get_results(
	"SELECT id, wp_post_id, instructor_id, created_at
	 FROM {$courses_table}
	 WHERE wp_post_id IS NOT NULL AND wp_post_id > 0
	 ORDER BY id ASC",
	ARRAY_A
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

$candidates = array();
$checked    = 0;

foreach ( (array) $rows as $row ) {
	$checked++;
	$post_id = absint( $row['wp_post_id'] );
	$post    = get_post( $post_id );
	if ( ! $post || 'lm_course' !== $post->post_type ) {
		// wp_post_id ya no apunta a un lm_course válido — señal
		// distinta (post borrado/cambiado de tipo), se reporta aparte.
		$candidates[] = array(
			'atora_course_id' => absint( $row['id'] ),
			'wp_post_id'      => $post_id,
			'row_instructor'  => absint( $row['instructor_id'] ?? 0 ),
			'real_instructor' => null,
			'row_created_at'  => (string) ( $row['created_at'] ?? '' ),
			'cpt_post_date'   => null,
			'motivo'          => $post ? 'wp_post_id apunta a un post que ya no es lm_course' : 'wp_post_id apunta a un post inexistente',
		);
		continue;
	}

	$instructor_ids = get_post_meta( $post_id, '_clms_instructor_ids', true );
	$real_instructor = is_array( $instructor_ids ) && ! empty( $instructor_ids )
		? absint( $instructor_ids[0] )
		: absint( $post->post_author );

	$row_instructor = absint( $row['instructor_id'] ?? 0 );

	if ( $real_instructor !== $row_instructor ) {
		$candidates[] = array(
			'atora_course_id' => absint( $row['id'] ),
			'wp_post_id'      => $post_id,
			'row_instructor'  => $row_instructor,
			'real_instructor' => $real_instructor,
			'row_created_at'  => (string) ( $row['created_at'] ?? '' ),
			'cpt_post_date'   => (string) $post->post_date_gmt,
			'motivo'          => 'instructor_id de la fila no coincide con el autor/instructor real del CPT',
		);
	}
}

printf( "Filas revisadas (wp_post_id IS NOT NULL): %d\n", $checked );
printf( "Candidatos encontrados: %d\n\n", count( $candidates ) );

if ( empty( $candidates ) ) {
	echo "Sin discrepancias — no hay candidatos que revisar.\n\n";
	exit( 0 );
}

printf(
	"%-16s %-12s %-14s %-14s %-20s %-20s %s\n",
	'atora_course_id',
	'wp_post_id',
	'instr. fila',
	'instr. real',
	'fila creada',
	'CPT creado',
	'motivo'
);
foreach ( $candidates as $c ) {
	printf(
		"%-16d %-12d %-14s %-14s %-20s %-20s %s\n",
		$c['atora_course_id'],
		$c['wp_post_id'],
		(string) $c['row_instructor'],
		null === $c['real_instructor'] ? 'n/d' : (string) $c['real_instructor'],
		$c['row_created_at'] ?: 'n/d',
		$c['cpt_post_date'] ?? 'n/d',
		$c['motivo']
	);
}

echo "\nEsto es un reporte de solo lectura. La corrección de cada caso\n";
echo "(reasignar, dejar como está, o desvincular) la decide una persona\n";
echo "revisando el contexto real de cada curso — este script no cambia nada.\n\n";
