<?php
/**
 * CLMS_Install_Profiles — PT-3 (sprint 6.3.0).
 *
 * @package ATORA_LMS\Tests\Modularity
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Modularity;

use PHPUnit\Framework\TestCase;

class InstallProfilesTest extends TestCase {

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
	public function test_three_profiles_defined(): void {
		$profiles = \CLMS_Install_Profiles::get_profiles();
		$this->assertSame( array( 'academia', 'institucional', 'corporativo' ), array_keys( $profiles ) );
	}

	/** @test */
	public function test_academia_includes_all_19_modules(): void {
		$modules = \CLMS_Install_Profiles::get_profile_modules( 'academia' );
		$this->assertCount( 19, $modules );
	}

	/** @test */
	public function test_institucional_excludes_commercial_modules(): void {
		$modules = \CLMS_Install_Profiles::get_profile_modules( 'institucional' );
		foreach ( array( 'crm', 'commerce', 'affiliates', 'newsletter', 'live-streaming' ) as $excluded ) {
			$this->assertNotContains( $excluded, $modules, "institucional no debe incluir {$excluded}" );
		}
	}

	/** @test */
	public function test_institucional_includes_academic_core(): void {
		$modules = \CLMS_Install_Profiles::get_profile_modules( 'institucional' );
		foreach ( array( 'lms', 'academic', 'gradebook', 'certificates', 'security', 'messaging', 'calendar', 'analytics', 'ai' ) as $expected ) {
			$this->assertContains( $expected, $modules );
		}
	}

	/** @test */
	public function test_corporativo_adds_crm_automation_email_engine_to_institucional(): void {
		$institucional = \CLMS_Install_Profiles::get_profile_modules( 'institucional' );
		$corporativo   = \CLMS_Install_Profiles::get_profile_modules( 'corporativo' );

		foreach ( $institucional as $slug ) {
			$this->assertContains( $slug, $corporativo );
		}
		foreach ( array( 'crm', 'automation', 'email-engine' ) as $added ) {
			$this->assertContains( $added, $corporativo );
		}
	}

	/** @test */
	public function test_corporativo_excludes_commerce_and_affiliates(): void {
		$modules = \CLMS_Install_Profiles::get_profile_modules( 'corporativo' );
		$this->assertNotContains( 'commerce', $modules );
		$this->assertNotContains( 'affiliates', $modules );
	}

	/** @test */
	public function test_get_profile_modules_unknown_profile_returns_null(): void {
		$this->assertNull( \CLMS_Install_Profiles::get_profile_modules( 'bogus' ) );
	}

	/** @test */
	public function test_current_defaults_to_academia_when_unset(): void {
		$this->assertSame( 'academia', \CLMS_Install_Profiles::current() );
	}

	/** @test */
	public function test_apply_sets_current_profile_and_active_modules(): void {
		\CLMS_Install_Profiles::apply( 'institucional' );

		$this->assertSame( 'institucional', \CLMS_Install_Profiles::current() );
		$this->assertTrue( \CLMS_Module_Registry::is_active( 'lms' ) );
		$this->assertFalse( \CLMS_Module_Registry::is_active( 'crm' ) );
	}

	/** @test */
	public function test_apply_unknown_profile_returns_false_and_does_not_change_state(): void {
		\CLMS_Install_Profiles::apply( 'academia' );
		$applied = \CLMS_Install_Profiles::apply( 'bogus' );

		$this->assertFalse( $applied );
		$this->assertSame( 'academia', \CLMS_Install_Profiles::current() );
	}

	/** @test */
	public function test_preview_before_any_apply_shows_diff_from_default_all_active(): void {
		// Sin apply() previo: estado actual = todos activos (default).
		$preview = \CLMS_Install_Profiles::preview( 'institucional' );

		$this->assertNotNull( $preview );
		$this->assertSame( array(), $preview['activates'], 'nada nuevo que activar, todo ya estaba activo' );
		$this->assertContains( 'crm', $preview['deactivates'] );
		$this->assertContains( 'affiliates', $preview['deactivates'] );
	}

	/** @test */
	public function test_preview_from_institucional_to_corporativo_shows_activates(): void {
		\CLMS_Install_Profiles::apply( 'institucional' );
		$preview = \CLMS_Install_Profiles::preview( 'corporativo' );

		$this->assertContains( 'crm', $preview['activates'] );
		$this->assertContains( 'automation', $preview['activates'] );
		$this->assertContains( 'email-engine', $preview['activates'] );
		$this->assertSame( array(), $preview['deactivates'] );
	}

	/** @test */
	public function test_preview_unknown_profile_returns_null(): void {
		$this->assertNull( \CLMS_Install_Profiles::preview( 'bogus' ) );
	}
}
