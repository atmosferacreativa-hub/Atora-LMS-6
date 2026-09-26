<?php

declare( strict_types = 1 );

// Shared only by the quiz REST tests. Defining this in tests/bootstrap.php
// prevents Patchwork from replacing wp_insert_post() in Classroom tests.
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $postarr, $wp_error = false ) {
		$GLOBALS['__atora_test_wp_insert_post_calls'] = (int) ( $GLOBALS['__atora_test_wp_insert_post_calls'] ?? 0 ) + 1;
		$GLOBALS['__atora_test_wp_insert_post_last']  = $postarr;
		if ( ! empty( $GLOBALS['__atora_test_wp_insert_post_fail'] ) ) {
			return new \WP_Error( 'wp_insert_post_failed', 'forced failure' );
		}
		$post_id = absint( $GLOBALS['__atora_test_wp_insert_post_id'] ?? 55555 );
		if ( $post_id && function_exists( 'atora_test_set_post' ) ) {
			atora_test_set_post(
				$post_id,
				array(
					'post_type'   => (string) ( $postarr['post_type'] ?? 'post' ),
					'post_status' => (string) ( $postarr['post_status'] ?? 'publish' ),
					'post_author' => absint( $postarr['post_author'] ?? 0 ),
					'post_title'  => (string) ( $postarr['post_title'] ?? '' ),
				)
			);
		}
		return $post_id;
	}
}
