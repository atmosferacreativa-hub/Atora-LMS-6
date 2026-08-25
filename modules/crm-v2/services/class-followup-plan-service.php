<?php
/**
 * Followup_Plan_Service — PT-1/PT-3/PT-4 backend (sprint 6.6.0).
 *
 * CRUD de planes de seguimiento, biblioteca de plantillas (PT-3),
 * generación de ocurrencias materializadas sobre
 * atora_calendar_events (reutiliza la tabla existente — PT-1.2, no
 * crea un motor de calendario paralelo), y las acciones del panel
 * lateral (PT-4.4/4.6/4.7): marcar contactado, saltar una ocurrencia,
 * excluir un estudiante puntual.
 *
 * Principio central de la OT, repetido acá porque gobierna todo este
 * archivo: marcar "contactado" registra un contacto — NUNCA mueve la
 * etapa del estudiante en Student_Followup_Service. Ningún método de
 * esta clase escribe en atora_crm_student_followups.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Followup_Plan_Service {

	const EVENT_TYPE = 'followup_checkin';

	/**
	 * PT-3.1: cuatro plantillas de arranque, ancladas a etapas reales
	 * de Student_Followup_Service::get_stages() — no se inventan
	 * etapas nuevas. Registro estático (no una fila de BD): por
	 * construcción, ningún docente puede editar esta lista para el
	 * resto de la instalación (PT-3.3) — solo puede aplicar una
	 * plantilla y guardar SU COPIA ajustada como un plan propio
	 * (guardado real, editable, en atora_followup_plans).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_templates(): array {
		$active_stages = array_values( array_filter(
			array_keys( Student_Followup_Service::get_stages() ),
			static fn( $s ) => ! in_array( $s, array( 'graduated', 'completed', 'inactive' ), true )
		) );

		return array(
			'weekly_checkin' => array(
				'name'            => __( 'Chequeo semanal', 'atora-lms' ),
				'description'     => __( 'Revisión semanal de estudiantes con bajo avance o en riesgo.', 'atora-lms' ),
				'stage_filter'    => array( 'low_progress', 'at_risk' ),
				'recurrence_rule' => 'WEEKLY;BYDAY=MO',
				'action_type'     => 'checkin',
			),
			'high_attention' => array(
				'name'            => __( 'Alta atención', 'atora-lms' ),
				'description'     => __( 'Revisión frecuente de estudiantes en intervención o que requieren apoyo.', 'atora-lms' ),
				'stage_filter'    => array( 'intervention', 'needs_support' ),
				'recurrence_rule' => 'DAILY;INTERVAL=3',
				'action_type'     => 'checkin',
			),
			'before_closing' => array(
				'name'            => __( 'Antes del cierre', 'atora-lms' ),
				'description'     => __( 'Revisión semanal de toda la sección, más frecuente cerca del cierre del período.', 'atora-lms' ),
				'stage_filter'    => $active_stages,
				'recurrence_rule' => 'WEEKLY;BYDAY=MO;INTENSIFY_DAYS=14',
				'action_type'     => 'checkin',
			),
			'milestones_only' => array(
				'name'            => __( 'Solo hitos', 'atora-lms' ),
				'description'     => __( 'Fechas puntuales que vos elegís, sin patrón automático.', 'atora-lms' ),
				'stage_filter'    => array_keys( Student_Followup_Service::get_stages() ),
				'recurrence_rule' => 'FIXED;DATES=',
				'action_type'     => 'checkin',
			),
		);
	}

	// ── CRUD de planes ───────────────────────────────────────────────────────

	/**
	 * @param int $plan_id
	 * @return array<string,mixed>|null
	 */
	public static function get_plan( int $plan_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_plans';
		if ( ! self::table_exists( $table ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $plan_id ), ARRAY_A );

		return is_array( $row ) ? self::normalize_plan_row( $row ) : null;
	}

	/**
	 * @param int    $teacher_id
	 * @param string $domain Opcional: 'academic'|'commercial' para filtrar. '' (default) = todos los dominios del usuario —
	 *                       PT-5.1: quien tiene planes de ambos dominios los ve todos acá, el selector de dominio de la UI filtra client-side.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_plans_for_teacher( int $teacher_id, string $domain = '' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_plans';
		if ( ! self::table_exists( $table ) || ! $teacher_id ) {
			return array();
		}

		$domain = self::sanitize_domain( $domain, '' );

		if ( '' !== $domain ) {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE teacher_id = %d AND domain = %s ORDER BY active DESC, name ASC", $teacher_id, $domain ),
				ARRAY_A
			);
		} else {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE teacher_id = %d ORDER BY active DESC, name ASC", $teacher_id ),
				ARRAY_A
			);
		}

		return array_map( array( __CLASS__, 'normalize_plan_row' ), $rows );
	}

	/**
	 * Crea un plan y materializa sus primeras ocurrencias.
	 *
	 * @param array $data {teacher_id, name, template_key, section_ids, stage_filter, recurrence_rule, action_type, end_date, save_as_template_name}
	 * @return int|\WP_Error ID del plan creado.
	 */
	public static function create_plan( array $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_plans';
		if ( ! self::table_exists( $table ) ) {
			return new \WP_Error( 'no_table', __( 'La tabla de planes de seguimiento no existe todavía.', 'atora-lms' ) );
		}

		$teacher_id      = absint( $data['teacher_id'] ?? get_current_user_id() );
		$domain          = self::sanitize_domain( (string) ( $data['domain'] ?? 'academic' ) );
		$name            = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
		$section_ids     = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $data['section_ids'] ?? array() ) ) ) ) );
		$stage_filter    = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $data['stage_filter'] ?? array() ) ) ) ) );
		$domain_config   = is_array( $data['domain_config'] ?? null ) ? $data['domain_config'] : array();
		$recurrence_rule = sanitize_text_field( (string) ( $data['recurrence_rule'] ?? '' ) );
		$template_key    = isset( $data['template_key'] ) ? sanitize_key( (string) $data['template_key'] ) : null;
		$action_type     = sanitize_key( (string) ( $data['action_type'] ?? 'checkin' ) ) ?: 'checkin';
		$end_date        = self::sanitize_date_or_null( $data['end_date'] ?? null );

		// Validación de alcance por dominio (PT-1.5):
		//   - academic (comportamiento idéntico a 6.6.0): section_ids y
		//     stage_filter son AMBOS obligatorios.
		//   - commercial, modo por-etapa: section_ids puede ir vacío —
		//     el alcance implícito es la cartera completa del vendedor
		//     (Commercial_Domain_Provider la acota por owner_id); solo
		//     stage_filter es obligatorio.
		//   - commercial, modo selección manual ("Cuenta clave", sin
		//     stage_filter): section_ids es obligatorio — ahí SON los
		//     contact_ids elegidos a mano, no un agrupador.
		if ( 'commercial' === $domain ) {
			$scope_ok = ! empty( $stage_filter ) || ! empty( $section_ids );
		} else {
			$scope_ok = ! empty( $section_ids ) && ! empty( $stage_filter );
		}

		if ( ! $teacher_id || '' === $name || ! $scope_ok || '' === $recurrence_rule ) {
			return new \WP_Error( 'datos_incompletos', __( 'Faltan datos para crear el plan (nombre, alcance, etapas o frecuencia).', 'atora-lms' ) );
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'teacher_id'      => $teacher_id,
				'domain'          => $domain,
				'name'            => $name,
				'template_key'    => $template_key,
				'section_ids'     => wp_json_encode( $section_ids ),
				'stage_filter'    => wp_json_encode( $stage_filter ),
				'domain_config'   => empty( $domain_config ) ? null : wp_json_encode( $domain_config ),
				'recurrence_rule' => $recurrence_rule,
				'action_type'     => $action_type,
				'active'          => 1,
				'end_date'        => $end_date,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new \WP_Error( 'db_error', __( 'No se pudo guardar el plan.', 'atora-lms' ) );
		}

		$plan_id = (int) $wpdb->insert_id;

		// Ventana de materialización inicial: hoy -> +90 días (o end_date si es antes).
		$range_end = $end_date ? min( $end_date, gmdate( 'Y-m-d', strtotime( '+90 days' ) ) ) : gmdate( 'Y-m-d', strtotime( '+90 days' ) );
		self::generate_occurrences( $plan_id, gmdate( 'Y-m-d' ), $range_end );

		do_action( 'atora/followup_plan/created', $plan_id, $teacher_id );

		return $plan_id;
	}

	/**
	 * PT-4.5: pausar sin borrar — no genera nuevas ocurrencias, pero
	 * conserva historial (filas ya materializadas) y configuración.
	 *
	 * @param int $plan_id
	 * @return bool
	 */
	public static function pause_plan( int $plan_id ): bool {
		return self::set_plan_active( $plan_id, false );
	}

	/**
	 * @param int $plan_id
	 * @return bool
	 */
	public static function resume_plan( int $plan_id ): bool {
		if ( ! self::set_plan_active( $plan_id, true ) ) {
			return false;
		}

		// Al reanudar, se re-extiende la ventana de ocurrencias futuras
		// (pausado pudo haber dejado un hueco sin materializar).
		$plan = self::get_plan( $plan_id );
		if ( $plan ) {
			$range_end = $plan['end_date'] ? min( $plan['end_date'], gmdate( 'Y-m-d', strtotime( '+90 days' ) ) ) : gmdate( 'Y-m-d', strtotime( '+90 days' ) );
			self::generate_occurrences( $plan_id, gmdate( 'Y-m-d' ), $range_end );
		}

		return true;
	}

	/**
	 * @param int  $plan_id
	 * @param bool $active
	 * @return bool
	 */
	private static function set_plan_active( int $plan_id, bool $active ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_plans';
		if ( ! self::table_exists( $table ) || ! $plan_id ) {
			return false;
		}

		return false !== $wpdb->update( $table, array( 'active' => $active ? 1 : 0 ), array( 'id' => $plan_id ), array( '%d' ), array( '%d' ) );
	}

	// ── Ocurrencias (materializadas sobre atora_calendar_events) ────────────

	/**
	 * Genera (si no existen ya) las filas de atora_calendar_events
	 * correspondientes a las fechas del recurrence_rule del plan
	 * dentro del rango indicado. Idempotente: una fecha ya
	 * materializada para este plan no se duplica.
	 *
	 * @param int    $plan_id
	 * @param string $range_start Y-m-d.
	 * @param string $range_end   Y-m-d.
	 * @return int Cantidad de ocurrencias nuevas creadas.
	 */
	public static function generate_occurrences( int $plan_id, string $range_start, string $range_end ): int {
		global $wpdb;

		$plan = self::get_plan( $plan_id );
		if ( ! $plan || ! $plan['active'] ) {
			return 0;
		}

		$dates = Followup_Recurrence::expand( $plan['recurrence_rule'], $range_start, $range_end, $plan['end_date'] );
		if ( empty( $dates ) ) {
			return 0;
		}

		$events_table = $wpdb->prefix . 'atora_calendar_events';
		$existing     = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DATE(start_datetime) FROM {$events_table} WHERE followup_plan_id = %d AND DATE(start_datetime) BETWEEN %s AND %s",
				$plan_id,
				$range_start,
				$range_end
			)
		);

		$created = 0;
		foreach ( $dates as $date ) {
			if ( in_array( $date, $existing, true ) ) {
				continue;
			}

			// El título con secciones anexadas es una noción académica
			// (section_ids ahí SON secciones); en comercial, section_ids
			// puede ser vacío (cartera completa) o una lista de
			// contact_ids ("Cuenta clave") — ninguno de los dos es un
			// título legible, así que ese dominio usa solo el nombre del
			// plan. Comportamiento académico sin cambios (6.6.0).
			$title = $plan['name'];
			if ( 'academic' === $plan['domain'] ) {
				$section_titles = array();
				foreach ( (array) $plan['section_ids'] as $section_id ) {
					if ( class_exists( '\ATORA\LMS\Section_Service' ) ) {
						$section = \ATORA\LMS\Section_Service::get( (int) $section_id );
						if ( $section ) {
							$section_titles[] = sanitize_text_field( (string) $section['title'] );
						}
					}
				}
				$title = $plan['name'] . ( $section_titles ? ' — ' . implode( ', ', $section_titles ) : '' );
			}

			$inserted = $wpdb->insert(
				$events_table,
				array(
					'title'           => sanitize_text_field( $title ),
					'description'     => '',
					'event_type'      => self::EVENT_TYPE,
					'start_datetime'  => $date . ' 08:00:00',
					'end_datetime'    => null,
					'course_id'       => 0,
					'lesson_id'       => 0,
					'user_id'         => (int) $plan['teacher_id'],
					'location'        => '',
					'max_participants'=> 0,
					'recurrence_rule' => '',
					'followup_plan_id'=> $plan_id,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%d' )
			);

			if ( $inserted ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Cron diario (PT-1.3 backstop + mantenimiento de ventana): extiende
	 * la ventana materializada de todo plan activo, y desactiva los
	 * planes sin end_date cuyas secciones ya cerraron su período.
	 *
	 * @return void
	 */
	public static function run_daily_maintenance(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_plans';
		if ( ! self::table_exists( $table ) ) {
			return;
		}

		$plan_ids = (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE active = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $plan_ids as $plan_id ) {
			$plan_id = (int) $plan_id;
			if ( self::maybe_deactivate_on_section_closure( $plan_id ) ) {
				continue; // se acaba de desactivar — no extender su ventana.
			}

			$plan = self::get_plan( $plan_id );
			if ( ! $plan ) {
				continue;
			}
			$range_end = $plan['end_date'] ? min( $plan['end_date'], gmdate( 'Y-m-d', strtotime( '+90 days' ) ) ) : gmdate( 'Y-m-d', strtotime( '+90 days' ) );
			self::generate_occurrences( $plan_id, gmdate( 'Y-m-d' ), $range_end );
		}
	}

	/**
	 * PT-1.3 (6.6.0): si el plan no tiene end_date propio, se apaga
	 * solo cuando NINGUNA de sus secciones sigue con el período
	 * abierto — reutiliza la señal de cierre que el LMS ya tiene
	 * (Section_Service::get()['status']/'end_date'), no inventa una
	 * nueva. Una sección sin end_date (abierta indefinidamente)
	 * mantiene el plan activo por esa sola sección.
	 *
	 * Solo aplica al dominio académico (6.7.0): el pipeline comercial
	 * no tiene un concepto de "cierre de período" que reutilizar — un
	 * plan comercial sin end_date sigue generando ocurrencias
	 * indefinidamente hasta que el vendedor lo pausa a mano. Documentado
	 * en docs/DEUDA-TECNICA.md como una simplificación deliberada, no
	 * una omisión.
	 *
	 * @param int $plan_id
	 * @return bool true si el plan se desactivó en esta llamada.
	 */
	private static function maybe_deactivate_on_section_closure( int $plan_id ): bool {
		$plan = self::get_plan( $plan_id );
		if ( ! $plan || ! $plan['active'] || $plan['end_date'] || 'academic' !== $plan['domain'] ) {
			return false; // tiene end_date propio, o no es del dominio académico — esta regla no aplica.
		}

		if ( ! class_exists( '\ATORA\LMS\Section_Service' ) || empty( $plan['section_ids'] ) ) {
			return false;
		}

		$today       = gmdate( 'Y-m-d' );
		$any_open    = false;
		foreach ( (array) $plan['section_ids'] as $section_id ) {
			$section = \ATORA\LMS\Section_Service::get( (int) $section_id );
			if ( ! $section ) {
				continue;
			}
			$closed_by_status = 'closed' === ( $section['status'] ?? '' );
			$section_end      = (string) ( $section['end_date'] ?? '' );
			$closed_by_date   = '' !== $section_end && $section_end < $today;

			if ( ! $closed_by_status && ! $closed_by_date ) {
				$any_open = true;
				break;
			}
		}

		if ( $any_open ) {
			return false;
		}

		self::set_plan_active( $plan_id, false );
		return true;
	}

	// ── Acciones sobre una ocurrencia puntual (panel lateral, PT-4.4/4.6/4.7) ──

	/**
	 * PT-4.6: salta una ocurrencia puntual sin afectar el resto de la
	 * serie — se elimina esa única fila de atora_calendar_events (y su
	 * estado/contactos asociados); las demás ocurrencias del plan no
	 * se tocan.
	 *
	 * @param int $event_id
	 * @return bool
	 */
	public static function skip_occurrence( int $event_id ): bool {
		global $wpdb;

		$events_table = $wpdb->prefix . 'atora_calendar_events';
		$is_plan_event = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE id = %d AND followup_plan_id IS NOT NULL", $event_id )
		) > 0;
		if ( ! $is_plan_event ) {
			return false;
		}

		self::delete_occurrence_state( $event_id );
		return false !== $wpdb->delete( $events_table, array( 'id' => $event_id ), array( '%d' ) );
	}

	/**
	 * PT-4.3: arrastrar para reprogramar — mueve una ocurrencia a otro
	 * día sin abrir formulario y sin romper el patrón del resto de la
	 * serie (las demás filas, ya materializadas de forma independiente,
	 * no se recalculan).
	 *
	 * @param int    $event_id
	 * @param string $new_date Y-m-d.
	 * @return bool
	 */
	public static function reschedule_occurrence( int $event_id, string $new_date ): bool {
		global $wpdb;

		$new_date = self::sanitize_date_or_null( $new_date );
		if ( ! $new_date ) {
			return false;
		}

		$events_table = $wpdb->prefix . 'atora_calendar_events';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, followup_plan_id FROM {$events_table} WHERE id = %d LIMIT 1", $event_id ), ARRAY_A );
		if ( empty( $row ) || empty( $row['followup_plan_id'] ) ) {
			return false;
		}

		return false !== $wpdb->update(
			$events_table,
			array( 'start_datetime' => $new_date . ' 08:00:00' ),
			array( 'id' => $event_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * PT-4.7: excluye un estudiante de esta ocurrencia puntual —
	 * nunca altera el plan completo ni futuras ocurrencias.
	 *
	 * @param int $event_id
	 * @param int $user_id
	 * @return bool
	 */
	public static function exclude_student_from_occurrence( int $event_id, int $user_id ): bool {
		$excluded   = self::get_excluded_students( $event_id );
		$excluded[] = $user_id;
		return self::save_occurrence_state( $event_id, array( 'excluded_user_ids' => array_values( array_unique( $excluded ) ) ) );
	}

	/**
	 * @param int $event_id
	 * @return array<int,int>
	 */
	public static function get_excluded_students( int $event_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_occurrence_state';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT excluded_user_ids FROM {$table} WHERE event_id = %d LIMIT 1", $event_id ) );
		if ( ! $raw ) {
			return array();
		}

		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? array_values( array_filter( array_map( 'absint', $decoded ) ) ) : array();
	}

	/**
	 * @param int   $event_id
	 * @param array $fields {excluded_user_ids?, skipped?}
	 * @return bool
	 */
	private static function save_occurrence_state( int $event_id, array $fields ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_occurrence_state';
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE event_id = %d LIMIT 1", $event_id ) );

		$data   = array();
		$format = array();
		if ( array_key_exists( 'excluded_user_ids', $fields ) ) {
			$data['excluded_user_ids'] = wp_json_encode( array_values( array_map( 'absint', (array) $fields['excluded_user_ids'] ) ) );
			$format[]                  = '%s';
		}
		if ( array_key_exists( 'skipped', $fields ) ) {
			$data['skipped'] = ! empty( $fields['skipped'] ) ? 1 : 0;
			$format[]        = '%d';
		}
		if ( empty( $data ) ) {
			return false;
		}

		if ( $existing_id ) {
			return false !== $wpdb->update( $table, $data, array( 'id' => $existing_id ), $format, array( '%d' ) );
		}

		$data['event_id'] = $event_id;
		$format[]          = '%d';
		return false !== $wpdb->insert( $table, $data, $format );
	}

	/**
	 * @param int $event_id
	 * @return void
	 */
	private static function delete_occurrence_state( int $event_id ): void {
		global $wpdb;

		$state_table    = $wpdb->prefix . 'atora_followup_occurrence_state';
		$contacts_table = $wpdb->prefix . 'atora_followup_contacts';
		if ( self::table_exists( $state_table ) ) {
			$wpdb->delete( $state_table, array( 'event_id' => $event_id ), array( '%d' ) );
		}
		if ( self::table_exists( $contacts_table ) ) {
			$wpdb->delete( $contacts_table, array( 'event_id' => $event_id ), array( '%d' ) );
		}
	}

	/**
	 * PT-4.4: registra un contacto — no toca la etapa del estudiante en
	 * ningún momento. Idempotente: volver a marcar al mismo estudiante
	 * en la misma ocurrencia solo actualiza contacted_at, no duplica fila
	 * (UNIQUE KEY event_user).
	 *
	 * @param int $event_id
	 * @param int $user_id
	 * @param int $contacted_by Docente que registra el contacto (0 = usuario actual).
	 * @return bool
	 */
	public static function mark_contacted( int $event_id, int $user_id, int $contacted_by = 0 ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_contacts';
		if ( ! self::table_exists( $table ) || ! $event_id || ! $user_id ) {
			return false;
		}

		$contacted_by = $contacted_by ?: get_current_user_id();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (event_id, user_id, contacted_by, contacted_at)
				 VALUES (%d, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE contacted_by = VALUES(contacted_by), contacted_at = VALUES(contacted_at)",
				$event_id,
				$user_id,
				$contacted_by,
				current_time( 'mysql', true )
			)
		);

		return false !== $result;
	}

	/**
	 * @param int $event_id
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_contacted_students( int $event_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'atora_followup_contacts';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, contacted_by, contacted_at FROM {$table} WHERE event_id = %d", $event_id ),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'user_id'      => absint( $row['user_id'] ?? 0 ),
					'contacted_by' => absint( $row['contacted_by'] ?? 0 ),
					'contacted_at' => sanitize_text_field( (string) ( $row['contacted_at'] ?? '' ) ),
				);
			},
			$rows
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * @param array $row
	 * @return array<string,mixed>
	 */
	private static function normalize_plan_row( array $row ): array {
		$section_ids   = json_decode( (string) ( $row['section_ids'] ?? '' ), true );
		$stage_filter  = json_decode( (string) ( $row['stage_filter'] ?? '' ), true );
		$domain_config = json_decode( (string) ( $row['domain_config'] ?? '' ), true );

		return array(
			'id'              => absint( $row['id'] ?? 0 ),
			'teacher_id'      => absint( $row['teacher_id'] ?? 0 ),
			'domain'          => self::sanitize_domain( (string) ( $row['domain'] ?? 'academic' ) ),
			'name'            => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			'template_key'    => $row['template_key'] ? sanitize_key( (string) $row['template_key'] ) : null,
			'section_ids'     => is_array( $section_ids ) ? array_values( array_map( 'absint', $section_ids ) ) : array(),
			'stage_filter'    => is_array( $stage_filter ) ? array_values( array_map( 'sanitize_key', $stage_filter ) ) : array(),
			'domain_config'   => is_array( $domain_config ) ? $domain_config : array(),
			'recurrence_rule' => sanitize_text_field( (string) ( $row['recurrence_rule'] ?? '' ) ),
			'action_type'     => sanitize_key( (string) ( $row['action_type'] ?? 'checkin' ) ),
			'active'          => ! empty( $row['active'] ),
			'end_date'        => $row['end_date'] ?: null,
			'created_at'      => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			'updated_at'      => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
		);
	}

	/**
	 * @param string $domain
	 * @param string $default Valor de respaldo si $domain no es uno de los dominios soportados. '' es un valor
	 *                         válido de $default (usado por get_plans_for_teacher() para significar "sin filtrar").
	 * @return string
	 */
	private static function sanitize_domain( string $domain, string $default = 'academic' ): string {
		$domain  = sanitize_key( $domain );
		$allowed = array( 'academic', 'commercial' );
		return in_array( $domain, $allowed, true ) ? $domain : $default;
	}

	/**
	 * @param mixed $value
	 * @return string|null
	 */
	private static function sanitize_date_or_null( $value ): ?string {
		$value = sanitize_text_field( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	/**
	 * @param string $table Nombre completo (con prefijo).
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}
}
