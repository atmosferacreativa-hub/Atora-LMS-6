<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Submission_Files_Assets_Trait {
	protected function handle_uploaded_files( $submission_id, $user_id, $files_data ) {
		$submission_id = absint( $submission_id );
		$user_id       = absint( $user_id );

		if ( empty( $files_data ) || empty( $files_data['name'] ) ) {
			return array();
		}

		$names = is_array( $files_data['name'] ) ? $files_data['name'] : array();

		$valid_count = 0;
		foreach ( $names as $name ) {
			if ( '' !== trim( (string) $name ) ) {
				$valid_count++;
			}
		}

		if ( $valid_count > $this->max_files ) {
			return new WP_Error(
				'too_many_files',
				sprintf( 'Solo puedes subir hasta %d archivos por entrega.', $this->max_files )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$uploaded_ids = array();

		foreach ( $names as $index => $original_name ) {
			$original_name = (string) $original_name;

			if ( '' === trim( $original_name ) ) {
				continue;
			}

			$error = isset( $files_data['error'][ $index ] ) ? absint( $files_data['error'][ $index ] ) : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue;
			}

			if ( UPLOAD_ERR_OK !== $error ) {
				return new WP_Error( 'upload_error', __( 'Uno de los archivos no pudo subirse correctamente.', 'atora-lms' ) );
			}

			$tmp_name = isset( $files_data['tmp_name'][ $index ] ) ? (string) $files_data['tmp_name'][ $index ] : '';
			$size     = isset( $files_data['size'][ $index ] ) ? absint( $files_data['size'][ $index ] ) : 0;

			if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
				return new WP_Error( 'invalid_upload', __( 'Se detectó un archivo inválido.', 'atora-lms' ) );
			}

			if ( $size > $this->max_file_size ) {
				return new WP_Error(
					'file_too_large',
					sprintf( 'Cada archivo debe pesar máximo %s.', size_format( $this->max_file_size ) )
				);
			}

			$check = wp_check_filetype_and_ext( $tmp_name, $original_name, $this->allowed_mimes );
			$ext   = ! empty( $check['ext'] ) ? (string) $check['ext'] : '';

			// Los formatos Office/OpenDocument modernos son contenedores ZIP y
			// algunas instalaciones (finfo) los detectan como "application/zip".
			// Si el nombre original tiene una extensión Office/ODF permitida,
			// se respeta esa extensión en lugar de rechazar el archivo.
			if ( '' === $ext || 'zip' === $ext ) {
				$office_exts = array( 'docx', 'odt' );
				$name_check  = wp_check_filetype( $original_name, $this->allowed_mimes );
				$name_ext    = ! empty( $name_check['ext'] ) ? (string) $name_check['ext'] : '';

				if ( in_array( $name_ext, $office_exts, true ) ) {
					$ext = $name_ext;
				}
			}

			if ( ! $ext || ! isset( $this->allowed_mimes[ $ext ] ) ) {
				return new WP_Error( 'invalid_file_type', __( 'Uno de los archivos tiene un formato no permitido.', 'atora-lms' ) );
			}

			$file_array = array(
				'name'     => sanitize_file_name( $original_name ),
				'tmp_name' => $tmp_name,
			);

			$attachment_id = media_handle_sideload( $file_array, $submission_id );

			if ( is_wp_error( $attachment_id ) ) {
				return new WP_Error( 'attachment_error', __( 'No se pudo guardar uno de los archivos adjuntos.', 'atora-lms' ) );
			}

			update_post_meta( $attachment_id, '_clms_submission_owner', $user_id );
			update_post_meta( $attachment_id, '_clms_submission_id', $submission_id );

			$uploaded_ids[] = absint( $attachment_id );
		}

		return $uploaded_ids;
	}

	protected function enqueue_assets() {
		if ( self::$assets_enqueued ) {
			return;
		}
		self::$assets_enqueued = true;

		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;

		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}
	}

	/**
	 * Resuelve curso desde la lección.
	 *
	 * @param int $lesson_id Lección.
	 * @return int
	 */
	protected function get_course_id_for_lesson( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id ) {
			return 0;
		}

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			return absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		return 0;
	}
}
