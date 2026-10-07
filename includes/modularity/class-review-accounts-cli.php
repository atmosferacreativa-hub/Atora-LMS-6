<?php
/**
 * WP-CLI: `wp atora review-accounts` (6.33.0).
 *
 * Crea (o actualiza) las cuentas que usan los revisores de Google Play y la
 * App Store, sobre cursos reales de la academia (el demo):
 * - `revisor-estudiante` (estudiante), matriculado en los cursos indicados;
 * - `revisor-docente` (docente), asignado a una sección de esos cursos, con el
 *   estudiante en la misma sección para que vea entregas y estudiantes.
 *
 *   wp atora review-accounts --yes --password=<contraseña> [--courses=12,34]
 *
 * Sin `--courses`, usa los tres primeros cursos publicados. Ver
 * `docs/TIENDAS-REVISION.md` en el repositorio de la app.
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

final class ATORA_Review_Accounts_CLI {

	const STUDENT = 'revisor-estudiante';
	const TEACHER = 'revisor-docente';

	public static function init(): void {
		\WP_CLI::add_command( 'atora review-accounts', array( __CLASS__, 'command' ) );
	}

	/**
	 * Crea las cuentas de revisión de las tiendas.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirma que se crearán (o actualizarán) dos cuentas de prueba.
	 *
	 * --password=<password>
	 * : Contraseña de las dos cuentas (al menos 12 caracteres).
	 *
	 * [--courses=<ids>]
	 * : Ids de cursos (WordPress) separados por coma. Por defecto, los tres primeros publicados.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function command( array $args, array $assoc_args ): void {
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::error( 'Confirma con --yes: se crearán o actualizarán las cuentas revisor-estudiante y revisor-docente.' );
			return;
		}
		$password = (string) ( $assoc_args['password'] ?? '' );
		if ( strlen( $password ) < 12 ) {
			\WP_CLI::error( 'La contraseña debe tener al menos 12 caracteres.' );
			return;
		}
		$courses = isset( $assoc_args['courses'] )
			? array_values( array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['courses'] ) ) ) )
			: array_map( 'absint', get_posts( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'numberposts' => 3, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'ASC' ) ) );
		if ( ! $courses ) {
			\WP_CLI::error( 'No hay cursos publicados.' );
			return;
		}
		$out = self::create( $password, $courses );
		\WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		\WP_CLI::success( 'Cuentas de revisión listas.' );
	}

	/** @return array<string,mixed> */
	public static function create( string $password, array $courses ): array {
		$domain  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com';
		$role    = get_role( 'student' ) ? 'student' : 'subscriber';
		$student = self::user( self::STUDENT, 'Revisor Estudiante', $role, $password, self::STUDENT . '@' . $domain );
		$teacher = self::user( self::TEACHER, 'Revisor Docente', get_role( 'lms_instructor' ) ? 'lms_instructor' : 'editor', $password, self::TEACHER . '@' . $domain );
		$done    = array();
		foreach ( $courses as $course_id ) {
			if ( 'lm_course' !== get_post_type( $course_id ) ) {
				continue;
			}
			if ( method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
				CLMS_Helper::enroll_user_in_course( $student, $course_id );
			}
			$table = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ? \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $course_id ) : null;
			if ( $table && class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
				\ATORA\LMS\LMS_Enrollment_Service::enroll( $student, (int) $table['id'] );
			}
			$section = 0;
			if ( class_exists( '\\ATORA\\LMS\\Section_Service' ) ) {
				// Repetir el comando reutiliza la sección (no crea otra).
				foreach ( (array) \ATORA\LMS\Section_Service::get_sections_by_course( $course_id ) as $existing ) {
					if ( 'Revisión de tiendas' === (string) ( $existing['title'] ?? '' ) ) {
						$section = (int) $existing['id'];
					}
				}
				if ( ! $section ) {
					$section = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $course_id, 'title' => 'Revisión de tiendas' ) );
				}
				if ( $section ) {
					\ATORA\LMS\Section_Service::add_teacher( $section, $teacher );
					\ATORA\LMS\Section_Service::add_student( $section, $student );
				}
			}
			$done[] = array( 'course_id' => $course_id, 'title' => get_the_title( $course_id ), 'section_id' => $section );
		}
		return array(
			'academy_url' => home_url( '/' ),
			'student'     => array( 'login' => self::STUDENT, 'id' => $student ),
			'teacher'     => array( 'login' => self::TEACHER, 'id' => $teacher ),
			'courses'     => $done,
		);
	}

	private static function user( string $login, string $name, string $role, string $password, string $email ): int {
		$user = get_user_by( 'login', $login );
		$id   = $user ? (int) $user->ID : (int) wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'display_name' => $name,
			'user_pass'    => $password,
			'role'         => $role,
		) );
		wp_set_password( $password, $id );
		( new WP_User( $id ) )->set_role( $role );
		return $id;
	}
}
