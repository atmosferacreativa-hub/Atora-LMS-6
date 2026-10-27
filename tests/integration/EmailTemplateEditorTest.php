<?php
/**
 * Integración 6.38.0: editor de plantillas. Variable desconocida rechazada al guardar; lo guardado cambia el
 * correo encolado (asunto, HTML y texto plano); restaurar vuelve a la original; la vista previa no guarda.
 */

declare( strict_types = 1 );

use ATORA\EmailEngine\Email_Queue;
use ATORA\EmailEngine\Email_Template_Editor;

final class EmailTemplateEditorTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		$this->assertTrue( class_exists( Email_Template_Editor::class ), 'El editor de plantillas debe estar cargado.' );
		delete_option( Email_Template_Editor::OPTION );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_email_queue" );
	}

	private function enqueue( int $user ): array {
		global $wpdb;
		$id = (int) Email_Queue::enqueue( array( 'user_id' => $user, 'template' => 'welcome_course', 'allow_duplicate' => true, 'metadata' => array( 'course_id' => self::factory()->post->create( array( 'post_title' => 'Química' ) ) ) ) );
		$this->assertGreaterThan( 0, $id );
		return $wpdb->get_row( $wpdb->prepare( "SELECT subject, body_html, body_text FROM {$wpdb->prefix}atora_email_queue WHERE id = %d", $id ), ARRAY_A );
	}

	public function test_unknown_variable_is_rejected_on_save(): void {
		$result = Email_Template_Editor::save( 'welcome_course', array(
			'subject' => 'Hola {{display_name}}',
			'blocks'  => array( array( 'type' => 'text', 'text' => 'Tu nota es {{nota_final}} en {{course_title}}' ) ),
		) );
		$this->assertWPError( $result );
		$this->assertSame( 'atora_email_unknown_variable', $result->get_error_code() );
		$this->assertSame( array( 'nota_final' ), $result->get_error_data()['variables'] );
		$this->assertNull( Email_Template_Editor::override_for( 'welcome_course' ), 'No se guardó nada.' );
	}

	public function test_saved_template_changes_the_sent_email_and_reset_restores(): void {
		$user     = self::factory()->user->create( array( 'display_name' => 'Luis' ) );
		$original = $this->enqueue( $user );

		$this->assertTrue( Email_Template_Editor::save( 'welcome_course', array(
			'subject' => 'Ya estás en {{course_title}}, {{display_name}}',
			'blocks'  => array(
				array( 'type' => 'heading', 'text' => 'Empieza hoy' ),
				array( 'type' => 'text', 'text' => "Primera línea\nSegunda <b>línea</b>" ),
				array( 'type' => 'button', 'text' => 'Abrir curso', 'url' => '{{course_url}}' ),
				array( 'type' => 'divider' ),
			),
		) ) );

		$custom = $this->enqueue( $user );
		$this->assertSame( 'Ya estás en Química, Luis', $custom['subject'] );
		$this->assertStringContainsString( 'Empieza hoy', $custom['body_html'] );
		$this->assertStringContainsString( 'Primera línea<br />', $custom['body_html'] );
		$this->assertStringContainsString( 'Segunda línea', $custom['body_html'] );
		$this->assertStringNotContainsString( '<b>', $custom['body_html'], 'El HTML escrito en un bloque no llega al correo.' );
		$this->assertMatchesRegularExpression( '#href="http://example\.org/\?p=\d+"#', $custom['body_html'], 'El botón usa la variable del curso.' );
		$this->assertStringContainsString( 'Empieza hoy', $custom['body_text'], 'El texto plano se genera solo.' );
		$this->assertStringContainsString( 'atora_email_token', $custom['body_html'], 'El pie con la baja sigue estando.' );

		Email_Template_Editor::reset( 'welcome_course' );
		$back = $this->enqueue( $user );
		$this->assertSame( $original['subject'], $back['subject'] );
		$this->assertStringNotContainsString( 'Empieza hoy', $back['body_html'] );
	}

	public function test_preview_does_not_save_and_validates(): void {
		$user    = self::factory()->user->create();
		$preview = Email_Template_Editor::preview( 'welcome_course', array( 'subject' => 'Prueba {{site_name}}', 'blocks' => array( array( 'type' => 'text', 'text' => 'Solo vista previa' ) ) ), get_userdata( $user ) );
		$this->assertIsArray( $preview );
		$this->assertStringContainsString( 'Solo vista previa', $preview['html'] );
		$this->assertStringContainsString( 'Solo vista previa', $preview['text'] );
		$this->assertNull( Email_Template_Editor::override_for( 'welcome_course' ) );
		$this->assertStringNotContainsString( 'Solo vista previa', $this->enqueue( $user )['body_html'], 'Lo previsualizado no se envía.' );

		$this->assertWPError( Email_Template_Editor::preview( 'welcome_course', array( 'subject' => 'x', 'blocks' => array( array( 'type' => 'text', 'text' => '{{inventada}}' ) ) ), get_userdata( $user ) ) );
	}

	public function test_button_needs_valid_url_and_blocks_need_content(): void {
		$bad_url = Email_Template_Editor::validate( 'welcome_course', array( 'subject' => 'x', 'blocks' => array( array( 'type' => 'button', 'text' => 'Ir', 'url' => 'javascript:alert(1)' ) ) ) );
		$this->assertWPError( $bad_url );
		$this->assertSame( 'atora_email_template_url', $bad_url->get_error_code() );
		$this->assertWPError( Email_Template_Editor::validate( 'welcome_course', array( 'subject' => 'x', 'blocks' => array( array( 'type' => 'text', 'text' => '   ' ) ) ) ) );
		$this->assertWPError( Email_Template_Editor::validate( 'no_existe', array( 'subject' => 'x', 'blocks' => array( array( 'type' => 'text', 'text' => 'a' ) ) ) ) );
	}

	public function test_admin_views_render(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$dir = dirname( __DIR__, 2 ) . '/modules/email-engine/views/';

		ob_start();
		include $dir . 'templates.php';
		$list = (string) ob_get_clean();
		$this->assertStringContainsString( 'atora_lesson_published', $list );

		$_GET['template'] = 'welcome_course';
		ob_start();
		include $dir . 'templates.php';
		$editor = (string) ob_get_clean();
		unset( $_GET['template'] );
		$this->assertStringContainsString( 'srcdoc=', $editor, 'Muestra la vista previa.' );
		$this->assertStringContainsString( '{{course_title}}', $editor, 'Lista las variables.' );

		$_GET['domain'] = 'no es dominio';
		$settings       = array( 'provider' => 'sendgrid' );
		ob_start();
		include $dir . 'diagnostics.php';
		$diag = (string) ob_get_clean();
		unset( $_GET['domain'] );
		$this->assertStringContainsString( 'Entregabilidad', $diag );
	}
}
