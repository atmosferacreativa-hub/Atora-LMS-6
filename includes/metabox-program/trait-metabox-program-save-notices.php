<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Metabox_Program_Save_Notices_Trait {
	public function save_meta_boxes( $post_id, $post ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! $post || 'lm_program' !== $post->post_type ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			return;
		}

		if ( empty( $_POST['clms_program_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['clms_program_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'clms_save_program_meta' ) ) {
			return;
		}

		$subtitle = isset( $_POST['_clms_program_subtitle'] ) ? sanitize_text_field( wp_unslash( $_POST['_clms_program_subtitle'] ) ) : '';
		$duration = isset( $_POST['_clms_program_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['_clms_program_duration'] ) ) : '';
		$courses  = isset( $_POST['clms_program_course_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['clms_program_course_ids'] ) ) : array();

		update_post_meta( $post_id, '_clms_program_subtitle', $subtitle );
		update_post_meta( $post_id, '_clms_program_duration', $duration );
		CLMS_Helper::sync_program_courses( $post_id, $courses );
		foreach ( $this->get_program_academic_fields() as $field_key => $field_config ) {
			if ( ! isset( $_POST[ $field_key ] ) ) {
				continue;
			}

			$raw  = wp_unslash( $_POST[ $field_key ] );
			$type = isset( $field_config['type'] ) ? (string) $field_config['type'] : 'text';
			$value = '';

			if ( 'select' === $type ) {
				$options  = isset( $field_config['options'] ) && is_array( $field_config['options'] ) ? array_keys( $field_config['options'] ) : array();
				$selected = sanitize_key( (string) $raw );
				$value    = in_array( $selected, $options, true ) ? $selected : '';
			} elseif ( 'textarea' === $type ) {
				$value = sanitize_textarea_field( $raw );
			} else {
				$value = sanitize_text_field( $raw );
			}

			update_post_meta( $post_id, $field_key, $value );
		}

		$diagnostics_service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Academic_Diagnostics_Service') : null;
		if ( ! $diagnostics_service && class_exists( 'CLMS_Academic_Diagnostics_Service' ) ) {
			$diagnostics_service = new CLMS_Academic_Diagnostics_Service();
		}
		if ( $diagnostics_service && method_exists( $diagnostics_service, 'diagnose_program' ) ) {
			$diag     = (array) $diagnostics_service->diagnose_program( $post_id );
			$warnings = isset( $diag['warnings'] ) && is_array( $diag['warnings'] ) ? $diag['warnings'] : array();
			$errors   = isset( $diag['errors'] ) && is_array( $diag['errors'] ) ? $diag['errors'] : array();
			$ready    = ! empty( $diag['program_ready'] );
			$notice   = '';
			$type     = 'info';

			if ( ! empty( $errors ) ) {
				$type = 'error';
				$notice = sprintf(
					/* translators: 1: errores, 2: advertencias */
					__( 'Diagnóstico del programa: %1$d errores críticos y %2$d advertencias detectadas.', 'atora-lms' ),
					count( $errors ),
					count( $warnings )
				);
			} elseif ( ! empty( $warnings ) ) {
				$type = 'warning';
				$notice = sprintf(
					/* translators: %d: advertencias */
					__( 'Diagnóstico del programa: %d advertencias en cursos asociados.', 'atora-lms' ),
					count( $warnings )
				);
			} elseif ( $ready ) {
				$type = 'success';
				$notice = __( 'Diagnóstico del programa: configuración académica lista para certificar.', 'atora-lms' );
			}

			if ( '' !== $notice ) {
				$this->add_diagnostics_admin_notice( $post_id, $type, $notice );
			}
		}

		if ( ! empty( $_POST['clms_program_commercial_nonce'] ) ) {
			$commercial_nonce = sanitize_text_field( wp_unslash( $_POST['clms_program_commercial_nonce'] ) );

			if ( wp_verify_nonce( $commercial_nonce, 'clms_save_program_commercial' ) ) {
				$mode = isset( $_POST['_clms_commercial_mode'] ) ? sanitize_key( wp_unslash( $_POST['_clms_commercial_mode'] ) ) : 'enrolled';
				update_post_meta( $post_id, '_clms_commercial_mode', in_array( $mode, array( 'enrolled', 'commercial' ), true ) ? $mode : 'enrolled' );

				$video_source = isset( $_POST['_clms_commercial_hero_video_source'] ) ? sanitize_key( wp_unslash( $_POST['_clms_commercial_hero_video_source'] ) ) : 'youtube';
				update_post_meta( $post_id, '_clms_commercial_hero_video_source', in_array( $video_source, array( 'youtube', 'vimeo', 'url' ), true ) ? $video_source : 'youtube' );

				$url_fields = array(
					'_clms_commercial_hero_video',
					'_clms_commercial_cta_url',
				);

				foreach ( $url_fields as $meta_key ) {
					if ( isset( $_POST[ $meta_key ] ) ) {
						update_post_meta( $post_id, $meta_key, esc_url_raw( wp_unslash( $_POST[ $meta_key ] ) ) );
					}
				}

				$text_fields = array(
					'_clms_program_price',
					'_clms_program_price_label',
					'_clms_commercial_tagline',
					'_clms_commercial_cta_label',
					'_clms_commercial_related_product_ids',
					'_clms_commercial_related_course_ids',
					'_clms_commercial_related_program_ids',
				);

				foreach ( $text_fields as $meta_key ) {
					if ( isset( $_POST[ $meta_key ] ) ) {
						update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ) );
					}
				}
			}
		}

		$teacher_nonce = isset( $_POST['clms_program_teacher_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_program_teacher_nonce'] ) ) : '';
		$teacher_ids   = isset( $_POST['clms_program_teacher_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['clms_program_teacher_ids'] ) ) : array();
		$teacher_ids   = array_values( array_unique( array_filter( $teacher_ids ) ) );

		$teacher_ids = array_values(
			array_filter(
				$teacher_ids,
				static function( $teacher_id ) {
					return $teacher_id && 'atora_teacher' === get_post_type( $teacher_id );
				}
			)
		);

		$new_name      = isset( $_POST['clms_new_program_teacher_name'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_new_program_teacher_name'] ) ) : '';
		$new_short_bio = isset( $_POST['clms_new_program_teacher_short_bio'] ) ? sanitize_textarea_field( wp_unslash( $_POST['clms_new_program_teacher_short_bio'] ) ) : '';
		$new_specialty = isset( $_POST['clms_new_program_teacher_specialty'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_new_program_teacher_specialty'] ) ) : '';
		$new_public    = ! empty( $_POST['clms_new_program_teacher_public'] ) ? '1' : '0';
		$has_new_data  = ( '' !== trim( (string) $new_name ) ) || ( '' !== trim( (string) $new_short_bio ) ) || ( '' !== trim( (string) $new_specialty ) );

		if ( $teacher_nonce && wp_verify_nonce( $teacher_nonce, 'clms_save_program_teachers' ) ) {
			if ( $has_new_data ) {
				$instructor = clms_core('CLMS_Instructor');
				if ( ! $instructor && class_exists( 'CLMS_Instructor' ) ) {
					$instructor = new CLMS_Instructor();
				}
				if ( $instructor && method_exists( $instructor, 'create_teacher_from_metabox' ) ) {
					$result = $instructor->create_teacher_from_metabox(
						array(
							'name'      => $new_name,
							'short_bio' => $new_short_bio,
							'specialty' => $new_specialty,
							'public'    => $new_public,
						)
					);
					if ( ! empty( $result['success'] ) && ! empty( $result['teacher_id'] ) ) {
						$teacher_id = absint( $result['teacher_id'] );
						if ( $teacher_id && ! in_array( $teacher_id, $teacher_ids, true ) ) {
							$teacher_ids[] = $teacher_id;
						}
						$this->add_admin_notice(
							$post_id,
							'success',
							$result['reused']
								? __( 'Docente reutilizado y asociado correctamente.', 'atora-lms' )
								: __( 'Docente creado y asociado correctamente.', 'atora-lms' )
						);
					} else {
						$this->add_admin_notice(
							$post_id,
							'error',
							isset( $result['message'] ) && $result['message'] ? $result['message'] : __( 'No se pudo crear el docente.', 'atora-lms' )
						);
					}
				} else {
					$this->add_admin_notice( $post_id, 'error', __( 'El módulo de docentes no está disponible.', 'atora-lms' ) );
				}
			} elseif ( '' === trim( (string) $new_name ) && ( '' !== trim( (string) $new_short_bio ) || '' !== trim( (string) $new_specialty ) ) ) {
				$this->add_admin_notice( $post_id, 'error', __( 'Para crear un docente nuevo debes indicar el nombre.', 'atora-lms' ) );
			}

			update_post_meta( $post_id, '_clms_program_teacher_ids', $teacher_ids );
		} elseif ( $has_new_data || isset( $_POST['clms_program_teacher_ids'] ) ) {
			$this->add_admin_notice( $post_id, 'error', __( 'No se pudieron guardar los docentes. Intenta nuevamente.', 'atora-lms' ) );
		}
	}

	protected function get_notice_key( $post_id ) {
		return 'clms_program_teacher_notice_' . absint( get_current_user_id() ) . '_' . absint( $post_id );
	}

	protected function get_diagnostics_notice_key( $post_id ) {
		return 'clms_program_diagnostics_notice_' . absint( get_current_user_id() ) . '_' . absint( $post_id );
	}

	protected function add_admin_notice( $post_id, $type, $message ) {
		$post_id = absint( $post_id );
		$user_id = absint( get_current_user_id() );
		if ( ! $post_id || ! $user_id || ! $message ) {
			return;
		}
		set_transient(
			$this->get_notice_key( $post_id ),
			array(
				'type'    => sanitize_key( $type ),
				'message' => sanitize_text_field( $message ),
			),
			90
		);
	}

	protected function add_diagnostics_admin_notice( $post_id, $type, $message ) {
		$post_id = absint( $post_id );
		$user_id = absint( get_current_user_id() );
		if ( ! $post_id || ! $user_id || ! $message ) {
			return;
		}
		set_transient(
			$this->get_diagnostics_notice_key( $post_id ),
			array(
				'type'    => sanitize_key( $type ),
				'message' => sanitize_text_field( $message ),
			),
			90
		);
	}

	public function render_admin_notices() {
		if ( ! is_admin() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'lm_program' !== $screen->post_type ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( ! $post_id ) {
			return;
		}
		$notice = get_transient( $this->get_notice_key( $post_id ) );
		if ( empty( $notice['message'] ) ) {
			$notice = array();
		}

		if ( ! empty( $notice['message'] ) ) {
			delete_transient( $this->get_notice_key( $post_id ) );
			$type = ! empty( $notice['type'] ) ? $notice['type'] : 'info';
			$allowed = array( 'success', 'error', 'warning', 'info' );
			if ( ! in_array( $type, $allowed, true ) ) {
				$type = 'info';
			}
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}

		$diag_notice = get_transient( $this->get_diagnostics_notice_key( $post_id ) );
		if ( empty( $diag_notice['message'] ) ) {
			return;
		}
		delete_transient( $this->get_diagnostics_notice_key( $post_id ) );
		$diag_type = ! empty( $diag_notice['type'] ) ? $diag_notice['type'] : 'info';
		$allowed = array( 'success', 'error', 'warning', 'info' );
		if ( ! in_array( $diag_type, $allowed, true ) ) {
			$diag_type = 'info';
		}
		echo '<div class="notice notice-' . esc_attr( $diag_type ) . ' is-dismissible"><p>' . esc_html( $diag_notice['message'] ) . '</p></div>';
	}

	// ── Matrículas ────────────────────────────────────────────────────────────

}
