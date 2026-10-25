<?php

declare( strict_types = 1 );

final class ScormE2ESeedTest extends WP_UnitTestCase {
	public function test_seed_creates_scorm_resources_and_exports_them(): void {
		$out = ATORA_E2E_Seed_CLI::seed( '', 'atora-e2e-2026' );

		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'lessons', $out );
		$this->assertArrayHasKey( 'scorm', $out['lessons'] );
		$this->assertGreaterThan( 0, (int) $out['lessons']['scorm'] );

		$lesson_id = (int) $out['lessons']['scorm'];
		$resources = get_post_meta( $lesson_id, '_clms_lesson_resources', true );
		$this->assertIsArray( $resources );

		$by_title = array();
		foreach ( $resources as $resource ) {
			if ( ! is_array( $resource ) ) {
				continue;
			}
			$title = (string) ( $resource['title'] ?? '' );
			if ( '' === $title ) {
				continue;
			}
			$by_title[ $title ] = $resource;
		}

		$this->assertArrayHasKey( 'SCORM mínimo', $by_title );
		$this->assertArrayHasKey( 'SCORM XHR', $by_title );

		foreach ( array( 'SCORM mínimo', 'SCORM XHR' ) as $title ) {
			$resource = $by_title[ $title ];
			$this->assertSame( 'scorm', (string) ( $resource['type'] ?? '' ) );
			$this->assertGreaterThan( 0, (int) ( $resource['library_item_id'] ?? 0 ) );
			$this->assertGreaterThan( 0, (int) ( $resource['library_version_id'] ?? 0 ) );

			global $wpdb;
			$item_id = (int) $resource['library_item_id'];
			$version_id = (int) $resource['library_version_id'];

			$item = (array) $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_items WHERE id = %d LIMIT 1", $item_id ),
				ARRAY_A
			);
			$this->assertNotEmpty( $item );
			$this->assertSame( 'published', (string) ( $item['status'] ?? '' ) );
			$this->assertSame( $version_id, (int) ( $item['current_version_id'] ?? 0 ) );

			$version = (array) $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}atora_library_versions WHERE id = %d AND item_id = %d LIMIT 1", $version_id, $item_id ),
				ARRAY_A
			);
			$this->assertNotEmpty( $version );
			$this->assertGreaterThan( 0, (int) ( $version['version_number'] ?? 0 ) );
		}

		$this->assertArrayHasKey( 'scorm_resources', $out );
		$this->assertIsArray( $out['scorm_resources'] );
		$this->assertCount( 2, $out['scorm_resources'] );
		$this->assertSame( 'SCORM mínimo', (string) ( $out['scorm_resources'][0]['title'] ?? '' ) );
		$this->assertSame( 'SCORM XHR', (string) ( $out['scorm_resources'][1]['title'] ?? '' ) );
		$this->assertGreaterThan( 0, (int) ( $out['scorm_resources'][0]['library_item_id'] ?? 0 ) );
		$this->assertGreaterThan( 0, (int) ( $out['scorm_resources'][1]['library_item_id'] ?? 0 ) );
	}
}

