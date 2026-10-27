<?php
/**
 * Integración 6.38.0: el boletín se encola de verdad (antes usaba una plantilla inexistente y no llegaba a
 * nadie), se envía por lotes sin duplicar, respeta segmento, supresión y baja, y se puede pausar,
 * reanudar y cancelar.
 */

declare( strict_types = 1 );

use ATORA\Newsletter\Newsletter;

final class NewsletterSendTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( ! class_exists( Newsletter::class ) ) {
			// El módulo puede estar apagado en la configuración de prueba: se carga explícitamente.
			require_once dirname( __DIR__, 2 ) . '/modules/newsletter/class-newsletter.php';
		}
		$this->assertTrue( class_exists( '\\ATORA\\EmailEngine\\Email_Queue' ), 'La cola de correo debe estar cargada.' );
		if ( ! post_type_exists( 'atora_newsletter' ) ) {
			Newsletter::register_cpt();
		}
		global $wpdb;
		foreach ( array( 'atora_email_queue', 'atora_email_suppression', 'atora_email_preferences' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		// Solo los usuarios que crea cada prueba son destinatarios.
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
			update_user_meta( (int) $id, 'atora_consent_marketing', '0' );
		}
	}

	private function subscribers( int $n, array $meta = array() ): array {
		$ids = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$id = self::factory()->user->create();
			update_user_meta( $id, 'atora_consent_marketing', '1' );
			foreach ( $meta as $k => $v ) {
				update_user_meta( $id, $k, $v );
			}
			$ids[] = $id;
		}
		return $ids;
	}

	private function newsletter( array $extra = array() ): int {
		$id = Newsletter::save( $extra + array(
			'title'    => 'Boletín de prueba',
			'sections' => array( array( 'type' => 'article', 'title' => 'Novedades', 'content' => 'Contenido del boletín' ) ),
		) );
		$this->assertIsInt( $id );
		return $id;
	}

	/** @return array<int,int> user_id => filas en cola para este boletín */
	private function queued( int $newsletter ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT user_id, COUNT(*) AS n FROM {$wpdb->prefix}atora_email_queue WHERE metadata LIKE %s GROUP BY user_id",
			'%"newsletter_id":' . $newsletter . ',%'
		) );
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->user_id ] = (int) $row->n;
		}
		return $out;
	}

	private function drain( int $id ): void {
		for ( $i = 0; $i < 20 && 'sending' === get_post_meta( $id, 'atora_nl_status', true ); $i++ ) {
			Newsletter::process_send_batch( $id );
		}
	}

	public function test_newsletter_really_enqueues_with_body_and_subject(): void {
		$users = $this->subscribers( 2 );
		$id    = $this->newsletter();
		Newsletter::send( $id );

		$queued = $this->queued( $id );
		$this->assertSame( array_fill_keys( $users, 1 ), $queued, 'Cada suscriptor recibe una sola copia.' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT subject, body_html FROM {$wpdb->prefix}atora_email_queue WHERE user_id = %d", $users[0] ) );
		$this->assertSame( 'Boletín de prueba', $row->subject );
		$this->assertStringContainsString( 'Contenido del boletín', $row->body_html, 'El cuerpo trae las secciones del boletín.' );
		$this->assertSame( 'sent', get_post_meta( $id, 'atora_nl_status', true ) );
	}

	public function test_batches_without_duplicates_and_no_resend(): void {
		$users = $this->subscribers( Newsletter::BATCH_SIZE + 5 );
		$id    = $this->newsletter();

		$first = Newsletter::send( $id );
		$this->assertSame( 'sending', $first['status'], 'Más de un lote: sigue en segundo plano.' );
		$this->assertSame( Newsletter::BATCH_SIZE, $first['queued'] );
		$this->assertNotFalse( wp_next_scheduled( 'atora_nl_send_batch', array( $id ) ), 'El siguiente lote queda programado.' );

		// Un reintento del mismo lote (cursor viejo) no duplica.
		update_post_meta( $id, 'atora_nl_cursor', 0 );
		$this->drain( $id );
		Newsletter::send( $id ); // Ya enviado: no se repite.

		$queued = $this->queued( $id );
		$this->assertCount( count( $users ), $queued );
		$this->assertSame( array( 1 ), array_values( array_unique( $queued ) ), 'Nadie recibe dos copias.' );
	}

	public function test_segment_suppression_and_unsubscribe(): void {
		$in        = $this->subscribers( 2, array( 'atora_country' => 'CO' ) );
		$out       = $this->subscribers( 1, array( 'atora_country' => 'MX' ) );
		$bounced   = $this->subscribers( 1, array( 'atora_country' => 'CO' ) );
		$unsub     = $this->subscribers( 1, array( 'atora_country' => 'CO' ) );
		$no_consent = $this->subscribers( 1, array( 'atora_country' => 'CO' ) );
		update_user_meta( $no_consent[0], 'atora_consent_marketing', '0' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_email_suppression', array( 'email' => get_userdata( $bounced[0] )->user_email, 'reason' => 'bounce' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_email_preferences', array( 'user_id' => $unsub[0], 'unsubscribed_all' => 1 ) );

		$id = $this->newsletter( array( 'segment' => array( 'rules' => array( array( 'field' => 'country', 'operator' => 'equals', 'value' => 'CO' ) ) ) ) );
		Newsletter::send( $id );

		$this->assertSame( $in, array_keys( $this->queued( $id ) ), 'Solo el segmento, sin suprimidos, bajas ni sin consentimiento.' );
		$this->assertNotContains( $out[0], array_keys( $this->queued( $id ) ) );
	}

	public function test_ab_split_is_deterministic(): void {
		$users = $this->subscribers( 4 );
		$id    = $this->newsletter( array(
			'ab_enabled'  => 1,
			'ab_variants' => array( 'variant_a' => array( 'subject' => 'Asunto A' ), 'variant_b' => array( 'subject' => 'Asunto B' ) ),
		) );
		Newsletter::send( $id );

		global $wpdb;
		foreach ( $users as $user ) {
			$subject = $wpdb->get_var( $wpdb->prepare( "SELECT subject FROM {$wpdb->prefix}atora_email_queue WHERE user_id = %d", $user ) );
			$this->assertSame( 0 === $user % 2 ? 'Asunto A' : 'Asunto B', $subject );
		}
		$variants = json_decode( (string) get_post_meta( $id, 'atora_nl_ab_variants', true ), true );
		$this->assertSame( 4, $variants['variant_a']['sent_to'] + $variants['variant_b']['sent_to'] );
	}

	public function test_pause_resume_cancel(): void {
		$this->subscribers( Newsletter::BATCH_SIZE * 2 + 1 );
		$id = $this->newsletter();

		Newsletter::send( $id );
		$this->assertSame( 'paused', Newsletter::pause( $id )['status'] );
		$this->assertFalse( wp_next_scheduled( 'atora_nl_send_batch', array( $id ) ), 'Pausado: sin lote programado.' );
		$this->assertSame( Newsletter::BATCH_SIZE, Newsletter::process_send_batch( $id )['queued'], 'Pausado: el cron no avanza.' );

		// Editar no reinicia el estado de un envío en curso.
		Newsletter::save( array( 'id' => $id, 'title' => 'Boletín de prueba' ) );
		$this->assertSame( 'paused', get_post_meta( $id, 'atora_nl_status', true ) );

		$this->assertSame( Newsletter::BATCH_SIZE * 2, Newsletter::resume( $id )['queued'] );
		$this->assertSame( 'cancelled', Newsletter::cancel( $id )['status'] );
		$this->assertSame( Newsletter::BATCH_SIZE * 2, Newsletter::process_send_batch( $id )['queued'], 'Cancelado: no se envía más.' );
	}
}
