<?php
/**
 * Integración 6.38.0: motor de flujos de automatización.
 * Espera y reanudación por cron; ramas; bucle bloqueado; no reingreso; fallo a la mitad y reanudación sin repetir
 * acciones; versiones con contactos en curso; límite diario; objetivo; validación; disparadores unificados;
 * migración; plantillas.
 */

declare( strict_types = 1 );

use ATORA\Automation\Automation_Engine;
use ATORA\Automation\Automation_Flows as Flows;
use ATORA\Automation\Automation_Triggers as Triggers;

final class AutomationFlowsTest extends WP_UnitTestCase {

	private int $http_status = 200;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( ! class_exists( Flows::class ) ) {
			// El módulo puede estar apagado en la configuración de prueba: se carga y se inicia.
			$dir = dirname( __DIR__, 2 ) . '/modules/automation/';
			foreach ( array( 'class-outbound-webhooks.php', 'class-automation-engine.php', 'class-automation-triggers.php', 'class-automation-flows.php' ) as $file ) {
				require_once $dir . $file;
			}
			Automation_Engine::init();
		}
		global $wpdb;
		foreach ( array( 'atora_automations', 'atora_automation_versions', 'atora_automation_runs', 'atora_automation_run_steps', 'atora_contact_tags', 'atora_contacts', 'atora_email_queue', 'atora_enrollments' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		delete_option( Flows::OPTION_LIMIT );
		Triggers::reset_request_state();
		$this->http_status = 200;
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		parent::tearDown();
	}

	public function fake_http( $pre, $args, $url ) {
		return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => $this->http_status, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}

	// ── Ayudas ──────────────────────────────────────────────────────────────

	private function student(): int {
		$user = self::factory()->user->create();
		clms_core( 'CLMS_Contacts_Core_Service' )->upsert_contact( array( 'user_id' => $user, 'email' => get_userdata( $user )->user_email, 'source' => 'test' ) );
		return $user;
	}

	private function flow( string $trigger, array $steps, array $extra = array(), array $conditions = array() ): int {
		$id = Flows::save( array(
			'name'         => 'Flujo ' . wp_rand(),
			'trigger_type' => $trigger,
			'conditions'   => $conditions,
			'definition'   => $extra + array( 'start' => $steps[0]['id'], 'steps' => $steps ),
			'active'       => 1,
		) );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	private function tag( string $id, string $tag, ?string $next = null, string $type = 'add_tag' ): array {
		return array( 'id' => $id, 'type' => 'action', 'action' => array( 'type' => $type, 'tag' => $tag ), 'next' => $next );
	}

	/** @return string[] */
	private function tags( int $user ): array {
		global $wpdb;
		return array_values( (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT t.tag_name FROM {$wpdb->prefix}atora_contact_tags t JOIN {$wpdb->prefix}atora_contacts c ON c.id = t.contact_id WHERE c.user_id = %d ORDER BY t.tag_name",
			$user
		) ) );
	}

	private function flow_run( int $flow, int $user ): object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_automation_runs WHERE automation_id = %d AND user_id = %d ORDER BY id DESC LIMIT 1", $flow, $user ) );
	}

	private function wake_now( int $run_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'atora_automation_runs', array( 'wake_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $run_id ) );
	}

	private function enroll( int $user, int $course ): void {
		Triggers::fire( 'course_enrolled', $user, 0, array( 'course_id' => $course ), 'course:' . $course );
	}

	// ── Motor ───────────────────────────────────────────────────────────────

	public function test_wait_and_resume_by_cron(): void {
		$user = $this->student();
		$flow = $this->flow( 'course_enrolled', array(
			$this->tag( 'a', 'primero', 'w' ),
			array( 'id' => 'w', 'type' => 'wait', 'minutes' => 60, 'next' => 'b' ),
			$this->tag( 'b', 'segundo' ),
		) );
		$this->enroll( $user, 10 );
		Flows::process();

		$run = $this->flow_run( $flow, $user );
		$this->assertSame( array( 'primero' ), $this->tags( $user ) );
		$this->assertSame( 'waiting', $run->status );
		$this->assertSame( 'b', $run->current_step );
		$this->assertGreaterThan( time() + 3000, strtotime( $run->wake_at . ' UTC' ), 'Espera una hora.' );

		Flows::process();
		$this->assertSame( array( 'primero' ), $this->tags( $user ), 'Antes de la hora no avanza.' );

		$this->wake_now( (int) $run->id );
		Flows::process();
		$this->assertSame( array( 'primero', 'segundo' ), $this->tags( $user ) );
		$this->assertSame( 'completed', $this->flow_run( $flow, $user )->status );
	}

	public function test_condition_branches(): void {
		$low  = $this->student();
		$high = $this->student();
		$this->flow( 'grade_published', array(
			array( 'id' => 'c', 'type' => 'condition', 'rules' => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'grade', 'operator' => 'less_than', 'value' => '60' ) ) ), 'yes' => 'baja', 'no' => 'buena' ),
			$this->tag( 'baja', 'nota-baja' ),
			$this->tag( 'buena', 'nota-buena' ),
		) );
		do_action( 'clms_grade_published', array( 'student_id' => $low, 'submission_id' => 1, 'grade' => 45 ) );
		do_action( 'clms_grade_published', array( 'student_id' => $high, 'submission_id' => 2, 'grade' => 90 ) );
		Flows::process();
		$this->assertSame( array( 'nota-baja' ), $this->tags( $low ) );
		$this->assertSame( array( 'nota-buena' ), $this->tags( $high ) );
	}

	public function test_loop_between_two_flows_is_blocked(): void {
		$user = $this->student();
		$ping = $this->flow( 'tag_added', array( $this->tag( 'q', 'pong', 'a', 'remove_tag' ), $this->tag( 'a', 'pong' ) ), array( 'allow_reentry' => true ), array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'tag', 'operator' => 'equals', 'value' => 'ping' ) ) ) );
		$pong = $this->flow( 'tag_added', array( $this->tag( 'q', 'ping', 'a', 'remove_tag' ), $this->tag( 'a', 'ping' ) ), array( 'allow_reentry' => true ), array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'tag', 'operator' => 'equals', 'value' => 'pong' ) ) ) );

		clms_core( 'CLMS_Contacts_Core_Service' )->add_tag( $user, 'ping' );
		for ( $i = 0; $i < 6; $i++ ) {
			Triggers::reset_request_state();
			Flows::process();
		}
		global $wpdb;
		$blocked = $wpdb->get_row( $wpdb->prepare( "SELECT automation_id, chain FROM {$wpdb->prefix}atora_automation_runs WHERE status = 'blocked' AND exit_reason = 'loop' AND user_id = %d", $user ) );
		$this->assertNotNull( $blocked, 'El bucle se detecta.' );
		$this->assertSame( $ping, (int) $blocked->automation_id, 'Se bloquea cuando el primero vuelve a dispararse.' );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_automation_runs WHERE status = 'completed' AND user_id = %d", $user ) ), 'Cada flujo corre una vez; no hay ida y vuelta infinita.' );
		$this->assertContains( (string) $pong, explode( ',', (string) $blocked->chain ) );
	}

	public function test_no_reentry_unless_allowed(): void {
		$user = $this->student();
		$once = $this->flow( 'course_enrolled', array( $this->tag( 'a', 'una-vez' ) ) );
		$this->enroll( $user, 10 );
		Flows::process();
		Triggers::reset_request_state();
		$this->enroll( $user, 11 );
		Flows::process();
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_automation_runs WHERE automation_id = %d", $once ) ), 'No entra dos veces.' );

		$again = $this->flow( 'course_enrolled', array( $this->tag( 'a', 'otra' ) ), array( 'allow_reentry' => true ) );
		Triggers::reset_request_state();
		$this->enroll( $user, 12 );
		Flows::process();
		Triggers::reset_request_state();
		$this->enroll( $user, 13 );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_automation_runs WHERE automation_id = %d", $again ) ), 'Con reingreso permitido, vuelve a entrar al terminar.' );
	}

	public function test_failure_midway_resumes_without_repeating_actions(): void {
		$user  = $this->student();
		$added = 0;
		$count = static function ( $u, $tag ) use ( &$added ) {
			if ( 'antes' === $tag ) {
				$added++;
			}
		};
		add_action( 'atora/crm/tag_added', $count, 5, 2 );
		$flow = $this->flow( 'course_enrolled', array(
			$this->tag( 'a', 'antes', 'hook' ),
			array( 'id' => 'hook', 'type' => 'action', 'action' => array( 'type' => 'webhook', 'url' => 'https://example.test/hook', 'method' => 'POST', 'payload' => '{}' ), 'next' => 'b' ),
			$this->tag( 'b', 'despues' ),
		) );

		$this->http_status = 500;
		$this->enroll( $user, 10 );
		Flows::process();
		$run = $this->flow_run( $flow, $user );
		$this->assertSame( 'waiting', $run->status );
		$this->assertSame( 'hook', $run->current_step );
		$this->assertSame( 1, (int) $run->attempts );

		// Corte del proceso a mitad: el recorrido quedó en "running".
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'atora_automation_runs', array( 'status' => 'running', 'locked_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ), array( 'id' => $run->id ) );
		$this->wake_now( (int) $run->id );
		$this->http_status = 200;
		Flows::process();

		$this->assertSame( 'completed', $this->flow_run( $flow, $user )->status );
		$this->assertSame( array( 'antes', 'despues' ), $this->tags( $user ) );
		$this->assertSame( 1, $added, 'La acción ya hecha no se repite al reanudar.' );
		remove_action( 'atora/crm/tag_added', $count, 5 );

		// Tres fallos seguidos: queda fallido y se puede reintentar desde el mismo paso.
		$user2 = $this->student();
		$this->http_status = 500;
		Triggers::reset_request_state();
		$this->enroll( $user2, 10 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->wake_now( (int) $this->flow_run( $flow, $user2 )->id );
			Flows::process();
		}
		$failed = $this->flow_run( $flow, $user2 );
		$this->assertSame( 'failed', $failed->status );
		$this->http_status = 200;
		$this->assertTrue( Flows::retry( (int) $failed->id ) );
		Flows::process();
		$this->assertSame( 'completed', $this->flow_run( $flow, $user2 )->status );
	}

	public function test_contacts_in_progress_keep_their_version(): void {
		$early = $this->student();
		$late  = $this->student();
		$flow  = $this->flow( 'course_enrolled', array(
			$this->tag( 'a', 'v1-inicio', 'w' ),
			array( 'id' => 'w', 'type' => 'wait', 'minutes' => 60, 'next' => 'b' ),
			$this->tag( 'b', 'v1-final' ),
		) );
		$this->enroll( $early, 10 );
		Flows::process();

		Flows::save( array( 'id' => $flow, 'name' => 'v2', 'trigger_type' => 'course_enrolled', 'definition' => array( 'start' => 'a', 'steps' => array( $this->tag( 'a', 'v2-unico' ) ) ), 'active' => 1 ) );
		$this->enroll( $late, 10 );
		$this->wake_now( (int) $this->flow_run( $flow, $early )->id );
		Flows::process();

		$this->assertSame( array( 'v1-final', 'v1-inicio' ), $this->tags( $early ), 'Quien estaba dentro termina con la versión con la que entró.' );
		$this->assertSame( array( 'v2-unico' ), $this->tags( $late ) );
		$this->assertSame( array( 1, 2 ), array( (int) $this->flow_run( $flow, $early )->version, (int) $this->flow_run( $flow, $late )->version ) );
	}

	public function test_daily_action_limit_postpones(): void {
		update_option( Flows::OPTION_LIMIT, 2 );
		$user = $this->student();
		$flow = $this->flow( 'course_enrolled', array( $this->tag( 'a', 't1', 'b' ), $this->tag( 'b', 't2', 'c' ), $this->tag( 'c', 't3' ) ) );
		$this->enroll( $user, 10 );
		Flows::process();
		$run = $this->flow_run( $flow, $user );
		$this->assertSame( array( 't1', 't2' ), $this->tags( $user ) );
		$this->assertSame( array( 'waiting', 'c', 'daily_limit' ), array( $run->status, $run->current_step, $run->exit_reason ) );
		$this->assertGreaterThan( time() + 3600, strtotime( $run->wake_at . ' UTC' ), 'Sigue mañana, no se pierde.' );
	}

	public function test_goal_exits_the_flow(): void {
		$user = $this->student();
		$flow = $this->flow( 'course_enrolled', array(
			array( 'id' => 'w', 'type' => 'wait', 'minutes' => 60, 'next' => 'a' ),
			$this->tag( 'a', 'recordatorio' ),
		), array( 'goal' => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'has_tag', 'operator' => 'equals', 'value' => 'volvio' ) ) ) ) );
		$this->enroll( $user, 10 );
		Flows::process();
		clms_core( 'CLMS_Contacts_Core_Service' )->add_tag( $user, 'volvio' );
		$this->wake_now( (int) $this->flow_run( $flow, $user )->id );
		Flows::process();
		$run = $this->flow_run( $flow, $user );
		$this->assertSame( array( 'exited', 'goal' ), array( $run->status, $run->exit_reason ) );
		$this->assertNotContains( 'recordatorio', $this->tags( $user ) );
	}

	public function test_validation_blocks_activation(): void {
		$cycle = Flows::validate( array( 'start' => 'a', 'steps' => array( $this->tag( 'a', 'x', 'b' ), array( 'id' => 'b', 'type' => 'wait', 'minutes' => 5, 'next' => 'a' ) ) ) );
		$this->assertNotEmpty( $cycle );
		$this->assertNotEmpty( Flows::validate( array( 'start' => 'a', 'steps' => array( array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'no_existe' ) ) ) ) ) );
		$this->assertNotEmpty( Flows::validate( array( 'start' => 'a', 'steps' => array( array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'borrar_todo' ) ) ) ) ) );
		$this->assertNotEmpty( Flows::validate( array( 'start' => 'a', 'steps' => array( $this->tag( 'a', 'x', 'fantasma' ) ) ) ) );
		$this->assertSame( array(), Flows::validate( array( 'start' => 'a', 'steps' => array( $this->tag( 'a', 'x' ) ) ) ) );

		$result = Flows::save( array( 'name' => 'Malo', 'trigger_type' => 'course_enrolled', 'definition' => array( 'start' => 'a', 'steps' => array( $this->tag( 'a', 'x', 'b' ), array( 'id' => 'b', 'type' => 'wait', 'minutes' => 5, 'next' => 'a' ) ) ), 'active' => 1 ) );
		$this->assertWPError( $result, 'Un flujo inválido no se activa.' );
		$draft = Flows::save( array( 'name' => 'Borrador', 'trigger_type' => 'course_enrolled', 'definition' => array( 'start' => 'a', 'steps' => array( $this->tag( 'a', 'x', 'b' ), array( 'id' => 'b', 'type' => 'wait', 'minutes' => 5, 'next' => 'a' ) ) ), 'active' => 0 ) );
		$this->assertIsInt( $draft, 'Como borrador sí se guarda.' );
	}

	public function test_simulation_does_not_execute(): void {
		$user = $this->student();
		$def  = array( 'start' => 'c', 'steps' => array(
			array( 'id' => 'c', 'type' => 'condition', 'rules' => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'grade', 'operator' => 'less_than', 'value' => '60' ) ) ), 'yes' => 'a', 'no' => null ),
			$this->tag( 'a', 'simulada' ),
		) );
		$path = Flows::simulate( $def, $user, 0, array( 'grade' => 40 ) );
		$this->assertSame( array( 'c', 'a' ), array_column( $path, 'step' ) );
		$this->assertSame( array(), $this->tags( $user ), 'Probar no ejecuta acciones.' );
	}

	// ── Disparadores ────────────────────────────────────────────────────────

	public function test_enrollment_from_both_lms_fires_once_and_real_enrollment_triggers(): void {
		$user   = $this->student();
		$course = self::factory()->post->create( array( 'post_type' => 'lm_course' ) );
		$flow   = $this->flow( 'course_enrolled', array( $this->tag( 'a', 'matriculado' ) ), array( 'allow_reentry' => true ) );

		do_action( 'clms_user_enrolled_in_course', $user, $course );
		do_action( 'atora/lms/enrolled', $user, $course, 99 );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_automation_runs WHERE automation_id = %d", $flow ) ), 'Dos fuentes del mismo hecho: un solo disparo.' );

		$other = $this->student();
		Triggers::reset_request_state();
		CLMS_Helper::enroll_user_in_course( $other, $course );
		$this->assertNotNull( $this->flow_run( $flow, $other ), 'Una matrícula real dispara el flujo (antes ningún código emitía el hook que escuchaba).' );
	}

	public function test_contact_trigger_never_targets_the_admin(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_contacts', array( 'email' => 'lead@example.test', 'name' => 'Lead', 'source' => 'test', 'status' => 'lead' ) );
		$contact = (int) $wpdb->insert_id;
		$flow    = $this->flow( 'contact_created', array( array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'welcome_account' ), 'next' => null ) ) );
		do_action( 'atora/crm/contact_created', $contact, 0 );
		Flows::process();
		$run = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, contact_id, status FROM {$wpdb->prefix}atora_automation_runs WHERE automation_id = %d", $flow ) );
		$this->assertSame( array( 0, $contact, 'completed' ), array( (int) $run->user_id, (int) $run->contact_id, $run->status ) );
		$this->assertSame( 'lead@example.test', (string) $wpdb->get_var( "SELECT recipient_email FROM {$wpdb->prefix}atora_email_queue ORDER BY id DESC LIMIT 1" ), 'El correo va al contacto, no al administrador.' );
	}

	public function test_inbox_action_really_notifies(): void {
		$user = $this->student();
		$this->flow( 'course_enrolled', array( array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'inbox_message', 'title' => 'Hola', 'message' => 'Bienvenida' ), 'next' => null ) ) );
		$this->enroll( $user, 10 );
		Flows::process();
		$notices = clms_core( 'CLMS_Notifications' )->get_notifications( $user );
		$this->assertNotEmpty( array_filter( (array) $notices, static fn( $n ) => false !== strpos( wp_json_encode( $n ), 'Bienvenida' ) ), 'El aviso llega al buzón (antes se disparaba un hook sin oyentes).' );
	}

	// ── Migración y plantillas ──────────────────────────────────────────────

	public function test_migration_of_retired_triggers_and_templates(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'atora_automations';
		$wpdb->insert( $table, array( 'name' => 'Viejo', 'description' => '', 'trigger_type' => 'grade_below_threshold', 'trigger_config' => '{}', 'conditions' => '{}', 'actions' => wp_json_encode( array( array( 'type' => 'send_email', 'template' => 'cart_recovery' ) ) ), 'active' => 1 ) );
		$old = (int) $wpdb->insert_id;
		$wpdb->insert( $table, array( 'name' => 'V2', 'description' => '', 'trigger_type' => 'enrollment_v2', 'trigger_config' => '{}', 'conditions' => '{}', 'actions' => '[]', 'active' => 1 ) );
		$v2 = (int) $wpdb->insert_id;

		$report = Automation_Engine::migrate_6380();
		$this->assertSame( 1, $report['triggers']['grade_below_threshold'] );
		$this->assertSame( 1, $report['templates'] );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT trigger_type, conditions, actions FROM {$table} WHERE id = %d", $old ) );
		$this->assertSame( 'grade_published', $row->trigger_type );
		$this->assertStringContainsString( 'less_than', (string) $row->conditions, 'La nota baja pasa a ser condición.' );
		$this->assertStringContainsString( 'cart_abandonment', (string) $row->actions );
		$this->assertSame( 'course_enrolled', (string) $wpdb->get_var( $wpdb->prepare( "SELECT trigger_type FROM {$table} WHERE id = %d", $v2 ) ) );

		$again = Automation_Engine::migrate_6380();
		$this->assertSame( array(), $again['triggers'], 'Segunda pasada: nada que migrar.' );
		$this->assertSame( 0, $again['templates'] );
	}

	public function test_flow_templates_are_valid_and_start_inactive(): void {
		foreach ( Flows::templates() as $key => $template ) {
			$this->assertSame( array(), Flows::validate( array( 'version' => 2 ) + $template['definition'] ), $key );
			$this->assertArrayHasKey( $template['trigger_type'], Triggers::catalog(), $key );
			$id = Flows::create_from_template( $key );
			$this->assertIsInt( $id );
			global $wpdb;
			$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}atora_automations WHERE id = %d", $id ) ) );
		}
	}

	public function test_legacy_action_list_runs_as_linear_flow(): void {
		global $wpdb;
		$user = $this->student();
		$wpdb->insert( $wpdb->prefix . 'atora_automations', array(
			'name' => 'Anterior', 'description' => '', 'trigger_type' => 'course_enrolled', 'trigger_config' => '{}', 'conditions' => '{}', 'active' => 1,
			'actions' => wp_json_encode( array( array( 'type' => 'add_tag', 'tag' => 'ya', 'delay_minutes' => 0 ), array( 'type' => 'add_tag', 'tag' => 'luego', 'delay_minutes' => 30 ) ) ),
		) );
		$flow = (int) $wpdb->insert_id;
		$this->enroll( $user, 10 );
		Flows::process();
		$this->assertSame( array( 'ya' ), $this->tags( $user ) );
		$this->wake_now( (int) $this->flow_run( $flow, $user )->id );
		Flows::process();
		$this->assertSame( array( 'luego', 'ya' ), $this->tags( $user ) );
	}
}
