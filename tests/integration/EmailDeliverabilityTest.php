<?php
/**
 * Integración 6.38.0: panel de entregabilidad. Métricas por día y proveedor a partir de la cola y los eventos;
 * verificador DNS con un resolvedor simulado (no consulta DNS real).
 */

declare( strict_types = 1 );

use ATORA\EmailEngine\Email_Deliverability;

final class EmailDeliverabilityTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		$this->assertTrue( class_exists( Email_Deliverability::class ), 'El panel de entregabilidad debe estar cargado.' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_email_queue" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_email_events" );
	}

	private function sent( string $provider, string $day, array $events = array(), array $extra = array() ): int {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_email_queue', $extra + array(
			'recipient_email' => 'a@example.test', 'recipient_name' => '', 'subject' => 's', 'body_html' => '', 'body_text' => '',
			'provider' => $provider, 'status' => 'sent', 'sent_at' => $day . ' 10:00:00', 'error_message' => '', 'metadata' => '{}',
		) );
		$id = (int) $wpdb->insert_id;
		foreach ( $events as $event ) {
			$wpdb->insert( $wpdb->prefix . 'atora_email_events', array( 'queue_id' => $id, 'event_type' => $event, 'event_data' => '{}' ) );
		}
		return $id;
	}

	public function test_daily_stats_by_provider(): void {
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$this->sent( 'sendgrid', $today, array( 'delivered', 'opened' ), array( 'opened_at' => $today . ' 11:00:00' ) );
		$this->sent( 'sendgrid', $today, array( 'delivered', 'delivered' ) ); // Evento repetido: cuenta una vez.
		$this->sent( 'sendgrid', $today, array( 'bounced' ) );
		$this->sent( 'mailgun', $today, array( 'complained' ), array( 'clicked_at' => $today . ' 12:00:00' ) );
		$this->sent( 'sendgrid', $yesterday, array( 'delivered' ) );
		$this->sent( 'sendgrid', gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS ) ); // Fuera del periodo.

		$rows = array();
		foreach ( Email_Deliverability::daily_stats( 14 ) as $row ) {
			$rows[ $row['day'] . '/' . $row['provider'] ] = $row;
		}
		$this->assertCount( 3, $rows );
		$sg = $rows[ $today . '/sendgrid' ];
		$this->assertSame( array( 3, 2, 1, 0, 1, 0 ), array( $sg['sent'], $sg['delivered'], $sg['bounced'], $sg['complained'], $sg['opened'], $sg['clicked'] ) );
		$mg = $rows[ $today . '/mailgun' ];
		$this->assertSame( array( 1, 1, 1 ), array( $mg['sent'], $mg['complained'], $mg['clicked'] ) );
		$this->assertSame( 1, $rows[ $yesterday . '/sendgrid' ]['delivered'] );
	}

	private function resolver( array $zone ): callable {
		return static fn( string $host, string $type ): array => $zone[ $type . ' ' . $host ] ?? array();
	}

	private function by_check( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row['check'] ] = $row;
		}
		return $out;
	}

	public function test_dns_all_correct_for_provider(): void {
		$rows = $this->by_check( Email_Deliverability::dns_check( 'academia.test', 'sendgrid', '', $this->resolver( array(
			'TXT academia.test'                => array( 'google-site-verification=x', 'v=spf1 include:_spf.google.com include:sendgrid.net ~all' ),
			'CNAME s1._domainkey.academia.test' => array( 's1.domainkey.u123.wl.sendgrid.net' ),
			'TXT _dmarc.academia.test'         => array( 'v=DMARC1; p=quarantine; rua=mailto:d@academia.test' ),
		) ) ) );
		$this->assertSame( 'ok', $rows['SPF']['status'] );
		$this->assertSame( 'ok', $rows['DKIM']['status'] );
		$this->assertSame( 'ok', $rows['DMARC']['status'] );
	}

	public function test_dns_missing_and_wrong_records_get_concrete_fixes(): void {
		$rows = $this->by_check( Email_Deliverability::dns_check( 'academia.test', 'mailgun', 'k1', $this->resolver( array(
			'TXT academia.test'        => array( 'v=spf1 include:_spf.google.com ~all' ),
			'TXT _dmarc.academia.test' => array( 'v=DMARC1; p=none' ),
		) ) ) );
		$this->assertSame( 'warning', $rows['SPF']['status'] );
		$this->assertStringContainsString( 'include:mailgun.org', $rows['SPF']['fix'] );
		$this->assertSame( 'missing', $rows['DKIM']['status'] );
		$this->assertStringContainsString( 'k1._domainkey.academia.test', $rows['DKIM']['fix'] );
		$this->assertSame( 'warning', $rows['DMARC']['status'] );

		$empty = $this->by_check( Email_Deliverability::dns_check( 'nuevo.test', 'brevo', '', $this->resolver( array() ) ) );
		$this->assertSame( 'missing', $empty['SPF']['status'] );
		$this->assertStringContainsString( 'v=spf1 include:spf.brevo.com ~all', $empty['SPF']['fix'] );
		$this->assertStringContainsString( 'v=DMARC1; p=none; rua=mailto:dmarc@nuevo.test', $empty['DMARC']['fix'] );
	}

	public function test_dns_flags_double_spf_and_plus_all(): void {
		$double = $this->by_check( Email_Deliverability::dns_check( 'a.test', 'smtp', '', $this->resolver( array( 'TXT a.test' => array( 'v=spf1 a ~all', 'v=spf1 mx ~all' ) ) ) ) );
		$this->assertSame( 'warning', $double['SPF']['status'] );
		$plus = $this->by_check( Email_Deliverability::dns_check( 'a.test', 'smtp', '', $this->resolver( array( 'TXT a.test' => array( 'v=spf1 +all' ) ) ) ) );
		$this->assertStringContainsString( '+all', $plus['SPF']['fix'] );
		$bad = Email_Deliverability::dns_check( 'no es dominio', 'smtp', '', $this->resolver( array() ) );
		$this->assertSame( 'missing', $bad[0]['status'] );
	}
}
