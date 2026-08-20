<?php
/**
 * V5_Installer — wp_post_id nullable en cursos/lecciones/programas,
 * verificación antes de marcar el esquema como actualizado — PT-6 (sprint 6.5.4).
 *
 * Hallazgo confirmado: atora_lessons y atora_programs comparten
 * exactamente el mismo defecto de esquema que atora_courses (6.5.3).
 * atora_quiz_submissions NO — verificado que su wp_post_id vincula
 * con el CPT legado clms_submission del que siempre se migra, sin
 * ningún punto de escritura nativa hoy.
 *
 * También cubre el hallazgo adicional encontrado al re-analizar la
 * versión de 6.5.3: migrate_course_wp_post_id_nullable() (nombre
 * anterior) era void — no verificaba el resultado del ALTER antes de
 * que install()/force_install() marcaran el esquema como completo.
 * La versión de este sprint (migrate_wp_post_id_nullable_columns())
 * sí lo hace.
 *
 * @package ATORA_LMS\Tests\LMS
 */

declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class AllTablesWpPostIdNullableTest extends TestCase {

	/**
	 * $wpdb configurable: qué tablas "existen" y si SHOW COLUMNS
	 * reporta la columna como ya nullable — para simular tanto el
	 * caso feliz como un ALTER que no tuvo efecto real.
	 */
	private function install_wpdb_fixture( array $existing_tables, bool $column_becomes_nullable_after_alter ): object {
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class( $existing_tables, $column_becomes_nullable_after_alter ) {
			public string $prefix  = 'wp_';
			public array  $queries = array();
			private array $existing_tables;
			private bool  $becomes_nullable;
			private array $altered = array();

			public function __construct( array $existing_tables, bool $becomes_nullable ) {
				$this->existing_tables  = $existing_tables;
				$this->becomes_nullable = $becomes_nullable;
			}

			public function prepare( string $sql, ...$args ): string {
				// %s se sustituye entre comillas — real $wpdb->prepare()
				// las agrega automáticamente, necesario para que el
				// regex de SHOW TABLES LIKE/SHOW COLUMNS más abajo
				// pueda extraer el valor.
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}

			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']+)'/", $sql, $m ) ) {
					$table = $m[1];
					foreach ( $this->existing_tables as $suffix ) {
						if ( $table === $this->prefix . $suffix ) { return $table; }
					}
					return null;
				}
				return null;
			}

			public function get_row( $sql, $output = 'ARRAY_A' ) {
				if ( false !== strpos( $sql, 'SHOW COLUMNS FROM' ) && preg_match( '/SHOW COLUMNS FROM (\S+)/', $sql, $m ) ) {
					$table = $m[1];
					$is_nullable = $this->becomes_nullable && in_array( $table, $this->altered, true );
					return array( 'Field' => 'wp_post_id', 'Null' => $is_nullable ? 'YES' : 'NO' );
				}
				return null;
			}

			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ) {
				$this->queries[] = $sql;
				if ( false !== strpos( $sql, 'ALTER TABLE' ) && preg_match( '/ALTER TABLE (\S+)/', $sql, $m ) ) {
					$this->altered[] = $m[1];
				}
				return 1;
			}
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		return $original;
	}

	private function restore_wpdb( object $original ): void {
		global $wpdb;
		$wpdb = $original;
	}

	private function invoke_all(): bool {
		$ref = new \ReflectionMethod( \ATORA\V5_Installer::class, 'migrate_wp_post_id_nullable_columns' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null );
	}

	/** @test */
	public function test_migrates_lessons_and_programs_too(): void {
		$original = $this->install_wpdb_fixture( array( 'atora_courses', 'atora_lessons', 'atora_programs' ), true );

		$ok = $this->invoke_all();

		global $wpdb;
		$altered_tables = array();
		foreach ( $wpdb->queries as $q ) {
			if ( preg_match( '/ALTER TABLE (\S+)/', $q, $m ) ) { $altered_tables[] = $m[1]; }
		}

		$this->assertTrue( $ok );
		$this->assertContains( 'wp_atora_courses', $altered_tables );
		$this->assertContains( 'wp_atora_lessons', $altered_tables );
		$this->assertContains( 'wp_atora_programs', $altered_tables );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_does_not_touch_quiz_submissions(): void {
		// PT-6.3: wp_post_id en atora_quiz_submissions vincula con el
		// CPT legado clms_submission del que siempre se migra — no es
		// "contenido nativo opcionalmente sin CPT", no se toca.
		$original = $this->install_wpdb_fixture( array( 'atora_courses', 'atora_lessons', 'atora_programs', 'atora_quiz_submissions' ), true );

		$this->invoke_all();

		global $wpdb;
		foreach ( $wpdb->queries as $q ) {
			$this->assertStringNotContainsString( 'atora_quiz_submissions', $q, 'quiz_submissions no debe aparecer en ninguna consulta de esta migración' );
		}

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_returns_false_and_does_not_lie_about_success_when_alter_has_no_effect(): void {
		// PT-6.1: a diferencia de la versión de 6.5.3 (void, sin
		// verificar), esta debe devolver false si el ALTER no tuvo
		// efecto real — para que install()/force_install() NO marquen
		// el esquema como actualizado.
		$original = $this->install_wpdb_fixture( array( 'atora_courses', 'atora_lessons', 'atora_programs' ), false );

		$ok = $this->invoke_all();

		$this->assertFalse( $ok, 'si el ALTER no tuvo efecto real, no debe reportarse éxito' );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_returns_true_when_all_three_alters_succeed(): void {
		$original = $this->install_wpdb_fixture( array( 'atora_courses', 'atora_lessons', 'atora_programs' ), true );

		$ok = $this->invoke_all();

		$this->assertTrue( $ok );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_returns_false_if_any_table_is_missing(): void {
		// atora_programs no existe todavía en este escenario simulado.
		$original = $this->install_wpdb_fixture( array( 'atora_courses', 'atora_lessons' ), true );

		$ok = $this->invoke_all();

		$this->assertFalse( $ok );

		$this->restore_wpdb( $original );
	}

	/** @test */
	public function test_already_nullable_column_is_left_alone(): void {
		// Si SHOW COLUMNS ya reporta Null=YES desde el inicio, no debe
		// emitir ningún ALTER — ya está migrada (p.ej. install nuevo,
		// create_tables() ya la creó nullable).
		global $wpdb;
		$original = $wpdb;

		$wpdb = new class {
			public string $prefix  = 'wp_';
			public array  $queries = array();
			public function prepare( string $sql, ...$args ): string {
				$i = 0;
				return preg_replace_callback( '/%[ds]/', function( $m ) use ( &$i, $args ) {
					if ( ! isset( $args[ $i ] ) ) { return '?'; }
					$value = $args[ $i++ ];
					return '%s' === $m[0] ? "'" . $value . "'" : (string) $value;
				}, $sql );
			}
			public function get_var( $sql ) {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']+)'/", $sql, $m ) ) { return $m[1]; }
				return null;
			}
			public function get_row( $sql, $output = 'ARRAY_A' ) {
				return array( 'Field' => 'wp_post_id', 'Null' => 'YES' );
			}
			public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
			public function get_col( $sql ) { return array(); }
			public function insert( $table, $data, $format = null ): int { return 1; }
			public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
			public function delete( $table, $where, $where_format = null ): int { return 1; }
			public function query( $sql ) { $this->queries[] = $sql; return 1; }
			public function esc_like( string $s ): string { return $s; }
			public function get_charset_collate(): string { return ''; }
		};

		$ref = new \ReflectionMethod( \ATORA\V5_Installer::class, 'migrate_wp_post_id_nullable_columns' );
		$ref->setAccessible( true );
		$ok = (bool) $ref->invoke( null );

		$this->assertTrue( $ok );
		$this->assertSame( array(), $wpdb->queries, 'ya nullable — no debe emitir ningún ALTER/UPDATE' );

		$wpdb = $original;
	}
}
