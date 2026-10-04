<?php
/**
 * Integración 6.27.4: lo que se edita en el editor de WordPress llega a
 * `atora_courses` / `atora_lessons` (y por tanto a la API móvil), con `revision`.
 */

declare( strict_types = 1 );

use ATORA\LMS\LMS_Course_Service;
use ATORA\LMS\LMS_Editor_Sync;

final class EditorTableSyncTest extends WP_UnitTestCase {

	private int $wp_course = 0;
	private int $course_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		// El DDL de force_install() confirma la transacción del test: se limpian filas de corridas previas.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_lessons" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_courses" );
		$this->assertTrue( class_exists( LMS_Editor_Sync::class ), 'La sincronización del editor debe cargarse con el plugin.' );

		$this->wp_course = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso base' ) );
		$this->course_id = absint( LMS_Course_Service::get_by_wp_post( $this->wp_course )['id'] ?? 0 );
		$this->assertGreaterThan( 0, $this->course_id, 'El curso publicado entra a la tabla al guardarse.' );
	}

	private function lesson( string $title, array $args = array() ): int {
		return self::factory()->post->create( array_merge( array(
			'post_type'   => 'lm_lesson',
			'post_status' => 'publish',
			'post_title'  => $title,
			'meta_input'  => array( '_clms_course_id' => $this->wp_course ),
		), $args ) );
	}

	private function lesson_row( int $wp_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_lessons WHERE wp_post_id = %d", $wp_id ), ARRAY_A ) ?: null;
	}

	private function course_row(): array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_courses WHERE wp_post_id = %d", $this->wp_course ), ARRAY_A );
	}

	private function curriculum_titles(): array {
		return array_column( LMS_Course_Service::get_lessons( $this->course_id ), 'title' );
	}

	public function test_lesson_created_after_course_migration_reaches_curriculum(): void {
		$this->lesson( 'Primera' );
		$this->lesson( 'Segunda' );
		$this->assertSame( array( 'Primera', 'Segunda' ), $this->curriculum_titles() );
	}

	public function test_lesson_title_and_order_edits_reach_table_and_bump_revision(): void {
		$id  = $this->lesson( 'Original' );
		$rev = (int) $this->lesson_row( $id )['revision'];

		wp_update_post( array( 'ID' => $id, 'post_title' => 'Editada', 'menu_order' => 4 ) );

		$row = $this->lesson_row( $id );
		$this->assertSame( 'Editada', $row['title'] );
		$this->assertSame( 4, (int) $row['lesson_order'] );
		$this->assertSame( $rev + 1, (int) $row['revision'] );
	}

	public function test_saving_without_changes_keeps_revision(): void {
		$id  = $this->lesson( 'Quieta' );
		$rev = (int) $this->lesson_row( $id )['revision'];
		wp_update_post( array( 'ID' => $id ) );
		$this->assertSame( $rev, (int) $this->lesson_row( $id )['revision'] );
	}

	public function test_lesson_draft_and_trash_leave_the_curriculum(): void {
		$draft = $this->lesson( 'Borrador' );
		$trash = $this->lesson( 'Papelera' );

		wp_update_post( array( 'ID' => $draft, 'post_status' => 'draft' ) );
		wp_trash_post( $trash );

		$this->assertSame( 'draft', $this->lesson_row( $draft )['status'] );
		$this->assertNotSame( 'published', $this->lesson_row( $trash )['status'] );
		$this->assertSame( array(), $this->curriculum_titles() );

		wp_untrash_post( $trash );
		wp_update_post( array( 'ID' => $trash, 'post_status' => 'publish' ) );
		$this->assertSame( array( 'Papelera' ), $this->curriculum_titles() );
	}

	public function test_lesson_permanent_delete_marks_row_deleted(): void {
		$id = $this->lesson( 'Efímera' );
		wp_delete_post( $id, true );
		$this->assertSame( LMS_Editor_Sync::DELETED, $this->lesson_row( $id )['status'] );
	}

	public function test_course_title_excerpt_and_status_edits_reach_table(): void {
		$rev = (int) $this->course_row()['revision'];

		wp_update_post( array( 'ID' => $this->wp_course, 'post_title' => 'Curso renombrado', 'post_excerpt' => 'Resumen nuevo' ) );
		$row = $this->course_row();
		$this->assertSame( 'Curso renombrado', $row['title'] );
		$this->assertSame( 'Resumen nuevo', $row['excerpt'] );
		$this->assertSame( $rev + 1, (int) $row['revision'] );

		wp_update_post( array( 'ID' => $this->wp_course, 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', $this->course_row()['status'] );

		wp_update_post( array( 'ID' => $this->wp_course, 'post_status' => 'publish' ) );
		wp_trash_post( $this->wp_course );
		$this->assertSame( 'trash', $this->course_row()['status'] );

		wp_delete_post( $this->wp_course, true );
		$this->assertSame( LMS_Editor_Sync::DELETED, $this->course_row()['status'] );
	}

	public function test_repair_aligns_drift_and_is_repeatable(): void {
		global $wpdb;
		$kept    = $this->lesson( 'Bien' );
		$drifted = $this->lesson( 'Título real' );
		$missing = $this->lesson( 'Sin fila' );

		// Estado previo a 6.27.4: fila vieja, lección sin fila y fila huérfana.
		$wpdb->update( $wpdb->prefix . 'atora_lessons', array( 'title' => 'Título viejo' ), array( 'wp_post_id' => $drifted ) );
		$wpdb->delete( $wpdb->prefix . 'atora_lessons', array( 'wp_post_id' => $missing ) );
		$wpdb->insert( $wpdb->prefix . 'atora_lessons', array( 'wp_post_id' => 999999, 'course_id' => $this->course_id, 'title' => 'Huérfana', 'status' => 'published' ) );
		$kept_rev = (int) $this->lesson_row( $kept )['revision'];

		$dry = LMS_Editor_Sync::repair_all( false );
		$this->assertSame( 1, $dry['lessons_updated'] );
		$this->assertSame( 1, $dry['lessons_inserted'] );
		$this->assertSame( 1, $dry['rows_deleted'] );
		$this->assertSame( 'Título viejo', $this->lesson_row( $drifted )['title'], 'El dry-run no escribe.' );

		$this->assertSame( $dry, LMS_Editor_Sync::repair_all() );
		$this->assertSame( 'Título real', $this->lesson_row( $drifted )['title'] );
		$this->assertNotNull( $this->lesson_row( $missing ) );
		$this->assertSame( LMS_Editor_Sync::DELETED, $this->lesson_row( 999999 )['status'] );
		$this->assertSame( $kept_rev, (int) $this->lesson_row( $kept )['revision'], 'Lo que está bien no se toca.' );

		$again = LMS_Editor_Sync::repair_all();
		$this->assertSame( 0, array_sum( $again ), 'Repetir no cambia nada.' );
	}
}
