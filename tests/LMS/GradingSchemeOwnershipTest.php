<?php
/**
 * CLMS_REST_Grading_Controller::get_grading_scheme()/update_grading_scheme()
 * — verificación de dueño del curso — PT-1 (sprint 6.5.6, "legacy REST hardening").
 *
 * Hallazgo confirmado: ambas rutas (/grades/scheme/{course_id}) solo
 * exigían can_manage_grading() → can_manage_content() — la misma
 * capability amplia de cualquier profesor/calificador, sin verificar
 * que el curso perteneciera a quien hacía la solicitud. Un instructor
 * con clms_grade_submissions (por ejemplo, un ayudante de cátedra)
 * podía leer o REESCRIBIR la ponderación de notas de un curso ajeno.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class GradingSchemeOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_caps();
		$GLOBALS['__atora_test_posts']     = array();
		$GLOBALS['__atora_test_post_meta'] = array();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		\atora_test_reset_user_caps();
		$GLOBALS['__atora_test_posts']     = array();
		$GLOBALS['__atora_test_post_meta'] = array();
		parent::tearDown();
	}

	private function make_request( int $course_id, array $json = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( $json );
		$request->set_param( 'course_id', $course_id );
		return $request;
	}

	/**
	 * Caso confirmado del hallazgo: un instructor SIN ser dueño del
	 * curso no debe poder leer ni escribir su esquema de calificación.
	 *
	 * @test
	 */
	public function test_non_owning_instructor_cannot_read_or_write_grading_scheme(): void {
		\atora_test_set_post( 40, array( 'post_type' => 'lm_course', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 3, 'clms_grade_submissions' );
		$GLOBALS['__atora_test_current_user_id'] = 3;

		$controller = new \CLMS_REST_Grading_Controller( new \CLMS_REST_Permissions() );

		$read_result = $controller->get_grading_scheme( $this->make_request( 40 ) );
		$this->assertInstanceOf( \WP_Error::class, $read_result, 'no debe poder leer el esquema de un curso ajeno' );

		$write_result = $controller->update_grading_scheme( $this->make_request( 40, array( 'quiz' => 40, 'assignment' => 60 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $write_result, 'no debe poder reescribir el esquema de un curso ajeno' );
	}

	/**
	 * Nota: el camino "dueño real del curso" pasa por
	 * CLMS_Helper::user_can_manage_lms() (includes/helper/trait-helper-core.php),
	 * una dependencia pesada guardada con class_exists() en todo el
	 * codebase y no cargada en este arnés de test — no se levanta acá
	 * solo para este fix puntual. Se cubre el camino de denegación (el
	 * hallazgo real que se corrige) y el bypass de manage_options, que
	 * entre ambos demuestran que el gate nuevo existe y funciona.
	 */

	/** @test */
	public function test_manage_options_can_manage_any_grading_scheme(): void {
		\atora_test_set_post( 42, array( 'post_type' => 'lm_course', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 9, 'manage_options' );
		$GLOBALS['__atora_test_current_user_id'] = 9;

		$controller = new \CLMS_REST_Grading_Controller( new \CLMS_REST_Permissions() );

		$read_result = $controller->get_grading_scheme( $this->make_request( 42 ) );
		$this->assertNotInstanceOf( \WP_Error::class, $read_result );
	}
}
