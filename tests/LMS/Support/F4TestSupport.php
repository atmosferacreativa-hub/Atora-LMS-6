<?php
/**
 * Shared test doubles for LMS cutover F4 tests.
 *
 * @package ATORA_LMS\Tests\LMS
 */
declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

use PHPUnit\Framework\TestCase;

class FakeWpdbF4 {
	public string $prefix = 'wp_';
	public array $get_var_queue     = array();
	public array $get_row_queue     = array();
	public array $get_results_queue = array();
	public array $get_col_queue     = array();
	public array $executed          = array();

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return preg_replace_callback( '/%[ds]/', function() use ( &$i, $args ) {
			return isset( $args[ $i ] ) ? (string) $args[ $i++ ] : '?';
		}, $sql );
	}
	public function get_var( $sql ) {
		$this->executed[] = $sql;
		return array_shift( $this->get_var_queue );
	}
	public function get_row( $sql, $output = OBJECT ) {
		$this->executed[] = $sql;
		return array_shift( $this->get_row_queue );
	}
	public function get_results( $sql, $output = OBJECT ) {
		$this->executed[] = $sql;
		$next = array_shift( $this->get_results_queue );
		return null === $next ? array() : $next;
	}
	public function get_col( $sql ) {
		$this->executed[] = $sql;
		$next = array_shift( $this->get_col_queue );
		return null === $next ? array() : $next;
	}
	public function insert( ...$a ) { return 1; }
	public function update( ...$a ) { return 1; }
	public function delete( ...$a ) { return 1; }
	public function query( $sql ) { $this->executed[] = $sql; return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
}

abstract class WpdbSwapTestCase extends TestCase {
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->original_wpdb = $wpdb;
		atora_test_reset_options();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		atora_test_reset_options();
		parent::tearDown();
	}

	protected function swap_wpdb(): FakeWpdbF4 {
		global $wpdb;
		$fake  = new FakeWpdbF4();
		$wpdb  = $fake;
		return $fake;
	}
}
