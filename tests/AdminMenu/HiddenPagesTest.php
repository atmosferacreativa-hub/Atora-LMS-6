<?php
/**
 * Admin menu — páginas ocultas accesibles / enlaces de hubs.
 *
 * @package ATORA_LMS\Tests\AdminMenu
 */

declare( strict_types = 1 );

namespace ATORA\Tests\AdminMenu;

use PHPUnit\Framework\TestCase;

final class HiddenPagesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__atora_test_admin_menu_calls'] = array();
		// Los hubs usan render_simple_hub_page(), que requiere capacidad 'read'.
		$GLOBALS['__atora_test_user_caps'][1]['read'] = true;
		atora_test_reset_options();
		if ( class_exists( '\CLMS_Module_Registry' ) ) {
			\CLMS_Module_Registry::flush_cache();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['__atora_test_admin_menu_calls'] = array();
		atora_test_reset_options();
		if ( class_exists( '\CLMS_Module_Registry' ) ) {
			\CLMS_Module_Registry::flush_cache();
		}
		parent::tearDown();
	}

	/** @test */
	public function test_registry_state_is_stored_in_option_atora_active_modules(): void {
		$this->assertTrue( class_exists( '\CLMS_Module_Registry' ) );

		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertFalse( \CLMS_Module_Registry::is_active( 'analytics' ) );
		$this->assertFalse( \CLMS_Module_Registry::is_active( 'automation' ) );
	}

	/** @test */
	public function test_forms_and_popups_register_under_clms_dashboard_when_module_active(): void {
		// Activar analytics explícitamente.
		update_option( 'atora_active_modules', array_merge(
			array( 'lms', 'academic', 'gradebook', 'security' ),
			array( 'analytics' )
		) );
		\CLMS_Module_Registry::flush_cache();

		// Ejecutar el registrador real del módulo.
		$this->assertTrue( class_exists( '\ATORA\Analytics\Forms_Builder' ) );
		\ATORA\Analytics\Forms_Builder::register_admin_menu();
		$this->assertTrue( class_exists( '\ATORA\Analytics\Popups' ) );
		\ATORA\Analytics\Popups::register_admin_menu();

		$calls = (array) ( $GLOBALS['__atora_test_admin_menu_calls'] ?? array() );
		$parents = array();
		foreach ( $calls as $c ) {
			if ( 'add_submenu_page' !== ( $c['fn'] ?? '' ) ) { continue; }
			$args = (array) ( $c['args'] ?? array() );
			$slug = (string) ( $args[4] ?? '' );
			if ( 'atora-forms' === $slug || 'atora-popups' === $slug ) {
				$parents[ $slug ] = (string) ( $args[0] ?? '' );
			}
		}

		$this->assertSame( 'clms-dashboard', $parents['atora-forms'] ?? null );
		$this->assertSame( 'clms-dashboard', $parents['atora-popups'] ?? null );
	}

	/** @test */
	public function test_automations_and_webhooks_register_under_clms_dashboard_when_module_active(): void {
		update_option( 'atora_active_modules', array_merge(
			array( 'lms', 'academic', 'gradebook', 'security' ),
			array( 'automation' )
		) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( class_exists( '\ATORA\Automation\Automation_Engine' ) );
		\ATORA\Automation\Automation_Engine::register_admin_menu();
		$this->assertTrue( class_exists( '\ATORA\Automation\Outbound_Webhooks' ) );
		\ATORA\Automation\Outbound_Webhooks::register_admin_menu();

		$calls = (array) ( $GLOBALS['__atora_test_admin_menu_calls'] ?? array() );
		$parents = array();
		foreach ( $calls as $c ) {
			if ( 'add_submenu_page' !== ( $c['fn'] ?? '' ) ) { continue; }
			$args = (array) ( $c['args'] ?? array() );
			$slug = (string) ( $args[4] ?? '' );
			if ( 'atora-automations' === $slug || 'atora-webhooks' === $slug ) {
				$parents[ $slug ] = (string) ( $args[0] ?? '' );
			}
		}

		$this->assertSame( 'clms-dashboard', $parents['atora-automations'] ?? null );
		$this->assertSame( 'clms-dashboard', $parents['atora-webhooks'] ?? null );
	}

	/** @test */
	public function test_reports_hub_hides_forms_and_popups_links_when_analytics_inactive(): void {
		// Módulo inactivo, pero la clase existe (está en el código) — el link
		// igual debe desaparecer para no apuntar a una página inexistente.
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( class_exists( '\CLMS_Admin_Menu' ) );
		$menu = new \CLMS_Admin_Menu();

		ob_start();
		$menu->render_reports_hub_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'admin.php?page=atora-forms', $html );
		$this->assertStringNotContainsString( 'admin.php?page=atora-popups', $html );
	}

	/** @test */
	public function test_growth_hub_hides_automations_and_webhooks_links_when_automation_inactive(): void {
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( class_exists( '\CLMS_Admin_Menu' ) );
		$menu = new \CLMS_Admin_Menu();

		ob_start();
		$menu->render_growth_hub_page();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'admin.php?page=atora-automations', $html );
		$this->assertStringNotContainsString( 'admin.php?page=atora-webhooks', $html );
	}

	/** @test */
	public function test_legacy_cohorts_slug_redirects_to_cohort_post_type_screen(): void {
		$this->assertTrue( class_exists( '\CLMS_Legacy_Slug_Redirects' ) );

		$_GET['page'] = 'clms-cohorts';
		\CLMS_Legacy_Slug_Redirects::maybe_redirect();

		$this->assertSame( 'https://example.test/wp-admin/edit.php?post_type=lm_cohort', atora_test_last_redirect() );
	}
}
