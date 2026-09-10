<?php
/**
 * H5P — Library Service (versioning + sharing).
 *
 * MVP: registro interno de librerías instaladas/permitidas para habilitar
 * un futuro marketplace/biblioteca (sin depender del storage interno H5P).
 *
 * @package ATORA_LMS
 * @since   6.19.0
 */

namespace ATORA\H5P;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class H5P_Library_Service {

	public function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'atora_h5p_library';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 200, absint( $limit ) ) );
		$offset = max( 0, absint( $offset ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} ORDER BY machine_name ASC, major_version DESC, minor_version DESC, patch_version DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Upsert por machine_name + versión.
	 *
	 * @param array<string,mixed> $payload
	 * @return true|WP_Error
	 */
	public function upsert( array $payload ) {
		global $wpdb;

		$machine = isset( $payload['machine_name'] ) ? sanitize_key( (string) $payload['machine_name'] ) : '';
		$major   = isset( $payload['major_version'] ) ? absint( $payload['major_version'] ) : 0;
		$minor   = isset( $payload['minor_version'] ) ? absint( $payload['minor_version'] ) : 0;
		$patch   = isset( $payload['patch_version'] ) ? absint( $payload['patch_version'] ) : 0;

		if ( '' === $machine || $major <= 0 ) {
			return new WP_Error( 'atora_h5p_invalid_library', __( 'Librería inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$title    = isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : $machine;
		$license  = isset( $payload['license'] ) ? sanitize_text_field( (string) $payload['license'] ) : '';
		$meta_json = null;
		if ( isset( $payload['metadata'] ) ) {
			$meta_json = wp_json_encode( $payload['metadata'] );
			if ( ! is_string( $meta_json ) ) {
				$meta_json = null;
			}
		}

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE machine_name = %s AND major_version = %d AND minor_version = %d AND patch_version = %d LIMIT 1",
				$machine,
				$major,
				$minor,
				$patch
			)
		);

		$data = array(
			'machine_name'  => $machine,
			'major_version' => $major,
			'minor_version' => $minor,
			'patch_version' => $patch,
			'title'         => $title,
			'license'       => $license,
			'metadata_json' => $meta_json,
			'updated_at'    => $now,
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ok = (bool) $wpdb->update( $this->table(), $data, array( 'id' => $existing ) );
			return $ok ? true : new WP_Error( 'atora_h5p_db_error', __( 'No se pudo actualizar la librería.', 'atora-lms' ), array( 'status' => 500 ) );
		}

		$data['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = (bool) $wpdb->insert( $this->table(), $data );
		return $ok ? true : new WP_Error( 'atora_h5p_db_error', __( 'No se pudo registrar la librería.', 'atora-lms' ), array( 'status' => 500 ) );
	}
}

