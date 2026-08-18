<?php
/**
 * CLMS_Profile_Labels / atora_profile_label() — PT-3.4.3 (sprint 6.3.0).
 *
 * @package ATORA_LMS\Tests\Modularity
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Modularity;

use PHPUnit\Framework\TestCase;

class ProfileLabelsTest extends TestCase {

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
	public function test_academia_profile_returns_default_label(): void {
		// Sin perfil guardado => 'academia' (default).
		$this->assertSame( 'Contactos', atora_profile_label( 'contacts', 'Contactos' ) );
		$this->assertSame( 'Producto', atora_profile_label( 'product', 'Producto' ) );
	}

	/** @test */
	public function test_corporativo_profile_returns_default_label(): void {
		\CLMS_Install_Profiles::apply( 'corporativo' );
		$this->assertSame( 'Contactos', atora_profile_label( 'contacts', 'Contactos' ) );
	}

	/** @test */
	public function test_institucional_profile_returns_academic_label(): void {
		\CLMS_Install_Profiles::apply( 'institucional' );
		$this->assertSame( 'Estudiantes', atora_profile_label( 'contacts', 'Contactos' ) );
		$this->assertSame( 'Programa de formación', atora_profile_label( 'product', 'Producto' ) );
		$this->assertSame( 'Sección', atora_profile_label( 'cohort', 'Cohorte comercial' ) );
	}

	/** @test */
	public function test_institucional_unmapped_key_falls_back_to_default(): void {
		\CLMS_Install_Profiles::apply( 'institucional' );
		$this->assertSame( 'Algo sin mapear', atora_profile_label( 'unmapped_key', 'Algo sin mapear' ) );
	}

	/** @test */
	public function test_switching_back_to_academia_restores_default(): void {
		\CLMS_Install_Profiles::apply( 'institucional' );
		$this->assertSame( 'Estudiantes', atora_profile_label( 'contacts', 'Contactos' ) );

		\CLMS_Install_Profiles::apply( 'academia' );
		$this->assertSame( 'Contactos', atora_profile_label( 'contacts', 'Contactos' ) );
	}

	/** @test */
	public function test_pt342_keys_present_in_institutional_map(): void {
		\CLMS_Install_Profiles::apply( 'institucional' );
		foreach ( array( 'enrollment', 'program', 'section', 'teacher', 'coordinator' ) as $key ) {
			$this->assertNotSame( 'default-fallback', atora_profile_label( $key, 'default-fallback' ), "clave '{$key}' debería estar mapeada" );
		}
	}
}
