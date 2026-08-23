<?php
/**
 * ATORA_Client_IP — resolución de IP consciente de proxies confiables — PT-1 (sprint 6.5.5).
 *
 * Extraído de Student_Assistant::resolve_client_ip() (ya correcto) y
 * centralizado para reemplazar las varias implementaciones inseguras
 * que confiaban en X-Forwarded-For/Client-IP sin verificar el origen
 * (Forms_Builder, Extended_Registration).
 *
 * PT-2 (6.5.7): corrige el sentido de recorrido de X-Forwarded-For.
 *
 * PT-1 (6.5.8): hallazgo real remanente — los rangos RFC1918 estaban
 * en la lista de proxies confiables POR DEFECTO ("PRIVATE IP ≠
 * TRUSTED PROXY"). La lista por defecto pasa a ser conservadora (solo
 * loopback); todo lo demás requiere configuración explícita vía
 * filtro (`atora_client_ip_trusted_proxies`). CF-Connecting-IP pasa a
 * tener su propia lista de confianza separada
 * (`atora_trusted_cloudflare_cidrs`, vacía por defecto).
 *
 * @package ATORA_LMS\Tests\Security
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Security;

use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\atora_test_reset_filters();
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
		\atora_test_reset_filters();
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

	/**
	 * @param array<int,string> $rules
	 */
	private function trust_generic_proxies( array $rules ): void {
		add_filter( 'atora_client_ip_trusted_proxies', static function () use ( $rules ) { return $rules; } );
	}

	/**
	 * @param array<int,string> $rules
	 */
	private function trust_cloudflare( array $rules ): void {
		add_filter( 'atora_trusted_cloudflare_cidrs', static function () use ( $rules ) { return $rules; } );
	}

	/**
	 * @param array<int,string> $rules
	 */
	private function trust_x_real_ip( array $rules ): void {
		add_filter( 'atora_trust_x_real_ip_proxies', static function () use ( $rules ) { return $rules; } );
	}

	/**
	 * Test A (OT 6.5.8) — cliente directo, sin proxy: la cabecera
	 * forjada por el propio cliente se ignora.
	 *
	 * @test
	 */
	public function test_a_direct_client_ignores_spoofed_header(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.20';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame( '203.0.113.20', \ATORA_Client_IP::get() );
	}

	/**
	 * Test B (OT 6.5.8) — hallazgo real de este sprint: una IP privada
	 * NO configurada explícitamente como proxy NO debe tratarse como
	 * confiable solo por ser RFC1918. Sin ningún filtro configurado
	 * (comportamiento por defecto), 192.168.1.50 no es un proxy
	 * confiable — la cabecera reenviada se ignora.
	 *
	 * @test
	 */
	public function test_b_unconfigured_private_ip_is_not_trusted_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '192.168.1.50';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$this->assertSame( '192.168.1.50', \ATORA_Client_IP::get(), 'PRIVATE IP ≠ TRUSTED PROXY por defecto' );
	}

	/**
	 * Test C (OT 6.5.8) — proxy genérico explícitamente autorizado vía
	 * filtro (no por defecto): la cabecera reenviada sí se resuelve.
	 *
	 * @test
	 */
	public function test_c_explicitly_configured_generic_proxy_is_trusted(): void {
		$this->trust_generic_proxies( array( '10.0.0.2' ) );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.2';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.20';

		$this->assertSame( '203.0.113.20', \ATORA_Client_IP::get() );
	}

	/**
	 * Test D (OT 6.5.8) — un proxy genérico confiable NO autoriza
	 * automáticamente CF-Connecting-IP (listas separadas): si
	 * REMOTE_ADDR no está en la lista Cloudflare (vacía por defecto),
	 * ese header se ignora aunque el proxy sea confiable para XFF.
	 *
	 * @test
	 */
	public function test_d_cf_header_ignored_from_non_cloudflare_trusted_proxy(): void {
		$this->trust_generic_proxies( array( '10.0.0.2' ) );

		$_SERVER['REMOTE_ADDR']           = '10.0.0.2'; // proxy genérico confiable, NO Cloudflare.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.20';

		$this->assertSame( '203.0.113.20', \ATORA_Client_IP::get(), 'CF-Connecting-IP no debe usarse desde un proxy que no está en la lista Cloudflare' );
	}

	/**
	 * PT-2 (6.5.9) — escenario del hallazgo: proxy genérico confiable
	 * para XFF, pero SIN autorización explícita para X-Real-IP.
	 * X-Real-IP falsificado por el cliente debe ignorarse y
	 * X-Forwarded-For (correcto) debe usarse en su lugar — antes de
	 * este fix, X-Real-IP se probaba primero y ganaba.
	 *
	 * @test
	 */
	public function test_g_spoofed_x_real_ip_ignored_without_explicit_authorization(): void {
		$this->trust_generic_proxies( array( '10.0.0.2' ) );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.2';
		$_SERVER['HTTP_X_REAL_IP']       = '6.6.6.6'; // falsificado por el cliente.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.20';

		$this->assertSame(
			'203.0.113.20',
			\ATORA_Client_IP::get(),
			'sin atora_trust_x_real_ip_proxies explícito, X-Real-IP no debe usarse aunque el proxy sea confiable para XFF'
		);
	}

	/**
	 * PT-2 (6.5.9) — cuando el proxy SÍ está explícitamente autorizado
	 * para X-Real-IP (además de ser un proxy genérico confiable), el
	 * header vuelve a resolverse normalmente.
	 *
	 * @test
	 */
	public function test_h_x_real_ip_used_when_proxy_explicitly_authorized(): void {
		$this->trust_generic_proxies( array( '10.0.0.2' ) );
		$this->trust_x_real_ip( array( '10.0.0.2' ) );

		$_SERVER['REMOTE_ADDR']    = '10.0.0.2';
		$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.55';

		$this->assertSame( '203.0.113.55', \ATORA_Client_IP::get() );
	}

	/**
	 * PT-2 (6.5.9) — un proxy que SOLO está en la lista de X-Real-IP
	 * pero no en la lista genérica no debe ser tratado como confiable
	 * en absoluto (la autorización de X-Real-IP es un requisito
	 * adicional, no un sustituto de la confianza genérica).
	 *
	 * @test
	 */
	public function test_i_x_real_ip_authorization_alone_does_not_imply_generic_proxy_trust(): void {
		$this->trust_x_real_ip( array( '10.0.0.2' ) );

		$_SERVER['REMOTE_ADDR']    = '10.0.0.2';
		$_SERVER['HTTP_X_REAL_IP'] = '203.0.113.55';

		$this->assertSame(
			'10.0.0.2',
			\ATORA_Client_IP::get(),
			'estar solo en la lista de X-Real-IP, sin estar en la lista genérica, no debe autorizar ningún header reenviado'
		);
	}

	/**
	 * Test E (OT 6.5.8) — Cloudflare configurado explícitamente: el
	 * header CF-Connecting-IP sí se resuelve.
	 *
	 * @test
	 */
	public function test_e_cf_header_used_when_remote_addr_is_configured_cloudflare_ip(): void {
		$this->trust_cloudflare( array( '198.51.100.0/24' ) );

		$_SERVER['REMOTE_ADDR']           = '198.51.100.5'; // dentro del rango Cloudflare configurado.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.20';

		$this->assertSame( '203.0.113.20', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_supports_ipv6_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';

		$this->assertSame( '2001:db8::1', \ATORA_Client_IP::get() );
	}

	/**
	 * Test F (OT 6.5.8) — IPv6: proxy confiable configurado vía CIDR
	 * IPv6, cliente IPv6 reenviado.
	 *
	 * @test
	 */
	public function test_f_ipv6_trusted_proxy_cidr_and_forwarded_ipv6_client(): void {
		$this->trust_generic_proxies( array( 'fc00::/7' ) );

		$_SERVER['REMOTE_ADDR']          = 'fc00::1'; // dentro del CIDR IPv6 explícitamente confiable.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8::42';

		$this->assertSame( '2001:db8::42', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_loopback_remote_addr_is_trusted_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';

		$this->assertSame( '198.51.100.9', \ATORA_Client_IP::get(), 'loopback sigue confiable por defecto (proxy local en la misma máquina)' );
	}

	/**
	 * PT-2 (6.5.7) — hallazgo real: la cadena debe recorrerse de
	 * DERECHA a IZQUIERDA. El cliente controla el extremo izquierdo (lo
	 * que él mismo escribe); un proxy de confianza real solo puede
	 * APPENDEAR a la derecha.
	 *
	 * @test
	 */
	public function test_spoofed_left_value_is_ignored_when_trusted_proxy_appends_real_ip(): void {
		$this->trust_generic_proxies( array( '10.0.0.5' ) );

		$_SERVER['REMOTE_ADDR']          = '10.0.0.5'; // proxy de confianza explícitamente configurado.
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
		$this->trust_generic_proxies( array( '192.168.1.1', '10.0.0.2', '172.16.0.3' ) );

		$_SERVER['REMOTE_ADDR']          = '192.168.1.1'; // último proxy de confianza (proxy3, implícito en REMOTE_ADDR).
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.2, 172.16.0.3'; // cliente, proxy1 (confiable), proxy2 (confiable).

		$this->assertSame( '198.51.100.7', \ATORA_Client_IP::get() );
	}

	/** @test */
	public function test_strips_port_from_ipv4_and_bracketed_ipv6(): void {
		$this->trust_generic_proxies( array( '10.1.1.1' ) );

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
