<?php
/**
 * Rubrics CLI — migración/verify de rúbricas desde postmeta a tablas.
 *
 * `wp atora rubrics migrate [--batch=100] [--dry-run] [--yes]`
 * `wp atora rubrics verify`
 *
 * @package ATORA_LMS\Rubrics
 * @since   6.26.5
 */

namespace ATORA\LMS;

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }

final class Rubrics_CLI {

	public static function init(): void {
		\WP_CLI::add_command( 'atora rubrics migrate', array( __CLASS__, 'migrate' ) );
		\WP_CLI::add_command( 'atora rubrics verify', array( __CLASS__, 'verify' ) );
	}

	public static function migrate( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] );
		$batch   = max( 10, min( 500, absint( $assoc_args['batch'] ?? 100 ) ) );

		$rubrics_table  = $wpdb->prefix . 'atora_rubrics';
		$criteria_table = $wpdb->prefix . 'atora_rubric_criteria';
		$levels_table   = $wpdb->prefix . 'atora_rubric_levels';

		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rubrics_table ) ) ) !== $rubrics_table ) {
			\WP_CLI::error( 'Tabla atora_rubrics no existe. Activa/actualiza el plugin primero.' );
			return;
		}

		if ( ! $dry_run && ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'Esto escribirá en tablas de rúbricas. ¿Continuar?', $assoc_args );
		}

		$institution_id = absint( (int) get_option( 'atora_default_institution', 0 ) );
		if ( $institution_id <= 0 && class_exists( '\ATORA\LMS\Institution_Service' ) ) {
			$institution_id = absint( \ATORA\LMS\Institution_Service::ensure_default_institution() );
			if ( $institution_id > 0 ) {
				update_option( 'atora_default_institution', $institution_id, false );
			}
		}

		$post_ids = get_posts( array(
			'post_type'      => array( 'clms_rubric', 'clms_rubric_preset' ),
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		$post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );

		$total = count( $post_ids );
		\WP_CLI::log( "Rúbricas detectadas (posts): {$total}" );

		$offset = 0;
		$migrated = 0;
		$skipped  = 0;
		$errors   = array();

		while ( $offset < $total ) {
			$chunk = array_slice( $post_ids, $offset, $batch );
			$offset += $batch;

			foreach ( $chunk as $wp_post_id ) {
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$rubrics_table} WHERE wp_post_id = %d", $wp_post_id )
				) > 0;
				if ( $exists ) {
					++$skipped;
					continue;
				}

				$criteria = get_post_meta( $wp_post_id, '_clms_rubric_criteria', true );
				$criteria = self::normalize_criteria_blob( $criteria, $wp_post_id, $errors );
				if ( null === $criteria ) {
					continue;
				}

				$total_points = 0;
				foreach ( $criteria as $c ) {
					$c = is_array( $c ) ? $c : array();
					$total_points += absint( $c['max_points'] ?? 0 );
				}

				$scale_type  = sanitize_key( (string) get_post_meta( $wp_post_id, '_clms_rubric_scale_type', true ) );
				$is_holistic = '1' === (string) get_post_meta( $wp_post_id, '_clms_rubric_is_holistic', true ) ? 1 : 0;

				$row = array(
					'institution_id' => $institution_id,
					'wp_post_id'     => $wp_post_id,
					'title'          => sanitize_text_field( (string) get_the_title( $wp_post_id ) ),
					'slug'           => sanitize_title( (string) get_post_field( 'post_name', $wp_post_id ) ),
					'scale_type'     => $scale_type,
					'scale_code'     => '',
					'is_holistic'    => $is_holistic,
					'total_points'   => $total_points,
					'revision'       => 1,
					'scope'          => 'institution',
					'owner_id'       => absint( (int) get_post_field( 'post_author', $wp_post_id ) ),
					'status'         => 'active',
				);

				if ( $dry_run ) {
					++$migrated;
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'START TRANSACTION' );
				try {
					$ok = $wpdb->insert(
						$rubrics_table,
						$row,
						array( '%d','%d','%s','%s','%s','%s','%d','%d','%d','%s','%d','%s' )
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'Insert rubrics falló.' );
					}
					$rubric_id = absint( $wpdb->insert_id );

					$order = 0;
					foreach ( $criteria as $c ) {
						$c = is_array( $c ) ? $c : array();

						$ok = $wpdb->insert(
							$criteria_table,
							array(
								'rubric_id'       => $rubric_id,
								'revision'        => 1,
								'criterion_order' => $order,
								'title'           => sanitize_text_field( (string) ( $c['name'] ?? '' ) ),
								'description'     => wp_kses_post( (string) ( $c['description'] ?? '' ) ),
								'max_points'      => absint( $c['max_points'] ?? 0 ),
								'weight'          => isset( $c['weight'] ) ? (float) $c['weight'] : 0.0,
								'type'            => sanitize_key( (string) ( $c['type'] ?? 'structured' ) ),
								'competency'      => sanitize_text_field( (string) ( $c['competency'] ?? '' ) ),
								'competency_id'   => sanitize_key( (string) ( $c['competency_id'] ?? '' ) ),
								'improvement_tip' => sanitize_textarea_field( (string) ( $c['improvement_tip'] ?? '' ) ),
								'nl_prompt'       => isset( $c['nl_prompt'] ) ? wp_kses_post( (string) $c['nl_prompt'] ) : null,
							),
							array( '%d','%d','%d','%s','%s','%d','%f','%s','%s','%s','%s','%s' )
						);
						if ( false === $ok ) {
							throw new \RuntimeException( 'Insert criteria falló.' );
						}
						$criterion_id = absint( $wpdb->insert_id );

						$levels = isset( $c['levels'] ) && is_array( $c['levels'] ) ? (array) $c['levels'] : array();
						$lorder = 0;
						foreach ( $levels as $lvl ) {
							$lvl = is_array( $lvl ) ? $lvl : array();
							$wpdb->insert(
								$levels_table,
								array(
									'criterion_id' => $criterion_id,
									'level_order'  => $lorder,
									'label'        => sanitize_text_field( (string) ( $lvl['label'] ?? '' ) ),
									'points'       => absint( $lvl['points'] ?? 0 ),
									'descriptor'   => wp_kses_post( (string) ( $lvl['descriptor'] ?? '' ) ),
								),
								array( '%d','%d','%s','%d','%s' )
							);
							++$lorder;
						}

						++$order;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'COMMIT' );
					++$migrated;
				} catch ( \Throwable $e ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'ROLLBACK' );
					$errors[] = "Rúbrica {$wp_post_id}: " . $e->getMessage();
				}
			}
		}

		\WP_CLI::log( 'Migradas: ' . absint( $migrated ) );
		\WP_CLI::log( 'Saltadas (ya existían): ' . absint( $skipped ) );

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $err ) {
				\WP_CLI::log( 'ERROR: ' . $err );
			}
			\WP_CLI::error( 'Migración incompleta: hay errores.' );
			return;
		}

		\WP_CLI::success( $dry_run ? 'Dry-run OK.' : 'Migración completada.' );
	}

	public static function verify( array $args, array $assoc_args ): void {
		global $wpdb;

		$rubrics_table  = $wpdb->prefix . 'atora_rubrics';
		$criteria_table = $wpdb->prefix . 'atora_rubric_criteria';

		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rubrics_table ) ) ) !== $rubrics_table ) {
			\WP_CLI::error( 'Tabla atora_rubrics no existe.' );
			return;
		}

		$rows = (array) $wpdb->get_results( "SELECT id, wp_post_id, revision, total_points FROM {$rubrics_table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$errors = array();

		foreach ( $rows as $row ) {
			$rubric_id  = absint( $row['id'] ?? 0 );
			$wp_post_id = absint( $row['wp_post_id'] ?? 0 );
			$rev        = max( 1, absint( $row['revision'] ?? 1 ) );

			if ( $wp_post_id <= 0 ) { continue; }

			$legacy = get_post_meta( $wp_post_id, '_clms_rubric_criteria', true );
			$legacy = self::normalize_criteria_blob( $legacy, $wp_post_id, $errors );
			if ( null === $legacy ) { continue; }

			$table_criteria = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT title, description, max_points, weight, competency, competency_id, improvement_tip FROM {$criteria_table} WHERE rubric_id = %d AND revision = %d ORDER BY criterion_order ASC, id ASC", $rubric_id, $rev ),
				ARRAY_A
			);

			$legacy_norm = array();
			foreach ( $legacy as $c ) {
				$c = is_array( $c ) ? $c : array();
				$legacy_norm[] = array(
					'title'           => (string) ( $c['name'] ?? '' ),
					'description'     => (string) ( $c['description'] ?? '' ),
					'max_points'      => absint( $c['max_points'] ?? 0 ),
					'weight'          => isset( $c['weight'] ) ? (float) $c['weight'] : 0.0,
					'competency'      => (string) ( $c['competency'] ?? '' ),
					'competency_id'   => (string) ( $c['competency_id'] ?? '' ),
					'improvement_tip' => (string) ( $c['improvement_tip'] ?? '' ),
				);
			}

			$table_norm = array();
			foreach ( $table_criteria as $c ) {
				$c = is_array( $c ) ? $c : array();
				$table_norm[] = array(
					'title'           => (string) ( $c['title'] ?? '' ),
					'description'     => (string) ( $c['description'] ?? '' ),
					'max_points'      => absint( $c['max_points'] ?? 0 ),
					'weight'          => isset( $c['weight'] ) ? (float) $c['weight'] : 0.0,
					'competency'      => (string) ( $c['competency'] ?? '' ),
					'competency_id'   => (string) ( $c['competency_id'] ?? '' ),
					'improvement_tip' => (string) ( $c['improvement_tip'] ?? '' ),
				);
			}

			if ( wp_json_encode( $legacy_norm ) !== wp_json_encode( $table_norm ) ) {
				$errors[] = "Divergencia en rúbrica wp_post_id={$wp_post_id} (rubric_id={$rubric_id}).";
			}

			$total_points = 0;
			foreach ( $legacy as $c ) {
				$c = is_array( $c ) ? $c : array();
				$total_points += absint( $c['max_points'] ?? 0 );
			}
			if ( $total_points !== absint( $row['total_points'] ?? 0 ) ) {
				$errors[] = "Total points no coincide en wp_post_id={$wp_post_id} (legacy={$total_points}, tabla=" . absint( $row['total_points'] ?? 0 ) . ').';
			}
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $err ) {
				\WP_CLI::log( 'ERROR: ' . $err );
			}
			\WP_CLI::error( 'Verify falló: divergencias detectadas.' );
			return;
		}

		\WP_CLI::success( 'Verify OK: cero divergencias (chequeo básico).' );
	}

	/**
	 * @param mixed $blob
	 * @param int   $wp_post_id
	 * @param array $errors
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function normalize_criteria_blob( $blob, int $wp_post_id, array &$errors ): ?array {
		$criteria = $blob;
		if ( is_string( $criteria ) && '' !== trim( $criteria ) ) {
			$decoded = json_decode( $criteria, true );
			if ( is_array( $decoded ) ) {
				$criteria = $decoded;
			} else {
				$maybe = maybe_unserialize( $criteria );
				if ( is_array( $maybe ) ) {
					$criteria = $maybe;
				}
			}
		}

		if ( ! is_array( $criteria ) ) {
			$errors[] = "Rúbrica {$wp_post_id}: criterios malformados (no parsea JSON/serialize).";
			return null;
		}

		return array_values( $criteria );
	}
}

