<?php
/**
 * CLMS_Enrollment_Manager_Access_Enrollment_Trait::redeem_access_link() —
 * fuerza bruta contra la contraseña del enlace de acceso — PT-3 (sprint 6.5.5).
 *
 * Hallazgo confirmado: wp_check_password() se usaba correctamente,
 * pero sin ningún límite de intentos — un usuario autenticado con el
 * link/token podía probar contraseñas indefinidamente hasta acertar.
 * Se agrega un contador por usuario+token (nunca solo por IP): 5
 * intentos fallidos por 15 minutos, reseteado en el primer acierto.
 *
 * @package ATORA_LMS\Tests\Enrollment
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Enrollment;

use PHPUnit\Framework\TestCase;

/**
 * Doble de prueba: compone solo el trait bajo test, evitando la
 * dependencia de ensure_db()/install_db() de las otras traits de
 * CLMS_Enrollment_Manager (no relevantes para esta lógica).
 */
class Test_Access_Link_Host {
	use \CLMS_Enrollment_Manager_Access_Enrollment_Trait;

	const LINK_FREE     = 'free';
	const LINK_PASSWORD = 'password';
	const LINK_REGISTER = 'register';

	public function table() { return 'wp_test_access_links'; }
}

class AccessLinkPasswordThrottleTest extends TestCase {

	/**
	 * $wpdb en memoria: una sola fila de enlace de tipo password, con
	 * un hash conocido (via el wp_check_password() stub del bootstrap).
	 */
	private function install_wpdb_fixture( string $correct_password ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $correct_password ) {
			public string $prefix = 'wp_';
			public object $row;

			public function __construct( string $correct_password ) {
				$this->row = (object) array(
					'id'              => 1,
					'token'           => 'tok-abc',
					'course_id'       => 7,
					'access_mode'     => 'password',
					'access_password' => wp_hash_password( $correct_password ),
					'used_count'      => 0,
				);
			}

			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function get_row( $sql, $output = 'ARRAY_A' ) { return $this->row; }
			public function get_var( $sql ) { return null; }
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ) { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_transients();
	}

	protected function tearDown(): void {
		atora_test_reset_transients();
		parent::tearDown();
	}

	/** @test */
	public function test_first_five_wrong_attempts_are_allowed_through_to_the_password_check(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$result = $host->redeem_access_link( 'tok-abc', 100, 'wrong-guess-' . $i );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'wrong_password', $result->get_error_code(), "intento {$i} debe evaluarse como contraseña incorrecta, no como bloqueo" );
		}

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_sixth_attempt_is_blocked_by_the_lockout(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$host->redeem_access_link( 'tok-abc', 101, 'wrong-guess-' . $i );
		}

		$result = $host->redeem_access_link( 'tok-abc', 101, 'wrong-guess-6' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'too_many_attempts', $result->get_error_code(), 'el 6º intento debe rechazarse por el límite, sin siquiera evaluar la contraseña' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_lockout_expires_after_the_ttl(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$host->redeem_access_link( 'tok-abc', 102, 'wrong-guess-' . $i );
		}
		$this->assertSame( 'too_many_attempts', $host->redeem_access_link( 'tok-abc', 102, 'wrong-again' )->get_error_code() );

		// Simula el vencimiento del TTL — el stub de transients de test
		// no expira solo por tiempo, así que se borra directo.
		delete_transient( 'clms_access_pw_attempts_102_' . md5( 'tok-abc' ) );

		$result = $host->redeem_access_link( 'tok-abc', 102, 'wrong-guess-again' );
		$this->assertSame( 'wrong_password', $result->get_error_code(), 'tras expirar el TTL, debe volver a evaluarse la contraseña normalmente' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_correct_password_resets_the_counter(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		$host->redeem_access_link( 'tok-abc', 103, 'wrong-guess-1' );
		$host->redeem_access_link( 'tok-abc', 103, 'wrong-guess-2' );

		// Acierto — CLMS_Helper no existe en el entorno de test, así que
		// la matrícula en sí falla más adelante en el flujo, pero el
		// contador de intentos ya debe haberse limpiado antes de eso.
		$host->redeem_access_link( 'tok-abc', 103, 'correct-horse-battery-staple' );

		$lock_key = 'clms_access_pw_attempts_103_' . md5( 'tok-abc' );
		$this->assertFalse( get_transient( $lock_key ), 'un acierto debe limpiar el contador de intentos fallidos' );

		// Y por lo tanto vuelve a tener las 5 oportunidades completas.
		for ( $i = 1; $i <= 5; $i++ ) {
			$result = $host->redeem_access_link( 'tok-abc', 103, 'wrong-again-' . $i );
			$this->assertSame( 'wrong_password', $result->get_error_code() );
		}

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_user_a_lockout_does_not_affect_user_b(): void {
		$original = $this->install_wpdb_fixture( 'correct-horse-battery-staple' );
		$host = new Test_Access_Link_Host();

		for ( $i = 1; $i <= 5; $i++ ) {
			$host->redeem_access_link( 'tok-abc', 201, 'wrong-guess-' . $i );
		}
		$this->assertSame( 'too_many_attempts', $host->redeem_access_link( 'tok-abc', 201, 'x' )->get_error_code() );

		// Usuario B, mismo token, primer intento — no debe heredar el bloqueo de A.
		$result_b = $host->redeem_access_link( 'tok-abc', 202, 'wrong-guess-b' );
		$this->assertSame( 'wrong_password', $result_b->get_error_code(), 'el bloqueo de un usuario no debe afectar a otro usuario distinto' );

		$this->restore_wpdb( $original );
	}
}
