<?php
/**
 * Microsoft — feed iCal de Outlook (Microsoft_Outlook): el token de
 * suscripción es por-curso — un token válido para el curso A no debe dar
 * acceso al curso B.
 *
 * @package ATORA_LMS\Tests\Microsoft
 */

declare( strict_types = 1 );

namespace {
	require_once __DIR__ . '/../stubs/class-clms-helper-stub.php';
}

namespace ATORA\Tests\Microsoft {

use PHPUnit\Framework\TestCase;

final class IcalUrlScopeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/microsoft/class-microsoft-outlook.php';

		atora_test_reset_post_meta();
		$GLOBALS['__atora_test_current_user_id'] = 0;
		\atora_test_reset_clms_helper_stub();
	}

	protected function tearDown(): void {
		\atora_test_reset_clms_helper_stub();
		parent::tearDown();
	}

	private function make_request( int $course_id, string $token ): \WP_REST_Request {
		$r = new \WP_REST_Request();
		$r->set_param( 'course_id', $course_id );
		$r->set_param( 'token', $token );
		return $r;
	}

	/** @test */
	public function correct_token_for_a_course_grants_access(): void {
		atora_test_set_post_meta( 100, '_atora_outlook_ical_token', 'secret-100' );

		$this->assertTrue(
			\ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 100, 'secret-100' ) )
		);
	}

	/**
	 * El caso central de S4.8: un token válido para el curso 100 no debe
	 * dar acceso al curso 200, aunque coincidan por accidente o por
	 * manipulación del parámetro course_id en la URL.
	 */
	public function test_token_for_one_course_does_not_grant_access_to_a_different_course(): void {
		atora_test_set_post_meta( 100, '_atora_outlook_ical_token', 'secret-100' );
		atora_test_set_post_meta( 200, '_atora_outlook_ical_token', 'secret-200' );

		$this->assertFalse(
			\ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 200, 'secret-100' ) ),
			'un token válido para el curso 100 no debe funcionar contra el curso 200'
		);
	}

	/** @test */
	public function wrong_token_is_rejected_even_for_a_logged_out_user(): void {
		atora_test_set_post_meta( 100, '_atora_outlook_ical_token', 'secret-100' );

		$this->assertFalse(
			\ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 100, 'wrong-token' ) )
		);
	}

	/** @test */
	public function logged_in_manager_can_access_without_a_token_match(): void {
		$GLOBALS['__atora_test_current_user_id'] = 5;
		atora_test_set_post_meta( 100, '_atora_outlook_ical_token', 'secret-100' );
		atora_test_set_can_manage_course( true );

		$this->assertTrue(
			\ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 100, 'wrong-token-but-logged-in-manager' ) )
		);
	}

	/** @test */
	public function logged_out_user_without_matching_token_is_denied(): void {
		$GLOBALS['__atora_test_current_user_id'] = 0;
		atora_test_set_post_meta( 100, '_atora_outlook_ical_token', 'secret-100' );
		atora_test_set_can_manage_course( true ); // aunque el flag esté en true, no hay sesión

		$this->assertFalse(
			\ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 100, 'wrong-token' ) )
		);
	}

	/** @test */
	public function missing_course_id_or_token_is_rejected(): void {
		$this->assertFalse( \ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 0, 'x' ) ) );
		$this->assertFalse( \ATORA\Microsoft\Microsoft_Outlook::rest_can_access_ical( $this->make_request( 100, '' ) ) );
	}
}
}
