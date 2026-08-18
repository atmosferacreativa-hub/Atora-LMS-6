<?php
/**
 * ATORA LMS — Uninstall handler.
 *
 * Se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 * Por defecto NO borra datos (protección del cliente: cursos, matrículas,
 * contactos CRM y certificados son valiosos). Solo borra todo si el admin
 * activó explícitamente "Eliminar datos al desinstalar" en Ajustes → ATORA.
 *
 * Opción de control: atora_lms_delete_data_on_uninstall (bool).
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1) Limpieza ligera SIEMPRE: crons y transients propios.
// ─────────────────────────────────────────────────────────────────────────────
$atora_crons = array(
	'atora_analytics_daily_cron',
	'atora_automation_cron',
	'atora_calendar_sync_cron',
	'atora_crm_autotag_cron',
	'atora_email_queue_cron',
	'atora_engagement_score_cron',
	'atora_live_reminders_cron',
	'atora_messaging_cron',
	'atora_newsletter_cron',
	'atora_newsletter_feed_cron',
	'atora_scoring_cron',
	'atora_lms_migration_cron',
	'atora_lms_reconcile_check',
	'atora_license_check_cron',
);
foreach ( $atora_crons as $atora_cron_hook ) {
	wp_clear_scheduled_hook( $atora_cron_hook );
}

global $wpdb;

// Transients propios (atora_* y clms_*).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_atora\_%'
	    OR option_name LIKE '\_transient\_timeout\_atora\_%'
	    OR option_name LIKE '\_transient\_clms\_%'
	    OR option_name LIKE '\_transient\_timeout\_clms\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// ─────────────────────────────────────────────────────────────────────────────
// 2) Borrado profundo SOLO con opt-in explícito.
// ─────────────────────────────────────────────────────────────────────────────
$atora_delete_all = (bool) get_option( 'atora_lms_delete_data_on_uninstall', false );

if ( ! $atora_delete_all ) {
	return;
}

// 2a) Tablas custom.
$atora_tables = array(
	'atora_2fa_tokens',
	'atora_abandoned_carts',
	'atora_affiliate_clicks',
	'atora_affiliate_commissions',
	'atora_affiliates',
	'atora_api_keys',
	'atora_automation_execution_log',
	'atora_automation_queue',
	'atora_automations',
	'atora_calendar_bookings',
	'atora_calendar_events',
	'atora_calendar_sync',
	'atora_certificates',
	'atora_companies',
	'atora_contact_activities',
	'atora_contact_list_pivot',
	'atora_contact_notes',
	'atora_contact_tags',
	'atora_contacts',
	'atora_conversation_messages',
	'atora_conversations',
	'atora_course_terms',
	'atora_courses',
	'atora_crm_lists',
	'atora_email_analytics',
	'atora_email_consent_log',
	'atora_email_events',
	'atora_email_preferences',
	'atora_email_queue',
	'atora_email_templates',
	'atora_enrollments',
	'atora_form_entries',
	'atora_gradebook',
	'atora_lesson_progress',
	'atora_lessons',
	'atora_lms_parity_log',
	'atora_message_log',
	'atora_message_queue',
	'atora_newsletters',
	'atora_program_enrollments',
	'atora_programs',
	'atora_quiz_submissions',
	'atora_quizzes',
	'atora_section_students',
	'atora_section_teachers',
	'atora_sections',
	'atora_trusted_devices',
	'atora_url_clicks',
	'atora_url_store',
	'atora_user_engagement',
	'atora_webhooks',
	'clms_badges',
);
foreach ( $atora_tables as $atora_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$atora_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

// 2b) Opciones (prefijos conocidos).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'atora\_%'
	    OR option_name LIKE 'clms\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2c) Usermeta y postmeta legacy del LMS.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '\_clms\_%' OR meta_key LIKE 'clms\_%' OR meta_key LIKE 'atora\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_clms\_%' OR meta_key LIKE 'clms\_%' OR meta_key LIKE 'atora\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// 2d) CPTs del plugin (cursos, lecciones, programas, cohortes).
$atora_cpts = array( 'clms_course', 'clms_lesson', 'clms_program', 'clms_cohort', 'atora_course', 'atora_lesson', 'atora_program' );
foreach ( $atora_cpts as $atora_cpt ) {
	$atora_ids = get_posts(
		array(
			'post_type'      => $atora_cpt,
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $atora_ids as $atora_post_id ) {
		wp_delete_post( (int) $atora_post_id, true );
	}
}

// 2e) Flush final de caché de objetos.
wp_cache_flush();
