<?php
/**
 * Informe de SOLO LECTURA (6.33.2, orden 1.0.1 punto 1): estudiantes inscritos
 * en un programa vigente sin matrícula a alguno de sus cursos publicados — los
 * que antes de 6.33.2 veían el curso en la lista y recibían "no disponible".
 *
 * No cambia nada. Funciona también en versiones anteriores (6.31+), sin el
 * servicio nuevo. Para corregir, con 6.33.2: `wp atora reconcile-program-access --yes`.
 *
 * Uso: wp eval-file wp-content/plugins/atora-lms/scripts/program-access-report.php
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'CLMS_Helper' ) ) {
	echo "Ejecutar con: wp eval-file <ruta>/program-access-report.php\n";
	return;
}

global $wpdb;
$rows     = array();
$programs = get_posts( array( 'post_type' => 'lm_program', 'post_status' => array( 'publish', 'private', 'draft' ), 'numberposts' => -1, 'fields' => 'ids' ) );

foreach ( $programs as $program_id ) {
	$courses = array_filter( array_map( 'absint', (array) CLMS_Helper::get_program_courses( $program_id ) ), static fn( $c ) => 'publish' === get_post_status( $c ) );
	if ( ! $courses ) {
		continue;
	}
	$members = array_map( 'absint', (array) get_post_meta( $program_id, CLMS_Helper::PROGRAM_ENROLLED_USERS_META, true ) );
	$table   = $wpdb->prefix . 'atora_program_enrollments';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$members = array_merge( $members, array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT pe.user_id FROM {$table} pe LEFT JOIN {$wpdb->prefix}atora_programs p ON p.id = pe.program_id
			 WHERE ( pe.wp_program_id = %d OR p.wp_post_id = %d ) AND pe.status IN ('active', 'completed')",
			$program_id,
			$program_id
		) ) ) );
	}
	foreach ( array_unique( array_filter( $members ) ) as $user_id ) {
		if ( ! in_array( (int) $program_id, array_map( 'absint', (array) CLMS_Helper::get_user_enrolled_programs( $user_id ) ), true )
			|| CLMS_Helper::has_user_program_access_expired( $user_id, $program_id ) ) {
			continue;
		}
		foreach ( $courses as $course_id ) {
			if ( ! CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
				$user   = get_userdata( $user_id );
				$rows[] = array( $user_id, $user ? $user->user_login : '', $program_id . ' ' . get_the_title( $program_id ), $course_id . ' ' . get_the_title( $course_id ) );
			}
		}
	}
}

foreach ( $rows as $row ) {
	echo implode( "\t", $row ), "\n";
}
printf( "Estudiantes afectados: %d · matrículas faltantes: %d (solo lectura, no se cambió nada)\n", count( array_unique( array_column( $rows, 0 ) ) ), count( $rows ) );
