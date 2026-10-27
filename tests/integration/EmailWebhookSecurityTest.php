<?php
/**
 * Integración 6.38.0: webhooks de correo. Firma por proveedor, repetición rechazada, cada evento una sola
 * vez, rebote permanente suprime (temporal no) y lo suprimido no se vuelve a encolar.
 */

declare( strict_types = 1 );

use ATORA\EmailEngine\Email_Engine;
use ATORA\EmailEngine\Email_Queue;
use ATORA\EmailEngine\Email_Webhook_Security;

final class EmailWebhookSecurityTest extends WP_UnitTestCase {

	private const MAILGUN_KEY = 'key-de-prueba-mailgun';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( ! class_exists( Email_Engine::class ) ) {
			$this->markTestSkipped( 'Módulo de correo inactivo.' );
		}
		global $wpdb;
		foreach ( array( 'atora_email_webhook_events', 'atora_email_suppression', 'atora_email_queue' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		update_option( 'atora_email_engine_options', array(
			'mailgun_webhook_signing_key' => self::MAILGUN_KEY,
			'postmark_webhook_user'       => 'pm-user',
			'postmark_webhook_pass'       => 'pm-pass',
		) );
	}

	private function queue_row( string $email ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_email_queue', array(
			'recipient_email' => $email,
			'recipient_name'  => 'Prueba',
			'user_id'         => 0,
			'subject'         => 'Hola',
			'body_html'       => '<p>Hola</p>',
			'body_text'       => 'Hola',
			'status'          => 'sent',
			'scheduled_at'    => current_time( 'mysql', true ),
			'priority'        => 5,
			'metadata'        => '{}',
		) );
		return (int) $wpdb->insert_id;
	}

	private function mailgun( array $event_data, ?int $timestamp = null, string $token = '' ): WP_REST_Response {
		$timestamp = $timestamp ?? time();
		$token     = '' !== $token ? $token : wp_generate_password( 20, false );
		$body      = array(
			'signature'  => array( 'timestamp' => (string) $timestamp, 'token' => $token, 'signature' => hash_hmac( 'sha256', $timestamp . $token, self::MAILGUN_KEY ) ),
			'event-data' => $event_data,
		);
		$request = new WP_REST_Request( 'POST', '/atora/v1/webhooks/mailgun' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return Email_Engine::handle_provider_webhook( 'mailgun', $request );
	}

	private function suppressed( string $email ): string {
		return Email_Engine::suppression_reason( $email );
	}

	public function test_mailgun_signature_freshness_and_single_use_token(): void {
		$queue = $this->queue_row( 'a@example.test' );
		$event = array( 'id' => 'ev-1', 'event' => 'opened', 'user-variables' => array( 'atora_queue_id' => $queue ) );

		$this->assertSame( 200, $this->mailgun( $event, time(), 'tok-1' )->get_status() );
		$this->assertSame( 403, $this->mailgun( $event + array(), time(), 'tok-1' )->get_status(), 'El mismo token no se acepta dos veces.' );
		$this->assertSame( 403, $this->mailgun( array( 'id' => 'ev-2' ) + $event, time() - 3600 )->get_status(), 'Una firma vieja se rechaza.' );

		$bad     = new WP_REST_Request( 'POST', '/atora/v1/webhooks/mailgun' );
		$bad->set_header( 'content-type', 'application/json' );
		$bad->set_body( wp_json_encode( array( 'signature' => array( 'timestamp' => (string) time(), 'token' => 'x', 'signature' => 'mala' ), 'event-data' => $event ) ) );
		$this->assertSame( 403, Email_Engine::handle_provider_webhook( 'mailgun', $bad )->get_status() );
	}

	public function test_each_event_is_processed_once(): void {
		global $wpdb;
		$queue = $this->queue_row( 'b@example.test' );
		$event = array( 'id' => 'ev-dup', 'event' => 'clicked', 'user-variables' => array( 'atora_queue_id' => $queue ) );
		$this->mailgun( $event );
		$this->mailgun( $event );
		$logged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_events WHERE queue_id = %d AND event_type = 'clicked'", $queue ) );
		$this->assertSame( 1, $logged, 'Un reintento del proveedor no duplica el evento.' );
	}

	public function test_hard_bounce_suppresses_soft_does_not_and_enqueue_skips(): void {
		$soft = $this->queue_row( 'soft@example.test' );
		$this->mailgun( array( 'id' => 'ev-soft', 'event' => 'failed', 'severity' => 'temporary', 'user-variables' => array( 'atora_queue_id' => $soft ) ) );
		$this->assertSame( '', $this->suppressed( 'soft@example.test' ), 'Un rebote temporal no suprime.' );

		$hard = $this->queue_row( 'hard@example.test' );
		$this->mailgun( array( 'id' => 'ev-hard', 'event' => 'failed', 'severity' => 'permanent', 'user-variables' => array( 'atora_queue_id' => $hard ) ) );
		$this->assertSame( 'bounce', $this->suppressed( 'hard@example.test' ) );

		$ok = self::factory()->user->create( array( 'user_email' => 'ok@example.test' ) );
		$this->assertGreaterThan( 0, (int) Email_Queue::enqueue( array( 'user_id' => $ok, 'template' => 'welcome_course', 'allow_duplicate' => true ) ), 'Control: una dirección normal sí se encola.' );

		$user = self::factory()->user->create( array( 'user_email' => 'hard@example.test' ) );
		$this->assertFalse( Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'welcome_course', 'allow_duplicate' => true ) ), 'A una dirección con rebote permanente no se le encola nada.' );
	}

	public function test_complaint_blocks_commercial_but_not_academic(): void {
		$queue = $this->queue_row( 'spam@example.test' );
		$this->mailgun( array( 'id' => 'ev-spam', 'event' => 'complained', 'user-variables' => array( 'atora_queue_id' => $queue ) ) );
		$this->assertSame( 'spam', $this->suppressed( 'spam@example.test' ) );

		$user = self::factory()->user->create( array( 'user_email' => 'spam@example.test' ) );
		$this->assertFalse( Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'special_offer', 'allow_duplicate' => true ) ), 'Tras una queja, nada comercial.' );
		$this->assertGreaterThan( 0, (int) Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'password_recovery', 'allow_duplicate' => true ) ), 'Lo de sistema (recuperar cuenta) sí.' );
	}

	public function test_postmark_requires_basic_auth(): void {
		$queue   = $this->queue_row( 'c@example.test' );
		$payload = wp_json_encode( array( 'RecordType' => 'Open', 'MessageID' => 'm-1', 'ReceivedAt' => gmdate( 'c' ), 'Metadata' => array( 'atora_queue_id' => $queue ) ) );

		$anon = new WP_REST_Request( 'POST', '/atora/v1/webhooks/postmark' );
		$anon->set_header( 'content-type', 'application/json' );
		$anon->set_body( $payload );
		$this->assertSame( 403, Email_Engine::handle_provider_webhook( 'postmark', $anon )->get_status() );

		$auth = new WP_REST_Request( 'POST', '/atora/v1/webhooks/postmark' );
		$auth->set_header( 'content-type', 'application/json' );
		$auth->set_header( 'authorization', 'Basic ' . base64_encode( 'pm-user:pm-pass' ) );
		$auth->set_body( $payload );
		$this->assertSame( 200, Email_Engine::handle_provider_webhook( 'postmark', $auth )->get_status() );
	}

	public function test_sns_signature_verification(): void {
		$key  = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$csr  = openssl_csr_new( array( 'commonName' => 'sns.us-east-1.amazonaws.com' ), $key );
		$x509 = openssl_csr_sign( $csr, null, $key, 1 );
		openssl_x509_export( $x509, $pem );

		$message = array(
			'Type'             => 'Notification',
			'MessageId'        => 'sns-1',
			'TopicArn'         => 'arn:aws:sns:us-east-1:123:ses-events',
			'Message'          => wp_json_encode( array( 'notificationType' => 'Bounce' ) ),
			'Timestamp'        => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'SignatureVersion' => '2',
			'SigningCertURL'   => 'https://sns.us-east-1.amazonaws.com/cert.pem',
		);
		$string = '';
		foreach ( array( 'Message', 'MessageId', 'Timestamp', 'TopicArn', 'Type' ) as $field ) {
			$string .= $field . "\n" . $message[ $field ] . "\n";
		}
		openssl_sign( $string, $signature, $key, OPENSSL_ALGO_SHA256 );
		$message['Signature'] = base64_encode( $signature );
		$fetch = static fn() => $pem;

		$this->assertTrue( Email_Webhook_Security::verify_sns( $message, 'arn:aws:sns:us-east-1:123:ses-events', $fetch ) );
		$this->assertFalse( Email_Webhook_Security::verify_sns( $message, 'arn:aws:sns:us-east-1:999:otro', $fetch ), 'Otro tema: rechazado.' );
		$this->assertFalse( Email_Webhook_Security::verify_sns( array( 'Message' => 'alterado' ) + $message, '', $fetch ), 'Mensaje alterado: rechazado.' );
		$this->assertFalse( Email_Webhook_Security::verify_sns( array( 'SigningCertURL' => 'https://evil.example/cert.pem' ) + $message, '', $fetch ), 'Certificado fuera de amazonaws.com: rechazado.' );
		$this->assertFalse( Email_Webhook_Security::is_aws_sns_url( 'http://sns.us-east-1.amazonaws.com/c.pem' ) );
	}
}
