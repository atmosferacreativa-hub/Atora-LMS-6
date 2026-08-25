<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PT-1 (6.11.0): implementación real de las primitivas de contacto que
 * históricamente vivían solo en \ATORA\CRM\CRM (modules/crm/, "CRM v1").
 * Este servicio se registra en el grupo 'core' del loader (sin condition:
 * 'module:crm') para que exista siempre, sea cual sea el estado del módulo
 * CRM -- así el módulo académico (y cualquier código nuevo) puede depender
 * de "puedo ver este contacto" / "registrá esta actividad" sin engancharse
 * a modules/crm/. \ATORA\CRM\CRM ahora delega acá (ver trait-crm-access.php
 * y trait-crm-contacts.php) en vez de duplicar la lógica, así que los ~40
 * call sites existentes de CRM:: siguen funcionando sin cambios.
 *
 * upsert_contact() deliberadamente NO se migró: su resolución de contacto
 * por teléfono/WhatsApp (find_contact_id_by_phone/normalize_contact_phone,
 * en trait-crm-events-messaging.php) está entrelazada con el pipeline de
 * ingestión de mensajes entrantes de v1 -- migrarla habría significado
 * arrastrar esa maquinaria completa por una sola primitiva que este sprint
 * no necesita. log_activity() (abajo) sí necesita poder crear un contacto
 * cuando no existe, así que usa ensure_contact_for_user(), una versión
 * mínima (sin matching por teléfono, sin sync de user_meta) suficiente para
 * ese único uso interno.
 */
class CLMS_Contacts_Core_Service {

	/**
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public function can_access_crm( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return false;
		}

		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
		if ( user_can( $user_id, 'manage_options' ) || $is_super_admin ) {
			return true;
		}

		$allowed_caps = array(
			'manage_options',
			'clms_access_crm_view',
			'clms_manage_crm',
			'clms_access_admin',
			'clms_manage_commerce',
			'clms_manage_courses',
			'clms_manage_lessons',
			'clms_view_teacher_dashboard',
			'clms_grade_submissions',
		);

		$can_access = false;
		foreach ( $allowed_caps as $capability ) {
			if ( user_can( $user_id, $capability ) ) {
				$can_access = true;
				break;
			}
		}

		/** Ver docblock de atora_crm_can_access_user en el CRM v1 original -- mismo filtro. */
		return (bool) apply_filters( 'atora_crm_can_access_user', $can_access, $user_id, $allowed_caps );
	}

	/**
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public function can_manage_crm( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		return $user_id && ( user_can( $user_id, 'clms_manage_crm' ) || user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public function has_global_contact_scope( int $user_id = 0 ): bool {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return false;
		}

		if ( ! $this->can_access_crm( $user_id ) ) {
			return false;
		}

		$is_super_admin = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
		if ( user_can( $user_id, 'manage_options' ) || $is_super_admin ) {
			return true;
		}

		// PT-1.3 (6.5.1, heredado de v1): clms_manage_courses/clms_manage_lessons
		// son caps docentes -- cualquier lms_instructor las tiene y no debía
		// heredar alcance global de contactos por eso. clms_access_admin
		// tampoco cuenta: solo significa "puede entrar al panel ATORA".
		if ( user_can( $user_id, 'clms_manage_commerce' ) ) {
			return true;
		}

		if (
			user_can( $user_id, 'edit_posts' )
			&& ! user_can( $user_id, 'clms_view_teacher_dashboard' )
			&& ! user_can( $user_id, 'clms_grade_submissions' )
		) {
			return true;
		}

		return false;
	}

	/**
	 * @param int $user_id Usuario.
	 * @return array<int,int>
	 */
	public function get_accessible_contact_user_ids( int $user_id = 0 ): array {
		$user_id = absint( $user_id ?: get_current_user_id() );
		if ( ! $user_id ) {
			return array();
		}

		if ( $this->can_manage_crm( $user_id ) ) {
			return array();
		}

		if ( ! $this->can_access_crm( $user_id ) ) {
			return array();
		}

		if ( $this->has_global_contact_scope( $user_id ) ) {
			return array();
		}

		$student_ids = array();
		$messaging   = null;

		// PT-1 (6.11.0): guard idéntico al original de v1 (movido tal
		// cual, no reescrito) -- method_exists('\CLMS_Helper', 'module')
		// no tiene relación obvia con clms_core(), pero se preserva sin
		// tocar para no introducir una diferencia de comportamiento.
		if ( class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'module' ) ) {
			$messaging = clms_core( 'CLMS_Messaging' );
		}
		if ( ! $messaging && class_exists( '\CLMS_Messaging' ) ) {
			$messaging = new \CLMS_Messaging();
		}

		if ( $messaging && method_exists( $messaging, 'get_compose_context_for_user' ) ) {
			$context     = (array) $messaging->get_compose_context_for_user( $user_id );
			$student_ids = array_map( 'absint', array_keys( (array) ( $context['students'] ?? array() ) ) );
		}

		if ( empty( $student_ids ) && class_exists( '\CLMS_Helper' ) && method_exists( '\CLMS_Helper', 'get_enrolled_student_ids' ) ) {
			$course_ids = get_posts(
				array(
					'post_type'              => 'lm_course',
					'post_status'            => array( 'publish', 'private', 'draft' ),
					'author'                 => $user_id,
					'fields'                 => 'ids',
					'posts_per_page'         => 200,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( (array) $course_ids as $course_id ) {
				foreach ( (array) \CLMS_Helper::get_enrolled_student_ids( absint( $course_id ) ) as $student_id ) {
					$student_ids[] = absint( $student_id );
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $student_ids ) ) ) );
	}

	/**
	 * @param int $user_id Usuario.
	 * @return object|null
	 */
	public function get_contact( int $user_id ): ?object {
		global $wpdb;
		$contacts_table = "{$wpdb->prefix}atora_contacts";

		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! $this->table_exists( $contacts_table ) ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$contacts_table} WHERE user_id = %d LIMIT 1",
			$user_id
		) ) ?: null;
	}

	/**
	 * @param int    $user_id       Usuario.
	 * @param string $activity_type Tipo de actividad.
	 * @param array  $data          Datos adicionales.
	 * @return void
	 */
	public function log_activity( int $user_id, string $activity_type, array $data = array() ): void {
		global $wpdb;
		$activities_table = "{$wpdb->prefix}atora_contact_activities";

		if ( ! $this->table_exists( $activities_table ) ) {
			return;
		}

		$contact = $this->get_contact( $user_id );
		if ( ! $contact ) {
			$contact = $this->ensure_contact_for_user( $user_id );
		}

		if ( ! $contact ) {
			return;
		}

		$activity_type = sanitize_key( $activity_type );
		$inserted      = $wpdb->insert(
			$activities_table,
			array(
				'contact_id'    => $contact->id,
				'activity_type' => $activity_type,
				'activity_data' => wp_json_encode( $data ),
				'created_by'    => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d' )
		);

		if ( $inserted ) {
			do_action( 'atora/crm/activity_logged', (int) $contact->id, $activity_type, $data, $user_id );
		}
	}

	/**
	 * @param int $contact_id Contacto CRM.
	 * @param int $limit      Máximo de resultados.
	 * @return array
	 */
	public function get_timeline( int $contact_id, int $limit = 50 ): array {
		global $wpdb;
		$activities_table = "{$wpdb->prefix}atora_contact_activities";

		$contact_id = absint( $contact_id );
		$limit      = max( 1, absint( $limit ) );
		if ( $contact_id <= 0 || ! $this->table_exists( $activities_table ) ) {
			return array();
		}

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$activities_table}
			 WHERE contact_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d",
			$contact_id,
			$limit
		) );
	}

	/**
	 * PT-2 (6.11.0): conveniencia para la ficha de estudiante -- resuelve
	 * user_id -> contact_id -> timeline en un solo llamado.
	 *
	 * @param int $user_id Usuario/estudiante.
	 * @param int $limit   Máximo de resultados.
	 * @return array
	 */
	public function get_timeline_for_student( int $user_id, int $limit = 50 ): array {
		$contact = $this->get_contact( absint( $user_id ) );
		if ( ! $contact ) {
			return array();
		}

		return $this->get_timeline( (int) $contact->id, $limit );
	}

	/**
	 * PT-3 (6.11.0): cuenta actividades recientes de un tipo dado entre un
	 * conjunto de usuarios -- usado por
	 * CLMS_Today_Aggregator_Service::collect_coordinator_items() para
	 * contar alertas 'academic_at_risk_alert' de los estudiantes de las
	 * secciones que coordina, sin loopear get_student_course_status() por
	 * estudiante (que sí sería costoso -- ver el plan de este sprint).
	 * Una sola consulta con JOIN, no N+1.
	 *
	 * @param array<int> $user_ids      Usuarios a considerar.
	 * @param string     $activity_type Tipo de actividad.
	 * @param int        $since_days    Ventana en días hacia atrás.
	 * @return int
	 */
	public function count_recent_activity_for_users( array $user_ids, string $activity_type, int $since_days = 7 ): int {
		global $wpdb;
		$contacts_table    = "{$wpdb->prefix}atora_contacts";
		$activities_table  = "{$wpdb->prefix}atora_contact_activities";

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) || ! $this->table_exists( $contacts_table ) || ! $this->table_exists( $activities_table ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$since        = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $since_days ) ) * DAY_IN_SECONDS ) );

		$args = array_merge( array( sanitize_key( $activity_type ) ), $user_ids, array( $since ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$activities_table} a
				 INNER JOIN {$contacts_table} c ON c.id = a.contact_id
				 WHERE a.activity_type = %s AND c.user_id IN ({$placeholders}) AND a.created_at >= %s",
				$args
			)
		);
	}

	/**
	 * PT-2 (6.11.0): registra una nota manual (coordinador/docente) en el
	 * timeline del estudiante, con tipo 'academic_note' para poder
	 * distinguirla del resto del historial comercial en la ficha.
	 *
	 * @param int    $student_id Estudiante.
	 * @param int    $course_id  Curso en el que se registra la nota.
	 * @param string $note       Texto de la nota.
	 * @param int    $author_id  Autor de la nota.
	 * @return void
	 */
	public function log_academic_note( int $student_id, int $course_id, string $note, int $author_id ): void {
		$note = trim( wp_kses_post( $note ) );
		if ( '' === $note ) {
			return;
		}

		$this->log_activity(
			absint( $student_id ),
			'academic_note',
			array(
				'course_id' => absint( $course_id ),
				'note'      => $note,
				'author_id' => absint( $author_id ),
			)
		);
	}

	/**
	 * Versión mínima de creación de contacto, usada solo por log_activity()
	 * cuando el usuario todavía no tiene un contacto CRM -- a diferencia de
	 * CRM::upsert_contact() (v1), no resuelve por teléfono/WhatsApp ni
	 * sincroniza user_meta; solo garantiza que exista una fila para poder
	 * asociarle la actividad.
	 *
	 * @param int $user_id Usuario.
	 * @return object|null
	 */
	protected function ensure_contact_for_user( int $user_id ): ?object {
		global $wpdb;
		$contacts_table = "{$wpdb->prefix}atora_contacts";

		$user_id = absint( $user_id );
		if ( ! $user_id || ! $this->table_exists( $contacts_table ) ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}

		$wpdb->insert(
			$contacts_table,
			array(
				'user_id'    => $user_id,
				'email'      => sanitize_email( $user->user_email ),
				'name'       => sanitize_text_field( $user->display_name ),
				'source'     => 'manual',
				'status'     => 'student',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $this->get_contact( $user_id );
	}

	/**
	 * @param string $table Nombre completo de tabla.
	 * @return bool
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;

		$like   = $wpdb->esc_like( $table );
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return $exists === $table;
	}
}
