<?php
/**
 * Integración 6.33.2 (orden 1.0.1, punto 1): acceso a un curso por la inscripción al programa.
 *
 * - Inscrito solo en el programa → entra a todos sus cursos (web y API móvil),
 *   también a uno agregado después.
 * - Programa caducado → no entra. Sin programa ni matrícula → no entra.
 * - Al agregar un curso al programa, sus inscritos quedan matriculados.
 * - `reconcile()` lista (solo lectura) y corrige las matrículas faltantes.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;

final class ProgramCourseAccessTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $stranger = 0;
	private int $program = 0;
	private int $course_a = 0;
	private int $course_b = 0;
	private int $lesson_a = 0;
	private array $tokens = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		do_action( 'rest_api_init', rest_get_server() );

		$author         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->student  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->course_a = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso del programa A' ) );
		$this->course_b = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso agregado después' ) );
		$this->lesson_a = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_author' => $author,
			'post_title'  => 'Lección A',
			'meta_input'  => array( '_clms_course_id' => $this->course_a ),
		) );
		LMS_Course_Service::get_by_wp_post( $this->course_a );
		LMS_Course_Service::get_by_wp_post( $this->course_b );
		LMS_Course_Service::get_lesson_by_wp_post( $this->lesson_a );
		$this->program = self::factory()->post->create( array( 'post_type' => 'lm_program', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Diplomado' ) );

		// Inscripción solo al programa, sin matrícula a sus cursos (como llega por compra,
		// migración o cuando los cursos se agregan después): el programa aún no tiene cursos.
		CLMS_Helper::enroll_user_in_program( $this->student, $this->program );
		$this->set_program_courses( array( $this->course_a ), false );

		foreach ( array( $this->student, $this->stranger ) as $user ) {
			$this->tokens[ $user ] = ATORA_Mobile_Token_Service::issue( $user, 'test' )['access_token'];
		}
	}

	/** Cambia los cursos del programa; sin `$hook`, como si el cambio no hubiera pasado por WordPress (datos existentes). */
	private function set_program_courses( array $courses, bool $hook ): void {
		if ( ! $hook ) {
			remove_action( 'added_post_meta', array( 'ATORA_Course_Access_Service', 'on_program_courses_changed' ), 10 );
			remove_action( 'updated_post_meta', array( 'ATORA_Course_Access_Service', 'on_program_courses_changed' ), 10 );
		}
		update_post_meta( $this->program, CLMS_Helper::PROGRAM_COURSES_META, $courses );
		if ( ! $hook ) {
			add_action( 'added_post_meta', array( 'ATORA_Course_Access_Service', 'on_program_courses_changed' ), 10, 3 );
			add_action( 'updated_post_meta', array( 'ATORA_Course_Access_Service', 'on_program_courses_changed' ), 10, 3 );
		}
	}

	private function table_id( int $wp_course ): int {
		return (int) LMS_Course_Service::get_by_wp_post( $wp_course )['id'];
	}

	private function get( int $user, string $route ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1' . $route );
		$request->set_header( 'Authorization', 'Bearer ' . $this->tokens[ $user ] );
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	public function test_program_member_enters_program_courses_on_web_and_app(): void {
		$this->assertFalse( CLMS_Helper::user_is_enrolled_in_course( $this->student, $this->course_a ), 'Sin matrícula directa (el caso del fallo).' );
		$this->assertTrue( ATORA_Course_Access_Service::can_access( $this->student, $this->course_a ) );
		$this->assertTrue( CLMS_Helper::user_can_access_course( $this->student, $this->course_a ), 'Web.' );
		$this->assertTrue( CLMS_Helper::user_can_access_lesson( $this->student, $this->lesson_a ), 'Web: lección.' );

		$course_id = $this->table_id( $this->course_a );
		$this->assertSame( 200, $this->get( $this->student, "/courses/{$course_id}" )->get_status(), 'App: abrir el curso.' );
		$listed = wp_list_pluck( (array) $this->get( $this->student, '/courses' )->get_data()['items'], 'id' );
		$this->assertContains( $course_id, array_map( 'intval', $listed ), 'App: el curso aparece en la lista.' );
		$this->assertContains( $course_id, $this->get( $this->student, '/sync/changes' )->get_data()['enrolled_course_ids'], 'Sincronización.' );
		$program = $this->get( $this->student, "/programs/{$this->program}" )->get_data();
		$this->assertContains( $course_id, array_map( 'intval', wp_list_pluck( $program['courses'], 'id' ) ) );
	}

	public function test_course_added_later_is_accessible_immediately_and_enrolls_members(): void {
		$this->assertFalse( ATORA_Course_Access_Service::can_access( $this->student, $this->course_b ) );
		$this->set_program_courses( array( $this->course_a, $this->course_b ), true );
		$this->assertTrue( ATORA_Course_Access_Service::can_access( $this->student, $this->course_b ) );
		$this->assertTrue( CLMS_Helper::user_is_enrolled_in_course( $this->student, $this->course_b ), 'Al agregarlo, el inscrito queda matriculado.' );
		$this->assertSame( 200, $this->get( $this->student, '/courses/' . $this->table_id( $this->course_b ) )->get_status() );
		$this->assertFalse( CLMS_Helper::user_is_enrolled_in_course( $this->stranger, $this->course_b ), 'Solo los inscritos.' );
	}

	public function test_expired_program_or_no_enrollment_gives_no_access(): void {
		CLMS_Helper::set_user_program_access_expiration( $this->student, $this->program, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$this->assertFalse( ATORA_Course_Access_Service::can_access( $this->student, $this->course_a ), 'Programa caducado.' );
		$this->assertSame( 403, $this->get( $this->student, '/courses/' . $this->table_id( $this->course_a ) )->get_status() );
		$this->assertFalse( CLMS_Helper::user_can_access_lesson( $this->student, $this->lesson_a ) );

		$this->assertFalse( ATORA_Course_Access_Service::can_access( $this->stranger, $this->course_a ), 'Sin programa ni matrícula.' );
		$this->assertSame( 403, $this->get( $this->stranger, '/courses/' . $this->table_id( $this->course_a ) )->get_status() );
		$this->assertNotContains( $this->table_id( $this->course_a ), (array) ( $this->get( $this->stranger, '/sync/changes' )->get_data()['enrolled_course_ids'] ?? array() ) );
	}

	public function test_reconcile_reports_read_only_and_then_fixes(): void {
		$missing = ATORA_Course_Access_Service::reconcile( $this->program, false );
		$this->assertSame( array( array( 'user_id' => $this->student, 'program_id' => $this->program, 'course_id' => $this->course_a ) ), $missing );
		$this->assertFalse( CLMS_Helper::user_is_enrolled_in_course( $this->student, $this->course_a ), 'Solo lectura: no cambia nada.' );

		CLMS_Helper::set_user_program_access_expiration( $this->student, $this->program, gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
		ATORA_Course_Access_Service::reconcile( $this->program, true );
		$this->assertTrue( CLMS_Helper::user_is_enrolled_in_course( $this->student, $this->course_a ) );
		$this->assertNotSame( '', (string) CLMS_Helper::get_user_course_access_expiration( $this->student, $this->course_a ), 'La matrícula caduca con el programa.' );
		$this->assertSame( array(), ATORA_Course_Access_Service::reconcile( $this->program, false ) );
	}
}
