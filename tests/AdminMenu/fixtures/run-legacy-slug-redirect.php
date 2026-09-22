<?php
/**
 * Runner aislado para CLMS_Legacy_Slug_Redirects::maybe_redirect().
 *
 * maybe_redirect() termina con un exit() real después de
 * wp_safe_redirect() — correcto en producción (WordPress no debe
 * seguir despachando la request tras enviar el header Location), pero
 * si el test lo llama dentro del propio proceso de PHPUnit, ese
 * exit() mata el proceso de PHPUnit ANTES de llegar a cualquier
 * assertSame(): el test "pasa" con exit code 0 sin haber comprobado
 * nada (ver HiddenPagesTest, hallazgo de la revisión de la rama
 * fix/6.26.7-academic-lab-blockers).
 *
 * Mismo patrón que tests/Analytics/fixtures/run-handle-submit.php:
 * este script corre en un proceso PHP totalmente aparte (lanzado con
 * proc_open() desde HiddenPagesTest). El exit() de maybe_redirect()
 * solo termina este proceso hijo; el proceso PHPUnit padre no se ve
 * afectado. A diferencia del fixture de Forms, aquí no basta un
 * marcador booleano — el test necesita el destino real del redirect,
 * así que un register_shutdown_function() vuelca
 * atora_test_last_redirect() al archivo marcador. PHP sí ejecuta las
 * shutdown functions registradas incluso cuando el script termina vía
 * exit() (a diferencia de un try/finally, que exit() nunca alcanza).
 *
 * Parámetros por variable de entorno: ATORA_TEST_MARKER (ruta del
 * archivo de salida) y ATORA_TEST_PAGE (valor de $_GET['page']).
 *
 * @package ATORA_LMS\Tests\AdminMenu
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';

$marker_path = (string) ( getenv( 'ATORA_TEST_MARKER' ) ?: '' );

register_shutdown_function(
	static function () use ( $marker_path ): void {
		if ( '' === $marker_path ) {
			return;
		}
		// atora_test_last_redirect() devuelve null si maybe_redirect()
		// nunca llamó a wp_safe_redirect() — se vuelca como cadena vacía
		// para distinguirlo de "no llegó a correr el shutdown".
		file_put_contents( $marker_path, (string) atora_test_last_redirect() );
	}
);

$_GET['page'] = (string) ( getenv( 'ATORA_TEST_PAGE' ) ?: '' );

\CLMS_Legacy_Slug_Redirects::maybe_redirect();
