<?php

declare( strict_types = 1 );

final class MultisiteUnsupportedTest extends WP_UnitTestCase {
	public function test_network_activation_is_rejected_on_multisite(): void {
		add_filter( 'atora_lms_is_multisite', static fn() => true );

		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ): void {
					throw new RuntimeException( is_string( $message ) ? $message : 'wp_die' );
				};
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Multisite/i' );
		atora_lms_activate( true );
	}
}

