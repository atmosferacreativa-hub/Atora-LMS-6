<?php
/**
 * 02-course-permalink-generation / 03-course-rewrite-resolution /
 * 05-instructor-permalink-generation / 06-instructor-rewrite-resolution
 * — sprint 6.5.11.
 *
 * Full end-to-end rewrite resolution needs a real WP_Rewrite instance
 * parsing a real request (not available in this environment — no
 * PHP/WordPress runtime, disclosed constraint carried through this
 * whole engagement). What CAN be verified without a WordPress runtime
 * at all is the actual regex WordPress would register for the
 * instructor rewrite rule, and the CPT's `has_archive`/`rewrite`
 * `slug` values used both by WP_Rewrite to build its rule and by
 * `get_permalink()`/`post_type_link` to build outgoing links — a
 * mismatch between those two would be the exact "generated permalink
 * and router disagree" BLOCKER this sprint's OT calls out.
 *
 * @package ATORA_LMS\Tests\Rewrite
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Rewrite;

use PHPUnit\Framework\TestCase;

class RewriteResolutionTest extends TestCase {

	/**
	 * 03/06 — the literal regex registered via add_rewrite_rule() for
	 * instructor profiles must actually match the historical
	 * /docentes/{slug}/ URL shape, and must NOT match an unrelated
	 * path — this is pure PCRE, no WordPress needed to verify it.
	 *
	 * @test
	 */
	public function test_instructor_rewrite_regex_matches_the_historical_url_shape(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-instructor.php' );

		$this->assertMatchesRegularExpression(
			"/add_rewrite_rule\\(\\s*'([^']+)'/",
			$source,
			'no se encontró ningún add_rewrite_rule() en CLMS_Instructor'
		);
		preg_match( "/add_rewrite_rule\\(\\s*'([^']+)'/", $source, $m );
		$pattern = $m[1];

		// La propia regla, tal cual la registra el código, debe resolver
		// una URL válida existente...
		$this->assertSame(
			1,
			preg_match( '#' . $pattern . '#', 'docentes/maria-perez/', $matches ),
			"el patrón registrado '{$pattern}' no matchea 'docentes/maria-perez/' — una URL de perfil de docente válida devolvería 404"
		);
		$this->assertSame( 'maria-perez', $matches[1] ?? null );

		// ...pero no debe capturar segmentos adicionales de ruta (evita
		// que /docentes/x/y/ resuelva silenciosamente a un slug 'x').
		$this->assertSame(
			0,
			preg_match( '#^' . $pattern . '$#', 'docentes/maria-perez/algo-mas/' ),
			'el patrón no debería matchear una ruta con segmentos extra después del slug'
		);
	}

	/**
	 * 03/06 — confirma que la reescritura resuelve a `clms_instructor`,
	 * el mismo query var que register_query_vars() expone — si estos
	 * dos discreparan, WP resolvería la URL pero $wp_query nunca vería
	 * el slug (404 funcional idéntico al de una regla ausente).
	 *
	 * @test
	 */
	public function test_instructor_rewrite_target_matches_registered_query_var(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-instructor.php' );

		preg_match( "/add_rewrite_rule\\(\\s*'[^']+',\\s*'([^']+)'/", $source, $target_match );
		$this->assertNotEmpty( $target_match, 'no se pudo extraer el destino de add_rewrite_rule()' );
		$this->assertStringContainsString( 'clms_instructor', $target_match[1] );

		$this->assertMatchesRegularExpression(
			"/\\\$vars\\[\\]\\s*=\\s*'clms_instructor'/",
			$source,
			'register_query_vars() debe exponer clms_instructor como query var reconocido, o el valor nunca llega a $wp_query'
		);
	}

	/**
	 * 02/03 — el slug de reescritura del CPT de curso ('cursos') debe
	 * ser el mismo tanto en `rewrite.slug` (usado por WP_Rewrite para
	 * construir la regla real) como en `has_archive` (usado también
	 * por generadores de enlaces como get_post_type_archive_link()) —
	 * un desacuerdo entre ambos es exactamente el BLOCKER "generated
	 * permalink and router disagree" que pide esta OT.
	 *
	 * @test
	 */
	public function test_course_rewrite_slug_and_archive_slug_agree(): void {
		$source = file_get_contents( __DIR__ . '/../../includes/class-cpt.php' );

		preg_match(
			"/protected function register_course_post_type\\(\\).*?register_post_type\\(\\s*'lm_course'/s",
			$source,
			$block_match
		);
		$this->assertNotEmpty( $block_match, 'no se encontró register_course_post_type()' );
		$block = $block_match[0];

		preg_match( "/'has_archive'\\s*=>\\s*'([^']+)'/", $block, $archive_match );
		preg_match( "/'rewrite'\\s*=>\\s*array\\(\\s*'slug'\\s*=>\\s*'([^']+)'/", $block, $rewrite_match );

		$this->assertNotEmpty( $archive_match, "'has_archive' no encontrado en el registro de lm_course" );
		$this->assertNotEmpty( $rewrite_match, "'rewrite' => array('slug' => ...) no encontrado en el registro de lm_course" );

		$this->assertSame(
			$rewrite_match[1],
			$archive_match[1],
			"has_archive ('{$archive_match[1]}') y rewrite.slug ('{$rewrite_match[1]}') deben coincidir — de lo contrario el archivo de cursos y las URLs individuales de curso viven bajo prefijos distintos, rompiendo enlaces generados por get_post_type_archive_link()"
		);
	}
}
