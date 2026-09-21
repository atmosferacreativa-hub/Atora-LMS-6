<?php
/**
 * Rubric_Service — puerta única de lectura/escritura de rúbricas en tablas.
 *
 * @package ATORA_LMS\Rubrics
 * @since   6.26.5
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Rubric_Service {

	private const TABLE_RUBRICS     = 'atora_rubrics';
	private const TABLE_CRITERIA    = 'atora_rubric_criteria';
	private const TABLE_LEVELS      = 'atora_rubric_levels';
	private const TABLE_EVALUATIONS = 'atora_rubric_evaluations';

	/**
	 * @return bool
	 */
	private static function schema_ready(): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_RUBRICS;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/**
	 * Rubric por wp_post_id.
	 *
	 * @param int $wp_post_id
	 * @return array<string,mixed>|null
	 */
	public static function get( int $wp_post_id ): ?array {
		global $wpdb;
		$wp_post_id = absint( $wp_post_id );
		if ( $wp_post_id <= 0 ) { return null; }

		if ( ! self::schema_ready() ) {
			return self::legacy_get_from_post( $wp_post_id );
		}

		$table = $wpdb->prefix . self::TABLE_RUBRICS;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		if ( is_array( $row ) ) {
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['wp_post_id'] = absint( $row['wp_post_id'] ?? 0 );
			$row['institution_id'] = absint( $row['institution_id'] ?? 0 );
			$row['revision'] = absint( $row['revision'] ?? 1 );
			$row['is_holistic'] = ! empty( $row['is_holistic'] ) ? 1 : 0;
			$row['total_points'] = absint( $row['total_points'] ?? 0 );
			return $row;
		}

		return self::legacy_get_from_post( $wp_post_id );
	}

	/**
	 * @param int $wp_post_id
	 * @return int
	 */
	public static function get_revision( int $wp_post_id ): int {
		$r = self::get( $wp_post_id );
		return absint( is_array( $r ) ? ( $r['revision'] ?? 1 ) : 1 );
	}

	/**
	 * @param int      $wp_post_id
	 * @param int|null $revision
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_criteria( int $wp_post_id, ?int $revision = null ): array {
		global $wpdb;
		$wp_post_id = absint( $wp_post_id );
		if ( $wp_post_id <= 0 ) { return array(); }

		$rubric = self::get( $wp_post_id );
		if ( ! is_array( $rubric ) ) { return array(); }

		$revision = null === $revision ? absint( $rubric['revision'] ?? 1 ) : max( 1, absint( $revision ) );

		if ( ! self::schema_ready() || empty( $rubric['id'] ) ) {
			return (array) ( $rubric['criteria'] ?? array() );
		}

		$criteria_table = $wpdb->prefix . self::TABLE_CRITERIA;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$criteria_table}
				 WHERE rubric_id = %d AND revision = %d
				 ORDER BY criterion_order ASC, id ASC",
				absint( $rubric['id'] ),
				$revision
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row = is_array( $row ) ? $row : array();
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['rubric_id'] = absint( $row['rubric_id'] ?? 0 );
			$row['revision'] = absint( $row['revision'] ?? 1 );
			$row['criterion_order'] = absint( $row['criterion_order'] ?? 0 );
			$row['max_points'] = absint( $row['max_points'] ?? 0 );
			$row['weight'] = isset( $row['weight'] ) ? (float) $row['weight'] : 0.0;
			$row['levels'] = self::get_levels( absint( $row['id'] ?? 0 ) );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * @param int $criterion_id
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_levels( int $criterion_id ): array {
		global $wpdb;
		$criterion_id = absint( $criterion_id );
		if ( $criterion_id <= 0 || ! self::schema_ready() ) { return array(); }

		$table = $wpdb->prefix . self::TABLE_LEVELS;
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE criterion_id = %d ORDER BY level_order ASC, id ASC",
				$criterion_id
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$row = is_array( $row ) ? $row : array();
			$row['id'] = absint( $row['id'] ?? 0 );
			$row['criterion_id'] = absint( $row['criterion_id'] ?? 0 );
			$row['level_order'] = absint( $row['level_order'] ?? 0 );
			$row['points'] = absint( $row['points'] ?? 0 );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Crea una nueva revisión inmutable (inserta criterio+niveles completos).
	 *
	 * No borra postmeta.
	 *
	 * @param int   $wp_post_id
	 * @param array $rubric_data {title,slug,scale_type,scale_code,is_holistic,scope,owner_id,status}
	 * @param array $criteria    Lista de criterios normalizados.
	 * @return array{rubric_id:int,revision:int}|WP_Error
	 */
	public static function create_revision( int $wp_post_id, array $rubric_data, array $criteria ) {
		global $wpdb;

		$wp_post_id = absint( $wp_post_id );
		if ( $wp_post_id <= 0 ) {
			return new \WP_Error( 'atora_rubrics_invalid_post', 'wp_post_id inválido.' );
		}
		if ( ! self::schema_ready() ) {
			return new \WP_Error( 'atora_rubrics_schema_missing', 'El esquema de rúbricas no está instalado.' );
		}

		$institution_id = absint( $rubric_data['institution_id'] ?? 0 );
		if ( $institution_id <= 0 && class_exists( '\ATORA\LMS\Tenant_Context' ) ) {
			$inst = \ATORA\LMS\Tenant_Context::require_current_institution_id();
			if ( ! is_wp_error( $inst ) ) {
				$institution_id = absint( $inst );
			}
		}
		if ( $institution_id <= 0 ) {
			$institution_id = absint( (int) get_option( 'atora_default_institution', 0 ) );
		}

		$rubrics_table  = $wpdb->prefix . self::TABLE_RUBRICS;
		$criteria_table = $wpdb->prefix . self::TABLE_CRITERIA;
		$levels_table   = $wpdb->prefix . self::TABLE_LEVELS;

		$existing = (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT id, revision FROM {$rubrics_table} WHERE wp_post_id = %d LIMIT 1", $wp_post_id ),
			ARRAY_A
		);
		$rubric_id = absint( $existing['id'] ?? 0 );
		$current_revision = absint( $existing['revision'] ?? 0 );

		$title       = sanitize_text_field( (string) ( $rubric_data['title'] ?? '' ) );
		$slug        = sanitize_title( (string) ( $rubric_data['slug'] ?? '' ) );
		$scale_type  = sanitize_key( (string) ( $rubric_data['scale_type'] ?? '' ) );
		$scale_code  = sanitize_key( (string) ( $rubric_data['scale_code'] ?? '' ) );
		$is_holistic = ! empty( $rubric_data['is_holistic'] ) ? 1 : 0;
		$scope       = sanitize_key( (string) ( $rubric_data['scope'] ?? 'institution' ) );
		$owner_id    = absint( $rubric_data['owner_id'] ?? 0 );
		$status      = sanitize_key( (string) ( $rubric_data['status'] ?? 'active' ) );

		$total_points = 0;
		foreach ( (array) $criteria as $c ) {
			$c = is_array( $c ) ? $c : array();
			$total_points += absint( $c['max_points'] ?? 0 );
		}

		// Transacción para coherencia (rubric + criteria + levels).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( $rubric_id <= 0 ) {
				$inserted = $wpdb->insert(
					$rubrics_table,
					array(
						'institution_id' => $institution_id,
						'wp_post_id'     => $wp_post_id,
						'title'          => $title,
						'slug'           => $slug,
						'scale_type'     => $scale_type,
						'scale_code'     => $scale_code,
						'is_holistic'    => $is_holistic,
						'total_points'   => $total_points,
						'revision'       => 1,
						'scope'          => $scope,
						'owner_id'       => $owner_id,
						'status'         => $status,
					),
					array( '%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%d','%s' )
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( 'No se pudo insertar atora_rubrics.' );
				}
				$rubric_id = absint( $wpdb->insert_id );
				$new_revision = 1;
			} else {
				$new_revision = max( 1, $current_revision + 1 );
				$updated = $wpdb->update(
					$rubrics_table,
					array(
						'title'        => $title,
						'slug'         => $slug,
						'scale_type'   => $scale_type,
						'scale_code'   => $scale_code,
						'is_holistic'  => $is_holistic,
						'total_points' => $total_points,
						'revision'     => $new_revision,
						'scope'        => $scope,
						'owner_id'     => $owner_id,
						'status'       => $status,
					),
					array( 'id' => $rubric_id ),
					array( '%s','%s','%s','%s','%d','%d','%d','%s','%d','%s' ),
					array( '%d' )
				);
				if ( false === $updated ) {
					throw new \RuntimeException( 'No se pudo actualizar atora_rubrics.' );
				}
			}

			$order = 0;
			foreach ( (array) $criteria as $c ) {
				$c = is_array( $c ) ? $c : array();

				$title_c       = sanitize_text_field( (string) ( $c['name'] ?? $c['title'] ?? '' ) );
				$desc_c        = wp_kses_post( (string) ( $c['description'] ?? '' ) );
				$max_points_c  = absint( $c['max_points'] ?? 0 );
				$weight_c      = isset( $c['weight'] ) ? (float) $c['weight'] : 0.0;
				$type_c        = sanitize_key( (string) ( $c['type'] ?? 'structured' ) );
				$competency    = sanitize_text_field( (string) ( $c['competency'] ?? '' ) );
				$competency_id = sanitize_key( (string) ( $c['competency_id'] ?? '' ) );
				$improvement   = sanitize_textarea_field( (string) ( $c['improvement_tip'] ?? '' ) );
				$nl_prompt     = isset( $c['nl_prompt'] ) ? (string) $c['nl_prompt'] : null;
				$nl_prompt     = null !== $nl_prompt ? wp_kses_post( $nl_prompt ) : null;

				$inserted = $wpdb->insert(
					$criteria_table,
					array(
						'rubric_id'       => $rubric_id,
						'revision'        => $new_revision,
						'criterion_order' => $order,
						'title'           => $title_c,
						'description'     => $desc_c,
						'max_points'      => $max_points_c,
						'weight'          => $weight_c,
						'type'            => $type_c,
						'competency'      => $competency,
						'competency_id'   => $competency_id,
						'improvement_tip' => $improvement,
						'nl_prompt'       => $nl_prompt,
					),
					array( '%d','%d','%d','%s','%s','%d','%f','%s','%s','%s','%s','%s' )
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( 'No se pudo insertar atora_rubric_criteria.' );
				}
				$criterion_id = absint( $wpdb->insert_id );

				$levels = isset( $c['levels'] ) && is_array( $c['levels'] ) ? (array) $c['levels'] : array();
				$lorder = 0;
				foreach ( $levels as $level ) {
					$level = is_array( $level ) ? $level : array();
					$wpdb->insert(
						$levels_table,
						array(
							'criterion_id' => $criterion_id,
							'level_order'  => $lorder,
							'label'        => sanitize_text_field( (string) ( $level['label'] ?? '' ) ),
							'points'       => absint( $level['points'] ?? 0 ),
							'descriptor'   => wp_kses_post( (string) ( $level['descriptor'] ?? '' ) ),
						),
						array( '%d','%d','%s','%d','%s' )
					);
					++$lorder;
				}

				++$order;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return array( 'rubric_id' => $rubric_id, 'revision' => $new_revision );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'atora_rubrics_write_failed', $e->getMessage() );
		}
	}

	/**
	 * Registra una evaluación inmutable (snapshot_json).
	 *
	 * @param array $data
	 * @return int|\WP_Error evaluation_id
	 */
	public static function record_evaluation( array $data ) {
		global $wpdb;

		if ( ! self::schema_ready() ) {
			return new \WP_Error( 'atora_rubrics_schema_missing', 'El esquema de rúbricas no está instalado.' );
		}

		$table = $wpdb->prefix . self::TABLE_EVALUATIONS;

		$institution_id   = absint( $data['institution_id'] ?? 0 );
		$submission_id    = absint( $data['submission_id'] ?? 0 );
		$wp_submission_id = absint( $data['wp_submission_id'] ?? 0 );
		$student_id       = absint( $data['student_id'] ?? 0 );
		$rubric_id        = absint( $data['rubric_id'] ?? 0 );
		$rubric_revision  = max( 1, absint( $data['rubric_revision'] ?? 1 ) );

		$snapshot_json = (string) ( $data['snapshot_json'] ?? '' );
		if ( '' === trim( $snapshot_json ) ) {
			return new \WP_Error( 'atora_rubrics_missing_snapshot', 'snapshot_json requerido.' );
		}

		$hash = hash( 'sha256', $snapshot_json );

		$inserted = $wpdb->insert(
			$table,
			array(
				'institution_id'   => $institution_id,
				'submission_id'    => $submission_id,
				'wp_submission_id' => $wp_submission_id,
				'student_id'       => $student_id,
				'rubric_id'        => $rubric_id,
				'rubric_revision'  => $rubric_revision,
				'total_points'     => absint( $data['total_points'] ?? 0 ),
				'earned_points'    => absint( $data['earned_points'] ?? 0 ),
				'scale_type'       => sanitize_key( (string) ( $data['scale_type'] ?? '' ) ),
				'scale_code'       => sanitize_key( (string) ( $data['scale_code'] ?? '' ) ),
				'holistic_score'   => isset( $data['holistic_score'] ) ? absint( $data['holistic_score'] ) : null,
				'holistic_comment' => isset( $data['holistic_comment'] ) ? wp_kses_post( (string) $data['holistic_comment'] ) : null,
				'snapshot_json'    => $snapshot_json,
				'hash'             => $hash,
				'source'           => sanitize_key( (string) ( $data['source'] ?? 'speedgrader' ) ),
				'grader_id'        => absint( $data['grader_id'] ?? 0 ),
				'on_behalf_of'     => absint( $data['on_behalf_of'] ?? 0 ),
				'ai_assisted'      => ! empty( $data['ai_assisted'] ) ? 1 : 0,
			),
			array( '%d','%d','%d','%d','%d','%d','%d','%d','%s','%s','%d','%s','%s','%s','%s','%d','%d','%d' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'atora_rubrics_eval_write_failed', 'No se pudo insertar evaluación.' );
		}
		return absint( $wpdb->insert_id );
	}

	/**
	 * Última evaluación registrada para una entrega WP (wp_post_id del submission CPT).
	 *
	 * @param int $wp_submission_id
	 * @return array<string,mixed>|null
	 */
	public static function get_evaluation( int $wp_submission_id ): ?array {
		global $wpdb;
		$wp_submission_id = absint( $wp_submission_id );
		if ( $wp_submission_id <= 0 || ! self::schema_ready() ) { return null; }

		$table = $wpdb->prefix . self::TABLE_EVALUATIONS;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE wp_submission_id = %d ORDER BY id DESC LIMIT 1",
				$wp_submission_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fallback de lectura legacy desde postmeta.
	 *
	 * @param int $wp_post_id
	 * @return array<string,mixed>|null
	 */
	private static function legacy_get_from_post( int $wp_post_id ): ?array {
		$wp_post_id = absint( $wp_post_id );
		if ( $wp_post_id <= 0 ) {
			return null;
		}

		$criteria = get_post_meta( $wp_post_id, '_clms_rubric_criteria', true );
		$criteria = is_array( $criteria ) ? $criteria : array();

		$total_points = 0;
		foreach ( $criteria as $c ) {
			$c = is_array( $c ) ? $c : array();
			$total_points += absint( $c['max_points'] ?? 0 );
		}

		$scale_type  = sanitize_key( (string) get_post_meta( $wp_post_id, '_clms_rubric_scale_type', true ) );
		$is_holistic = '1' === (string) get_post_meta( $wp_post_id, '_clms_rubric_is_holistic', true ) ? 1 : 0;

		return array(
			'id'            => 0,
			'institution_id'=> absint( (int) get_option( 'atora_default_institution', 0 ) ),
			'wp_post_id'    => $wp_post_id,
			'title'         => (string) get_the_title( $wp_post_id ),
			'slug'          => sanitize_title( (string) get_post_field( 'post_name', $wp_post_id ) ),
			'scale_type'    => $scale_type,
			'scale_code'    => '',
			'is_holistic'   => $is_holistic,
			'total_points'  => absint( $total_points ),
			'revision'      => 1,
			'scope'         => 'institution',
			'owner_id'      => absint( (int) get_post_field( 'post_author', $wp_post_id ) ),
			'status'        => 'active',
			'criteria'      => $criteria,
		);
	}
}
