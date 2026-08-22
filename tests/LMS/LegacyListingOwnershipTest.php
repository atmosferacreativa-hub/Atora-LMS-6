<?php
/**
 * CLMS_REST_Academics_Core_Resources_Trait::get_courses()/get_programs()/
 * get_lessons() — scoping por dueño en listados — PT-1 (sprint 6.5.7).
 *
 * Hallazgo confirmado (no atrapado en el audit de 6.5.6, que revisó
 * los endpoints de escritura pero no la lógica interna de filtrado de
 * estos tres listados): clms_manage_courses/clms_manage_lessons/
 * clms_manage_submissions/clms_grade_submissions es una capability
 * GENÉRICA que tiene cualquier instructor — no implica
 * edit_others_lm_courses. Con status=draft|private(|all para
 * lecciones) + un teacher_id (o course_id, para lecciones) provisto
 * por el cliente apuntando a OTRO instructor, el listado devolvía
 * directamente el contenido no publicado de ese otro instructor —
 * $args['author']/$args['post__in'] se fijaban al valor del cliente
 * sin verificar dueño.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class LegacyListingOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_caps();
		\atora_test_reset_posts();
		$GLOBALS['__atora_test_post_types'] = array();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		\atora_test_reset_user_caps();
		\atora_test_reset_posts();
		$GLOBALS['__atora_test_post_types'] = array();
		parent::tearDown();
	}

	private function controller(): \CLMS_REST_Academics_Controller {
		return new \CLMS_REST_Academics_Controller( new \CLMS_REST_Permissions() );
	}

	private function request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return $request;
	}

	// ── get_courses() ────────────────────────────────────────────────────────

	/**
	 * Caso confirmado del hallazgo (courses): instructor A, con
	 * clms_manage_courses (sin edit_others_lm_courses), pide los
	 * borradores del instructor B por teacher_id — no debe recibir
	 * ninguno.
	 *
	 * @test
	 */
	public function test_instructor_cannot_list_another_instructors_draft_courses_via_teacher_id(): void {
		\atora_test_set_post( 10, array( 'post_type' => 'lm_course', 'post_status' => 'draft', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 3, 'clms_manage_courses' );
		$GLOBALS['__atora_test_current_user_id'] = 3;

		$response = $this->controller()->get_courses( $this->request( array( 'status' => 'draft', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 0, $payload['total'], 'instructor A no debe ver los borradores de B via teacher_id=B' );
	}

	/** @test */
	public function test_instructor_sees_own_draft_courses(): void {
		\atora_test_set_post( 11, array( 'post_type' => 'lm_course', 'post_status' => 'draft', 'post_author' => 3 ) );
		\atora_test_set_user_cap( 3, 'clms_manage_courses' );
		$GLOBALS['__atora_test_current_user_id'] = 3;

		// Incluso pidiendo teacher_id=2 (ajeno), debe forzarse a sus propios cursos.
		$response = $this->controller()->get_courses( $this->request( array( 'status' => 'draft', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 1, $payload['total'], 'instructor A sí debe ver sus propios borradores, sin importar el teacher_id enviado' );
	}

	/** @test */
	public function test_admin_can_list_any_instructors_draft_courses(): void {
		\atora_test_set_post( 12, array( 'post_type' => 'lm_course', 'post_status' => 'draft', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 1, 'manage_options' );
		$GLOBALS['__atora_test_current_user_id'] = 1;

		$response = $this->controller()->get_courses( $this->request( array( 'status' => 'draft', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 1, $payload['total'], 'manage_options sí debe conservar acceso global' );
	}

	/** @test */
	public function test_student_cannot_request_draft_courses_at_all(): void {
		$GLOBALS['__atora_test_current_user_id'] = 5; // sin ninguna capability de gestión.
		\atora_test_set_post( 13, array( 'post_type' => 'lm_course', 'post_status' => 'draft', 'post_author' => 2 ) );

		$response = $this->controller()->get_courses( $this->request( array( 'status' => 'draft', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 0, $payload['total'] );
	}

	// ── get_programs() ───────────────────────────────────────────────────────

	/** @test */
	public function test_instructor_cannot_list_another_instructors_private_programs_via_teacher_id(): void {
		\atora_test_set_post( 20, array( 'post_type' => 'lm_program', 'post_status' => 'private', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 3, 'clms_manage_courses' );
		$GLOBALS['__atora_test_current_user_id'] = 3;

		$response = $this->controller()->get_programs( $this->request( array( 'status' => 'private', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 0, $payload['total'] );
	}

	/** @test */
	public function test_admin_can_list_any_instructors_private_programs(): void {
		\atora_test_set_post( 21, array( 'post_type' => 'lm_program', 'post_status' => 'private', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 1, 'manage_options' );
		$GLOBALS['__atora_test_current_user_id'] = 1;

		$response = $this->controller()->get_programs( $this->request( array( 'status' => 'private', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 1, $payload['total'] );
	}

	// ── get_lessons() ────────────────────────────────────────────────────────

	/**
	 * Caso confirmado del hallazgo (lessons, ángulo teacher_id):
	 * status=all + teacher_id de otro instructor no debe filtrar por
	 * ese teacher_id — debe forzarse al usuario actual.
	 *
	 * @test
	 */
	public function test_instructor_cannot_list_another_instructors_lessons_via_teacher_id(): void {
		\atora_test_set_post( 30, array( 'post_type' => 'lm_lesson', 'post_status' => 'draft', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 3, 'clms_manage_lessons' );
		$GLOBALS['__atora_test_current_user_id'] = 3;

		$response = $this->controller()->get_lessons( $this->request( array( 'status' => 'all', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 0, $payload['total'], 'instructor A no debe ver lecciones de B via teacher_id=B' );
	}

	/**
	 * Caso confirmado del hallazgo (lessons, ángulo course_id): un
	 * instructor con capability de gestión, pidiendo status=draft +
	 * course_id de un curso ajeno, no debe recibir esas lecciones — el
	 * chequeo anterior solo corría para usuarios SIN la capability
	 * genérica, que nunca es el caso del atacante real.
	 *
	 * @test
	 */
	public function test_instructor_cannot_list_lessons_of_a_foreign_course_via_course_id(): void {
		\atora_test_set_post( 31, array( 'post_type' => 'lm_lesson', 'post_status' => 'draft', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 3, 'clms_manage_lessons' );
		$GLOBALS['__atora_test_current_user_id'] = 3;

		$response = $this->controller()->get_lessons( $this->request( array( 'status' => 'draft', 'course_id' => 999 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 0, $payload['total'] );
	}

	/** @test */
	public function test_admin_can_list_any_instructors_lessons(): void {
		\atora_test_set_post( 32, array( 'post_type' => 'lm_lesson', 'post_status' => 'draft', 'post_author' => 2 ) );
		\atora_test_set_user_cap( 1, 'manage_options' );
		$GLOBALS['__atora_test_current_user_id'] = 1;

		$response = $this->controller()->get_lessons( $this->request( array( 'status' => 'all', 'teacher_id' => 2 ) ) );
		$payload  = $response->get_data();

		$this->assertSame( 1, $payload['total'] );
	}
}
