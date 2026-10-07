<?php
/**
 * Borradores de datos personales de ATORA LMS (6.33.1), con el mecanismo
 * estándar de WordPress (`wp_privacy_personal_data_erasers`): funcionan desde
 * Herramientas → Borrar datos personales y desde las solicitudes de
 * eliminación de cuenta (`ATORA_Account_Deletion`).
 *
 * Recorre todas las tablas del plugin que hacen referencia a una persona (por
 * id de usuario, correo o teléfono) y aplica una acción por tabla:
 * - `cancel`: colas de envío. Lo pendiente se cancela (no sale) y lo enviado
 *   queda sin destinatario.
 * - `delete`: datos operativos (sesiones, tokens, preferencias, CRM, análisis…).
 * - `unlink`: registros y auditorías: la persona pasa a 0 y los textos
 *   personales se vacían; el registro se conserva.
 * - `academic`: notas, actas, entregas, matrículas, certificados. Al
 *   anonimizar se conservan (la cuenta queda como "Usuario eliminado" y se
 *   vacían los textos personales); al eliminar por completo se borran.
 * Mensajes y credenciales tienen reglas propias (el texto de lo que escribió la
 * persona se borra; el titular del certificado pasa a anónimo, con evento de
 * auditoría y la huella de la credencial recalculada).
 *
 * `verify()` vuelve a recorrer las tablas y lista lo que quede.
 *
 * @package ATORA_LMS
 * @since 6.33.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Privacy_Erasers {

	const ANONYMIZE = 'anonymize';
	const DELETE    = 'delete';
	const HOLDER    = 'Titular anonimizado';

	/** Modo de la ejecución en curso (desde Herramientas de WordPress: anonimizar). */
	private static string $mode = self::ANONYMIZE;

	/** Columnas que identifican a la persona. */
	const USER_COLUMNS = array( 'user_id', 'student_id', 'author_id', 'owner_id', 'owner_user_id', 'target_user_id', 'reviewee_id', 'reviewer_id', 'assistant_id', 'instructor_id', 'teacher_id', 'actor_id', 'grader_id', 'primary_grader_id', 'moderator_id', 'last_actor_id', 'recorded_by', 'assessed_by', 'contacted_by', 'requested_by', 'decided_by', 'reviewed_by', 'issued_by', 'created_by', 'closed_by', 'published_by', 'revoked_by', 'set_by', 'submitted_by', 'assigned_to', 'on_behalf_of', 'processed_by' );
	const EMAIL_COLUMNS = array( 'email', 'recipient_email', 'invited_email', 'contact_email', 'user_email' );
	const PHONE_COLUMNS = array( 'phone', 'whatsapp', 'recipient_phone' );
	/** Columnas que dicen *de quién* es la fila (las demás de USER_COLUMNS son personal que actuó). */
	const PERSON_COLUMNS = array( 'user_id', 'student_id', 'author_id', 'owner_id', 'owner_user_id', 'target_user_id', 'reviewee_id' );
	/** Textos personales que se vacían al conservar un registro. */
	const TEXT_COLUMNS = array( 'recipient_name', 'display_name', 'name', 'student_code', 'ip_address', 'user_agent', 'device_name', 'device_label', 'external_participant_id', 'last_statement_json', 'payout_details', 'city', 'company', 'job_title', 'country' );

	/** Tablas académicas: se conservan al anonimizar. */
	const ACADEMIC = array( 'atora_assignment_submissions', 'atora_quiz_submissions', 'atora_lesson_progress', 'atora_lesson_positions', 'atora_enrollments', 'atora_program_enrollments', 'atora_gradebook', 'atora_institutional_grades', 'atora_grade_moderations', 'atora_grade_rectifications', 'atora_rubric_evaluations', 'atora_certificates', 'atora_credentials', 'atora_attendance', 'atora_h5p_tracking', 'atora_portfolios', 'atora_portfolio_items', 'atora_portfolio_assessments', 'atora_portfolio_feedback', 'atora_section_students', 'atora_cohort_members', 'atora_institution_members', 'clms_grade_appeals', 'clms_group_members', 'clms_group_submissions', 'clms_group_grade_overrides', 'clms_progress_tracking' );
	/** Colas de envío: lo pendiente se cancela. */
	const QUEUES = array( 'atora_email_queue' => 'recipient_email', 'atora_message_queue' => 'recipient_phone', 'atora_automation_queue' => '' );
	/** Registros y auditorías: se conservan desvinculados. */
	const LOGS = array( 'atora_audit_log', 'atora_tenancy_audit', 'atora_ai_usage', 'atora_ai_jobs', 'atora_content_changes', 'atora_credential_events', 'atora_credential_revocations', 'atora_gradebook_events', 'atora_gradebook_cycles', 'atora_library_events', 'atora_library_items', 'atora_library_versions', 'atora_academic_periods', 'atora_grading_scales', 'clms_group_audit_log', 'clms_peer_review_audit_log', 'atora_affiliate_commissions', 'atora_rubrics', 'atora_h5p_content', 'atora_followup_plans', 'atora_instructor_delegations', 'clms_groups', 'atora_google_classroom_course_map', 'atora_calendar_events', 'atora_institutions', 'atora_companies', 'atora_webhooks', 'atora_api_keys', 'atora_automations', 'atora_email_templates', 'atora_newsletters', 'atora_crm_lists', 'atora_crm_campaigns', 'atora_cohorts', 'atora_sections', 'atora_courses', 'atora_programs', 'atora_lessons', 'atora_quizzes', 'atora_rubric_criteria', 'atora_rubric_levels', 'atora_live_sessions', 'atora_email_sequences', 'atora_email_sequence_steps', 'atora_crm_contact_fields' );
	/** No son datos de una persona (o los maneja otra regla). */
	const SKIP = array( 'atora_messages', 'atora_message_participants', 'atora_message_threads', 'atora_account_deletions' );

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register' ) );
	}

	public static function register( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['atora-lms'] = array(
			'eraser_friendly_name' => __( 'ATORA LMS (cursos, mensajes, colas de envío, CRM, IA y registros)', 'atora-lms' ),
			'callback'             => array( __CLASS__, 'eraser' ),
		);
		return $erasers;
	}

	/** Ejecuta las borradoras con un modo (lo usan las solicitudes de eliminación). */
	public static function with_mode( string $mode, callable $callback ) {
		$previous   = self::$mode;
		self::$mode = self::DELETE === $mode ? self::DELETE : self::ANONYMIZE;
		try {
			return $callback();
		} finally {
			self::$mode = $previous;
		}
	}

	/** Callback de WordPress: una sola página (todo se hace en una pasada). */
	public static function eraser( $email_address, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email_address );
		$out  = self::erase( $user ? (int) $user->ID : 0, (string) $email_address, $user ? (string) $user->display_name : '' );
		return array(
			'items_removed'  => $out['removed'] > 0,
			'items_retained' => $out['retained'] > 0,
			'messages'       => array_merge( $out['messages'], $out['errors'] ),
			'done'           => true,
		);
	}

	/** @return array<string,array{columns:array,user:array,email:array,phone:array,text:array}> Tablas del plugin con datos de personas. */
	public static function tables(): array {
		global $wpdb;
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, DATA_TYPE AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s )', $wpdb->esc_like( $wpdb->prefix . 'atora_' ) . '%', $wpdb->esc_like( $wpdb->prefix . 'clms_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$tables = array();
		foreach ( $rows as $row ) {
			$short = substr( (string) $row['t'], strlen( $wpdb->prefix ) );
			$tables[ $short ]['columns'][] = (string) $row['c'];
			if ( 'json' === strtolower( (string) $row['d'] ) ) {
				$tables[ $short ]['json'][] = (string) $row['c'];
			}
		}
		$out = array();
		foreach ( $tables as $short => $info ) {
			$columns = $info['columns'];
			$spec    = array(
				'columns' => $columns,
				'json'    => $info['json'] ?? array(),
				'user'    => array_values( array_intersect( self::USER_COLUMNS, $columns ) ),
				'person'  => array_values( array_intersect( self::PERSON_COLUMNS, $columns ) ),
				'staff'   => array_values( array_diff( array_intersect( self::USER_COLUMNS, $columns ), self::PERSON_COLUMNS ) ),
				'email'   => array_values( array_intersect( self::EMAIL_COLUMNS, $columns ) ),
				'phone'   => array_values( array_intersect( self::PHONE_COLUMNS, $columns ) ),
				'text'    => array_values( array_intersect( self::TEXT_COLUMNS, $columns ) ),
			);
			if ( $spec['user'] || $spec['email'] || $spec['phone'] ) {
				$out[ $short ] = $spec;
			}
		}
		ksort( $out );
		return $out;
	}

	public static function action( string $table ): string {
		if ( in_array( $table, self::SKIP, true ) ) {
			return 'special';
		}
		if ( isset( self::QUEUES[ $table ] ) ) {
			return 'cancel';
		}
		if ( in_array( $table, self::ACADEMIC, true ) ) {
			return 'academic';
		}
		if ( in_array( $table, self::LOGS, true ) ) {
			return 'unlink';
		}
		return 'delete';
	}

	/** WHERE para las filas de esta persona en una tabla. @return array{0:string,1:array} */
	private static function where( array $spec, int $user_id, string $email, string $phone, string $which = 'user' ): array {
		$parts = array();
		$args  = array();
		if ( $user_id > 0 ) {
			foreach ( $spec[ $which ] as $column ) {
				$parts[] = "`{$column}` = %d";
				$args[]  = $user_id;
			}
		}
		if ( '' !== $email ) {
			foreach ( $spec['email'] as $column ) {
				$parts[] = "`{$column}` = %s";
				$args[]  = $email;
			}
		}
		if ( '' !== $phone ) {
			foreach ( $spec['phone'] as $column ) {
				$parts[] = "`{$column}` = %s";
				$args[]  = $phone;
			}
		}
		return array( $parts ? '(' . implode( ' OR ', $parts ) . ')' : '', $args );
	}

	/** Teléfono guardado en el perfil (para encontrarlo en colas y CRM). */
	private static function phone_of( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		foreach ( array( 'billing_phone', 'phone', 'atora_phone', 'clms_phone', 'whatsapp' ) as $key ) {
			$value = trim( (string) get_user_meta( $user_id, $key, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Borra o anonimiza los datos de una persona en todas las tablas del plugin.
	 *
	 * @return array{removed:int,retained:int,messages:string[],errors:string[]}
	 */
	public static function erase( int $user_id, string $email, string $name = '' ): array {
		global $wpdb;
		$out   = array( 'removed' => 0, 'retained' => 0, 'messages' => array(), 'errors' => array() );
		$phone = self::phone_of( $user_id );
		$run   = static function ( $sql, string $table ) use ( &$out, $wpdb ) {
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB
			if ( false === $result || '' !== (string) $wpdb->last_error ) {
				/* translators: 1: tabla, 2: error */
				$out['errors'][] = sprintf( __( 'No se pudo limpiar %1$s: %2$s', 'atora-lms' ), $table, (string) $wpdb->last_error );
				return 0;
			}
			return (int) $result;
		};

		self::erase_messages( $user_id, $out, $run );
		self::erase_crm( $user_id, $email, $out, $run );

		foreach ( self::tables() as $table => $spec ) {
			$action = self::action( $table );
			if ( 'special' === $action ) {
				continue;
			}
			list( $where, $args ) = self::where( $spec, $user_id, $email, $phone );
			if ( '' === $where ) {
				continue;
			}
			$full = $wpdb->prefix . $table;
			if ( 'cancel' === $action ) {
				$set = array( "`status` = IF(`status` IN ('pending','sending','scheduled','queued','processing'), 'cancelled', `status`)" );
				foreach ( array_merge( $spec['user'] ) as $column ) {
					$set[] = "`{$column}` = 0";
				}
				foreach ( array_merge( $spec['email'], $spec['phone'], $spec['text'] ) as $column ) {
					$set[] = "`{$column}` = ''";
				}
				foreach ( array( 'body_html', 'body_text', 'variables', 'action_data', 'context', 'metadata', 'subject' ) as $column ) {
					if ( in_array( $column, $spec['columns'], true ) ) {
						// Las columnas JSON no admiten texto vacío.
						$set[] = in_array( $column, $spec['json'], true ) ? "`{$column}` = '{}'" : "`{$column}` = ''";
					}
				}
				$out['removed'] += $run( $wpdb->prepare( "UPDATE `{$full}` SET " . implode( ', ', $set ) . " WHERE {$where}", $args ), $table ); // phpcs:ignore WordPress.DB
				continue;
			}
			// Personal que actuó (calificó, creó, aprobó…): se desvincula, nunca se borra la fila ajena.
			if ( $user_id > 0 && $spec['staff'] && ! ( 'academic' === $action && self::ANONYMIZE === self::$mode ) ) {
				foreach ( $spec['staff'] as $column ) {
					$run( $wpdb->prepare( "UPDATE `{$full}` SET `{$column}` = 0 WHERE `{$column}` = %d", $user_id ), $table ); // phpcs:ignore WordPress.DB
				}
			}
			list( $own, $own_args ) = self::where( $spec, $user_id, $email, $phone, 'person' );
			if ( 'delete' === $action || ( 'academic' === $action && self::DELETE === self::$mode ) ) {
				if ( 'atora_credentials' === $table ) {
					self::forget_credentials( $user_id, $run, true );
				}
				if ( '' !== $own ) {
					$out['removed'] += $run( $wpdb->prepare( "DELETE FROM `{$full}` WHERE {$own}", $own_args ), $table ); // phpcs:ignore WordPress.DB
				}
				continue;
			}
			if ( 'academic' === $action ) {
				// Se conserva el registro académico; la cuenta queda como "Usuario eliminado".
				if ( 'atora_credentials' === $table ) {
					self::forget_credentials( $user_id, $run, false );
				}
				$set = array();
				foreach ( array_merge( $spec['email'], $spec['phone'], $spec['text'] ) as $column ) {
					$set[] = "`{$column}` = ''";
				}
				// Personas distintas del titular (docentes que calificaron, por ejemplo): solo se desvincula si es esta persona.
				if ( $set && '' !== $own ) {
					$run( $wpdb->prepare( "UPDATE `{$full}` SET " . implode( ', ', $set ) . " WHERE {$own}", $own_args ), $table ); // phpcs:ignore WordPress.DB
				}
				$out['retained']++;
				continue;
			}
			// unlink: el registro queda, sin la persona.
			$set = array();
			foreach ( $spec['user'] as $column ) {
				$set[] = $wpdb->prepare( "`{$column}` = IF(`{$column}` = %d, 0, `{$column}`)", $user_id ); // phpcs:ignore WordPress.DB
			}
			foreach ( array_merge( $spec['email'], $spec['phone'] ) as $column ) {
				$set[] = $wpdb->prepare( "`{$column}` = IF(`{$column}` IN (%s, %s), '', `{$column}`)", $email, $phone ); // phpcs:ignore WordPress.DB
			}
			foreach ( $spec['text'] as $column ) {
				$set[] = "`{$column}` = ''";
			}
			if ( $set ) {
				$out['removed'] += $run( $wpdb->prepare( "UPDATE `{$full}` SET " . implode( ', ', $set ) . " WHERE {$where}", $args ), $table ); // phpcs:ignore WordPress.DB
			}
		}

		// Registros de certificado guardados en el perfil y PDF generados.
		if ( $user_id > 0 ) {
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s )", $user_id, '_clms_certificate_record_%', '_clms_program_certificate_record_%' ) ) as $key ) { // phpcs:ignore WordPress.DB
				if ( self::DELETE === self::$mode ) {
					delete_user_meta( $user_id, $key );
					continue;
				}
				$record = get_user_meta( $user_id, $key, true );
				if ( is_array( $record ) ) {
					$record['student_name'] = __( self::HOLDER, 'atora-lms' ); // phpcs:ignore WordPress.WP.I18n
					update_user_meta( $user_id, $key, $record );
				}
			}
		}
		// Nombre completo y correo en textos libres (firmas, mensajes de otros, JSON): se reemplazan.
		foreach ( array( '[correo]' => $email, '[nombre]' => false !== strpos( trim( $name ), ' ' ) ? trim( $name ) : '' ) as $placeholder => $needle ) {
			if ( '' === $needle ) {
				continue;
			}
			foreach ( self::text_columns() as $table => $columns ) {
				foreach ( $columns as $column ) {
					$run( $wpdb->prepare( "UPDATE `{$wpdb->prefix}{$table}` SET `{$column}` = REPLACE(`{$column}`, %s, %s) WHERE `{$column}` LIKE %s", $needle, $placeholder, '%' . $wpdb->esc_like( $needle ) . '%' ), $table ); // phpcs:ignore WordPress.DB
				}
			}
		}
		if ( $out['errors'] ) {
			$out['messages'][] = __( 'Algunas tablas no se pudieron limpiar; la solicitud queda incompleta.', 'atora-lms' );
		}
		return $out;
	}

	/** Mensajes: lo que escribió la persona se borra; sale de las conversaciones; sus hilos de avisos se eliminan. */
	private static function erase_messages( int $user_id, array &$out, callable $run ): void {
		global $wpdb;
		if ( $user_id <= 0 || ! self::exists( 'atora_messages' ) ) {
			return;
		}
		$messages     = $wpdb->prefix . 'atora_messages';
		$participants = $wpdb->prefix . 'atora_message_participants';
		$threads      = $wpdb->prefix . 'atora_message_threads';
		$thread_ids   = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT thread_id FROM {$participants} WHERE user_id = %d", $user_id ) ) ); // phpcs:ignore WordPress.DB
		$out['removed'] += $run( $wpdb->prepare( "DELETE FROM {$messages} WHERE author_id = %d", $user_id ), 'atora_messages' ); // phpcs:ignore WordPress.DB
		$out['removed'] += $run( $wpdb->prepare( "DELETE FROM {$participants} WHERE user_id = %d", $user_id ), 'atora_message_participants' ); // phpcs:ignore WordPress.DB
		foreach ( $thread_ids as $thread_id ) {
			$others = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$participants} WHERE thread_id = %d", $thread_id ) ); // phpcs:ignore WordPress.DB
			if ( 0 === $others ) {
				$run( $wpdb->prepare( "DELETE FROM {$messages} WHERE thread_id = %d", $thread_id ), 'atora_messages' ); // phpcs:ignore WordPress.DB
				if ( self::exists( 'atora_message_threads' ) ) {
					$run( $wpdb->prepare( "DELETE FROM {$threads} WHERE id = %d", $thread_id ), 'atora_message_threads' ); // phpcs:ignore WordPress.DB
				}
			} elseif ( self::exists( 'atora_message_threads' ) ) {
				// La clave del hilo puede incluir el id de la persona.
				$run( $wpdb->prepare( "UPDATE {$threads} SET thread_key = NULL WHERE id = %d", $thread_id ), 'atora_message_threads' ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/** CRM: el contacto y todo lo que cuelga de él (notas, etiquetas, actividad, conversaciones). */
	private static function erase_crm( int $user_id, string $email, array &$out, callable $run ): void {
		global $wpdb;
		if ( ! self::exists( 'atora_contacts' ) ) {
			return;
		}
		$contacts = $wpdb->prefix . 'atora_contacts';
		$ids      = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$contacts} WHERE ( user_id > 0 AND user_id = %d ) OR ( email <> '' AND email = %s )", $user_id, $email ) ) ); // phpcs:ignore WordPress.DB
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', $ids );
		foreach ( array( 'atora_contact_notes', 'atora_contact_tags', 'atora_contact_activities', 'atora_contact_list_pivot', 'atora_crm_contact_field_values', 'atora_crm_campaign_recipients', 'atora_crm_deals', 'atora_crm_tasks', 'atora_abandoned_carts', 'atora_url_clicks' ) as $table ) {
			if ( self::exists( $table ) && in_array( 'contact_id', self::columns( $table ), true ) ) {
				$out['removed'] += $run( "DELETE FROM {$wpdb->prefix}{$table} WHERE contact_id IN ({$in})", $table ); // phpcs:ignore WordPress.DB
			}
		}
		if ( self::exists( 'atora_conversations' ) ) {
			$conversations = array_map( 'absint', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}atora_conversations WHERE contact_id IN ({$in})" ) ); // phpcs:ignore WordPress.DB
			if ( $conversations && self::exists( 'atora_conversation_messages' ) ) {
				$out['removed'] += $run( "DELETE FROM {$wpdb->prefix}atora_conversation_messages WHERE conversation_id IN (" . implode( ',', $conversations ) . ')', 'atora_conversation_messages' ); // phpcs:ignore WordPress.DB
			}
			$out['removed'] += $run( "DELETE FROM {$wpdb->prefix}atora_conversations WHERE contact_id IN ({$in})", 'atora_conversations' ); // phpcs:ignore WordPress.DB
		}
		$out['removed'] += $run( "DELETE FROM {$contacts} WHERE id IN ({$in})", 'atora_contacts' ); // phpcs:ignore WordPress.DB
	}

	/** Certificados: al anonimizar, el titular pasa a anónimo (evento de auditoría y huella nueva); al eliminar, se borran. */
	private static function forget_credentials( int $user_id, callable $run, bool $delete ): void {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return;
		}
		$table = $wpdb->prefix . 'atora_credentials';
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, credential_uuid, snapshot_json FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$uploads = wp_upload_dir( null, false );
		foreach ( $rows as $row ) {
			foreach ( (array) glob( trailingslashit( $uploads['basedir'] ) . 'atora-private/certificates/' . $row['credential_uuid'] . '-*.pdf' ) as $file ) {
				wp_delete_file( $file );
			}
			if ( $delete ) {
				continue;
			}
			$snapshot = json_decode( (string) $row['snapshot_json'], true );
			$snapshot = is_array( $snapshot ) ? $snapshot : array();
			$snapshot['holder_name'] = self::HOLDER;
			$snapshot = class_exists( 'CLMS_Credential_Policy' ) ? CLMS_Credential_Policy::canonicalize( $snapshot ) : $snapshot;
			$hash     = class_exists( 'CLMS_Credential_Policy' ) ? CLMS_Credential_Policy::snapshot_hash( $snapshot ) : hash( 'sha256', (string) wp_json_encode( $snapshot ) );
			$run( $wpdb->prepare( "UPDATE {$table} SET snapshot_json = %s, snapshot_hash = %s, updated_at = %s WHERE id = %d", wp_json_encode( $snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $hash, current_time( 'mysql', true ), (int) $row['id'] ), 'atora_credentials' ); // phpcs:ignore WordPress.DB
			if ( self::exists( 'atora_credential_events' ) ) {
				$events   = $wpdb->prefix . 'atora_credential_events';
				$previous = (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_hash FROM {$events} WHERE credential_id = %d ORDER BY id DESC LIMIT 1", (int) $row['id'] ) ); // phpcs:ignore WordPress.DB
				$created  = current_time( 'mysql', true );
				$details  = (string) wp_json_encode( array( 'reason' => 'account_deletion', 'snapshot_hash' => $hash ) );
				$wpdb->insert( $events, array( 'credential_id' => (int) $row['id'], 'action' => 'holder_anonymized', 'actor_id' => get_current_user_id(), 'details_json' => $details, 'previous_hash' => $previous, 'event_hash' => hash( 'sha256', $row['id'] . '|holder_anonymized|' . get_current_user_id() . '|' . $created . '|' . $previous . '|' . $details ), 'created_at' => $created ) ); // phpcs:ignore WordPress.DB
			}
		}
	}

	/**
	 * Lo que queda de la persona después de borrar. Al anonimizar se admiten los
	 * registros académicos (con el id de la cuenta ya anonimizada).
	 *
	 * @return string[] Detalle legible ("tabla: 3 filas con el correo").
	 */
	public static function verify( int $user_id, string $email, string $name, string $mode ): array {
		global $wpdb;
		$left  = array();
		$phone = '';
		foreach ( self::tables() as $table => $spec ) {
			$action = self::action( $table );
			$full   = $wpdb->prefix . $table;
			if ( $user_id > 0 && $spec['user'] && ! ( 'academic' === $action && self::ANONYMIZE === $mode ) && 'atora_account_deletions' !== $table ) {
				foreach ( $spec['user'] as $column ) {
					$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$full}` WHERE `{$column}` = %d", $user_id ) ); // phpcs:ignore WordPress.DB
					if ( $count ) {
						$left[] = sprintf( '%s.%s: %d', $table, $column, $count );
					}
				}
			}
			if ( '' !== $email ) {
				foreach ( $spec['email'] as $column ) {
					$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$full}` WHERE `{$column}` = %s", $email ) ); // phpcs:ignore WordPress.DB
					if ( $count ) {
						$left[] = sprintf( '%s.%s (correo): %d', $table, $column, $count );
					}
				}
			}
		}
		// El correo y el nombre completo no deben aparecer en ningún texto del plugin.
		$needles = array_filter( array( $email, false !== strpos( trim( $name ), ' ' ) ? trim( $name ) : '' ) );
		foreach ( $needles as $needle ) {
			foreach ( self::text_columns() as $table => $columns ) {
				foreach ( $columns as $column ) {
					$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}{$table}` WHERE `{$column}` LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ) ); // phpcs:ignore WordPress.DB
					if ( $count ) {
						$left[] = sprintf( '%s.%s (%s): %d', $table, $column, $needle === $email ? 'correo' : 'nombre', $count );
					}
				}
			}
		}
		if ( $user_id > 0 && '' !== $email && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_email = %s", $email ) ) ) { // phpcs:ignore WordPress.DB
			$left[] = 'users.user_email';
		}
		return array_values( array_unique( $left ) );
	}

	/** @return array<string,string[]> Columnas de texto de las tablas del plugin (para buscar correo y nombre). */
	private static function text_columns(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s ) AND DATA_TYPE IN ('varchar','text','mediumtext','longtext','json','tinytext')", $wpdb->esc_like( $wpdb->prefix . 'atora_' ) . '%', $wpdb->esc_like( $wpdb->prefix . 'clms_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $row ) {
			$short = substr( (string) $row['t'], strlen( $wpdb->prefix ) );
			if ( 'atora_account_deletions' === $short ) {
				continue;
			}
			$out[ $short ][] = (string) $row['c'];
		}
		return $out;
	}

	private static function exists( string $table ): bool {
		return array_key_exists( $table, self::all_tables() );
	}

	private static function columns( string $table ): array {
		return self::all_tables()[ $table ] ?? array();
	}

	/** @return array<string,string[]> */
	private static function all_tables(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ( TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s )', $wpdb->esc_like( $wpdb->prefix . 'atora_' ) . '%', $wpdb->esc_like( $wpdb->prefix . 'clms_' ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ substr( (string) $row['t'], strlen( $wpdb->prefix ) ) ][] = (string) $row['c'];
		}
		return $out;
	}
}
