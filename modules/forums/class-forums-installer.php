<?php
/**
 * Forums installer: creates tables with CREATE TABLE IF NOT EXISTS.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

namespace ATORA\Forums;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Forums_Installer {
	public static function ensure_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$forums = $wpdb->prefix . 'atora_forums';
		$topics = $wpdb->prefix . 'atora_forum_topics';
		$replies = $wpdb->prefix . 'atora_forum_replies';
		$reports = $wpdb->prefix . 'atora_forum_reports';

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$forums} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				scope_type VARCHAR(20) NOT NULL,
				scope_id BIGINT UNSIGNED NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY inst_scope (institution_id, scope_type, scope_id),
				KEY inst_id (institution_id)
			) {$charset}"
		); // phpcs:ignore WordPress.DB

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$topics} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				forum_id BIGINT UNSIGNED NOT NULL,
				institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				author_id BIGINT UNSIGNED NOT NULL,
				title VARCHAR(255) NOT NULL DEFAULT '',
				body LONGTEXT NOT NULL,
				pinned TINYINT(1) NOT NULL DEFAULT 0,
				closed TINYINT(1) NOT NULL DEFAULT 0,
				hidden TINYINT(1) NOT NULL DEFAULT 0,
				accepted_reply_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				replies_count INT UNSIGNED NOT NULL DEFAULT 0,
				last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				client_event_id VARCHAR(80) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY forum_hidden_pinned (forum_id, hidden, pinned, last_activity_at),
				KEY forum_author (forum_id, author_id, last_activity_at),
				KEY inst_forum (institution_id, forum_id),
				KEY title_like (title),
				UNIQUE KEY forum_author_event (forum_id, author_id, client_event_id)
			) {$charset}"
		); // phpcs:ignore WordPress.DB

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$replies} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				topic_id BIGINT UNSIGNED NOT NULL,
				forum_id BIGINT UNSIGNED NOT NULL,
				institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				author_id BIGINT UNSIGNED NOT NULL,
				body LONGTEXT NOT NULL,
				hidden TINYINT(1) NOT NULL DEFAULT 0,
				client_event_id VARCHAR(80) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY topic_hidden (topic_id, hidden, created_at),
				KEY inst_forum (institution_id, forum_id),
				UNIQUE KEY topic_author_event (topic_id, author_id, client_event_id)
			) {$charset}"
		); // phpcs:ignore WordPress.DB

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$reports} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				forum_id BIGINT UNSIGNED NOT NULL,
				institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				target_type VARCHAR(20) NOT NULL,
				target_id BIGINT UNSIGNED NOT NULL,
				reporter_id BIGINT UNSIGNED NOT NULL,
				reason VARCHAR(255) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'open',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY inst_status (institution_id, status, created_at),
				KEY target (target_type, target_id)
			) {$charset}"
		); // phpcs:ignore WordPress.DB
	}
}

