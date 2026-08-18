<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Analytics {

	const OPTION_EVENT_LOG = 'clms_analytics_event_log';
	const MAX_EVENTS       = 250;
	const INACTIVITY_DAYS  = 14;
	const ORDER_SCAN_LIMIT = 200;
	const CACHE_TTL        = 300;
	const CACHE_VERSION_OPTION = 'clms_analytics_cache_version';

	public function __construct() {
		add_action( 'clms_lesson_completed', array( $this, 'record_lesson_completed' ), 10, 2 );
		add_action( 'clms_submission_created', array( $this, 'record_submission_created' ), 10, 3 );
		add_action( 'clms_submission_graded', array( $this, 'record_submission_graded' ), 10, 5 );
		add_action( 'clms_commerce_order_after_processed', array( $this, 'record_order_processed' ), 10, 4 );
		add_action( 'clms_commerce_course_access_notified', array( $this, 'record_course_access_notified' ), 10, 4 );
		add_action( 'clms_user_enrolled', array( $this, 'record_user_enrolled' ), 10, 2 );
		add_action( 'clms_user_enrolled_in_program', array( $this, 'record_user_enrolled_in_program' ), 10, 3 );
		add_action( 'clms_ai_error', array( $this, 'record_ai_error' ), 10, 1 );
		add_action( 'clms_permission_denied', array( $this, 'record_permission_denied' ), 10, 1 );
		add_action( 'clms_system_log', array( $this, 'record_system_log' ), 10, 1 );
		add_action( 'clms_peer_review_quality_scored', array( $this, 'record_peer_review_quality' ), 10, 3 );
		add_action( 'clms_peer_reviewer_training_completed', array( $this, 'record_peer_reviewer_training' ), 10, 2 );
	}

	public function get_dashboard_snapshot( $role_context, $user_id ) {
		$role_context = sanitize_key( (string) $role_context );
		$user_id      = absint( $user_id );

		if ( class_exists( 'CLMS_Cache' ) ) {
			$cached = CLMS_Cache::get( 'analytics', array( $role_context, $user_id ), array() );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		} else {
			$version  = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
			$cache_key = 'clms_analytics_snapshot_' . $role_context . '_' . $user_id . '_' . $version;
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$snapshot = array(
			'academic'      => $this->get_academic_overview( $role_context, $user_id ),
			'commercial'    => $this->get_commercial_overview( $role_context, $user_id ),
			'observability' => $this->get_observability_overview(),
		);

		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::set( 'analytics', array( $role_context, $user_id ), $snapshot, self::CACHE_TTL );
		} else {
			set_transient( $cache_key, $snapshot, self::CACHE_TTL );
		}

		return $snapshot;
	}

	public function get_recent_events( $limit = 20, $filters = array() ) {
		$limit   = max( 1, absint( $limit ) );
		$filters = is_array( $filters ) ? $filters : array();
		$events  = get_option( self::OPTION_EVENT_LOG, array() );
		$events  = is_array( $events ) ? $events : array();
		$clean   = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || empty( $event['id'] ) ) {
				continue;
			}

			$event = $this->sanitize_event( $event );

			if ( ! empty( $filters['type'] ) && sanitize_key( (string) $filters['type'] ) !== $event['type'] ) {
				continue;
			}

			if ( ! empty( $filters['severity'] ) && sanitize_key( (string) $filters['severity'] ) !== $event['severity'] ) {
				continue;
			}

			$clean[] = $event;

			if ( count( $clean ) >= $limit ) {
				break;
			}
		}

		return $clean;
	}

	public function get_event_series( $types, $days = 42, $bucket = 'week', $mode = 'count', $context_key = '' ) {
		$types = is_array( $types ) ? $types : array( $types );
		$types = array_values(
			array_filter(
				array_map(
					static function( $type ) {
						return sanitize_key( (string) $type );
					},
					$types
				)
			)
		);
		$days        = max( 1, absint( $days ) );
		$bucket      = in_array( $bucket, array( 'day', 'week' ), true ) ? $bucket : 'week';
		$mode        = 'avg' === $mode ? 'avg' : 'count';
		$context_key = sanitize_key( (string) $context_key );

		$bucket_seconds  = 'day' === $bucket ? DAY_IN_SECONDS : 7 * DAY_IN_SECONDS;
		$bucket_span     = max( 1, (int) round( $bucket_seconds / DAY_IN_SECONDS ) );
		$bucket_count    = max( 1, (int) ceil( $days / $bucket_span ) );
		$now             = (int) current_time( 'timestamp' );
		$start_timestamp = $now - ( $bucket_count * $bucket_seconds );

		$buckets = array();
		for ( $i = 0; $i < $bucket_count; $i++ ) {
			$bucket_start = $start_timestamp + ( $i * $bucket_seconds );
			$buckets[ $i ] = array(
				'label' => date_i18n( 'd M', $bucket_start ),
				'value' => 0,
				'sum'   => 0,
				'count' => 0,
			);
		}

		$events = get_option( self::OPTION_EVENT_LOG, array() );
		$events = is_array( $events ) ? $events : array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || empty( $event['id'] ) ) {
				continue;
			}

			$event = $this->sanitize_event( $event );
			if ( ! empty( $types ) && ! in_array( $event['type'], $types, true ) ) {
				continue;
			}

			$timestamp = $event['created_at'] ? strtotime( $event['created_at'] ) : 0;
			if ( ! $timestamp ) {
				continue;
			}

			if ( $timestamp < $start_timestamp || $timestamp > $now ) {
				continue;
			}

			$index = (int) floor( ( $timestamp - $start_timestamp ) / $bucket_seconds );
			if ( ! isset( $buckets[ $index ] ) ) {
				continue;
			}

			if ( 'avg' === $mode ) {
				if ( $context_key && isset( $event['context'][ $context_key ] ) ) {
					$raw = $event['context'][ $context_key ];
					$value = is_numeric( $raw ) ? (float) $raw : (float) preg_replace( '/[^0-9\.\-]/', '', (string) $raw );
					if ( is_numeric( $value ) ) {
						$buckets[ $index ]['sum']  += (float) $value;
						$buckets[ $index ]['count'] += 1;
					}
				}
			} else {
				$buckets[ $index ]['value'] += 1;
			}
		}

		foreach ( $buckets as $i => $bucket_item ) {
			if ( 'avg' === $mode ) {
				$avg = $bucket_item['count'] > 0 ? ( $bucket_item['sum'] / $bucket_item['count'] ) : 0;
				$buckets[ $i ]['value'] = (float) round( $avg, 2 );
			}
			unset( $buckets[ $i ]['sum'], $buckets[ $i ]['count'] );
		}

		return array_values( $buckets );
	}

	public function record_event( $type, $severity, $message, $context = array() ) {
		$type     = sanitize_key( (string) $type );
		$severity = sanitize_key( (string) $severity );
		$message  = sanitize_text_field( (string) $message );
		$context  = $this->sanitize_context( is_array( $context ) ? $context : array() );

		if ( ! $type || ! $message ) {
			return false;
		}

		$events = get_option( self::OPTION_EVENT_LOG, array() );
		$events = is_array( $events ) ? $events : array();

		array_unshift(
			$events,
			array(
				'id'         => wp_generate_uuid4(),
				'type'       => $type,
				'severity'   => $severity ? $severity : 'info',
				'message'    => $message,
				'context'    => $context,
				'created_at' => current_time( 'mysql' ),
			)
		);

		if ( count( $events ) > self::MAX_EVENTS ) {
			$events = array_slice( $events, 0, self::MAX_EVENTS );
		}

		update_option( self::OPTION_EVENT_LOG, $events, false );
		$this->bump_cache_version();

		return true;
	}

	protected function bump_cache_version() {
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::bump_version( 'analytics' );
			return;
		}
		update_option( self::CACHE_VERSION_OPTION, time(), false );
	}

	public function record_lesson_completed( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$course_id = CLMS_Helper::get_course_id_from_lesson( $lesson_id );

		$this->record_event(
			'lesson_completed',
			'info',
			'Lección completada.',
			array(
				'user_id'   => $user_id,
				'lesson_id' => $lesson_id,
				'course_id' => $course_id,
			)
		);
	}

	public function record_submission_created( $submission_id, $lesson_id, $student_id ) {
		$this->record_event(
			'submission_created',
			'info',
			'Nueva entrega registrada.',
			array(
				'submission_id' => absint( $submission_id ),
				'lesson_id'     => absint( $lesson_id ),
				'user_id'       => absint( $student_id ),
				'course_id'     => CLMS_Helper::get_course_id_from_lesson( $lesson_id ),
			)
		);
	}

	public function record_submission_graded( $submission_id, $lesson_id, $user_id, $status, $grade ) {
		$this->record_event(
			'submission_graded',
			'info',
			'Entrega evaluada.',
			array(
				'submission_id' => absint( $submission_id ),
				'lesson_id'     => absint( $lesson_id ),
				'user_id'       => absint( $user_id ),
				'course_id'     => CLMS_Helper::get_course_id_from_lesson( $lesson_id ),
				'status'        => sanitize_key( (string) $status ),
				'grade'         => '' !== (string) $grade ? absint( $grade ) : '',
			)
		);
	}

	public function record_order_processed( $order_id, $user_id, $result = array(), $order = null ) {
		$result = is_array( $result ) ? $result : array();

		$this->record_event(
			'commerce_order_processed',
			empty( $result['errors'] ) ? 'info' : 'warning',
			'Orden comercial procesada para ATORA.',
			array(
				'order_id'                  => absint( $order_id ),
				'user_id'                   => absint( $user_id ),
				'courses'                   => array_values( array_map( 'absint', (array) ( $result['courses'] ?? array() ) ) ),
				'programs'                  => array_values( array_map( 'absint', (array) ( $result['programs'] ?? array() ) ) ),
				'newly_enrolled'            => count( (array) ( $result['newly_enrolled'] ?? array() ) ),
				'newly_enrolled_programs'   => count( (array) ( $result['newly_enrolled_programs'] ?? array() ) ),
				'error_count'               => count( (array) ( $result['errors'] ?? array() ) ),
				'order_total'               => ( $order && is_a( $order, 'WC_Order' ) ) ? (float) $order->get_total() : '',
			)
		);
	}

	public function record_course_access_notified( $user_id, $course_id, $order_id, $is_new_access ) {
		$this->record_event(
			'course_access_notified',
			'info',
			'Acceso al curso comunicado al estudiante.',
			array(
				'user_id'       => absint( $user_id ),
				'course_id'     => absint( $course_id ),
				'order_id'      => absint( $order_id ),
				'is_new_access' => ! empty( $is_new_access ) ? 1 : 0,
			)
		);
	}

	public function record_user_enrolled( $user_id, $course_id ) {
		$this->record_event(
			'enrollment_created',
			'info',
			'Inscripción en curso registrada.',
			array(
				'user_id'   => absint( $user_id ),
				'course_id' => absint( $course_id ),
			)
		);
	}

	public function record_user_enrolled_in_program( $user_id, $program_id, $result = array() ) {
		$result = is_array( $result ) ? $result : array();

		$this->record_event(
			'program_enrollment_created',
			'info',
			'Inscripción en programa registrada.',
			array(
				'user_id'   => absint( $user_id ),
				'program_id' => absint( $program_id ),
				'courses'   => array_values( array_map( 'absint', (array) ( $result['courses'] ?? array() ) ) ),
				'errors'    => array_values( array_map( 'sanitize_text_field', (array) ( $result['errors'] ?? array() ) ) ),
			)
		);
	}

	public function record_ai_error( $payload = array() ) {
		$payload = is_array( $payload ) ? $payload : array();

		$this->record_event(
			'ai_error',
			'error',
			! empty( $payload['message'] ) ? (string) $payload['message'] : 'Error registrado en flujo IA.',
			$payload
		);
	}

	public function record_permission_denied( $payload = array() ) {
		$payload = is_array( $payload ) ? $payload : array();

		$this->record_event(
			'permission_denied',
			'warning',
			! empty( $payload['message'] ) ? (string) $payload['message'] : 'Permiso denegado.',
			$payload
		);
	}

	public function record_system_log( $payload = array() ) {
		$payload = is_array( $payload ) ? $payload : array();

		$this->record_event(
			'system_log',
			! empty( $payload['severity'] ) ? (string) $payload['severity'] : 'info',
			! empty( $payload['message'] ) ? (string) $payload['message'] : 'Evento del sistema.',
			$payload
		);
	}

	/**
	 * Registra señal analítica de calidad de revisión entre pares.
	 *
	 * @param int   $assignment_id Asignación.
	 * @param array $quality       Calidad calculada.
	 * @param int   $submission_id Entrega.
	 * @return void
	 */
	public function record_peer_review_quality( $assignment_id, $quality = array(), $submission_id = 0 ) {
		$assignment_id = absint( $assignment_id );
		$submission_id = absint( $submission_id );
		$quality       = is_array( $quality ) ? $quality : array();

		$reviewer_id = isset( $quality['reviewer_id'] ) ? absint( $quality['reviewer_id'] ) : 0;
		$score       = isset( $quality['score'] ) && is_numeric( $quality['score'] ) ? (float) $quality['score'] : 0;
		$status      = isset( $quality['status'] ) ? sanitize_key( (string) $quality['status'] ) : 'unknown';
		$flags       = isset( $quality['flags'] ) && is_array( $quality['flags'] ) ? array_values( array_map( 'sanitize_text_field', $quality['flags'] ) ) : array();

		$this->record_event(
			'peer_review_quality_scored',
			'high' === $status ? 'info' : ( 'medium' === $status ? 'warning' : 'warning' ),
			'Revisión entre pares evaluada en calidad.',
			array(
				'assignment_id' => $assignment_id,
				'submission_id' => $submission_id,
				'user_id'       => $reviewer_id,
				'quality_score' => $score,
				'quality_status'=> $status,
				'flags'         => $flags,
			)
		);
	}

	/**
	 * Registra finalización de entrenamiento de reviewer.
	 *
	 * @param int   $user_id Usuario.
	 * @param array $status  Estado guardado.
	 * @return void
	 */
	public function record_peer_reviewer_training( $user_id, $status = array() ) {
		$this->record_event(
			'peer_reviewer_training_completed',
			'info',
			'Revisor entre pares completó entrenamiento.',
			array(
				'user_id'      => absint( $user_id ),
				'version'      => absint( $status['version'] ?? 1 ),
				'completed_at' => sanitize_text_field( (string) ( $status['completed_at'] ?? '' ) ),
			)
		);
	}

	protected function get_academic_overview( $role_context, $user_id ) {
		$course_ids = $this->get_scoped_course_ids( $role_context, $user_id );
		$grading    = clms_core('CLMS_Grading');
		$rows       = array();
		$totals     = array(
			'progress'            => array(),
			'grades'              => array(),
			'completion_rate'     => array(),
			'pending_submissions' => 0,
			'abandonment'         => 0,
			'completed_courses'   => 0,
			'active_courses'      => count( $course_ids ),
		);

		foreach ( $course_ids as $course_id ) {
			$row = $this->build_academic_course_row( $course_id, $role_context, $user_id, $grading );

			if ( empty( $row ) ) {
				continue;
			}

			$rows[] = $row;
			$totals['progress'][]        = $row['avg_progress'];
			$totals['grades'][]          = $row['avg_grade'];
			$totals['completion_rate'][] = $row['completion_rate'];
			$totals['pending_submissions'] += $row['pending_submissions'];
			$totals['abandonment']       += $row['inactive_students'];
			$totals['completed_courses'] += ! empty( $row['is_completed'] ) ? 1 : 0;
		}

		usort(
			$rows,
			static function( $a, $b ) {
				return $b['pending_submissions'] <=> $a['pending_submissions'];
			}
		);

		return array(
			'cards' => array(
				array( 'label' => 'Cursos activos', 'value' => absint( $totals['active_courses'] ) ),
				array( 'label' => 'Progreso promedio', 'value' => absint( $this->average_int( $totals['progress'] ) ) . '%' ),
				array( 'label' => 'Completitud', 'value' => absint( $this->average_int( $totals['completion_rate'] ) ) . '%' ),
				array( 'label' => 'Nota promedio', 'value' => absint( $this->average_int( $totals['grades'] ) ) . '%' ),
				array( 'label' => 'Pendientes', 'value' => absint( $totals['pending_submissions'] ) ),
				array( 'label' => 'Abandono/Inactividad', 'value' => absint( $totals['abandonment'] ) ),
			),
			'rows'  => array_slice( $rows, 0, 6 ),
		);
	}

	protected function get_commercial_overview( $role_context, $user_id ) {
		unset( $role_context, $user_id );

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'cards' => array(
					array( 'label' => 'Conversiones', 'value' => 0 ),
					array( 'label' => 'Ventas académicas', 'value' => 0 ),
					array( 'label' => 'Ingresos', 'value' => '$0' ),
					array( 'label' => 'Cross-sell', 'value' => '0%' ),
				),
				'rows'  => array(),
			);
		}

		$orders      = wc_get_orders(
			array(
				'limit'  => self::ORDER_SCAN_LIMIT,
				'status' => array( 'processing', 'completed' ),
				'return' => 'objects',
			)
		);
		$commerce    = clms_core('CLMS_Commerce');
		$enrollment  = ( $commerce && method_exists( $commerce, 'get' ) ) ? $commerce->get( 'enrollment' ) : null;
		$revenue_map = array();
		$academic_orders = 0;
		$converted_orders = 0;
		$revenue_total    = 0.0;
		$cross_sell_orders = 0;

		foreach ( $orders as $order ) {
			if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
				continue;
			}

			$order_id    = absint( $order->get_id() );
			$course_ids  = array_map( 'absint', (array) get_post_meta( $order_id, '_clms_commerce_enrolled_courses', true ) );
			$program_ids = array_map( 'absint', (array) get_post_meta( $order_id, '_clms_commerce_enrolled_programs', true ) );
			$entity_ids  = array_values( array_unique( array_filter( array_merge( $course_ids, $program_ids ) ) ) );

			if ( empty( $entity_ids ) && $enrollment && method_exists( $enrollment, 'get_access_map_from_order' ) ) {
				$map = (array) $enrollment->get_access_map_from_order( $order );

				foreach ( $map as $row ) {
					$entity_ids = array_merge(
						$entity_ids,
						array_map( 'absint', (array) ( $row['course_ids'] ?? array() ) ),
						array_map( 'absint', (array) ( $row['program_ids'] ?? array() ) )
					);
				}

				$entity_ids = array_values( array_unique( array_filter( $entity_ids ) ) );
			}

			if ( empty( $entity_ids ) ) {
				continue;
			}

			++$academic_orders;
			$revenue_total += (float) $order->get_total();

			if ( ! empty( $course_ids ) || ! empty( $program_ids ) ) {
				++$converted_orders;
			}

			if ( count( $entity_ids ) > 1 ) {
				++$cross_sell_orders;
			}

			$share = count( $entity_ids ) > 0 ? ( (float) $order->get_total() / count( $entity_ids ) ) : 0;

			foreach ( $entity_ids as $entity_id ) {
				if ( ! isset( $revenue_map[ $entity_id ] ) ) {
					$revenue_map[ $entity_id ] = array(
						'entity_id'   => $entity_id,
						'title'       => get_the_title( $entity_id ),
						'type'        => get_post_type( $entity_id ),
						'revenue'     => 0.0,
						'orders'      => 0,
					);
				}

				$revenue_map[ $entity_id ]['revenue'] += $share;
				++$revenue_map[ $entity_id ]['orders'];
			}
		}

		usort(
			$revenue_map,
			static function( $a, $b ) {
				return ( $b['revenue'] <=> $a['revenue'] );
			}
		);

		$cross_sell_rate = $academic_orders > 0 ? (int) round( ( $cross_sell_orders / $academic_orders ) * 100 ) : 0;

		return array(
			'cards' => array(
				array( 'label' => 'Conversiones', 'value' => absint( $converted_orders ) ),
				array( 'label' => 'Ventas académicas', 'value' => absint( $academic_orders ) ),
				array( 'label' => 'Ingresos', 'value' => '$' . number_format_i18n( $revenue_total, 2 ) ),
				array( 'label' => 'Cross-sell', 'value' => absint( $cross_sell_rate ) . '%' ),
			),
			'rows'  => array_slice( array_values( $revenue_map ), 0, 6 ),
		);
	}

	protected function get_observability_overview() {
		$events                 = $this->get_recent_events( 12 );
		$ai_errors              = count( $this->get_recent_events( 20, array( 'type' => 'ai_error' ) ) );
		$permission_denials     = count( $this->get_recent_events( 20, array( 'type' => 'permission_denied' ) ) );
		$health_items           = array(
			array( 'label' => 'WP-Cron', 'status' => ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) ? 'ok' : 'warning', 'detail' => ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) ? 'Activo' : 'Desactivado' ),
			array( 'label' => 'Followups', 'status' => wp_next_scheduled( 'clms_messaging_followups_daily' ) ? 'ok' : 'warning', 'detail' => wp_next_scheduled( 'clms_messaging_followups_daily' ) ? 'Programado' : 'Sin cron' ),
			array( 'label' => 'AI Alerts', 'status' => wp_next_scheduled( 'clms_ai_alerts_daily' ) ? 'ok' : 'warning', 'detail' => wp_next_scheduled( 'clms_ai_alerts_daily' ) ? 'Programado' : 'Sin cron' ),
			array( 'label' => 'IA', 'status' => $this->is_ai_configured() ? 'ok' : 'warning', 'detail' => $this->is_ai_configured() ? 'Proveedor listo' : 'Sin provider configurado' ),
			array( 'label' => 'WooCommerce', 'status' => function_exists( 'wc_get_orders' ) ? 'ok' : 'info', 'detail' => function_exists( 'wc_get_orders' ) ? 'Activo' : 'No activo' ),
		);

		return array(
			'cards'  => array(
				array( 'label' => 'Errores IA', 'value' => absint( $ai_errors ) ),
				array( 'label' => 'Permisos denegados', 'value' => absint( $permission_denials ) ),
				array( 'label' => 'Eventos recientes', 'value' => count( $events ) ),
				array( 'label' => 'Salud', 'value' => $this->count_health_status( $health_items, 'ok' ) . '/' . count( $health_items ) ),
			),
			'events' => $events,
			'health' => $health_items,
		);
	}

	protected function build_academic_course_row( $course_id, $role_context, $user_id, $grading ) {
		$course_id     = absint( $course_id );
		$role_context  = sanitize_key( (string) $role_context );
		$user_id       = absint( $user_id );
		$student_ids   = array();
		$avg_progress  = 0;
		$avg_grade     = 0;
		$completion    = 0;
		$pending_count = 0;
		$inactive      = 0;
		$is_completed  = false;

		if ( 'student' === $role_context ) {
			$student_ids = array( $user_id );
		} else {
			$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );
		}

		$progress_values = array();
		$grade_values    = array();
		$completed_count = 0;

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );

			if ( ! $student_id ) {
				continue;
			}

			$summary = ( $grading && method_exists( $grading, 'get_course_grade_summary' ) )
				? (array) $grading->get_course_grade_summary( $student_id, $course_id )
				: array();

			$progress_values[] = isset( $summary['progress_percent'] ) ? absint( $summary['progress_percent'] ) : 0;
			$grade_values[]    = isset( $summary['final_average'] ) ? absint( $summary['final_average'] ) : 0;

			if ( method_exists( 'CLMS_Helper', 'is_course_completed' ) && CLMS_Helper::is_course_completed( $student_id, $course_id ) ) {
				++$completed_count;
				if ( 'student' === $role_context ) {
					$is_completed = true;
				}
			}

			if ( $this->is_student_inactive_in_course( $student_id, $course_id ) ) {
				++$inactive;
			}
		}

		$avg_progress = $this->average_int( $progress_values );
		$avg_grade    = $this->average_int( $grade_values );
		$completion   = ! empty( $student_ids ) ? (int) round( ( $completed_count / count( $student_ids ) ) * 100 ) : 0;
		$pending_count = $this->count_pending_submissions( $course_id, 'student' === $role_context ? $user_id : 0 );

		return array(
			'course_id'            => $course_id,
			'course_title'         => get_the_title( $course_id ),
			'enrolled_students'    => 'student' === $role_context ? 1 : count( $student_ids ),
			'avg_progress'         => $avg_progress,
			'completion_rate'      => $completion,
			'avg_grade'            => $avg_grade,
			'pending_submissions'  => $pending_count,
			'inactive_students'    => $inactive,
			'is_completed'         => $is_completed,
		);
	}

	protected function get_scoped_course_ids( $role_context, $user_id ) {
		$role_context = sanitize_key( (string) $role_context );
		$user_id      = absint( $user_id );

		if ( 'student' === $role_context ) {
			return array_values( array_map( 'absint', ( class_exists('\\ATORA\\LMS\\LMS_Enrollment_Service') ? array_column( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $user_id ), 'course_id' ) : \CLMS_Helper::get_user_enrolled_courses( $user_id ) ) ) );
		}

		$args = array(
			'post_type'              => 'lm_course',
			'post_status'            => array( 'publish', 'private', 'draft' ),
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( 'instructor' === $role_context ) {
			$args['author'] = $user_id;
		}

		return array_values( array_map( 'absint', get_posts( $args ) ) );
	}

	protected function count_pending_submissions( $course_id, $student_id = 0 ) {
		$meta_query = array(
			array(
				'key'   => '_clms_submission_course_id',
				'value' => absint( $course_id ),
				'type'  => 'NUMERIC',
			),
			array(
				'key'     => '_clms_submission_status',
				'value'   => array( 'submitted', 'in_review' ),
				'compare' => 'IN',
			),
		);

		if ( $student_id ) {
			$meta_query[] = array(
				'key'   => '_clms_submission_user_id',
				'value' => absint( $student_id ),
				'type'  => 'NUMERIC',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_query'             => $meta_query,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return absint( $query->found_posts );
	}

	protected function is_student_inactive_in_course( $user_id, $course_id ) {
		$last = $this->get_last_activity_timestamp( $user_id, $course_id );

		if ( ! $last ) {
			return true;
		}

		return ( time() - $last ) >= ( self::INACTIVITY_DAYS * DAY_IN_SECONDS );
	}

	protected function get_last_activity_timestamp( $user_id, $course_id ) {
		$user_id    = absint( $user_id );
		$course_id  = absint( $course_id );
		$lesson_ids = CLMS_Helper::get_course_lessons( $course_id );

		if ( empty( $lesson_ids ) ) {
			return 0;
		}

		$submissions = get_posts(
			array(
				'post_type'              => 'clms_submission',
				'post_status'            => array( 'publish', 'private' ),
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'meta_query'             => array(
					array(
						'key'     => '_clms_submission_lesson_id',
						'value'   => array_map( 'absint', $lesson_ids ),
						'compare' => 'IN',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		if ( ! empty( $submissions ) ) {
			return (int) strtotime( (string) get_post_field( 'post_date_gmt', absint( $submissions[0] ) ) );
		}

		$dates = get_user_meta( $user_id, CLMS_Helper::USER_ENROLLMENT_DATES_META, true );
		$dates = is_array( $dates ) ? $dates : array();

		if ( ! empty( $dates[ $course_id ] ) ) {
			return (int) strtotime( sanitize_text_field( (string) $dates[ $course_id ] ) );
		}

		return 0;
	}

	protected function average_int( $values ) {
		$values = array_values( array_filter( array_map( 'intval', (array) $values ), static function( $value ) {
			return $value >= 0;
		} ) );

		if ( empty( $values ) ) {
			return 0;
		}

		return (int) round( array_sum( $values ) / count( $values ) );
	}

	protected function count_health_status( $items, $status ) {
		$status = sanitize_key( (string) $status );
		$count  = 0;

		foreach ( (array) $items as $item ) {
			if ( $status === sanitize_key( (string) ( $item['status'] ?? '' ) ) ) {
				++$count;
			}
		}

		return $count;
	}

	protected function is_ai_configured() {
		$manager = clms_core('CLMS_AI_Manager');

		return $manager && method_exists( $manager, 'is_configured' ) ? (bool) $manager->is_configured() : false;
	}

	protected function sanitize_event( $event ) {
		return array(
			'id'         => sanitize_text_field( (string) ( $event['id'] ?? '' ) ),
			'type'       => sanitize_key( (string) ( $event['type'] ?? '' ) ),
			'severity'   => sanitize_key( (string) ( $event['severity'] ?? 'info' ) ),
			'message'    => sanitize_text_field( (string) ( $event['message'] ?? '' ) ),
			'context'    => $this->sanitize_context( (array) ( $event['context'] ?? array() ) ),
			'created_at' => sanitize_text_field( (string) ( $event['created_at'] ?? '' ) ),
		);
	}

	protected function sanitize_context( $context ) {
		$clean = array();

		foreach ( (array) $context as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_map(
					static function( $item ) {
						return is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '';
					},
					$value
				);
				continue;
			}

			$clean[ $key ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}

		return $clean;
	}
}
