<?php
/**
 * REST Controller — SCORM runtime (Bloque 2).
 *
 * Namespace: atora/v1
 * Base: scorm
 *
 * @package ATORA_LMS
 * @since 6.35.0
 */

namespace ATORA\SCORM\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__, 3 ) . '/includes/scorm/class-scorm-launch-service.php';

final class SCORM_Controller extends WP_REST_Controller {
	private \CLMS_SCORM_Attempt_Service $attempts;

	public function __construct( \CLMS_SCORM_Attempt_Service $attempts ) {
		$this->namespace = 'atora/v1';
		$this->rest_base = 'scorm';
		$this->attempts  = $attempts;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/attempts/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_attempt' ),
					'permission_callback' => array( $this, 'can_start_attempt' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/attempts/(?P<id>\\d+)/values',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_values' ),
					'permission_callback' => array( $this, 'can_use_attempt' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/attempts/(?P<id>\\d+)/commit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'commit' ),
					'permission_callback' => array( $this, 'can_use_attempt' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/report',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'report' ),
					'permission_callback' => array( $this, 'can_view_report' ),
				),
			)
		);
	}

	public function can_start_attempt( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );
		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'atora_scorm_invalid_lesson', __( 'Lección inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$user_id = get_current_user_id();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_access_lesson' ) ) {
			if ( ! \CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'No tienes acceso a esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	public function can_use_attempt( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		$attempt_id = absint( $request->get_param( 'id' ) );
		if ( ! $attempt_id ) {
			return new WP_Error( 'atora_scorm_invalid_attempt', __( 'Intento inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$attempt = $this->attempts->get_attempt_for_user( $attempt_id, get_current_user_id() );
		return is_wp_error( $attempt ) ? $attempt : true;
	}

	public function can_view_report( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Debes iniciar sesión.', 'atora-lms' ), array( 'status' => 401 ) );
		}
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );
		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return new WP_Error( 'atora_scorm_invalid_lesson', __( 'Lección inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'user_can_manage_lms' ) ) {
			if ( ! \CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
				return new WP_Error( 'rest_forbidden', __( 'No autorizado.', 'atora-lms' ), array( 'status' => 403 ) );
			}
			return true;
		}
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'rest_forbidden', __( 'No autorizado.', 'atora-lms' ), array( 'status' => 403 ) );
	}

	/** @param WP_REST_Request $request */
	public function start_attempt( $request ) {
		$user_id           = get_current_user_id();
		$lesson_id         = absint( $request->get_param( 'lesson_id' ) );
		$library_item_id   = absint( $request->get_param( 'library_item_id' ) );
		unset( $request );
		if ( ! $lesson_id || ! $library_item_id ) {
			return new WP_Error( 'atora_scorm_invalid_params', __( 'Parámetros inválidos.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$resource = $this->find_scorm_resource_for_lesson( $lesson_id, $library_item_id );
		if ( is_wp_error( $resource ) ) {
			return $resource;
		}
		$resource_version_id = absint( $resource['library_version_id'] ?? 0 );

		$version = $this->resolve_library_version( $library_item_id, $resource_version_id );
		if ( is_wp_error( $version ) ) {
			return $version;
		}

		$meta = $this->extract_scorm_meta( $version );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$max_attempts = max( 0, absint( get_post_meta( $lesson_id, '_clms_scorm_max_attempts', true ) ) );
		$attempt = $this->attempts->start_or_resume_attempt( $user_id, $lesson_id, $library_item_id, absint( $version['id'] ?? 0 ), (string) $meta['scorm_version'], $max_attempts );
		if ( is_wp_error( $attempt ) ) {
			return $attempt;
		}

		$sco_hrefs = $this->list_sco_hrefs_from_install( (string) $meta['content_base_url'] );
		if ( is_wp_error( $sco_hrefs ) ) {
			$sco_hrefs = array( (string) $meta['launch_href'] );
		}

		return new WP_REST_Response(
			array(
				'attempt_id'        => absint( $attempt['id'] ?? 0 ),
				'scorm_version'     => (string) $meta['scorm_version'],
				'launch_url'        => (string) $meta['launch_url'],
				'content_base_url'  => (string) $meta['content_base_url'],
				'launch_href'       => (string) $meta['launch_href'],
				'sco_hrefs'         => array_values( array_filter( array_map( 'strval', (array) $sco_hrefs ) ) ),
			),
			201
		);
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function find_scorm_resource_for_lesson( int $lesson_id, int $library_item_id ) {
		return \CLMS_SCORM_Launch_Service::find_scorm_resource_for_lesson( $lesson_id, $library_item_id );
	}

	/** @param WP_REST_Request $request */
	public function get_values( $request ) {
		global $wpdb;
		$attempt_id = absint( $request->get_param( 'id' ) );
		$table = \CLMS_SCORM_Attempt_Service::table_cmi();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT cmi_key, cmi_value FROM {$table} WHERE attempt_id = %d", $attempt_id ), ARRAY_A );
		$out = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['cmi_key'] ) ) {
				$out[ (string) $row['cmi_key'] ] = (string) ( $row['cmi_value'] ?? '' );
			}
		}
		return new WP_REST_Response( array( 'values' => $out ), 200 );
	}

	/** @param WP_REST_Request $request */
	public function commit( $request ) {
		$attempt_id = absint( $request->get_param( 'id' ) );
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$values  = isset( $payload['values'] ) && is_array( $payload['values'] ) ? (array) $payload['values'] : array();
		$result = $this->attempts->commit_values( $attempt_id, get_current_user_id(), $values );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/** @param WP_REST_Request $request */
	public function report( $request ) {
		global $wpdb;
		$lesson_id = absint( $request->get_param( 'lesson_id' ) );
		$student_id = absint( $request->get_param( 'student_id' ) );
		$table = \CLMS_SCORM_Attempt_Service::table_attempts();
		$sql = $student_id
			? $wpdb->prepare( "SELECT * FROM {$table} WHERE lesson_id = %d AND user_id = %d ORDER BY user_id, attempt_number", $lesson_id, $student_id )
			: $wpdb->prepare( "SELECT * FROM {$table} WHERE lesson_id = %d ORDER BY user_id, attempt_number", $lesson_id );
		$attempts = (array) $wpdb->get_results( $sql, ARRAY_A );
		$attempt_ids = array_values( array_filter( array_map( static fn( $r ) => absint( is_array( $r ) ? ( $r['id'] ?? 0 ) : 0 ), $attempts ) ) );
		if ( ! empty( $attempt_ids ) ) {
			$cmi_table = \CLMS_SCORM_Attempt_Service::table_cmi();
			$in = implode( ',', array_map( 'absint', $attempt_ids ) );
			$interactions = (array) $wpdb->get_results( "SELECT attempt_id, COUNT(*) AS cnt FROM {$cmi_table} WHERE attempt_id IN ({$in}) AND cmi_key LIKE 'cmi.interactions.%' GROUP BY attempt_id", ARRAY_A );
			$objectives   = (array) $wpdb->get_results( "SELECT attempt_id, COUNT(*) AS cnt FROM {$cmi_table} WHERE attempt_id IN ({$in}) AND cmi_key LIKE 'cmi.objectives.%' GROUP BY attempt_id", ARRAY_A );
			$inter_map = array();
			foreach ( $interactions as $row ) {
				if ( is_array( $row ) ) {
					$inter_map[ absint( $row['attempt_id'] ?? 0 ) ] = absint( $row['cnt'] ?? 0 );
				}
			}
			$obj_map = array();
			foreach ( $objectives as $row ) {
				if ( is_array( $row ) ) {
					$obj_map[ absint( $row['attempt_id'] ?? 0 ) ] = absint( $row['cnt'] ?? 0 );
				}
			}
			foreach ( $attempts as $i => $attempt ) {
				$aid = absint( is_array( $attempt ) ? ( $attempt['id'] ?? 0 ) : 0 );
				if ( $aid ) {
					$attempts[ $i ]['interactions_count'] = $inter_map[ $aid ] ?? 0;
					$attempts[ $i ]['objectives_count']   = $obj_map[ $aid ] ?? 0;
				}
			}
		}
		return new WP_REST_Response( array( 'attempts' => $attempts ), 200 );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_library_version( int $item_id, int $version_id ) {
		return \CLMS_SCORM_Launch_Service::resolve_library_version( $item_id, $version_id );
	}

	/**
	 * @return array{scorm_version:string,launch_url:string,content_base_url:string,launch_href:string}|WP_Error
	 */
	private function extract_scorm_meta( array $version ) {
		return \CLMS_SCORM_Launch_Service::extract_scorm_meta( $version );
	}

	/**
	 * @return string[]|WP_Error
	 */
	private function list_sco_hrefs_from_install( string $content_base_url ) {
		return \CLMS_SCORM_Launch_Service::list_sco_hrefs_from_install( $content_base_url );
	}
}

