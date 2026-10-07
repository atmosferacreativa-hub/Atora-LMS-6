<?php
/**
 * Integración 6.33.0: eliminación de cuenta (requisito de Google Play y Apple).
 *
 * - `POST /account/deletion-request` registra la solicitud, avisa a los
 *   administradores por el buzón y responde con el plazo; es idempotente.
 * - Anonimizar borra nombre, correo, usuario y contacto, cierra sesiones y
 *   dispositivos, y conserva lo académico (entregas y notas) sin datos personales.
 * - Eliminar por completo borra la cuenta.
 * - La página pública permite pedirla sin la app: con sesión (botón con nonce)
 *   o con un enlace firmado por correo, sin revelar si el correo existe.
 */

declare( strict_types = 1 );

final class AccountDeletionTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $admin = 0;
	private string $email = '';
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_account_deletions" );
		do_action( 'rest_api_init', rest_get_server() );
		$this->email   = 'borrar.' . wp_generate_password( 6, false ) . '@escuela.test';
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'user_login' => 'borrar' . wp_generate_password( 5, false, false ), 'display_name' => 'Julia Rojas', 'first_name' => 'Julia', 'last_name' => 'Rojas', 'user_email' => $this->email ) );
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $this->student, 'billing_phone', '+58 412 0000000' );
		update_user_meta( $this->student, '_clms_course_progress_99', array( 'percent' => 80 ) );
		$this->token = ATORA_Mobile_Token_Service::issue( $this->student, 'test' )['access_token'];
		reset_phpmailer_instance();
		wp_set_current_user( 0 );
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tearDown();
	}

	private function request_from_app(): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/account/deletion-request' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->token );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'note' => 'Ya terminé mis estudios.' ) ) );
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	public function test_app_request_is_registered_notifies_admin_and_returns_deadline(): void {
		$this->assertTrue( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['account_deletion'] );
		// Reporte de errores de la app: activo por defecto, la academia lo puede apagar.
		$this->assertTrue( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['crash_reports'] );
		update_option( ATORA_Mobile_Settings_Admin::CRASH_OPTION, '0' );
		$this->assertFalse( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['crash_reports'] );
		delete_option( ATORA_Mobile_Settings_Admin::CRASH_OPTION );
		$response = $this->request_from_app();
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ), $data['deadline'] );
		$this->assertStringContainsString( 'Recibimos tu solicitud', $data['message'] );

		$again = $this->request_from_app()->get_data();
		$this->assertSame( $data['request_id'], $again['request_id'], 'Idempotente: una sola solicitud pendiente.' );
		$this->assertTrue( $again['replayed'] );

		$inbox = array_filter( ATORA_Inbox_Store::received( $this->admin ), static fn( $m ) => false !== strpos( wp_json_encode( $m ), 'eliminar su cuenta' ) );
		$this->assertNotEmpty( $inbox, 'El administrador recibe el aviso en el buzón.' );
	}

	public function test_anonymize_removes_personal_data_and_keeps_academic_records(): void {
		$submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $this->student ) );
		update_post_meta( $submission, '_clms_submission_grade', '87' );
		$request_id = $this->request_from_app()->get_data()['request_id'];

		$this->assertTrue( ATORA_Account_Deletion::process( $request_id, 'anonymize', $this->admin ) );
		$user = get_userdata( $this->student );
		$this->assertSame( 'Usuario eliminado', $user->display_name );
		$this->assertSame( 'eliminado_' . $this->student, $user->user_login );
		$this->assertStringEndsWith( '@anonimo.invalid', $user->user_email );
		$this->assertSame( '', $user->first_name );
		$this->assertSame( '', get_user_meta( $this->student, 'billing_phone', true ), 'Datos de contacto borrados.' );
		$this->assertSame( array(), $user->roles, 'Ya no puede entrar a nada.' );
		$this->assertFalse( get_user_by( 'email', $this->email ) );
		$this->assertWPError( ATORA_Mobile_Token_Service::validate( $this->token ), 'Sesión de la app cerrada.' );
		// Lo académico se conserva.
		$this->assertSame( $this->student, (int) get_post_field( 'post_author', $submission ) );
		$this->assertSame( '87', get_post_meta( $submission, '_clms_submission_grade', true ) );
		$this->assertSame( array( 'percent' => 80 ), get_user_meta( $this->student, '_clms_course_progress_99', true ) );
		$this->assertWPError( ATORA_Account_Deletion::process( $request_id, 'anonymize', $this->admin ), 'No se procesa dos veces.' );
	}

	public function test_full_deletion_removes_the_account_and_admins_are_protected(): void {
		$request_id = $this->request_from_app()->get_data()['request_id'];
		// Sin aceptar la advertencia, no se elimina nada.
		$refused = ATORA_Account_Deletion::process( $request_id, 'delete', $this->admin );
		$this->assertSame( 'atora_account_deletion_confirm', $refused->get_error_code() );
		$this->assertStringContainsString( 'Se borrarán también notas y actas; la institución puede estar obligada a conservarlas', $refused->get_error_message() );
		$this->assertNotFalse( get_userdata( $this->student ) );
		$this->assertSame( 'Julia Rojas', get_userdata( $this->student )->display_name, 'Tampoco se anonimiza.' );

		$before = time();
		$this->assertTrue( ATORA_Account_Deletion::process( $request_id, 'delete', $this->admin, true ) );
		$this->assertFalse( get_userdata( $this->student ) );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_account_deletions WHERE id = %d", $request_id ), ARRAY_A );
		$this->assertSame( 'delete', $row['mode'] );
		$this->assertSame( (string) $this->admin, (string) $row['processed_by'], 'Queda quién la ejecutó.' );
		$this->assertGreaterThanOrEqual( $before - 1, strtotime( $row['processed_at'] . ' UTC' ), 'Y cuándo.' );

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin_req   = ATORA_Account_Deletion::request( $other_admin, 'web' );
		$this->assertSame( 'atora_account_deletion_admin', ATORA_Account_Deletion::process( $admin_req['request_id'], 'delete', $this->admin, true )->get_error_code() );
	}

	public function test_default_action_is_anonymize(): void {
		$request_id = $this->request_from_app()->get_data()['request_id'];
		$this->assertTrue( ATORA_Account_Deletion::process( $request_id ) );
		$this->assertSame( 'Usuario eliminado', get_userdata( $this->student )->display_name, 'Por defecto se anonimiza: la cuenta sigue y lo académico se conserva.' );
	}

	public function test_public_page_by_email_link_without_revealing_accounts(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( 'email' => 'nadie@escuela.test' );
		$this->assertSame( 'email_sent', ATORA_Account_Deletion::handle_public_request()['state'], 'Misma respuesta si el correo no existe.' );
		$this->assertEmpty( tests_retrieve_phpmailer_instance()->mock_sent );

		$_POST = array( 'email' => $this->email );
		$this->assertSame( 'email_sent', ATORA_Account_Deletion::handle_public_request()['state'] );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( $this->email, $mail->to[0][0] );
		$this->assertSame( 1, preg_match( '#[?&]u=(\d+)&e=(\d+)&t=([a-f0-9]{64})#', $mail->body, $m ) );
		$this->assertNull( ATORA_Account_Deletion::pending( $this->student ), 'Sin confirmar, no hay solicitud.' );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array( 'u' => $m[1], 'e' => $m[2], 't' => str_repeat( '0', 64 ) );
		$this->assertSame( 'invalid_link', ATORA_Account_Deletion::handle_public_request()['state'] );
		$_GET['t'] = $m[3];
		$this->assertSame( 'requested', ATORA_Account_Deletion::handle_public_request()['state'] );
		$this->assertSame( 'web', ATORA_Account_Deletion::pending( $this->student )['source'] );
	}

	public function test_public_page_with_session_uses_nonce(): void {
		wp_set_current_user( $this->student );
		$this->assertSame( 'form_logged_in', ATORA_Account_Deletion::handle_public_request()['state'] );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array( '_wpnonce' => 'malo' );
		$this->assertSame( 'error', ATORA_Account_Deletion::handle_public_request()['state'] );
		$_POST['_wpnonce'] = wp_create_nonce( 'atora_delete_my_account' );
		$this->assertSame( 'requested', ATORA_Account_Deletion::handle_public_request()['state'] );
		$this->assertNotNull( ATORA_Account_Deletion::pending( $this->student ) );
	}
}
