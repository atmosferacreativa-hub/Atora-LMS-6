<?php
/**
 * Integración 6.30.0: buzón propio (mensajes y avisos en tablas).
 */

declare( strict_types = 1 );

final class InboxTest extends WP_UnitTestCase {

	private int $teacher = 0;
	private int $student = 0;
	private int $other = 0;
	private int $course = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		global $wpdb;
		foreach ( array( 'atora_messages', 'atora_message_participants', 'atora_message_threads', 'atora_mobile_push_tokens' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
		delete_option( ATORA_Inbox_Migration::OPTION );
		$this->teacher = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Docente' ) );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Estudiante' ) );
		$this->other   = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Otro' ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Curso buzón' ) );
	}

	private function messaging(): CLMS_Messaging {
		return clms_core( 'CLMS_Messaging' );
	}

	private function notifications(): CLMS_Notifications {
		return clms_core( 'CLMS_Notifications' );
	}

	public function test_public_messaging_api_answers_as_before(): void {
		$sent = $this->messaging()->send_message( $this->student, array(
			'message_type' => 'manual',
			'sender_type'  => 'teacher',
			'sender_id'    => $this->teacher,
			'title'        => 'Hola',
			'message'      => 'Revisa la lección 2',
			'course_id'    => $this->course,
		) );
		$this->assertIsArray( $sent );
		foreach ( array( 'id', 'message_type', 'sender_type', 'sender_id', 'sender_name', 'title', 'message', 'link', 'course_id', 'thread_id', 'thread_type', 'thread_label', 'is_read', 'created_at' ) as $key ) {
			$this->assertArrayHasKey( $key, $sent, $key );
		}

		$list = $this->messaging()->get_messages( $this->student );
		$this->assertCount( 1, $list );
		$this->assertSame( 'Revisa la lección 2', $list[0]['message'] );
		$this->assertSame( 0, $list[0]['is_read'] );
		$this->assertSame( 1, $this->messaging()->get_unread_count( $this->student ) );

		$threads = $this->messaging()->get_threads( $this->student );
		$this->assertSame( 'direct', $threads[0]['thread_type'] );
		$this->assertSame( 1, $threads[0]['unread_count'] );

		$this->assertTrue( $this->messaging()->mark_message_read( $this->student, $list[0]['id'] ) );
		$this->assertSame( 0, $this->messaging()->get_unread_count( $this->student ) );
		$this->assertSame( array(), $this->messaging()->get_messages( $this->other ), 'Otro estudiante no ve el mensaje.' );
	}

	public function test_no_automatic_notice_copy_and_single_counter(): void {
		$this->messaging()->send_message( $this->student, array( 'message_type' => 'manual', 'sender_type' => 'teacher', 'sender_id' => $this->teacher, 'title' => 'T', 'message' => 'M', 'mirror_notification' => true ) );
		$this->assertSame( array(), $this->notifications()->get_notifications( $this->student ), 'Ya no se copia cada mensaje como aviso.' );

		$this->notifications()->add_notification( $this->student, array( 'type' => 'submission_graded', 'title' => 'Nota publicada', 'message' => 'Nota: 80/100', 'lesson_id' => 7 ) );
		$this->assertSame( 2, $this->notifications()->get_unread_count( $this->student ) );
		$this->assertSame( 2, $this->messaging()->get_unread_count( $this->student ), 'Un solo contador: mensajes y avisos.' );

		$notices = $this->notifications()->get_notifications( $this->student );
		$this->assertSame( 'submission_graded', $notices[0]['type'] );
		$this->assertSame( 'submission_graded', $notices[0]['kind'] );
		$this->assertSame( 7, $notices[0]['lesson_id'] );
		$this->assertTrue( $this->notifications()->mark_notification_read( $this->student, $notices[0]['id'] ) );
		$this->assertSame( 1, $this->messaging()->get_unread_count( $this->student ) );
	}

	public function test_creating_a_notice_fires_the_same_hook(): void {
		$seen = array();
		$listener = static function ( $user_id, $item ) use ( &$seen ) {
			$seen[] = array( $user_id, $item['type'], $item['title'] );
		};
		add_action( 'atora/notification_added', $listener, 10, 2 );
		$this->notifications()->add_notification( $this->student, array( 'type' => 'lesson_published', 'title' => 'Nueva lección' ) );
		remove_action( 'atora/notification_added', $listener, 10 );
		$this->assertSame( array( array( $this->student, 'lesson_published', 'Nueva lección' ) ), $seen );
	}

	public function test_messaging_filters_still_run(): void {
		$calls  = 0;
		$filter = static function ( $item ) use ( &$calls ) {
			++$calls;
			return $item;
		};
		add_filter( 'clms_modularity_messaging_raw_item', $filter );
		add_filter( 'clms_modularity_messaging_item', $filter );
		$this->messaging()->send_message( $this->student, array( 'message_type' => 'enrollment', 'title' => 'Bienvenido' ) );
		remove_filter( 'clms_modularity_messaging_raw_item', $filter );
		remove_filter( 'clms_modularity_messaging_item', $filter );
		$this->assertSame( 2, $calls );
	}

	public function test_automatic_messages_go_to_avisos_with_kind_and_dedupe(): void {
		$first = $this->messaging()->send_message( $this->student, array( 'message_type' => 'enrollment', 'title' => 'Bienvenido', 'dedupe_key' => 'enroll_5' ) );
		$again = $this->messaging()->send_message( $this->student, array( 'message_type' => 'enrollment', 'title' => 'Bienvenido', 'dedupe_key' => 'enroll_5' ) );
		$this->assertSame( 'system', $first['thread_type'] );
		$this->assertSame( 'enrollment', $first['kind'] );
		$this->assertFalse( $again, 'Mismo dedupe_key: no se repite.' );
	}

	public function test_staff_notices_never_reach_students(): void {
		$this->assertFalse( $this->notifications()->add_notification( $this->student, array( 'type' => 'early_warning', 'title' => 'Alerta' ) ) );
		$this->assertTrue( $this->notifications()->add_notification( $this->teacher, array( 'type' => 'early_warning', 'title' => 'Alerta' ) ) );
		$this->assertSame( 'early_warning', $this->notifications()->get_notifications( $this->teacher )[0]['kind'] );
	}

	public function test_migration_moves_both_metas_and_does_not_duplicate(): void {
		update_user_meta( $this->student, CLMS_Messaging::META_KEY, array(
			array( 'id' => 'uuid-m2', 'message_type' => 'enrollment', 'title' => 'Bienvenido', 'message' => 'x', 'is_read' => 0, 'created_at' => '2026-09-02 10:00:00' ),
			array( 'id' => 'uuid-m1', 'message_type' => 'manual', 'sender_type' => 'teacher', 'sender_id' => $this->teacher, 'title' => 'Hola', 'message' => 'Primer mensaje', 'is_read' => 1, 'created_at' => '2026-09-01 10:00:00' ),
		) );
		update_user_meta( $this->student, CLMS_Notifications::META_KEY, array(
			array( 'id' => 'uuid-n2', 'type' => 'message_manual', 'title' => 'copia', 'is_read' => 0, 'created_at' => '2026-09-01 10:00:01' ),
			array( 'id' => 'uuid-n1', 'type' => 'submission_graded', 'title' => 'Nota', 'grade' => 90, 'is_read' => 0, 'created_at' => '2026-09-03 10:00:00' ),
		) );

		$first = ATORA_Inbox_Migration::run_batch( 50 );
		$this->assertTrue( $first['done'] );
		$this->assertSame( 2, $first['messages'] );
		$this->assertSame( 1, $first['notices'] );
		$this->assertSame( 1, $first['skipped_copies'] );

		delete_option( ATORA_Inbox_Migration::OPTION );
		$second = ATORA_Inbox_Migration::run_batch( 50 );
		$this->assertSame( 0, $second['messages'] + $second['notices'], 'Correr dos veces no duplica.' );

		$messages = $this->messaging()->get_messages( $this->student );
		$this->assertCount( 3, $messages );
		$ids = wp_list_pluck( $messages, 'id' );
		$this->assertContains( 'uuid-m1', $ids, 'El id viejo sigue sirviendo.' );
		$this->assertSame( 2, $this->messaging()->get_unread_count( $this->student ), 'Lo leído sigue leído.' );
		$this->assertNotEmpty( get_user_meta( $this->student, CLMS_Messaging::META_KEY, true ), 'La meta vieja no se borra.' );
	}

	public function test_retention_deletes_only_old_read_notices(): void {
		global $wpdb;
		$this->notifications()->add_notification( $this->student, array( 'type' => 'lesson_published', 'title' => 'Vieja leída' ) );
		$this->notifications()->add_notification( $this->student, array( 'type' => 'lesson_published', 'title' => 'Vieja sin leer' ) );
		$this->messaging()->send_message( $this->student, array( 'message_type' => 'manual', 'sender_id' => $this->teacher, 'title' => 'Conversación', 'message' => 'x' ) );
		$old = gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_messages SET read_at = %s, created_at = %s WHERE title = %s", $old, $old, 'Vieja leída' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}atora_messages SET created_at = %s WHERE title IN ('Vieja sin leer', 'Conversación')", $old ) );

		$this->assertSame( 1, ATORA_Inbox_Hooks::run_retention() );
		$titles = $wpdb->get_col( "SELECT title FROM {$wpdb->prefix}atora_messages ORDER BY id" );
		$this->assertSame( array( 'Vieja sin leer', 'Conversación' ), $titles );
	}
}
