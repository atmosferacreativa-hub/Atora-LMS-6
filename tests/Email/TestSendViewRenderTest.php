<?php
/**
 * Email Engine — render de la pestaña "Prueba de envío".
 *
 * Reproduce un fallo real: la vista usaba `esc_textarea_e()` (inexistente)
 * y rompía el HTML del panel al lanzar una excepción con el `<textarea>`
 * abierto, dejando el aviso de error incrustado dentro del campo.
 *
 * @package ATORA_LMS\Tests\Email
 */

declare( strict_types = 1 );

namespace ATORA\Tests\Email;

use PHPUnit\Framework\TestCase;

final class TestSendViewRenderTest extends TestCase {

	private function renderView(): string {
		$_GET = array();

		// Variables esperadas por la vista (normalmente inyectadas desde admin.php).
		$app_password_links  = array();
		$identity_health_rows = array();
		$identity_labels      = array(
			'academia' => 'Academia / Admin',
			'teacher'  => 'Docencia',
			'admin'    => 'Comercial',
		);

		ob_start();
		require __DIR__ . '/../../modules/email-engine/views/test-send.php';
		return (string) ob_get_clean();
	}

	/** @test */
	public function test_renders_without_exceptions_and_closes_textarea(): void {
		$html = $this->renderView();

		$this->assertNotSame( '', trim( $html ), 'La vista debe producir HTML.' );
		$this->assertStringContainsString( 'id="test_message"', $html, 'Debe incluir el textarea del mensaje.' );

		libxml_use_internal_errors( true );
		$dom = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . '<!doctype html><html><body>' . $html . '</body></html>' );
		$xpath = new \DOMXPath( $dom );

		$textarea = $xpath->query( "//textarea[@id='test_message']" )->item( 0 );
		$this->assertNotNull( $textarea, 'Debe renderizar el textarea.' );
		$this->assertSame(
			'Este es un envío de prueba desde ATORA.',
			trim( (string) $textarea->textContent ),
			'El textarea debe contener el mensaje predeterminado.'
		);

		$buttons = $xpath->query( '//button' );
		$this->assertSame( 2, $buttons->length, 'Debe renderizar ambos botones de envío.' );

		$button_texts = array();
		foreach ( $buttons as $button ) {
			$button_texts[] = trim( (string) $button->textContent );
		}
		$this->assertContains( 'Enviar prueba', $button_texts );
		$this->assertContains( 'Enviar prueba de todas las plantillas', $button_texts );

		// Validación adicional: ambos botones deben estar fuera del textarea.
		$textarea_end = strpos( $html, '</textarea>' );
		$this->assertIsInt( $textarea_end, 'El textarea debe cerrarse en el HTML.' );
		$this->assertNotFalse( $textarea_end );
		$first_button = strpos( $html, '<button' );
		$this->assertNotFalse( $first_button, 'Debe existir al menos un botón.' );
		$this->assertGreaterThan( $textarea_end, $first_button, 'Los botones deben quedar fuera del textarea.' );
	}
}

