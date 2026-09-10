<?php
/**
 * F4 — canonical read router.
 *
 * @package ATORA_LMS\Tests\LMS
 */
declare( strict_types = 1 );

namespace ATORA\Tests\LMS;

require_once __DIR__ . '/Support/F4TestSupport.php';

class ReadRouterTest extends WpdbSwapTestCase {

	/** @test */
	public function test_default_source_is_legacy(): void {
		$this->assertSame( 'legacy', \ATORA\LMS\LMS_Read_Router::source() );
	}

	/** @test */
	public function test_default_is_not_tables(): void {
		$this->assertFalse( \ATORA\LMS\LMS_Read_Router::is_tables() );
	}

	/** @test */
	public function test_set_source_tables_switches_is_tables(): void {
		\ATORA\LMS\LMS_Read_Router::set_source( 'tables' );
		$this->assertSame( 'tables', \ATORA\LMS\LMS_Read_Router::source() );
		$this->assertTrue( \ATORA\LMS\LMS_Read_Router::is_tables() );
	}

	/** @test */
	public function test_set_source_invalid_value_clamps_to_legacy(): void {
		\ATORA\LMS\LMS_Read_Router::set_source( 'bogus' );
		$this->assertSame( 'legacy', \ATORA\LMS\LMS_Read_Router::source() );
		$this->assertFalse( \ATORA\LMS\LMS_Read_Router::is_tables() );
	}

	/** @test */
	public function test_set_source_back_to_legacy(): void {
		\ATORA\LMS\LMS_Read_Router::set_source( 'tables' );
		\ATORA\LMS\LMS_Read_Router::set_source( 'legacy' );
		$this->assertSame( 'legacy', \ATORA\LMS\LMS_Read_Router::source() );
	}
}
