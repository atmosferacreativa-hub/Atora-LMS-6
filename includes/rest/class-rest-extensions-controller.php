<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_REST_Extensions_Controller {

	protected $permissions;

	public function __construct( CLMS_REST_Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/* --------------------------------------------------------------
	 * OPENAPI + PERFORMANCE
	 * -------------------------------------------------------------- */

	protected function resolve_doc_path( $file ) {
		$file = trim( (string) $file );
		if ( '' === $file ) {
			return false;
		}

		if ( defined( 'ATORA_LMS_DIR' ) && ATORA_LMS_DIR ) {
			$base = ATORA_LMS_DIR;
		} elseif ( defined( 'CLMS_PLUGIN_DIR' ) && CLMS_PLUGIN_DIR ) {
			$base = CLMS_PLUGIN_DIR;
		} else {
			$base = trailingslashit( dirname( __FILE__, 2 ) );
		}

		$path = trailingslashit( $base ) . ltrim( $file, '/\\' );

		return file_exists( $path ) ? $path : false;
	}

	protected function build_openapi_response( $path, $error_code, $error_message ) {
		if ( ! $path ) {
			return new WP_Error( $error_code, $error_message, array( 'status' => 404 ) );
		}

		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return new WP_Error( $error_code, $error_message, array( 'status' => 404 ) );
		}

		$response = new WP_REST_Response( $contents, 200 );
		$response->set_headers(
			array(
				'Content-Type' => 'application/yaml; charset=utf-8',
			)
		);

		return $response;
	}

	public function get_openapi_spec( WP_REST_Request $request ) {
		unset( $request );
		$path = $this->resolve_doc_path( 'docs/openapi.yaml' );
		return $this->build_openapi_response( $path, 'clms_openapi_missing', __( 'Documento OpenAPI no disponible.', 'atora-lms' ) );
	}

	public function get_public_openapi_spec( WP_REST_Request $request ) {
		unset( $request );
		$path = $this->resolve_doc_path( 'docs/openapi-public.yaml' );
		return $this->build_openapi_response( $path, 'clms_openapi_public_missing', __( 'Documento OpenAPI público no disponible.', 'atora-lms' ) );
	}

	public function get_performance_status( WP_REST_Request $request ) {
		unset( $request );

		$object_cache = class_exists( 'CLMS_Cache' ) ? CLMS_Cache::get_object_cache_status() : array(
			'enabled'     => false,
			'driver'      => 'none',
			'dropin_path' => '',
		);

		$opcache_available = function_exists( 'opcache_get_status' );
		$opcache_enabled   = $opcache_available ? (bool) ini_get( 'opcache.enable' ) : false;

		return rest_ensure_response(
			array(
				'object_cache' => array(
					'enabled'     => (bool) $object_cache['enabled'],
					'driver'      => $object_cache['driver'],
					'dropin_path' => $object_cache['dropin_path'],
				),
				'opcache'      => array(
					'available' => (bool) $opcache_available,
					'enabled'   => (bool) $opcache_enabled,
				),
				'wp_cache'     => array(
					'enabled' => defined( 'WP_CACHE' ) ? (bool) WP_CACHE : false,
				),
				'php'          => array(
					'version' => PHP_VERSION,
				),
			)
		);
	}

	/* --------------------------------------------------------------
	 * RUBRICS
	 * -------------------------------------------------------------- */

	public function get_rubrics( WP_REST_Request $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = $this->sanitize_per_page( $request->get_param( 'per_page' ) );

		$args = array(
			'post_type'      => 'clms_rubric',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$can_access_admin = class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'can_access_admin' )
			? CLMS_Access::can_access_admin()
			: current_user_can( 'manage_options' );

		if ( ! $can_access_admin ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );

		$rubrics = array();
		foreach ( $query->posts as $post ) {
			$rubrics[] = $this->format_rubric( $post );
		}

		return rest_ensure_response( array(
			'rubrics'     => $rubrics,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
		) );
	}

	public function create_rubric( WP_REST_Request $request ) {
		$data  = $this->get_json_or_body_params( $request );
		$title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'missing_title', __( 'El título de la rúbrica es obligatorio.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$criteria = isset( $data['criteria'] ) && is_array( $data['criteria'] )
			? $this->sanitize_rubric_criteria( $data['criteria'] )
			: array();

		$post_id = wp_insert_post( array(
			'post_type'    => 'clms_rubric',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'post_author'  => get_current_user_id(),
		) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_clms_rubric_criteria', $criteria );
		update_post_meta( $post_id, '_clms_rubric_max_score', absint( $data['max_score'] ?? 100 ) );

		return rest_ensure_response( $this->format_rubric( get_post( $post_id ) ) );
	}

	public function get_rubric( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'clms_rubric' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Rúbrica no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post->ID ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver esta rúbrica.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response( $this->format_rubric( $post ) );
	}

	public function update_rubric( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'clms_rubric' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Rúbrica no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post->ID ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar esta rúbrica.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data    = $this->get_json_or_body_params( $request );
		$updates = array( 'ID' => $post->ID );

		if ( isset( $data['title'] ) ) {
			$updates['post_title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['description'] ) ) {
			$updates['post_content'] = sanitize_textarea_field( $data['description'] );
		}

		wp_update_post( $updates );

		if ( isset( $data['criteria'] ) && is_array( $data['criteria'] ) ) {
			update_post_meta( $post->ID, '_clms_rubric_criteria', $this->sanitize_rubric_criteria( $data['criteria'] ) );
		}

		if ( isset( $data['max_score'] ) ) {
			update_post_meta( $post->ID, '_clms_rubric_max_score', absint( $data['max_score'] ) );
		}

		return rest_ensure_response( $this->format_rubric( get_post( $post->ID ) ) );
	}

	public function delete_rubric( WP_REST_Request $request ) {
		$post = get_post( absint( $request['id'] ) );

		if ( ! $post || 'clms_rubric' !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Rúbrica no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		if ( ! $this->current_user_can_manage_post_resource( $post->ID ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para eliminar esta rúbrica.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		wp_delete_post( $post->ID, true );

		return rest_ensure_response( array( 'deleted' => true, 'id' => $post->ID ) );
	}

	public function get_lesson_rubric( WP_REST_Request $request ) {
		$lesson_id = absint( $request['id'] );

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver la rúbrica de esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$rubric_id = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );

		if ( ! $rubric_id ) {
			return rest_ensure_response( array( 'rubric' => null ) );
		}

		$rubric = get_post( $rubric_id );

		if ( ! $rubric || 'clms_rubric' !== $rubric->post_type ) {
			return rest_ensure_response( array( 'rubric' => null ) );
		}

		return rest_ensure_response( array( 'rubric' => $this->format_rubric( $rubric ) ) );
	}

	public function set_lesson_rubric( WP_REST_Request $request ) {
		$lesson_id = absint( $request['id'] );
		$data      = $this->get_json_or_body_params( $request );
		$rubric_id = isset( $data['rubric_id'] ) ? absint( $data['rubric_id'] ) : 0;

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para editar la rúbrica de esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( $rubric_id ) {
			$rubric = get_post( $rubric_id );
			if ( ! $rubric || 'clms_rubric' !== $rubric->post_type ) {
				return new WP_Error( 'not_found', __( 'Rúbrica no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
			}

			if ( ! $this->current_user_can_manage_post_resource( $rubric_id ) ) {
				return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para asignar esta rúbrica.', 'atora-lms' ), array( 'status' => 403 ) );
			}
		}

		update_post_meta( $lesson_id, '_clms_rubric_id', $rubric_id );

		return rest_ensure_response( array( 'lesson_id' => $lesson_id, 'rubric_id' => $rubric_id ) );
	}

	protected function format_rubric( WP_Post $post ) {
		$criteria  = class_exists( 'CLMS_Rubric' )
			? CLMS_Rubric::get_criteria( $post->ID )
			: $this->sanitize_rubric_criteria( (array) get_post_meta( $post->ID, '_clms_rubric_criteria', true ) );
		$max_score = class_exists( 'CLMS_Rubric' )
			? CLMS_Rubric::get_total_points( $post->ID )
			: absint( get_post_meta( $post->ID, '_clms_rubric_max_score', true ) );
		if ( ! $max_score ) {
			$max_score = 100;
		}

		return array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'criteria'    => $criteria,
			'max_score'   => $max_score,
			'created_at'  => $post->post_date_gmt,
		);
	}

	protected function sanitize_rubric_criteria( array $criteria ) {
		$clean = array();
		foreach ( $criteria as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$criterion = array(
				'name'        => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
				'description' => isset( $item['description'] ) ? sanitize_textarea_field( $item['description'] ) : '',
				'competency'  => isset( $item['competency'] ) ? sanitize_text_field( $item['competency'] ) : '',
				'improvement_tip' => isset( $item['improvement_tip'] ) ? sanitize_textarea_field( $item['improvement_tip'] ) : '',
				'max_points'  => isset( $item['max_points'] ) ? absint( $item['max_points'] ) : 10,
				'levels'      => array(),
			);
			if ( isset( $item['levels'] ) && is_array( $item['levels'] ) ) {
				foreach ( $item['levels'] as $level ) {
					if ( ! is_array( $level ) ) {
						continue;
					}
					$criterion['levels'][] = array(
						'label'       => isset( $level['label'] ) ? sanitize_text_field( $level['label'] ) : '',
						'description' => isset( $level['description'] ) ? sanitize_textarea_field( $level['description'] ) : ( isset( $level['descriptor'] ) ? sanitize_textarea_field( $level['descriptor'] ) : '' ),
						'descriptor'  => isset( $level['descriptor'] ) ? sanitize_textarea_field( $level['descriptor'] ) : ( isset( $level['description'] ) ? sanitize_textarea_field( $level['description'] ) : '' ),
						'points'      => isset( $level['points'] ) ? absint( $level['points'] ) : 0,
					);
				}
			}
			if ( $criterion['name'] ) {
				$clean[] = $criterion;
			}
		}
		return $clean;
	}

	/* --------------------------------------------------------------
	 * TRANSCRIPTIONS
	 * -------------------------------------------------------------- */

	public function get_transcription( WP_REST_Request $request ) {
		$lesson_id = absint( $request['id'] );

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver esta transcripción.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$transcription = get_post_meta( $lesson_id, '_clms_transcription', true );
		$status        = get_post_meta( $lesson_id, '_clms_transcription_status', true ) ?: 'none';

		return rest_ensure_response( array(
			'lesson_id'     => $lesson_id,
			'status'        => $status,
			'transcription' => $transcription ?: null,
			'updated_at'    => get_post_meta( $lesson_id, '_clms_transcription_updated_at', true ) ?: null,
		) );
	}

	public function trigger_transcription( WP_REST_Request $request ) {
		$lesson_id = absint( $request['id'] );
		$data      = $this->get_json_or_body_params( $request );

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para transcribir esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( ! empty( $data['transcription'] ) ) {
			update_post_meta( $lesson_id, '_clms_transcription', sanitize_textarea_field( $data['transcription'] ) );
			update_post_meta( $lesson_id, '_clms_transcription_status', 'done' );
			update_post_meta( $lesson_id, '_clms_transcription_updated_at', gmdate( 'Y-m-d H:i:s' ) );

			return rest_ensure_response( array( 'lesson_id' => $lesson_id, 'status' => 'done' ) );
		}

		$provider = isset( $data['provider'] ) ? sanitize_key( (string) $data['provider'] ) : 'auto';
		if ( ! in_array( $provider, array( 'auto', 'youtube_captions', 'whisper' ), true ) ) {
			$provider = 'auto';
		}

		$source            = $this->get_lesson_video_source_slug( $lesson_id );
		$resolved_provider = 'auto' === $provider ? ( 'youtube' === $source ? 'youtube_captions' : 'whisper' ) : $provider;
		$needs_whisper_key = 'whisper' === $resolved_provider;

		if ( $needs_whisper_key && ! $this->has_whisper_key_configured() ) {
			return new WP_Error(
				'clms_whisper_not_configured',
				__( 'Whisper no está configurado. Añade una API key en Configuración de Atora > APIs e IA.', 'atora-lms' ),
				array( 'status' => 400 )
			);
		}

		update_post_meta( $lesson_id, '_clms_transcription_status', 'queued' );
		update_post_meta( $lesson_id, '_clms_transcription_provider', $resolved_provider );
		do_action( 'clms_transcription_queued', $lesson_id );

		return rest_ensure_response(
			array(
				'lesson_id' => $lesson_id,
				'status'    => 'queued',
				'provider'  => $resolved_provider,
			)
		);
	}

	/* --------------------------------------------------------------
	 * PEER REVIEW
	 * -------------------------------------------------------------- */

	public function get_peer_assignments( WP_REST_Request $request ) {
		$lesson_id = absint( $request['lesson_id'] );

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver asignaciones de esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$assignments = get_posts( array(
			'post_type'      => 'clms_peer_review',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => '_clms_pr_lesson_id',
					'value' => $lesson_id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		$rows = array();
		foreach ( $assignments as $assignment ) {
			$rows[] = $this->format_peer_assignment( $assignment );
		}

		return rest_ensure_response( array( 'assignments' => $rows, 'total' => count( $rows ) ) );
	}

	public function assign_peer_reviews( WP_REST_Request $request ) {
		$lesson_id   = absint( $request['lesson_id'] );
		$data        = $this->get_json_or_body_params( $request );
		$per_student = absint( $data['reviews_per_student'] ?? 2 );
		$per_student = max( 1, min( $per_student, 5 ) );

		if ( ! $this->current_user_can_manage_post_resource( $lesson_id ) ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para asignar revisiones en esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		if ( ! class_exists( 'CLMS_Peer_Review' ) ) {
			return new WP_Error( 'module_missing', __( 'Módulo de revisión entre pares no disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}

		$result = CLMS_Peer_Review::assign_for_lesson( $lesson_id, $per_student );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array(
			'lesson_id' => $lesson_id,
			'assigned'  => $result['assigned'],
			'skipped'   => $result['skipped'],
		) );
	}

	public function submit_peer_review( WP_REST_Request $request ) {
		$assignment_id = absint( $request['assignment_id'] );
		$reviewer_id   = get_current_user_id();

		$assignment = get_post( $assignment_id );
		if ( ! $assignment || 'clms_peer_review' !== $assignment->post_type ) {
			return new WP_Error( 'not_found', __( 'Asignación no encontrada.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		$reviewer_meta = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
		if ( $reviewer_meta !== $reviewer_id ) {
			return new WP_Error( 'rest_forbidden', __( 'No estás asignado a esta revisión.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$status = get_post_meta( $assignment_id, '_clms_pr_status', true );
		if ( 'completed' === $status ) {
			return new WP_Error( 'already_submitted', __( 'Ya enviaste esta revisión.', 'atora-lms' ), array( 'status' => 409 ) );
		}

		$lesson_id = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		if ( class_exists( 'CLMS_Peer_Review' ) && CLMS_Peer_Review::is_training_required_for_lesson( $lesson_id ) && ! CLMS_Peer_Review::is_reviewer_trained( $reviewer_id ) ) {
			return new WP_Error( 'training_required', __( 'Debes completar el entrenamiento de revisión antes de enviar evaluaciones entre pares.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data    = $this->get_json_or_body_params( $request );
		$scores  = CLMS_Peer_Review::validate_scores_for_assignment(
			$assignment_id,
			isset( $data['scores'] ) && is_array( $data['scores'] ) ? $data['scores'] : array()
		);
		$comment = isset( $data['comment'] ) ? sanitize_textarea_field( $data['comment'] ) : '';

		if ( is_wp_error( $scores ) ) {
			return $scores;
		}

		update_post_meta( $assignment_id, '_clms_pr_scores', $scores );
		update_post_meta( $assignment_id, '_clms_pr_comment', $comment );
		update_post_meta( $assignment_id, '_clms_pr_status', 'completed' );
		update_post_meta( $assignment_id, '_clms_pr_submitted_at', gmdate( 'Y-m-d H:i:s' ) );

		wp_update_post( array( 'ID' => $assignment_id, 'post_status' => 'publish' ) );

		$submission_id = absint( get_post_meta( $assignment_id, '_clms_pr_submission_id', true ) );
		do_action( 'clms_peer_review_completed', $assignment_id, $submission_id );

		return rest_ensure_response( array( 'assignment_id' => $assignment_id, 'status' => 'completed' ) );
	}

	public function get_my_peer_assignments( WP_REST_Request $request ) {
		unset( $request );
		$reviewer_id = get_current_user_id();

		$assignments = get_posts( array(
			'post_type'      => 'clms_peer_review',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 50,
			'meta_query'     => array(
				array(
					'key'   => '_clms_pr_reviewer_id',
					'value' => $reviewer_id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		$rows = array();
		foreach ( $assignments as $assignment ) {
			$rows[] = $this->format_peer_assignment( $assignment );
		}

		return rest_ensure_response( array( 'assignments' => $rows ) );
	}

	protected function format_peer_assignment( WP_Post $post ) {
		$submission_id = absint( get_post_meta( $post->ID, '_clms_pr_submission_id', true ) );
		$reviewer_id   = absint( get_post_meta( $post->ID, '_clms_pr_reviewer_id', true ) );
		$lesson_id     = absint( get_post_meta( $post->ID, '_clms_pr_lesson_id', true ) );

		return array(
			'id'            => $post->ID,
			'lesson_id'     => $lesson_id,
			'submission_id' => $submission_id,
			'reviewer_id'   => $reviewer_id,
			'status'        => get_post_meta( $post->ID, '_clms_pr_status', true ) ?: 'pending',
			'scores'        => (array) get_post_meta( $post->ID, '_clms_pr_scores', true ),
			'comment'       => get_post_meta( $post->ID, '_clms_pr_comment', true ) ?: '',
			'quality_score' => absint( get_post_meta( $post->ID, '_clms_pr_quality_score', true ) ),
			'quality_status'=> sanitize_key( (string) get_post_meta( $post->ID, '_clms_pr_quality_status', true ) ),
			'submitted_at'  => get_post_meta( $post->ID, '_clms_pr_submitted_at', true ) ?: null,
			'created_at'    => $post->post_date_gmt,
		);
	}

	/* --------------------------------------------------------------
	 * WEBHOOKS
	 * -------------------------------------------------------------- */

	public function get_webhooks( WP_REST_Request $request ) {
		unset( $request );

		// PT-1 (6.5.6): defensa en profundidad — igual que el resto de
		// este controlador re-verifica el permiso dentro del handler en
		// vez de confiar solo en el permission_callback de la ruta.
		if ( ! $this->permissions->can_manage_webhooks() ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para ver los webhooks.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$hooks = get_option( '_clms_webhooks', array() );

		$response_hooks = array();
		foreach ( (array) $hooks as $hook ) {
			if ( ! is_array( $hook ) ) {
				continue;
			}
			$response_hooks[] = $this->sanitize_webhook_for_response( $hook );
		}

		return rest_ensure_response( array( 'webhooks' => $response_hooks ) );
	}

	public function create_webhook( WP_REST_Request $request ) {
		if ( ! $this->permissions->can_manage_webhooks() ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para crear webhooks.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$data  = $this->get_json_or_body_params( $request );
		$url   = isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
		$event = isset( $data['event'] ) ? $this->sanitize_webhook_event( (string) $data['event'] ) : '';

		$allowed_events = array(
			'lesson.completed', 'course.completed', 'enrollment.created',
			'submission.created', 'grade.updated', 'certificate.issued',
			'peer_review.completed',
		);

		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', __( 'URL del webhook inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $event, $allowed_events, true ) ) {
			return new WP_Error( 'invalid_event', __( 'Evento no válido. Eventos permitidos: ', 'atora-lms' ) . implode( ', ', $allowed_events ), array( 'status' => 400 ) );
		}

		$hooks = get_option( '_clms_webhooks', array() );
		$id    = $this->generate_webhook_id();
		$secret = isset( $data['secret'] ) ? sanitize_text_field( (string) $data['secret'] ) : '';
		if ( '' === $secret ) {
			$secret = wp_generate_password( 24, false );
		}

		$hooks[ $id ] = array(
			'id'         => $id,
			'url'        => $url,
			'event'      => $event,
			'secret'     => $secret,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		update_option( '_clms_webhooks', $hooks );

		return rest_ensure_response( $this->sanitize_webhook_for_response( $hooks[ $id ] ) );
	}

	public function delete_webhook( WP_REST_Request $request ) {
		if ( ! $this->permissions->can_manage_webhooks() ) {
			return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para eliminar webhooks.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$id    = $this->sanitize_webhook_id( (string) $request['id'] );
		$hooks = get_option( '_clms_webhooks', array() );

		if ( '' === $id ) {
			return new WP_Error( 'invalid_id', __( 'Identificador de webhook inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! isset( $hooks[ $id ] ) ) {
			return new WP_Error( 'not_found', __( 'Webhook no encontrado.', 'atora-lms' ), array( 'status' => 404 ) );
		}

		unset( $hooks[ $id ] );
		update_option( '_clms_webhooks', $hooks );

		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	/* --------------------------------------------------------------
	 * ME (current user)
	 * -------------------------------------------------------------- */

	public function get_me( WP_REST_Request $request ) {
		unset( $request );
		$user = wp_get_current_user();

		return rest_ensure_response( array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'roles'        => (array) $user->roles,
			'is_teacher'   => CLMS_Access::can_view_teacher_dashboard(),
			'is_admin'     => CLMS_Access::can_access_admin(),
			'member_since' => $user->user_registered,
		) );
	}

	public function get_my_profile( WP_REST_Request $request ) {
		unset( $request );
		$user_id = get_current_user_id();

		return rest_ensure_response(
			array(
				'profile' => $this->get_student_profile_payload( $user_id ),
				'meta'    => array(
					'profile_url' => $this->get_student_profile_url( $user_id ),
				),
			)
		);
	}

	public function update_my_profile( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$data    = $this->get_json_or_body_params( $request );

		$profile = $this->update_student_profile_payload( $user_id, $data );

		if ( is_wp_error( $profile ) ) {
			return $profile;
		}

		$dashboard = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Dashboard') : null;
		if ( $dashboard && method_exists( $dashboard, 'invalidate_cache_for_user' ) ) {
			$dashboard->invalidate_cache_for_user( $user_id );
		}

		return rest_ensure_response(
			array(
				'profile' => $profile,
			)
		);
	}

	public function get_my_courses( WP_REST_Request $request ) {
		unset( $request );
		$user_id    = get_current_user_id();
		$course_ids = class_exists( 'CLMS_Helper' )
			? ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) )
			: array();

		if ( empty( $course_ids ) ) {
			return rest_ensure_response( array( 'courses' => array(), 'total' => 0 ) );
		}

		$posts = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'post__in'       => $course_ids,
			'orderby'        => 'post__in',
		) );

		$completed_lessons = (array) get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed_lessons = array_map( 'absint', $completed_lessons );

		$courses = array();
		foreach ( $posts as $course ) {
			$lessons   = class_exists( 'CLMS_Helper' ) ? (array) CLMS_Helper::get_course_lessons( $course->ID ) : array();
			$total     = count( $lessons );
			$completed = 0;

			foreach ( $lessons as $lesson ) {
				$lesson_id = is_object( $lesson ) ? $lesson->ID : absint( $lesson );
				if ( in_array( $lesson_id, $completed_lessons, true ) ) {
					++$completed;
				}
			}

			$progress = $total > 0 ? round( ( $completed / $total ) * 100 ) : 0;

			$courses[] = array(
				'id'                => $course->ID,
				'title'             => $course->post_title,
				'slug'              => $course->post_name,
				'url'               => get_permalink( $course->ID ),
				'thumbnail'         => get_the_post_thumbnail_url( $course->ID, 'medium' ) ?: null,
				'progress'          => $progress,
				'completed'         => 100 === $progress,
				'total_lessons'     => $total,
				'completed_lessons' => $completed,
			);
		}

		return rest_ensure_response( array( 'courses' => $courses, 'total' => count( $courses ) ) );
	}

	public function get_my_programs( WP_REST_Request $request ) {
		unset( $request );
		$user_id  = get_current_user_id();
		$programs = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::get_user_program_curriculum_map( $user_id )
			: array();

		return rest_ensure_response(
			array(
				'programs' => $programs,
				'total'    => count( $programs ),
			)
		);
	}

	protected function get_student_profile_payload( $user_id ) {
		$user_id = absint( $user_id );

		return array(
			'interests' => (string) get_user_meta( $user_id, '_clms_student_interests', true ),
			'level'     => (string) get_user_meta( $user_id, '_clms_student_level', true ),
			'goals'     => (string) get_user_meta( $user_id, '_clms_student_goals', true ),
			'area'      => (string) get_user_meta( $user_id, '_clms_student_area', true ),
			'consent'   => (bool) get_user_meta( $user_id, '_clms_student_personalization_consent', true ),
			'bio'       => (string) get_user_meta( $user_id, 'description', true ),
		);
	}

	protected function update_student_profile_payload( $user_id, $data ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return new WP_Error( 'clms_profile_invalid_user', __( 'Usuario inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$data = is_array( $data ) ? $data : array();

		$updates = array();

		if ( array_key_exists( 'interests', $data ) ) {
			$updates['_clms_student_interests'] = $this->sanitize_profile_textarea( $data['interests'], 400 );
		}

		if ( array_key_exists( 'level', $data ) ) {
			$level = sanitize_key( (string) $data['level'] );
			$updates['_clms_student_level'] = $this->sanitize_profile_level( $level );
		}

		if ( array_key_exists( 'goals', $data ) ) {
			$updates['_clms_student_goals'] = $this->sanitize_profile_textarea( $data['goals'], 400 );
		}

		if ( array_key_exists( 'area', $data ) ) {
			$updates['_clms_student_area'] = sanitize_text_field( (string) $data['area'] );
		}

		if ( array_key_exists( 'consent', $data ) ) {
			$updates['_clms_student_personalization_consent'] = $this->sanitize_bool( $data['consent'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'bio', $data ) ) {
			$bio = $this->sanitize_profile_textarea( $data['bio'], 300 );
			update_user_meta( $user_id, 'description', $bio );
		}

		foreach ( $updates as $meta_key => $value ) {
			update_user_meta( $user_id, $meta_key, $value );
		}

		return $this->get_student_profile_payload( $user_id );
	}

	protected function sanitize_profile_textarea( $value, $max = 400 ) {
		$max   = max( 50, absint( $max ) );
		$value = sanitize_textarea_field( (string) $value );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}

		return substr( $value, 0, $max );
	}

	protected function sanitize_profile_level( $value ) {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'principiante', 'intermedio', 'avanzado' );

		if ( ! $value || ! in_array( $value, $allowed, true ) ) {
			return '';
		}

		return $value;
	}

	protected function get_student_profile_url( $user_id ) {
		$user_id = absint( $user_id );
		$url     = '';

		if ( function_exists( 'clms_core' ) && clms_core() && method_exists( clms_core(), 'get_module' ) ) {
			$student_profile = clms_core()->get_module( 'CLMS_Student_Profile' );
			if ( $student_profile && method_exists( $student_profile, 'get_profile_url' ) ) {
				$url = $student_profile->get_profile_url( $user_id );
			}
		}

		return apply_filters( 'clms_student_profile_url', $url, $user_id );
	}

	/* --------------------------------------------------------------
	 * HELPERS
	 * -------------------------------------------------------------- */

	protected function get_json_or_body_params( WP_REST_Request $request ) {
		$data = $request->get_json_params();

		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = $request->get_body_params();
		}

		return is_array( $data ) ? $data : array();
	}

	protected function generate_webhook_id() {
		return 'wh_' . wp_generate_uuid4();
	}

	protected function sanitize_webhook_id( $value ) {
		$value = (string) $value;
		$sanitized = preg_replace( '/[^A-Za-z0-9._-]/', '', $value );
		return is_string( $sanitized ) ? $sanitized : '';
	}

	protected function sanitize_webhook_for_response( array $hook ) {
		$secret = isset( $hook['secret'] ) ? (string) $hook['secret'] : '';

		return array(
			'id'            => isset( $hook['id'] ) ? $this->sanitize_webhook_id( (string) $hook['id'] ) : '',
			'url'           => isset( $hook['url'] ) ? esc_url_raw( (string) $hook['url'] ) : '',
			'event'         => isset( $hook['event'] ) ? $this->sanitize_webhook_event( (string) $hook['event'] ) : '',
			'secret'        => $this->mask_secret( $secret ),
			'secret_masked' => '' !== $secret,
			'created_at'    => isset( $hook['created_at'] ) ? sanitize_text_field( (string) $hook['created_at'] ) : '',
		);
	}

	protected function sanitize_webhook_event( string $event ): string {
		$event = strtolower( trim( $event ) );
		$event = preg_replace( '/[^a-z0-9._-]/', '', $event );
		return is_string( $event ) ? $event : '';
	}

	protected function mask_secret( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $secret ) : strlen( $secret );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		$visible = function_exists( 'mb_substr' ) ? mb_substr( $secret, -4 ) : substr( $secret, -4 );
		return str_repeat( '*', max( 4, $length - 4 ) ) . $visible;
	}

	protected function get_lesson_video_source_slug( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		$extra     = get_post_meta( $lesson_id, '_clms_lesson_extra_videos', true );

		if ( is_array( $extra ) && ! empty( $extra[0]['source'] ) ) {
			return sanitize_key( (string) $extra[0]['source'] );
		}

		$legacy = get_post_meta( $lesson_id, '_clms_lesson_video_source', true );
		return $legacy ? sanitize_key( (string) $legacy ) : 'youtube';
	}

	protected function has_whisper_key_configured() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_whisper_api_key' ) ) {
			return '' !== trim( (string) CLMS_AI_Settings_Service::get_whisper_api_key() );
		}

		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_whisper_key' ) ) {
			return '' !== trim( (string) CLMS_Settings::get_whisper_key() );
		}

		$options = (array) get_option( 'clms_ai_settings', array() );
		$whisper = trim( (string) ( $options['whisper_api_key'] ?? '' ) );

		return '' !== $whisper;
	}

	protected function sanitize_per_page( $value ) {
		$value = absint( $value );

		if ( $value < 1 ) {
			$value = 20;
		}

		if ( $value > 100 ) {
			$value = 100;
		}

		return $value;
	}

	protected function sanitize_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) absint( $value );
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
	}

	protected function current_user_can_manage_post_resource( $post_id ) {
		return $this->permissions->current_user_can_manage_post_resource( $post_id );
	}
}
