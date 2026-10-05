<?php
/**
 * Diagnóstico de solo lectura (6.29.5): qué notas finales cambian con la regla
 * nueva del promedio (una nota de cero no es "sin notas").
 *
 * Uso (con el plugin que esté instalado, ANTES de actualizar):
 *   wp eval-file wp-content/plugins/atora-lms/scripts/audit-zero-grade-averages.php > auditoria-ceros.txt
 *
 * Funciona con cualquier versión instalada (6.28.x en adelante): no depende de
 * que el motor ya tenga la regla nueva.
 * - "Actual": la nota final que el plugin instalado muestra hoy.
 * - "Nueva": la regla de 6.29.5, calculada desde cada nota (quizzes con intento;
 *   tareas con nota liberada), combinando por cantidad de notas y separando
 *   "sin notas" de 0.
 *
 * No modifica notas ni matrículas. Al pedir el resumen, el motor puede regenerar
 * su caché (lo mismo que abrir el panel). La salida trae nombres de estudiantes:
 * guardarla en un lugar privado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Ejecutar con: wp eval-file scripts/audit-zero-grade-averages.php\n" );
}

$engine = function_exists( 'clms_core' ) ? clms_core( 'CLMS_Assessment_Engine' ) : null;
if ( ! $engine || ! method_exists( $engine, 'build_course_gradebook' ) || ! class_exists( 'CLMS_Helper' ) ) {
	echo "El motor de evaluación no está disponible.\n";
	return;
}

/** Promedio entero 0–100, o null sin notas (igual que CLMS_Grade_Average). */
$average = static function ( array $scores ) {
	$scores = array_values( array_filter( $scores, 'is_numeric' ) );
	if ( ! $scores ) {
		return null;
	}
	$scores = array_map( static fn( $v ) => max( 0, min( 100, (float) $v ) ), $scores );
	return (int) round( array_sum( $scores ) / count( $scores ) );
};

/** Nota liberada: la regla de 6.29.0 (solo `graded`), o la clase si está instalada. */
$released = static function ( string $status, $grade ): bool {
	if ( class_exists( 'CLMS_Student_Grade_Visibility' ) ) {
		return CLMS_Student_Grade_Visibility::grade_visible( $status, $grade );
	}
	return '' !== (string) $grade && 'graded' === $status;
};

$label = static fn( $v ) => null === $v ? 'sin notas' : (string) $v;

$version  = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '?';
$students = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
$changed  = array();
$pairs    = 0;
$to_null  = 0;

foreach ( $students as $student ) {
	$courses = array_filter( array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_courses( (int) $student->ID ) ) );
	foreach ( $courses as $course_id ) {
		++$pairs;
		$gradebook = (array) $engine->build_course_gradebook( (int) $student->ID, $course_id );
		$summary   = (array) ( $gradebook['summary'] ?? array() );
		$current   = isset( $summary['final_average'] ) && is_numeric( $summary['final_average'] ) ? (int) $summary['final_average'] : null;

		$quiz = array();
		$task = array();
		foreach ( (array) ( $gradebook['entries'] ?? array() ) as $entry ) {
			if ( '' !== (string) ( $entry['quiz_grade'] ?? '' ) ) {
				$quiz[] = $entry['quiz_grade'];
			}
			$grade = $entry['assignment_grade'] ?? '';
			if ( '' !== (string) $grade && $released( (string) ( $entry['submission_status'] ?? '' ), $grade ) ) {
				$task[] = $grade;
			}
		}
		$q   = $average( $quiz );
		$t   = $average( $task );
		$new = ( null !== $q && null !== $t ) ? (int) round( ( $q + $t ) / 2 ) : ( $q ?? $t );

		if ( null === $new && ( null === $current || 0 === $current ) ) {
			++$to_null; // Antes "0", ahora "sin notas": no cambia ninguna nota real.
			continue;
		}
		if ( $current !== $new ) {
			$changed[] = array( (int) $student->ID, $student->display_name, $student->user_email, $course_id, get_the_title( $course_id ), $current, $new, $q, $t, count( $quiz ), count( $task ) );
		}
	}
}

printf( "Auditoría de promedios con cero — plugin instalado %s — %s\n\n", $version, wp_date( 'Y-m-d H:i' ) );
printf( "Pares estudiante × curso revisados: %d\nSin notas (hoy se ve 0, con 6.29.5 \"sin notas\"): %d\nNotas finales que cambian: %d\n\n", $pairs, $to_null, count( $changed ) );
foreach ( $changed as $row ) {
	printf(
		"Estudiante %d (%s, %s) · curso %d \"%s\": actual %s → nueva %s (quizzes: %s en %d notas; tareas: %s en %d notas)\n",
		$row[0], $row[1], $row[2], $row[3], $row[4], $label( $row[5] ), $label( $row[6] ), $label( $row[7] ), $row[9], $label( $row[8] ), $row[10]
	);
}
