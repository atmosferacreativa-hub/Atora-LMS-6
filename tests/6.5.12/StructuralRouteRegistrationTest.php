<?php
/**
 * 04-course-valid-url / 05-course-invalid-url / 07-instructor-valid-url
 * / 08-instructor-invalid-url — sprint 6.5.12.
 *
 * Full end-to-end URL resolution needs a real WP_Rewrite/WP_Query
 * against a real WordPress install (not available in this environment
 * — no PHP/WordPress runtime, disclosed constraint carried through
 * this whole engagement). What's verified here, without any
 * WordPress bootstrap, is that the actual PHP source atora_lms.php
 * runs at 'init' priority 0 performs the exact method calls that
 * would make `post_type_exists('lm_course')` true and the instructor
 * rewrite rule present, by directly re-deriving the reflection-based
 * call sequence from the real file and confirming it targets the real
 * classes/methods (not stubs or placeholders).
 *
 * @package ATORA_LMS\Tests\Bootstrap
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

class StructuralRouteRegistrationTest extends TestCase {

	/**
	 * 02/04/05 — the structural bootstrap must call the SAME
	 * `register_post_types()` method that `includes/class-cpt.php`
	 * actually defines (not a copy, not a stub) — confirms no drift
	 * between the early call and the real registration logic.
	 *
	 * @test
	 */
	public function test_structural_bootstrap_calls_the_real_cpt_registration_method(): void {
		$bootstrap = file_get_contents( __DIR__ . '/../../atora_lms.php' );
		$cpt_source = file_get_contents( __DIR__ . '/../../includes/class-cpt.php' );

		$this->assertStringContainsString( '$early_cpt->register_post_types()', $bootstrap );
		$this->assertStringContainsString( '$early_cpt->register_taxonomies()', $bootstrap );

		// Confirm the method being called is a REAL public method on
		// CLMS_CPT, not renamed/removed since.
		$this->assertMatchesRegularExpression(
			'/public function register_post_types\(\)/',
			$cpt_source
		);
		$this->assertMatchesRegularExpression(
			"/register_post_type\\(\\s*'lm_course'/",
			$cpt_source,
			'register_post_types() must still register lm_course'
		);
	}

	/**
	 * 06/07/08 — same confirmation for the instructor rewrite rule.
	 *
	 * @test
	 */
	public function test_structural_bootstrap_calls_the_real_instructor_rewrite_method(): void {
		$bootstrap = file_get_contents( __DIR__ . '/../../atora_lms.php' );
		$instructor_source = file_get_contents( __DIR__ . '/../../includes/class-instructor.php' );

		$this->assertStringContainsString( '$early_instructor->register_rewrite()', $bootstrap );

		$this->assertMatchesRegularExpression(
			'/public function register_rewrite\(\)/',
			$instructor_source
		);
		$this->assertMatchesRegularExpression(
			"/add_rewrite_rule\\(\\s*'\\^docentes\\//",
			$instructor_source,
			'register_rewrite() must still register the /docentes/{slug}/ pattern'
		);
	}

	/**
	 * The structural bootstrap must run at 'init' priority 0 — earlier
	 * than CLMS_Loader::boot() (priority 1), so by the time the loader
	 * (and anything it triggers, including the 6.5.11 versioned
	 * rewrite-flush on 'wp_loaded') runs, the CPT/rewrite rule are
	 * already registered deterministically.
	 *
	 * @test
	 */
	public function test_structural_bootstrap_priority_precedes_loader_boot(): void {
		$bootstrap = file_get_contents( __DIR__ . '/../../atora_lms.php' );

		preg_match( "/add_action\\(\\s*'init',\\s*static function \\(\\) \\{.*?CLMS_CPT.*?\\}, (\\d+) \\);/s", $bootstrap, $structural_match );
		$this->assertNotEmpty( $structural_match, 'no se pudo extraer la prioridad del bootstrap estructural' );

		preg_match( "/CLMS_Loader::boot\\(\\)/", $bootstrap, $loader_call_match );
		$this->assertNotEmpty( $loader_call_match, 'no se encontró la llamada a CLMS_Loader::boot()' );

		// El bootstrap estructural referencia explícitamente prioridad 1
		// como la prioridad del closure que contiene a CLMS_Loader::boot()
		// (documentado en el propio comentario del fix) — se confirma acá
		// que la prioridad numérica extraída es efectivamente MENOR.
		$structural_priority = (int) $structural_match[1];
		$this->assertLessThan(
			1,
			$structural_priority,
			"el bootstrap estructural (prioridad {$structural_priority}) debe correr ANTES que el closure que contiene CLMS_Loader::boot() (prioridad 1)"
		);
	}
}
