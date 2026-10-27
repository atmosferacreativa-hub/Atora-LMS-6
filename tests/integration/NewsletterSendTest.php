<?php
/**
 * Integración 6.38.0: boletín.
 * - Se encola de verdad (antes usaba una plantilla inexistente y no llegaba a nadie), por lotes y sin duplicar.
 * - Un suscriptor es un contacto (lista `boletin` del CRM), con o sin cuenta.
 * - Doble opt-in, confirmación y baja con enlaces firmados; registro de consentimiento.
 * - Migración idempotente con la regla del titular: solo el consentimiento explícito queda suscrito.
 * - Segmentos en la base de datos; 10.000 contactos sin pasar un límite de memoria.
 * - Pausa, reanudación, cancelación y métricas por envío.
 */

declare( strict_types = 1 );

use ATORA\EmailEngine\Email_Unsubscribe;
use ATORA\Newsletter\Newsletter;
use ATORA\Newsletter\Newsletter_Subscribers as Subs;

final class NewsletterSendTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		foreach ( array( 'class-newsletter.php', 'class-newsletter-subscribers.php' ) as $file ) {
			// El módulo puede estar apagado en la configuración de prueba: se carga explícitamente.
			require_once dirname( __DIR__, 2 ) . '/modules/newsletter/' . $file;
		}
		$this->assertTrue( class_exists( '\\ATORA\\EmailEngine\\Email_Queue' ), 'La cola de correo debe estar cargada.' );
		if ( ! post_type_exists( 'atora_newsletter' ) ) {
			Newsletter::register_cpt();
		}
		global $wpdb;
		foreach ( array( 'atora_email_queue', 'atora_email_suppression', 'atora_email_preferences', 'atora_contacts', 'atora_contact_list_pivot', 'atora_contact_tags', 'atora_email_consent_log', 'atora_enrollments', 'atora_institution_members' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		delete_option( 'atora_newsletter_migrated_at' );
		update_option( Subs::OPTION_DOUBLE, 1 );
	}

	/** Usuarios suscritos y confirmados. @return int[] contact ids */
	private function subscribers( int $n ): array {
		$ids = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$user = self::factory()->user->create();
			$this->assertSame( 'subscribed', Subs::subscribe( get_userdata( $user )->user_email, '', $user, 'test', false ) );
			$ids[] = Subs::ensure_contact( get_userdata( $user )->user_email, '', $user );
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

	/** @return array<int,int> contact_id => filas en cola para este boletín */
	private function queued( int $newsletter ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT CAST( JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.contact_id' ) ) AS UNSIGNED ) AS c, COUNT(*) AS n
			 FROM {$wpdb->prefix}atora_email_queue
			 WHERE CAST( JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.newsletter_id' ) ) AS UNSIGNED ) = %d GROUP BY c ORDER BY c",
			$newsletter
		) );
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->c ] = (int) $row->n;
		}
		return $out;
	}

	private function drain( int $id ): void {
		for ( $i = 0; $i < 100 && 'sending' === get_post_meta( $id, 'atora_nl_status', true ); $i++ ) {
			Newsletter::process_send_batch( $id );
		}
	}

	// ── Envío ───────────────────────────────────────────────────────────────

	public function test_newsletter_really_enqueues_with_body_and_subject(): void {
		$contacts = $this->subscribers( 2 );
		$id       = $this->newsletter();
		Newsletter::send( $id );

		$this->assertSame( array_fill_keys( $contacts, 1 ), $this->queued( $id ), 'Cada suscriptor recibe una sola copia.' );
		global $wpdb;
		$row = $wpdb->get_row( "SELECT subject, body_html FROM {$wpdb->prefix}atora_email_queue ORDER BY id ASC LIMIT 1" );
		$this->assertSame( 'Boletín de prueba', $row->subject );
		$this->assertStringContainsString( 'Contenido del boletín', $row->body_html, 'El cuerpo trae las secciones (con acentos intactos).' );
		$this->assertSame( 'sent', get_post_meta( $id, 'atora_nl_status', true ) );
	}

	public function test_batches_without_duplicates_and_no_resend(): void {
		$contacts = $this->subscribers( Newsletter::BATCH_SIZE + 5 );
		$id       = $this->newsletter();

		$first = Newsletter::send( $id );
		$this->assertSame( 'sending', $first['status'], 'Más de un lote: sigue en segundo plano.' );
		$this->assertSame( Newsletter::BATCH_SIZE, $first['queued'] );
		$this->assertNotFalse( wp_next_scheduled( 'atora_nl_send_batch', array( $id ) ), 'El siguiente lote queda programado.' );

		update_post_meta( $id, 'atora_nl_cursor', 0 ); // Corte a mitad: el cron vuelve a empezar.
		$this->drain( $id );
		Newsletter::send( $id ); // Ya enviado: no se repite.

		$queued = $this->queued( $id );
		$this->assertCount( count( $contacts ), $queued );
		$this->assertSame( array( 1 ), array_values( array_unique( $queued ) ), 'Nadie recibe dos copias.' );
	}

	public function test_contact_without_account_receives_and_unsubscribes_in_one_click(): void {
		$this->assertSame( 'subscribed', Subs::subscribe( 'Externa@Example.test ', 'Externa', 0, 'form', false ) );
		$contact = Subs::ensure_contact( 'externa@example.test' );
		$this->assertSame( $contact, Subs::ensure_contact( 'EXTERNA@example.test' ), 'Mismo correo, mismo contacto.' );

		$id = $this->newsletter();
		Newsletter::send( $id );
		$this->assertSame( array( $contact => 1 ), $this->queued( $id ) );

		global $wpdb;
		$html = (string) $wpdb->get_var( "SELECT body_html FROM {$wpdb->prefix}atora_email_queue LIMIT 1" );
		$this->assertSame( 1, preg_match( '/atora_email_token=(c\d+\.[a-f0-9]{32})/', html_entity_decode( $html ), $m ), 'El pie trae baja para el contacto.' );
		$this->assertSame( 'confirm', Email_Unsubscribe::handle( $m[1], 'GET' ) );
		$this->assertSame( 'subscribed', Subs::status( $contact ), 'Abrir el enlace no da de baja.' );
		$this->assertSame( 'done', Email_Unsubscribe::handle( $m[1], 'POST' ) );
		$this->assertSame( 'unsubscribed', Subs::status( $contact ) );

		$next = $this->newsletter( array( 'title' => 'Segundo' ) );
		Newsletter::send( $next );
		$this->assertSame( array(), $this->queued( $next ), 'Tras la baja no recibe más.' );
	}

	// ── Doble opt-in ───────────────────────────────────────────────────────

	public function test_double_optin_pending_until_confirmed(): void {
		$this->assertSame( 'pending', Subs::subscribe( 'nueva@example.test', 'Nueva' ) );
		$contact = Subs::ensure_contact( 'nueva@example.test' );

		$id = $this->newsletter();
		Newsletter::send( $id );
		$this->assertSame( array(), $this->queued( $id ), 'Pendiente: no recibe el boletín.' );

		global $wpdb;
		$confirm = $wpdb->get_row( "SELECT subject, body_html FROM {$wpdb->prefix}atora_email_queue WHERE recipient_email = 'nueva@example.test' ORDER BY id DESC LIMIT 1" );
		$this->assertNotNull( $confirm, 'Se envió el correo de confirmación.' );
		$this->assertSame( 1, preg_match( '/atora_email_token=(n\d+\.[a-f0-9]{32})/', html_entity_decode( (string) $confirm->body_html ), $m ) );

		$this->assertSame( 'invalid', Subs::confirm( 'n' . $contact . '.' . str_repeat( 'a', 32 ) ) );
		$this->assertSame( 'pending', Subs::status( $contact ) );
		$this->assertSame( 'confirmed', Subs::confirm( $m[1] ) );
		$this->assertSame( 'subscribed', Subs::status( $contact ) );

		$log = $wpdb->get_col( $wpdb->prepare( "SELECT action FROM {$wpdb->prefix}atora_email_consent_log WHERE preference_key = %s ORDER BY id", 'newsletter:contact:' . $contact ) );
		$this->assertSame( array( 'subscribe_pending', 'subscribe_confirmed' ), $log, 'El consentimiento queda registrado.' );

		$second = $this->newsletter( array( 'title' => 'Ya confirmada' ) );
		Newsletter::send( $second );
		$this->assertSame( array( $contact => 1 ), $this->queued( $second ) );
	}

	public function test_public_form_requires_consent_and_hides_existing(): void {
		$r = new WP_REST_Request( 'POST', '/atora/v1/newsletter/subscribe' );
		$r->set_param( 'email', 'form@example.test' );
		$r->set_param( 'consent', false );
		$this->assertSame( 400, Subs::rest_subscribe( $r )->get_status(), 'Sin casilla, no hay alta.' );

		$r->set_param( 'consent', true );
		$this->assertSame( 'pending', Subs::rest_subscribe( $r )->get_data()['status'] );

		$bot = new WP_REST_Request( 'POST', '/atora/v1/newsletter/subscribe' );
		$bot->set_param( 'email', 'bot@example.test' );
		$bot->set_param( 'consent', true );
		$bot->set_param( 'website', 'spam' );
		Subs::rest_subscribe( $bot );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE email = 'bot@example.test'" ), 'El campo trampa no guarda nada.' );

		// Un usuario existente siempre confirma por correo, aunque el doble opt-in esté apagado.
		update_option( Subs::OPTION_DOUBLE, 0 );
		$user = self::factory()->user->create( array( 'user_email' => 'cuenta@example.test' ) );
		$u    = new WP_REST_Request( 'POST', '/atora/v1/newsletter/subscribe' );
		$u->set_param( 'email', 'cuenta@example.test' );
		$u->set_param( 'consent', true );
		$this->assertSame( 'pending', Subs::rest_subscribe( $u )->get_data()['status'] );
		$this->assertSame( 'pending', Subs::status( Subs::ensure_contact( 'cuenta@example.test', '', $user ) ) );
	}

	// ── Migración ───────────────────────────────────────────────────────────

	public function test_migration_follows_explicit_consent_and_is_idempotent(): void {
		global $wpdb;
		foreach ( get_users( array( 'fields' => 'ID' ) ) as $id ) {
			update_user_meta( (int) $id, 'atora_consent_marketing', '0' ); // Usuarios previos fuera del conteo.
		}
		$yes    = self::factory()->user->create( array( 'user_email' => 'Si@Example.test' ) );
		$none   = self::factory()->user->create();
		$no     = self::factory()->user->create();
		$unsub  = self::factory()->user->create();
		$bounce = self::factory()->user->create();
		update_user_meta( $yes, 'atora_consent_marketing', '1' );
		delete_user_meta( $none, 'atora_consent_marketing' ); // Usuario anterior al registro extendido: sin marca.
		update_user_meta( $no, 'atora_consent_marketing', '0' );
		update_user_meta( $unsub, 'atora_consent_marketing', '1' );
		update_user_meta( $bounce, 'atora_consent_marketing', '1' );
		$wpdb->insert( $wpdb->prefix . 'atora_email_preferences', array( 'user_id' => $unsub, 'unsubscribed_all' => 1 ) );
		$wpdb->insert( $wpdb->prefix . 'atora_email_suppression', array( 'email' => get_userdata( $bounce )->user_email, 'reason' => 'bounce' ) );
		// Contacto previo con el mismo correo en otra capitalización: no se duplica.
		$wpdb->insert( $wpdb->prefix . 'atora_contacts', array( 'email' => 'si@example.test', 'name' => 'Previo', 'source' => 'manual', 'status' => 'student' ) );
		$previous = (int) $wpdb->insert_id;

		$plan = Subs::migrate( true );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contact_list_pivot" ), 'La simulación no escribe.' );
		$this->assertSame( array( 1, 1 ), array( $plan['subscribed'], $plan['pending'] ) );

		$first = Subs::migrate();
		$this->assertSame( array( 1, 1 ), array( $first['subscribed'], $first['pending'] ) );
		$this->assertSame( 'subscribed', Subs::status( $previous ), 'Usa el contacto existente.' );
		$this->assertSame( 'student', (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_contacts WHERE id = %d", $previous ) ), 'No le cambia el estado del CRM.' );
		$this->assertSame( 'pending', Subs::status( Subs::ensure_contact( get_userdata( $none )->user_email, '', $none ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_queue" ), 'Migrar no envía correos.' );

		$contacts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts" );
		$second   = Subs::migrate();
		$this->assertSame( array( 0, 0, 2 ), array( $second['subscribed'], $second['pending'], $second['skipped_existing'] ), 'Segunda pasada: nada nuevo.' );
		$this->assertSame( $contacts, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts" ), 'Sin contactos duplicados.' );
	}

	// ── Segmentos ───────────────────────────────────────────────────────────

	public function test_segments_resolved_in_database(): void {
		global $wpdb;
		$contacts = $this->subscribers( 5 );
		$users    = array_map( static fn( $c ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}atora_contacts WHERE id = %d", $c ) ), $contacts );

		$wpdb->insert( $wpdb->prefix . 'atora_contact_tags', array( 'contact_id' => $contacts[0], 'tag_name' => 'vip' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_contact_tags', array( 'contact_id' => $contacts[1], 'tag_name' => 'vip' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_enrollments', array( 'user_id' => $users[1], 'course_id' => 77, 'status' => 'active' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_enrollments', array( 'user_id' => $users[2], 'course_id' => 77, 'status' => 'completed' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'user_id' => $users[3], 'institution_id' => 9, 'role' => 'student', 'status' => 'active' ) );
		update_user_meta( $users[4], '_clms_last_access_at', current_time( 'mysql' ) );
		update_user_meta( $users[0], '_clms_last_access_at', gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - 60 * DAY_IN_SECONDS ) );
		update_user_meta( $users[2], 'atora_country', 'CO' );

		$pick = static fn( array $rules ) => array_column( Subs::page( array( 'rules' => $rules ), 0, 100 ), 'contact_id' );
		$this->assertSame( array( $contacts[0], $contacts[1] ), $pick( array( array( 'type' => 'tag', 'value' => 'vip' ) ) ) );
		$this->assertSame( array( $contacts[1], $contacts[2] ), $pick( array( array( 'type' => 'course', 'value' => 77 ) ) ) );
		$this->assertSame( array( $contacts[1] ), $pick( array( array( 'type' => 'tag', 'value' => 'vip' ), array( 'type' => 'course', 'value' => 77 ) ) ), 'Las reglas se combinan.' );
		$this->assertSame( array( $contacts[2] ), $pick( array( array( 'type' => 'enrollment_status', 'value' => 'completed' ) ) ) );
		$this->assertSame( array( $contacts[3] ), $pick( array( array( 'type' => 'institution', 'value' => 9 ) ) ) );
		$this->assertSame( array( $contacts[4] ), $pick( array( array( 'type' => 'active_within_days', 'value' => 7 ) ) ) );
		$this->assertContains( $contacts[0], $pick( array( array( 'type' => 'inactive_for_days', 'value' => 30 ) ) ) );
		$this->assertNotContains( $contacts[4], $pick( array( array( 'type' => 'inactive_for_days', 'value' => 30 ) ) ) );
		$this->assertSame( array( $contacts[2] ), $pick( array( array( 'field' => 'country', 'operator' => 'equals', 'value' => 'CO' ) ) ), 'Las reglas anteriores (meta) siguen sirviendo.' );

		$wpdb->insert( $wpdb->prefix . 'atora_email_suppression', array( 'email' => get_userdata( $users[1] )->user_email, 'reason' => 'bounce' ) );
		$this->assertSame( array( $contacts[0] ), $pick( array( array( 'type' => 'tag', 'value' => 'vip' ) ) ), 'Lo suprimido no entra.' );
	}

	public function test_ten_thousand_contacts_paged_under_memory_limit(): void {
		global $wpdb;
		$list   = Subs::list_id();
		$values = array();
		for ( $i = 1; $i <= 10000; $i++ ) {
			$values[] = $wpdb->prepare( '(%s, %s, %s, %s)', "masivo{$i}@example.test", 'Masivo', 'test', 'lead' );
			if ( 0 === $i % 1000 ) {
				$wpdb->query( "INSERT INTO {$wpdb->prefix}atora_contacts (email, name, source, status) VALUES " . implode( ',', $values ) );
				$values = array();
			}
		}
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}atora_contact_list_pivot (contact_id, list_id, status) SELECT id, %d, 'subscribed' FROM {$wpdb->prefix}atora_contacts WHERE email LIKE 'masivo%%'", $list ) );

		$before = memory_get_usage();
		$start  = microtime( true );
		$seen   = 0;
		$peak   = 0;
		$cursor = 0;
		do {
			$page   = Subs::page( array(), $cursor, 500 );
			$seen  += count( $page );
			$cursor = $page ? (int) end( $page )['contact_id'] : $cursor;
			$peak   = max( $peak, memory_get_usage() - $before );
		} while ( count( $page ) === 500 );
		$elapsed = microtime( true ) - $start;

		$this->assertSame( 10000, $seen );
		$this->assertLessThan( 8 * 1024 * 1024, $peak, 'Recorrer 10.000 contactos no carga todo en memoria (límite fijado: 8 MB).' );
		fwrite( STDERR, sprintf( "\n[10.000 contactos] %.2f s, memoria extra máx. %.1f MB\n", $elapsed, $peak / 1048576 ) );
	}

	// ── Control y métricas ──────────────────────────────────────────────────

	public function test_ab_split_is_deterministic(): void {
		$contacts = $this->subscribers( 4 );
		$id       = $this->newsletter( array(
			'ab_enabled'  => 1,
			'ab_variants' => array( 'variant_a' => array( 'subject' => 'Asunto A' ), 'variant_b' => array( 'subject' => 'Asunto B' ) ),
		) );
		Newsletter::send( $id );

		global $wpdb;
		foreach ( $contacts as $contact ) {
			$subject = $wpdb->get_var( $wpdb->prepare( "SELECT subject FROM {$wpdb->prefix}atora_email_queue WHERE CAST( JSON_UNQUOTE( JSON_EXTRACT( metadata, '$.contact_id' ) ) AS UNSIGNED ) = %d", $contact ) );
			$this->assertSame( 0 === $contact % 2 ? 'Asunto A' : 'Asunto B', $subject );
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

		Newsletter::save( array( 'id' => $id, 'title' => 'Boletín de prueba' ) );
		$this->assertSame( 'paused', get_post_meta( $id, 'atora_nl_status', true ), 'Editar no reinicia un envío en curso.' );

		$this->assertSame( Newsletter::BATCH_SIZE * 2, Newsletter::resume( $id )['queued'] );
		$this->assertSame( 'cancelled', Newsletter::cancel( $id )['status'] );
		$this->assertSame( Newsletter::BATCH_SIZE * 2, Newsletter::process_send_batch( $id )['queued'], 'Cancelado: no se envía más.' );
	}

	public function test_metrics_per_send_with_links(): void {
		global $wpdb;
		$contacts = $this->subscribers( 3 );
		$id       = $this->newsletter();
		Newsletter::send( $id );
		$rows = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}atora_email_queue ORDER BY id" );
		$wpdb->query( "UPDATE {$wpdb->prefix}atora_email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP()" );
		$wpdb->update( $wpdb->prefix . 'atora_email_queue', array( 'opened_at' => current_time( 'mysql', true ), 'clicked_at' => current_time( 'mysql', true ) ), array( 'id' => $rows[0] ) );
		foreach ( array( array( $rows[0], 'delivered', '{}' ), array( $rows[1], 'delivered', '{}' ), array( $rows[0], 'clicked', '{"url":"https://example.test/a"}' ), array( $rows[0], 'clicked', '{"event-data":{"url":"https://example.test/a"}}' ), array( $rows[2], 'bounced', '{}' ) ) as $ev ) {
			$wpdb->insert( $wpdb->prefix . 'atora_email_events', array( 'queue_id' => $ev[0], 'event_type' => $ev[1], 'event_data' => $ev[2] ) );
		}
		Subs::unsubscribe( $contacts[1] );

		$m = Newsletter::metrics( $id );
		$this->assertSame( array( 3, 3, 2, 1, 1, 1, 1 ), array( $m['queued'], $m['sent'], $m['delivered'], $m['opened'], $m['clicked'], $m['bounced'], $m['unsubscribed'] ) );
		$this->assertSame( array( 'https://example.test/a' => 2 ), $m['links'] );
	}

	public function test_blog_post_notification_goes_through_batches_once(): void {
		$contacts = $this->subscribers( 2 );
		$post     = self::factory()->post->create_and_get( array( 'post_title' => 'Entrada nueva', 'post_excerpt' => 'Resumen' ) );
		$first    = Newsletter::send_auto( 'post:' . $post->ID, $post->post_title, array( array( 'type' => 'article', 'title' => 'Entrada nueva', 'content' => 'Resumen' ) ) );
		$again    = Newsletter::send_auto( 'post:' . $post->ID, $post->post_title, array() );
		$this->assertSame( $first, $again, 'El mismo origen no genera un segundo envío.' );
		$this->assertSame( array_fill_keys( $contacts, 1 ), $this->queued( $first ) );
	}

	public function test_admin_screen_renders_with_metrics_and_subscribers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->subscribers( 1 );
		Newsletter::send( $this->newsletter() );
		ob_start();
		include dirname( __DIR__, 2 ) . '/modules/newsletter/views/admin.php';
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Suscritos: 1', $html );
		$this->assertStringContainsString( 'Entregados 0', $html );
		$this->assertStringContainsString( 'Migrar suscriptores anteriores', $html );
	}
}
