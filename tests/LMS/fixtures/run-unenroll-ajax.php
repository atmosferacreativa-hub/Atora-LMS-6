<?php
/**
 * Runner aislado para ajax_unenroll_user() — verificación de dueño
 * del curso — PT-9 (sprint 6.5.5).
 *
 * Termina en exit() (wp_send_json_*) — se ejecuta en un proceso PHP
 * aparte (ver EnrollmentAjaxOwnershipTest, que lo lanza con
 * proc_open()) y vuelca el estado final de usermeta a un archivo para
 * que el proceso PHPUnit padre lo revise después.
 *
 * Solo cubre el camino de cursos (ajax_unenroll_user) — el de
 * programas (ajax_unenroll_user_program) usa las mismas dos líneas de
 * chequeo de dueño, pero depende de constantes de CLMS_Helper
 * (USER_ENROLLED_PROGRAMS_META), una clase pesada fuera del alcance
 * de este arnés de test aislado.
 *
 * Variables de entorno: ATORA_TEST_OUT, ATORA_TEST_CURRENT_USER,
 * ATORA_TEST_OWNER_ID, ATORA_TEST_COURSE_ID, ATORA_TEST_USER_ID,
 * ATORA_TEST_EXTRA_CAP (capability adicional a otorgar, opcional).
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../../includes/metabox-course/trait-metabox-course-enrollment-ajax.php';

class Test_Course_Enrollment_Ajax_Host {
	use CLMS_Metabox_Course_Enrollment_Ajax_Trait;
}

$out_path  = (string) getenv( 'ATORA_TEST_OUT' );
$course_id = (int) getenv( 'ATORA_TEST_COURSE_ID' );
$owner_id  = (int) getenv( 'ATORA_TEST_OWNER_ID' );
$current   = (int) getenv( 'ATORA_TEST_CURRENT_USER' );
$user_id   = (int) getenv( 'ATORA_TEST_USER_ID' );
$extra_cap = (string) getenv( 'ATORA_TEST_EXTRA_CAP' );

$GLOBALS['__atora_test_current_user_id'] = $current;
atora_test_set_user_cap( $current, 'clms_manage_courses' );
if ( '' !== $extra_cap ) {
	atora_test_set_user_cap( $current, $extra_cap );
}

atora_test_set_post( $course_id, array( 'post_type' => 'lm_course', 'post_author' => $owner_id ) );
atora_test_set_post_type( $course_id, 'lm_course' );

$nonce = wp_create_nonce( 'clms_enrollment_nonce' );

$_POST = array(
	'nonce'     => $nonce,
	'course_id' => (string) $course_id,
	'user_id'   => (string) $user_id,
);

register_shutdown_function( static function () use ( $out_path, $user_id ) {
	$meta = $GLOBALS['__atora_test_user_meta'][ $user_id ] ?? array();
	file_put_contents( $out_path, wp_json_encode( array( 'meta_written' => array_key_exists( '_clms_enrolled_courses', $meta ) ) ) );
} );

( new Test_Course_Enrollment_Ajax_Host() )->ajax_unenroll_user();
