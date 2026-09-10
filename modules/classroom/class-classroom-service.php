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
		$token = $this->get_access_token( $user_id );
		if ( ! $token ) {
			return new WP_Error( 'not_connected', __( 'Google no está conectado para este usuario.', 'atora-lms' ) );
		}

		$url = 'https://classroom.googleapis.com/v1' . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$res = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
			'timeout' => 20,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Error al llamar Google Classroom.', 'atora-lms' );
			return new WP_Error( 'classroom_api_error', $msg, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
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

