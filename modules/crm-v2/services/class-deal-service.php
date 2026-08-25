<?php
/**
 * Servicio de pipeline comercial CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deal_Service {
	/**
	 * Etapas del pipeline de ventas.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function get_stages(): array {
		return array(
			'new_lead'        => array( 'label' => __( 'Nuevo lead', 'atora-lms' ), 'color' => '#16a34a' ),
			'contacted'       => array( 'label' => __( 'Contactado', 'atora-lms' ), 'color' => '#0284c7' ),
			'interested'      => array( 'label' => __( 'Interesado', 'atora-lms' ), 'color' => '#2563eb' ),
			'proposal_sent'   => array( 'label' => __( 'Propuesta enviada', 'atora-lms' ), 'color' => '#6366f1' ),
			'payment_pending' => array( 'label' => __( 'Pago pendiente', 'atora-lms' ), 'color' => '#d97706' ),
			'enrolled'        => array( 'label' => __( 'Inscrito', 'atora-lms' ), 'color' => '#0f766e' ),
			'won'             => array( 'label' => __( 'Ganado', 'atora-lms' ), 'color' => '#047857' ),
			'lost'            => array( 'label' => __( 'Perdido', 'atora-lms' ), 'color' => '#dc2626' ),
			'reactivate_later'=> array( 'label' => __( 'Reactivar luego', 'atora-lms' ), 'color' => '#9333ea' ),
		);
	}

	/**
	 * Devuelve tablero Kanban de ventas.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_board(): array {
		self::seed_from_contacts();

		global $wpdb;
		$table = $wpdb->prefix . 'atora_crm_deals';
		if ( ! DB_Service::table_exists( $table ) ) {
			return array( 'stages' => self::get_stages(), 'items' => array() );
		}

		$rows = (array) $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY FIELD(stage,'new_lead','contacted','interested','proposal_sent','payment_pending','enrolled','won','lost','reactivate_later'), updated_at DESC",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$grouped = array();
		foreach ( array_keys( self::get_stages() ) as $stage_key ) {
			$grouped[ $stage_key ] = array();
		}

		foreach ( $rows as $row ) {
			$stage = sanitize_key( (string) ( $row['stage'] ?? 'new_lead' ) );
			if ( ! isset( $grouped[ $stage ] ) ) {
				$grouped[ $stage ] = array();
			}
			$grouped[ $stage ][] = self::normalize_deal_row( $row );
		}

		return array(
			'stages' => self::get_stages(),
			'items'  => $grouped,
		);
	}

	/**
	 * Devuelve mini tablero filtrado por etapas.
	 *
	 * @param array $args Argumentos.
	 * @return array<string,mixed>
	 */
	public static function get_mini_board( array $args = array() ): array {
		$board           = self::get_board();
		$requested_stages = array_values( array_filter( array_map( 'sanitize_key', (array) ( $args['stages'] ?? array() ) ) ) );
		$stages          = self::get_stages();
		$items           = array();
		$filtered_stages = array();

		if ( empty( $requested_stages ) ) {
			$requested_stages = array_keys( $stages );
		}

		foreach ( $requested_stages as $stage_key ) {
			if ( ! isset( $stages[ $stage_key ] ) ) {
				continue;
			}
			$filtered_stages[ $stage_key ] = $stages[ $stage_key ];
			$items[ $stage_key ]           = array_values( (array) ( $board['items'][ $stage_key ] ?? array() ) );
		}

		return array(
			'stages' => $filtered_stages,
			'items'  => $items,
		);
	}

	/**
	 * Mueve un deal entre etapas.
	 *
	 * @param int    $deal_id      Deal.
	 * @param string $to_stage     Etapa destino.
	 * @param string $lost_reason  Motivo de pérdida.
	 * @return bool
	 */
	public static function move_deal( int $deal_id, string $to_stage, string $lost_reason = '' ): bool {
		global $wpdb;

		$deal_id   = absint( $deal_id );
		$to_stage  = sanitize_key( $to_stage );
		$table     = $wpdb->prefix . 'atora_crm_deals';
		$stages    = self::get_stages();
		if ( ! $deal_id || ! isset( $stages[ $to_stage ] ) || ! DB_Service::table_exists( $table ) ) {
			return false;
		}

		$current = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $deal_id ), ARRAY_A );
		if ( empty( $current ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$table,
			array(
				'stage'       => $to_stage,
				'lost_reason' => 'lost' === $to_stage ? sanitize_textarea_field( $lost_reason ) : '',
			),
			array( 'id' => $deal_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		$contact_id = absint( $current['contact_id'] ?? 0 );
		$user_id    = absint( $current['user_id'] ?? 0 );

		if ( $contact_id ) {
			Activity_Service::log_contact_activity(
				$contact_id,
				'deal_stage_changed',
				array(
					'deal_id'      => $deal_id,
					'from_stage'   => sanitize_key( (string) ( $current['stage'] ?? '' ) ),
					'to_stage'     => $to_stage,
					'lost_reason'  => sanitize_text_field( $lost_reason ),
				)
			);
		}

		if ( $user_id ) {
			Activity_Service::log_user_activity(
				$user_id,
				'crm_deal_stage_changed',
				array(
					'deal_id'    => $deal_id,
					'to_stage'   => $to_stage,
				)
			);
		}

		if ( 'payment_pending' === $to_stage ) {
			Task_Service::create_task(
				array(
					'title'       => __( 'Recordar pago pendiente del lead', 'atora-lms' ),
					'contact_id'  => $contact_id,
					'user_id'     => $user_id,
					'related_type'=> 'deal',
					'related_id'  => $deal_id,
					'task_type'   => 'payment',
					'priority'    => 'high',
					'due_at'      => gmdate( 'Y-m-d H:i:s', strtotime( '+1 day', current_time( 'timestamp', true ) ) ),
				)
			);
		}

		if ( in_array( $to_stage, array( 'enrolled', 'won' ), true ) ) {
			self::maybe_mark_contact_as_student( $contact_id );
		}

		return true;
	}

	/**
	 * El deal más reciente (no ganado/perdido con preferencia, pero
	 * cualquiera si no hay otro) de cada contacto pedido — usado por
	 * Commercial_Domain_Provider para la plantilla "Cuenta clave"
	 * (PT-2.1, sprint 6.7.0), donde el vendedor selecciona contactos a
	 * mano en vez de filtrar por etapa. Reutiliza get_contact_deals()
	 * por contacto en vez de una query nueva — N contactos de "Cuenta
	 * clave" es una lista corta seleccionada a mano, no un roster masivo.
	 *
	 * @param array<int,int> $contact_ids IDs de contacto.
	 * @return array<int,array<string,mixed>> Un deal normalizado por contact_id (el más reciente), en el mismo orden de $contact_ids.
	 */
	public static function get_latest_deal_per_contact( array $contact_ids ): array {
		$deals = array();
		foreach ( array_unique( array_map( 'absint', $contact_ids ) ) as $contact_id ) {
			if ( ! $contact_id ) {
				continue;
			}
			$contact_deals = self::get_contact_deals( $contact_id, 1 );
			if ( ! empty( $contact_deals ) ) {
				$deals[ $contact_id ] = $contact_deals[0];
			}
		}
		return $deals;
	}

	/**
	 * @param int $contact_id
	 * @param int $limit      Límite.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_contact_deals( int $contact_id, int $limit = 10 ): array {
		global $wpdb;

		$table      = $wpdb->prefix . 'atora_crm_deals';
		$contact_id = absint( $contact_id );
		$limit      = max( 1, min( 100, absint( $limit ) ) );
		if ( ! $contact_id || ! DB_Service::table_exists( $table ) ) {
			return array();
		}

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d ORDER BY updated_at DESC LIMIT %d", $contact_id, $limit ),
			ARRAY_A
		);

		$normalized = array();
		foreach ( $rows as $row ) {
			$normalized[] = self::normalize_deal_row( $row );
		}

		return $normalized;
	}

	/**
	 * Resumen comercial del pipeline.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_summary(): array {
		$board = self::get_board();
		$items = (array) ( $board['items'] ?? array() );

		$totals = array(
			'total'      => 0,
			'open'       => 0,
			'won'        => 0,
			'lost'       => 0,
			'pipeline_value' => 0.0,
		);

		foreach ( $items as $stage => $rows ) {
			foreach ( (array) $rows as $row ) {
				$totals['total']++;
				if ( in_array( $stage, array( 'won', 'enrolled' ), true ) ) {
					$totals['won']++;
				} elseif ( 'lost' === $stage ) {
					$totals['lost']++;
				} else {
					$totals['open']++;
					$totals['pipeline_value'] += (float) ( $row['estimated_value'] ?? 0 );
				}
			}
		}

		return $totals;
	}

	/**
	 * Garantiza un deal activo para el contacto (idempotente).
	 *
	 * @param int                $contact_id Contacto.
	 * @param array<string,mixed> $args      Datos opcionales.
	 * @return int ID del deal existente/creado.
	 */
	public static function ensure_deal_for_contact( int $contact_id, array $args = array() ): int {
		global $wpdb;

		$contact_id = absint( $contact_id );
		if ( $contact_id <= 0 ) {
			return 0;
		}

		$table = $wpdb->prefix . 'atora_crm_deals';
		if ( ! DB_Service::table_exists( $table ) ) {
			DB_Service::maybe_install_schema();
			if ( ! DB_Service::table_exists( $table ) ) {
				return 0;
			}
		}

		$existing_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE contact_id = %d
				   AND stage NOT IN ('won','lost')
				 ORDER BY updated_at DESC
				 LIMIT 1",
				$contact_id
			)
		);
		if ( $existing_id > 0 ) {
			return $existing_id;
		}

		$title = sanitize_text_field( (string) ( $args['title'] ?? __( 'Lead sin nombre', 'atora-lms' ) ) );
		if ( '' === $title ) {
			$title = __( 'Lead sin nombre', 'atora-lms' );
		}
		$user_id     = absint( $args['user_id'] ?? 0 );
		$channel     = sanitize_key( (string) ( $args['channel'] ?? 'email' ) );
		$temperature = sanitize_key( (string) ( $args['temperature'] ?? 'warm' ) );
		$stage       = sanitize_key( (string) ( $args['stage'] ?? 'new_lead' ) );
		if ( ! isset( self::get_stages()[ $stage ] ) ) {
			$stage = 'new_lead';
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'contact_id'      => $contact_id,
				'user_id'         => $user_id,
				'title'           => $title,
				'channel'         => '' !== $channel ? $channel : 'email',
				'temperature'     => '' !== $temperature ? $temperature : 'warm',
				'stage'           => $stage,
				'next_action'     => __( 'Primer contacto comercial', 'atora-lms' ),
				'assigned_to'     => get_current_user_id(),
				'estimated_value' => 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f' )
		);
		if ( ! $inserted ) {
			return 0;
		}

		return absint( $wpdb->insert_id );
	}

	/**
	 * Crea deals base desde leads/prospectos para no iniciar vacío.
	 *
	 * @return void
	 */
	private static function seed_from_contacts(): void {
		global $wpdb;

		$deals_table    = $wpdb->prefix . 'atora_crm_deals';
		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $deals_table ) || ! DB_Service::table_exists( $contacts_table ) ) {
			return;
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$deals_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $count > 0 ) {
			return;
		}

		$contacts = (array) $wpdb->get_results(
			"SELECT id, user_id, name, status, source FROM {$contacts_table} WHERE status IN ('lead','prospect') ORDER BY updated_at DESC LIMIT 80",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $contacts as $contact ) {
			$contact_id = absint( $contact['id'] ?? 0 );
			if ( ! $contact_id ) {
				continue;
			}
			$title = sanitize_text_field( (string) ( $contact['name'] ?: __( 'Lead sin nombre', 'atora-lms' ) ) );
			$wpdb->insert(
				$deals_table,
				array(
					'contact_id'      => $contact_id,
					'user_id'         => absint( $contact['user_id'] ?? 0 ),
					'title'           => $title,
					'channel'         => 'email',
					'temperature'     => 'warm',
					'stage'           => 'new_lead',
					'next_action'     => __( 'Primer contacto comercial', 'atora-lms' ),
					'assigned_to'     => get_current_user_id(),
					'estimated_value' => 0,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f' )
			);
		}
	}

	/**
	 * Normaliza fila de deal.
	 *
	 * @param array $row Fila DB.
	 * @return array<string,mixed>
	 */
	private static function normalize_deal_row( array $row ): array {
		$stage  = sanitize_key( (string) ( $row['stage'] ?? 'new_lead' ) );
		$stages = self::get_stages();

		return array(
			'id'             => absint( $row['id'] ?? 0 ),
			'contact_id'     => absint( $row['contact_id'] ?? 0 ),
			'user_id'        => absint( $row['user_id'] ?? 0 ),
			'title'          => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			'course_id'      => absint( $row['course_id'] ?? 0 ),
			'channel'        => sanitize_key( (string) ( $row['channel'] ?? 'email' ) ),
			'temperature'    => sanitize_key( (string) ( $row['temperature'] ?? 'warm' ) ),
			'stage'          => $stage,
			'stage_label'    => (string) ( $stages[ $stage ]['label'] ?? $stage ),
			'next_action'    => sanitize_text_field( (string) ( $row['next_action'] ?? '' ) ),
			'assigned_to'    => absint( $row['assigned_to'] ?? 0 ),
			'estimated_value'=> (float) ( $row['estimated_value'] ?? 0 ),
			'lost_reason'    => sanitize_text_field( (string) ( $row['lost_reason'] ?? '' ) ),
			'updated_at'     => sanitize_text_field( (string) ( $row['updated_at'] ?? '' ) ),
		);
	}

	/**
	 * Marca contacto como estudiante al ganar/inscribir.
	 *
	 * @param int $contact_id Contacto.
	 * @return void
	 */
	private static function maybe_mark_contact_as_student( int $contact_id ): void {
		global $wpdb;

		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return;
		}

		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $contacts_table ) ) {
			return;
		}

		$wpdb->update(
			$contacts_table,
			array( 'status' => 'student' ),
			array( 'id' => $contact_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
