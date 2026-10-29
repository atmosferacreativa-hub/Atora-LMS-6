<?php
/**
 * Integración: Foros (web shortcode + admin reportes).
 */

declare( strict_types = 1 );

use ATORA\Forums\Forums_Installer;

final class ForumsWebTest extends WP_UnitTestCase {
	private int $teacher = 0;
	private int $student = 0;
	private int $stranger = 0;
	private int $admin_b = 0;
	private int $inst_a = 0;
	private int $inst_b = 0;
	private int $wp_course = 0;
	private int $wp_course_b = 0;
	private int $forum_id = 0;
	private int $forum_b = 0;
	private int $previous_default = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		if ( class_exists( 'CLMS_DB_Migration' ) ) {
			( new CLMS_DB_Migration() )->run();
		}
		Forums_Installer::ensure_tables();

		global $wpdb;
		foreach ( array( 'atora_forums', 'atora_forum_topics', 'atora_forum_replies', 'atora_forum_reports', 'atora_sections', 'atora_section_teachers' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->remove_institutions();

		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'foros-web-a', 'name' => 'Inst A', 'status' => 'active' ) );
		$this->inst_a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'foros-web-b', 'name' => 'Inst B', 'status' => 'active' ) );
		$this->inst_b = (int) $wpdb->insert_id;
		$this->previous_default = (int) get_option( 'atora_default_institution', 0 );
		update_option( 'atora_default_institution', $this->inst_a, false );

		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor', 'display_name' => 'Docente Web' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Alumno Web' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'No Matriculado Web' ) );
		$this->admin_b = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Admin B' ) );

		foreach ( array( $this->teacher, $this->student, $this->stranger, $this->admin_b ) as $u ) {
			$role = $u === $this->teacher ? 'teacher' : ( $u === $this->admin_b ? 'admin' : 'member' );
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_a, 'user_id' => $u, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $u, '_atora_active_institution', $this->inst_a );
		}
		$wpdb->update( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_b ), array( 'user_id' => $this->admin_b ) );
		update_user_meta( $this->admin_b, '_atora_active_institution', $this->inst_b );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher, 'post_title' => 'Curso Web' ) );
		$course = (int) \ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'];
		\ATORA\LMS\LMS_Enrollment_Service::enroll( $this->student, $course );
		CLMS_Helper::enroll_user_in_course( $this->student, $this->wp_course );
		$section = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course, 'title' => 'Sección Web' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section, $this->teacher );

		update_option( 'atora_default_institution', $this->inst_b, false );
		$this->wp_course_b = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $author, 'post_title' => 'Curso Web B' ) );
		$section_b = (int) \ATORA\LMS\Section_Service::create( array( 'wp_course_id' => $this->wp_course_b, 'title' => 'Sección Web B' ) );
		\ATORA\LMS\Section_Service::add_teacher( $section_b, $this->admin_b );
		\ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->wp_course_b );
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $this->inst_b ), array( 'wp_post_id' => $this->wp_course_b ) );
		ATORA_Teacher_Scope::reset_cache();
		$forum_b = \ATORA\Forums\Forums_Service::forum_for_scope( 'course', $this->wp_course_b );
		$this->forum_b = (int) ( is_array( $forum_b ) ? $forum_b['id'] : 0 );
		update_option( 'atora_default_institution', $this->inst_a, false );

		$forum = \ATORA\Forums\Forums_Service::forum_for_scope( 'course', $this->wp_course );
		$this->forum_id = (int) ( is_array( $forum ) ? $forum['id'] : 0 );
		$this->assertGreaterThan( 0, $this->forum_id );
	}

	protected function tearDown(): void {
		global $wpdb;
		foreach ( array( 'atora_forums', 'atora_forum_topics', 'atora_forum_replies', 'atora_forum_reports' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		$this->remove_institutions();
		update_option( 'atora_default_institution', $this->previous_default, false );
		parent::tearDown();
	}

	private function remove_institutions(): void {
		global $wpdb;
		$ids = array_map( 'absint', (array) $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('foros-web-a','foros-web-b')" ) );
		if ( $ids ) {
			$in = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institution_members WHERE institution_id IN ({$in})" ); // phpcs:ignore WordPress.DB
			$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
		}
	}

	public function test_shortcode_without_attributes_renders_for_course_page(): void {
		wp_set_current_user( $this->student );
		$this->go_to( get_permalink( $this->wp_course ) );
		global $post;
		$post = get_post( $this->wp_course );
		setup_postdata( $post );
		$html = do_shortcode( '[atora_forum]' );
		$this->assertStringContainsString( 'class="atora-forum"', $html );
		$this->assertStringContainsString( 'Crear tema', $html );
	}

	public function test_shortcode_non_enrolled_user_sees_access_notice_not_topics(): void {
		wp_set_current_user( $this->stranger );
		$this->go_to( get_permalink( $this->wp_course ) );
		global $post;
		$post = get_post( $this->wp_course );
		setup_postdata( $post );
		$html = do_shortcode( '[atora_forum]' );
		$this->assertStringContainsString( 'No tienes acceso a este foro', $html );
		$this->assertStringNotContainsString( 'Crear tema', $html );
	}

	public function test_admin_resolve_report_marks_resolved_and_hide_resolve_hides_message(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_forum_topics', array(
			'forum_id'       => $this->forum_id,
			'institution_id' => $this->inst_a,
			'author_id'      => $this->student,
			'title'          => 'Tema reportado',
			'body'           => '...',
			'client_event_id' => 'webt1',
		) ); // phpcs:ignore WordPress.DB
		$topic_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_forum_replies', array(
			'topic_id'       => $topic_id,
			'forum_id'       => $this->forum_id,
			'institution_id' => $this->inst_a,
			'author_id'      => $this->student,
			'body'           => 'Respuesta',
			'client_event_id' => 'webr1',
		) ); // phpcs:ignore WordPress.DB
		$reply_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_forum_reports', array(
			'forum_id'       => $this->forum_id,
			'institution_id' => $this->inst_a,
			'target_type'    => 'reply',
			'target_id'      => $reply_id,
			'reporter_id'    => $this->student,
			'reason'         => 'spam',
			'status'         => 'open',
		) ); // phpcs:ignore WordPress.DB
		$report_id = (int) $wpdb->insert_id;

		wp_set_current_user( $this->teacher );
		$_POST = array(
			'atora_forums_admin_action' => 'report_action',
			'report_id' => (string) $report_id,
			'action'    => 'dismiss',
			'_wpnonce'  => wp_create_nonce( 'atora_forums_admin_reports' ),
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		ob_start();
		ATORA_Forums::render_admin();
		ob_end_clean();
		$st = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_forum_reports WHERE id = %d", $report_id ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'dismissed', $st );
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT hidden FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d", $reply_id ) ) ); // phpcs:ignore WordPress.DB

		$wpdb->update( $wpdb->prefix . 'atora_forum_reports', array( 'status' => 'open' ), array( 'id' => $report_id ) ); // phpcs:ignore WordPress.DB
		$_POST = array(
			'atora_forums_admin_action' => 'report_action',
			'report_id' => (string) $report_id,
			'action'    => 'hide_resolve',
			'_wpnonce'  => wp_create_nonce( 'atora_forums_admin_reports' ),
		);
		ob_start();
		ATORA_Forums::render_admin();
		ob_end_clean();
		$st2 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}atora_forum_reports WHERE id = %d", $report_id ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'resolved', $st2 );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT hidden FROM {$wpdb->prefix}atora_forum_replies WHERE id = %d", $reply_id ) ) ); // phpcs:ignore WordPress.DB
	}

	public function test_moderator_of_other_course_does_not_see_report(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_forum_reports', array(
			'forum_id'       => $this->forum_id,
			'institution_id' => $this->inst_a,
			'target_type'    => 'topic',
			'target_id'      => 1,
			'reporter_id'    => $this->student,
			'reason'         => 'x',
			'status'         => 'open',
		) ); // phpcs:ignore WordPress.DB
		$rid = (int) $wpdb->insert_id;

		wp_set_current_user( $this->admin_b );
		$rows = ATORA_Forums::get_open_reports_for_user( $this->admin_b );
		$this->assertSame( array(), array_values( array_filter( $rows, static fn( $r ) => (int) $r['id'] === $rid ) ) );
	}
}

