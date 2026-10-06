<?php
/**
 * Diagnóstico de solo lectura (6.31.0): quién perdería acceso a qué cursos con
 * la regla nueva de `ATORA_Teacher_Scope` (SpeedGrader web y `/teacher/*`).
 *
 * Uso (con 6.31.0 instalado; también sirve en un clon antes de actualizar):
 *   wp eval-file wp-content/plugins/atora-lms/scripts/audit-teacher-scope-access.php
 *
 * Regla anterior: administrador (todo) o `CLMS_Helper::user_can_manage_lms()`
 * sobre el curso o alguna de sus lecciones.
 * Regla nueva: sección asignada, autoría, editar lo ajeno, delegación o la regla
 * anterior; con varias instituciones activas, solo los cursos de la propia.
 *
 * No modifica nada: cambia el usuario en sesión solo para evaluar permisos y lo
 * restaura al terminar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "Ejecutar con: wp eval-file scripts/audit-teacher-scope-access.php\n" );
}

if ( ! class_exists( 'ATORA_Teacher_Scope' ) || ! class_exists( 'CLMS_Helper' ) ) {
	echo "Requiere ATORA LMS 6.31.0 o superior.\n";
	return;
}

$previous_user = get_current_user_id();
$courses       = get_posts( array( 'post_type' => 'lm_course', 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
$users         = get_users( array( 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
$lost          = array();
$checked       = 0;

$old_rule = static function ( int $user_id, int $course_id ): bool {
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	if ( CLMS_Helper::user_can_manage_lms( $course_id ) ) {
		return true;
	}
	foreach ( (array) CLMS_Helper::get_course_lessons( $course_id ) as $lesson_id ) {
		if ( CLMS_Helper::user_can_manage_lms( (int) $lesson_id ) ) {
			return true;
		}
	}
	return false;
};

$new_rule = static function ( int $user_id, int $course_id ): bool {
	if ( ATORA_Teacher_Scope::can_access_course( $user_id, $course_id ) ) {
		return true;
	}
	foreach ( (array) CLMS_Helper::get_course_lessons( $course_id ) as $lesson_id ) {
		if ( (int) get_post_field( 'post_author', (int) $lesson_id ) === $user_id && ATORA_Teacher_Scope::in_tenant( $user_id, $course_id ) ) {
			return true;
		}
	}
	return false;
};

foreach ( $users as $user ) {
	$user_id = (int) $user->ID;
	wp_set_current_user( $user_id );
	ATORA_Teacher_Scope::reset_cache();
	foreach ( $courses as $course_id ) {
		++$checked;
		if ( $old_rule( $user_id, (int) $course_id ) && ! $new_rule( $user_id, (int) $course_id ) ) {
			$lost[] = array( $user, (int) $course_id );
		}
	}
}
wp_set_current_user( $previous_user );

$version = defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '?';
printf( "Acceso docente con la regla nueva — plugin %s — %s\n", $version, current_time( 'mysql' ) );
printf( "Varias instituciones activas: %s\n", ATORA_Teacher_Scope::multi_institution() ? 'sí' : 'no' );
printf( "Usuarios × cursos revisados: %d\n\n", $checked );
printf( "Pierden acceso: %d\n", count( $lost ) );
foreach ( $lost as list( $user, $course_id ) ) {
	printf( "  - %s <%s> (#%d) — curso #%d %s (institución #%d)\n", $user->display_name, $user->user_email, $user->ID, $course_id, get_the_title( $course_id ), ATORA_Teacher_Scope::course_institution( $course_id ) );
}
echo "\nSolo lectura: no se cambió nada.\n";
