<?php
/**
 * CLMS_Module_Registry — PT-2 (sprint 6.3.0).
 *
 * @package ATORA_LMS\Tests\Modularity
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Modularity;

use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase {

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

	/** @test */
	public function test_all_19_slugs_defined(): void {
		$this->assertCount( 19, \CLMS_Module_Registry::get_modules() );
	}

	/** @test */
	public function test_core_slugs_are_lms_academic_gradebook_security(): void {
		$modules = \CLMS_Module_Registry::get_modules();
		$core    = array_keys( array_filter( $modules, fn( $m ) => ! empty( $m['core'] ) ) );
		sort( $core );
		$this->assertSame( array( 'academic', 'gradebook', 'lms', 'security' ), $core );
	}

	/** @test */
	public function test_default_without_option_is_all_active(): void {
		// Sin update_option() — simula instalación existente sin configurar.
		foreach ( array_keys( \CLMS_Module_Registry::get_modules() ) as $slug ) {
			$this->assertTrue( \CLMS_Module_Registry::is_active( $slug ), "{$slug} debería estar activo por defecto" );
		}
	}

	/** @test */
	public function test_deactivating_a_module_makes_is_active_false(): void {
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security', 'crm' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( \CLMS_Module_Registry::is_active( 'crm' ) );
		$this->assertFalse( \CLMS_Module_Registry::is_active( 'affiliates' ) );
	}

	/** @test */
	public function test_core_module_stays_active_even_if_excluded_from_option(): void {
		// Option corrupta/manipulada que "olvida" un core — no debe importar.
		update_option( 'atora_active_modules', array( 'crm' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( \CLMS_Module_Registry::is_active( 'lms' ) );
		$this->assertTrue( \CLMS_Module_Registry::is_active( 'security' ) );
	}

	/** @test */
	public function test_empty_array_option_means_only_core_active(): void {
		// Distingue "nunca configurado" (false) de "todo desactivado" ([]).
		update_option( 'atora_active_modules', array() );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( \CLMS_Module_Registry::is_active( 'lms' ), 'core sigue activo' );
		$this->assertFalse( \CLMS_Module_Registry::is_active( 'crm' ) );
		$this->assertFalse( \CLMS_Module_Registry::is_active( 'affiliates' ) );
	}

	/** @test */
	public function test_unknown_slug_fails_open(): void {
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security' ) );
		\CLMS_Module_Registry::flush_cache();

		$this->assertTrue( \CLMS_Module_Registry::is_active( 'some-future-module' ) );
	}

	/** @test */
	public function test_get_active_dependents_finds_active_dependent(): void {
		// affiliates requiere commerce; ambos activos por defecto.
		$dependents = \CLMS_Module_Registry::get_active_dependents( 'commerce' );
		$this->assertContains( 'affiliates', $dependents );
	}

	/** @test */
	public function test_get_active_dependents_excludes_inactive_dependent(): void {
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security', 'commerce' ) );
		\CLMS_Module_Registry::flush_cache();

		// affiliates no está en la lista activa => no debe contar como dependiente activo.
		$dependents = \CLMS_Module_Registry::get_active_dependents( 'commerce' );
		$this->assertNotContains( 'affiliates', $dependents );
	}

	/** @test */
	public function test_resolve_activation_closure_includes_transitive_requires(): void {
		// affiliates -> requires commerce -> requires lms.
		$closure = \CLMS_Module_Registry::resolve_activation_closure( 'affiliates' );
		sort( $closure );
		$this->assertSame( array( 'affiliates', 'commerce', 'lms' ), $closure );
	}

	/** @test */
	public function test_flush_cache_picks_up_new_option_value_same_request(): void {
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security' ) );
		\CLMS_Module_Registry::flush_cache();
		$this->assertFalse( \CLMS_Module_Registry::is_active( 'crm' ) );

		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'security', 'crm' ) );
		\CLMS_Module_Registry::flush_cache();
		$this->assertTrue( \CLMS_Module_Registry::is_active( 'crm' ) );
	}
}
