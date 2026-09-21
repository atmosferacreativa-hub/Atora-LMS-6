<?php
/**
 * Runner aislado para Forms_Builder::handle_submit() que devuelve el JSON por stdout.
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../../bootstrap.php';

global $wpdb;
$wpdb = new class {
	public string $prefix = 'wp_';
	public function prepare( string $sql, ...$args ): string { return $sql; }
	public function query( $sql ) { return 1; }
	public function insert( $table, $data, $format = null ): int { return 1; }
	public function get_var( $sql ) { return 0; } // no throttled
	public function get_row( $sql, $output = 'ARRAY_A' ) { return null; }
	public function get_results( $sql, $output = 'ARRAY_A' ) { return array(); }
	public function get_col( $sql ) { return array(); }
	public function update( $table, $data, $where, $format = null, $where_format = null ) { return 1; }
	public function delete( $table, $where, $where_format = null ): int { return 1; }
	public function esc_like( string $s ): string { return $s; }
	public function get_charset_collate(): string { return ''; }
};

$form_id = 42;
atora_test_set_post_type( $form_id, 'atora_form' );

$schema = getenv( 'ATORA_TEST_SCHEMA' );
if ( false === $schema || '' === $schema ) {
	$schema = wp_json_encode( array( 'fields' => array() ) );
}
atora_test_set_post_meta( $form_id, 'atora_form_schema', $schema );

$fields_json = getenv( 'ATORA_TEST_FIELDS_JSON' );
$fields = array();
if ( false !== $fields_json && '' !== $fields_json ) {
	$decoded = json_decode( (string) $fields_json, true );
	$fields = is_array( $decoded ) ? $decoded : array();
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
$_POST = array(
	'form_id'          => (string) $form_id,
	'atora_form_nonce' => wp_create_nonce( 'atora_form_' . $form_id ),
	'fields'           => $fields,
);

\ATORA\Analytics\Forms_Builder::handle_submit();

