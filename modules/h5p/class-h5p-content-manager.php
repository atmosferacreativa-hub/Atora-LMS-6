<?php
/**
 * H5P — Content Manager (CRUD) para storage de ATORA.
 *
 * MVP: crea/lee el vínculo entre el CPT `h5p_content` (WordPress) y la tabla
 * `atora_h5p_content` (metadata + JSON).
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Content_Manager {

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_h5p_content';
	}

	/**
	 * Registra CPT `h5p_content` SOLO si no existe (compatibilidad con plugin H5P).
	 */
	public function maybe_register_cpt(): void {
		if ( post_type_exists( 'h5p_content' ) ) {
			return;
		}

		register_post_type(
			'h5p_content',
			array(
				'label'           => __( 'Interactivo H5P', 'atora-lms' ),
				'labels'          => array(
					'name'          => __( 'Interactivo H5P', 'atora-lms' ),
					'singular_name' => __( 'Contenido interactivo H5P', 'atora-lms' ),
					'menu_name'     => __( '🎮 Interactivo H5P', 'atora-lms' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'clms-dashboard',
				'show_in_rest'    => false,
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Obtiene el registro ATORA por WP post_id (h5p_content).
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_by_wp_post_id( int $wp_post_id ): ?array {
		global $wpdb;
		$wp_post_id = absint( $wp_post_id );
		if ( ! $wp_post_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Crea el post (CPT) y garantiza la fila en `atora_h5p_content`.
	 *
	 * @param array<string,mixed> $payload
	 * @return int|WP_Error wp_post_id o error.
	 */
	public function create( array $payload ) {
		$title = isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : '';
		if ( '' === $title ) {
			return new WP_Error( 'atora_h5p_invalid_title', __( 'Título requerido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$wp_post_id = wp_insert_post(
			array(
				'post_type'    => 'h5p_content',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $wp_post_id ) ) {
			return $wp_post_id;
		}

		$ensure = $this->ensure_row( (int) $wp_post_id, $payload );
		if ( is_wp_error( $ensure ) ) {
			return $ensure;
		}

		return (int) $wp_post_id;
	}

	/**
	 * Crea o actualiza la fila de `atora_h5p_content` ligada al post.
	 *
	 * @param int                 $wp_post_id
	 * @param array<string,mixed> $payload
	 * @return true|WP_Error
	 */
	public function ensure_row( int $wp_post_id, array $payload = array() ) {
		global $wpdb;

		$wp_post_id = absint( $wp_post_id );
		if ( ! $wp_post_id ) {
			return new WP_Error( 'atora_h5p_invalid_post', __( 'Post inválido.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$provider    = isset( $payload['provider'] ) ? sanitize_key( (string) $payload['provider'] ) : 'wp_h5p';
		$external_id = isset( $payload['external_id'] ) ? sanitize_text_field( (string) $payload['external_id'] ) : '';
		$visibility  = isset( $payload['visibility'] ) ? sanitize_key( (string) $payload['visibility'] ) : 'private';
		$status      = isset( $payload['status'] ) ? sanitize_key( (string) $payload['status'] ) : 'active';
		$license     = isset( $payload['license'] ) ? sanitize_text_field( (string) $payload['license'] ) : '';

		$content_json = null;
		if ( array_key_exists( 'content_json', $payload ) ) {
			$content_json = wp_json_encode( $payload['content_json'] );
			if ( ! is_string( $content_json ) ) {
				$content_json = null;
			}
		}

		$tags_json = null;
		if ( isset( $payload['tags'] ) ) {
			$tags_json = wp_json_encode( (array) $payload['tags'] );
			if ( ! is_string( $tags_json ) ) {
				$tags_json = null;
			}
		}

		$now      = current_time( 'mysql' );
		$existing = $this->get_by_wp_post_id( $wp_post_id );

		$data = array(
			'wp_post_id'   => $wp_post_id,
			'provider'     => $provider,
			'external_id'  => $external_id,
			'visibility'   => $visibility,
			'status'       => $status,
			'license'      => $license,
			'content_json' => $content_json,
			'tags_json'    => $tags_json,
			'updated_at'   => $now,
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = (bool) $wpdb->update( $this->table(), $data, array( 'wp_post_id' => $wp_post_id ) );
			return $ok ? true : new WP_Error( 'atora_h5p_db_error', __( 'No se pudo actualizar el contenido.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$data['author_id']  = get_current_user_id();
		$data['created_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = (bool) $wpdb->insert( $this->table(), $data );
		return $ok ? true : new WP_Error( 'atora_h5p_db_error', __( 'No se pudo crear el contenido.', 'atora-lms' ), array( 'status' => 500 ) );
	}
}
