<?php
/**
 * Integración 6.29.0: notas, devoluciones y certificados del estudiante.
 * Regla: el estudiante nunca ve una nota que no esté liberada, ni en la web ni en la app.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Enrollment_Service;

final class MobileGradesCertificatesTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $other = 0;
	private int $wp_course = 0;
	private int $course = 0;
	private int $wp_lesson = 0;
	private int $lesson = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		foreach ( array( 'atora_lessons', 'atora_courses', 'atora_enrollments', 'atora_lesson_progress' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::bump_version( 'gradebook' );
		}
		$this->student   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso con notas' ) );
		$this->course    = absint( LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'] );
		$this->wp_lesson = self::factory()->post->create( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => 'Tarea con rúbrica',
			'meta_input'  => array( '_clms_course_id' => $this->wp_course, 'lm_activity_type' => 'tarea' ),
		) );
		$this->lesson = absint( LMS_Course_Service::get_lesson_by_wp_post( $this->wp_lesson )['id'] );
		foreach ( array( $this->student, $this->other ) as $user ) {
			LMS_Enrollment_Service::enroll( $user, $this->course );
			if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'enroll_user_in_course' ) ) {
				CLMS_Helper::enroll_user_in_course( $user, $this->wp_course );
			}
		}
	}

	/** Entrega calificada en SpeedGrader: `in_review` = guardada sin liberar, `graded` = liberada. */
	private function grade( int $user, string $status, int $grade, string $feedback = 'Buen trabajo' ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish', 'post_author' => $user ) );
		update_post_meta( $id, '_clms_submission_user_id', $user );
		update_post_meta( $id, '_clms_submission_lesson_id', $this->wp_lesson );
		update_post_meta( $id, '_clms_submission_course_id', $this->wp_course );
		update_post_meta( $id, '_clms_submission_status', $status );
		update_post_meta( $id, '_clms_submission_grade', $grade );
		update_post_meta( $id, '_clms_submission_feedback', $feedback );
		update_post_meta( $id, '_clms_submission_rubric_scores', array( array( 'score' => 8, 'feedback' => 'Clara' ) ) );
		if ( class_exists( 'CLMS_Cache' ) ) {
			CLMS_Cache::bump_version( 'gradebook' );
		}
		return $id;
	}

	private function as( int $user, string $method, array $url = array(), array $params = array() ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'GET', '/atora-mobile/v1/x' );
		$request->set_url_params( $url );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = ATORA_Mobile_REST_Controller::$method( $request );
		return is_wp_error( $response ) ? $response : $response->get_data();
	}

	private function course_grade_for( int $user ) {
		foreach ( $this->as( $user, 'grades' )['courses'] as $course ) {
			if ( $course['course_id'] === $this->course ) {
				return $course['final_grade'];
			}
		}
		return 'sin curso';
	}

	public function test_saved_but_not_released_grade_never_appears(): void {
		$this->grade( $this->student, 'in_review', 95 );

		$this->assertNull( $this->course_grade_for( $this->student ), 'No cuenta en el promedio.' );
		$course = array_values( array_filter( $this->as( $this->student, 'grades' )['courses'], fn( $c ) => $c['course_id'] === $this->course ) )[0];
		$this->assertSame( 0, $course['graded_count'], 'Una nota no liberada no dispara el aviso.' );
		$activities = $this->as( $this->student, 'course_grades', array( 'course_id' => $this->course ) )['activities'];
		$this->assertNull( $activities[0]['grade'] );
		$this->assertSame( 'in_review', $activities[0]['status'] );

		// Web: el promedio del panel (CLMS_Grading) tampoco la cuenta.
		$summary = clms_core( 'CLMS_Grading' )->get_course_grade_summary( $this->student, $this->wp_course );
		$this->assertSame( 0, absint( $summary['graded_lessons'] ?? 0 ) );
	}

	public function test_released_grade_appears_in_app_and_web_alike(): void {
		$this->grade( $this->student, 'graded', 80 );

		$this->assertEquals( 80.0, $this->course_grade_for( $this->student ) );
		$course = array_values( array_filter( $this->as( $this->student, 'grades' )['courses'], fn( $c ) => $c['course_id'] === $this->course ) )[0];
		$this->assertSame( 1, $course['graded_count'], 'La app usa esto para avisar de una nota nueva.' );
		$this->assertNotNull( $course['last_graded_at'] );
		$activities = $this->as( $this->student, 'course_grades', array( 'course_id' => $this->course ) )['activities'];
		$this->assertEquals( 80.0, $activities[0]['grade'] );
		$this->assertSame( 'graded', $activities[0]['status'] );
		$web = clms_core( 'CLMS_Grading' )->get_course_grade_summary( $this->student, $this->wp_course );
		$this->assertEquals( 80, $web['final_average'], 'Mismo número que la web.' );
	}

	public function test_student_does_not_see_another_students_grades(): void {
		$this->grade( $this->other, 'graded', 40 );
		$this->assertNull( $this->course_grade_for( $this->student ) );
		$this->assertNull( $this->as( $this->student, 'course_grades', array( 'course_id' => $this->course ) )['activities'][0]['grade'] );
	}

	public function test_rubric_only_after_release(): void {
		$rubric = self::factory()->post->create( array( 'post_type' => 'clms_rubric', 'post_status' => 'publish', 'post_title' => 'Rúbrica' ) );
		update_post_meta( $rubric, CLMS_Rubric::META_CRITERIA, array( array(
			'name'       => 'Argumentación',
			'max_points' => 10,
			'competency' => 'Escritura',
			'levels'     => array(
				array( 'label' => 'En desarrollo', 'points' => 5 ),
				array( 'label' => 'Competente', 'points' => 8 ),
				array( 'label' => 'Excelente', 'points' => 10 ),
			),
		) ) );
		update_post_meta( $this->wp_lesson, '_clms_rubric_id', $rubric );
		$submission = $this->grade( $this->student, 'in_review', 70, 'Revisa la conclusión' );
		$review = clms_core( 'CLMS_Submission' )->get_student_review_view( $this->student, $this->wp_lesson );
		$this->assertNull( $review['grade'] );
		$this->assertNull( $review['rubric'] );
		$this->assertNull( $review['feedback'] );

		update_post_meta( $submission, '_clms_submission_status', 'graded' );
		$review = clms_core( 'CLMS_Submission' )->get_student_review_view( $this->student, $this->wp_lesson );
		$this->assertNotNull( $review['grade'] );
		$this->assertSame( 'Revisa la conclusión', $review['feedback'] );
		$this->assertSame( 'Clara', $review['rubric']['rows'][0]['feedback'] ?? null, 'Con la nota liberada llega la rúbrica por criterio.' );
		$this->assertEquals( 8, $review['rubric']['rows'][0]['score'] );
		$this->assertSame( 'Argumentación', $review['rubric']['rows'][0]['name'] );
		$this->assertSame( 'Competente', $review['rubric']['rows'][0]['level'], 'Nivel alcanzado: el de esos puntos, como en SpeedGrader.' );
	}

	/** 6.29.3: el nivel sale de las bandas de SpeedGrader, con el puntaje decimal sin truncar. */
	public function test_rubric_level_follows_speedgrader_bands(): void {
		$levels = array( array( 'label' => 'Suficiente', 'points' => 3 ), array( 'label' => 'Bueno', 'points' => 4 ) );
		$rubric = self::factory()->post->create( array( 'post_type' => 'clms_rubric', 'post_status' => 'publish', 'post_title' => 'Rúbrica 4' ) );
		update_post_meta( $rubric, CLMS_Rubric::META_CRITERIA, array( array( 'name' => 'Claridad', 'max_points' => 4, 'levels' => $levels ) ) );
		update_post_meta( $this->wp_lesson, '_clms_rubric_id', $rubric );
		$submission = $this->grade( $this->student, 'graded', 88 );

		$expect = static fn( float $v ): string => CLMS_Rubric_Level_Bands::describe( CLMS_Rubric_Panel_Renderer::build_level_bands( $levels, 4 ), $v );
		// Pares (no claves: PHP trunca las claves float).
		foreach ( array( array( 3.5, 'entre Suficiente y Bueno' ), array( 3.0, 'Suficiente' ), array( 4.0, 'Bueno' ) ) as list( $score, $label ) ) {
			update_post_meta( $submission, '_clms_submission_rubric_scores', array( array( 'score' => (float) $score, 'feedback' => '' ) ) );
			$row = clms_core( 'CLMS_Submission' )->get_student_review_view( $this->student, $this->wp_lesson )['rubric']['rows'][0];
			$this->assertSame( $label, $row['level'], "Puntaje {$score}" );
			$this->assertSame( $expect( (float) $score ), $row['level'], 'Igual que SpeedGrader con los mismos datos.' );
		}
		$this->assertEquals( 3.5, ( function () use ( $submission ) {
			update_post_meta( $submission, '_clms_submission_rubric_scores', array( array( 'score' => 3.5, 'feedback' => '' ) ) );
			return clms_core( 'CLMS_Submission' )->get_student_review_view( $this->student, $this->wp_lesson )['rubric']['rows'][0]['score'];
		} )(), 'El puntaje no se trunca.' );
	}

	public function test_certificate_link_expires_and_is_bound_to_the_user(): void {
		$expires = time() + 600;
		$sig     = ATORA_Mobile_REST_Controller::certificate_signature( $this->student, 'course', $this->wp_course, $expires );
		$url     = array( 'target_type' => 'course', 'target_id' => $this->wp_course );

		$other = $this->as( $this->other, 'certificate_document', $url, array( 'expires' => $expires, 'sig' => $sig ) );
		$this->assertSame( 'atora_mobile_certificate_link', $other->get_error_code(), 'El enlace de otro usuario no sirve.' );

		$old     = time() - 1;
		$expired = $this->as( $this->student, 'certificate_document', $url, array( 'expires' => $old, 'sig' => ATORA_Mobile_REST_Controller::certificate_signature( $this->student, 'course', $this->wp_course, $old ) ) );
		$this->assertSame( 'atora_mobile_certificate_link', $expired->get_error_code(), 'Un enlace vencido no sirve.' );

		// Firma válida del propio usuario: pasa la firma (y luego decide la elegibilidad).
		$own = $this->as( $this->student, 'certificate_document', $url, array( 'expires' => $expires, 'sig' => $sig ) );
		if ( is_wp_error( $own ) ) {
			$this->assertNotSame( 'atora_mobile_certificate_link', $own->get_error_code() );
		}
	}

	public function test_new_routes_are_not_cacheable(): void {
		do_action( 'rest_api_init', rest_get_server() );
		foreach ( array( '/atora-mobile/v1/grades', "/atora-mobile/v1/courses/{$this->course}/grades", '/atora-mobile/v1/certificates' ) as $route ) {
			$request  = new WP_REST_Request( 'GET', $route );
			$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_get_server()->dispatch( $request ) ), rest_get_server(), $request );
			$this->assertSame( 'no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control'] ?? null, $route );
		}
	}
}
