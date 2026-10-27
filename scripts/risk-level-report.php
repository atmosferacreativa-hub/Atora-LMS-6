<?php
/**
 * Informe de solo lectura (6.36.0): niveles de riesgo académico con la regla canónica.
 *
 * Uso: wp eval-file scripts/risk-level-report.php [--course=ID]
 * No escribe nada. Sirve para revisar, al actualizar un sitio con estudiantes reales, cuántos quedan
 * en cada nivel y por qué motivo, antes de que los docentes lo vean.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'ATORA_Academic_Risk_Rule' ) ) {
	echo "Ejecutar con: wp eval-file scripts/risk-level-report.php (plugin 6.36.0 o posterior activo)\n";
	return;
}

$course_filter = 0;
foreach ( (array) ( $args ?? array() ) as $arg ) {
	if ( preg_match( '/^--course=(\d+)$/', (string) $arg, $m ) ) {
		$course_filter = (int) $m[1];
	}
}

$status_service = clms_core( 'CLMS_Academic_Status_Service' );
$courses        = $course_filter ? array( $course_filter ) : get_posts( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
$totals         = array( 'normal' => 0, 'medium' => 0, 'high' => 0 );
$reasons        = array();

foreach ( $courses as $course_id ) {
	$students = array_values( array_filter( array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( (int) $course_id ) ) ) );
	$by_level = array( 'normal' => 0, 'medium' => 0, 'high' => 0 );
	foreach ( $students as $student_id ) {
		$status = (array) $status_service->get_student_course_status( (int) $student_id, (int) $course_id );
		$level  = (string) ( $status['risk_level'] ?? 'normal' );
		$by_level[ $level ] = ( $by_level[ $level ] ?? 0 ) + 1;
		$totals[ $level ]   = ( $totals[ $level ] ?? 0 ) + 1;
		foreach ( (array) ( $status['risk_reason_codes'] ?? array() ) as $code ) {
			$reasons[ $code ] = ( $reasons[ $code ] ?? 0 ) + 1;
		}
	}
	printf( "Curso %d (%s): %d estudiantes · normal %d · medio %d · alto %d\n", $course_id, get_the_title( $course_id ), count( $students ), $by_level['normal'], $by_level['medium'], $by_level['high'] );
}

printf( "\nTotal · normal %d · medio %d · alto %d\n", $totals['normal'], $totals['medium'], $totals['high'] );
arsort( $reasons );
foreach ( $reasons as $code => $count ) {
	printf( "  %s: %d\n", $code, $count );
}
