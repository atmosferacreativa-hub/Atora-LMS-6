<?php
/**
 * Integración 6.31.0: `ATORA_Teacher_Scope`, una sola regla para SpeedGrader web
 * y `/teacher/*`.
 */

declare( strict_types = 1 );

final class TeacherScopeTest extends WP_UnitTestCase {

	private int $author = 0;
	private int $course = 0;
	private int $lesson = 0;
	private int $submission = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		foreach ( array( 'atora_sections', 'atora_section_teachers', 'atora_institutions' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();

		$this->author = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->author ) );
		$this->lesson = self::factory()->post->create( array( 'post_type' => 'lm_lesson', 'post_status' => 'publish', 'post_author' => $this->author, 'meta_input' => array( '_clms_course_id' => $this->course ) ) );
		$this->submission = self::factory()->post->create( array( 'post_type' => 'clms_submission', 'post_status' => 'publish' ) );
		update_post_meta( $this->submission, '_clms_submission_lesson_id', $this->lesson );
		update_post_meta( $this->submission, '_clms_submission_course_id', $this->course );
	}

	private function section_teacher(): int {
		$teacher = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$section = \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->course, 'title' => 'A' ) );
		\ATORA\LMS\Section_Service::add_teacher( (int) $section, $teacher );
		return $teacher;
	}

	public function test_section_teacher_can_grade_and_sees_the_course(): void {
		$teacher = $this->section_teacher();
		$this->assertTrue( ATORA_Teacher_Scope::can_grade_submission( $teacher, $this->submission ) );
		$this->assertContains( $this->course, ATORA_Teacher_Scope::course_ids( $teacher ) );

		wp_set_current_user( $teacher );
		$this->assertTrue( ( new CLMS_Grading() )->current_user_can_grade_submission( $this->submission ), 'SpeedGrader web usa la misma regla.' );
	}

	public function test_author_keeps_access_and_a_stranger_has_none(): void {
		$this->assertTrue( ATORA_Teacher_Scope::can_grade_submission( $this->author, $this->submission ) );
		$stranger = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->assertFalse( ATORA_Teacher_Scope::can_grade_submission( $stranger, $this->submission ) );
		$this->assertFalse( ATORA_Teacher_Scope::can_access_course( $stranger, $this->course ) );
		$this->assertNotContains( $this->course, ATORA_Teacher_Scope::course_ids( $stranger ) );
	}

	public function test_admin_is_limited_to_own_institution_only_with_several(): void {
		global $wpdb;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( ATORA_Teacher_Scope::can_grade_submission( $admin, $this->submission ), 'Una sola institución: sin cambios.' );

		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'a', 'name' => 'A', 'status' => 'active' ) );
		$a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'b', 'name' => 'B', 'status' => 'active' ) );
		$b = (int) $wpdb->insert_id;
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $b ), array( 'wp_post_id' => $this->course ) );
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $a, 'user_id' => $admin, 'role' => 'admin', 'status' => 'active' ) );
		ATORA_Teacher_Scope::reset_cache();

		$this->assertTrue( ATORA_Teacher_Scope::multi_institution() );
		$this->assertFalse( ATORA_Teacher_Scope::can_grade_submission( $admin, $this->submission ), 'Administrador de otra institución.' );
		$this->assertNotContains( $this->course, ATORA_Teacher_Scope::course_ids( $admin ) );

		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $a ), array( 'wp_post_id' => $this->course ) );
		ATORA_Teacher_Scope::reset_cache();
		$this->assertTrue( ATORA_Teacher_Scope::can_grade_submission( $admin, $this->submission ) );
	}
}
