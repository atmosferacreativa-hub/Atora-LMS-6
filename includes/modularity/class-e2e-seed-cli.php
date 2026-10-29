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
 * 6.33.0: el curso es certificable (recorrido `certificado-pdf`, ver
 * `ATORA_E2E_Runtime`) y la plantilla del certificado lleva tres firmas.
 *
 * 6.31.0 (recorridos del docente):
 * - rúbrica de 2 criterios (Inicial 4 · Logrado 8 · Excelente 10) en "Tarea" y en "Ensayo";
 * - lección "Ensayo" con una entrega de "Estudiante Dos E2E" con un PDF;
 * - dos lecciones vencidas sin entregar → alerta de early-warning (riesgo);
 * - un segundo docente en la misma sección (conflicto 409);
 * - una tarea grupal ("Proyecto grupal", grupo con los dos estudiantes).
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
	 * [--perf]
	 * : 6.33.0: además, "Curso grande E2E" con 100 lecciones (medición en gama baja).
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
		if ( ! empty( $assoc_args['perf'] ) ) {
			$out['large_course'] = self::large_course( (int) $out['student']['id'], (int) $out['teacher']['id'] );
		}
		\WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * 6.39.0: clase en vivo abierta (recorrido `clase-en-vivo` de la app 1.2.0). Enlace externo cargado a mano;
	 * empieza 5 min después de sembrar y dura 4 h para que siga abierta durante toda la suite.
	 */
	public static function live_lesson( int $course, int $teacher ): int {
		$live_start = time() + 5 * MINUTE_IN_SECONDS;
		$live       = self::lesson( $course, $teacher, 'leccion-e2e-en-vivo', 'Clase en vivo', 11, array(
			'lm_session_type'            => 'sincrono',
			'_clms_live_class_provider'  => 'custom',
			'_clms_live_class_url'       => 'https://example.org/clase-en-vivo-e2e',
			'_clms_live_class_starts_at' => gmdate( 'Y-m-d\TH:i', $live_start ),
			'_clms_live_class_ends_at'   => gmdate( 'Y-m-d\TH:i', $live_start + 4 * HOUR_IN_SECONDS ),
			'_clms_live_class_timezone'  => 'UTC',
		) );
		if ( class_exists( 'ATORA_Live_Session_Service' ) ) {
			ATORA_Live_Session_Service::set_recording( $live, array( 'url' => 'https://example.org/grabacion-e2e', 'note' => 'Grabación de la clase anterior.' ), $teacher );
		}
		return $live;
	}

	/** 6.33.0: curso con 100 lecciones para medir listas largas en un teléfono de gama baja. */
	public static function large_course( int $student, int $teacher ): int {
		$existing = get_page_by_path( 'curso-e2e-grande', OBJECT, 'lm_course' );
		$course   = $existing ? (int) $existing->ID : (int) wp_insert_post( array(
			'post_type'    => 'lm_course',
			'post_status'  => 'publish',
			'post_name'    => 'curso-e2e-grande',
			'post_title'   => 'Curso grande E2E',
			'post_author'  => $teacher,
			'post_content' => 'Curso con 100 lecciones para medir el rendimiento.',
		) );
		for ( $n = 1; $n <= 100; $n++ ) {
			self::lesson( $course, $teacher, 'leccion-e2e-grande-' . $n, sprintf( 'Lección %03d', $n ), $n, array() );
		}
		if ( method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
			CLMS_Helper::enroll_user_in_course( $student, $course );
		}
		$table = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ? \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $course ) : null;
		if ( $table && class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
			\ATORA\LMS\LMS_Enrollment_Service::enroll( $student, (int) $table['id'] );
		}
		return $course;
	}

	/** @return array<string,mixed> */
	public static function seed( string $video_dir, string $password ): array {
		$admin_user = get_user_by( 'login', 'admin' );
		$admin_id   = $admin_user ? (int) $admin_user->ID : 0;

		$teacher  = self::user( 'docente_e2e', 'Docente E2E', 'lms_instructor', $password );
		$teacher2 = self::user( 'docente2_e2e', 'Docente Dos E2E', 'lms_instructor', $password );
		// 6.41.0: instructor asistente con delegación del docente sobre el curso (pre-calificación).
		$assistant = self::user( 'asistente_e2e', 'Asistente E2E', 'lms_instructor_assistant', $password );
		$role     = get_role( 'student' ) ? 'student' : 'subscriber';
		$student  = self::user( 'estudiante_e2e', 'Estudiante E2E', $role, $password );
		$student2 = self::user( 'estudiante2_e2e', 'Estudiante Dos E2E', $role, $password );
		if ( ! $admin_id ) {
			$admin_id = $teacher;
		}

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

		// 1.0.2 (app): materiales descargables para el recorrido `material-zoom`.
		update_post_meta( $videos, '_clms_lesson_resources', array(
			array(
				'type'        => 'file',
				'title'       => 'Guía PDF',
				'description' => 'PDF para pruebas de zoom (app).',
				'file_id'     => self::pdf( $teacher ),
			),
			array(
				'type'        => 'file',
				'title'       => 'Imagen de ejemplo',
				'description' => 'Imagen para pruebas de zoom (app).',
				'file_id'     => self::image( $teacher ),
			),
			// 6.37.0: audio de la lección (recorridos de audio de la app 1.2.0).
			array(
				'type'        => 'audio',
				'title'       => 'Audio de la clase',
				'description' => 'Audio MP3 de 3 segundos para pruebas.',
				'file_id'     => self::audio( $teacher ),
				'transcript'  => 'Transcripción de prueba del audio de la clase.',
			),
		) );

		// 6.35.0 (SCORM): lección con paquetes SCORM para recorridos `scorm-*` de la app.
		// Nota: estos recursos son obligatorios. Si no se pueden crear, el seed falla.
		$scorm = self::lesson( $course, $teacher, 'leccion-e2e-scorm', 'SCORM', 99, array() );
		$resources = array(
			self::scorm_resource( $admin_id ),
			self::scorm_xhr_resource( $admin_id ),
		);
		update_post_meta( $scorm, '_clms_lesson_resources', $resources );
		// Sin límite: el CI corre `scorm-online`, `scorm-sin-conexion` y `scorm-xhr` en la misma lección.
		update_post_meta( $scorm, '_clms_scorm_max_attempts', 0 );
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

		$rubric = self::rubric( $teacher );
		update_post_meta( $task, '_clms_rubric_id', $rubric );
		$essay = self::lesson( $course, $teacher, 'leccion-e2e-ensayo', 'Ensayo', 4, array(
			'lm_activity_type' => 'tarea',
			'_clms_rubric_id'  => $rubric,
			'_clms_due_date'   => gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ),
			'_clms_due_time'   => '23:59',
		) );
		$overdue = array();
		foreach ( array( 1 => 'Práctica vencida 1', 2 => 'Práctica vencida 2' ) as $n => $title ) {
			$overdue[] = self::lesson( $course, $teacher, 'leccion-e2e-vencida-' . $n, $title, 4 + $n, array(
				'lm_activity_type' => 'tarea',
				'_clms_due_date'   => gmdate( 'Y-m-d', time() - ( 2 + $n ) * DAY_IN_SECONDS ),
				'_clms_due_time'   => '10:00',
			) );
		}
		// 6.31.1: entrega propia para la prueba de concurrencia real (dos guardados a la vez).
		$concurrency = self::lesson( $course, $teacher, 'leccion-e2e-concurrencia', 'Concurrencia', 9, array(
			'lm_activity_type' => 'tarea',
			'_clms_rubric_id'  => $rubric,
		) );
		update_post_meta( $course, '_clms_course_groups_enabled', '1' );
		$group_task = self::lesson( $course, $teacher, 'leccion-e2e-grupal', 'Proyecto grupal', 8, array(
			'lm_activity_type'      => 'tarea',
			'_clms_evaluation_mode' => 'group',
		) );

		// 6.33.2 (orden 1.0.1, punto 4): video público real de Google Drive (recorrido leccion-drive).
		$drive = self::lesson( $course, $teacher, 'leccion-e2e-drive', 'Video de Drive', 10, array(
			'_clms_lesson_extra_videos' => array( array( 'source' => 'drive', 'url' => self::DRIVE_VIDEO, 'title' => 'Video de Drive' ) ),
		) );
		$live = self::live_lesson( $course, $teacher );

		// 6.33.2 (orden 1.0.1, punto 1): estudiante inscrito SOLO en un programa (recorrido programa-acceso).
		$program_access = self::program_only( $teacher, $password );

		$table_course = class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ? \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $course ) : null;
		foreach ( array( $student, $student2 ) as $enrolled ) {
			if ( method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
				CLMS_Helper::enroll_user_in_course( $enrolled, $course );
			}
			if ( $table_course && class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ) {
				\ATORA\LMS\LMS_Enrollment_Service::enroll( $enrolled, (int) $table_course['id'] );
			}
		}

		$section = self::section( $course, $teacher, $student );
		if ( $section && class_exists( '\\ATORA\\LMS\\Section_Service' ) ) {
			\ATORA\LMS\Section_Service::add_teacher( $section, $teacher2 );
			\ATORA\LMS\Section_Service::add_student( $section, $student2 );
		}
		self::delegate( $teacher, $assistant, $course );
		$submission  = self::submission( $student, $task, $course );
		$submission2 = self::submission( $student2, $essay, $course, self::pdf( $student2 ) );
		$submission3 = self::submission( $student2, $concurrency, $course );
		$message     = self::message( $teacher, $student, $course );
		$group       = self::group( $course, $teacher, array( $student, $student2 ) );
		// Alertas de entregas vencidas (aunque el módulo no esté activo: el riesgo lee su tabla).
		if ( ! class_exists( '\\ATORA\\EarlyWarning\\Early_Warning_Service' ) && defined( 'ATORA_LMS_DIR' ) && is_readable( ATORA_LMS_DIR . 'modules/early-warning/class-early-warning-service.php' ) ) {
			require_once ATORA_LMS_DIR . 'modules/early-warning/class-early-warning-service.php';
		}
		if ( class_exists( '\\ATORA\\EarlyWarning\\Early_Warning_Service' ) ) {
			( new \ATORA\EarlyWarning\Early_Warning_Service() )->scan_course( $course, false );
		}

		// 6.33.0: certificado institucional en PDF para el recorrido `certificado-pdf`.
		update_option( 'clms_certificates_enabled', '1' );
		update_post_meta( $course, '_atora_e2e_certificate', '1' );
		update_post_meta( $course, '_clms_course_certificate_enabled', '1' );
		if ( class_exists( 'ATORA_Certificate_Template' ) ) {
			ATORA_Certificate_Template::save( array(
				'show_hours' => 1,
				'show_date'  => 1,
				'signatures' => array(
					array( 'image_id' => 0, 'name' => 'Ana Ruiz', 'role' => 'Directora académica' ),
					array( 'image_id' => 0, 'name' => 'Luis Paz', 'role' => 'Coordinador' ),
					array( 'image_id' => 0, 'name' => 'Eva Sol', 'role' => 'Secretaria general' ),
				),
			) );
		}

		// 6.32.0: IA activada para las pruebas de pantalla. Responde el proveedor
		// simulado (constante ATORA_AI_FAKE en el wp-config del CI); sin proveedor
		// configurado, /discovery no declara nada aunque estén activadas.
		$modules = get_option( 'atora_active_modules', false );
		if ( is_array( $modules ) && ! in_array( 'ai', $modules, true ) ) {
			$modules[] = 'ai';
			update_option( 'atora_active_modules', $modules );
		}
		if ( class_exists( 'ATORA_AI_Usage_Service' ) ) {
			ATORA_AI_Usage_Service::set_features( array( ATORA_AI_Usage_Service::ASSISTANT => true, ATORA_AI_Usage_Service::SUGGESTION => true ) );
		}

		return array(
			'password'   => $password,
			'teacher'    => array( 'id' => $teacher, 'login' => 'docente_e2e' ),
			'teacher2'   => array( 'id' => $teacher2, 'login' => 'docente2_e2e' ),
			'assistant'  => array( 'id' => $assistant, 'login' => 'asistente_e2e' ),
			'student'    => array( 'id' => $student, 'login' => 'estudiante_e2e' ),
			'student2'   => array( 'id' => $student2, 'login' => 'estudiante2_e2e' ),
			'course'     => array( 'wp_id' => $course, 'id' => $table_course ? (int) $table_course['id'] : 0, 'section_id' => $section ),
			'lessons'    => array( 'videos' => $videos, 'scorm' => $scorm, 'task' => $task, 'quiz' => $quiz, 'essay' => $essay, 'overdue' => $overdue, 'group' => $group_task ),
			'scorm_resources' => array(
				array(
					'title'              => (string) ( $resources[0]['title'] ?? '' ),
					'library_item_id'    => (int) ( $resources[0]['library_item_id'] ?? 0 ),
					'library_version_id' => (int) ( $resources[0]['library_version_id'] ?? 0 ),
				),
				array(
					'title'              => (string) ( $resources[1]['title'] ?? '' ),
					'library_item_id'    => (int) ( $resources[1]['library_item_id'] ?? 0 ),
					'library_version_id' => (int) ( $resources[1]['library_version_id'] ?? 0 ),
				),
			),
			'rubric'     => $rubric,
			'group'      => $group,
			'submission' => $submission,
			'submission2' => $submission2,
			'submission_concurrency' => $submission3,
			'message'    => $message,
			'drive_lesson' => $drive,
			'live_lesson'  => $live,
			'program_access' => $program_access,
		);
	}

	/** Video público de Drive del titular ("cualquiera con el enlace"), liviano (3,7 MB). */
	const DRIVE_VIDEO = 'https://drive.google.com/file/d/1iMeTyedNEB_hv2iF4GavRGfmROAVtPUq/view';

	/**
	 * Programa con un curso propio y `estudiante3_e2e` inscrito solo en el programa,
	 * sin matrícula al curso (como queda tras una compra o migración, o un curso
	 * agregado después sin pasar por la pantalla del programa).
	 *
	 * @return array<string,int>
	 */
	private static function program_only( int $teacher, string $password ): array {
		$role    = get_role( 'student' ) ? 'student' : 'subscriber';
		$student = self::user( 'estudiante3_e2e', 'Estudiante Programa E2E', $role, $password );
		$existing = get_page_by_path( 'curso-e2e-programa', OBJECT, 'lm_course' );
		$course   = $existing ? (int) $existing->ID : (int) wp_insert_post( array(
			'post_type'    => 'lm_course',
			'post_status'  => 'publish',
			'post_name'    => 'curso-e2e-programa',
			'post_title'   => 'Curso del programa E2E',
			'post_author'  => $teacher,
			'post_content' => 'Curso al que se entra solo por el programa.',
		) );
		$lesson   = self::lesson( $course, $teacher, 'leccion-e2e-programa', 'Bienvenida al programa', 1, array() );
		$existing = get_page_by_path( 'programa-e2e', OBJECT, 'lm_program' );
		$program  = $existing ? (int) $existing->ID : (int) wp_insert_post( array(
			'post_type'   => 'lm_program',
			'post_status' => 'publish',
			'post_name'   => 'programa-e2e',
			'post_title'  => 'Diplomado E2E',
			'post_author' => $teacher,
		) );
		if ( method_exists( 'CLMS_Helper', 'enroll_user_in_program' ) && ! CLMS_Helper::user_is_enrolled_in_course( $student, $course ) ) {
			// Inscripción al programa cuando aún no tenía cursos; el curso se agrega sin el gancho que matricula.
			delete_post_meta( $program, CLMS_Helper::PROGRAM_COURSES_META );
			CLMS_Helper::enroll_user_in_program( $student, $program );
			remove_action( 'added_post_meta', array( 'ATORA_Course_Access_Service', 'on_program_courses_changed' ), 10 );
			remove_action( 'updated_post_meta', array( 'ATORA_Course_Access_Service', 'on_program_courses_changed' ), 10 );
			update_post_meta( $program, CLMS_Helper::PROGRAM_COURSES_META, array( $course ) );
		}
		if ( class_exists( '\\ATORA\\LMS\\LMS_Course_Service' ) ) {
			\ATORA\LMS\LMS_Course_Service::get_by_wp_post( $course );
			\ATORA\LMS\LMS_Course_Service::get_lesson_by_wp_post( $lesson );
		}
		return array( 'student' => $student, 'program' => $program, 'course' => $course, 'lesson' => $lesson );
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
		$sections = array_values( (array) $class::get_sections_by_course( $course ) ); // indexado por id
		$id       = ! empty( $sections[0]['id'] ) ? (int) $sections[0]['id'] : (int) $class::create( array( 'wp_course_id' => $course, 'title' => 'Sección E2E' ) );
		if ( $id > 0 ) {
			$class::add_teacher( $id, $teacher );
			$class::add_student( $id, $student );
		}
		return $id;
	}

	/** Rúbrica de 2 criterios, migrada a tablas (como la usa SpeedGrader). */
	private static function rubric( int $author ): int {
		$existing = get_page_by_path( 'rubrica-e2e', OBJECT, 'clms_rubric' );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		$id     = (int) wp_insert_post( array( 'post_type' => 'clms_rubric', 'post_status' => 'publish', 'post_name' => 'rubrica-e2e', 'post_title' => 'Rúbrica E2E', 'post_author' => $author ) );
		$levels = array( array( 'label' => 'Inicial', 'points' => 4 ), array( 'label' => 'Logrado', 'points' => 8 ), array( 'label' => 'Excelente', 'points' => 10 ) );
		update_post_meta( $id, '_clms_rubric_scale_type', '0_100' );
		update_post_meta( $id, '_clms_rubric_is_holistic', '0' );
		update_post_meta( $id, '_clms_rubric_criteria', array(
			array( 'name' => 'Claridad', 'description' => 'Se entiende la idea principal.', 'max_points' => 10, 'weight' => 50, 'levels' => $levels ),
			array( 'name' => 'Estructura', 'description' => 'Tiene orden lógico.', 'max_points' => 10, 'weight' => 50, 'levels' => $levels ),
		) );
		if ( class_exists( '\\ATORA\\LMS\\Rubrics_CLI' ) ) {
			update_option( 'atora_rubric_source', 'tables', false );
			\ATORA\LMS\Rubrics_CLI::migrate( array(), array( 'yes' => true, 'batch' => 50 ) );
		}
		return $id;
	}

	/** Un PDF mínimo válido de una página, como adjunto de la entrega. */
	private static function pdf( int $author ): int {
		$existing = get_page_by_path( 'ensayo-e2e', OBJECT, 'attachment' );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		$text    = 'Ensayo de prueba E2E';
		$stream  = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
		$objects = array(
			'<< /Type /Catalog /Pages 2 0 R >>',
			'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
			'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
			'<< /Length ' . strlen( $stream ) . " >>\nstream\n{$stream}\nendstream",
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
		);
		$pdf     = "%PDF-1.4\n";
		$offsets = array();
		foreach ( $objects as $i => $object ) {
			$offsets[] = strlen( $pdf );
			$pdf      .= ( $i + 1 ) . " 0 obj\n{$object}\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= 'xref' . "\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( 'ensayo-e2e.pdf' );
		file_put_contents( $tmp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id = media_handle_sideload( array( 'name' => 'ensayo-e2e.pdf', 'tmp_name' => $tmp ), 0, 'Ensayo E2E', array( 'post_name' => 'ensayo-e2e', 'post_author' => $author ) );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/** Imagen PNG mínima (1×1), como material descargable. */
	private static function image( int $author ): int {
		// PNG 1×1 transparente.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO5W1c8AAAAASUVORK5CYII=', true );
		if ( false === $png ) {
			return 0;
		}
		$tmp = wp_tempnam( 'material-e2e.png' );
		if ( ! $tmp ) {
			return 0;
		}
		file_put_contents( $tmp, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$id = media_handle_sideload(
			array( 'name' => 'material-e2e.png', 'tmp_name' => $tmp ),
			0,
			'Material E2E',
			array( 'post_name' => 'material-e2e', 'post_author' => $author )
		);
		return is_wp_error( $id ) ? 0 : absint( $id );
	}

	/** Paquete SCORM de fixtures (tests/fixtures/scorm), como adjunto ZIP. */
	/** 6.37.0: audio MP3 de prueba (tests/fixtures/audio/tono.mp3). */
	private static function audio( int $author ): int {
		$existing = get_page_by_path( 'audio-e2e', OBJECT, 'attachment' );
		if ( $existing ) {
			return (int) $existing->ID;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$fixture = dirname( __DIR__, 2 ) . '/tests/fixtures/audio/tono.mp3';
		$tmp     = file_exists( $fixture ) ? wp_tempnam( 'audio-e2e.mp3' ) : '';
		if ( ! $tmp ) {
			self::seed_error( 'Audio E2E: falta tests/fixtures/audio/tono.mp3.' );
		}
		copy( $fixture, $tmp );
		$id = media_handle_sideload( array( 'name' => 'audio-e2e.mp3', 'tmp_name' => $tmp ), 0, 'Audio E2E', array( 'post_name' => 'audio-e2e', 'post_author' => $author ) );
		if ( is_wp_error( $id ) ) {
			self::seed_error( 'Audio E2E: ' . $id->get_error_message() );
		}
		return absint( $id );
	}

	private static function scorm_zip_attachment( int $author ): int {
		$existing = get_page_by_path( 'scorm-e2e', OBJECT, 'attachment' );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$fixture = dirname( __DIR__, 2 ) . '/tests/fixtures/scorm/RuntimeMinimumCalls_SCORM12.zip';
		if ( ! file_exists( $fixture ) ) {
			return 0;
		}
		$tmp = wp_tempnam( 'scorm-e2e.zip' );
		if ( ! $tmp ) {
			return 0;
		}
		copy( $fixture, $tmp );
		$id = media_handle_sideload(
			array( 'name' => 'scorm-e2e.zip', 'tmp_name' => $tmp ),
			0,
			'SCORM E2E',
			array( 'post_name' => 'scorm-e2e', 'post_author' => $author )
		);
		return is_wp_error( $id ) ? 0 : absint( $id );
	}

	/** Paquete SCORM (fixture) que hace fetch/XHR desde sus propios archivos. */
	private static function scorm_xhr_zip_attachment( int $author ): int {
		$existing = get_page_by_path( 'scorm-xhr-e2e', OBJECT, 'attachment' );
		if ( $existing ) {
			return (int) $existing->ID;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$fixture = dirname( __DIR__, 2 ) . '/tests/fixtures/scorm/AtoraXHR_SCORM12.zip';
		if ( ! file_exists( $fixture ) ) {
			return 0;
		}
		$tmp = wp_tempnam( 'scorm-xhr-e2e.zip' );
		if ( ! $tmp ) {
			return 0;
		}
		copy( $fixture, $tmp );
		$id = media_handle_sideload(
			array( 'name' => 'scorm-xhr-e2e.zip', 'tmp_name' => $tmp ),
			0,
			'SCORM XHR E2E',
			array( 'post_name' => 'scorm-xhr-e2e', 'post_author' => $author )
		);
		return is_wp_error( $id ) ? 0 : absint( $id );
	}

	/** @return array<string,mixed> */
	private static function scorm_resource( int $admin_id ): array {
		self::require_class( '\CLMS_Academic_Library_Service', 'SCORM E2E' );
		self::require_class( '\ATORA\LMS\Tenant_Context', 'SCORM E2E' );
		self::require_class( '\ATORA\LMS\Institution_Service', 'SCORM E2E' );
		$admin_id = absint( $admin_id );
		if ( ! $admin_id ) { self::seed_error( 'SCORM E2E: no se pudo resolver admin_id.' ); }
		$attachment_id = self::scorm_zip_attachment( $admin_id );
		if ( ! $attachment_id ) { self::seed_error( 'SCORM E2E: no se pudo crear el adjunto del ZIP fixture.' ); }

		$institution_id = self::require_or_create_institution_id( 'SCORM E2E' );

		return \ATORA\LMS\Tenant_Context::with(
			$institution_id,
			static function() use ( $admin_id, $attachment_id ) {
				global $wpdb;
				$service = new \CLMS_Academic_Library_Service();
				$item_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}atora_library_items WHERE slug = %s LIMIT 1",
						'scorm-e2e'
					)
				);
				if ( ! $item_id ) {
					$created = $service->create_item(
						array(
							'title'          => 'SCORM E2E',
							'slug'           => 'scorm-e2e',
							'description'    => 'Paquete SCORM para recorridos E2E.',
							'resource_type'  => 'scorm',
						),
						$admin_id
					);
					if ( is_wp_error( $created ) ) {
						self::seed_error( 'SCORM E2E: create_item falló: ' . $created->get_error_code() . ' — ' . $created->get_error_message() );
					}
					if ( empty( $created['id'] ) ) { self::seed_error( 'SCORM E2E: create_item devolvió un resultado sin id.' ); }
					$item_id = absint( $created['id'] );
				}

				$version = (array) $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}atora_library_versions WHERE item_id = %d ORDER BY version_number DESC LIMIT 1",
						$item_id
					),
					ARRAY_A
				);
				if ( empty( $version ) ) {
					$added = $service->add_version(
						$item_id,
						array(
							'attachment_id' => $attachment_id,
							'mime_type'     => 'application/zip',
						),
						$admin_id
					);
					if ( is_wp_error( $added ) ) {
						self::seed_error( 'SCORM E2E: add_version falló: ' . $added->get_error_code() . ' — ' . $added->get_error_message() );
					}
					$version = is_array( $added ) ? $added : array();
				}

				self::ensure_library_item_published( $service, $item_id, $admin_id, 'SCORM E2E' );

				if ( empty( $version['id'] ) ) {
					self::seed_error( 'SCORM E2E: no se pudo resolver el id de versión.' );
				}

				return array(
					'type'              => 'scorm',
					'title'             => 'SCORM mínimo',
					'description'       => 'Paquete SCORM para pruebas de pantalla (app).',
					'library_item_id'   => $item_id,
					// Fijar la versión: el cliente no elige.
					'library_version_id'=> absint( $version['id'] ),
					'scorm_max_attempts'=> 0,
				);
			}
		);
	}

	/** @return array<string,mixed> */
	private static function scorm_xhr_resource( int $admin_id ): array {
		self::require_class( '\CLMS_Academic_Library_Service', 'SCORM XHR E2E' );
		self::require_class( '\ATORA\LMS\Tenant_Context', 'SCORM XHR E2E' );
		self::require_class( '\ATORA\LMS\Institution_Service', 'SCORM XHR E2E' );
		$admin_id = absint( $admin_id );
		if ( ! $admin_id ) { self::seed_error( 'SCORM XHR E2E: no se pudo resolver admin_id.' ); }
		$attachment_id = self::scorm_xhr_zip_attachment( $admin_id );
		if ( ! $attachment_id ) { self::seed_error( 'SCORM XHR E2E: no se pudo crear el adjunto del ZIP fixture.' ); }

		$institution_id = self::require_or_create_institution_id( 'SCORM XHR E2E' );

		return \ATORA\LMS\Tenant_Context::with(
			$institution_id,
			static function() use ( $admin_id, $attachment_id ) {
				global $wpdb;
				$service = new \CLMS_Academic_Library_Service();
				$item_id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}atora_library_items WHERE slug = %s LIMIT 1",
						'scorm-xhr-e2e'
					)
				);
				if ( ! $item_id ) {
					$created = $service->create_item(
						array(
							'title'         => 'SCORM XHR E2E',
							'slug'          => 'scorm-xhr-e2e',
							'description'   => 'Paquete SCORM con fetch/XHR para pruebas de pantalla.',
							'resource_type' => 'scorm',
						),
						$admin_id
					);
					if ( is_wp_error( $created ) ) {
						self::seed_error( 'SCORM XHR E2E: create_item falló: ' . $created->get_error_code() . ' — ' . $created->get_error_message() );
					}
					if ( empty( $created['id'] ) ) { self::seed_error( 'SCORM XHR E2E: create_item devolvió un resultado sin id.' ); }
					$item_id = absint( $created['id'] );
				}

				$version = (array) $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}atora_library_versions WHERE item_id = %d ORDER BY version_number DESC LIMIT 1",
						$item_id
					),
					ARRAY_A
				);
				if ( empty( $version ) ) {
					$added = $service->add_version(
						$item_id,
						array(
							'attachment_id' => $attachment_id,
							'mime_type'     => 'application/zip',
						),
						$admin_id
					);
					if ( is_wp_error( $added ) ) {
						self::seed_error( 'SCORM XHR E2E: add_version falló: ' . $added->get_error_code() . ' — ' . $added->get_error_message() );
					}
					$version = is_array( $added ) ? $added : array();
				}

				self::ensure_library_item_published( $service, $item_id, $admin_id, 'SCORM XHR E2E' );

				if ( empty( $version['id'] ) ) {
					self::seed_error( 'SCORM XHR E2E: no se pudo resolver el id de versión.' );
				}

				return array(
					'type'               => 'scorm',
					'title'              => 'SCORM XHR',
					'description'        => 'Paquete SCORM con fetch/XHR (app).',
					'library_item_id'    => $item_id,
					// Fijar la versión: el cliente no elige.
					'library_version_id' => absint( $version['id'] ),
					'scorm_max_attempts' => 0,
				);
			}
		);
	}

	private static function ensure_library_item_published( \CLMS_Academic_Library_Service $service, int $item_id, int $actor_id, string $context ): void {
		global $wpdb;
		$item_id  = absint( $item_id );
		$actor_id = absint( $actor_id );
		$status = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_library_items WHERE id = %d LIMIT 1", $item_id )
		);
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			self::seed_error( $context . ': no se pudo leer status del item.' );
		}
		if ( 'published' === $status ) {
			return;
		}
		// Política editorial: draft → review → published.
		if ( 'draft' === $status ) {
			$review = $service->transition( $item_id, 'review', $actor_id );
			if ( is_wp_error( $review ) ) {
				self::seed_error( $context . ': transition(draft→review) falló: ' . $review->get_error_code() . ' — ' . $review->get_error_message() );
			}
			$status = 'review';
		}
		if ( 'review' === $status ) {
			$published = $service->transition( $item_id, 'published', $actor_id );
			if ( is_wp_error( $published ) ) {
				self::seed_error( $context . ': transition(review→published) falló: ' . $published->get_error_code() . ' — ' . $published->get_error_message() );
			}
			return;
		}
		self::seed_error( $context . ': status inesperado al publicar: ' . $status );
	}

	private static function seed_error( string $message ): void {
		\WP_CLI::error( $message );
	}

	private static function require_class( string $class, string $context ): void {
		if ( class_exists( $class ) ) {
			return;
		}
		self::seed_error( $context . ': falta la clase ' . $class . ' (el plugin no la cargó en WP-CLI).' );
	}

	/** 6.41.0: miembros de la institución y delegación del curso (como lo haría el docente desde la web). */
	private static function delegate( int $teacher, int $assistant, int $course ): void {
		if ( ! class_exists( 'ATORA_Delegation_Service' ) ) {
			return;
		}
		global $wpdb;
		$institution = self::require_or_create_institution_id( 'Delegación E2E' );
		$members     = $wpdb->prefix . 'atora_institution_members';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $members ) ) ) === $members ) {
			foreach ( array( $teacher => 'teacher', $assistant => 'assistant' ) as $user => $role ) {
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$members} WHERE institution_id = %d AND user_id = %d", $institution, $user ) ); // phpcs:ignore WordPress.DB
				if ( ! $exists ) {
					$wpdb->insert( $members, array( 'institution_id' => $institution, 'user_id' => $user, 'role' => $role, 'status' => 'active' ) );
				}
				update_user_meta( $user, '_atora_active_institution', $institution );
			}
		}
		$previous = get_current_user_id();
		wp_set_current_user( $teacher );
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			\ATORA\LMS\Tenant_Context::reset_cache();
		}
		if ( ! ATORA_Delegation_Service::grant( $teacher, $assistant, array( 'scope' => 'course', 'wp_course_id' => $course, 'perms' => array( 'content' => true ) ) ) ) {
			self::seed_error( 'No se pudo delegar el curso E2E al asistente.' );
		}
		wp_set_current_user( $previous );
	}

	private static function require_or_create_institution_id( string $context ): int {
		$institution_id = \ATORA\LMS\Tenant_Context::current_institution_id();
		if ( is_wp_error( $institution_id ) ) {
			\WP_CLI::log( $context . ': Tenant_Context no pudo resolver institución: ' . $institution_id->get_error_code() . ' — ' . $institution_id->get_error_message() );
			global $wpdb;
			$table = $wpdb->prefix . 'atora_institutions';
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
			if ( $exists ) {
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				\WP_CLI::log( $context . ': atora_institutions=' . $count . ' → usando ensure_default_institution().' );
			} else {
				\WP_CLI::log( $context . ': la tabla atora_institutions no existe → usando ensure_default_institution().' );
			}
			$created = \ATORA\LMS\Institution_Service::ensure_default_institution();
			if ( $created <= 0 ) {
				self::seed_error( $context . ': no se pudo crear/resolver una institución por defecto (atora_institutions vacía o tabla ausente).' );
			}
			$institution_id = $created;
		}
		$institution_id = absint( $institution_id );
		if ( $institution_id <= 0 ) {
			self::seed_error( $context . ': institution_id inválido (0) tras la resolución.' );
		}
		return $institution_id;
	}

	/** Grupo de la tarea grupal. */
	private static function group( int $course, int $actor, array $members ): int {
		if ( ! class_exists( '\\ATORA\\Groups\\Group_Service' ) ) {
			return 0;
		}
		$service = new \ATORA\Groups\Group_Service();
		foreach ( $service->list_groups( $course ) as $row ) {
			if ( 'Equipo E2E' === (string) ( $row['name'] ?? '' ) ) {
				return (int) $row['id'];
			}
		}
		$id = $service->create_group( $course, 'Equipo E2E', $actor );
		if ( $id ) {
			$service->set_members( $id, $members, $actor );
		}
		return (int) $id;
	}

	private static function submission( int $student, int $lesson, int $course, int $attachment = 0 ): int {
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
		if ( $attachment > 0 ) {
			wp_update_post( array( 'ID' => $attachment, 'post_parent' => $id ) );
			update_post_meta( $id, '_clms_submission_files', array( $attachment ) );
			update_post_meta( $id, '_clms_submission_attachments', array( $attachment ) );
		}
		// 6.31.0: el intento queda en el historial, como una entrega web.
		if ( class_exists( 'ATORA_Web_Submission_History' ) ) {
			ATORA_Web_Submission_History::record( $id, 'web-seed-' . $id );
		}
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
