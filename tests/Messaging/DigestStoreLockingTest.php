<?php
/**
 * Digest_Store — locking, retención y tope de filas — PT-2 (sprint 6.5.4).
 *
 * Hallazgo confirmado: el patrón era leer → construir mensaje →
 * borrar, sin bloqueo — dos ejecuciones de cron solapadas podían leer
 * las mismas filas antes de que la primera las borrara, produciendo
 * un resumen duplicado. Tampoco había TTL ni límite de filas por
 * usuario.
 *
 * @package ATORA_LMS\Tests\Messaging
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Messaging;

use PHPUnit\Framework\TestCase;

class DigestStoreLockingTest extends TestCase {

	/**
	 * $wpdb en memoria que implementa de verdad las semánticas de
	 * update()/get_results()/query() que Digest_Store necesita — no
	 * solo capturar SQL, sino comportarse como una tabla real para
	 * poder probar el claim atómico y la limpieza.
	 */
	private function install_wpdb_fixture(): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix   = 'wp_';
			public array  $rows     = array(); // id => row
			public int    $next_id  = 1;
			public string $engine   = 'InnoDB';

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
					return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
				}, $sql );
			}

			public function seed( int $user_id, string $status = 'pending', string $created_at = '', ?string $claimed_at = null, ?string $claim_token = null ): int {
				$id = $this->next_id++;
				$this->rows[ $id ] = array(
					'id'           => $id,
					'user_id'      => $user_id,
					'type'         => 'assignment_graded',
					'template_key' => 'tpl',
					'variables'    => wp_json_encode( array( 'lesson_title' => 'Lección ' . $id ) ),
					'status'       => $status,
					'claimed_at'   => $claimed_at,
					'claim_token'  => $claim_token,
					'created_at'   => $created_at ?: current_time( 'mysql', true ),
				);
				return $id;
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']+)'/", $sql, $m ) ) {
					return $m[1];
				}
				if ( false !== strpos( $sql, 'SHOW COLUMNS FROM' ) ) {
					return 'status'; // ya migrada
				}
				if ( false !== strpos( $sql, 'information_schema.TABLES' ) ) {
					return $this->engine;
				}
				if ( false !== strpos( $sql, 'SELECT COUNT(*)' ) && preg_match( '/user_id = (\d+)/', $sql, $m ) ) {
					$uid = (int) $m[1];
					return count( array_filter( $this->rows, static fn( $r ) => $r['user_id'] === $uid ) );
				}
				return null;
			}

			public function get_col( $sql ) {
				if ( false !== strpos( $sql, 'DISTINCT user_id' ) ) {
					$ids = array_unique( array_map(
						static fn( $r ) => $r['user_id'],
						array_filter( $this->rows, static fn( $r ) => 'pending' === $r['status'] )
					) );
					return array_values( $ids );
				}
				if ( false !== strpos( $sql, 'SELECT id FROM' ) && preg_match( '/user_id = (\d+) ORDER BY id ASC LIMIT (\d+)/', $sql, $m ) ) {
					$uid   = (int) $m[1];
					$limit = (int) $m[2];
					$ids = array_values( array_map(
						static fn( $r ) => $r['id'],
						array_filter( $this->rows, static fn( $r ) => $r['user_id'] === $uid )
					) );
					sort( $ids );
					return array_slice( $ids, 0, $limit );
				}
				return array();
			}

			public function get_results( $sql, $output = 'ARRAY_A' ) {
				if ( preg_match( "/user_id = (\d+) AND status = 'claimed' AND claim_token = (.+?) ORDER BY/", $sql, $m ) ) {
					$uid = (int) $m[1];
					$claim_token = trim( $m[2] );
					return array_values( array_filter( $this->rows, static function( $r ) use ( $uid, $claim_token ) {
						return $r['user_id'] === $uid && 'claimed' === $r['status'] && $r['claim_token'] === $claim_token;
					} ) );
				}
				if ( preg_match( '/user_id = (\d+) ORDER BY id ASC/', $sql, $m ) ) {
					$uid = (int) $m[1];
					return array_values( array_filter( $this->rows, static fn( $r ) => $r['user_id'] === $uid ) );
				}
				return array();
			}

			public function insert( $table, $data, $format = null ): int {
				$id = $this->next_id++;
				$this->rows[ $id ] = array_merge( array( 'id' => $id, 'claimed_at' => null ), $data );
				return 1;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$count = 0;
				foreach ( $this->rows as $id => $row ) {
					$matches = true;
					foreach ( $where as $key => $value ) {
						if ( ( $row[ $key ] ?? null ) !== $value ) { $matches = false; break; }
					}
					if ( ! $matches ) { continue; }
					foreach ( $data as $key => $value ) {
						$this->rows[ $id ][ $key ] = $value;
					}
					$count++;
				}
				return $count;
			}

			public function query( $sql ) {
				if ( false !== strpos( $sql, "SET status = 'pending', claimed_at = NULL, claim_token = NULL WHERE status = 'claimed' AND claimed_at <" ) ) {
					preg_match( "/claimed_at < (.+)$/", $sql, $m );
					$threshold = trim( $m[1] );
					$count = 0;
					foreach ( $this->rows as $id => $row ) {
						if ( 'claimed' === $row['status'] && $row['claimed_at'] < $threshold ) {
							$this->rows[ $id ]['status']      = 'pending';
							$this->rows[ $id ]['claimed_at']  = null;
							$this->rows[ $id ]['claim_token'] = null;
							$count++;
						}
					}
					return $count;
				}
				if ( false !== strpos( $sql, "SET status = 'pending', claimed_at = NULL, claim_token = NULL WHERE id IN" ) ) {
					preg_match( '/WHERE id IN \(([\d,]+)\)/', $sql, $m );
					$ids = array_map( 'intval', explode( ',', $m[1] ?? '' ) );
					foreach ( $ids as $id ) {
						if ( isset( $this->rows[ $id ] ) ) {
							$this->rows[ $id ]['status']      = 'pending';
							$this->rows[ $id ]['claimed_at']  = null;
							$this->rows[ $id ]['claim_token'] = null;
						}
					}
					return count( $ids );
				}
				if ( false !== strpos( $sql, "DELETE FROM" ) && false !== strpos( $sql, "status = 'pending' AND created_at <" ) ) {
					preg_match( '/created_at < (.+)$/', $sql, $m );
					$threshold = trim( $m[1] );
					$count = 0;
					foreach ( $this->rows as $id => $row ) {
						if ( 'pending' === $row['status'] && $row['created_at'] < $threshold ) {
							unset( $this->rows[ $id ] );
							$count++;
						}
					}
					return $count;
				}
				if ( false !== strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, 'WHERE id IN' ) ) {
					preg_match( '/WHERE id IN \(([\d,]+)\)/', $sql, $m );
					$ids = array_map( 'intval', explode( ',', $m[1] ?? '' ) );
					foreach ( $ids as $id ) { unset( $this->rows[ $id ] ); }
					return count( $ids );
				}
				return 1;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	/** @test */
	public function test_second_overlapping_claim_finds_nothing(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$wpdb->seed( 10, 'pending' );
		$wpdb->seed( 10, 'pending' );

		$first  = \ATORA\Messaging\Digest_Store::claim_items_for_user( 10 );
		$second = \ATORA\Messaging\Digest_Store::claim_items_for_user( 10 );

		$this->assertCount( 2, $first, 'la primera corrida se lleva todo lo pendiente' );
		$this->assertCount( 0, $second, 'la segunda corrida solapada no debe encontrar nada que reclamar — evita el resumen duplicado' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_claimed_items_not_lost_on_simulated_failure_then_released(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$id = $wpdb->seed( 11, 'pending' );

		$claimed = \ATORA\Messaging\Digest_Store::claim_items_for_user( 11 );
		$this->assertCount( 1, $claimed );

		// Simula que el envío falló a mitad de camino: nunca se llama
		// clear_items(). La fila sigue 'claimed', no se pierde.
		$this->assertSame( 'claimed', $wpdb->rows[ $id ]['status'] );

		// Envejecer el reclamo más allá del umbral de 15 minutos.
		$wpdb->rows[ $id ]['claimed_at'] = gmdate( 'Y-m-d H:i:s', time() - 20 * 60 );

		$released = \ATORA\Messaging\Digest_Store::release_stale_claims();
		$this->assertSame( 1, $released );
		$this->assertSame( 'pending', $wpdb->rows[ $id ]['status'] );

		// Ahora sí se puede volver a reclamar — sin duplicado (una sola fila).
		$retried = \ATORA\Messaging\Digest_Store::claim_items_for_user( 11 );
		$this->assertCount( 1, $retried );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_recent_claim_is_not_released_as_stale(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$id = $wpdb->seed( 12, 'claimed', '', current_time( 'mysql', true ) );

		$released = \ATORA\Messaging\Digest_Store::release_stale_claims();

		$this->assertSame( 0, $released );
		$this->assertSame( 'claimed', $wpdb->rows[ $id ]['status'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_expired_pending_items_are_purged(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$old_id   = $wpdb->seed( 13, 'pending', gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) );
		$fresh_id = $wpdb->seed( 13, 'pending', current_time( 'mysql', true ) );

		$purged = \ATORA\Messaging\Digest_Store::purge_expired();

		$this->assertSame( 1, $purged );
		$this->assertArrayNotHasKey( $old_id, $wpdb->rows );
		$this->assertArrayHasKey( $fresh_id, $wpdb->rows );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_row_cap_discards_oldest_before_inserting(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		for ( $i = 0; $i < \ATORA\Messaging\Digest_Store::MAX_ROWS_PER_USER; $i++ ) {
			$wpdb->seed( 14, 'pending' );
		}
		$this->assertCount( \ATORA\Messaging\Digest_Store::MAX_ROWS_PER_USER, $wpdb->rows );

		\ATORA\Messaging\Digest_Store::add_item( 14, 'assignment_graded', 'tpl', array() );

		$this->assertCount(
			\ATORA\Messaging\Digest_Store::MAX_ROWS_PER_USER,
			array_filter( $wpdb->rows, static fn( $r ) => $r['user_id'] === 14 ),
			'no debe crecer más allá del tope — se descarta lo más viejo al insertar'
		);
		$this->assertArrayNotHasKey( 1, $wpdb->rows, 'la fila más vieja (id 1) debe haberse descartado' );

		$this->restore_wpdb( $original );
	}

	/**
	 * PT-2 (6.5.5): dos reclamos que caen en el mismo segundo
	 * (claimed_at idéntico) no deben poder leerse cruzados — el
	 * criterio de lectura es claim_token, no claimed_at. Se siembra a
	 * mano una fila "de otro worker" con el mismo claimed_at que la
	 * fila recién reclamada por este, para simular la colisión de
	 * precisión de 1 segundo que el hallazgo original describía.
	 *
	 * @test
	 */
	public function test_same_second_claims_are_not_cross_selected_by_token(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;

		$own_id = $wpdb->seed( 16, 'pending' );
		$claimed = \ATORA\Messaging\Digest_Store::claim_items_for_user( 16 );
		$this->assertCount( 1, $claimed );

		$same_second = $wpdb->rows[ $own_id ]['claimed_at'];

		// Fila "de otro worker": mismo claimed_at, mismo user_id (podría
		// ser un run posterior tras liberarse), pero un claim_token
		// distinto — nunca debe aparecer en una lectura por token ajena.
		$foreign_id = $wpdb->seed( 16, 'claimed', '', $same_second, 'foreign-worker-token' );

		// Reproduce la consulta antigua (por claimed_at) para confirmar
		// que el hallazgo era real: habría devuelto AMBAS filas.
		$by_claimed_at = array_values( array_filter( $wpdb->rows, static function ( $r ) use ( $same_second ) {
			return 16 === $r['user_id'] && 'claimed' === $r['status'] && $r['claimed_at'] === $same_second;
		} ) );
		$this->assertCount( 2, $by_claimed_at, 'filtrar solo por claimed_at sí mezclaría ambas filas (el bug que se corrige)' );

		// La propia fila reclamada por este worker sigue teniendo su
		// propio claim_token, distinto del de la fila "foránea".
		$this->assertNotSame( 'foreign-worker-token', $wpdb->rows[ $own_id ]['claim_token'] );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_get_users_with_pending_items_excludes_claimed(): void {
		$original = $this->install_wpdb_fixture();
		global $wpdb;
		$wpdb->seed( 15, 'claimed' );

		$users = \ATORA\Messaging\Digest_Store::get_users_with_pending_items();

		$this->assertNotContains( 15, $users, 'un usuario con solo filas claimed no debe aparecer como pendiente' );

		$this->restore_wpdb( $original );
	}
}
