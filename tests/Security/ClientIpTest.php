<?php
/**
 * ATORA_Client_IP — resolución de IP consciente de proxies confiables — PT-1 (sprint 6.5.5).
 *
 * Extraído de Student_Assistant::resolve_client_ip() (ya correcto) y
 * centralizado para reemplazar las varias implementaciones inseguras
 * que confiaban en X-Forwarded-For/Client-IP sin verificar el origen
 * (Forms_Builder, Extended_Registration).
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_CLIENT_IP'],
			$_SERVER['HTTP_CF_CONNECTING_IP'],
			$_SERVER['HTTP_X_REAL_IP'],
			$_SERVER['HTTP_X_FORWARDED']
		);
	}

	protected function tearDown(): void {
		unset(
			$_SERVER['REMOTE_ADDR'],
			$_SERVER['HTTP_X_FORWARDED_FOR'],
			$_SERVER['HTTP_CLIENT_IP'],
			$_SERVER['HTTP_CF_CONNECTING_IP'],
			$_SERVER['HTTP_X_REAL_IP'],
			$_SERVER['HTTP_X_FORWARDED']
		);
		parent::tearDown();
	}

	/** @test */
	public function test_returns_remote_addr_when_not_a_trusted_proxy(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame( '203.0.113.10', \ATORA_Client_IP::get(), 'REMOTE_ADDR no confiable — la cabecera forjada debe ignorarse' );
	}

	/** @test */
	public function test_uses_forwarded_header_behind_trusted_private_proxy(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.77';

		$this->assertSame( '198.51.100.77', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_cf_connecting_ip_takes_priority_over_x_forwarded_for(): void {
		$_SERVER['REMOTE_ADDR']           = '127.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.1';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.2';

		$this->assertSame( '198.51.100.1', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_supports_ipv6_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';

		$this->assertSame( '2001:db8::1', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_supports_ipv6_trusted_proxy_and_forwarded_ipv6_client(): void {
		$_SERVER['REMOTE_ADDR']          = '::1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::42';

		$this->assertSame( '2001:db8::42', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_x_forwarded_for_chain_picks_first_non_proxy_ip(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.50, 10.0.0.2, 172.16.0.3';

		$this->assertSame( '198.51.100.50', \ATORA_Client_IP::get() );
	}

	/**
	 * PT-2 (6.5.7) — hallazgo real: la cadena debe recorrerse de
	 * DERECHA a IZQUIERDA. El cliente controla el extremo izquierdo (lo
	 * que él mismo escribe); un proxy de confianza real solo puede
	 * APPENDEAR a la derecha. Si el "recorrido" fuera izquierda→derecha
	 * (como en la versión anterior a este sprint), un atacante podía
	 * anteponer una IP falsa y el proxy real simplemente la dejaría
	 * pasar como primer valor "no confiable" encontrado.
	 *
	 * @test
	 */
	public function test_spoofed_left_value_is_ignored_when_trusted_proxy_appends_real_ip(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5'; // proxy de confianza real.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.20'; // 1.2.3.4 = valor inventado por el cliente; 203.0.113.20 = lo que el proxy de verdad anexó.

		$this->assertSame( '203.0.113.20', \ATORA_Client_IP::get(), 'debe devolver lo que el proxy de confianza anexó, no lo que el cliente escribió a la izquierda' );
	}

	/**
	 * Multi-hop: dos proxies de confianza intermedios, cliente real al
	 * extremo izquierdo — debe resolver recorriendo de derecha a
	 * izquierda y saltando cada hop confiable.
	 *
	 * @test
	 */
	public function test_multi_hop_chain_resolves_right_to_left_skipping_trusted_hops(): void {
		$_SERVER['REMOTE_ADDR']          = '192.168.1.1'; // último proxy de confianza (proxy3, implícito en REMOTE_ADDR).
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.2, 172.16.0.3'; // cliente, proxy1 (confiable), proxy2 (confiable).

		$this->assertSame( '198.51.100.7', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_strips_port_from_ipv4_and_bracketed_ipv6(): void {
		$_SERVER['REMOTE_ADDR']          = '10.1.1.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '[2001:db8::9]:443';

		$this->assertSame( '2001:db8::9', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_invalid_remote_addr_returns_empty_string(): void {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$this->assertSame( '', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_missing_remote_addr_returns_empty_string(): void {
		$this->assertSame( '', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_get_hashed_never_leaks_raw_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';

		$hash = \ATORA_Client_IP::get_hashed();
		$this->assertNotSame( '203.0.113.99', $hash );
		$this->assertSame( 64, strlen( $hash ), 'debe ser un hash sha256 en hex' );
	}

	/** @test */
	public function test_custom_trusted_proxy_filter_is_respected(): void {
		$_SERVER['REMOTE_ADDR']          = '55.55.55.55';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5';

		// Sin configurar el filtro, 55.55.55.55 no es confiable — se usa tal cual.
		$this->assertSame( '55.55.55.55', \ATORA_Client_IP::get() );
	}
}
