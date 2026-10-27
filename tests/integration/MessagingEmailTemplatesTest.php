<?php
/**
 * Integración 6.38.0: los avisos académicos que el enrutador de mensajes manda por correo llegan a la cola
 * de correo con su contenido. Antes sus plantillas (`atora_*`) no estaban en el catálogo y la cola las
 * rechazaba: «nueva lección», que solo usa correo, no salía nunca.
 */

declare( strict_types = 1 );

use ATORA\EmailEngine\Email_Queue;
use ATORA\EmailEngine\Email_Templates;

final class MessagingEmailTemplatesTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		$this->assertTrue( class_exists( Email_Queue::class ), 'La cola de correo debe estar cargada.' );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_email_queue" );
	}

	/** @return array{subject:string,body_html:string}|null */
	private function last_email( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT subject, body_html FROM {$wpdb->prefix}atora_email_queue WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id ), ARRAY_A );
		return $row ?: null;
	}

	public function test_router_email_reaches_the_queue_with_content(): void {
		if ( ! class_exists( '\\ATORA\\Messaging\\Messaging_Router' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/messaging/class-messaging-router.php';
		}
		$user = self::factory()->user->create( array( 'display_name' => 'Ana' ) );
		$ok   = \ATORA\Messaging\Messaging_Router::send(
			$user,
			'lesson_published',
			'atora_lesson_published',
			array( 'student_name' => 'Ana', 'lesson_title' => 'Fotosíntesis', 'course_title' => 'Biología', 'button_url' => 'https://example.test/leccion' ),
			array( 'skip_digest' => true )
		);
		$this->assertTrue( $ok );
		\ATORA\Messaging\Messaging_Router::process_queue();

		$email = $this->last_email( $user );
		$this->assertNotNull( $email, 'El aviso llegó a la cola de correo.' );
		$this->assertSame( 'Nueva lección: Fotosíntesis', $email['subject'] );
		$this->assertStringContainsString( 'Fotosíntesis', $email['body_html'] );
		$this->assertStringContainsString( 'Biología', $email['body_html'] );
		$this->assertStringContainsString( 'https://example.test/leccion', $email['body_html'] );
	}

	public function test_every_router_template_renders(): void {
		$user = self::factory()->user->create();
		$keys = array(
			'atora_assignment_graded', 'atora_assignment_due_soon', 'atora_submission_received', 'atora_student_inactive',
			'atora_at_risk_teacher', 'atora_improvement_plan', 'atora_lesson_published', 'atora_section_announcement',
			'atora_daily_digest_student', 'atora_daily_digest_teacher', 'atora_followup_occurrence_due',
		);
		foreach ( $keys as $key ) {
			$this->assertNotNull( Email_Templates::get( $key ), $key . ' está en el catálogo.' );
			$id = Email_Queue::enqueue( array(
				'user_id'         => $user,
				'template'        => $key,
				'allow_duplicate' => true,
				'metadata'        => array( 'lesson_title' => 'L<script>', 'course_title' => 'C', 'grade' => '4.5', 'subject' => 'Asunto propio', 'body' => 'Texto propio' ),
			) );
			$this->assertGreaterThan( 0, (int) $id, $key . ' se encola.' );
		}
		$email = $this->last_email( $user );
		$this->assertStringNotContainsString( '<script>', $email['body_html'], 'Las variables se escapan.' );
	}

	public function test_variables_are_escaped_in_subject_and_body(): void {
		$user = self::factory()->user->create();
		Email_Queue::enqueue( array(
			'user_id'         => $user,
			'template'        => 'atora_assignment_graded',
			'allow_duplicate' => true,
			'metadata'        => array( 'lesson_title' => '<b>Ensayo</b>', 'course_title' => 'Historia', 'grade' => '5' ),
		) );
		$email = $this->last_email( $user );
		$this->assertStringContainsString( '&lt;b&gt;Ensayo&lt;/b&gt;', $email['body_html'] );
		$this->assertStringNotContainsString( '<b>Ensayo</b>', $email['body_html'] );
	}
}
