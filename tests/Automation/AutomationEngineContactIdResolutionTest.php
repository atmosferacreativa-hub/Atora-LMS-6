<?php
/**
 * Automation_Engine — resolución de contact_id real (Bloque 4, 6.34.0).
 *
 * Regresión: el motor de automatización dependía de un método inexistente
 * (get_contact_id_by_user), escondido por method_exists(), así que acciones
 * basadas en contact_id (p.ej. mover etapas de pipeline) no hacían nada.
 *
 * @package ATORA_LMS\Tests\Automation
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Automation;

use ATORA\Automation\Automation_Engine;
use PHPUnit\Framework\TestCase;

class AutomationEngineContactIdResolutionTest extends TestCase {
	private ?object $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		atora_test_reset_users();
	}

	protected function tearDown(): void {
		atora_test_reset_users();
		global $wpdb;
		$wpdb = $this->original_wpdb;
		parent::tearDown();
	}

	private function install_wpdb_stub(): object {
		global $wpdb;
		$this->original_wpdb = $wpdb;

		$wpdb = new class {
			public string $prefix = 'wp_';
			public array $updates = array();
			private int $next_contact_id = 100;
			/** @var array<int,int> */
			private array $contacts_by_user = array();

			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }

			public function get_var( $sql ) {
				if ( false !== strpos( (string) $sql, 'SHOW TABLES LIKE' ) ) {
					if ( false !== strpos( (string) $sql, 'atora_contacts' ) ) {
						return $this->prefix . 'atora_contacts';
					}
					// Para este test no necesitamos el resto de tablas; devolver null
					// evita que write_execution_log() intente insertar.
					return null;
				}
				return null;
			}

			public function get_row( $sql, $output = OBJECT ) {
				$sql = (string) $sql;
				if ( false !== strpos( $sql, $this->prefix . 'atora_contacts' )
					&& preg_match( '/user_id = (\d+)/', $sql, $m ) ) {
					$user_id = (int) $m[1];
					if ( isset( $this->contacts_by_user[ $user_id ] ) ) {
						return (object) array(
							'id'      => $this->contacts_by_user[ $user_id ],
							'user_id' => $user_id,
						);
					}
					return null;
				}
				return null;
			}

			public function insert( $table, $data, $format = null ): int {
				if ( (string) $table === $this->prefix . 'atora_contacts' ) {
					$user_id = (int) ( $data['user_id'] ?? 0 );
					if ( $user_id > 0 && ! isset( $this->contacts_by_user[ $user_id ] ) ) {
						$this->contacts_by_user[ $user_id ] = $this->next_contact_id++;
					}
					return 1;
				}
				return 1;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->updates[] = array(
					'table' => (string) $table,
					'data'  => (array) $data,
					'where' => (array) $where,
				);
				return 1;
			}

			public function get_results( $sql, $output = OBJECT ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function query( $sql ) { return 1; }
		};

		return $this->original_wpdb;
	}

	/** @test */
	public function test_move_pipeline_stage_resolves_contact_id_and_updates_deal(): void {
		$this->install_wpdb_stub();
		atora_test_set_user( 50, array( 'user_email' => 'student50@example.test', 'display_name' => 'Student 50' ) );

		$row = (object) array(
			'id'          => 1,
			'user_id'     => 50,
			'retry_count' => 0,
			'action_data' => wp_json_encode( array(
				'type'     => 'move_pipeline_stage',
				'pipeline' => 'sales',
				'stage'    => 'qualified',
			) ),
			'context'     => wp_json_encode( array() ),
			'action_index'=> 0,
			'automation_id' => 0,
		);

		$ref = new \ReflectionMethod( Automation_Engine::class, 'execute_action' );
		$ref->setAccessible( true );
		$ref->invoke( null, $row );

		global $wpdb;
		$this->assertIsObject( $wpdb );
		$this->assertIsArray( $wpdb->updates );

		$deal_updates = array_values( array_filter( $wpdb->updates, static function( array $update ): bool {
			return str_ends_with( $update['table'], 'atora_crm_deals' );
		} ) );

		$this->assertNotEmpty( $deal_updates, 'se esperaba un update real a atora_crm_deals (la automatización no debe quedar en no-op)' );
		$this->assertSame( 'qualified', $deal_updates[0]['data']['stage'] ?? null );
		$this->assertSame( 100, $deal_updates[0]['where']['contact_id'] ?? null, 'contact_id debería resolverse desde el usuario (creando un contacto mínimo si faltaba)' );
	}
}

