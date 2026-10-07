<?php
/**
 * Integración 6.33.1 (Bloque E.1): la eliminación de cuenta borra los datos de
 * la persona en todas las tablas del plugin, no solo el usuario de WordPress.
 *
 * - Tras procesar, no queda nombre, correo ni id en ninguna tabla registrada
 *   (al anonimizar, solo los registros académicos, sin datos personales).
 * - Un correo pendiente se cancela y no se envía.
 * - Si una tabla falla, la solicitud queda "incompleta" con el detalle y se
 *   puede reintentar.
 * - Los borradores están registrados en WordPress (Herramientas → Borrar datos personales).
 */

declare( strict_types = 1 );

final class PrivacyErasureTest extends WP_UnitTestCase {

	private const NAME = 'Lucía Méndez';

	private int $student = 0;
	private int $teacher = 0;
	private int $admin = 0;
	private string $email = '';
	private int $submission = 0;
	private int $credential = 0;
	private int $thread = 0;
	private int $contact_id = 0;
	private int $usage_id = 0;
	private int $audit_id = 0;
	private int $message_queue_id = 0;
	private array $email_ids = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$this->email   = 'lucia.' . wp_generate_password( 6, false ) . '@escuela.test';
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'user_login' => 'lmendez' . wp_generate_password( 4, false, false ), 'display_name' => self::NAME, 'first_name' => 'Lucía', 'last_name' => 'Méndez', 'user_email' => $this->email ) );
		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Profe Ríos' ) );
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $this->student, 'billing_phone', '+58 412 5550000' );
		$p = $wpdb->prefix;
		$now = current_time( 'mysql', true );

		// Sesión móvil, aviso al teléfono, subida a medias.
		ATORA_Mobile_Token_Service::issue( $this->student, 'Teléfono de Lucía' );
		$wpdb->insert( "{$p}atora_mobile_push_tokens", array( 'user_id' => $this->student, 'token' => 'ExponentPushToken[' . wp_generate_password( 10, false ) . ']', 'platform' => 'android', 'academy' => 'x', 'created_at' => $now, 'last_seen_at' => $now ) );
		$wpdb->insert( "{$p}atora_upload_sessions", array( 'user_id' => $this->student, 'upload_token' => 'tok_' . wp_generate_password( 48, false, false ), 'filename' => 'tarea.pdf', 'mime_type' => 'application/pdf', 'total_bytes' => 10, 'received_bytes' => 0, 'status' => 'open', 'storage_path' => '', 'created_at' => $now, 'expires_at' => $now ) );
		// Colas: un correo pendiente y uno ya enviado; un WhatsApp pendiente.
		$wpdb->insert( "{$p}atora_email_queue", array( 'recipient_email' => $this->email, 'recipient_name' => self::NAME, 'user_id' => $this->student, 'subject' => 'Recordatorio para Lucía', 'body_html' => '<p>Hola Lucía Méndez</p>', 'body_text' => 'Hola Lucía Méndez', 'status' => 'pending', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ), 'created_at' => $now ) );
		$this->email_ids[] = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}atora_email_queue", array( 'recipient_email' => $this->email, 'recipient_name' => self::NAME, 'user_id' => $this->student, 'subject' => 'Bienvenida', 'body_text' => 'Hola', 'status' => 'sent', 'scheduled_at' => $now, 'sent_at' => $now, 'created_at' => $now ) );
		$this->email_ids[] = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}atora_message_queue", array( 'recipient_phone' => '+58 412 5550000', 'recipient_name' => self::NAME, 'user_id' => $this->student, 'channel' => 'whatsapp', 'template_key' => 'recordatorio', 'variables' => '{"nombre":"Lucía"}', 'status' => 'pending', 'scheduled_at' => $now ) );
		$this->message_queue_id = (int) $wpdb->insert_id;
		// CRM: contacto con nota.
		// El CRM puede haber creado ya el contacto al registrarse el usuario.
		$contact = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}atora_contacts WHERE email = %s", $this->email ) );
		if ( ! $contact ) {
			$wpdb->insert( "{$p}atora_contacts", array( 'user_id' => $this->student, 'email' => $this->email, 'name' => self::NAME, 'source' => 'web', 'status' => 'lead', 'created_at' => $now, 'updated_at' => $now ) );
			$contact = (int) $wpdb->insert_id;
		}
		$wpdb->update( "{$p}atora_contacts", array( 'user_id' => $this->student, 'name' => self::NAME, 'phone' => '+58 412 5550000', 'city' => 'Caracas' ), array( 'id' => $contact ) );
		$this->contact_id = $contact;
		// IA, auditoría, preferencias, formulario.
		$wpdb->insert( "{$p}atora_ai_usage", array( 'user_id' => $this->student, 'feature' => 'assistant', 'result' => 'ok', 'created_at' => $now ) );
		$this->usage_id = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}atora_audit_log", array( 'actor_id' => $this->student, 'action' => 'login', 'object_type' => 'user', 'object_id' => $this->student, 'details_json' => '{"email":"' . $this->email . '"}', 'created_at' => $now ) );
		$this->audit_id = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}atora_email_preferences", array( 'user_id' => $this->student, 'updated_at' => $now ) );
		$wpdb->insert( "{$p}atora_form_entries", array( 'form_id' => 1, 'user_id' => $this->student, 'entry_data' => '{"correo":"' . $this->email . '","nombre":"Lucía Méndez"}', 'ip_address' => '10.0.0.1', 'created_at' => $now ) );
		// Mensajes: la estudiante escribe; el docente la nombra.
		$thread       = (int) ATORA_Inbox_Store::direct_thread_id( $this->student, $this->teacher );
		$this->thread = $thread;
		ATORA_Inbox_Store::add_message( $thread, $this->student, array( 'body' => 'Profe, soy Lucía Méndez, mi correo es ' . $this->email ) );
		ATORA_Inbox_Store::add_message( $thread, $this->teacher, array( 'body' => 'Hola Lucía Méndez, recibido.' ) );
		// Académico: entrega firmada y su fila de historial; credencial emitida.
		$course           = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish' ) );
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		update_post_meta( $this->submission, '_clms_submission_grade', '91' );
		$wpdb->insert( "{$p}atora_assignment_submissions", array( 'user_id' => $this->student, 'course_id' => 1, 'lesson_id' => 1, 'wp_post_id' => $this->submission, 'attempt' => 1, 'source' => 'web', 'status' => 'submitted', 'body_text' => 'Mi ensayo. Atentamente, Lucía Méndez (' . $this->email . ')', 'client_event_id' => 'evt-' . wp_generate_password( 6, false ), 'server_received_at' => $now, 'created_at' => $now ) );
		$issued           = ( new CLMS_Credential_Service() )->issue( array( 'user_id' => $this->student, 'target_type' => 'course', 'target_id' => $course, 'holder_name' => self::NAME, 'achievement_name' => 'Química' ), $this->admin );
		$this->credential = (int) $issued['id'];
		update_user_meta( $this->student, '_clms_certificate_record_' . $course, array( 'student_name' => self::NAME, 'certificate_code' => 'ATORA-2026-000001' ) );
		wp_set_current_user( $this->admin );
		reset_phpmailer_instance();
	}

	protected function tearDown(): void {
		remove_all_filters( 'query' );
		parent::tearDown();
	}

	private function request(): int {
		return (int) ATORA_Account_Deletion::request( $this->student, 'app' )['request_id'];
	}

	private function row( int $request_id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_account_deletions WHERE id = %d", $request_id ), ARRAY_A );
	}

	public function test_anonymize_leaves_no_personal_data_in_any_table_and_keeps_academic_records(): void {
		global $wpdb;
		$p          = $wpdb->prefix;
		$request_id = $this->request();
		$result     = ATORA_Account_Deletion::process( $request_id, 'anonymize', $this->admin );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'processed', $this->row( $request_id )['status'] );
		$this->assertSame( array(), ATORA_Privacy_Erasers::verify( $this->student, $this->email, self::NAME, 'anonymize' ), 'Ninguna tabla conserva nombre, correo ni id fuera de lo académico.' );

		// Colas: el pendiente quedó cancelado y sin destinatario; nada sale.
		$queue = $wpdb->get_results( $wpdb->prepare( "SELECT status, recipient_email, body_text FROM {$p}atora_email_queue WHERE id IN (%d, %d) ORDER BY id", $this->email_ids[0], $this->email_ids[1] ), ARRAY_A );
		$this->assertSame( array( 'cancelled', 'sent' ), array_column( $queue, 'status' ) );
		$this->assertSame( array( '', '' ), array_column( $queue, 'recipient_email' ) );
		\ATORA\EmailEngine\Email_Queue::process_batch();
		$to = array();
		foreach ( (array) tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			foreach ( (array) ( $mail['to'] ?? array() ) as $recipient ) {
				$to[] = (string) ( $recipient[0] ?? '' );
			}
		}
		$this->assertNotContains( $this->email, $to, 'El correo pendiente no se envía.' );
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}atora_email_queue WHERE id = %d", $this->email_ids[0] ) ), 'Sigue cancelado después de la cola.' );
		$this->assertSame( 'cancelled', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}atora_message_queue WHERE id = %d", $this->message_queue_id ) ) );

		// Operativo borrado.
		foreach ( array( 'atora_mobile_sessions', 'atora_mobile_push_tokens', 'atora_upload_sessions', 'atora_email_preferences', 'atora_form_entries' ) as $table ) {
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}{$table} WHERE user_id = %d", $this->student ) ), $table );
		}
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}atora_contacts WHERE id = %d", $this->contact_id ) ), 'CRM: el contacto se borra.' );
		// Registros conservados sin la persona.
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$p}atora_ai_usage WHERE id = %d", $this->usage_id ) ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT actor_id FROM {$p}atora_audit_log WHERE id = %d", $this->audit_id ) ) );
		// Mensajes: lo suyo borrado; lo del docente sin su nombre.
		$bodies = $wpdb->get_col( $wpdb->prepare( "SELECT body FROM {$p}atora_messages WHERE thread_id = %d", $this->thread ) );
		$this->assertSame( array( 'Hola [nombre], recibido.' ), $bodies );
		// Académico conservado, sin datos personales.
		$this->assertSame( '91', get_post_meta( $this->submission, '_clms_submission_grade', true ) );
		$this->assertSame( 'Mi ensayo. Atentamente, [nombre] ([correo])', $wpdb->get_var( $wpdb->prepare( "SELECT body_text FROM {$p}atora_assignment_submissions WHERE wp_post_id = %d", $this->submission ) ) );
		$snapshot = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT snapshot_json FROM {$p}atora_credentials WHERE id = %d", $this->credential ) ), true );
		$this->assertSame( 'Titular anonimizado', $snapshot['holder_name'] );
		$verified = ( new CLMS_Credential_Service() )->verify( (string) $wpdb->get_var( $wpdb->prepare( "SELECT credential_uuid FROM {$p}atora_credentials WHERE id = %d", $this->credential ) ) );
		$this->assertSame( 'verified', $verified['integrity'], 'La huella se recalculó: la credencial sigue íntegra.' );
		$this->assertSame( 'holder_anonymized', $wpdb->get_var( $wpdb->prepare( "SELECT action FROM {$p}atora_credential_events WHERE credential_id = %d ORDER BY id DESC LIMIT 1", $this->credential ) ) );
		foreach ( get_user_meta( $this->student ) as $key => $values ) {
			$this->assertStringNotContainsString( self::NAME, (string) $values[0], "usermeta {$key}" );
		}
	}

	public function test_full_delete_removes_academic_records_too(): void {
		global $wpdb;
		$request_id = $this->request();
		$this->assertTrue( ATORA_Account_Deletion::process( $request_id, 'delete', $this->admin, true ) );
		$this->assertSame( 'processed', $this->row( $request_id )['status'] );
		$this->assertSame( array(), ATORA_Privacy_Erasers::verify( $this->student, $this->email, self::NAME, 'delete' ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_assignment_submissions WHERE user_id = %d", $this->student ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_credentials WHERE id = %d", $this->credential ) ) );
		$this->assertNull( get_post( $this->submission ) );
		// Lo del docente se conserva (solo se borran las filas de la persona).
		$this->assertSame( array( 'Hola [nombre], recibido.' ), $wpdb->get_col( $wpdb->prepare( "SELECT body FROM {$wpdb->prefix}atora_messages WHERE thread_id = %d", $this->thread ) ) );
	}

	public function test_a_failing_table_leaves_the_request_incomplete_and_retry_completes_it(): void {
		$request_id = $this->request();
		$break      = static function ( $query ) {
			return 0 === strpos( ltrim( (string) $query ), 'DELETE' ) && false !== strpos( (string) $query, 'atora_upload_sessions' ) ? 'DELETE FROM tabla_que_no_existe_e1' : $query;
		};
		add_filter( 'query', $break );
		$result = ATORA_Account_Deletion::process( $request_id, 'anonymize', $this->admin );
		remove_filter( 'query', $break );

		$this->assertWPError( $result );
		$this->assertSame( 'atora_account_deletion_incomplete', $result->get_error_code() );
		$row = $this->row( $request_id );
		$this->assertSame( 'incomplete', $row['status'] );
		$this->assertStringContainsString( 'atora_upload_sessions', (string) $row['detail'] );

		// Reintento: se completa (la cuenta ya estaba anonimizada; el correo se recupera del detalle).
		$this->assertTrue( ATORA_Account_Deletion::process( $request_id, 'anonymize', $this->admin ) );
		$this->assertSame( 'processed', $this->row( $request_id )['status'] );
		$this->assertSame( array(), ATORA_Privacy_Erasers::verify( $this->student, $this->email, self::NAME, 'anonymize' ) );
	}

	public function test_erasers_are_registered_in_wordpress_tools(): void {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( 'atora-lms', $erasers );
		// Desde Herramientas → Borrar datos personales (por correo, anonimizando).
		$response = call_user_func( $erasers['atora-lms']['callback'], $this->email, 1 );
		$this->assertTrue( $response['done'] );
		$this->assertTrue( $response['items_removed'] );
		global $wpdb;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_contacts WHERE id = %d", $this->contact_id ) ) );
	}
}
