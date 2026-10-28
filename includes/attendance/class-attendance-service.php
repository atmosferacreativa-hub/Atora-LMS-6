<?php
/**
 * Asistencia presencial (6.40.0).
 *
 * Antes la tabla `atora_attendance` solo la llenaban Zoom, Meet y Teams: no había forma de tomar lista en el aula.
 *
 * - Una sesión presencial por curso, lección (opcional) y fecha: fila en `atora_live_sessions` con proveedor
 *   `presencial` y clave externa `presencial:{curso}:{lección}:{fecha}` (única, así dos teléfonos abren la misma).
 * - Estados: presente, tarde, ausente, justificado (con nota).
 * - La toma se hace sin conexión y llega después: cada marca trae cuándo se tomó en el teléfono y gana la más
 *   reciente (un envío viejo que llega tarde no pisa una corrección). Reenviar lo mismo no cambia nada.
 * - Cada marca dispara `atora/attendance/recorded`: la racha de ausencias pasa al seguimiento (regla existente).
 *
 * Permisos: el docente con el curso en su alcance, también el asistente con delegación (tomar lista no es evaluar).
 *
 * @package ATORA_LMS
 * @since 6.40.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Attendance_Service {

	const PROVIDER = 'presencial';
	const STATUSES = array( 'presente', 'tarde', 'ausente', 'justificado' );

	private static function sessions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_live_sessions';
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_attendance';
	}

	public static function can_take( int $user_id, int $wp_course ): bool {
		return $user_id > 0 && $wp_course > 0 && class_exists( 'ATORA_Teacher_Scope' )
			&& ATORA_Teacher_Scope::has_teacher_role( $user_id )
			&& ATORA_Teacher_Scope::can_access_course( $user_id, $wp_course );
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'atora_attendance_forbidden', __( 'No puedes tomar asistencia en este curso.', 'atora-lms' ), array( 'status' => 403 ) );
	}

	/** Lecciones presenciales del curso (marcadas por la planificación híbrida o a mano). @return int[] */
	public static function in_person_lessons( int $wp_course ): array {
		return array_map( 'absint', get_posts( array(
			'post_type'        => 'lm_lesson',
			'post_status'      => array( 'publish', 'private', 'draft', 'future' ),
			'posts_per_page'   => 200,
			'fields'           => 'ids',
			'orderby'          => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array( 'key' => '_clms_course_id', 'value' => (string) $wp_course ),
				array( 'key' => '_atora_in_person', 'value' => '1' ),
			),
		) ) );
	}

	/**
	 * Abre (o devuelve) la sesión presencial de un día.
	 *
	 * @return array|WP_Error sesión
	 */
	public static function open_session( int $user_id, int $wp_course, int $wp_lesson, string $date, string $time = '' ) {
		if ( ! self::can_take( $user_id, $wp_course ) ) {
			return self::forbidden();
		}
		if ( $wp_lesson && absint( get_post_meta( $wp_lesson, '_clms_course_id', true ) ) !== $wp_course ) {
			return new WP_Error( 'atora_attendance_lesson', __( 'La lección no es de este curso.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$time   = 1 === preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '08:00';
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, wp_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			return new WP_Error( 'atora_attendance_date', __( 'La fecha no es válida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$key = self::PROVIDER . ':' . $wp_course . ':' . $wp_lesson . ':' . $date;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'INSERT IGNORE INTO ' . self::sessions_table() . ' (lesson_id, course_id, provider, external_id, start_datetime, duration_minutes, timezone, status, created_by_user_id, created_at) VALUES (%d, %d, %s, %s, %s, %d, %s, %s, %d, %s)',
			$wp_lesson,
			$wp_course,
			self::PROVIDER,
			$key,
			gmdate( 'Y-m-d H:i:s', $parsed->getTimestamp() ),
			60,
			wp_timezone_string(),
			'ended',
			$user_id,
			current_time( 'mysql', true )
		) );
		return self::session_by_key( $key );
	}

	public static function session_key( int $wp_course, int $wp_lesson, string $date ): string {
		return self::PROVIDER . ':' . $wp_course . ':' . $wp_lesson . ':' . $date;
	}

	/** Sesión de ese día si ya existe (sin crearla). */
	public static function find_session( int $wp_course, int $wp_lesson, string $date ): ?array {
		$found = self::session_by_key( self::session_key( $wp_course, $wp_lesson, $date ) );
		return is_wp_error( $found ) ? null : $found;
	}

	/**
	 * Lista del día sin crear la sesión (para abrirla en el teléfono antes de marcar).
	 *
	 * @return array|WP_Error
	 */
	public static function roster_for( int $user_id, int $wp_course, int $wp_lesson, string $date ) {
		if ( ! self::can_take( $user_id, $wp_course ) ) {
			return self::forbidden();
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'atora_attendance_date', __( 'La fecha no es válida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$session = self::find_session( $wp_course, $wp_lesson, $date );
		if ( $session ) {
			return self::roster( $user_id, $session['id'] );
		}
		$students = array();
		foreach ( self::student_ids( $user_id, $wp_course ) as $student ) {
			$user = get_userdata( $student );
			if ( $user ) {
				$students[] = array( 'id' => $student, 'name' => (string) ( $user->display_name ?: $user->user_login ), 'status' => null, 'note' => '', 'marked_at' => null );
			}
		}
		usort( $students, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return array(
			'session'  => array( 'id' => 0, 'course_id' => $wp_course, 'lesson_id' => $wp_lesson, 'date' => $date, 'starts_at' => null, 'title' => $wp_lesson ? sanitize_text_field( (string) get_the_title( $wp_lesson ) ) : __( 'Clase', 'atora-lms' ) ),
			'students' => $students,
		);
	}

	/**
	 * Abre la sesión del día (si hace falta) y guarda las marcas. Es lo que usa la cola del teléfono.
	 *
	 * @return array|WP_Error
	 */
	public static function record_for( int $user_id, int $wp_course, int $wp_lesson, string $date, array $marks ) {
		$session = self::open_session( $user_id, $wp_course, $wp_lesson, $date );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		$result = self::record( $user_id, $session['id'], $marks );
		return is_wp_error( $result ) ? $result : $result + array( 'session' => $session );
	}

	/** Sesiones presenciales recientes del curso con su conteo. @return array[] */
	public static function recent_sessions( int $wp_course, int $limit = 20 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT s.*, SUM(a.status = \'presente\') AS presente, SUM(a.status = \'tarde\') AS tarde, SUM(a.status = \'ausente\') AS ausente, SUM(a.status = \'justificado\') AS justificado
			 FROM ' . self::sessions_table() . ' s LEFT JOIN ' . self::table() . ' a ON a.session_id = s.id
			 WHERE s.provider = %s AND s.course_id = %d GROUP BY s.id ORDER BY s.start_datetime DESC LIMIT %d',
			self::PROVIDER,
			$wp_course,
			$limit
		), ARRAY_A );
		return array_map( static function ( array $row ): array {
			return self::shape_session( $row ) + array(
				'counts' => array(
					'presente'    => (int) $row['presente'],
					'tarde'       => (int) $row['tarde'],
					'ausente'     => (int) $row['ausente'],
					'justificado' => (int) $row['justificado'],
				),
			);
		}, $rows );
	}

	/** @return array|WP_Error */
	private static function session_by_key( string $key ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE provider = %s AND external_id = %s', self::PROVIDER, $key ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::shape_session( $row ) : new WP_Error( 'atora_attendance_unavailable', __( 'La asistencia no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
	}

	/** @return array|null */
	public static function session( int $session_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::sessions_table() . ' WHERE id = %d AND provider = %s', $session_id, self::PROVIDER ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::shape_session( $row ) : null;
	}

	private static function shape_session( array $row ): array {
		$parts = explode( ':', (string) $row['external_id'] );
		return array(
			'id'        => (int) $row['id'],
			'course_id' => (int) $row['course_id'],
			'lesson_id' => (int) $row['lesson_id'],
			'date'      => (string) ( $parts[3] ?? '' ),
			'starts_at' => $row['start_datetime'] ? gmdate( 'c', strtotime( $row['start_datetime'] . ' UTC' ) ) : null,
			'title'     => $row['lesson_id'] ? sanitize_text_field( (string) get_the_title( (int) $row['lesson_id'] ) ) : __( 'Clase', 'atora-lms' ),
		);
	}

	/** Estudiantes de la sesión: matriculados (o de la sección del docente). @return int[] */
	private static function student_ids( int $user_id, int $wp_course ): array {
		$ids = array_map( 'absint', (array) CLMS_Helper::get_enrolled_student_ids( $wp_course ) );
		if ( class_exists( '\\ATORA\\LMS\\Section_Service' ) && ATORA_Teacher_Scope::is_section_teacher( $user_id, $wp_course ) && ! user_can( $user_id, 'manage_options' ) ) {
			$own = array();
			foreach ( (array) \ATORA\LMS\Section_Service::get_sections_by_course( $wp_course ) as $section ) {
				$teachers = array_map( 'intval', wp_list_pluck( (array) \ATORA\LMS\Section_Service::get_teachers( (int) $section['id'] ), 'user_id' ) );
				if ( in_array( $user_id, $teachers, true ) ) {
					$own = array_merge( $own, array_map( 'absint', \ATORA\LMS\Section_Service::get_section_student_ids( (int) $section['id'] ) ) );
				}
			}
			$ids = array_values( array_intersect( $ids, $own ) );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Lista de la sesión con el estado de cada estudiante (null = sin marcar).
	 *
	 * @return array|WP_Error
	 */
	public static function roster( int $user_id, int $session_id ) {
		$session = self::session( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'atora_attendance_not_found', __( 'Sesión no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_take( $user_id, $session['course_id'] ) ) {
			return self::forbidden();
		}
		global $wpdb;
		$marks = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, status, note, marked_at, recorded_by FROM ' . self::table() . ' WHERE session_id = %d AND user_id > 0', $session_id ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$marks[ (int) $row['user_id'] ] = $row;
		}
		$students = array();
		foreach ( self::student_ids( $user_id, $session['course_id'] ) as $student ) {
			$user = get_userdata( $student );
			if ( ! $user ) {
				continue;
			}
			$mark       = $marks[ $student ] ?? null;
			$students[] = array(
				'id'        => $student,
				'name'      => (string) ( $user->display_name ?: $user->user_login ),
				'status'    => $mark ? (string) $mark['status'] : null,
				'note'      => $mark ? (string) $mark['note'] : '',
				'marked_at' => $mark && $mark['marked_at'] ? gmdate( 'c', strtotime( $mark['marked_at'] . ' UTC' ) ) : null,
			);
		}
		usort( $students, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );
		return array( 'session' => $session, 'students' => $students );
	}

	/**
	 * Guarda marcas. Cada una: student_id, status, note, marked_at (ISO, cuándo se tomó en el teléfono).
	 * Gana la marca más reciente; una más vieja que la guardada se ignora.
	 *
	 * @return array{applied:int,ignored:int}|WP_Error
	 */
	public static function record( int $user_id, int $session_id, array $marks ) {
		$session = self::session( $session_id );
		if ( ! $session ) {
			return new WP_Error( 'atora_attendance_not_found', __( 'Sesión no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_take( $user_id, $session['course_id'] ) ) {
			return self::forbidden();
		}
		$allowed = array_flip( self::student_ids( $user_id, $session['course_id'] ) );
		global $wpdb;
		$applied = 0;
		$ignored = 0;
		foreach ( $marks as $mark ) {
			$student = absint( $mark['student_id'] ?? 0 );
			$status  = sanitize_key( (string) ( $mark['status'] ?? '' ) );
			if ( ! isset( $allowed[ $student ] ) || ! in_array( $status, self::STATUSES, true ) ) {
				return new WP_Error( 'atora_attendance_invalid', __( 'Hay una marca inválida: revisa el estudiante y el estado.', 'atora-lms' ), array( 'status' => 400 ) );
			}
			$when      = strtotime( (string) ( $mark['marked_at'] ?? '' ) );
			$when      = $when && $when <= time() + 300 ? $when : time();
			$marked_at = gmdate( 'Y-m-d H:i:s', $when );
			$note      = 'justificado' === $status || 'tarde' === $status ? mb_substr( sanitize_text_field( (string) ( $mark['note'] ?? '' ) ), 0, 255 ) : '';

			$current = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, note, marked_at FROM ' . self::table() . " WHERE session_id = %d AND user_id = %d AND external_participant_id = ''", $session_id, $student ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $current && $current['marked_at'] && strtotime( $current['marked_at'] . ' UTC' ) > $when ) {
				++$ignored; // Ya hay una toma posterior (otro teléfono o una corrección).
				continue;
			}
			if ( $current && $current['status'] === $status && (string) $current['note'] === $note && $current['marked_at'] === $marked_at ) {
				++$ignored; // Reenvío idéntico.
				continue;
			}
			$data = array(
				'status'      => $status,
				'note'        => $note,
				'recorded_by' => $user_id,
				'marked_at'   => $marked_at,
				'updated_at'  => current_time( 'mysql', true ),
				'source'      => 'manual',
				'course_id'   => $session['course_id'],
			);
			if ( $current ) {
				$wpdb->update( self::table(), $data, array( 'id' => (int) $current['id'] ) );
			} else {
				$wpdb->insert( self::table(), $data + array( 'user_id' => $student, 'session_id' => $session_id, 'external_participant_id' => '', 'created_at' => current_time( 'mysql', true ) ) );
			}
			++$applied;
			do_action( 'atora/attendance/recorded', $student, $session['course_id'], $status );
		}
		return array( 'applied' => $applied, 'ignored' => $ignored );
	}

	/**
	 * Resumen de un estudiante en un curso (todas las fuentes: aula y clases en vivo).
	 *
	 * @return array{sessions:int,presente:int,tarde:int,ausente:int,justificado:int,rate:float|null}
	 */
	public static function summary( int $student, int $wp_course ): array {
		global $wpdb;
		$out = array( 'sessions' => 0, 'presente' => 0, 'tarde' => 0, 'ausente' => 0, 'justificado' => 0, 'rate' => null );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) n FROM ' . self::table() . ' WHERE user_id = %d AND course_id = %d GROUP BY status', $student, $wp_course ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['n'];
				$out['sessions']      += (int) $row['n'];
			}
		}
		$counted = $out['sessions'] - $out['justificado'];
		$out['rate'] = $counted > 0 ? round( 100 * ( $out['presente'] + $out['tarde'] ) / $counted, 1 ) : null;
		return $out;
	}
}
