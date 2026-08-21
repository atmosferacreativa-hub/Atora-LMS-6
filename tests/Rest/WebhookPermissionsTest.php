<?php
/**
 * CLMS_REST_Permissions::can_manage_webhooks() — PT-1 (sprint 6.5.6,
 * "legacy REST hardening").
 *
 * Hallazgo confirmado: GET/POST /webhooks y DELETE /webhooks/{id}
 * estaban gateados por can_manage_content() — la misma capability
 * amplia que ya tiene cualquier profesor/calificador
 * (clms_manage_lessons/clms_manage_submissions/clms_grade_submissions),
 * no solo administradores del sitio. Un webhook no está ligado a un
 * curso propio — se dispara para eventos de TODA la plataforma
 * (lesson.completed, grade.updated, certificate.issued, etc. de
 * cualquier curso) — así que cualquier profesor podía registrar una
 * URL externa propia y recibir una copia de eventos de calificación/
 * matrícula/certificados de alumnos que no eran suyos. No existe un
 * "dueño" natural al que anclar un ownership check, así que la
 * corrección fue subir el requisito a manage_options en vez de
 * intentar inventar un scope que el propio modelo de datos no tiene.
 *
 * @package ATORA_LMS\Tests\Rest
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Rest;

use PHPUnit\Framework\TestCase;

class WebhookPermissionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_user_caps();
		$GLOBALS['__atora_test_current_user_id'] = 0;
	}

	protected function tearDown(): void {
		\atora_test_reset_user_caps();
		parent::tearDown();
	}

	/**
	 * Caso confirmado del hallazgo: un profesor con capacidades de
	 * gestión de contenido (lo que can_manage_content() exigía como
	 * único gate antes de este sprint) pero SIN manage_options no debe
	 * poder gestionar webhooks. (can_manage_content() en sí depende de
	 * CLMS_Access, no cargada en este arnés de test — se verifica acá
	 * directamente el resultado de can_manage_webhooks() con las
	 * capabilities que sí satisfarían can_manage_content() en el código
	 * real: clms_manage_lessons/clms_manage_submissions/
	 * clms_grade_submissions.)
	 *
	 * @test
	 */
	public function test_content_manager_without_manage_options_cannot_manage_webhooks(): void {
		$GLOBALS['__atora_test_current_user_id'] = 5;
		\atora_test_set_user_cap( 5, 'clms_manage_lessons' );
		\atora_test_set_user_cap( 5, 'clms_grade_submissions' );

		$permissions = new \CLMS_REST_Permissions();

		$this->assertFalse( $permissions->can_manage_webhooks(), 'un profesor con capacidades de gestión de contenido, sin manage_options, no debe poder gestionar webhooks' );
	}

	/** @test */
	public function test_site_admin_can_manage_webhooks(): void {
		$GLOBALS['__atora_test_current_user_id'] = 1;
		\atora_test_set_user_cap( 1, 'manage_options' );

		$permissions = new \CLMS_REST_Permissions();

		$this->assertTrue( $permissions->can_manage_webhooks() );
	}

	/** @test */
	public function test_logged_out_user_cannot_manage_webhooks(): void {
		$permissions = new \CLMS_REST_Permissions();

		$this->assertFalse( $permissions->can_manage_webhooks() );
	}
}
