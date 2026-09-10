<?php
/**
 * CLMS_Install_Profiles — PT-3 (sprint 6.3.0), renombrado y ampliado a
 * cuatro perfiles en PT-1 (sprint 6.12.0).
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
	public function test_four_profiles_defined_in_order(): void {
		$profiles = \CLMS_Install_Profiles::get_profiles();
		$this->assertSame( array( 'docente', 'institucion', 'creadores', 'academia' ), array_keys( $profiles ) );
	}

	/** @test */
	public function test_academia_includes_all_28_modules(): void {
		// 6.21.0 registra 28 módulos.
		$modules = \CLMS_Install_Profiles::get_profile_modules( 'academia' );
		$this->assertCount( 28, $modules );
	}

	/** @test */
	public function test_docente_is_the_minimal_teaching_set(): void {
		$modules = \CLMS_Install_Profiles::get_profile_modules( 'docente' );
		$this->assertSame(
			array( 'lms', 'academic', 'gradebook', 'security', 'certificates', 'calendar', 'google' ),
			$modules
		);
	}

	/** @test */
	public function test_institucion_extends_docente_without_commercial_modules(): void {
		$docente     = \CLMS_Install_Profiles::get_profile_modules( 'docente' );
		$institucion = \CLMS_Install_Profiles::get_profile_modules( 'institucion' );

		foreach ( $docente as $slug ) {
			$this->assertContains( $slug, $institucion );
		}
		foreach ( array( 'messaging', 'analytics', 'ai', 'live-streaming', 'gamification' ) as $added ) {
			$this->assertContains( $added, $institucion );
		}
		foreach ( array( 'crm', 'commerce', 'affiliates', 'newsletter', 'automation', 'webhooks', 'mcp', 'email-engine' ) as $excluded ) {
			$this->assertNotContains( $excluded, $institucion, "institucion no debe incluir {$excluded}" );
		}
	}

	/** @test */
	public function test_creadores_extends_institucion_with_commercial_modules(): void {
		$institucion = \CLMS_Install_Profiles::get_profile_modules( 'institucion' );
		$creadores   = \CLMS_Install_Profiles::get_profile_modules( 'creadores' );

		foreach ( $institucion as $slug ) {
			$this->assertContains( $slug, $creadores );
		}
		foreach ( array( 'crm', 'commerce', 'affiliates', 'email-engine', 'newsletter', 'automation', 'webhooks', 'mcp' ) as $added ) {
			$this->assertContains( $added, $creadores );
		}
	}

	/** @test */
	public function test_creadores_is_a_subset_of_the_full_academia_profile(): void {
		$creadores = \CLMS_Install_Profiles::get_profile_modules( 'creadores' );
		$academia  = \CLMS_Install_Profiles::get_profile_modules( 'academia' );

		$this->assertSame( array(), array_values( array_diff( $creadores, $academia ) ) );
		$this->assertContains( 'classroom', $academia );
		$this->assertNotContains( 'classroom', $creadores );
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
		\CLMS_Install_Profiles::apply( 'institucion' );

		$this->assertSame( 'institucion', \CLMS_Install_Profiles::current() );
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
		$preview = \CLMS_Install_Profiles::preview( 'institucion' );

		$this->assertNotNull( $preview );
		$this->assertSame( array(), $preview['activates'], 'nada nuevo que activar, todo ya estaba activo' );
		$this->assertContains( 'crm', $preview['deactivates'] );
		$this->assertContains( 'affiliates', $preview['deactivates'] );
	}

	/** @test */
	public function test_preview_from_institucion_to_creadores_shows_activates(): void {
		\CLMS_Install_Profiles::apply( 'institucion' );
		$preview = \CLMS_Install_Profiles::preview( 'creadores' );

		$this->assertContains( 'crm', $preview['activates'] );
		$this->assertContains( 'automation', $preview['activates'] );
		$this->assertContains( 'email-engine', $preview['activates'] );
		$this->assertSame( array(), $preview['deactivates'] );
	}

	/** @test */
	public function test_preview_unknown_profile_returns_null(): void {
		$this->assertNull( \CLMS_Install_Profiles::preview( 'bogus' ) );
	}

	/** @test */
	public function test_apply_clears_modified_state(): void {
		\CLMS_Install_Profiles::apply( 'docente' );
		\CLMS_Install_Profiles::mark_modified();
		$this->assertTrue( \CLMS_Install_Profiles::is_modified() );

		\CLMS_Install_Profiles::apply( 'creadores' );
		$this->assertFalse( \CLMS_Install_Profiles::is_modified() );
	}

	/** @test */
	public function test_display_label_appends_modified_suffix(): void {
		\CLMS_Install_Profiles::apply( 'creadores' );
		$this->assertSame( 'Creadores', \CLMS_Install_Profiles::display_label( 'creadores' ) );

		\CLMS_Install_Profiles::mark_modified();
		$this->assertSame( 'Creadores (modificado)', \CLMS_Install_Profiles::display_label( 'creadores' ) );
	}

	/** @test */
	public function test_migrate_legacy_institucional_maps_to_institucion_unmodified(): void {
		\CLMS_Install_Profiles::apply( 'institucion' );
		// Simula una instalación 6.3.0–6.11.0 con perfil legado guardado.
		update_option( \CLMS_Install_Profiles::OPTION, 'institucional' );
		delete_option( \CLMS_Install_Profiles::MIGRATED_OPTION );

		\CLMS_Install_Profiles::maybe_migrate_legacy_profile();

		$this->assertSame( 'institucion', \CLMS_Install_Profiles::current() );
		$this->assertFalse( \CLMS_Install_Profiles::is_modified(), 'el set de módulos coincide exactamente, no debería marcarse modificado' );
	}

	/** @test */
	public function test_migrate_legacy_corporativo_maps_to_creadores_and_marks_modified(): void {
		// corporativo (6.3.0) no incluía newsletter/commerce/affiliates/webhooks/mcp:
		// el set activo real no coincidirá con creadores, así que debe marcarse modificado.
		update_option( 'atora_active_modules', array( 'lms', 'academic', 'gradebook', 'certificates', 'security', 'messaging', 'calendar', 'analytics', 'ai', 'crm', 'automation', 'email-engine' ) );
		update_option( \CLMS_Install_Profiles::OPTION, 'corporativo' );
		delete_option( \CLMS_Install_Profiles::MIGRATED_OPTION );
		\CLMS_Module_Registry::flush_cache();

		\CLMS_Install_Profiles::maybe_migrate_legacy_profile();

		$this->assertSame( 'creadores', \CLMS_Install_Profiles::current() );
		$this->assertTrue( \CLMS_Install_Profiles::is_modified() );
	}

	/** @test */
	public function test_migrate_is_idempotent_and_runs_once(): void {
		update_option( \CLMS_Install_Profiles::OPTION, 'institucional' );
		delete_option( \CLMS_Install_Profiles::MIGRATED_OPTION );

		\CLMS_Install_Profiles::maybe_migrate_legacy_profile();
		$this->assertSame( 'institucion', \CLMS_Install_Profiles::current() );

		// Un segundo cambio manual a un perfil legado no debe volver a migrarse
		// porque MIGRATED_OPTION ya quedó en true.
		update_option( \CLMS_Install_Profiles::OPTION, 'corporativo' );
		\CLMS_Install_Profiles::maybe_migrate_legacy_profile();
		$this->assertSame( 'academia', \CLMS_Install_Profiles::current(), 'corporativo ya no es un slug válido y la migración no vuelve a correr' );
	}

	/** @test */
	public function test_no_saved_option_never_triggers_migration(): void {
		delete_option( \CLMS_Install_Profiles::OPTION );
		delete_option( \CLMS_Install_Profiles::MIGRATED_OPTION );

		\CLMS_Install_Profiles::maybe_migrate_legacy_profile();

		$this->assertSame( 'academia', \CLMS_Install_Profiles::current() );
		$this->assertFalse( \CLMS_Install_Profiles::is_modified() );
	}
}
