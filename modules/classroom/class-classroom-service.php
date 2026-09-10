<?php
/**
 * Epic 6 — Google Classroom: servicio (API + mapeos + sync de roster).
 *
 * @package ATORA_LMS
 * @since   6.18.0
 */
namespace ATORA\Classroom;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Classroom_Service {

	private function table_course_map(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_google_classroom_course_map';
	}

	private function table_sync_log(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_google_classroom_sync_log';
	}

	private function table_coursework_map(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_google_classroom_coursework_map';
	}

	public function get_access_token( int $user_id ): ?string {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}
		if ( ! class_exists( '\ATORA\Calendar\Calendar_Sync' ) ) {
			return null;
		}
		return \ATORA\Calendar\Calendar_Sync::get_valid_access_token( $user_id, 'google' );
	}

	/**
	 * @param string $path Path bajo https://classroom.googleapis.com/v1
	 * @param array  $query
	 * @param int    $user_id
	 * @return array|WP_Error
	 */
	public function api_get( string $path, array $query, int $user_id ) {
		return $this->api_request( 'GET', $path, $query, array(), $user_id );
	}

	/**
	 * @param string $method
	 * @param string $path
	 * @param array  $query
	 * @param array  $body
	 * @param int    $user_id
	 * @return array|WP_Error
	 */
	public function api_request( string $method, string $path, array $query, array $body, int $user_id ) {
		$method = strtoupper( trim( $method ) );
		$token = $this->get_access_token( $user_id );
		if ( ! $token ) {
			return new WP_Error( 'not_connected', __( 'Google no está conectado para este usuario.', 'atora-lms' ) );
		}

		$url = 'https://classroom.googleapis.com/v1' . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$attempts = 0;
		$max_attempts = 4;
		$res = null;
		$code = 0;
		$retry_after = 0;

		while ( $attempts < $max_attempts ) {
			++$attempts;

			$args = array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'timeout' => 25,
			);
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				$args['body'] = wp_json_encode( $body );
			}

			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				// Errores de red suelen ser transitorios: pequeño backoff y retry.
				$this->sleep_backoff_ms( $attempts, 0 );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $res );
			$retry_after_raw = wp_remote_retrieve_header( $res, 'retry-after' );
			$retry_after = is_scalar( $retry_after_raw ) ? absint( (string) $retry_after_raw ) : 0;

			if ( $this->should_retry_http_code( $code ) ) {
				$this->sleep_backoff_ms( $attempts, $retry_after );
				continue;
			}

			break;
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = '' !== trim( $raw ) ? json_decode( $raw, true ) : array();

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Error al llamar Google Classroom.', 'atora-lms' );
			// Mensaje más accionable cuando faltan scopes.
			if ( 403 === $code && is_string( $msg ) && false !== stripos( $msg, 'insufficient authentication scopes' ) ) {
				$msg = __( 'Google Classroom: permisos insuficientes (scopes). Activa Classroom en ATORA → Google y reconecta la cuenta.', 'atora-lms' );
			}
			return new WP_Error( 'classroom_api_error', $msg, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	private function should_retry_http_code( int $code ): bool {
		return in_array( (int) $code, array( 429, 500, 502, 503, 504 ), true );
	}

	private function sleep_backoff_ms( int $attempt, int $retry_after_seconds = 0 ): void {
		$attempt = max( 1, $attempt );
		$retry_after_seconds = max( 0, absint( $retry_after_seconds ) );

		// Si Google envía Retry-After, respétalo (cap pequeño para no bloquear requests largos).
		if ( $retry_after_seconds > 0 ) {
			$ms = min( 3000, $retry_after_seconds * 1000 );
			usleep( $ms * 1000 );
			return;
		}

		// Backoff exponencial suave: 250ms, 500ms, 1000ms, 2000ms...
		$ms = (int) ( 250 * ( 2 ** ( $attempt - 1 ) ) );
		$ms = max( 0, min( 2000, $ms ) );
		if ( $ms > 0 ) {
			usleep( $ms * 1000 );
		}
	}

	/**
	 * Lista cursos visibles para el usuario (teacher).
	 *
	 * @param int $user_id
	 * @return array<int,array{gc_course_id:string,name:string,section:string}>
	 */
	public function list_courses_for_user( int $user_id ): array {
		$out = array();
		$page_token = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$q = array(
				'teacherId'    => 'me',
				'courseStates' => 'ACTIVE',
				'pageSize'     => 100,
			);
			if ( $page_token ) {
				$q['pageToken'] = $page_token;
			}

			$data = $this->api_get( '/courses', $q, $user_id );
			if ( is_wp_error( $data ) ) {
				break;
			}

			$courses = isset( $data['courses'] ) && is_array( $data['courses'] ) ? $data['courses'] : array();
			foreach ( $courses as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$id = sanitize_text_field( (string) ( $c['id'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}
				$out[] = array(
					'gc_course_id' => $id,
					'name'         => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
					'section'      => sanitize_text_field( (string) ( $c['section'] ?? '' ) ),
				);
			}

			$page_token = sanitize_text_field( (string) ( $data['nextPageToken'] ?? '' ) );
			if ( '' === $page_token ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param string $gc_course_id
	 * @param int    $actor_user_id
	 * @return array<string,string> Map userId => email
	 */
	public function get_course_student_email_map( string $gc_course_id, int $actor_user_id ): array {
		$gc_course_id  = sanitize_text_field( trim( $gc_course_id ) );
		$actor_user_id = absint( $actor_user_id );
		if ( '' === $gc_course_id || ! $actor_user_id ) {
			return array();
		}

		$page_token = '';
		$out = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$q = array( 'pageSize' => 200 );
			if ( $page_token ) {
				$q['pageToken'] = $page_token;
			}

			$data = $this->api_get( '/courses/' . rawurlencode( $gc_course_id ) . '/students', $q, $actor_user_id );
			if ( is_wp_error( $data ) ) {
				break;
			}

			$students = isset( $data['students'] ) && is_array( $data['students'] ) ? $data['students'] : array();
			foreach ( $students as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$profile = isset( $s['profile'] ) && is_array( $s['profile'] ) ? $s['profile'] : array();
				$user_id = sanitize_text_field( (string) ( $profile['id'] ?? '' ) );
				$email   = sanitize_email( (string) ( $profile['emailAddress'] ?? '' ) );
				if ( $user_id && $email ) {
					$out[ $user_id ] = strtolower( $email );
				}
			}

			$page_token = sanitize_text_field( (string) ( $data['nextPageToken'] ?? '' ) );
			if ( '' === $page_token ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Lista tareas (courseWork) de un curso Classroom.
	 *
	 * @param string $gc_course_id
	 * @param int    $actor_user_id
	 * @return array<int,array{gc_coursework_id:string,title:string,description:string,due_date:string,due_time:string,state:string,work_type:string,update_time:string}>
	 */
	public function list_coursework( string $gc_course_id, int $actor_user_id ): array {
		$gc_course_id   = sanitize_text_field( trim( $gc_course_id ) );
		$actor_user_id  = absint( $actor_user_id );
		if ( '' === $gc_course_id || ! $actor_user_id ) {
			return array();
		}

		$out = array();
		$page_token = '';

		for ( $i = 0; $i < 10; $i++ ) {
			$q = array(
				'pageSize' => 100,
				'orderBy'  => 'updateTime desc',
			);
			if ( $page_token ) {
				$q['pageToken'] = $page_token;
			}

			$data = $this->api_get( '/courses/' . rawurlencode( $gc_course_id ) . '/courseWork', $q, $actor_user_id );
			if ( is_wp_error( $data ) ) {
				break;
			}

			$items = isset( $data['courseWork'] ) && is_array( $data['courseWork'] ) ? $data['courseWork'] : array();
			foreach ( $items as $cw ) {
				if ( ! is_array( $cw ) ) {
					continue;
				}
				$id = sanitize_text_field( (string) ( $cw['id'] ?? '' ) );
				if ( '' === $id ) {
					continue;
				}

				$due = $this->extract_due( $cw );
				$out[] = array(
					'gc_coursework_id' => $id,
					'title'            => sanitize_text_field( (string) ( $cw['title'] ?? '' ) ),
					'description'      => sanitize_textarea_field( (string) ( $cw['description'] ?? '' ) ),
					'due_date'         => $due['date'],
					'due_time'         => $due['time'],
					'state'            => sanitize_key( (string) ( $cw['state'] ?? '' ) ),
					'work_type'        => sanitize_key( (string) ( $cw['workType'] ?? '' ) ),
					'update_time'      => sanitize_text_field( (string) ( $cw['updateTime'] ?? '' ) ),
					'max_points'       => absint( $cw['maxPoints'] ?? 0 ),
				);
			}

			$page_token = sanitize_text_field( (string) ( $data['nextPageToken'] ?? '' ) );
			if ( '' === $page_token ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Importa una tarea (courseWork) como lección en el curso WP, idempotente por gc_coursework_id.
	 *
	 * @param int    $wp_course_id
	 * @param string $gc_coursework_id
	 * @param int    $actor_user_id
	 * @param string $post_status 'draft'|'publish'
	 * @return array{wp_lesson_id:int,created:bool}|WP_Error
	 */
	public function import_coursework_as_lesson( int $wp_course_id, string $gc_coursework_id, int $actor_user_id, string $post_status = 'draft' ) {
		global $wpdb;

		$wp_course_id     = absint( $wp_course_id );
		$actor_user_id    = absint( $actor_user_id );
		$gc_coursework_id = sanitize_text_field( trim( $gc_coursework_id ) );
		$post_status      = sanitize_key( $post_status );

		if ( ! $wp_course_id || ! $actor_user_id || '' === $gc_coursework_id ) {
			return new WP_Error( 'invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ) );
		}
		if ( ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
			$post_status = 'draft';
		}

		$map = $this->get_mapping_by_wp_course( $wp_course_id );
		if ( empty( $map ) ) {
			return new WP_Error( 'not_mapped', __( 'Este curso no tiene mapeo con Classroom.', 'atora-lms' ) );
		}
		$gc_course_id = sanitize_text_field( (string) ( $map['gc_course_id'] ?? '' ) );
		if ( '' === $gc_course_id ) {
			return new WP_Error( 'not_mapped', __( 'Mapeo inválido (gc_course_id vacío).', 'atora-lms' ) );
		}

		$data = $this->api_get( '/courses/' . rawurlencode( $gc_course_id ) . '/courseWork/' . rawurlencode( $gc_coursework_id ), array(), $actor_user_id );
		if ( is_wp_error( $data ) ) {
			$this->log_sync( $wp_course_id, $gc_course_id, 'coursework', 'error', $data->get_error_message(), array( 'code' => $data->get_error_code() ) );
			return $data;
		}

		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = __( 'Tarea importada de Classroom', 'atora-lms' );
		}
		$desc = (string) ( $data['description'] ?? '' );
		$desc = wp_kses_post( $desc );

		$due = $this->extract_due( $data );
		$state = sanitize_key( (string) ( $data['state'] ?? '' ) );
		$max_points = absint( $data['maxPoints'] ?? 0 );

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, wp_lesson_id FROM {$this->table_coursework_map()} WHERE gc_course_id = %s AND gc_coursework_id = %s LIMIT 1",
				$gc_course_id,
				$gc_coursework_id
			),
			ARRAY_A
		);

		$created = false;
		$wp_lesson_id = absint( is_array( $existing ) ? ( $existing['wp_lesson_id'] ?? 0 ) : 0 );

		if ( $wp_lesson_id && 'lm_lesson' === get_post_type( $wp_lesson_id ) ) {
			wp_update_post( array(
				'ID'           => $wp_lesson_id,
				'post_title'   => $title,
				'post_content' => $desc,
			) );
		} else {
			$wp_lesson_id = wp_insert_post( array(
				'post_type'    => 'lm_lesson',
				'post_status'  => $post_status,
				'post_title'   => $title,
				'post_content' => $desc,
				'post_author'  => $actor_user_id,
			) );
			if ( is_wp_error( $wp_lesson_id ) ) {
				$this->log_sync( $wp_course_id, $gc_course_id, 'coursework', 'error', $wp_lesson_id->get_error_message(), array() );
				return $wp_lesson_id;
			}
			$wp_lesson_id = absint( $wp_lesson_id );
			$created = true;
		}

		if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'set_course_id_for_lesson' ) ) {
			\CLMS_Helper::set_course_id_for_lesson( $wp_lesson_id, $wp_course_id, true );
		} else {
			update_post_meta( $wp_lesson_id, '_clms_course_id', $wp_course_id );
			update_post_meta( $wp_lesson_id, '_clms_lesson_course_id', $wp_course_id );
			update_post_meta( $wp_lesson_id, 'course_id', $wp_course_id );
		}

		if ( '' !== $due['date'] ) {
			update_post_meta( $wp_lesson_id, '_clms_due_date', $due['date'] );
			update_post_meta( $wp_lesson_id, 'lm_due_date', $due['date'] );
		}
		if ( '' !== $due['time'] ) {
			update_post_meta( $wp_lesson_id, '_clms_due_time', $due['time'] );
		}

		if ( $max_points > 0 ) {
			update_post_meta( $wp_lesson_id, '_clms_gradebook_points', $max_points );
			update_post_meta( $wp_lesson_id, '_clms_max_points', $max_points );
		}

		$now = current_time( 'mysql', true );
		$payload_json = wp_json_encode( $data );

		$payload = array(
			'wp_course_id'     => $wp_course_id,
			'gc_course_id'     => $gc_course_id,
			'gc_coursework_id' => $gc_coursework_id,
			'wp_lesson_id'     => $wp_lesson_id,
			'title'            => $title,
			'due_date'         => $due['date'] ?: null,
			'due_time'         => $due['time'] ?: null,
			'state'            => $state ?: null,
			'payload_json'     => $payload_json ?: null,
			'updated_at'       => $now,
		);

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$wpdb->update( $this->table_coursework_map(), $payload, array( 'id' => absint( $existing['id'] ) ) );
		} else {
			$payload['created_at'] = $now;
			$wpdb->insert( $this->table_coursework_map(), $payload );
		}

		$this->log_sync( $wp_course_id, $gc_course_id, 'coursework', 'ok', 'Import de tarea completado.', array(
			'gc_coursework_id' => $gc_coursework_id,
			'wp_lesson_id'     => $wp_lesson_id,
			'created'          => $created,
		) );

		return array(
			'wp_lesson_id' => $wp_lesson_id,
			'created'      => $created,
		);
	}

	public function get_coursework_map_for_course( int $wp_course_id ): array {
		global $wpdb;
		$wp_course_id = absint( $wp_course_id );
		if ( ! $wp_course_id ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT gc_coursework_id, wp_lesson_id, title, updated_at
				 FROM {$this->table_coursework_map()}
				 WHERE wp_course_id = %d",
				$wp_course_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$out = array();
		foreach ( $rows as $r ) {
			$gc_id = sanitize_text_field( (string) ( $r['gc_coursework_id'] ?? '' ) );
			if ( '' === $gc_id ) {
				continue;
			}
			$out[ $gc_id ] = array(
				'wp_lesson_id' => absint( $r['wp_lesson_id'] ?? 0 ),
				'title'        => sanitize_text_field( (string) ( $r['title'] ?? '' ) ),
				'updated_at'   => sanitize_text_field( (string) ( $r['updated_at'] ?? '' ) ),
			);
		}

		return $out;
	}

	/**
	 * Publica notas desde ATORA → Classroom para una tarea (courseWork) importada.
	 *
	 * Regla actual (MVP): solo empuja notas para estudiantes que:
	 * - existen en WordPress (matching por email), y
	 * - tienen una entrega `clms_submission` asociada a la lección importada, y
	 * - tienen nota registrada en `_clms_submission_grade` o `_clms_final_grade`.
	 *
	 * @param int    $wp_course_id
	 * @param string $gc_coursework_id
	 * @param int    $actor_user_id
	 * @param bool   $return_grade Si true, hace :return para publicar al estudiante.
	 * @return array{updated:int,skipped_no_user:int,skipped_no_grade:int,errors:int}
	 */
	public function push_grades_for_coursework( int $wp_course_id, string $gc_coursework_id, int $actor_user_id, bool $return_grade = true ): array {
		global $wpdb;

		$wp_course_id     = absint( $wp_course_id );
		$actor_user_id    = absint( $actor_user_id );
		$gc_coursework_id = sanitize_text_field( trim( $gc_coursework_id ) );

		$out = array(
			'updated'          => 0,
			'skipped_no_user'  => 0,
			'skipped_no_grade' => 0,
			'errors'           => 0,
		);

		if ( ! $wp_course_id || ! $actor_user_id || '' === $gc_coursework_id ) {
			return $out;
		}

		$map = $this->get_mapping_by_wp_course( $wp_course_id );
		if ( empty( $map ) ) {
			return $out;
		}
		$gc_course_id = sanitize_text_field( (string) ( $map['gc_course_id'] ?? '' ) );
		if ( '' === $gc_course_id ) {
			return $out;
		}

		$cw_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT wp_lesson_id FROM {$this->table_coursework_map()} WHERE gc_course_id = %s AND gc_coursework_id = %s LIMIT 1",
				$gc_course_id,
				$gc_coursework_id
			),
			ARRAY_A
		);
		$wp_lesson_id = absint( is_array( $cw_row ) ? ( $cw_row['wp_lesson_id'] ?? 0 ) : 0 );
		if ( ! $wp_lesson_id || 'lm_lesson' !== get_post_type( $wp_lesson_id ) ) {
			$this->log_sync( $wp_course_id, $gc_course_id, 'grades', 'error', 'No hay lección importada para este courseWork.', array( 'gc_coursework_id' => $gc_coursework_id ) );
			return $out;
		}

		$coursework = $this->api_get(
			'/courses/' . rawurlencode( $gc_course_id ) . '/courseWork/' . rawurlencode( $gc_coursework_id ),
			array(),
			$actor_user_id
		);
		if ( is_wp_error( $coursework ) ) {
			$this->log_sync( $wp_course_id, $gc_course_id, 'grades', 'error', $coursework->get_error_message(), array( 'gc_coursework_id' => $gc_coursework_id ) );
			return $out;
		}

		$classroom_max = is_array( $coursework ) ? absint( $coursework['maxPoints'] ?? 0 ) : 0;
		$lesson_max = absint( get_post_meta( $wp_lesson_id, '_clms_gradebook_points', true ) );
		if ( ! $lesson_max ) {
			$lesson_max = absint( get_post_meta( $wp_lesson_id, '_clms_max_points', true ) );
		}

		$email_map = $this->get_course_student_email_map( $gc_course_id, $actor_user_id );

		$page_token = '';
		for ( $page = 0; $page < 12; $page++ ) {
			$q = array( 'pageSize' => 200 );
			if ( $page_token ) {
				$q['pageToken'] = $page_token;
			}

			$data = $this->api_get(
				'/courses/' . rawurlencode( $gc_course_id ) . '/courseWork/' . rawurlencode( $gc_coursework_id ) . '/studentSubmissions',
				$q,
				$actor_user_id
			);
			if ( is_wp_error( $data ) ) {
				$out['errors']++;
				$this->log_sync( $wp_course_id, $gc_course_id, 'grades', 'error', $data->get_error_message(), array( 'gc_coursework_id' => $gc_coursework_id ) );
				break;
			}

			$subs = isset( $data['studentSubmissions'] ) && is_array( $data['studentSubmissions'] ) ? $data['studentSubmissions'] : array();
			foreach ( $subs as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$student_submission_id = sanitize_text_field( (string) ( $sub['id'] ?? '' ) );
				$gc_user_id            = sanitize_text_field( (string) ( $sub['userId'] ?? '' ) );
				if ( '' === $student_submission_id || '' === $gc_user_id ) {
					continue;
				}

				$email = isset( $email_map[ $gc_user_id ] ) ? (string) $email_map[ $gc_user_id ] : '';
				$email = sanitize_email( $email );
				if ( '' === $email ) {
					$out['skipped_no_user']++;
					continue;
				}

				$wp_user = get_user_by( 'email', $email );
				if ( ! $wp_user instanceof \WP_User ) {
					$out['skipped_no_user']++;
					continue;
				}

				$raw_grade = $this->get_wp_submission_grade_for_lesson( absint( $wp_user->ID ), $wp_lesson_id );
				if ( null === $raw_grade ) {
					$out['skipped_no_grade']++;
					continue;
				}

				$points = $this->normalize_grade_to_classroom_points( (float) $raw_grade, $lesson_max, $classroom_max );
				if ( null === $points ) {
					$out['skipped_no_grade']++;
					continue;
				}

				$patched = $this->api_request(
					'PATCH',
					'/courses/' . rawurlencode( $gc_course_id ) . '/courseWork/' . rawurlencode( $gc_coursework_id ) . '/studentSubmissions/' . rawurlencode( $student_submission_id ),
					array( 'updateMask' => 'draftGrade' ),
					array( 'draftGrade' => $points ),
					$actor_user_id
				);
				if ( is_wp_error( $patched ) ) {
					$out['errors']++;
					continue;
				}

				if ( $return_grade ) {
					$returned = $this->api_request(
						'POST',
						'/courses/' . rawurlencode( $gc_course_id ) . '/courseWork/' . rawurlencode( $gc_coursework_id ) . '/studentSubmissions/' . rawurlencode( $student_submission_id ) . ':return',
						array(),
						array(),
						$actor_user_id
					);
					if ( is_wp_error( $returned ) ) {
						$out['errors']++;
						continue;
					}
				}

				$out['updated']++;
			}

			$page_token = sanitize_text_field( (string) ( $data['nextPageToken'] ?? '' ) );
			if ( '' === $page_token ) {
				break;
			}
		}

		$this->log_sync( $wp_course_id, $gc_course_id, 'grades', 'ok', 'Push de notas completado.', array_merge( array( 'gc_coursework_id' => $gc_coursework_id, 'wp_lesson_id' => $wp_lesson_id ), $out ) );
		return $out;
	}

	private function get_wp_submission_grade_for_lesson( int $student_id, int $lesson_id ): ?float {
		$student_id = absint( $student_id );
		$lesson_id  = absint( $lesson_id );
		if ( ! $student_id || ! $lesson_id ) {
			return null;
		}

		$q = new \WP_Query( array(
			'post_type'      => 'clms_submission',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_clms_submission_user_id',
					'value' => $student_id,
				),
				array(
					'key'   => '_clms_submission_lesson_id',
					'value' => $lesson_id,
				),
			),
		) );

		$ids = $q->posts;
		$submission_id = ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
		if ( ! $submission_id ) {
			return null;
		}

		$grade = get_post_meta( $submission_id, '_clms_submission_grade', true );
		if ( '' === (string) $grade ) {
			$grade = get_post_meta( $submission_id, '_clms_final_grade', true );
		}
		if ( '' === (string) $grade || null === $grade ) {
			return null;
		}
		if ( ! is_numeric( $grade ) ) {
			return null;
		}
		return (float) $grade;
	}

	/**
	 * @param float $raw_grade Nota en ATORA (puntos, o % si no hay max_points).
	 * @param int   $lesson_max_points
	 * @param int   $classroom_max_points
	 * @return float|null
	 */
	private function normalize_grade_to_classroom_points( float $raw_grade, int $lesson_max_points, int $classroom_max_points ): ?float {
		$raw_grade = (float) $raw_grade;
		$lesson_max_points = absint( $lesson_max_points );
		$classroom_max_points = absint( $classroom_max_points );

		if ( $raw_grade < 0 ) {
			$raw_grade = 0.0;
		}
		if ( $classroom_max_points <= 0 ) {
			return null;
		}

		if ( $lesson_max_points > 0 ) {
			$percent = min( 1.0, max( 0.0, $raw_grade / (float) $lesson_max_points ) );
			$points  = $percent * (float) $classroom_max_points;
		} else {
			// Sin max_points en la lección: tratar raw como porcentaje (legado).
			$percent = min( 1.0, max( 0.0, $raw_grade / 100.0 ) );
			$points  = $percent * (float) $classroom_max_points;
		}

		$points = round( $points, 2 );
		$points = max( 0.0, min( (float) $classroom_max_points, $points ) );
		return $points;
	}

	/**
	 * @param array $coursework Raw classroom courseWork object.
	 * @return array{date:string,time:string}
	 */
	private function extract_due( array $coursework ): array {
		$due_date = '';
		$due_time = '';

		$dd = isset( $coursework['dueDate'] ) && is_array( $coursework['dueDate'] ) ? $coursework['dueDate'] : array();
		$dt = isset( $coursework['dueTime'] ) && is_array( $coursework['dueTime'] ) ? $coursework['dueTime'] : array();

		$y = isset( $dd['year'] ) ? absint( $dd['year'] ) : 0;
		$m = isset( $dd['month'] ) ? absint( $dd['month'] ) : 0;
		$d = isset( $dd['day'] ) ? absint( $dd['day'] ) : 0;
		if ( $y && $m && $d ) {
			$due_date = sprintf( '%04d-%02d-%02d', $y, $m, $d );
		}

		$hh = isset( $dt['hours'] ) ? absint( $dt['hours'] ) : 0;
		$mm = isset( $dt['minutes'] ) ? absint( $dt['minutes'] ) : 0;
		if ( isset( $dt['hours'] ) || isset( $dt['minutes'] ) ) {
			$due_time = sprintf( '%02d:%02d', max( 0, min( 23, $hh ) ), max( 0, min( 59, $mm ) ) );
		}

		return array(
			'date' => $due_date,
			'time' => $due_time,
		);
	}

	/**
	 * @return array<int,array>
	 */
	public function list_mappings( int $limit = 200 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, absint( $limit ) ) );

		$sql = "SELECT id, wp_course_id, gc_course_id, gc_course_name, owner_user_id, last_roster_sync_at, created_at, updated_at
			FROM {$this->table_course_map()}
			ORDER BY id DESC
			LIMIT %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function get_mapping_by_wp_course( int $wp_course_id ): array {
		global $wpdb;
		$wp_course_id = absint( $wp_course_id );
		if ( ! $wp_course_id ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, wp_course_id, gc_course_id, gc_course_name, owner_user_id, last_roster_sync_at, created_at, updated_at
				 FROM {$this->table_course_map()}
				 WHERE wp_course_id = %d
				 LIMIT 1",
				$wp_course_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	public function upsert_mapping( int $wp_course_id, string $gc_course_id, string $gc_course_name, int $owner_user_id ): bool {
		global $wpdb;

		$wp_course_id  = absint( $wp_course_id );
		$owner_user_id = absint( $owner_user_id );
		$gc_course_id  = sanitize_text_field( trim( $gc_course_id ) );
		$gc_course_name = sanitize_text_field( (string) $gc_course_name );

		if ( ! $wp_course_id || ! $owner_user_id || '' === $gc_course_id ) {
			return false;
		}

		$existing = $this->get_mapping_by_wp_course( $wp_course_id );
		$now = current_time( 'mysql', true );

		$data = array(
			'wp_course_id'   => $wp_course_id,
			'gc_course_id'   => $gc_course_id,
			'gc_course_name' => $gc_course_name,
			'owner_user_id'  => $owner_user_id,
			'updated_at'     => $now,
		);

		if ( empty( $existing ) ) {
			$data['created_at'] = $now;
			$ok = false !== $wpdb->insert( $this->table_course_map(), $data );
			return (bool) $ok;
		}

		$ok = false !== $wpdb->update( $this->table_course_map(), $data, array( 'id' => absint( $existing['id'] ?? 0 ) ) );
		return (bool) $ok;
	}

	public function delete_mapping( int $mapping_id ): bool {
		global $wpdb;
		$mapping_id = absint( $mapping_id );
		if ( ! $mapping_id ) {
			return false;
		}
		$ok = false !== $wpdb->delete( $this->table_course_map(), array( 'id' => $mapping_id ) );
		return (bool) $ok;
	}

	/**
	 * Sync roster: lista estudiantes del curso de Classroom y los inscribe en el curso WP por email.
	 *
	 * @param int  $wp_course_id
	 * @param int  $actor_user_id
	 * @param bool $create_users_if_missing
	 * @return array{enrolled:int,already_enrolled:int,missing:int,missing_emails:array<int,string>}
	 */
	public function sync_roster_by_email( int $wp_course_id, int $actor_user_id, bool $create_users_if_missing = false ): array {
		$wp_course_id = absint( $wp_course_id );
		$actor_user_id = absint( $actor_user_id );

		$result = array(
			'enrolled'        => 0,
			'already_enrolled'=> 0,
			'missing'         => 0,
			'missing_emails'  => array(),
		);

		$map = $this->get_mapping_by_wp_course( $wp_course_id );
		if ( empty( $map ) ) {
			return $result;
		}

		$gc_course_id = sanitize_text_field( (string) ( $map['gc_course_id'] ?? '' ) );
		if ( '' === $gc_course_id ) {
			return $result;
		}

		$page_token = '';
		$emails = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$q = array(
				'pageSize' => 200,
			);
			if ( $page_token ) {
				$q['pageToken'] = $page_token;
			}

			$data = $this->api_get( '/courses/' . rawurlencode( $gc_course_id ) . '/students', $q, $actor_user_id );
			if ( is_wp_error( $data ) ) {
				$this->log_sync( $wp_course_id, $gc_course_id, 'roster', 'error', $data->get_error_message(), array( 'code' => $data->get_error_code() ) );
				return $result;
			}

			$students = isset( $data['students'] ) && is_array( $data['students'] ) ? $data['students'] : array();
			foreach ( $students as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$profile = isset( $s['profile'] ) && is_array( $s['profile'] ) ? $s['profile'] : array();
				$email = sanitize_email( (string) ( $profile['emailAddress'] ?? '' ) );
				if ( $email ) {
					$emails[] = strtolower( $email );
				}
			}

			$page_token = sanitize_text_field( (string) ( $data['nextPageToken'] ?? '' ) );
			if ( '' === $page_token ) {
				break;
			}
		}
		$emails = array_values( array_unique( array_filter( $emails ) ) );

		if ( empty( $emails ) ) {
			$this->log_sync( $wp_course_id, $gc_course_id, 'roster', 'ok', 'Roster vacío o sin emails.', array() );
			return $result;
		}

		foreach ( $emails as $email ) {
			$user = get_user_by( 'email', $email );
			if ( ! $user instanceof \WP_User ) {
				if ( $create_users_if_missing ) {
					$new_id = wp_insert_user( array(
						'user_login'   => sanitize_user( current( explode( '@', $email ) ) ?: $email, true ),
						'user_email'   => $email,
						'user_pass'    => wp_generate_password( 32 ),
						'display_name' => $email,
						'role'         => 'lms_student',
					) );
					if ( is_wp_error( $new_id ) ) {
						$result['missing']++;
						$result['missing_emails'][] = $email;
						continue;
					}
					$user = get_userdata( (int) $new_id );
				} else {
					$result['missing']++;
					$result['missing_emails'][] = $email;
					continue;
				}
			}

			$user_id = absint( $user->ID );
			if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'user_is_enrolled_in_course' ) && \CLMS_Helper::user_is_enrolled_in_course( $user_id, $wp_course_id ) ) {
				$result['already_enrolled']++;
				continue;
			}

			$ok = class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'enroll_user_in_course' )
				? (bool) \CLMS_Helper::enroll_user_in_course( $user_id, $wp_course_id )
				: false;

			if ( $ok ) {
				$result['enrolled']++;
			} else {
				$result['missing']++;
				$result['missing_emails'][] = $email;
			}
		}

		$this->touch_roster_sync( absint( $map['id'] ?? 0 ) );
		$this->log_sync( $wp_course_id, $gc_course_id, 'roster', 'ok', 'Sync de roster completado.', $result );
		return $result;
	}

	private function touch_roster_sync( int $mapping_id ): void {
		global $wpdb;
		$mapping_id = absint( $mapping_id );
		if ( ! $mapping_id ) {
			return;
		}
		$wpdb->update(
			$this->table_course_map(),
			array(
				'last_roster_sync_at' => current_time( 'mysql', true ),
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'id' => $mapping_id )
		);
	}

	private function log_sync( int $wp_course_id, string $gc_course_id, string $sync_type, string $status, string $message, array $meta ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table_sync_log(),
			array(
				'wp_course_id' => absint( $wp_course_id ),
				'gc_course_id' => sanitize_text_field( (string) $gc_course_id ),
				'sync_type'    => sanitize_key( $sync_type ),
				'status'       => sanitize_key( $status ),
				'message'      => sanitize_text_field( $message ),
				'meta_json'    => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
				'created_at'   => current_time( 'mysql', true ),
			)
		);
	}
}
