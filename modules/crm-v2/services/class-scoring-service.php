<?php
/**
 * Scoring_Service — Scoring predictivo de conversión (Fase II S4)
 *
 * Combina señales de engagement, progreso académico, apertura de emails y LTV
 * para generar un score 0-100 por contacto almacenado en atora_contacts.conversion_score.
 *
 * @package ATORA_LMS\CRM_V2\Services
 * @since   5.30.0
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Scoring_Service {

	// ── Pesos de la fórmula ──────────────────────────────────────────────────
	const W_ENGAGEMENT = 0.30;
	const W_PROGRESS   = 0.30;
	const W_EMAIL_OPEN = 0.20;
	const W_LTV        = 0.20;

	// LTV de referencia para normalización (ajustar según negocio)
	const LTV_REFERENCE = 500.0;

	/**
	 * Calcula el score predictivo de un contacto (0-100).
	 *
	 * @param int $contact_id ID en atora_contacts.
	 * @return int Score 0-100.
	 */
	public static function calculate_score( int $contact_id ): int {
		global $wpdb;

		$contacts_table = $wpdb->prefix . 'atora_contacts';
		$contact        = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT engagement_score, life_time_value, email FROM {$contacts_table} WHERE id = %d LIMIT 1",
				$contact_id
			),
			ARRAY_A
		);

		if ( empty( $contact ) ) { return 0; }

		// ── 1. Engagement score (ya normalizado 0-100 en la tabla) ────────────
		$engagement = min( 100, absint( $contact['engagement_score'] ?? 0 ) );

		// ── 2. Progreso en cursos (avg progress_pct de atora_enrollments) ─────
		$enroll_table = $wpdb->prefix . 'atora_enrollments';
		$progress     = 0;
		if ( DB_Service::table_exists( $enroll_table ) ) {
			$user_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT user_id FROM {$contacts_table} WHERE id = %d LIMIT 1", $contact_id )
			);
			if ( $user_id ) {
				$avg = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT AVG(progress_pct) FROM {$enroll_table} WHERE user_id = %d AND status = 'active'",
						$user_id
					)
				);
				$progress = min( 100, (int) round( (float) $avg ) );
			}
		}

		// ── 3. Tasa de apertura de emails (opens / sent de últimas campañas) ──
		$recipients_table = $wpdb->prefix . 'atora_crm_campaign_recipients';
		$email_open_rate  = 0;
		if ( DB_Service::table_exists( $recipients_table ) && ! empty( $contact['email'] ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS total, SUM(opened_at IS NOT NULL) AS opened
					 FROM {$recipients_table}
					 WHERE email = %s",
					sanitize_email( (string) $contact['email'] )
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = absint( $row['total'] ?? 0 );
			if ( $total > 0 ) {
				$email_open_rate = min( 100, (int) round( absint( $row['opened'] ?? 0 ) / $total * 100 ) );
			}
		}

		// ── 4. LTV normalizado (0-100 relativo a LTV_REFERENCE) ──────────────
		$ltv            = (float) ( $contact['life_time_value'] ?? 0 );
		$ltv_normalized = min( 100, (int) round( $ltv / self::LTV_REFERENCE * 100 ) );

		// ── Fórmula ponderada ─────────────────────────────────────────────────
		$score = (int) round(
			$engagement  * self::W_ENGAGEMENT +
			$progress    * self::W_PROGRESS   +
			$email_open_rate * self::W_EMAIL_OPEN +
			$ltv_normalized  * self::W_LTV
		);

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Recalcula el score de todos los contactos en batches.
	 *
	 * @param int $batch Tamaño del batch.
	 */
	public static function recalculate_all( int $batch = 100 ): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'atora_contacts';
		$offset = 0;

		do {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $batch, $offset )
			);

			foreach ( (array) $ids as $id ) {
				$id    = absint( $id );
				$score = self::calculate_score( $id );
				$wpdb->update(
					$table,
					array( 'conversion_score' => $score ),
					array( 'id' => $id ),
					array( '%d' ),
					array( '%d' )
				);
			}

			$offset += $batch;
		} while ( count( $ids ) === $batch );
	}

	/**
	 * Etiqueta semáforo del score.
	 *
	 * @param int $score Score 0-100.
	 * @return string "Alta" | "Media" | "Baja"
	 */
	public static function get_score_label( int $score ): string {
		if ( $score > 70 ) { return __( 'Alta', 'atora-lms' ); }
		if ( $score >= 40 ) { return __( 'Media', 'atora-lms' ); }
		return __( 'Baja', 'atora-lms' );
	}
}
