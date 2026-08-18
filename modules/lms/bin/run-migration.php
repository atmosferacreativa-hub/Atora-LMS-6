<?php
/**
 * Script de migración LMS — Fase V S16
 * Ejecutado: 2026-05-18 (primera ejecución en producción)
 *
 * Uso desde raíz de WordPress:
 *   php wp-content/plugins/atora-lms/modules/lms/bin/run-migration.php
 *
 * O via WP-CLI:
 *   wp eval-file wp-content/plugins/atora-lms/modules/lms/bin/run-migration.php
 *
 * @package ATORA_LMS\LMS
 */

// Cargar WordPress si se ejecuta directamente
if ( ! defined( 'ABSPATH' ) ) {
	$wp_root = dirname( __DIR__, 5 ); // plugin -> plugins -> wp-content -> wp root
	$wp_load = $wp_root . '/wp-load.php';
	if ( ! file_exists( $wp_load ) ) {
		// Fallback: buscar wp-load.php subiendo hasta 8 niveles
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

// ── Verificación pre-migración ────────────────────────────────────────────────
echo "\n=== VERIFICACIÓN PRE-MIGRACIÓN (" . current_time( 'mysql' ) . ") ===\n";

$has_courses_table = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'atora_courses' ) ) === $wpdb->prefix . 'atora_courses';
if ( ! $has_courses_table ) {
	echo "ERROR: La tabla {$wpdb->prefix}atora_courses no existe. Activar el plugin primero.\n";
	exit( 1 );
}

$courses_new = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_courses" ); // phpcs:ignore
$courses_cpt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lm_course' AND post_status='publish'" ); // phpcs:ignore
$lessons_cpt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lm_lesson' AND post_status='publish'" ); // phpcs:ignore

printf( "atora_courses (ya migrados): %d\n", $courses_new );
printf( "lm_course CPT publicados:    %d\n", $courses_cpt );
printf( "lm_lesson CPT publicados:    %d\n", $lessons_cpt );

if ( ! class_exists( '\ATORA\LMS\LMS_Migrator' ) ) {
	// Cargar manualmente si no está disponible
	$migrator_file = __DIR__ . '/../class-lms-migrator.php';
	if ( file_exists( $migrator_file ) ) {
		require_once __DIR__ . '/../class-lms-course-service.php';
		require_once $migrator_file;
	} else {
		echo "ERROR: class-lms-migrator.php no encontrado.\n";
		exit( 1 );
	}
}

// ── Migración (F1.7: lotes sucesivos con cursores de continuación hasta que
//    reconcile() llegue a 0 pendientes, con tope de iteraciones) ──────────────
$batch          = 30;
$max_iterations = 50;
$stale_limit    = 3;

$status  = \ATORA\LMS\LMS_Migrator::get_status();
$pending = (int) ( $status['pending_total'] ?? 0 );

printf( "\nPendientes (reconcile()) antes de migrar: %d\n", $pending );

if ( 0 === $pending ) {
	echo "INFO: reconcile() ya está en 0. Nada que hacer.\n";
} else {
	printf( "\n=== EJECUTANDO MIGRACIÓN (batch=%d, hasta %d iteraciones) ===\n", $batch, $max_iterations );

	$stale_count = 0;
	for ( $i = 1; $i <= $max_iterations; $i++ ) {
		$result      = \ATORA\LMS\LMS_Migrator::migrate_all( $batch );
		$status      = \ATORA\LMS\LMS_Migrator::get_status();
		$pending_now = (int) ( $status['pending_total'] ?? 0 );

		printf(
			"  [%2d] cursos +%d, programas +%d, matrículas +%d, progreso +%d, gradebook +%d, certs +%d -> pendientes: %d\n",
			$i,
			(int) ( $result['courses']['migrated']             ?? 0 ),
			(int) ( $result['programs']['migrated']            ?? 0 ),
			(int) ( $result['enrollments']['migrated']         ?? 0 ),
			(int) ( $result['lesson_progress']['migrated']     ?? 0 ),
			(int) ( $result['gradebook']['upserted']           ?? 0 ),
			(int) ( $result['certificates']['upserted']        ?? 0 ),
			$pending_now
		);

		if ( 0 === $pending_now ) {
			break;
		}

		if ( $pending_now === $pending ) {
			$stale_count++;
			if ( $stale_count >= $stale_limit ) {
				printf( "\n⚠  Sin progreso en %d iteraciones seguidas (pendientes: %d). Puede haber referencias huérfanas permanentes — revisa el detalle de reconcile() abajo.\n", $stale_limit, $pending_now );
				break;
			}
		} else {
			$stale_count = 0;
		}

		$pending = $pending_now;
	}
}

// ── Verificación post-migración ───────────────────────────────────────────────
echo "\n=== VERIFICACIÓN POST-MIGRACIÓN ===\n";
$status = \ATORA\LMS\LMS_Migrator::get_status();
printf( "Cursos CPT:          %d\n", (int) ( $status['cpt_courses']      ?? 0 ) );
printf( "Cursos migrados:     %d (%.1f%%)\n", (int) ( $status['migrated_courses'] ?? 0 ), (float) ( $status['courses_pct'] ?? 0 ) );
printf( "Lecciones CPT:       %d\n", (int) ( $status['cpt_lessons']      ?? 0 ) );
printf( "Lecciones migradas:  %d (%.1f%%)\n", (int) ( $status['migrated_lessons'] ?? 0 ), (float) ( $status['lessons_pct'] ?? 0 ) );
printf( "Matrículas migradas: %d\n", (int) ( $status['migrated_enroll']  ?? 0 ) );

echo "\n--- reconcile() ---\n";
foreach ( (array) ( $status['reconcile'] ?? array() ) as $key => $value ) {
	printf( "%-35s %d\n", $key, (int) $value );
}
printf( "%-35s %d\n", 'pending_total', (int) ( $status['pending_total'] ?? 0 ) );

if ( ! empty( $status['is_complete'] ) ) {
	echo "\n✅ MIGRACIÓN F1 COMPLETA — reconcile() = 0 pendientes.\n";
} else {
	echo "\n⚠  MIGRACIÓN INCOMPLETA — quedan pendientes en reconcile(). Ejecuta de nuevo o activa el cron de continuación, y revisa el detalle anterior.\n";
	exit( 2 );
}

echo "\nMigración completada: " . current_time( 'mysql' ) . "\n\n";
