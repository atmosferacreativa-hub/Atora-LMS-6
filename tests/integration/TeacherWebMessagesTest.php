<?php
/**
 * Integración 6.30.2: la página web de mensajes del docente muestra la
 * conversación completa (lo enviado y lo recibido), con el mismo buzón,
 * servicio y contador que la app.
 *
 * Antes solo listaba lo recibido: el docente no veía sus propios mensajes.
 */

declare( strict_types = 1 );

final class TeacherWebMessagesTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $student = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		foreach ( array( 'atora_messages', 'atora_message_participants', 'atora_message_threads' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->teacher = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Docente Web' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Estudiante Web' ) );
	}

	private function send_as( int $user_id, array $params ) {
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/messages' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params + array( 'client_event_id' => wp_generate_uuid4() ) ) );
		return ATORA_Mobile_Messages_Controller::send( $request );
	}

	private function page_as_teacher( int $thread_id = 0 ): string {
		wp_set_current_user( $this->teacher );
		$_GET = $thread_id ? array( 'page' => 'clms-messages', 'thread' => $thread_id ) : array( 'page' => 'clms-messages' );
		ob_start();
		( new CLMS_Admin_Menu() )->render_messages_page();
		$html = (string) ob_get_clean();
		$_GET = array();
		return $html;
	}

	public function test_teacher_sees_sent_and_received_in_the_thread_and_shared_counter(): void {
		clms_core( 'CLMS_Messaging' )->send_message( $this->student, array(
			'message_type' => 'manual',
			'sender_type'  => 'teacher',
			'sender_id'    => $this->teacher,
			'title'        => 'Tarea 2',
			'message'      => 'Mensaje que escribió el docente',
		) );
		$thread_id = (int) ATORA_Inbox_Store::threads_for_user( $this->student, '', 20 )['items'][0]['id'];
		$reply     = $this->send_as( $this->student, array( 'thread_id' => $thread_id, 'body' => 'Respuesta del estudiante' ) );
		$this->assertNotWPError( $reply );

		$this->assertSame( 1, ATORA_Inbox_Store::unread_count( $this->teacher ) );
		$html = $this->page_as_teacher();

		$this->assertStringContainsString( 'Mensaje que escribió el docente', $html, 'Lo enviado por el docente aparece en la web.' );
		$this->assertStringContainsString( 'Respuesta del estudiante', $html );
		$list = substr( $html, (int) strpos( $html, '<div class="clms-admin-message-list">' ) );
		$this->assertLessThan( strpos( $list, 'Respuesta del estudiante' ), strpos( $list, 'Mensaje que escribió el docente' ), 'Orden cronológico.' );
		$this->assertStringContainsString( 'name="action" value="atora_inbox_reply"', $html, 'Se puede responder desde la web.' );
		$this->assertSame( 0, ATORA_Inbox_Store::unread_count( $this->teacher ), 'Abrir el hilo en la web lo marca leído: el mismo contador que la app.' );
	}

	public function test_web_reply_goes_through_the_same_send_as_the_app(): void {
		clms_core( 'CLMS_Messaging' )->send_message( $this->student, array( 'message_type' => 'manual', 'sender_type' => 'teacher', 'sender_id' => $this->teacher, 'title' => 'Hola', 'message' => 'Inicio' ) );
		$thread_id = (int) ATORA_Inbox_Store::threads_for_user( $this->teacher, '', 20 )['items'][0]['id'];

		wp_set_current_user( $this->teacher );
		$_POST = array(
			'thread_id'       => $thread_id,
			'body'            => 'Respuesta web del docente',
			'client_event_id' => 'web-evt-1',
			'_wpnonce'        => wp_create_nonce( 'atora_inbox_reply_' . $thread_id ),
		);
		$_REQUEST = $_POST;
		add_filter( 'wp_redirect', static function () { throw new RuntimeException( 'redirect' ); } );
		try {
			clms_core( 'CLMS_Messaging' )->handle_inbox_reply();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
		$_POST = $_REQUEST = array();

		$bodies = wp_list_pluck( ATORA_Inbox_Store::thread_messages( $thread_id ), 'body' );
		$this->assertContains( 'Respuesta web del docente', $bodies );
		$this->assertSame( 2, ATORA_Inbox_Store::unread_count( $this->student ), 'El estudiante tiene el mensaje inicial y la respuesta sin leer.' );
	}
}
