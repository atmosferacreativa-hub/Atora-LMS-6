<?php
/**
 * Integración 6.38.0: pantalla del editor de flujos. Se renderiza; guarda por POST con nonce; no activa un flujo
 * inválido; simula sin ejecutar; la pestaña de recorridos y las plantillas se muestran; activar desde la lista
 * también valida.
 */

declare( strict_types = 1 );

use ATORA\Automation\Automation_Engine;
use ATORA\Automation\Automation_Flows as Flows;

final class AutomationEditorViewTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( ! class_exists( Flows::class ) ) {
			$dir = dirname( __DIR__, 2 ) . '/modules/automation/';
			foreach ( array( 'class-outbound-webhooks.php', 'class-automation-engine.php', 'class-automation-triggers.php', 'class-automation-flows.php' ) as $file ) {
				require_once $dir . $file;
			}
			Automation_Engine::init();
		}
		global $wpdb;
		foreach ( array( 'atora_automations', 'atora_automation_versions', 'atora_automation_runs', 'atora_automation_run_steps', 'atora_contact_tags' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		unset( $_SERVER['REQUEST_METHOD'], $_REQUEST['atora_flow_nonce'] );
		parent::tearDown();
	}

	private function render( string $tab, array $get = array(), array $post = array() ): string {
		$_GET = array( 'page' => 'atora-automations', 'tab' => $tab ) + $get;
		if ( $post ) {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = $post;
			$_REQUEST                  = $post;
		} else {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = array();
		}
		ob_start();
		include dirname( __DIR__, 2 ) . '/modules/automation/views/admin.php';
		$html = (string) ob_get_clean();
		$dump = getenv( 'ATORA_DUMP_EDITOR' );
		if ( $dump && 'editor' === $tab && ! $post ) {
			// Página autónoma para la prueba en navegador (scripts/flow-editor-browser-check.cjs).
			$root   = dirname( __DIR__, 2 );
			$before = implode( "\n", (array) wp_scripts()->get_data( 'atora-automation-flow', 'before' ) );
			file_put_contents( $dump, '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="file://' . $root . '/assets/css/atora-automation-flow.css">'
				. $html . '<script>' . $before . '</script><script src="file://' . $root . '/assets/js/atora-automation-flow.js"></script>' );
		}
		return $html;
	}

	private function post_flow( array $definition, bool $active, string $op = 'save', array $extra = array() ): array {
		return $extra + array(
			'atora_flow_nonce' => wp_create_nonce( 'atora_flow_editor' ),
			'atora_flow_op'    => $op,
			'name'             => 'Flujo de prueba',
			'description'      => '',
			'trigger_type'     => 'course_enrolled',
			'priority'         => '10',
			'active'           => $active ? '1' : '',
			'definition'       => wp_slash( wp_json_encode( $definition ) ),
			'conditions'       => '{}',
		);
	}

	public function test_editor_renders_with_data_for_the_canvas(): void {
		$id   = Flows::create_from_template( 'estudiante_riesgo' );
		$html = $this->render( 'editor', array( 'flow' => (string) $id ) );
		$this->assertStringContainsString( 'id="atora-flow-canvas"', $html );
		$this->assertStringContainsString( 'Estudiante en riesgo', $html );
		$this->assertStringContainsString( '&quot;teacher_task&quot;', $html, 'La definición llega al campo JSON.' );
		$this->assertStringContainsString( 'versión 1', $html );
		$this->assertTrue( wp_script_is( 'atora-automation-flow', 'enqueued' ) );
	}

	public function test_save_by_post_and_invalid_is_not_activated(): void {
		global $wpdb;
		$good = array( 'start' => 'a', 'steps' => array( array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'add_tag', 'tag' => 'hola' ), 'next' => null ) ) );
		$html = $this->render( 'editor', array(), $this->post_flow( $good, true ) );
		$this->assertStringContainsString( 'Flujo guardado', $html );
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT active FROM {$wpdb->prefix}atora_automations ORDER BY id DESC LIMIT 1" ) );

		$bad  = array( 'start' => 'a', 'steps' => array( array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'send_email', 'template' => 'no_existe' ), 'next' => null ) ) );
		$html = $this->render( 'editor', array(), $this->post_flow( $bad, true ) );
		$this->assertStringContainsString( 'No se activó', $html );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}atora_automations" ), 'El inválido no se guardó activo.' );
	}

	public function test_simulation_from_the_screen(): void {
		$user = self::factory()->user->create();
		$def  = array( 'start' => 'c', 'steps' => array(
			array( 'id' => 'c', 'type' => 'condition', 'rules' => array( 'operator' => 'AND', 'rules' => array( array( 'field' => 'grade', 'operator' => 'less_than', 'value' => '60' ) ) ), 'yes' => 'a', 'no' => null ),
			array( 'id' => 'a', 'type' => 'action', 'action' => array( 'type' => 'add_tag', 'tag' => 'x' ), 'next' => null ),
		) );
		$html = $this->render( 'editor', array(), $this->post_flow( $def, false, 'simulate', array( 'test_contact' => (string) $user, 'test_context' => '{"grade": 40}' ) ) );
		$this->assertMatchesRegularExpression( '#<code>c</code> — condition: sí.*<code>a</code> — action: add_tag#s', $html );
	}

	public function test_runs_and_templates_tabs_and_list_toggle_validates(): void {
		global $wpdb;
		$html = $this->render( 'templates' );
		$this->assertStringContainsString( 'Recuperación de inactivos', $html );
		$this->assertStringContainsString( 'Recorridos', $this->render( 'runs' ) );

		$wpdb->insert( $wpdb->prefix . 'atora_automations', array( 'name' => 'Roto', 'description' => '', 'trigger_type' => 'course_enrolled', 'trigger_config' => '{}', 'conditions' => '{}', 'actions' => wp_json_encode( array( array( 'type' => 'send_email', 'template' => 'no_existe' ) ) ), 'active' => 0 ) );
		$id   = (int) $wpdb->insert_id;
		$html = $this->render( 'workflows', array(), array(
			'atora_automation_nonce'  => wp_create_nonce( 'atora_automation_admin_action' ),
			'atora_automation_action' => 'toggle_workflow',
			'id'                      => (string) $id,
			'active'                  => '1',
		) );
		$this->assertStringContainsString( 'No se activó', $html );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$wpdb->prefix}atora_automations WHERE id = %d", $id ) ) );
	}
}
