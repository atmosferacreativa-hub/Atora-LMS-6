<?php
/**
 * V5_Installer — creación condicional de tablas por módulo (P3, sprint 6.12.0).
 *
 * dbDelta() y las consultas SHOW TABLES no son practicables en este
 * entorno de test (sin WordPress/MySQL real, ver tests/6.5.10/InstallerSchemaTest.php),
 * así que se verifica por reflexión la parte que sí es pura PHP:
 * module_wants_tables() / table_owner_module() y su consistencia con
 * CLMS_Module_Registry::get_modules()['tables'].
 *
 * @package ATORA_LMS\Tests\Modularity
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Modularity;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class V5InstallerModuleTablesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_options();
		\CLMS_Module_Registry::flush_cache();
	}

	protected function tearDown(): void {
		atora_test_reset_options();
		\CLMS_Module_Registry::flush_cache();
		parent::tearDown();
	}

	private function table_owner_module( string $bare_table ): ?string {
		$ref = new ReflectionMethod( '\ATORA\V5_Installer', 'table_owner_module' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $bare_table );
	}

	private function module_wants_tables( string $slug ): bool {
		$ref = new ReflectionMethod( '\ATORA\V5_Installer', 'module_wants_tables' );
		$ref->setAccessible( true );
		return $ref->invoke( null, $slug );
	}

	/** @test */
	public function test_known_tables_map_to_expected_modules(): void {
		$this->assertSame( 'crm', $this->table_owner_module( 'atora_contacts' ) );
		$this->assertSame( 'email-engine', $this->table_owner_module( 'atora_email_queue' ) );
		$this->assertSame( 'calendar', $this->table_owner_module( 'atora_calendar_sync' ) );
		$this->assertSame( 'commerce', $this->table_owner_module( 'atora_abandoned_carts' ) );
		$this->assertSame( 'mcp', $this->table_owner_module( 'atora_api_keys' ) );
		$this->assertSame( 'gamification', $this->table_owner_module( 'clms_badges' ) );
		$this->assertSame( 'webhooks', $this->table_owner_module( 'atora_webhooks' ) );
	}

	/** @test */
	public function test_core_and_unmapped_tables_return_null(): void {
		// Tablas core (lms/academic/security) nunca pasan por el gate de
		// módulo — deben seguir creándose siempre.
		$this->assertNull( $this->table_owner_module( 'atora_courses' ) );
		$this->assertNull( $this->table_owner_module( 'atora_2fa_tokens' ) );
		$this->assertNull( $this->table_owner_module( 'atora_followup_plans' ) );
		$this->assertNull( $this->table_owner_module( 'nonexistent_table' ) );
	}

	/** @test */
	public function test_module_wants_tables_follows_registry_active_state(): void {
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertFalse( $this->module_wants_tables( 'crm' ) );
		$this->assertTrue( $this->module_wants_tables( 'lms' ) );

		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security', 'crm' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( $this->module_wants_tables( 'crm' ) );
	}

	/**
	 * Consistencia: toda tabla que table_owner_module() asigna a un
	 * módulo debe aparecer también en el 'tables' declarado por
	 * CLMS_Module_Registry para ese mismo módulo — de lo contrario
	 * ensure_active_module_tables() y el registry divergirían sobre qué
	 * módulo es dueño de qué tabla.
	 *
	 * @test
	 */
	public function test_owner_map_matches_registry_tables_declaration(): void {
		$modules = \CLMS_Module_Registry::get_modules();

		foreach ( array(
			'atora_contacts'          => 'crm',
			'atora_email_queue'       => 'email-engine',
			'atora_newsletters'       => 'newsletter',
			'atora_automations'       => 'automation',
			'atora_message_queue'     => 'messaging',
			'atora_calendar_events'   => 'calendar',
			'atora_abandoned_carts'   => 'commerce',
			'atora_affiliates'        => 'affiliates',
			'atora_user_engagement'   => 'analytics',
			'atora_api_keys'          => 'mcp',
			'clms_badges'             => 'gamification',
			'atora_webhooks'          => 'webhooks',
		) as $table => $expected_slug ) {
			$this->assertSame( $expected_slug, $this->table_owner_module( $table ) );
			$this->assertContains(
				$table,
				$modules[ $expected_slug ]['tables'],
				"'{$table}' debe estar en CLMS_Module_Registry para '{$expected_slug}'"
			);
		}
	}

	/** @test */
	public function test_docente_profile_wants_fewer_table_owning_modules_than_academia(): void {
		\CLMS_Install_Profiles::apply( 'docente' );
		$docente_wants = array_filter(
			array( 'crm', 'email-engine', 'newsletter', 'automation', 'messaging', 'calendar', 'commerce', 'affiliates', 'analytics', 'mcp', 'gamification', 'webhooks' ),
			fn( $slug ) => $this->module_wants_tables( $slug )
		);

		\CLMS_Install_Profiles::apply( 'academia' );
		$academia_wants = array_filter(
			array( 'crm', 'email-engine', 'newsletter', 'automation', 'messaging', 'calendar', 'commerce', 'affiliates', 'analytics', 'mcp', 'gamification', 'webhooks' ),
			fn( $slug ) => $this->module_wants_tables( $slug )
		);

		$this->assertLessThan( count( $academia_wants ), count( $docente_wants ) );
		// docente solo trae 'calendar' de este grupo (los demás son de academic/institucion/creadores).
		$this->assertSame( array( 'calendar' ), array_values( $docente_wants ) );
	}
}
