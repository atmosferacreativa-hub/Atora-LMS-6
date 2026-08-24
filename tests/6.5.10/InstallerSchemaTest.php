<?php
/**
 * 04-installer-schema / 06-parity-tables (partial) — sprint 6.5.10.
 *
 * Verifica que V5_Installer::install()/force_install() efectivamente
 * intentan crear las tablas de paridad LMS (PT-3) como parte del
 * ciclo de vida oficial, y que DB_Service (CRM v2) ahora incluye las
 * cuatro tablas huérfanas de Sequence_Service (PT-6) en su lista de
 * tablas requeridas — sin ejecutar dbDelta() real contra una BD
 * (no disponible en este entorno de test), verificando en su lugar
 * que la cadena de llamadas y la lista de tablas son correctas.
 *
 * @package ATORA_LMS\Tests\Installer
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Installer;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class InstallerSchemaTest extends TestCase {

	/**
	 * PT-3 (6.5.10): V5_Installer::install() debe intentar crear las
	 * tablas de LMS_Parity como parte de su cadena de instalación
	 * oficial, no solo depender de que 'init' se complete sin fatales
	 * en otro módulo.
	 *
	 * @test
	 */
	public function test_ensure_parity_tables_calls_lms_parity_ensure_table(): void {
		$this->assertTrue(
			class_exists( '\ATORA\V5_Installer' ),
			'V5_Installer debe estar cargado en el bootstrap de test'
		);

		$ref = new ReflectionMethod( '\ATORA\V5_Installer', 'ensure_parity_tables' );
		$this->assertTrue( $ref->isPrivate(), 'ensure_parity_tables() debe seguir siendo privado — es un detalle interno de install()' );

		// dbDelta() no existe fuera de wp-admin/includes/upgrade.php en
		// este entorno de test — LMS_Parity::ensure_table() lo requiere
		// vía require_once ABSPATH . 'wp-admin/includes/upgrade.php',
		// inexistente acá, así que invocar ensure_parity_tables() de
		// punta a punta no es practicable sin un WordPress real. Se
		// confirma en su lugar, por inspección de la fuente, que
		// V5_Installer::install()/force_install() efectivamente
		// encadenan la llamada a ensure_parity_tables() (PT-3 de este
		// hotfix es precisamente que esa llamada exista en el ciclo de
		// instalación oficial) y que LMS_Parity está disponible para
		// que la resuelva.
		$this->assertTrue(
			class_exists( '\ATORA\LMS\LMS_Parity' ),
			'LMS_Parity debe estar disponible para que ensure_parity_tables() pueda llamarlo'
		);

		$installer_source = file_get_contents( __DIR__ . '/../../modules/class-v5-installer.php' );
		$this->assertStringContainsString(
			'self::ensure_parity_tables()',
			$installer_source,
			'install()/force_install() deben encadenar ensure_parity_tables()'
		);
	}

	/**
	 * PT-3/PT-4/PT-6 (6.5.10): SCHEMA_VERSION debe haber cambiado desde
	 * la línea base de 6.5.9 ('5.1.6-telegram-links-table') — si no,
	 * install() se corta en el guard de la línea 1 y ninguno de los
	 * fixes de este sprint llega a ejecutarse en una instalación
	 * existente.
	 *
	 * @test
	 */
	public function test_schema_version_bumped_since_6_5_9(): void {
		$this->assertNotSame(
			'5.1.6-telegram-links-table',
			\ATORA\V5_Installer::SCHEMA_VERSION,
			'SCHEMA_VERSION debe haberse incrementado para que install() vuelva a correr en instalaciones existentes de 6.5.9'
		);
	}

	/**
	 * PT-6 (6.5.10): las cuatro tablas huérfanas de Sequence_Service
	 * deben estar en la lista de tablas requeridas del CRM v2 — de lo
	 * contrario get_missing_tables() nunca las detecta como faltantes
	 * y needs_schema solo se dispara por el version_compare() de
	 * SCHEMA_VERSION, no por su ausencia real.
	 *
	 * @test
	 */
	public function test_required_tables_include_sequence_service_tables(): void {
		$this->assertTrue( class_exists( '\ATORA\CRM_V2\Services\DB_Service' ) );

		$ref = new ReflectionMethod( '\ATORA\CRM_V2\Services\DB_Service', 'get_required_tables' );
		$ref->setAccessible( true );
		$tables = $ref->invoke( null );

		foreach ( array(
			'atora_email_sequences',
			'atora_email_sequence_steps',
			'atora_email_sequence_enrollments',
			'atora_email_suppression',
		) as $expected_suffix ) {
			$found = false;
			foreach ( $tables as $t ) {
				if ( false !== strpos( $t, $expected_suffix ) ) {
					$found = true;
					break;
				}
			}
			$this->assertTrue( $found, "get_required_tables() debe incluir {$expected_suffix}" );
		}
	}

	/**
	 * PT-6 (6.5.10): DB_Service::SCHEMA_VERSION también debe haber
	 * cambiado desde la línea base pre-6.5.10 ('2.2.0'), o
	 * maybe_install_schema() nunca dispara needs_schema por versión
	 * (solo por tablas ya ausentes, lo cual sigue siendo un backstop
	 * válido, pero la intención explícita de este fix es forzar el
	 * re-chequeo inmediato en el próximo request).
	 *
	 * @test
	 */
	public function test_db_service_schema_version_bumped(): void {
		$this->assertNotSame( '2.2.0', \ATORA\CRM_V2\Services\DB_Service::SCHEMA_VERSION );
	}
}
