<?php
/**
 * Acceso a un curso (6.33.2): un único criterio para la web, la API móvil y el catálogo.
 *
 * Tiene acceso quien tenga matrícula vigente en el curso o una inscripción
 * vigente (no caducada) en un programa que lo contenga. Antes la lista del
 * programa mostraba sus cursos, pero el acceso solo miraba la matrícula al
 * curso: el estudiante inscrito solo en el programa recibía "no disponible".
 *
 * La pertenencia al programa se lee en cada consulta (meta `_clms_program_courses`):
 * un curso agregado a un programa queda accesible de inmediato para sus inscritos.
 *
 * `CLMS_Helper::user_is_enrolled_in_course()` sigue significando matrícula
 * directa (comercio y gestión de matrículas la usan para no duplicar).
 *
 * @package ATORA_LMS
 * @since 6.33.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Course_Access_Service {

	/**
	 * ¿Puede entrar el usuario al curso? `$course` es el id del curso de WordPress (lm_course).
	 */
	public static function can_access( int $user_id, int $course ): bool {
		if ( $user_id <= 0 || $course <= 0 || 'lm_course' !== get_post_type( $course ) ) {
			return false;
		}
		// Sin caché: un curso recién agregado a un programa da acceso de inmediato.
		return ( class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_is_enrolled_in_course( $user_id, $course ) )
			|| self::via_program( $user_id, $course ) > 0;
	}

	/** Igual, con el id del curso en las tablas (`atora_courses.id`, el que usa la API móvil). */
	public static function can_access_course_id( int $user_id, int $course_id ): bool {
		$wp_course = self::wp_course( $course_id );
		return $wp_course > 0 && self::can_access( $user_id, $wp_course );
	}

	/**
	 * Programa vigente del usuario que contiene el curso (id de WordPress), o 0.
	 */
	public static function via_program( int $user_id, int $course ): int {
		if ( $user_id <= 0 || $course <= 0 || ! class_exists( 'CLMS_Helper' ) ) {
			return 0;
		}
		foreach ( self::active_programs( $user_id ) as $program ) {
			if ( in_array( $course, array_map( 'absint', (array) CLMS_Helper::get_program_courses( $program ) ), true ) ) {
				return $program;
			}
		}
		return 0;
	}

	/**
	 * Cursos (WordPress) a los que el usuario entra solo por sus programas vigentes.
	 *
	 * @return array<int,int> curso => programa
	 */
	public static function program_courses( int $user_id ): array {
		$out = array();
		if ( $user_id <= 0 || ! class_exists( 'CLMS_Helper' ) ) {
			return $out;
		}
		foreach ( self::active_programs( $user_id ) as $program ) {
			foreach ( (array) CLMS_Helper::get_program_courses( $program ) as $course ) {
				$course = absint( $course );
				if ( $course > 0 && ! isset( $out[ $course ] ) && 'publish' === get_post_status( $course ) ) {
					$out[ $course ] = $program;
				}
			}
		}
		return $out;
	}

	/**
	 * Programas con inscripción vigente (no caducada, programa no enviado a la papelera).
	 *
	 * @return array<int,int>
	 */
	public static function active_programs( int $user_id ): array {
		if ( $user_id <= 0 || ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}
		$ids = array();
		foreach ( (array) CLMS_Helper::get_user_enrolled_programs( $user_id ) as $program ) {
			$program = absint( $program );
			if ( $program <= 0 || 'lm_program' !== get_post_type( $program ) || 'trash' === get_post_status( $program ) ) {
				continue;
			}
			if ( method_exists( 'CLMS_Helper', 'has_user_program_access_expired' ) && CLMS_Helper::has_user_program_access_expired( $user_id, $program ) ) {
				continue;
			}
			$ids[] = $program;
		}
		return array_values( array_unique( $ids ) );
	}

	public static function boot(): void {
		// Curso agregado a un programa: sus inscritos quedan también matriculados (como al inscribirse).
		add_action( 'added_post_meta', array( __CLASS__, 'on_program_courses_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_program_courses_changed' ), 10, 3 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'atora reconcile-program-access', array( __CLASS__, 'cli' ) );
		}
	}

	/** @param int $meta_id @param int $post_id @param string $meta_key */
	public static function on_program_courses_changed( $meta_id, $post_id, $meta_key ): void {
		if ( ! class_exists( 'CLMS_Helper' ) || CLMS_Helper::PROGRAM_COURSES_META !== $meta_key || 'lm_program' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		self::reconcile( (int) $post_id, true );
	}

	/**
	 * Inscritos de un programa (meta del programa, meta del usuario y tabla).
	 *
	 * @return array<int,int>
	 */
	public static function program_members( int $program ): array {
		global $wpdb;
		$ids = array_map( 'absint', (array) get_post_meta( $program, CLMS_Helper::PROGRAM_ENROLLED_USERS_META, true ) );
		$ids = array_merge( $ids, array_map( 'absint', (array) get_users( array(
			'fields'     => 'ID',
			'meta_key'   => CLMS_Helper::USER_ENROLLED_PROGRAMS_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => '"' . $program . '"', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_compare' => 'LIKE',
		) ) ) );
		$ids = array_merge( $ids, array_map( 'absint', (array) get_users( array(
			'fields'       => 'ID',
			'meta_key'     => CLMS_Helper::USER_ENROLLED_PROGRAMS_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'   => 'i:' . $program . ';', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_compare' => 'LIKE',
		) ) ) );
		$table = $wpdb->prefix . 'atora_program_enrollments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$programs = $wpdb->prefix . 'atora_programs';
			$ids = array_merge( $ids, array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT pe.user_id FROM {$table} pe LEFT JOIN {$programs} p ON p.id = pe.program_id
				 WHERE ( pe.wp_program_id = %d OR p.wp_post_id = %d ) AND pe.status IN ('active', 'completed')",
				$program,
				$program
			) ) ) );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Inscritos vigentes del programa (o de todos) que no tienen la matrícula a
	 * alguno de sus cursos publicados. Con `$apply`, los matricula (como al
	 * inscribirse en el programa) y copia la caducidad del programa al curso.
	 *
	 * @return array<int,array{user_id:int,program_id:int,course_id:int}>
	 */
	public static function reconcile( int $program = 0, bool $apply = false ): array {
		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return array();
		}
		$programs = $program > 0 ? array( $program ) : array_map( 'absint', (array) get_posts( array(
			'post_type'   => 'lm_program',
			'post_status' => array( 'publish', 'private', 'draft' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		) ) );
		$out = array();
		foreach ( $programs as $program_id ) {
			$courses = array_filter( array_map( 'absint', (array) CLMS_Helper::get_program_courses( $program_id ) ), static fn( $c ) => 'publish' === get_post_status( $c ) );
			if ( ! $courses ) {
				continue;
			}
			foreach ( self::program_members( $program_id ) as $user_id ) {
				if ( ! in_array( $program_id, self::active_programs( $user_id ), true ) ) {
					continue;
				}
				foreach ( $courses as $course_id ) {
					if ( CLMS_Helper::user_is_enrolled_in_course( $user_id, $course_id ) ) {
						continue;
					}
					$out[] = array( 'user_id' => $user_id, 'program_id' => $program_id, 'course_id' => $course_id );
					if ( $apply && CLMS_Helper::enroll_user_in_course( $user_id, $course_id ) ) {
						$expires = (string) CLMS_Helper::get_user_program_access_expiration( $user_id, $program_id );
						if ( '' !== $expires ) {
							CLMS_Helper::set_user_course_access_expiration( $user_id, $course_id, $expires );
						}
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Estudiantes inscritos en un programa sin matrícula a alguno de sus cursos.
	 *
	 * Sin `--yes` solo informa (no cambia nada). Con `--yes`, los matricula.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Aplicar la corrección.
	 *
	 * [--program=<id>]
	 * : Solo este programa (id de WordPress).
	 *
	 * [--format=<format>]
	 * : table (por defecto), csv o json.
	 *
	 * @param array $args       Posicionales.
	 * @param array $assoc_args Opciones.
	 */
	public static function cli( $args, $assoc_args ): void {
		$apply = ! empty( $assoc_args['yes'] );
		$rows  = self::reconcile( absint( $assoc_args['program'] ?? 0 ), $apply );
		$users = count( array_unique( wp_list_pluck( $rows, 'user_id' ) ) );
		$items = array_map( static function ( $r ) {
			$user = get_userdata( $r['user_id'] );
			return array(
				'user_id'  => $r['user_id'],
				'login'    => $user ? $user->user_login : '',
				'programa' => $r['program_id'] . ' · ' . get_the_title( $r['program_id'] ),
				'curso'    => $r['course_id'] . ' · ' . get_the_title( $r['course_id'] ),
			);
		}, $rows );
		if ( $items ) {
			\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $items, array( 'user_id', 'login', 'programa', 'curso' ) );
		}
		\WP_CLI::success( sprintf(
			$apply ? 'Matriculados: %1$d estudiantes en %2$d cursos.' : 'Afectados: %1$d estudiantes, %2$d matrículas faltantes (solo lectura; --yes para corregir).',
			$users,
			count( $rows )
		) );
	}

	private static function wp_course( int $course_id ): int {
		if ( $course_id <= 0 || ! class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			return 0;
		}
		$row = \ATORA\LMS\LMS_Course_Service::get( $course_id );
		return absint( is_array( $row ) ? ( $row['wp_post_id'] ?? 0 ) : 0 );
	}
}
