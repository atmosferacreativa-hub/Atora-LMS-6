<?php
/**
 * Arranque del buzón (6.30.0): migración en segundo plano y retención diaria.
 *
 * @package ATORA_LMS
 * @since 6.30.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Inbox_Hooks {

	const RETENTION_HOOK = 'atora_inbox_retention_daily';

	public static function boot(): void {
		ATORA_Inbox_Migration::boot();
		add_action( self::RETENTION_HOOK, array( __CLASS__, 'run_retention' ) );
		add_action( 'init', array( __CLASS__, 'schedule_retention' ) );
	}

	public static function schedule_retention(): void {
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
	}

	/** Avisos leídos de más de 180 días; los mensajes de conversación no se tocan. */
	public static function run_retention(): int {
		return ATORA_Inbox_Store::tables_ready() ? ATORA_Inbox_Store::purge_read_notices() : 0;
	}
}
