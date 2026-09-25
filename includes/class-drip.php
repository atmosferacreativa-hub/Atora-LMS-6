<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Drip {

	const META_TYPE                = '_clms_drip_type';
	const META_DATE                = '_clms_drip_date';
	const META_DAYS_ENROLLED       = '_clms_drip_days_enrolled';
	const META_DAYS_AFTER_PREVIOUS = '_clms_drip_days_after_previous';

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_lm_lesson', array( $this, 'save_metabox' ) );
	}

	public function register_metabox() {
		add_meta_box(
			'clms_lesson_drip',
			'Liberación de la lección',
			array( $this, 'render_metabox' ),
			'lm_lesson',
			'side',
			'default'
		);
	}

	public function render_metabox( $post ) {
		if ( ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_save_lesson_drip', 'clms_lesson_drip_nonce' );

		$drip_type                = (string) get_post_meta( $post->ID, self::META_TYPE, true );
		$drip_date                = (string) get_post_meta( $post->ID, self::META_DATE, true );
		$days_enrolled            = absint( get_post_meta( $post->ID, self::META_DAYS_ENROLLED, true ) );
		$days_after_previous      = absint( get_post_meta( $post->ID, self::META_DAYS_AFTER_PREVIOUS, true ) );

		if ( '' === $drip_type ) {
			$drip_type = 'none';
		}
		?>
		<div class="clms-drip-box">
			<p>
				<label for="clms_drip_type"><strong>Tipo de liberación</strong></label><br>
				<select name="clms_drip_type" id="clms_drip_type" style="width:100%;">
					<option value="none" <?php selected( $drip_type, 'none' ); ?>>Sin restricción</option>
					<option value="date" <?php selected( $drip_type, 'date' ); ?>>Fecha específica</option>
					<option value="days_enrolled" <?php selected( $drip_type, 'days_enrolled' ); ?>>Días después de inscribirse</option>
					<option value="days_after_previous" <?php selected( $drip_type, 'days_after_previous' ); ?>>Días después de completar la anterior</option>
				</select>
			</p>

			<p>
				<label for="clms_drip_date"><strong>Fecha de apertura</strong></label><br>
				<input
					type="date"
					name="clms_drip_date"
					id="clms_drip_date"
					value="<?php echo esc_attr( $drip_date ); ?>"
					style="width:100%;"
				>
			</p>

			<p>
				<label for="clms_drip_days_enrolled"><strong>Días desde inscripción</strong></label><br>
				<input
					type="number"
					min="0"
					step="1"
					name="clms_drip_days_enrolled"
					id="clms_drip_days_enrolled"
					value="<?php echo esc_attr( $days_enrolled ); ?>"
					style="width:100%;"
				>
			</p>

			<p>
				<label for="clms_drip_days_after_previous"><strong>Días desde la lección anterior</strong></label><br>
				<input
					type="number"
					min="0"
					step="1"
					name="clms_drip_days_after_previous"
					id="clms_drip_days_after_previous"
					value="<?php echo esc_attr( $days_after_previous ); ?>"
					style="width:100%;"
				>
			</p>

			<p style="margin:0;color:#666;font-size:12px;">
				Se usa solo el campo que corresponda al tipo elegido.
			</p>
		</div>
		<?php
	}

	public function save_metabox( $post_id ) {
		if ( ! isset( $_POST['clms_lesson_drip_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clms_lesson_drip_nonce'] ) ), 'clms_save_lesson_drip' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			return;
		}

		$allowed_types = array( 'none', 'date', 'days_enrolled', 'days_after_previous' );

		$drip_type = isset( $_POST['clms_drip_type'] ) ? sanitize_key( wp_unslash( $_POST['clms_drip_type'] ) ) : 'none';
		if ( ! in_array( $drip_type, $allowed_types, true ) ) {
			$drip_type = 'none';
		}

		$drip_date           = isset( $_POST['clms_drip_date'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_drip_date'] ) ) : '';
		$days_enrolled       = isset( $_POST['clms_drip_days_enrolled'] ) ? absint( wp_unslash( $_POST['clms_drip_days_enrolled'] ) ) : 0;
		$days_after_previous = isset( $_POST['clms_drip_days_after_previous'] ) ? absint( wp_unslash( $_POST['clms_drip_days_after_previous'] ) ) : 0;

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $drip_date ) ) {
			$drip_date = '';
		}

		update_post_meta( $post_id, self::META_TYPE, $drip_type );
		update_post_meta( $post_id, self::META_DATE, $drip_date );
		update_post_meta( $post_id, self::META_DAYS_ENROLLED, $days_enrolled );
		update_post_meta( $post_id, self::META_DAYS_AFTER_PREVIOUS, $days_after_previous );
	}

	public static function is_lesson_available( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return false;
		}

		if ( self::user_can_manage_lms( $lesson_id ) ) {
			return true;
		}

		$state = self::get_availability_state( $user_id, $lesson_id );

		return ! empty( $state['available'] );
	}

	public static function get_availability_state( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		$default = array(
			'available'    => false,
			'reason'       => 'unknown',
			'message'      => __( 'Esta lección aún no está disponible.', 'atora-lms' ),
			'available_at' => 0,
		);

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			$default['reason']  = 'invalid_lesson';
			$default['message'] = 'La lección no es válida.';
			return $default;
		}

		if ( self::user_can_manage_lms( $lesson_id ) ) {
			return array(
				'available'    => true,
				'reason'       => 'manager',
				'message'      => '',
				'available_at' => 0,
			);
		}

		$course_id = self::get_course_id( $lesson_id );

		if ( $course_id && ! self::user_is_enrolled_in_course( $user_id, $course_id ) ) {
			return array(
				'available'    => false,
				'reason'       => 'not_enrolled',
				'message'      => __( 'Debes estar inscrito en el curso para acceder a esta lección.', 'atora-lms' ),
				'available_at' => 0,
			);
		}

		$drip_type = (string) get_post_meta( $lesson_id, self::META_TYPE, true );
		if ( '' === $drip_type ) {
			$drip_type = 'none';
		}

		if ( 'none' === $drip_type ) {
			return array(
				'available'    => true,
				'reason'       => 'none',
				'message'      => '',
				'available_at' => 0,
			);
		}

		$now = current_time( 'timestamp' );

		if ( 'date' === $drip_type ) {
			$date_value = (string) get_post_meta( $lesson_id, self::META_DATE, true );
			$open_at    = self::build_local_timestamp( $date_value, '00:00:00' );

			if ( ! $open_at ) {
				return array(
					'available'    => true,
					'reason'       => 'invalid_date_fallback',
					'message'      => '',
					'available_at' => 0,
				);
			}

			if ( $now >= $open_at ) {
				return array(
					'available'    => true,
					'reason'       => 'date_open',
					'message'      => '',
					'available_at' => $open_at,
				);
			}

			return array(
				'available'    => false,
				'reason'       => 'date_locked',
				'message'      => sprintf( __( 'Esta lección estará disponible el %s.', 'atora-lms' ), wp_date( 'd/m/Y', $open_at, wp_timezone() ) ),
				'available_at' => $open_at,
			);
		}

		if ( 'days_enrolled' === $drip_type ) {
			$days = absint( get_post_meta( $lesson_id, self::META_DAYS_ENROLLED, true ) );

			if ( $days <= 0 ) {
				return array(
					'available'    => true,
					'reason'       => 'days_enrolled_zero',
					'message'      => '',
					'available_at' => 0,
				);
			}

			$enrolled_at = self::get_enrollment_timestamp( $user_id, $course_id );

			/*
			 * Compatibilidad con alumnos antiguos:
			 * si no existe timestamp de inscripción, no bloqueamos.
			 */
			if ( ! $enrolled_at ) {
				return array(
					'available'    => true,
					'reason'       => 'legacy_enrollment_without_timestamp',
					'message'      => '',
					'available_at' => 0,
				);
			}

			$open_at = $enrolled_at + ( $days * DAY_IN_SECONDS );

			if ( $now >= $open_at ) {
				return array(
					'available'    => true,
					'reason'       => 'days_enrolled_open',
					'message'      => '',
					'available_at' => $open_at,
				);
			}

			return array(
				'available'    => false,
				'reason'       => 'days_enrolled_locked',
				'message'      => sprintf( _n( 'Esta lección se desbloquea %d día después de la inscripción.', 'Esta lección se desbloquea %d días después de la inscripción.', $days, 'atora-lms' ), $days ),
				'available_at' => $open_at,
			);
		}

		if ( 'days_after_previous' === $drip_type ) {
			$days               = absint( get_post_meta( $lesson_id, self::META_DAYS_AFTER_PREVIOUS, true ) );
			$previous_lesson_id = self::get_previous_lesson_id( $lesson_id );

			if ( ! $previous_lesson_id ) {
				return array(
					'available'    => true,
					'reason'       => 'no_previous_lesson',
					'message'      => '',
					'available_at' => 0,
				);
			}

			if ( ! self::is_lesson_completed_by_user( $user_id, $previous_lesson_id ) ) {
				return array(
					'available'    => false,
					'reason'       => 'previous_not_completed',
					'message'      => __( 'Debes completar primero la lección anterior.', 'atora-lms' ),
					'available_at' => 0,
				);
			}

			if ( $days <= 0 ) {
				return array(
					'available'    => true,
					'reason'       => 'days_after_previous_zero',
					'message'      => '',
					'available_at' => 0,
				);
			}

			$completed_at = self::get_lesson_completion_timestamp( $user_id, $previous_lesson_id );

			/*
			 * Compatibilidad con datos antiguos:
			 * si sabemos que está completada pero no tenemos fecha,
			 * no la bloqueamos.
			 */
			if ( ! $completed_at ) {
				return array(
					'available'    => true,
					'reason'       => 'legacy_completion_without_timestamp',
					'message'      => '',
					'available_at' => 0,
				);
			}

			$open_at = $completed_at + ( $days * DAY_IN_SECONDS );

			if ( $now >= $open_at ) {
				return array(
					'available'    => true,
					'reason'       => 'days_after_previous_open',
					'message'      => '',
					'available_at' => $open_at,
				);
			}

			return array(
				'available'    => false,
				'reason'       => 'days_after_previous_locked',
				'message'      => sprintf( _n( 'Esta lección se desbloquea %d día después de completar la lección anterior.', 'Esta lección se desbloquea %d días después de completar la lección anterior.', $days, 'atora-lms' ), $days ),
				'available_at' => $open_at,
			);
		}

		return array(
			'available'    => true,
			'reason'       => 'unknown_type_fallback',
			'message'      => '',
			'available_at' => 0,
		);
	}

	protected static function get_course_id( $lesson_id ) {
		$lesson_id = absint( $lesson_id );

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_course_id_from_lesson' ) ) {
			return absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		}

		return 0;
	}

	protected static function user_is_enrolled_in_course( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return false;
		}

		if ( self::user_can_manage_lms( $course_id ) ) {
			return true;
		}

		// Fase 11: si existe matrícula activa en tablas (atora_enrollments), respetarla.
		if ( class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' )
			&& is_callable( array( '\\ATORA\\LMS\\LMS_Enrollment_Service', 'is_enrolled_by_wp_id' ) )
		) {
			if ( \ATORA\LMS\LMS_Enrollment_Service::is_enrolled_by_wp_id( $user_id, $course_id ) ) {
				return true;
			}
		}

		$user_courses = get_user_meta( $user_id, '_clms_enrolled_courses', true );
		if ( is_array( $user_courses ) && in_array( $course_id, array_map( 'absint', $user_courses ), true ) ) {
			return true;
		}

		$course_users = get_post_meta( $course_id, '_clms_enrolled_users', true );
		if ( is_array( $course_users ) && in_array( $user_id, array_map( 'absint', $course_users ), true ) ) {
			return true;
		}

		return false;
	}

	protected static function get_enrollment_timestamp( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( ! $user_id || ! $course_id ) {
			return 0;
		}

		// Fase 11: intentar desde atora_enrollments (usa GMT/UTC).
		global $wpdb;
		if ( isset( $wpdb ) && isset( $wpdb->prefix ) ) {
			$table  = $wpdb->prefix . 'atora_enrollments';
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				$enrolled_at = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT enrolled_at FROM {$table} WHERE user_id = %d AND wp_course_id = %d AND status IN ('active','completed') ORDER BY enrolled_at DESC LIMIT 1",
						$user_id,
						$course_id
					)
				);
				if ( '' !== $enrolled_at ) {
					$ts = strtotime( $enrolled_at . ' UTC' );
					if ( false !== $ts ) {
						return (int) $ts;
					}
				}
			}
		}

		$user_dates = get_user_meta( $user_id, '_clms_enrollment_dates', true );
		if ( is_array( $user_dates ) && ! empty( $user_dates[ $course_id ] ) ) {
			return absint( $user_dates[ $course_id ] );
		}

		$course_dates = get_post_meta( $course_id, '_clms_enrollment_dates', true );
		if ( is_array( $course_dates ) && ! empty( $course_dates[ $user_id ] ) ) {
			return absint( $course_dates[ $user_id ] );
		}

		return 0;
	}

	protected static function get_course_lessons( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id ) {
			return array();
		}

		$lesson_ids = get_posts(
			array(
				'post_type'      => 'lm_lesson',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_clms_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => 'lm_course_id',
						'value' => $course_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		return array_map( 'absint', $lesson_ids );
	}

	protected static function get_previous_lesson_id( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		$course_id = self::get_course_id( $lesson_id );

		if ( ! $lesson_id || ! $course_id ) {
			return 0;
		}

		$lessons = self::get_course_lessons( $course_id );
		if ( empty( $lessons ) ) {
			return 0;
		}

		$current_index = array_search( $lesson_id, $lessons, true );
		if ( false === $current_index || $current_index < 1 ) {
			return 0;
		}

		return absint( $lessons[ $current_index - 1 ] );
	}

	protected static function is_lesson_completed_by_user( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return false;
		}

		$quiz_result = get_user_meta( $user_id, 'clms_quiz_result_' . $lesson_id, true );
		if ( is_array( $quiz_result ) && ! empty( $quiz_result ) ) {
			return true;
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'author'         => $user_id,
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( ! empty( $submission_ids ) ) {
			return true;
		}

		$keys = array(
			'clms_completed_lesson_' . $lesson_id,
			'_clms_completed_lesson_' . $lesson_id,
		);

		foreach ( $keys as $key ) {
			$value = get_user_meta( $user_id, $key, true );
			if ( ! empty( $value ) ) {
				return true;
			}
		}

		return false;
	}

	protected static function get_lesson_completion_timestamp( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return 0;
		}

		// Fase 11: leer completación desde atora_lesson_progress (usa GMT/UTC).
		global $wpdb;
		if ( isset( $wpdb ) && isset( $wpdb->prefix ) ) {
			$table  = $wpdb->prefix . 'atora_lesson_progress';
			$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $exists === $table ) {
				$completed_at = (string) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT completed_at FROM {$table} WHERE user_id = %d AND wp_lesson_id = %d AND status = 'completed' ORDER BY completed_at DESC LIMIT 1",
						$user_id,
						$lesson_id
					)
				);
				if ( '' !== $completed_at ) {
					$ts = strtotime( $completed_at . ' UTC' );
					if ( false !== $ts ) {
						return (int) $ts;
					}
				}
			}
		}

		$timestamp_keys = array(
			'clms_completed_lesson_time_' . $lesson_id,
			'_clms_completed_lesson_time_' . $lesson_id,
		);

		foreach ( $timestamp_keys as $key ) {
			$value = absint( get_user_meta( $user_id, $key, true ) );
			if ( $value > 0 ) {
				return $value;
			}
		}

		$quiz_result = get_user_meta( $user_id, 'clms_quiz_result_' . $lesson_id, true );
		if ( is_array( $quiz_result ) ) {
			foreach ( array( 'completed_at', 'submitted_at', 'attempted_at', 'timestamp', 'date' ) as $key ) {
				if ( empty( $quiz_result[ $key ] ) ) {
					continue;
				}

				if ( is_numeric( $quiz_result[ $key ] ) ) {
					return absint( $quiz_result[ $key ] );
				}

				$time = strtotime( (string) $quiz_result[ $key ] );
				if ( $time ) {
					return absint( $time );
				}
			}
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => 'clms_submission',
				'post_status'    => array( 'publish', 'private' ),
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'author'         => $user_id,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		if ( ! empty( $submission_ids ) ) {
			$post_time = get_post_time( 'U', true, $submission_ids[0] );
			if ( $post_time ) {
				return absint( $post_time );
			}
		}

		return 0;
	}

	protected static function build_local_timestamp( $date, $time = '00:00:00' ) {
		$date = trim( (string) $date );
		$time = trim( (string) $time );

		if ( '' === $date ) {
			return 0;
		}

		if ( '' === $time ) {
			$time = '00:00:00';
		}

		try {
			$dt = new DateTime( $date . ' ' . $time, wp_timezone() );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	protected static function user_can_manage_lms( $post_id = 0 ) {
		return CLMS_Helper::user_can_manage_lms( $post_id );
	}
}

if ( ! function_exists( 'is_lesson_available' ) ) {
	function is_lesson_available( $user_id, $lesson_id ) {
		return CLMS_Drip::is_lesson_available( $user_id, $lesson_id );
	}
}
