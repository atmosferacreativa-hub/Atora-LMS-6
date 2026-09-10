<?php
/**
 * Portfolios — Portfolios_Service::export_portfolio_zip(): casos
 * defensivos (id inválido, portafolio inexistente, extensión ZipArchive
 * no disponible).
 *
 * NOTA: este entorno de test (contenedor PHP CLI sin ext-zip) no tiene
 * la extensión `zip` instalada, así que no se pudo ejercer el camino
 * "feliz" (ZIP realmente generado con portfolio.json/items/feedback) de
 * punta a punta — export_portfolio_zip() retorna array() apenas detecta
 * que ZipArchive no existe (class-portfolios-service.php:820-822), así
 * que ese fallback SÍ queda cubierto, pero el contenido real del ZIP no
 * se verificó en este entorno. Si se corre con ext-zip disponible, vale
 * la pena añadir un test que abra el ZIP resultante y confirme que
 * contiene portfolio.json/items.csv/feedback.csv con el payload
 * correcto.
 *
 * @package ATORA_LMS\Tests\Portfolios
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Portfolios;

use PHPUnit\Framework\TestCase;

final class FakeWpdbPortfolioExport {
	public string $prefix = 'wp_';
	public array $portfolio_row = array();

	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function get_var( $sql ) { return 1; }
	public function get_row( $sql, $output = null ) {
		if ( false !== strpos( $sql, 'atora_portfolios' ) ) {
			return $this->portfolio_row ?: null;
		}
		return null;
	}
	public function get_results( $sql, $output = null ) { return array(); }
}

final class ExportZipTest extends TestCase {

	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/portfolios/class-portfolios-service.php';

		global $wpdb;
		$this->original_wpdb = $wpdb;
		$wpdb = new FakeWpdbPortfolioExport();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	/** @test */
	public function returns_empty_for_invalid_portfolio_id(): void {
		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertSame( array(), $service->export_portfolio_zip( 0 ) );
	}

	/** @test */
	public function returns_empty_when_portfolio_does_not_exist(): void {
		global $wpdb;
		$wpdb->portfolio_row = array(); // sin fila -> get_portfolio() devuelve []

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertSame( array(), $service->export_portfolio_zip( 999 ) );
	}

	/** @test */
	public function returns_empty_when_ziparchive_extension_is_unavailable(): void {
		if ( class_exists( '\ZipArchive' ) ) {
			$this->markTestSkipped( 'ext-zip está disponible en este entorno; este caso no aplica aquí.' );
		}

		global $wpdb;
		$wpdb->portfolio_row = array(
			'id' => 1, 'course_id' => 100, 'user_id' => 7, 'title' => 'Mi portafolio',
			'visibility' => 'private', 'public_slug' => '', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
		);

		$service = new \ATORA\Portfolios\Portfolios_Service();
		$this->assertSame( array(), $service->export_portfolio_zip( 1 ) );
	}
}
