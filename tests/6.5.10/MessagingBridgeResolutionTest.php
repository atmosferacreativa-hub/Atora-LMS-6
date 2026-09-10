<?php
/**
 * 08-messaging-bridge — sprint 6.5.10.
 *
 * Prueba directa de la semántica de resolución de namespaces de PHP
 * que causó el fatal original ("Class \"ATORA\CLMS_Academic_Messaging_Bridge\"
 * not found") y confirma que el patrón usado en el fix
 * (V5_Modules::load_messaging(), modules/class-v5-modules.php) es el
 * correcto: una referencia estática sin backslash a una clase global
 * dentro de un archivo con namespace se resuelve al namespace actual
 * en tiempo de COMPILACIÓN, sin importar que class_exists() con un
 * string plano SÍ encuentre la clase (los strings nunca se resuelven
 * por namespace).
 *
 * @package ATORA_LMS\Tests\Bootstrap
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

class MessagingBridgeResolutionTest extends TestCase {

	/**
	 * CLMS_Academic_Messaging_Bridge es una clase GLOBAL real de este
	 * proyecto (includes/academic/class-academic-messaging-bridge.php)
	 * — confirma que sigue existiendo y sigue siendo global (sin
	 * namespace), la precondición exacta bajo la que el bug original
	 * se manifestaba.
	 *
	 * @test
	 */
	public function test_academic_messaging_bridge_is_a_real_global_class(): void {
		$file = __DIR__ . '/../../includes/academic/class-academic-messaging-bridge.php';
		$this->assertFileExists( $file );

		$source = file_get_contents( $file );
		$this->assertStringNotContainsString(
			"\nnamespace ",
			$source,
			'CLMS_Academic_Messaging_Bridge debe seguir siendo una clase global (sin namespace) — si esto cambia, el fix de PT-1 debe revisarse'
		);
		$this->assertStringContainsString( 'class CLMS_Academic_Messaging_Bridge', $source );
	}

	/**
	 * Confirma, en el propio archivo del bug reportado, que la llamada
	 * estática real usa el backslash calificador y que la comprobación
	 * class_exists() que la precede TAMBIÉN lo usa (fail-closed y
	 * consistente entre ambas — antes del fix, class_exists() sin
	 * backslash pasaba igual mientras la llamada real fallaba, una
	 * discrepancia silenciosa entre el guard y el uso real).
	 *
	 * @test
	 */
	public function test_v5_modules_uses_a_fully_qualified_reference(): void {
		$source = file_get_contents( __DIR__ . '/../../modules/class-v5-modules.php' );

		$this->assertStringContainsString(
			"class_exists( '\\CLMS_Academic_Messaging_Bridge' )",
			$source,
			'el guard class_exists() debe apuntar explícitamente al namespace global'
		);
		$this->assertStringContainsString(
			'\CLMS_Academic_Messaging_Bridge::init();',
			$source,
			'la llamada estática real debe usar el backslash calificador — sin él, PHP la resuelve a ATORA\\CLMS_Academic_Messaging_Bridge, inexistente'
		);
	}

	/**
	 * Demostración directa, en tiempo de ejecución de ESTE MISMO
	 * archivo de test (namespace ATORA\Tests\Bootstrap), de la
	 * semántica exacta que causó el bug: una referencia sin backslash
	 * a un símbolo global se resuelve al namespace actual y no se
	 * encuentra, mientras que la versión con backslash sí resuelve al
	 * espacio de nombres global correctamente.
	 *
	 * @test
	 */
	public function test_php_namespace_resolution_semantics_reproduced(): void {
		// Definición de una clase global mínima, sin namespace, para
		// esta prueba puntual (no interfiere con ninguna clase real del
		// proyecto).
		if ( ! class_exists( '\Test_6510_Global_Class', false ) ) {
			eval( 'class Test_6510_Global_Class { public static function ping() { return "pong"; } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}

		// class_exists() con un string plano SIEMPRE se resuelve desde
		// el namespace global — esto es lo que hacía que el guard
		// original pasara sin detectar el problema.
		$this->assertTrue( class_exists( 'Test_6510_Global_Class' ) );

		// La llamada estática SIN backslash, en cambio, si se
		// escribiera literalmente en este archivo namespaced,
		// resolvería a ATORA\Tests\Bootstrap\Test_6510_Global_Class
		// (inexistente) — se confirma indirectamente comprobando que
		// esa clase namespaced NO existe, exactamente la causa del
		// fatal original.
		$this->assertFalse(
			class_exists( __NAMESPACE__ . '\\Test_6510_Global_Class' ),
			'una clase global no debe "aparecer" bajo el namespace actual solo por compartir nombre — esta es la trampa exacta del bug original'
		);

		// La llamada calificada con backslash SÍ resuelve correctamente
		// al namespace global, igual que el fix aplicado.
		$this->assertSame( 'pong', \Test_6510_Global_Class::ping() );
	}
}
