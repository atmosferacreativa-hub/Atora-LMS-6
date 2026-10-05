<?php
/**
 * Diagnóstico (6.29.5): notas finales que cambian con la regla nueva del promedio.
 *
 * Uso: wp eval-file scripts/audit-zero-grade-averages.php
 *
 * Antes de 6.29.5 el promedio del curso decidía qué combinar con `> 0`
 * (quiz 0 + tarea 100 = 100) y "sin notas" se guardaba como 0. Este script
 * recorre estudiante × curso matriculado, toma los promedios de quiz y tarea
 * que da el motor actual y reconstruye la nota anterior con la regla vieja.
 *
 * No modifica notas ni matrículas. Al pedir el resumen, el motor puede
 * regenerar su caché (lo mismo que abrir el panel).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Ejecutar con: wp eval-file scripts/audit-zero-grade-averages.php\n" );
}

$engine = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Assessment_Engine' ) : null;
if ( ! $engine || ! method_exists( $engine, 'build_course_gradebook' ) || ! class_exists( 'CLMS_Helper' ) ) {
	echo "El motor de evaluación no está disponible.\n";
	return;
}

/** Regla anterior a 6.29.5, a partir de los promedios por tipo (null = sin notas). */
$old_rule = static function ( $quiz, $task ): int {
	$q = null === $quiz ? 0 : (int) $quiz;
	$t = null === $task ? 0 : (int) $task;
	if ( $q > 0 && $t > 0 ) {
		return (int) round( ( $q + $t ) / 2 );
	}
	return $q > 0 ? $q : $t;
};

$students = get_users( array( 'fields' => array( 'ID', 'display_name' ) ) );
$changed  = array();
$to_null  = 0;
$pairs    = 0;

foreach ( $students as $student ) {
	$courses = array_filter( array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_courses( (int) $student->ID ) ) );
	foreach ( $courses as $course_id ) {
		++$pairs;
		$summary = (array) ( $engine->build_course_gradebook( (int) $student->ID, $course_id )['summary'] ?? array() );
		$quiz    = isset( $summary['quiz_average'] ) && is_numeric( $summary['quiz_average'] ) ? (int) $summary['quiz_average'] : null;
		$task    = isset( $summary['assignment_average'] ) && is_numeric( $summary['assignment_average'] ) ? (int) $summary['assignment_average'] : null;
		$new     = isset( $summary['final_average'] ) && is_numeric( $summary['final_average'] ) ? (int) $summary['final_average'] : null;
		$old     = $old_rule( $quiz, $task );

		if ( null === $new ) {
			++$to_null; // Antes 0, ahora "sin notas": no cambia una nota real.
			continue;
		}
		if ( $old !== $new ) {
			$changed[] = array( (int) $student->ID, $student->display_name, $course_id, get_the_title( $course_id ), $old, $new, $quiz, $task );
		}
	}
}

printf( "Pares estudiante × curso revisados: %d\nSin notas (antes 0, ahora \"sin notas\"): %d\nNotas finales que cambian: %d\n\n", $pairs, $to_null, count( $changed ) );
foreach ( $changed as $row ) {
	printf( "Estudiante %d (%s) · curso %d \"%s\": %d → %d (quiz %s, tareas %s)\n", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], null === $row[6] ? '—' : $row[6], null === $row[7] ? '—' : $row[7] );
}
