<?php
/**
 * Integración 6.38.0: baja segura (token firmado, GET confirma, POST da de baja), List-Unsubscribe en los seis
 * proveedores, baja que corta lo comercial, bloqueo de la cola, límites por proveedor y filas colgadas.
 * Ningún correo sale: las llamadas HTTP de los proveedores se capturan con `pre_http_request`.
 */

declare( strict_types = 1 );

use ATORA\EmailEngine\Email_Queue;
use ATORA\EmailEngine\Email_Unsubscribe;

final class EmailEngineDeliveryTest extends WP_UnitTestCase {

	/** @var array<int,array{url:string,args:array}> */
	private array $http = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		$this->assertTrue( class_exists( Email_Queue::class ), 'La cola de correo debe estar cargada.' );
		global $wpdb;
		foreach ( array( 'atora_email_queue', 'atora_email_suppression', 'atora_email_preferences', 'atora_email_consent_log' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		update_option( 'atora_email_engine_options', array(
			'provider'         => 'sendgrid',
			'sendgrid_api_key' => 'SG.prueba',
			'mailgun_api_key'  => 'key-prueba',
			'mailgun_domain'   => 'mg.example.test',
			'postmark_api_key' => 'pm-prueba',
			'brevo_api_key'    => 'xkeysib-prueba',
			'ses_access_key'   => 'AKIAPRUEBA',
			'ses_secret_key'   => 'secreto',
			'ses_region'       => 'us-east-1',
		) );
		$this->http = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		parent::tearDown();
	}

	public function capture( $pre, array $args, string $url ) {
		$this->http[] = array( 'url' => $url, 'args' => $args );
		return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 202, 'message' => 'Accepted' ), 'cookies' => array(), 'filename' => null );
	}

	private function row( string $provider, int $user_id = 0, string $template = 'special_offer', string $status = 'pending', array $extra = array() ): int {
		global $wpdb;
		$email = $user_id ? get_userdata( $user_id )->user_email : 'x' . wp_rand() . '@example.test';
		$wpdb->insert( $wpdb->prefix . 'atora_email_queue', $extra + array(
			'recipient_email' => $email,
			'recipient_name'  => 'Prueba',
			'user_id'         => $user_id,
			'subject'         => 'Hola',
			'body_html'       => '<p>Hola</p>',
			'body_text'       => 'Hola',
			'provider'        => $provider,
			'status'          => $status,
			'scheduled_at'    => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			'priority'        => 5,
			'error_message'   => '',
			'metadata'        => wp_json_encode( array( 'template' => $template ) ),
		) );
		return (int) $wpdb->insert_id;
	}

	private function row_status( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_email_queue WHERE id = %d", $id ) );
	}

	// ── Baja ────────────────────────────────────────────────────────────────

	public function test_token_is_signed_per_user_and_old_queue_ids_are_rejected(): void {
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();
		$token = Email_Unsubscribe::token( $user, get_userdata( $user )->user_email );

		$this->assertSame( $user, Email_Unsubscribe::verify( $token ) );
		$this->assertSame( 0, Email_Unsubscribe::verify( str_replace( 'u' . $user . '.', 'u' . $other . '.', $token ) ), 'Cambiar el ID invalida la firma.' );
		$this->assertSame( 0, Email_Unsubscribe::verify( (string) $this->row( 'sendgrid', $user ) ), 'El ID de la cola ya no sirve como token.' );
		$this->assertSame( 0, Email_Unsubscribe::verify( '' ) );
	}

	public function test_get_confirms_post_unsubscribes_and_commercial_stops(): void {
		$user  = self::factory()->user->create();
		$token = Email_Unsubscribe::token( $user, get_userdata( $user )->user_email );

		$this->assertSame( 'confirm', Email_Unsubscribe::handle( $token, 'GET' ) );
		$this->assertFalse( Email_Unsubscribe::is_unsubscribed( $user ), 'Abrir el enlace (o un antivirus) no da de baja.' );
		$this->assertGreaterThan( 0, (int) Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'special_offer', 'allow_duplicate' => true ) ), 'Control: antes de la baja, lo comercial se encola.' );

		$this->assertSame( 'done', Email_Unsubscribe::handle( $token, 'POST' ) );
		$this->assertTrue( Email_Unsubscribe::is_unsubscribed( $user ) );
		$this->assertSame( 'invalid', Email_Unsubscribe::handle( 'u1.deadbeef', 'POST' ) );

		$this->assertFalse( Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'special_offer', 'allow_duplicate' => true ) ), 'Tras la baja, nada comercial.' );
		$this->assertGreaterThan( 0, (int) Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'password_recovery', 'allow_duplicate' => true ) ), 'La cuenta sigue recibiendo lo de seguridad.' );

		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_consent_log WHERE user_id = %d AND action = 'unsubscribe'", $user ) ), 'La baja queda registrada.' );
	}

	public function test_email_body_carries_working_unsubscribe_link(): void {
		$user = self::factory()->user->create();
		$id   = (int) Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'special_offer', 'allow_duplicate' => true ) );
		global $wpdb;
		$html = (string) $wpdb->get_var( $wpdb->prepare( "SELECT body_html FROM {$wpdb->prefix}atora_email_queue WHERE id = %d", $id ) );
		$this->assertSame( 1, preg_match( '/atora_email_token=(u\d+\.[a-f0-9]{32})/', html_entity_decode( $html ), $m ), 'El enlace de baja del correo lleva token.' );
		$this->assertSame( $user, Email_Unsubscribe::verify( $m[1] ) );
	}

	// ── Cabeceras List-Unsubscribe en los seis proveedores ───────────────────

	public function test_list_unsubscribe_reaches_every_api_provider(): void {
		$user = self::factory()->user->create();
		foreach ( array( 'sendgrid', 'mailgun', 'postmark', 'brevo', 'ses' ) as $provider ) {
			$this->http = array();
			$id         = $this->row( $provider, $user );
			Email_Queue::process_batch();
			$this->assertSame( 'sent', $this->row_status( $id ), $provider );
			$this->assertNotEmpty( $this->http, $provider . ': llamó al proveedor.' );
			$body = $this->http[0]['args']['body'];
			$flat = is_array( $body ) ? wp_json_encode( $body ) : (string) $body;
			$this->assertStringContainsString( 'List-Unsubscribe', $flat, $provider );
			$this->assertStringContainsString( 'List-Unsubscribe=One-Click', $flat, $provider );
			$this->assertStringContainsString( 'atora_email_token', $flat, $provider );
		}
		$this->assertStringContainsString( 'X-Atora-Queue-ID', $flat, 'SES manda la cabecera que enlaza los eventos SNS con el envío.' );
	}

	public function test_list_unsubscribe_reaches_smtp_and_skips_transactional(): void {
		$user    = self::factory()->user->create();
		$headers = array();
		$grab    = static function ( $args ) use ( &$headers ) {
			$headers[] = implode( "\n", (array) $args['headers'] );
			return $args;
		};
		add_filter( 'wp_mail', $grab );
		add_filter( 'pre_wp_mail', '__return_true' );
		$commercial    = $this->row( 'smtp', $user, 'special_offer' );
		$transactional = $this->row( 'smtp', $user, 'password_recovery' );
		Email_Queue::process_batch();
		remove_filter( 'wp_mail', $grab );
		remove_filter( 'pre_wp_mail', '__return_true' );

		$this->assertSame( 'sent', $this->row_status( $commercial ) );
		$this->assertCount( 2, $headers );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $headers[0] . $headers[1] );
		$this->assertSame( 1, substr_count( $headers[0] . $headers[1], 'List-Unsubscribe:' ), 'Lo de seguridad no lleva baja (no se puede dar de baja de eso).' );
	}

	public function test_header_injection_is_dropped(): void {
		$clean = Email_Unsubscribe::clean_headers( array( 'X-Ok' => 'valor', "X-Mal\r\nBcc" => 'x', 'X-Valor' => "a\r\nBcc: otro@example.test", 'Bad Name' => 'x' ) );
		$this->assertSame( array( 'X-Ok' => 'valor' ), $clean );
	}

	// ── Cola ────────────────────────────────────────────────────────────────

	public function test_second_worker_does_nothing_while_first_holds_the_lock(): void {
		$id    = $this->row( 'sendgrid' );
		$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('atora_email_queue_batch', 0)" )->fetch_row()[0] );
		Email_Queue::process_batch();
		$this->assertSame( 'pending', $this->row_status( $id ), 'Con el lote tomado por otro proceso, no se envía nada.' );
		$other->query( "SELECT RELEASE_LOCK('atora_email_queue_batch')" );
		$other->close();
		Email_Queue::process_batch();
		$this->assertSame( 'sent', $this->row_status( $id ) );
	}

	public function test_provider_rate_limit_per_minute(): void {
		$options = get_option( 'atora_email_engine_options' );
		update_option( 'atora_email_engine_options', $options + array( 'rate_limit_sendgrid_minute' => 2 ) );
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->row( 'sendgrid' );
		}
		$mailgun = $this->row( 'mailgun' );
		Email_Queue::process_batch();

		$statuses = array_map( array( $this, 'row_status' ), $ids );
		$this->assertSame( 2, count( array_keys( $statuses, 'sent', true ) ), 'Solo 2 por minuto en SendGrid.' );
		$this->assertSame( 3, count( array_keys( $statuses, 'pending', true ) ), 'El resto espera, no falla.' );
		$this->assertSame( 'sent', $this->row_status( $mailgun ), 'El límite es por proveedor.' );

		Email_Queue::process_batch();
		$this->assertSame( 2, count( array_keys( array_map( array( $this, 'row_status' ), $ids ), 'sent', true ) ), 'Dentro del mismo minuto, sigue sin pasar el límite.' );
	}

	public function test_transactional_goes_first(): void {
		$options = get_option( 'atora_email_engine_options' );
		update_option( 'atora_email_engine_options', $options + array( 'rate_limit_sendgrid_minute' => 1 ) );
		$bulk   = $this->row( 'sendgrid', 0, 'special_offer', 'pending', array( 'priority' => 10 ) );
		$urgent = $this->row( 'sendgrid', 0, 'password_recovery', 'pending', array( 'priority' => 1 ) );
		Email_Queue::process_batch();
		$this->assertSame( 'sent', $this->row_status( $urgent ) );
		$this->assertSame( 'pending', $this->row_status( $bulk ) );
	}

	public function test_stuck_sending_row_is_failed_not_resent(): void {
		$stuck = $this->row( 'sendgrid', 0, 'special_offer', 'sending', array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) );
		$fresh = $this->row( 'sendgrid', 0, 'special_offer', 'sending', array( 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
		Email_Queue::process_batch();
		$this->assertSame( 'failed', $this->row_status( $stuck ), 'Interrumpido: queda fallido para revisar.' );
		$this->assertSame( 'sending', $this->row_status( $fresh ), 'Uno reciente puede estar enviándose ahora.' );
		$this->assertEmpty( $this->http, 'No se reenvía por su cuenta.' );
	}
}
