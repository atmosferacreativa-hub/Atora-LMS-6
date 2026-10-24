<?php
/**
 * Persistencia versionada de la Biblioteca académica.
 *
 * @package ATORA_LMS
 * @since 6.24.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../scorm/class-scorm-package-service.php';

class CLMS_Academic_Library_Service {

	public function create_item( $data, $actor_id = 0 ) {
		global $wpdb;

		$data = is_array( $data ) ? $data : array();
		$institution_id = $this->require_institution_id_from_data( $data );
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $data['slug'] ?? $title ) );
		if ( '' === $title || '' === $slug ) {
			return new WP_Error( 'clms_library_invalid_item', __( 'Título y slug son obligatorios.', 'atora-lms' ) );
		}

		$actor_id = absint( $actor_id ?: get_current_user_id() );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'atora_library_items',
			array(
				'institution_id'=> absint( $institution_id ),
				'slug'          => $slug,
				'title'         => $title,
				'description'   => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'resource_type' => CLMS_Academic_Library_Policy::normalize_resource_type( $data['resource_type'] ?? '' ),
				'status'        => 'draft',
				'created_by'    => $actor_id,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'clms_library_item_insert_failed', __( 'No se pudo crear el recurso o el slug ya existe.', 'atora-lms' ) );
		}

		$item_id = (int) $wpdb->insert_id;
		$this->log_event( 'item_created', $item_id, array( 'slug' => $slug ), $actor_id );
		if ( ! empty( $data['version'] ) && is_array( $data['version'] ) ) {
			$version = $this->add_version( $item_id, $data['version'], $actor_id );
			if ( is_wp_error( $version ) ) {
				$this->cleanup_failed_item( $item_id );
				return $version;
			}
		}
		if ( ! empty( $data['links'] ) && is_array( $data['links'] ) ) {
			$links = $this->replace_links( $item_id, $data['links'], $actor_id );
			if ( is_wp_error( $links ) ) {
				$this->cleanup_failed_item( $item_id );
				return $links;
			}
		}
		return $this->get_item( $item_id );
	}

	public function add_version( $item_id, $data, $actor_id = 0 ) {
		global $wpdb;

		$item = $this->get_item_row( $item_id );
		if ( empty( $item ) || 'archived' === $item['status'] ) {
			return new WP_Error( 'clms_library_item_locked', __( 'El recurso no existe o está archivado.', 'atora-lms' ) );
		}

		$data          = is_array( $data ) ? $data : array();
		$attachment_id = absint( $data['attachment_id'] ?? 0 );
		$content_url   = esc_url_raw( (string) ( $data['content_url'] ?? '' ) );
		if ( ! $attachment_id && '' === $content_url ) {
			return new WP_Error( 'clms_library_version_source_required', __( 'La versión requiere un adjunto o una URL.', 'atora-lms' ) );
		}

		$last_version = absint( $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version_number) FROM {$wpdb->prefix}atora_library_versions WHERE item_id = %d", absint( $item_id ) ) ) );
		$metadata     = is_array( $data['metadata'] ?? null ) ? $data['metadata'] : array();
		$payload      = array(
			'attachment_id' => $attachment_id,
			'content_url'   => $content_url,
			'mime_type'     => CLMS_Academic_Library_Policy::normalize_mime_type( $data['mime_type'] ?? '' ),
			'metadata'      => $metadata,
		);

		// SCORM: validar e instalar el ZIP antes de crear la versión.
		if ( 'scorm' === (string) ( $item['resource_type'] ?? '' ) ) {
			$prepared = $this->prepare_scorm_payload( $item, $last_version + 1, $payload );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$payload  = $prepared;
			$metadata = is_array( $payload['metadata'] ?? null ) ? (array) $payload['metadata'] : array();
			$content_url = (string) ( $payload['content_url'] ?? '' );
			$attachment_id = absint( $payload['attachment_id'] ?? 0 );
		}
		$actor_id = absint( $actor_id ?: get_current_user_id() );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'atora_library_versions',
			array(
				'item_id'        => absint( $item_id ),
				'version_number' => $last_version + 1,
				'attachment_id'  => $attachment_id,
				'content_url'    => $content_url,
				'mime_type'      => $payload['mime_type'],
				'metadata_json'  => wp_json_encode( $metadata ),
				'checksum_sha256'=> CLMS_Academic_Library_Policy::canonical_hash( $payload ),
				'change_note'    => sanitize_textarea_field( (string) ( $data['change_note'] ?? '' ) ),
				'created_by'     => $actor_id,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'clms_library_version_insert_failed', __( 'No se pudo crear la versión.', 'atora-lms' ) );
		}

		$version_id = (int) $wpdb->insert_id;
		$wpdb->update(
			$wpdb->prefix . 'atora_library_items',
			array( 'current_version_id' => $version_id, 'status' => 'draft' ),
			array( 'id' => absint( $item_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		$this->log_event( 'version_created', $item_id, array( 'version_id' => $version_id, 'version_number' => $last_version + 1, 'checksum_sha256' => CLMS_Academic_Library_Policy::canonical_hash( $payload ) ), $actor_id );
		return array( 'id' => $version_id, 'version_number' => $last_version + 1, 'checksum_sha256' => CLMS_Academic_Library_Policy::canonical_hash( $payload ) );
	}

	/**
	 * @param array<string,mixed> $item DB row de atora_library_items.
	 * @param array{attachment_id:int,content_url:string,mime_type:string,metadata:array<string,mixed>} $payload
	 * @return array{attachment_id:int,content_url:string,mime_type:string,metadata:array<string,mixed>}|WP_Error
	 */
	private function prepare_scorm_payload( array $item, int $version_number, array $payload ) {
		$attachment_id = absint( $payload['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'clms_scorm_attachment_required', __( 'Los paquetes SCORM requieren un adjunto ZIP.', 'atora-lms' ) );
		}
		if ( ! function_exists( 'get_attached_file' ) ) {
			return new WP_Error( 'clms_scorm_wp_required', __( 'No se pudo resolver la ruta del adjunto SCORM.', 'atora-lms' ) );
		}
		$zip_path = (string) get_attached_file( $attachment_id );
		if ( '' === $zip_path || ! file_exists( $zip_path ) ) {
			return new WP_Error( 'clms_scorm_zip_not_found', __( 'El ZIP SCORM no existe en el servidor.', 'atora-lms' ) );
		}
		if ( 'zip' !== strtolower( (string) pathinfo( $zip_path, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'clms_scorm_zip_invalid', __( 'El adjunto SCORM debe ser un ZIP.', 'atora-lms' ) );
		}
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return new WP_Error( 'clms_scorm_wp_required', __( 'No se pudo resolver el directorio de uploads.', 'atora-lms' ) );
		}

		$institution_id = absint( $item['institution_id'] ?? 0 );
		$item_id        = absint( $item['id'] ?? 0 );
		if ( $institution_id <= 0 || $item_id <= 0 ) {
			return new WP_Error( 'clms_scorm_invalid_item', __( 'Recurso SCORM inválido.', 'atora-lms' ) );
		}

		$uploads = wp_upload_dir();
		$basedir = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' );
		$baseurl = rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/\\' );
		if ( '' === $basedir || '' === $baseurl ) {
			return new WP_Error( 'clms_scorm_uploads_unavailable', __( 'No se pudo resolver uploads para SCORM.', 'atora-lms' ) );
		}

		$rel_dir  = 'atora-library/scorm/' . $institution_id . '/' . $item_id . '/v' . $version_number;
		$dest_dir = $basedir . '/' . $rel_dir;

		$installed = CLMS_SCORM_Package_Service::install_zip( $zip_path, $dest_dir );
		if ( is_wp_error( $installed ) ) {
			$this->cleanup_dir( $dest_dir );
			return $installed;
		}

		$launch_href = (string) ( $installed['launch_href'] ?? '' );
		$launch_url  = $baseurl . '/' . $rel_dir . '/' . ltrim( str_replace( '\\', '/', $launch_href ), '/' );
		$launch_url  = esc_url_raw( $launch_url );

		$metadata = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
		$metadata['scorm'] = array(
			'installed_at'      => current_time( 'mysql', true ),
			'scorm_version'     => (string) $installed['scorm_version'],
			'schemaversion_raw' => (string) $installed['schemaversion_raw'],
			'launch_href'       => $launch_href,
			'content_base_url'  => $baseurl . '/' . $rel_dir,
			'launch_url'        => $launch_url,
		);

		$payload['metadata']  = $metadata;
		$payload['mime_type'] = '' !== (string) ( $payload['mime_type'] ?? '' ) ? $payload['mime_type'] : 'application/zip';
		$payload['content_url'] = $launch_url;
		return $payload;
	}

	private function cleanup_dir( string $dir ): void {
		$dir = rtrim( $dir, '/\\' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->cleanup_dir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	public function transition( $item_id, $target_status, $actor_id = 0 ) {
		global $wpdb;

		$item = $this->get_item_row( $item_id );
		$target_status = sanitize_key( (string) $target_status );
		if ( empty( $item ) || ! CLMS_Academic_Library_Policy::can_transition( $item['status'], $target_status ) ) {
			return new WP_Error( 'clms_library_invalid_transition', __( 'La transición editorial no está permitida.', 'atora-lms' ) );
		}
		if ( 'published' === $target_status && empty( $item['current_version_id'] ) ) {
			return new WP_Error( 'clms_library_version_required', __( 'No se puede publicar un recurso sin versión.', 'atora-lms' ) );
		}

		$data = array( 'status' => $target_status );
		$formats = array( '%s' );
		if ( 'published' === $target_status ) {
			$data['published_at'] = current_time( 'mysql', true );
			$data['published_by'] = absint( $actor_id ?: get_current_user_id() );
			$formats[] = '%s';
			$formats[] = '%d';
		}
		$updated = $wpdb->update(
			$wpdb->prefix . 'atora_library_items',
			$data,
			array( 'id' => absint( $item_id ), 'status' => $item['status'] ),
			$formats,
			array( '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'clms_library_concurrent_update', __( 'El recurso cambió durante la operación.', 'atora-lms' ) );
		}
		$this->log_event( 'status_changed', $item_id, array( 'from' => $item['status'], 'to' => $target_status ), $actor_id );
		return $this->get_item( $item_id );
	}

	public function replace_links( $item_id, $links, $actor_id = 0 ) {
		global $wpdb;

		if ( empty( $this->get_item_row( $item_id ) ) ) {
			return new WP_Error( 'clms_library_item_not_found', __( 'Recurso no encontrado.', 'atora-lms' ) );
		}
		$normalized = array();
		foreach ( (array) $links as $link ) {
			$link = is_array( $link ) ? $link : array();
			$type = sanitize_key( (string) ( $link['link_type'] ?? '' ) );
			$course_id = absint( $link['course_id'] ?? 0 );
			$object_key = sanitize_key( (string) ( $link['object_key'] ?? '' ) );
			$object_id = absint( $link['object_id'] ?? 0 );
			if ( ! in_array( $type, CLMS_Academic_Library_Policy::LINK_TYPES, true ) || ! $course_id ) {
				return new WP_Error( 'clms_library_invalid_link', __( 'Cada vínculo requiere tipo y curso válidos.', 'atora-lms' ) );
			}
			if ( 'competency' === $type ) {
				$competencies = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
				if ( '' === $object_key || ! $competencies || empty( $competencies->get_competency( $course_id, $object_key ) ) ) {
					return new WP_Error( 'clms_library_competency_not_found', __( 'La competencia vinculada no existe en el curso.', 'atora-lms' ) );
				}
			} else {
				$evidence = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Evidence_Service') : null;
				$config   = ( $object_id && $evidence && method_exists( $evidence, 'get_activity_evidence_config' ) )
					? (array) $evidence->get_activity_evidence_config( $object_id )
					: array();
				if ( ! $object_id || absint( $config['course_id'] ?? 0 ) !== $course_id ) {
					return new WP_Error( 'clms_library_evidence_not_found', __( 'La actividad de evidencia no pertenece al curso indicado.', 'atora-lms' ) );
				}
			}
			$normalized[] = compact( 'type', 'course_id', 'object_key', 'object_id' );
		}

		$table = $wpdb->prefix . 'atora_library_links';
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->delete( $table, array( 'item_id' => absint( $item_id ) ), array( '%d' ) );
		foreach ( $normalized as $link ) {
			$ok = $wpdb->insert(
				$table,
				array( 'item_id' => absint( $item_id ), 'link_type' => $link['type'], 'course_id' => $link['course_id'], 'object_key' => $link['object_key'], 'object_id' => $link['object_id'] ),
				array( '%d', '%s', '%d', '%s', '%d' )
			);
			if ( ! $ok ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'clms_library_link_insert_failed', __( 'No se pudieron guardar los vínculos académicos.', 'atora-lms' ) );
			}
		}
		$wpdb->query( 'COMMIT' );
		$this->log_event( 'links_replaced', $item_id, array( 'count' => count( $normalized ) ), $actor_id );
		return $normalized;
	}

	public function get_item( $item_id ) {
		global $wpdb;

		$institution_id = $this->require_institution_id_from_data( array() );
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$item = $this->get_item_row( $item_id );
		if ( empty( $item ) ) {
			return array();
		}
		$item['version'] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_versions WHERE id = %d LIMIT 1", absint( $item['current_version_id'] ) ), ARRAY_A );
		$item['links']   = $wpdb->get_results( $wpdb->prepare( "SELECT link_type, course_id, object_key, object_id FROM {$wpdb->prefix}atora_library_links WHERE item_id = %d ORDER BY link_type, id", absint( $item_id ) ), ARRAY_A );
		return $item;
	}

	public function list_items( $status = 'published', $course_id = 0 ) {
		global $wpdb;

		$institution_id = $this->require_institution_id_from_data( array() );
		if ( is_wp_error( $institution_id ) ) {
			return $institution_id;
		}

		$status = sanitize_key( (string) $status );
		if ( $course_id ) {
			return $wpdb->get_results(
				$wpdb->prepare( "SELECT DISTINCT i.* FROM {$wpdb->prefix}atora_library_items i INNER JOIN {$wpdb->prefix}atora_library_links l ON l.item_id = i.id WHERE i.institution_id = %d AND i.status = %s AND l.course_id = %d ORDER BY i.title", absint( $institution_id ), $status, absint( $course_id ) ),
				ARRAY_A
			);
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_items WHERE institution_id = %d AND status = %s ORDER BY title", absint( $institution_id ), $status ), ARRAY_A );
	}

	protected function cleanup_failed_item( $item_id ) {
		global $wpdb;
		$item_id = absint( $item_id );
		$wpdb->delete( $wpdb->prefix . 'atora_library_links', array( 'item_id' => $item_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'atora_library_versions', array( 'item_id' => $item_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'atora_library_events', array( 'item_id' => $item_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'atora_library_items', array( 'id' => $item_id, 'status' => 'draft' ), array( '%d', '%s' ) );
	}

	protected function get_item_row( $item_id ) {
		global $wpdb;

		$institution_id = $this->require_institution_id_from_data( array() );
		if ( is_wp_error( $institution_id ) ) {
			return array();
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_items WHERE institution_id = %d AND id = %d LIMIT 1", absint( $institution_id ), absint( $item_id ) ), ARRAY_A );
	}

	protected function require_institution_id_from_data( array $data ) {
		$institution_id = absint( $data['institution_id'] ?? 0 );

		if ( ! $institution_id && class_exists( '\ATORA\LMS\Tenant_Context' ) ) {
			$resolved = \ATORA\LMS\Tenant_Context::require_current_institution_id();
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$institution_id = absint( $resolved );
		}

		if ( $institution_id <= 0 ) {
			return new WP_Error( 'clms_library_missing_institution', __( 'Institución no resuelta.', 'atora-lms' ) );
		}

		return $institution_id;
	}

	protected function log_event( $action, $item_id, $details, $actor_id ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'atora_library_events',
			array( 'actor_id' => absint( $actor_id ?: get_current_user_id() ), 'action' => sanitize_key( (string) $action ), 'item_id' => absint( $item_id ), 'details_json' => wp_json_encode( is_array( $details ) ? $details : array() ) ),
			array( '%d', '%s', '%d', '%s' )
		);
	}
}
