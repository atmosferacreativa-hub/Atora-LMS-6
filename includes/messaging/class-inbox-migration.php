<?php
/**
 * Migración de las metas `clms_internal_messages` y `clms_notifications` al
 * buzón propio (6.30.0).
 *
 * - Idempotente: cada mensaje conserva su uuid como `legacy_id` (único por
 *   hilo); correrla dos veces no duplica.
 * - Por lotes de usuarios, con progreso en una opción. Al actualizar el plugin
 *   se agenda en cron hasta terminar; también `wp atora inbox migrate`.
 * - No borra ni modifica las metas viejas en esta versión.
 * - Silenciosa: no dispara notificaciones al teléfono ni hooks de aviso nuevo.
 * - Las notificaciones `message_*` eran copias automáticas de un mensaje (que
 *   ya se migra desde su propia meta): no se migran, para no duplicar.
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Inbox_Migration {

	const OPTION     = 'atora_inbox_migration';
	const CRON_HOOK  = 'atora_inbox_migrate_batch';
	const BATCH_SIZE = 50;

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_batch' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'atora inbox migrate', array( __CLASS__, 'cli' ) );
		}
	}

	/** Al actualizar: empieza (o retoma) la migración en segundo plano. */
	public static function schedule(): void {
		$state = self::state();
		if ( ! empty( $state['done'] ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	public static function run_cron_batch(): void {
		$result = self::run_batch( self::BATCH_SIZE );
		if ( ! $result['done'] ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/** @return array{last_user_id:int, done:bool, users:int, messages:int, notices:int, skipped_copies:int} */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array( 'last_user_id' => 0, 'done' => false, 'users' => 0, 'messages' => 0, 'notices' => 0, 'skipped_copies' => 0 )
		);
	}

	/**
	 * Un lote de usuarios con metas viejas.
	 *
	 * @return array{done:bool, users:int, messages:int, notices:int, skipped_copies:int}
	 */
	public static function run_batch( int $size = self::BATCH_SIZE ): array {
		global $wpdb;
		$state = self::state();
		if ( ! class_exists( 'ATORA_Inbox_Store' ) || ! ATORA_Inbox_Store::tables_ready() ) {
			return array( 'done' => false, 'users' => 0, 'messages' => 0, 'notices' => 0, 'skipped_copies' => 0 );
		}
		$users = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) AND user_id > %d ORDER BY user_id ASC LIMIT %d",
					CLMS_Messaging::META_KEY,
					CLMS_Notifications::META_KEY,
					(int) $state['last_user_id'],
					max( 1, $size )
				)
			)
		);

		$batch = array( 'users' => 0, 'messages' => 0, 'notices' => 0, 'skipped_copies' => 0 );
		foreach ( $users as $user_id ) {
			$counts = self::migrate_user( $user_id );
			++$batch['users'];
			foreach ( array( 'messages', 'notices', 'skipped_copies' ) as $key ) {
				$batch[ $key ] += $counts[ $key ];
			}
			$state['last_user_id'] = $user_id;
		}

		$state['done'] = count( $users ) < max( 1, $size );
		foreach ( $batch as $key => $value ) {
			$state[ $key ] = (int) $state[ $key ] + $value;
		}
		update_option( self::OPTION, $state, false );

		return array( 'done' => (bool) $state['done'] ) + $batch;
	}

	/**
	 * Migra las dos metas de un usuario. Solo cuenta lo que se creó (lo ya migrado no suma).
	 *
	 * @return array{messages:int, notices:int, skipped_copies:int}
	 */
	public static function migrate_user( int $user_id ): array {
		$counts = array( 'messages' => 0, 'notices' => 0, 'skipped_copies' => 0 );

		// Mensajes: del más viejo al más nuevo (la meta guarda el más nuevo primero).
		$messages = get_user_meta( $user_id, CLMS_Messaging::META_KEY, true );
		$messages = is_array( $messages ) ? array_reverse( $messages ) : array();
		foreach ( $messages as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			if ( self::migrate_message( $user_id, $item ) ) {
				++$counts['messages'];
			}
		}

		$notices = get_user_meta( $user_id, CLMS_Notifications::META_KEY, true );
		$notices = is_array( $notices ) ? array_reverse( $notices ) : array();
		foreach ( $notices as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			if ( 0 === strpos( sanitize_key( (string) ( $item['type'] ?? '' ) ), 'message_' ) ) {
				++$counts['skipped_copies'];
				continue;
			}
			if ( self::migrate_notice( $user_id, $item ) ) {
				++$counts['notices'];
			}
		}

		return $counts;
	}

	private static function gmt( $local ): string {
		$local = trim( (string) $local );
		return '' !== $local ? get_gmt_from_date( $local ) : current_time( 'mysql', true );
	}

	private static function migrate_message( int $user_id, array $item ): bool {
		$sender_id    = absint( $item['sender_id'] ?? 0 );
		$message_type = sanitize_key( (string) ( $item['message_type'] ?? 'general' ) );
		$conversation = $sender_id > 0 && $sender_id !== $user_id && in_array( $message_type, CLMS_Messaging::conversation_message_types(), true );
		$course_id    = absint( $item['course_id'] ?? 0 );

		$thread_id = $conversation
			? ATORA_Inbox_Store::direct_thread_id( $sender_id, $user_id, $course_id, $course_id ? get_the_title( $course_id ) : '', array( $sender_id => 'teacher', $user_id => 'student' ) )
			: ATORA_Inbox_Store::system_thread_id( $user_id );
		if ( is_wp_error( $thread_id ) ) {
			return false;
		}
		$read   = ! empty( $item['is_read'] );
		$result = ATORA_Inbox_Store::add_message(
			(int) $thread_id,
			$conversation ? $sender_id : 0,
			array(
				'kind'          => $conversation ? 'message' : ( $message_type ? $message_type : 'general' ),
				'title'         => (string) ( $item['title'] ?? '' ),
				'body'          => (string) ( $item['message'] ?? '' ),
				'link'          => (string) ( $item['link'] ?? '' ),
				'course_id'     => $course_id,
				'lesson_id'     => absint( $item['lesson_id'] ?? 0 ),
				'submission_id' => absint( $item['submission_id'] ?? 0 ),
				'legacy_id'     => (string) $item['id'],
				'read_at'       => ! $conversation && $read ? self::gmt( $item['created_at'] ?? '' ) : null,
				'created_at'    => self::gmt( $item['created_at'] ?? '' ),
				'silent'        => true,
				'meta'          => array(
					'message_type'        => $message_type,
					'sender_type'         => sanitize_key( (string) ( $item['sender_type'] ?? 'system' ) ),
					'sender_name'         => sanitize_text_field( (string) ( $item['sender_name'] ?? '' ) ),
					'program_id'          => absint( $item['program_id'] ?? 0 ),
					'recommendation_type' => sanitize_key( (string) ( $item['recommendation_type'] ?? '' ) ),
					'priority'            => sanitize_key( (string) ( $item['priority'] ?? 'normal' ) ),
					'reply_to'            => sanitize_text_field( (string) ( $item['reply_to'] ?? '' ) ),
					'automation_source'   => sanitize_key( (string) ( $item['automation_source'] ?? '' ) ),
					'migrated'            => true,
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return false;
		}
		// Conversación: lo leído avanza el "último leído" del destinatario (no lo retrocede).
		if ( $conversation && $read ) {
			ATORA_Inbox_Store::mark_thread_read( (int) $thread_id, $user_id, (int) $result['message']['id'] );
		}
		return $result['created'];
	}

	private static function migrate_notice( int $user_id, array $item ): bool {
		$thread_id = ATORA_Inbox_Store::system_thread_id( $user_id );
		if ( is_wp_error( $thread_id ) ) {
			return false;
		}
		$kind   = sanitize_key( (string) ( $item['type'] ?? 'general' ) );
		$result = ATORA_Inbox_Store::add_message(
			(int) $thread_id,
			0,
			array(
				'kind'          => '' !== $kind ? $kind : 'general',
				'title'         => (string) ( $item['title'] ?? '' ),
				'body'          => (string) ( $item['message'] ?? '' ),
				'link'          => (string) ( $item['link'] ?? '' ),
				'course_id'     => absint( $item['course_id'] ?? 0 ),
				'lesson_id'     => absint( $item['lesson_id'] ?? 0 ),
				'submission_id' => absint( $item['submission_id'] ?? 0 ),
				'legacy_id'     => (string) $item['id'],
				'read_at'       => ! empty( $item['is_read'] ) ? self::gmt( $item['created_at'] ?? '' ) : null,
				'created_at'    => self::gmt( $item['created_at'] ?? '' ),
				'silent'        => true,
				'meta'          => array(
					'status'   => sanitize_key( (string) ( $item['status'] ?? '' ) ),
					'grade'    => isset( $item['grade'] ) && '' !== (string) $item['grade'] ? absint( $item['grade'] ) : '',
					'feedback' => sanitize_textarea_field( (string) ( $item['feedback'] ?? '' ) ),
					'migrated' => true,
				),
			)
		);
		return ! is_wp_error( $result ) && $result['created'];
	}

	/**
	 * Migra los mensajes y avisos guardados en metas de usuario al buzón.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Usuarios por lote. Default 50.
	 *
	 * [--restart]
	 * : Vuelve a recorrer todos los usuarios (no duplica: es idempotente).
	 */
	public static function cli( $args, $assoc ): void {
		if ( ! empty( $assoc['restart'] ) ) {
			delete_option( self::OPTION );
		}
		$size = max( 1, absint( $assoc['batch'] ?? self::BATCH_SIZE ) );
		do {
			$result = self::run_batch( $size );
			\WP_CLI::log( sprintf( 'Lote: %d usuarios, %d mensajes, %d avisos, %d copias omitidas.', $result['users'], $result['messages'], $result['notices'], $result['skipped_copies'] ) );
		} while ( ! $result['done'] );
		$state = self::state();
		\WP_CLI::success( sprintf( 'Migración terminada: %d usuarios, %d mensajes, %d avisos (%d copias automáticas omitidas).', $state['users'], $state['messages'], $state['notices'], $state['skipped_copies'] ) );
	}
}
