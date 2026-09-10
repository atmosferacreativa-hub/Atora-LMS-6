<?php
/**
 * Persistencia y operaciones del Gradebook institucional.
 *
 * @package ATORA_LMS
 * @since 6.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Institutional_Gradebook_Service {

	public function create_period( $data, $actor_id = 0 ) {
		global $wpdb;

		$data       = is_array( $data ) ? $data : array();
		$academy_id = absint( $data['academy_id'] ?? 0 );
		$code       = sanitize_key( (string) ( $data['code'] ?? '' ) );
		$name       = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$starts_at  = $this->normalize_date( $data['starts_at'] ?? '' );
		$ends_at    = $this->normalize_date( $data['ends_at'] ?? '' );

		if ( '' === $code || '' === $name || ! $starts_at || ! $ends_at || $ends_at < $starts_at ) {
			return new WP_Error( 'clms_period_invalid', __( 'Código, nombre y rango de fechas válido son obligatorios.', 'atora-lms' ) );
		}

		$table = $wpdb->prefix . 'atora_academic_periods';
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE academy_id = %d AND code = %s LIMIT 1", $academy_id, $code )
		);
		if ( $exists ) {
			return new WP_Error( 'clms_period_duplicate', __( 'Ya existe un período con ese código.', 'atora-lms' ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'academy_id' => $academy_id,
				'code'       => $code,
				'name'       => $name,
				'starts_at'  => $starts_at,
				'ends_at'    => $ends_at,
				'status'     => 'draft',
				'created_by' => absint( $actor_id ?: get_current_user_id() ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'clms_period_insert_failed', __( 'No se pudo crear el período académico.', 'atora-lms' ) );
		}

		$this->log_event( 'period_created', 'period', (int) $wpdb->insert_id, array( 'code' => $code ), $actor_id );
		return (int) $wpdb->insert_id;
	}

	public function transition_period( $period_id, $target_status, $actor_id = 0 ) {
		global $wpdb;

		$period = $this->get_row( 'atora_academic_periods', $period_id );
		if ( empty( $period ) ) {
			return new WP_Error( 'clms_period_not_found', __( 'Período académico no encontrado.', 'atora-lms' ) );
		}

		$target_status = sanitize_key( (string) $target_status );
		if ( ! CLMS_Institutional_Gradebook_Policy::can_transition_period( $period['status'], $target_status ) ) {
			return new WP_Error( 'clms_period_invalid_transition', __( 'La transición del período no está permitida.', 'atora-lms' ) );
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'atora_academic_periods',
			array( 'status' => $target_status ),
			array( 'id' => absint( $period_id ), 'status' => $period['status'] ),
			array( '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'clms_period_concurrent_update', __( 'El período cambió durante la operación. Actualiza e inténtalo de nuevo.', 'atora-lms' ) );
		}

		$this->log_event( 'period_status_changed', 'period', $period_id, array( 'from' => $period['status'], 'to' => $target_status ), $actor_id );
		return true;
	}

	public function create_scale( $data, $actor_id = 0 ) {
		global $wpdb;

		$data       = is_array( $data ) ? $data : array();
		$academy_id = absint( $data['academy_id'] ?? 0 );
		$code       = sanitize_key( (string) ( $data['code'] ?? '' ) );
		$name       = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$minimum    = isset( $data['minimum'] ) && is_numeric( $data['minimum'] ) ? (float) $data['minimum'] : 0.0;
		$maximum    = isset( $data['maximum'] ) && is_numeric( $data['maximum'] ) ? (float) $data['maximum'] : 100.0;
		$bands      = CLMS_Institutional_Gradebook_Policy::normalize_scale_bands( $data['bands'] ?? array(), $minimum, $maximum );

		if ( '' === $code || '' === $name ) {
			return new WP_Error( 'clms_scale_invalid', __( 'Código y nombre de la escala son obligatorios.', 'atora-lms' ) );
		}
		if ( is_wp_error( $bands ) ) {
			return $bands;
		}

		$table = $wpdb->prefix . 'atora_grading_scales';
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE academy_id = %d AND code = %s AND status != 'retired' LIMIT 1", $academy_id, $code )
		);
		if ( $exists ) {
			return new WP_Error( 'clms_scale_duplicate', __( 'Ya existe una escala activa con ese código.', 'atora-lms' ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'academy_id' => $academy_id,
				'code'       => $code,
				'name'       => $name,
				'minimum'    => $minimum,
				'maximum'    => $maximum,
				'bands_json' => wp_json_encode( $bands ),
				'status'     => 'active',
				'version'    => 1,
				'created_by' => absint( $actor_id ?: get_current_user_id() ),
			),
			array( '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'clms_scale_insert_failed', __( 'No se pudo crear la escala.', 'atora-lms' ) );
		}

		$this->log_event( 'scale_created', 'scale', (int) $wpdb->insert_id, array( 'code' => $code, 'version' => 1 ), $actor_id );
		return (int) $wpdb->insert_id;
	}

	public function create_cycle( $period_id, $course_id, $scale_id, $actor_id = 0 ) {
		global $wpdb;

		$period_id = absint( $period_id );
		$course_id = absint( $course_id );
		$scale_id  = absint( $scale_id );
		if ( ! $period_id || ! $course_id || ! $scale_id ) {
			return new WP_Error( 'clms_cycle_invalid_reference', __( 'Período, curso y escala son obligatorios.', 'atora-lms' ) );
		}

		$period = $this->get_row( 'atora_academic_periods', $period_id );
		$scale  = $this->get_row( 'atora_grading_scales', $scale_id );
		if ( empty( $period ) || 'open' !== $period['status'] ) {
			return new WP_Error( 'clms_cycle_period_not_open', __( 'El período debe estar abierto.', 'atora-lms' ) );
		}
		if ( empty( $scale ) || 'active' !== $scale['status'] ) {
			return new WP_Error( 'clms_cycle_scale_inactive', __( 'La escala debe estar activa.', 'atora-lms' ) );
		}
		if ( absint( $period['academy_id'] ?? 0 ) !== absint( $scale['academy_id'] ?? 0 ) ) {
			return new WP_Error( 'clms_cycle_academy_mismatch', __( 'El período y la escala pertenecen a academias diferentes.', 'atora-lms' ) );
		}

		$table = $wpdb->prefix . 'atora_gradebook_cycles';
		$inserted = $wpdb->insert(
			$table,
			array(
				'academy_id' => absint( $period['academy_id'] ?? 0 ),
				'period_id'  => absint( $period_id ),
				'course_id'  => absint( $course_id ),
				'scale_id'   => absint( $scale_id ),
				'status'     => 'draft',
				'lock_version' => 1,
				'created_by' => absint( $actor_id ?: get_current_user_id() ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%d', '%d' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'clms_cycle_insert_failed', __( 'No se pudo crear el ciclo de calificaciones o ya existe.', 'atora-lms' ) );
		}

		$this->log_event( 'cycle_created', 'cycle', (int) $wpdb->insert_id, array( 'period_id' => absint( $period_id ), 'course_id' => absint( $course_id ) ), $actor_id );
		return (int) $wpdb->insert_id;
	}

	public function save_grade( $cycle_id, $student_id, $grade, $expected_revision = 0, $actor_id = 0, $details = array() ) {
		global $wpdb;

		$cycle = $this->get_row( 'atora_gradebook_cycles', $cycle_id );
		if ( empty( $cycle ) ) {
			return new WP_Error( 'clms_cycle_not_found', __( 'Ciclo de calificaciones no encontrado.', 'atora-lms' ) );
		}
		if ( ! CLMS_Institutional_Gradebook_Policy::cycle_accepts_grades( $cycle['status'] ) ) {
			return new WP_Error( 'clms_cycle_locked', __( 'El ciclo no admite cambios de notas.', 'atora-lms' ) );
		}

		$scale = $this->get_row( 'atora_grading_scales', $cycle['scale_id'] );
		$bands = ! empty( $scale['bands_json'] ) ? json_decode( $scale['bands_json'], true ) : array();
		$band  = CLMS_Institutional_Gradebook_Policy::resolve_band( $grade, $bands );
		if ( empty( $band ) ) {
			return new WP_Error( 'clms_grade_out_of_scale', __( 'La nota está fuera de la escala institucional.', 'atora-lms' ) );
		}

		$table = $wpdb->prefix . 'atora_institutional_grades';
		$current = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE cycle_id = %d AND student_id = %d LIMIT 1", absint( $cycle_id ), absint( $student_id ) ),
			ARRAY_A
		);

		if ( $current ) {
			$current_revision = absint( $current['revision'] ?? 1 );
			if ( $expected_revision && $expected_revision !== $current_revision ) {
				return new WP_Error( 'clms_grade_revision_conflict', __( 'La nota fue modificada por otra persona. Actualiza antes de guardar.', 'atora-lms' ) );
			}
			$result = $wpdb->update(
				$table,
				array(
					'grade'         => (float) $grade,
					'scale_code'    => sanitize_key( (string) $band['code'] ),
					'status'        => 'draft',
					'revision'      => $current_revision + 1,
					'source_json'   => wp_json_encode( is_array( $details ) ? $details : array() ),
					'last_actor_id' => absint( $actor_id ?: get_current_user_id() ),
				),
				array( 'id' => absint( $current['id'] ), 'revision' => $current_revision ),
				array( '%f', '%s', '%s', '%d', '%s', '%d' ),
				array( '%d', '%d' )
			);
			if ( 1 !== $result ) {
				return new WP_Error( 'clms_grade_concurrent_update', __( 'No se pudo guardar porque la nota cambió durante la operación.', 'atora-lms' ) );
			}
			$grade_id = absint( $current['id'] );
			$revision = $current_revision + 1;
			$previous = $current['grade'];
		} else {
			$result = $wpdb->insert(
				$table,
				array(
					'cycle_id'      => absint( $cycle_id ),
					'student_id'    => absint( $student_id ),
					'course_id'     => absint( $cycle['course_id'] ),
					'grade'         => (float) $grade,
					'scale_code'    => sanitize_key( (string) $band['code'] ),
					'status'        => 'draft',
					'revision'      => 1,
					'source_json'   => wp_json_encode( is_array( $details ) ? $details : array() ),
					'last_actor_id' => absint( $actor_id ?: get_current_user_id() ),
				),
				array( '%d', '%d', '%d', '%f', '%s', '%s', '%d', '%s', '%d' )
			);
			if ( ! $result ) {
				return new WP_Error( 'clms_grade_insert_failed', __( 'No se pudo guardar la nota.', 'atora-lms' ) );
			}
			$grade_id = (int) $wpdb->insert_id;
			$revision = 1;
			$previous = null;
		}

		$this->log_event( 'grade_saved', 'institutional_grade', $grade_id, array( 'cycle_id' => absint( $cycle_id ), 'student_id' => absint( $student_id ), 'previous' => $previous, 'grade' => (float) $grade, 'revision' => $revision ), $actor_id );
		return array( 'id' => $grade_id, 'revision' => $revision, 'scale_code' => sanitize_key( (string) $band['code'] ) );
	}

	public function transition_cycle( $cycle_id, $target_status, $expected_lock_version = 0, $actor_id = 0 ) {
		global $wpdb;

		$cycle = $this->get_row( 'atora_gradebook_cycles', $cycle_id );
		if ( empty( $cycle ) ) {
			return new WP_Error( 'clms_cycle_not_found', __( 'Ciclo de calificaciones no encontrado.', 'atora-lms' ) );
		}

		$target_status = sanitize_key( (string) $target_status );
		if ( ! CLMS_Institutional_Gradebook_Policy::can_transition_cycle( $cycle['status'], $target_status ) ) {
			return new WP_Error( 'clms_cycle_invalid_transition', __( 'La transición del ciclo no está permitida.', 'atora-lms' ) );
		}

		$lock_version = absint( $cycle['lock_version'] ?? 1 );
		if ( $expected_lock_version && $expected_lock_version !== $lock_version ) {
			return new WP_Error( 'clms_cycle_revision_conflict', __( 'El ciclo cambió durante la operación. Actualiza antes de continuar.', 'atora-lms' ) );
		}

		$records = $wpdb->get_results(
			$wpdb->prepare( "SELECT student_id, grade, scale_code, status, revision FROM {$wpdb->prefix}atora_institutional_grades WHERE cycle_id = %d ORDER BY student_id ASC", absint( $cycle_id ) ),
			ARRAY_A
		);

		if ( in_array( $target_status, array( 'published', 'closed' ), true ) && empty( $records ) ) {
			return new WP_Error( 'clms_cycle_empty', __( 'No se puede publicar o cerrar un ciclo sin calificaciones.', 'atora-lms' ) );
		}

		$snapshot_records = $records;
		if ( in_array( $target_status, array( 'published', 'closed' ), true ) ) {
			$snapshot_records = array_map(
				static function ( $record ) use ( $target_status ) {
					$record['status'] = $target_status;
					return $record;
				},
				$records
			);
		}

		$now  = current_time( 'mysql', true );
		$data = array(
			'status'        => $target_status,
			'lock_version'  => $lock_version + 1,
			'snapshot_hash' => CLMS_Institutional_Gradebook_Policy::canonical_snapshot_hash( $snapshot_records ),
			'snapshot_json' => wp_json_encode( $snapshot_records ),
		);
		$formats = array( '%s', '%d', '%s', '%s' );

		if ( 'published' === $target_status ) {
			$data['published_at'] = $now;
			$data['published_by'] = absint( $actor_id ?: get_current_user_id() );
			$formats[] = '%s';
			$formats[] = '%d';
		}
		if ( 'closed' === $target_status ) {
			$data['closed_at'] = $now;
			$data['closed_by'] = absint( $actor_id ?: get_current_user_id() );
			$formats[] = '%s';
			$formats[] = '%d';
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'atora_gradebook_cycles',
			$data,
			array( 'id' => absint( $cycle_id ), 'lock_version' => $lock_version, 'status' => $cycle['status'] ),
			$formats,
			array( '%d', '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'clms_cycle_concurrent_update', __( 'El ciclo cambió durante la operación.', 'atora-lms' ) );
		}

		if ( 'published' === $target_status ) {
			$wpdb->update( $wpdb->prefix . 'atora_institutional_grades', array( 'status' => 'published' ), array( 'cycle_id' => absint( $cycle_id ) ), array( '%s' ), array( '%d' ) );
		} elseif ( 'closed' === $target_status ) {
			$wpdb->update( $wpdb->prefix . 'atora_institutional_grades', array( 'status' => 'closed' ), array( 'cycle_id' => absint( $cycle_id ) ), array( '%s' ), array( '%d' ) );
		}

		$this->log_event( 'cycle_status_changed', 'cycle', $cycle_id, array( 'from' => $cycle['status'], 'to' => $target_status, 'snapshot_hash' => $data['snapshot_hash'] ), $actor_id );
		return array( 'status' => $target_status, 'lock_version' => $lock_version + 1, 'snapshot_hash' => $data['snapshot_hash'] );
	}

	public function request_rectification( $grade_id, $new_grade, $reason, $actor_id = 0 ) {
		global $wpdb;

		$grade = $this->get_row( 'atora_institutional_grades', $grade_id );
		if ( empty( $grade ) ) {
			return new WP_Error( 'clms_grade_not_found', __( 'Calificación institucional no encontrada.', 'atora-lms' ) );
		}
		$cycle = $this->get_row( 'atora_gradebook_cycles', $grade['cycle_id'] );
		if ( empty( $cycle ) || ! CLMS_Institutional_Gradebook_Policy::cycle_accepts_rectifications( $cycle['status'] ) ) {
			return new WP_Error( 'clms_rectification_not_allowed', __( 'Solo se rectifican notas publicadas o cerradas.', 'atora-lms' ) );
		}

		$reason = sanitize_textarea_field( (string) $reason );
		if ( strlen( $reason ) < 10 ) {
			return new WP_Error( 'clms_rectification_reason_required', __( 'La rectificación requiere una justificación suficiente.', 'atora-lms' ) );
		}

		$scale = $this->get_row( 'atora_grading_scales', $cycle['scale_id'] );
		$bands = ! empty( $scale['bands_json'] ) ? json_decode( $scale['bands_json'], true ) : array();
		$band  = CLMS_Institutional_Gradebook_Policy::resolve_band( $new_grade, $bands );
		if ( empty( $band ) ) {
			return new WP_Error( 'clms_grade_out_of_scale', __( 'La nota propuesta está fuera de la escala.', 'atora-lms' ) );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'atora_grade_rectifications',
			array(
				'cycle_id'       => absint( $grade['cycle_id'] ),
				'grade_id'       => absint( $grade_id ),
				'student_id'     => absint( $grade['student_id'] ),
				'previous_grade' => (float) $grade['grade'],
				'proposed_grade' => (float) $new_grade,
				'reason'         => $reason,
				'status'         => 'requested',
				'requested_by'   => absint( $actor_id ?: get_current_user_id() ),
			),
			array( '%d', '%d', '%d', '%f', '%f', '%s', '%s', '%d' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'clms_rectification_insert_failed', __( 'No se pudo registrar la rectificación.', 'atora-lms' ) );
		}

		$this->log_event( 'rectification_requested', 'rectification', (int) $wpdb->insert_id, array( 'grade_id' => absint( $grade_id ), 'previous' => (float) $grade['grade'], 'proposed' => (float) $new_grade ), $actor_id );
		return (int) $wpdb->insert_id;
	}

	public function decide_rectification( $rectification_id, $decision, $actor_id = 0 ) {
		global $wpdb;

		$decision = sanitize_key( (string) $decision );
		if ( ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
			return new WP_Error( 'clms_rectification_invalid_decision', __( 'Decisión de rectificación no válida.', 'atora-lms' ) );
		}

		$rectification = $this->get_row( 'atora_grade_rectifications', $rectification_id );
		if ( empty( $rectification ) || 'requested' !== $rectification['status'] ) {
			return new WP_Error( 'clms_rectification_not_pending', __( 'La rectificación no está pendiente.', 'atora-lms' ) );
		}

		$actor_id = absint( $actor_id ?: get_current_user_id() );
		if ( $actor_id === absint( $rectification['requested_by'] ) ) {
			return new WP_Error( 'clms_rectification_separation_of_duties', __( 'Quien solicita una rectificación no puede aprobarla.', 'atora-lms' ) );
		}

		$wpdb->query( 'START TRANSACTION' );
		$updated = $wpdb->update(
			$wpdb->prefix . 'atora_grade_rectifications',
			array( 'status' => $decision, 'decided_by' => $actor_id, 'decided_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $rectification_id ), 'status' => 'requested' ),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);
		if ( 1 !== $updated ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'clms_rectification_concurrent_update', __( 'La rectificación ya fue procesada.', 'atora-lms' ) );
		}

		if ( 'approved' === $decision ) {
			$grade = $this->get_row( 'atora_institutional_grades', $rectification['grade_id'] );
			$cycle = $this->get_row( 'atora_gradebook_cycles', $rectification['cycle_id'] );
			$scale = $this->get_row( 'atora_grading_scales', $cycle['scale_id'] ?? 0 );
			$bands = ! empty( $scale['bands_json'] ) ? json_decode( $scale['bands_json'], true ) : array();
			$band  = CLMS_Institutional_Gradebook_Policy::resolve_band( $rectification['proposed_grade'], $bands );
			if ( empty( $grade ) || empty( $band ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'clms_rectification_context_invalid', __( 'No se pudo reconstruir el contexto de la rectificación.', 'atora-lms' ) );
			}

			$applied = $wpdb->update(
				$wpdb->prefix . 'atora_institutional_grades',
				array(
					'grade'         => (float) $rectification['proposed_grade'],
					'scale_code'    => sanitize_key( (string) $band['code'] ),
					'status'        => 'rectified',
					'revision'      => absint( $grade['revision'] ) + 1,
					'last_actor_id' => $actor_id,
				),
				array( 'id' => absint( $grade['id'] ), 'revision' => absint( $grade['revision'] ) ),
				array( '%f', '%s', '%s', '%d', '%d' ),
				array( '%d', '%d' )
			);
			if ( 1 !== $applied ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'clms_rectification_grade_conflict', __( 'La nota cambió antes de aplicar la rectificación.', 'atora-lms' ) );
			}

			$wpdb->update(
				$wpdb->prefix . 'atora_grade_rectifications',
				array( 'status' => 'applied', 'applied_at' => current_time( 'mysql', true ) ),
				array( 'id' => absint( $rectification_id ), 'status' => 'approved' ),
				array( '%s', '%s' ),
				array( '%d', '%s' )
			);
		}

		$wpdb->query( 'COMMIT' );
		$this->log_event( 'rectification_' . ( 'approved' === $decision ? 'applied' : 'rejected' ), 'rectification', $rectification_id, array( 'grade_id' => absint( $rectification['grade_id'] ) ), $actor_id );
		return true;
	}

	public function get_context( $academy_id = 0, $course_id = 0, $cycle_id = 0 ) {
		global $wpdb;

		$academy_id = absint( $academy_id );
		$course_id  = absint( $course_id );
		$cycle_id   = absint( $cycle_id );

		$periods = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_academic_periods WHERE academy_id = %d ORDER BY starts_at DESC, id DESC", $academy_id ),
			ARRAY_A
		);
		$scales = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_grading_scales WHERE academy_id = %d ORDER BY code ASC, version DESC", $academy_id ),
			ARRAY_A
		);

		if ( $course_id ) {
			$cycles = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_gradebook_cycles WHERE academy_id = %d AND course_id = %d ORDER BY id DESC", $academy_id, $course_id ),
				ARRAY_A
			);
		} else {
			$cycles = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_gradebook_cycles WHERE academy_id = %d ORDER BY id DESC", $academy_id ),
				ARRAY_A
			);
		}

		$grades = array();
		$rectifications = array();
		if ( $cycle_id ) {
			$grades = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_institutional_grades WHERE cycle_id = %d ORDER BY student_id ASC", $cycle_id ),
				ARRAY_A
			);
			$rectifications = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_grade_rectifications WHERE cycle_id = %d ORDER BY id DESC", $cycle_id ),
				ARRAY_A
			);
		}

		return array(
			'periods'        => is_array( $periods ) ? $periods : array(),
			'scales'         => is_array( $scales ) ? $scales : array(),
			'cycles'         => is_array( $cycles ) ? $cycles : array(),
			'grades'         => is_array( $grades ) ? $grades : array(),
			'rectifications' => is_array( $rectifications ) ? $rectifications : array(),
		);
	}

	public function get_row( $table_suffix, $id ) {
		global $wpdb;

		$allowed = array(
			'atora_academic_periods',
			'atora_grading_scales',
			'atora_gradebook_cycles',
			'atora_institutional_grades',
			'atora_grade_rectifications',
		);
		$table_suffix = sanitize_key( (string) $table_suffix );
		if ( ! in_array( $table_suffix, $allowed, true ) ) {
			return array();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table_suffix} WHERE id = %d LIMIT 1", absint( $id ) ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : array();
	}

	protected function normalize_date( $value ) {
		$value = sanitize_text_field( (string) $value );
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
	}

	protected function log_event( $action, $object_type, $object_id, $details, $actor_id = 0 ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'atora_gradebook_events',
			array(
				'actor_id'    => absint( $actor_id ?: get_current_user_id() ),
				'action'      => sanitize_key( (string) $action ),
				'object_type' => sanitize_key( (string) $object_type ),
				'object_id'   => absint( $object_id ),
				'details_json'=> wp_json_encode( is_array( $details ) ? $details : array() ),
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		do_action( 'atora/gradebook/institutional_event', $action, $object_type, absint( $object_id ), $details );
	}
}
