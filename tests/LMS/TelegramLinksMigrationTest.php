<?php
/**
 * V5_Installer::migrate_telegram_links_from_usermeta() — backfill de
 * atora_telegram_links — PT-5 (sprint 6.5.7), sin ganador arbitrario
 * en PT-4 (sprint 6.5.8).
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class TelegramLinksMigrationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_options();
	}

	protected function tearDown(): void {
		\atora_test_reset_options();
		parent::tearDown();
	}

	/**
	 * $wpdb con filas de usermeta simuladas (posiblemente con un
	 * conflicto: dos usuarios apuntando al mismo chat_id, heredado del
	 * modelo anterior) y una tabla atora_telegram_links en memoria.
	 */
	private function install_wpdb_fixture( array $usermeta_rows ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $usermeta_rows ) {
			public string $prefix   = 'wp_';
			public string $usermeta = 'wp_usermeta';
			public array  $links    = array(); // id => array(user_id, chat_id)
			private int   $next_id  = 1;
			private array $usermeta_rows;
			public array  $error_logs = array();

			public function __construct( array $usermeta_rows ) {
				$this->usermeta_rows = $usermeta_rows;
			}

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']+)'/", $sql, $m ) ) {
					return $m[1];
				}
				if ( false !== strpos( $sql, 'SELECT user_id FROM' ) && false !== strpos( $sql, 'atora_telegram_links' ) ) {
					if ( preg_match( "/user_id = (\d+)/", $sql, $m ) ) {
						foreach ( $this->links as $row ) {
							if ( (int) $row['user_id'] === (int) $m[1] ) { return $row['user_id']; }
						}
						return null;
					}
					if ( preg_match( "/chat_id = '([^']*)'/", $sql, $m ) ) {
						foreach ( $this->links as $row ) {
							if ( $row['chat_id'] === $m[1] ) { return $row['user_id']; }
						}
						return null;
					}
				}
				return null;
			}

			public function get_results( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'wp_usermeta' ) ) {
					return $this->usermeta_rows;
				}
				return array();
			}

			public function insert( $table, $data, $format = null ): int {
				if ( false !== strpos( (string) $table, 'atora_telegram_links' ) ) {
					$this->links[ $this->next_id++ ] = $data;
				}
				return 1;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_col( $sql ) { return array(); }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	private function invoke_migration(): bool {
		$ref = new \ReflectionMethod( \ATORA\V5_Installer::class, 'migrate_telegram_links_from_usermeta' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null );
	}

	/** @test */
	public function test_backfills_unambiguous_links(): void {
		$original = $this->install_wpdb_fixture( array(
			array( 'user_id' => 5, 'chat_id' => '111' ),
			array( 'user_id' => 6, 'chat_id' => '222' ),
		) );

		$ok = $this->invoke_migration();

		global $wpdb;
		$this->assertTrue( $ok );
		$this->assertCount( 2, $wpdb->links );

		$this->restore_wpdb( $original );
	}

	/**
	 * Caso confirmado del hallazgo (6.5.8, PT-4): dos usuarios
	 * distintos con el MISMO chat_id en usermeta (posible bajo el
	 * modelo anterior) — NINGUNO de los dos recibe ownership
	 * automático, ni siquiera "el primero" (la versión de 6.5.7 sí lo
	 * hacía, dependiendo del orden no garantizado de la consulta).
	 * Ambos quedan fuera de la tabla, disponibles en usermeta para
	 * revisión manual.
	 *
	 * @test
	 */
	public function test_ambiguous_shared_chat_id_migrates_neither_user(): void {
		$original = $this->install_wpdb_fixture( array(
			array( 'user_id' => 7, 'chat_id' => '999' ),
			array( 'user_id' => 8, 'chat_id' => '999' ), // mismo chat_id, otro usuario — dato ambiguo heredado.
		) );

		$this->invoke_migration();

		global $wpdb;
		$this->assertCount( 0, $wpdb->links, 'ningún usuario debe recibir ownership arbitrario sobre un chat_id ambiguo' );

		$report = get_option( 'atora_telegram_migration_report' );
		$this->assertSame( 2, $report['conflicts'] ?? null, 'ambas filas ambiguas deben contarse como conflicto en el reporte' );
		$this->assertSame( 0, $report['migrated'] ?? null );

		$this->restore_wpdb( $original );
	}

	/**
	 * Un tercer usuario, con un chat_id que NO es ambiguo, sí debe
	 * migrarse con normalidad aunque en la misma corrida haya otro par
	 * de filas en conflicto — el conflicto de un chat_id no debe
	 * bloquear la migración de los demás.
	 *
	 * @test
	 */
	public function test_unambiguous_link_migrates_even_when_another_pair_conflicts(): void {
		$original = $this->install_wpdb_fixture( array(
			array( 'user_id' => 7, 'chat_id' => '999' ),
			array( 'user_id' => 8, 'chat_id' => '999' ),
			array( 'user_id' => 10, 'chat_id' => '555' ),
		) );

		$this->invoke_migration();

		global $wpdb;
		$this->assertCount( 1, $wpdb->links );
		$this->assertSame( 10, $wpdb->links[1]['user_id'] ?? null );

		$report = get_option( 'atora_telegram_migration_report' );
		$this->assertSame( 1, $report['migrated'] ?? null );
		$this->assertSame( 2, $report['conflicts'] ?? null );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_already_migrated_user_is_not_reprocessed(): void {
		$original = $this->install_wpdb_fixture( array(
			array( 'user_id' => 9, 'chat_id' => '333' ),
		) );

		global $wpdb;
		$wpdb->links[1] = array( 'user_id' => 9, 'chat_id' => '333' ); // ya migrado en una corrida anterior.

		$this->invoke_migration();

		$this->assertCount( 1, $wpdb->links, 'no debe duplicar una fila ya migrada' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_returns_false_if_table_does_not_exist(): void {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public function prepare( string $sql, ...$args ): string { return $sql; }
			public function get_var( $sql ) { return null; } // SHOW TABLES nunca encuentra la tabla.
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		$ok = $this->invoke_migration();
		$this->assertFalse( $ok );

		$wpdb = $original;
	}
}
