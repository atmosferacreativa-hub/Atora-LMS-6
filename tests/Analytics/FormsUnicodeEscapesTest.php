<?php
/**
 * Analytics — Forms_Builder: normaliza escapes unicode rotos ("u00e9")
 * tanto al guardar como al leer el schema.
 *
 * @package ATORA_LMS\Tests\Analytics
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Analytics;

use PHPUnit\Framework\TestCase;

final class FormsUnicodeEscapesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/../../modules/analytics/class-forms-builder.php';
	}

	/** @test */
	public function normalizes_broken_uXXXX_sequences_into_real_unicode(): void {
		$schema = array(
			'fields' => array(
				array(
					'type'        => 'text',
					'name'        => 'telefono',
					'label'       => 'Telu00e9fono',
					'placeholder' => 'Cuu00e9ntanos quu00e9 necesitas',
				),
			),
		);

		$normalized = \ATORA\Analytics\Forms_Builder::normalize_unicode_escapes_for_schema( $schema );

		$this->assertSame( 'Teléfono', $normalized['fields'][0]['label'] );
		$this->assertSame( 'Cuéntanos qué necesitas', $normalized['fields'][0]['placeholder'] );
	}
}

