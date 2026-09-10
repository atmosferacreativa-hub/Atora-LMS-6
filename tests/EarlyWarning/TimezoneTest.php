<?php
/**
 * Early Warning — S3.1: la fecha límite de una lección debe interpretarse
 * en la zona horaria configurada en WordPress (wp_timezone()), no en la
 * del servidor PHP.
 *
 * @package ATORA_LMS\Tests\EarlyWarning
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
}

namespace ATORA\Tests\EarlyWarning {

use PHPUnit\Framework\TestCase;

final class TimezoneTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/early-warning/class-early-warning-service.php';
		atora_test_reset_post_meta();
	}

	protected function tearDown(): void {
		\atora_test_set_timezone( 'UTC' );
		parent::tearDown();
	}

	private function call_get_deadline_lessons( \ATORA\EarlyWarning\Early_Warning_Service $service, int $course_id, int $now_ts ): array {
		$m = new \ReflectionMethod( $service, 'get_deadline_lessons' );
		$m->setAccessible( true );
		return $m->invoke( $service, $course_id, $now_ts );
	}

	/**
	 * Una fecha límite de "23:30" en horario Santiago (UTC-3/UTC-4) ya
	 * pasó a las 03:00 UTC del día siguiente, pero NO ha pasado a las
	 * 23:45 UTC del mismo día (todavía son ~19:45-20:45 en Santiago).
	 * Si el código usara strtotime() con el timezone del servidor (UTC
	 * en este entorno de test) en vez de wp_timezone(), esta distinción
	 * se perdería.
	 */
	public function test_deadline_respects_configured_wp_timezone_not_server_timezone(): void {
		\atora_test_set_timezone( 'America/Santiago' );

		$course_id = 1;
		$lesson_id = 50;
		atora_test_set_course_lessons( $course_id, array( $lesson_id ) );
		atora_test_set_post_meta( $lesson_id, '_clms_due_date', '2026-03-10' );
		atora_test_set_post_meta( $lesson_id, '_clms_due_time', '23:30' );

		$service = new \ATORA\EarlyWarning\Early_Warning_Service();

		// 2026-03-10 23:45 UTC: en Santiago (UTC-3 en marzo, horario de
		// verano) son las 20:45 — el deadline (23:30 hora local) TODAVÍA
		// no pasó.
		$still_pending_ts = gmmktime( 23, 45, 0, 3, 10, 2026 );
		$this->assertEmpty(
			$this->call_get_deadline_lessons( $service, $course_id, $still_pending_ts ),
			'a las 23:45 UTC el deadline de las 23:30 hora Santiago (UTC-3) aún no debería haber vencido'
		);

		// 2026-03-11 03:00 UTC = 2026-03-10 24:00 Santiago: el deadline
		// (23:30 hora local) YA pasó.
		$already_past_ts = gmmktime( 3, 0, 0, 3, 11, 2026 );
		$this->assertNotEmpty(
			$this->call_get_deadline_lessons( $service, $course_id, $already_past_ts ),
			'a las 03:00 UTC del día siguiente el deadline de las 23:30 hora Santiago ya debería haber vencido'
		);
	}

	/** @test */
	public function deadline_in_utc_matches_naive_comparison(): void {
		\atora_test_set_timezone( 'UTC' );

		$course_id = 1;
		$lesson_id = 51;
		atora_test_set_course_lessons( $course_id, array( $lesson_id ) );
		atora_test_set_post_meta( $lesson_id, '_clms_due_date', '2026-03-10' );
		atora_test_set_post_meta( $lesson_id, '_clms_due_time', '12:00' );

		$service = new \ATORA\EarlyWarning\Early_Warning_Service();

		$before = gmmktime( 11, 0, 0, 3, 10, 2026 );
		$after  = gmmktime( 13, 0, 0, 3, 10, 2026 );

		$this->assertEmpty( $this->call_get_deadline_lessons( $service, $course_id, $before ) );
		$this->assertNotEmpty( $this->call_get_deadline_lessons( $service, $course_id, $after ) );
	}
}
}
