<?php
/**
 * Integración 6.37.0: planificación híbrida sin IA.
 * Crear, guardar con revisión (409 si alguien guardó antes), duplicar, vista previa y publicar sin
 * duplicar lecciones; el asistente prepara pero no publica ni cambia la evidencia; sin alcance, nada.
 */

declare( strict_types = 1 );

final class HybridPlanServiceTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $course  = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_hybrid_plans" );
		// force_install confirma la transacción (DDL): limpiar filas de otras pruebas.
		foreach ( array( 'atora_sections', 'atora_section_teachers' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		ATORA_Teacher_Scope::reset_cache();
		$this->teacher = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $this->teacher ) );
		wp_set_current_user( $this->teacher );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug = 'plan-inst'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institution_members WHERE role IN ('teacher','assistant') " );
		ATORA_Teacher_Scope::reset_cache();
		parent::tearDown();
	}

	private function data(): array {
		$audio = self::factory()->attachment->create( array( 'post_mime_type' => 'audio/mpeg', 'post_title' => 'Clase' ) );
		return array(
			'title'      => 'Fotosíntesis',
			'week_label' => 'Semana 3',
			'objective'  => 'Explicar cómo las plantas producen su alimento.',
			'in_person'  => array( 'date' => '2026-11-10', 'time' => '08:00', 'place' => 'Aula 2', 'activities' => 'Experimento con hojas.' ),
			'remote'     => array( 'before' => 'Escuchar el audio y leer la guía.', 'after' => 'Responder el cuestionario.' ),
			'resources'  => array(
				array( 'type' => 'audio', 'title' => 'Audio de la clase', 'file_id' => $audio, 'transcript' => 'Texto del audio.' ),
				array( 'type' => 'link', 'title' => 'Lectura', 'url' => 'https://example.org/lectura' ),
			),
			'evidence'   => array( 'enabled' => true, 'title' => 'Informe del experimento', 'instructions' => 'Foto y explicación.', 'due_date' => '2026-11-14', 'due_time' => '', 'rubric_id' => 0, 'criteria' => 'Claridad; uso de conceptos.' ),
			'support'    => array( 'how' => 'Foro de dudas y comentarios en la entrega.', 'when' => 'Respuesta en 48 h.' ),
		);
	}

	private function course_lessons(): array {
		return get_posts( array( 'post_type' => 'lm_lesson', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_clms_course_id', 'meta_value' => $this->course ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public function test_create_save_with_revision_and_conflict(): void {
		$plan = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->course, $this->data() );
		$this->assertIsArray( $plan );
		$this->assertSame( 'draft', $plan['status'] );
		$this->assertSame( 1, $plan['revision'] );

		$data          = $plan['data'];
		$data['title'] = 'Fotosíntesis y respiración';
		$saved         = ATORA_Hybrid_Plan_Service::update( $this->teacher, $plan['id'], $data, 1 );
		$this->assertSame( 2, $saved['revision'] );
		$this->assertSame( 'Fotosíntesis y respiración', $saved['data']['title'] );

		$stale = ATORA_Hybrid_Plan_Service::update( $this->teacher, $plan['id'], $data, 1 );
		$this->assertWPError( $stale );
		$this->assertSame( 'atora_plan_conflict', $stale->get_error_code() );
		$this->assertSame( 409, $stale->get_error_data()['status'] );
	}

	public function test_publish_creates_lessons_once_and_republish_updates_them(): void {
		$plan = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->course, $this->data() );

		$preview = ATORA_Hybrid_Plan_Service::preview( $this->teacher, $plan['id'] );
		$this->assertSame( array( 'create', 'create', 'create' ), wp_list_pluck( $preview['items'], 'action' ) );
		$this->assertCount( 0, $this->course_lessons(), 'La vista previa no crea nada.' );

		$out = ATORA_Hybrid_Plan_Service::publish( $this->teacher, $plan['id'], 1 );
		$this->assertIsArray( $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
		$this->assertCount( 3, $this->course_lessons() );
		$map = $out['plan']['lesson_map'];
		$this->assertSame( array( 'remote', 'in_person', 'evidence' ), array_keys( $map ) );

		$remote = $map['remote'];
		$this->assertSame( 'publish', get_post_status( $remote ) );
		$resources = get_post_meta( $remote, '_clms_lesson_resources', true );
		$this->assertSame( 'audio', $resources[0]['type'] );
		$this->assertSame( 'Texto del audio.', $resources[0]['transcript'] );
		$this->assertStringContainsString( 'Escuchar el audio', get_post_field( 'post_content', $remote ) );
		$this->assertSame( 'tarea', get_post_meta( $map['evidence'], 'lm_activity_type', true ) );
		$this->assertSame( '2026-11-14', get_post_meta( $map['evidence'], '_clms_due_date', true ) );

		// Publicar otra vez la misma revisión: no duplica.
		$again = ATORA_Hybrid_Plan_Service::publish( $this->teacher, $plan['id'], 1 );
		$this->assertIsArray( $again );
		$this->assertCount( 3, $this->course_lessons(), 'Reintentar no duplica lecciones.' );

		// Editar y republicar: actualiza las mismas lecciones.
		$data          = $out['plan']['data'];
		$data['title'] = 'Fotosíntesis (revisada)';
		$saved         = ATORA_Hybrid_Plan_Service::update( $this->teacher, $plan['id'], $data, 1 );
		$this->assertTrue( $saved['has_unpublished_changes'] );
		$preview = ATORA_Hybrid_Plan_Service::preview( $this->teacher, $plan['id'] );
		$this->assertSame( array( 'update', 'update', 'update' ), wp_list_pluck( $preview['items'], 'action' ) );
		ATORA_Hybrid_Plan_Service::publish( $this->teacher, $plan['id'], 2 );
		$this->assertCount( 3, $this->course_lessons() );
		$this->assertStringContainsString( 'Fotosíntesis (revisada)', get_the_title( $remote ) );
	}

	public function test_publish_requires_current_revision(): void {
		$plan = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->course, $this->data() );
		ATORA_Hybrid_Plan_Service::update( $this->teacher, $plan['id'], $plan['data'], 1 );
		$result = ATORA_Hybrid_Plan_Service::publish( $this->teacher, $plan['id'], 1 );
		$this->assertWPError( $result );
		$this->assertSame( 'atora_plan_conflict', $result->get_error_code() );
		$this->assertCount( 0, $this->course_lessons() );
	}

	public function test_duplicate_is_a_new_draft(): void {
		$plan = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->course, $this->data() );
		ATORA_Hybrid_Plan_Service::publish( $this->teacher, $plan['id'], 1 );
		$copy = ATORA_Hybrid_Plan_Service::duplicate( $this->teacher, $plan['id'] );
		$this->assertNotSame( $plan['id'], $copy['id'] );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertSame( array(), $copy['lesson_map'], 'La copia no hereda las lecciones publicadas.' );
		$this->assertStringContainsString( '(copia)', $copy['title'] );
	}

	public function test_stranger_and_coordinator_have_no_access(): void {
		$plan     = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->course, $this->data() );
		$stranger = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->assertWPError( ATORA_Hybrid_Plan_Service::create( $stranger, $this->course, $this->data() ) );
		$this->assertWPError( ATORA_Hybrid_Plan_Service::get_for_user( $stranger, $plan['id'] ) );
		$this->assertWPError( ATORA_Hybrid_Plan_Service::publish( $stranger, $plan['id'], 1 ) );

		$coordinator = self::factory()->user->create( array( 'role' => 'lms_coordinator' ) );
		$this->assertFalse( ATORA_Hybrid_Plan_Service::can_edit( $coordinator, $this->course ) );
	}

	public function test_works_with_ai_disabled_and_suggest_says_so(): void {
		update_option( 'atora_ai_features', array() );
		$this->assertFalse( ATORA_Hybrid_Plan_Service::ai_available() );
		$plan = ATORA_Hybrid_Plan_Service::create( $this->teacher, $this->course, $this->data() );
		$this->assertIsArray( ATORA_Hybrid_Plan_Service::publish( $this->teacher, $plan['id'], 1 ), 'Sin IA el recorrido completo funciona.' );
		$suggest = ATORA_Hybrid_Plan_Service::suggest( $this->teacher, $plan['id'], 'remote' );
		$this->assertWPError( $suggest );
		$this->assertSame( 'atora_plan_ai_off', $suggest->get_error_code() );
	}

	public function test_assistant_with_delegation_has_instructor_privileges(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'plan-inst', 'name' => 'Plan', 'status' => 'active' ) );
		$inst      = (int) $wpdb->insert_id;
		$assistant = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant' ) );
		foreach ( array( $this->teacher => 'teacher', $assistant => 'assistant' ) as $user => $role ) {
			$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $inst, 'user_id' => $user, 'role' => $role, 'status' => 'active' ) );
			update_user_meta( $user, '_atora_active_institution', $inst );
		}
		\ATORA\LMS\Tenant_Context::reset_cache();
		wp_set_current_user( $this->teacher );
		$this->assertGreaterThan( 0, ATORA_Delegation_Service::grant( $this->teacher, $assistant, array( 'scope' => 'course', 'wp_course_id' => $this->course, 'perms' => array( 'content' => true ) ) ) );
		wp_set_current_user( $assistant );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();

		// 6.41.0: con delegación, el instructor asistente tiene los mismos privilegios que el instructor.
		$this->assertTrue( ATORA_Hybrid_Plan_Service::can_edit( $assistant, $this->course ) );
		$this->assertTrue( ATORA_Hybrid_Plan_Service::can_publish( $assistant, $this->course ) );

		$data             = $this->data();
		$data['evidence'] = array( 'enabled' => true, 'title' => 'Examen', 'instructions' => 'x' );
		$plan             = ATORA_Hybrid_Plan_Service::create( $assistant, $this->course, $data );
		$this->assertTrue( $plan['data']['evidence']['enabled'], 'El asistente define la evidencia.' );
		$this->assertFalse( ATORA_Hybrid_Plan_Service::get_for_user( $assistant, $plan['id'] )['evidence_locked'] );

		$result = ATORA_Hybrid_Plan_Service::publish( $assistant, $plan['id'], 1 );
		$this->assertIsArray( $result, 'El asistente publica.' );
		$this->assertNotEmpty( $this->course_lessons() );

		// Sin delegación, nada.
		$other = self::factory()->user->create( array( 'role' => 'lms_instructor_assistant' ) );
		ATORA_Teacher_Scope::reset_cache();
		$this->assertFalse( ATORA_Hybrid_Plan_Service::can_edit( $other, $this->course ) );
		$this->assertFalse( ATORA_Hybrid_Plan_Service::can_publish( $other, $this->course ) );
	}
}
