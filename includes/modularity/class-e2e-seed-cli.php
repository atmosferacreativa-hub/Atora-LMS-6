<?php
/**
 * WP-CLI: `wp atora seed-e2e` (6.30.2)
 *
 * Datos fijos para las pruebas de pantalla de la app (Maestro), en un WordPress
 * temporal dentro del CI. Nunca en un sitio con estudiantes reales: se niega si
 * no se pasa `--yes` y la constante `ATORA_E2E` no está definida.
 *
 * Crea (o reutiliza, si ya existen) y escribe en JSON los ids y credenciales:
 * - un curso con una sección; el docente asignado a la sección, el estudiante matriculado;
 * - lección 1 "Videos" con 3 MP4 locales (copiados desde --video-dir a la biblioteca);
 * - lección 2 "Tarea" (actividad `tarea`, con fecha límite) y una entrega del estudiante sin calificar;
 * - lección 3 "Quiz" con 3 preguntas;
 * - un mensaje del docente al estudiante.
 *
 *   wp atora seed-e2e --yes --video-dir=/tmp/e2e-media [--password=...]
 *
 * @package ATORA_LMS
 * @since 6.30.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

final class ATORA_E2E_Seed_CLI {

	const COURSE_SLUG = 'curso-e2e';

	public static function init(): void {
		\WP_CLI::add_command( 'atora seed-e2e', array( __CLASS__, 'command' ) );
	}

	/**
	 * Siembra los datos de las pruebas de pantalla.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirma que el sitio es temporal (o define ATORA_E2E en wp-config).
	 *
	 * [--video-dir=<dir>]
	 * : Carpeta con video-1.mp4, video-2.mp4 y video-3.mp4.
	 *
	 * [--password=<password>]
	 * : Contraseña de las cuentas de prueba. Por defecto: atora-e2e-2026.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public static function command( array $args, array $assoc_args ): void {
		if ( empty( $assoc_args['yes'] ) && ! ( defined( 'ATORA_E2E' ) && ATORA_E2E ) ) {
			\WP_CLI::error( 'Solo para un WordPress temporal de pruebas: usa --yes o define ATORA_E2E.' );
			return;
		}
		$out = self::seed(
			(string) ( $assoc_args['video-dir'] ?? '' ),
			(string) ( $assoc_args['password'] ?? 'atora-e2e-2026' )
		);
		\WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** @return array<string,mixed> */
	public static function seed( string $video_dir, string $password ): array {
		$teacher = self::user( 'docente_e2e', 'Docente E2E', 'lms_instructor', $password );
		$student = self::user( 'estudiante_e2e', 'Estudiante E2E', get_role( 'student' ) ? 'student' : 'subscriber', $password );

		$existing = get_page_by_path( self::COURSE_SLUG, OBJECT, 'lm_course' );
		$course   = $existing ? (int) $existing->ID : (int) wp_insert_post( array(
			'post_type'    => 'lm_course',
			'post_status'  => 'publish',
			'post_name'    => self::COURSE_SLUG,
			'post_title'   => 'Curso E2E',
			'post_author'  => $teacher,
			'post_content' => 'Curso para las pruebas de pantalla.',
		) );

		$videos = self::lesson( $course, $teacher, 'leccion-e2e-videos', 'Videos', 1, array(
			'_clms_lesson_extra_videos' => self::videos( $video_dir, $teacher ),
		) );
		$task = self::lesson( $course, $teacher, 'leccion-e2e-tarea', 'Tarea', 2, array(
			'lm_activity_type' => 'tarea',
			'_clms_due_date'   => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
			'_clms_due_time'   => '23:59',
		) );
		$quiz = self::lesson( $course, $teacher, 'leccion-e2e-quiz', 'Quiz', 3, array(
			'lm_activity_type'      => 'quiz',
			'_clms_quiz_enabled'    => 'yes',
			'_lm_quiz_has_eval'     => 'yes',
			'_clms_quiz_questions'  => array(
				array( 'type' => 'single', 'question' => '¿Cuánto es 2 + 2?', 'options' => array( '3', '4', '5' ), 'correct' => '4' ),
				array( 'type' => 'single', 'question' => '¿Capital de Venezuela?', 'options' => array( 'Caracas', 'Maracaibo', 'Valencia' ), 'correct' => 'Caracas' ),
				array( 'type' => 'true_false', 'question' => 'El agua hierve a 100 °C al nivel del mar.', 'options' => array( 'Verdadero', 'Falso' ), 'correct' => 'Verdadero' ),
			),
		) );

		if ( method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			CLMS_Helper::enroll_user_in_course( $student, $course );
		}
		$table_course = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ? \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $course ) : null;
		if ( $table_course && class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
			\ATORA\LMS\LMS_Enrollment_Service::enroll( $student, (int) $table_course['id'] );
		}

		$section = self::section( $course, $teacher, $student );
		$submission = self::submission( $student, $task, $course );
		$message = self::message( $teacher, $student, $course );

		return array(
			'password'   => $password,
			'teacher'    => array( 'id' => $teacher, 'login' => 'docente_e2e' ),
			'student'    => array( 'id' => $student, 'login' => 'estudiante_e2e' ),
			'course'     => array( 'wp_id' => $course, 'id' => $table_course ? (int) $table_course['id'] : 0, 'section_id' => $section ),
			'lessons'    => array( 'videos' => $videos, 'task' => $task, 'quiz' => $quiz ),
			'submission' => $submission,
			'message'    => $message,
		);
	}

	private static function user( string $login, string $name, string $role, string $password ): int {
		$user = get_user_by( 'login', $login );
		$id   = $user ? (int) $user->ID : (int) wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $login . '@e2e.test',
			'display_name' => $name,
			'user_pass'    => $password,
			'role'         => $role,
		) );
		wp_set_password( $password, $id );
		return $id;
	}

	private static function lesson( int $course, int $author, string $slug, string $title, int $order, array $meta ): int {
		$existing = get_page_by_path( $slug, OBJECT, 'lm_lesson' );
		$id       = $existing ? (int) $existing->ID : (int) wp_insert_post( array(
			'post_type'    => 'lm_lesson',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_author'  => $author,
			'menu_order'   => $order,
			'post_content' => 'Contenido de la lección ' . $title . '.',
			'meta_input'   => array( '_clms_course_id' => $course ),
		) );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}
		// Re-guardar para que la sincronización del editor lleve la meta a las tablas.
		wp_update_post( array( 'ID' => $id, 'menu_order' => $order ) );
		return $id;
	}

	/** Copia los MP4 a la biblioteca (una vez) y devuelve la lista del editor. */
	private static function videos( string $dir, int $author ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$list = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$name     = 'video-e2e-' . $i;
			$existing = get_page_by_path( $name, OBJECT, 'attachment' );
			$id       = $existing ? (int) $existing->ID : 0;
			$file     = rtrim( $dir, '/' ) . '/video-' . $i . '.mp4';
			if ( ! $id && '' !== $dir && is_readable( $file ) ) {
				$tmp = wp_tempnam( $file );
				copy( $file, $tmp );
				$id = (int) media_handle_sideload( array( 'name' => $name . '.mp4', 'tmp_name' => $tmp ), 0, 'Video E2E ' . $i, array( 'post_name' => $name, 'post_author' => $author ) );
			}
			if ( $id > 0 && ! is_wp_error( $id ) ) {
				$list[] = array( 'source' => 'upload', 'url' => wp_get_attachment_url( $id ), 'title' => 'Video ' . $i, 'attachment_id' => $id );
			}
		}
		return $list;
	}

	private static function section( int $course, int $teacher, int $student ): int {
		$class = '\\ATORA\\LMS\\Section_Service';
		if ( ! class_exists( $class ) ) {
			return 0;
		}
		$sections = (array) $class::get_sections_by_course( $course );
		$id       = ! empty( $sections[0]['id'] ) ? (int) $sections[0]['id'] : (int) $class::create( array( 'wp_course_id' => $course, 'title' => 'Sección E2E' ) );
		if ( $id > 0 ) {
			$class::add_teacher( $id, $teacher );
			$class::add_student( $id, $student );
		}
		return $id;
	}

	private static function submission( int $student, int $lesson, int $course ): int {
		$found = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => '_clms_submission_user_id', 'value' => $student, 'type' => 'NUMERIC' ),
				array( 'key' => '_clms_submission_lesson_id', 'value' => $lesson, 'type' => 'NUMERIC' ),
			),
		) );
		if ( $found ) {
			return (int) $found[0];
		}
		$id = (int) wp_insert_post( array(
			'post_type'   => 'clms_submission',
			'post_status' => 'publish',
			'post_author' => $student,
			'post_title'  => 'Entrega E2E',
		) );
		update_post_meta( $id, '_clms_submission_user_id', $student );
		update_post_meta( $id, '_clms_submission_lesson_id', $lesson );
		update_post_meta( $id, '_clms_submission_course_id', $course );
		update_post_meta( $id, '_clms_submission_comment', 'Mi entrega de prueba.' );
		update_post_meta( $id, '_clms_submission_status', 'submitted' );
		update_post_meta( $id, '_clms_submission_submitted_at', current_time( 'mysql' ) );
		return $id;
	}

	private static function message( int $teacher, int $student, int $course ): int {
		if ( ! class_exists( 'ATORA_Inbox_Store' ) || ! ATORA_Inbox_Store::tables_ready() ) {
			return 0;
		}
		$thread = ATORA_Inbox_Store::direct_thread_id( $teacher, $student, $course, get_the_title( $course ), array( $teacher => 'teacher', $student => 'student' ) );
		if ( is_wp_error( $thread ) ) {
			return 0;
		}
		$result = ATORA_Inbox_Store::add_message( (int) $thread, $teacher, array(
			'kind'            => 'message',
			'body'            => 'Hola, revisa la tarea de esta semana.',
			'course_id'       => $course,
			'client_event_id' => 'seed-e2e-message-1',
			'meta'            => array( 'sender_type' => 'teacher', 'message_type' => 'manual', 'source' => 'seed-e2e' ),
		) );
		return is_wp_error( $result ) ? 0 : (int) $result['message']['id'];
	}
}
