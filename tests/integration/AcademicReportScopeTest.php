<?php
/**
 * Integración 6.36.0: los informes académicos (incluida la exportación de riesgo) usan el mismo
 * alcance que SpeedGrader y `/teacher/*`: docente de sección sí, ajeno no; con varias
 * instituciones, el administrador solo ve los cursos de la suya.
 */

declare( strict_types = 1 );

final class AcademicReportScopeTest extends WP_UnitTestCase {

	private int $author = 0;
	private int $course = 0;

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
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('ra','rb')" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institution_members WHERE role = 'admin'" );
		ATORA_Teacher_Scope::reset_cache();
		parent::tearDown();
	}

	private function can( int $viewer ): bool {
		$service = clms_core( 'CLMS_Academic_Report_Service' );
		$method  = new ReflectionMethod( $service, 'viewer_can_access_course_report' );
		$method->setAccessible( true );
		return (bool) $method->invoke( $service, $viewer, $this->course );
	}

	public function test_section_teacher_yes_stranger_no(): void {
		$teacher = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$section = \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->course, 'title' => 'A' ) );
		\ATORA\LMS\Section_Service::add_teacher( (int) $section, $teacher );
		$this->assertTrue( $this->can( $teacher ), 'El docente de sección ve el informe de su curso.' );
		$this->assertTrue( $this->can( $this->author ) );

		$stranger = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->assertFalse( $this->can( $stranger ) );
	}

	public function test_admin_of_another_institution_cannot_export(): void {
		global $wpdb;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( $this->can( $admin ), 'Una sola institución: sin cambios.' );

		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'ra', 'name' => 'RA', 'status' => 'active' ) );
		$a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'rb', 'name' => 'RB', 'status' => 'active' ) );
		$b = (int) $wpdb->insert_id;
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $b ), array( 'wp_post_id' => $this->course ) );
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $a, 'user_id' => $admin, 'role' => 'admin', 'status' => 'active' ) );
		ATORA_Teacher_Scope::reset_cache();

		$this->assertFalse( $this->can( $admin ), 'Antes de 6.36.0 cualquier administrador exportaba el riesgo de cualquier curso.' );

		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $a ), array( 'wp_post_id' => $this->course ) );
		ATORA_Teacher_Scope::reset_cache();
		$this->assertTrue( $this->can( $admin ) );
	}
}
