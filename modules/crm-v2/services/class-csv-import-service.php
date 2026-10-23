<?php
/**
 * Servicio de importación CSV — CRM v2.
 *
 * @package ATORA_LMS\CRM_V2
 */

namespace ATORA\CRM_V2\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Import_Service {
	/**
	 * Vista previa de CSV (headers + N filas).
	 *
	 * @param string $csv_csv Contenido CSV.
	 * @param int    $max_rows Máximo de filas.
	 * @return array{headers:array<int,string>,rows:array<int,array<int,string>>}
	 */
	public static function preview_csv( string $csv_csv, int $max_rows = 10 ): array {
		$max_rows = max( 1, min( 50, absint( $max_rows ) ) );
		$stream   = self::open_stream_from_string( $csv_csv );
		if ( ! is_resource( $stream ) ) {
			return array( 'headers' => array(), 'rows' => array() );
		}

		$headers = self::read_csv_headers( $stream );
		$rows    = array();
		while ( count( $rows ) < $max_rows ) {
			$line = fgetcsv( $stream );
			if ( false === $line ) {
				break;
			}
			$rows[] = array_map( static fn( $v ) => sanitize_text_field( (string) $v ), (array) $line );
		}
		fclose( $stream );

		return array(
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * Importa contactos desde CSV.
	 *
	 * @param string              $csv_csv        Contenido CSV.
	 * @param array<string,string> $mapping       Mapa header => campo (name,email,phone,country,city,status,source o custom:<field_key>).
	 * @param array<string,mixed> $options        Opciones (update_existing, batch_size).
	 * @return array{inserted:int,updated:int,skipped:int,errors:array<int,array<string,mixed>>}
	 */
	public static function import_csv( string $csv_csv, array $mapping = array(), array $options = array() ): array {
		global $wpdb;

		$institution_id = self::resolve_institution_id();
		if ( $institution_id <= 0 ) {
			return array(
				'inserted' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'errors'   => array(
					array( 'row' => 0, 'message' => __( 'Institución no resuelta.', 'atora-lms' ) ),
				),
			);
		}

		$contacts_table = $wpdb->prefix . 'atora_contacts';
		if ( ! DB_Service::table_exists( $contacts_table ) ) {
			return array(
				'inserted' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'errors'   => array(
					array( 'row' => 0, 'message' => __( 'Tabla de contactos no disponible.', 'atora-lms' ) ),
				),
			);
		}

		$update_existing = ! empty( $options['update_existing'] );
		$batch_size      = max( 50, min( 2000, absint( $options['batch_size'] ?? 200 ) ) );

		$stream = self::open_stream_from_string( $csv_csv );
		if ( ! is_resource( $stream ) ) {
			return array(
				'inserted' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'errors'   => array( array( 'row' => 0, 'message' => __( 'CSV inválido.', 'atora-lms' ) ) ),
			);
		}

		$headers = self::read_csv_headers( $stream );
		if ( empty( $headers ) ) {
			fclose( $stream );
			return array(
				'inserted' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'errors'   => array( array( 'row' => 0, 'message' => __( 'El CSV no tiene encabezados.', 'atora-lms' ) ) ),
			);
		}

		$mapping = empty( $mapping ) ? self::auto_mapping_from_headers( $headers ) : $mapping;
		$custom_definitions = self::get_custom_field_definitions_by_key();

		$inserted = 0;
		$updated  = 0;
		$skipped  = 0;
		$errors   = array();
		$row_num  = 1; // headers.
		$processed = 0;

		while ( true ) {
			$line = fgetcsv( $stream );
			if ( false === $line ) {
				break;
			}
			$row_num++;

			$assoc = self::row_to_assoc( $headers, (array) $line );
			$payload = self::map_row_to_contact_payload( $assoc, $mapping );
			$custom_values = (array) ( $payload['custom_values'] ?? array() );
			unset( $payload['custom_values'] );

			$name  = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
			$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );
			$phone = sanitize_text_field( (string) ( $payload['phone'] ?? '' ) );

			if ( '' === $name && '' === $email && '' === $phone ) {
				$skipped++;
				continue;
			}

			$row_errors = self::validate_custom_required_fields( $custom_values, $custom_definitions );
			if ( ! empty( $row_errors ) ) {
				$errors[] = array( 'row' => $row_num, 'message' => implode( '; ', $row_errors ) );
				continue;
			}

			$contact_id = 0;
			if ( $update_existing && ( '' !== $email || '' !== $phone ) ) {
				$contact_id = self::find_existing_contact_id( $institution_id, $email, $phone );
			}

			if ( $contact_id > 0 ) {
				$update_data = array();
				foreach ( array( 'name', 'email', 'phone', 'country', 'city', 'status', 'source' ) as $field ) {
					$val = $payload[ $field ] ?? null;
					if ( null === $val ) {
						continue;
					}
					$val = 'email' === $field ? sanitize_email( (string) $val ) : sanitize_text_field( (string) $val );
					if ( '' === $val ) {
						continue;
					}
					$update_data[ $field ] = $val;
				}
				$update_data['updated_at'] = current_time( 'mysql', true );

				if ( ! empty( $update_data ) ) {
					$wpdb->update( $contacts_table, $update_data, array( 'id' => $contact_id, 'institution_id' => $institution_id ) );
					$updated++;
					Audit_Service::log( 'contact_updated', 'contact', $contact_id, array( 'source' => 'csv_import' ), 0, $institution_id );
					Activity_Service::log_contact_activity( $contact_id, 'contact_updated', array( 'source' => 'csv_import' ) );
				}
			} else {
				$insert_data = array(
					'institution_id' => $institution_id,
					'name'           => $name ?: __( 'Contacto', 'atora-lms' ),
					'email'          => $email,
					'phone'          => $phone,
					'country'        => sanitize_text_field( (string) ( $payload['country'] ?? '' ) ),
					'city'           => sanitize_text_field( (string) ( $payload['city'] ?? '' ) ),
					'status'         => sanitize_key( (string) ( $payload['status'] ?? 'lead' ) ) ?: 'lead',
					'source'         => sanitize_key( (string) ( $payload['source'] ?? 'csv_import' ) ) ?: 'csv_import',
					'updated_at'     => current_time( 'mysql', true ),
				);

				$ok = $wpdb->insert( $contacts_table, $insert_data );
				if ( ! $ok ) {
					$errors[] = array( 'row' => $row_num, 'message' => __( 'No se pudo insertar el contacto.', 'atora-lms' ) );
					continue;
				}
				$contact_id = absint( $wpdb->insert_id );
				$inserted++;
				Audit_Service::log( 'contact_created', 'contact', $contact_id, array( 'source' => 'csv_import' ), 0, $institution_id );
				Activity_Service::log_contact_activity( $contact_id, 'contact_created', array( 'source' => 'csv_import' ) );
			}

			if ( $contact_id > 0 && ! empty( $custom_values ) ) {
				foreach ( $custom_values as $field_key => $val ) {
					$field_key = sanitize_key( (string) $field_key );
					if ( '' === $field_key ) {
						continue;
					}
					Contact_Service::save_contact_custom_value( $contact_id, $field_key, (string) $val );
				}
			}

			$processed++;
			if ( $processed % $batch_size === 0 ) {
				// Punto de control (no-op): la operación ya es incremental.
			}
		}

		fclose( $stream );

		Audit_Service::log(
			'contacts_imported',
			'import',
			0,
			array(
				'inserted' => $inserted,
				'updated'  => $updated,
				'skipped'  => $skipped,
			),
			0,
			$institution_id
		);

		return array(
			'inserted' => $inserted,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}

	/**
	 * @return resource|false
	 */
	private static function open_stream_from_string( string $csv ): mixed {
		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! is_resource( $stream ) ) {
			return false;
		}
		fwrite( $stream, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );
		return $stream;
	}

	/**
	 * @param resource $stream
	 * @return array<int,string>
	 */
	private static function read_csv_headers( $stream ): array {
		$line = fgetcsv( $stream );
		if ( false === $line ) {
			return array();
		}

		$headers = array();
		foreach ( (array) $line as $h ) {
			$headers[] = trim( (string) $h );
		}
		return $headers;
	}

	/**
	 * @param array<int,string> $headers
	 * @return array<string,string>
	 */
	private static function auto_mapping_from_headers( array $headers ): array {
		$mapping = array();
		foreach ( $headers as $h ) {
			$k = strtolower( trim( (string) $h ) );
			if ( in_array( $k, array( 'name', 'nombre' ), true ) ) {
				$mapping[ $h ] = 'name';
			} elseif ( in_array( $k, array( 'email', 'correo' ), true ) ) {
				$mapping[ $h ] = 'email';
			} elseif ( in_array( $k, array( 'phone', 'telefono', 'teléfono' ), true ) ) {
				$mapping[ $h ] = 'phone';
			} elseif ( in_array( $k, array( 'country', 'pais', 'país' ), true ) ) {
				$mapping[ $h ] = 'country';
			} elseif ( in_array( $k, array( 'city', 'ciudad' ), true ) ) {
				$mapping[ $h ] = 'city';
			} elseif ( 'status' === $k ) {
				$mapping[ $h ] = 'status';
			} elseif ( 'source' === $k ) {
				$mapping[ $h ] = 'source';
			} elseif ( 0 === strpos( $k, 'cf:' ) ) {
				$field_key      = sanitize_key( substr( (string) $h, 3 ) );
				$mapping[ $h ] = 'custom:' . $field_key;
			}
		}
		return $mapping;
	}

	/**
	 * @param array<int,string> $headers
	 * @param array<int,string> $values
	 * @return array<string,string>
	 */
	private static function row_to_assoc( array $headers, array $values ): array {
		$out = array();
		foreach ( $headers as $i => $h ) {
			$out[ $h ] = sanitize_text_field( (string) ( $values[ $i ] ?? '' ) );
		}
		return $out;
	}

	/**
	 * @param array<string,string> $row
	 * @param array<string,string> $mapping
	 * @return array<string,mixed>
	 */
	private static function map_row_to_contact_payload( array $row, array $mapping ): array {
		$payload = array(
			'custom_values' => array(),
		);

		foreach ( $mapping as $header => $field ) {
			$raw = (string) ( $row[ $header ] ?? '' );
			$field_raw = (string) $field;
			if ( '' === $field_raw ) {
				continue;
			}

			if ( 0 === strpos( $field_raw, 'custom:' ) ) {
				$key = sanitize_key( substr( $field_raw, 7 ) );
				if ( '' !== $key ) {
					$payload['custom_values'][ $key ] = $raw;
				}
				continue;
			}

			$field = sanitize_key( $field_raw );
			if ( '' === $field ) {
				continue;
			}

			if ( in_array( $field, array( 'name', 'email', 'phone', 'country', 'city', 'status', 'source' ), true ) ) {
				$payload[ $field ] = $raw;
			}
		}

		return $payload;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_custom_field_definitions_by_key(): array {
		$defs = array();
		foreach ( (array) Contact_Service::get_custom_fields() as $field ) {
			$key = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}
			$defs[ $key ] = $field;
		}
		return $defs;
	}

	/**
	 * @param array<string,string>              $values
	 * @param array<string,array<string,mixed>> $defs
	 * @return array<int,string>
	 */
	private static function validate_custom_required_fields( array $values, array $defs ): array {
		$errors = array();
		foreach ( $defs as $key => $field ) {
			if ( empty( $field['is_required'] ) ) {
				continue;
			}
			$val = (string) ( $values[ $key ] ?? '' );
			if ( '' === trim( $val ) ) {
				$errors[] = sprintf( __( 'Campo requerido vacío: %s', 'atora-lms' ), $key );
			}
		}
		return $errors;
	}

	private static function resolve_institution_id(): int {
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			$resolved = \ATORA\LMS\Tenant_Context::current_institution_id();
			if ( is_wp_error( $resolved ) ) {
				return 0;
			}
			return absint( $resolved );
		}
		return 0;
	}

	private static function find_existing_contact_id( int $institution_id, string $email, string $phone ): int {
		global $wpdb;
		$institution_id = absint( $institution_id );
		$email = sanitize_email( $email );
		$phone = sanitize_text_field( $phone );
		$table = $wpdb->prefix . 'atora_contacts';
		if ( $institution_id <= 0 || ! DB_Service::table_exists( $table ) ) {
			return 0;
		}

		if ( '' !== $email ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE institution_id = %d AND LOWER(TRIM(email)) = LOWER(TRIM(%s)) LIMIT 1", $institution_id, $email )
			);
			if ( $id > 0 ) {
				return $id;
			}
		}

		if ( '' !== $phone ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE institution_id = %d AND (phone = %s OR whatsapp = %s) LIMIT 1", $institution_id, $phone, $phone )
			);
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}
}

