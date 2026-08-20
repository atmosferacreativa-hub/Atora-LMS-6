<?php
/**
 * CLMS_Metabox_Course_Enrollment_Ajax_Trait::ajax_unenroll_user() —
 * verificación de dueño del curso — PT-9 (sprint 6.5.5, hallazgo del
 * barrido de auditoría P8/P9).
 *
 * Hallazgo confirmado: ajax_enroll_user()/ajax_unenroll_user() (y sus
 * equivalentes de programa) solo exigían la capability genérica
 * clms_manage_courses — sin verificar que el curso perteneciera al
 * instructor que hace la solicitud. Cualquier instructor podía
 * matricular/desmatricular usuarios en/de un curso ajeno.
 *
 * ajax_unenroll_user() termina en exit() — cada escenario corre en un
 * proceso PHP aparte vía proc_open() sobre
 * fixtures/run-unenroll-ajax.php.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class EnrollmentAjaxOwnershipTest extends TestCase {

	private ?string $out = null;

	protected function tearDown(): void {
		if ( $this->out && file_exists( $this->out ) ) {
			@unlink( $this->out );
		}
		$this->out = null;
		parent::tearDown();
	}

	private function run_scenario( array $env ): ?array {
		$this->out = sys_get_temp_dir() . '/atora_test_unenroll_' . bin2hex( random_bytes( 8 ) ) . '.json';
		@unlink( $this->out );

		$php_bin = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$script  = __DIR__ . '/fixtures/run-unenroll-ajax.php';

		$full_env = array_merge( $_ENV ?? array(), array( 'ATORA_TEST_OUT' => $this->out ), $env );

		$process = proc_open(
			array( $php_bin, $script ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes,
			null,
			$full_env
		);

		if ( ! is_resource( $process ) ) {
			$this->fail( 'no se pudo lanzar el proceso PHP hijo' );
		}

		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		if ( ! file_exists( $this->out ) ) {
			return null;
		}

		$decoded = json_decode( (string) file_get_contents( $this->out ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Caso confirmado del hallazgo: un instructor con clms_manage_courses
	 * pero SIN ser dueño del curso no debe poder desmatricular a nadie
	 * de él.
	 *
	 * @test
	 */
	public function test_non_owning_instructor_cannot_unenroll(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_COURSE_ID'    => '50',
			'ATORA_TEST_OWNER_ID'     => '2',
			'ATORA_TEST_CURRENT_USER' => '3', // instructor distinto del dueño (2).
			'ATORA_TEST_USER_ID'      => '99',
		) );

		$this->assertNotNull( $result );
		$this->assertFalse( $result['meta_written'] ?? true, 'no debe escribirse ningún cambio de matrícula sobre un curso ajeno' );
	}

	/** @test */
	public function test_owning_instructor_can_unenroll(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_COURSE_ID'    => '51',
			'ATORA_TEST_OWNER_ID'     => '4',
			'ATORA_TEST_CURRENT_USER' => '4', // dueño real.
			'ATORA_TEST_USER_ID'      => '99',
		) );

		$this->assertNotNull( $result );
		$this->assertTrue( $result['meta_written'] ?? false, 'el dueño del curso sí debe poder desmatricular' );
	}

	/** @test */
	public function test_edit_others_lm_courses_can_unenroll_regardless_of_ownership(): void {
		$result = $this->run_scenario( array(
			'ATORA_TEST_COURSE_ID'    => '52',
			'ATORA_TEST_OWNER_ID'     => '2',
			'ATORA_TEST_CURRENT_USER' => '5',
			'ATORA_TEST_USER_ID'      => '99',
			'ATORA_TEST_EXTRA_CAP'    => 'edit_others_lm_courses',
		) );

		$this->assertNotNull( $result );
		$this->assertTrue( $result['meta_written'] ?? false );
	}
}
