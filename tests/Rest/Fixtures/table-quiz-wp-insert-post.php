<?php

declare( strict_types = 1 );

// Shared only by the quiz REST tests. Defining this in tests/bootstrap.php
// prevents Patchwork from replacing wp_insert_post() in Classroom tests.
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, $wp_error = false ) {
		if ( ! empty( $GLOBALS['__atora_test_wp_insert_post_fail'] ) ) {
			return new \WP_Error( 'wp_insert_post_failed', 'forced failure' );
		}
		return absint( $GLOBALS['__atora_test_wp_insert_post_id'] ?? 55555 );
	}
}
