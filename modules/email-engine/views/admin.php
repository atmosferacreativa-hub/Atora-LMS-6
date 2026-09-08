<?php
/**
 * Email Engine Admin UI.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar correos.', 'atora-lms' ) );
}

$settings = (array) get_option( 'atora_email_engine_options', array() );
$status   = array(
	'type'    => '',
	'message' => '',
);
$identity_labels = array(
	'academia' => __( 'Academia / Admin', 'atora-lms' ),
	'teacher'  => __( 'Docencia', 'atora-lms' ),
	'admin'    => __( 'Comercial', 'atora-lms' ),
);

$to_scalar_string = static function ( $value, string $default = '' ): string {
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}

	return $default;
};

$pick_non_empty = static function ( array $values, string $default = '' ) use ( $to_scalar_string ): string {
	foreach ( $values as $value ) {
		$candidate = trim( $to_scalar_string( $value ) );
		if ( '' !== $candidate ) {
			return $candidate;
		}
	}

	return $default;
};

$normalize_identity_key = static function ( $identity ) use ( $to_scalar_string ): string {
	$identity = sanitize_key( $to_scalar_string( $identity ) );
	$aliases  = array(
		'docencia'       => 'teacher',
		'docente'        => 'teacher',
		'comercio'       => 'admin',
		'comercial'      => 'admin',
		'commercial'     => 'admin',
		'sales'          => 'admin',
		'venta'          => 'admin',
		'ventas'         => 'admin',
		'lead'           => 'admin',
		'leads'          => 'admin',
		'prospect'       => 'admin',
		'prospecto'      => 'admin',
		'administracion' => 'academia',
		'administration' => 'academia',
		'ops'            => 'academia',
		'operaciones'    => 'academia',
		'tienda'         => 'academia',
		'store'          => 'academia',
		'woocommerce'    => 'academia',
	);
	if ( isset( $aliases[ $identity ] ) ) {
		$identity = $aliases[ $identity ];
	}

	return in_array( $identity, array( 'academia', 'teacher', 'admin' ), true ) ? $identity : 'academia';
};

$identity_prefix = static function ( $identity ) use ( $normalize_identity_key ): string {
	$identity = $normalize_identity_key( $identity );
	$map      = array(
		'academia' => 'identity_academia',
		'teacher'  => 'identity_teacher',
		'admin'    => 'identity_admin',
	);
	return $map[ $identity ] ?? 'identity_academia';
};

$decrypt_secret = static function ( string $value ): string {
	$value = (string) $value;
	if ( '' === $value ) {
		return '';
	}
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return sanitize_text_field( $value );
	}

	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
	$decrypted = openssl_decrypt( $value, 'AES-256-CBC', $key, 0, $iv ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	return is_string( $decrypted ) ? $decrypted : sanitize_text_field( $value );
};

$send_direct_email = static function ( string $to, string $subject, string $body, $identity, ?string &$error_details = null ) use ( $settings, $normalize_identity_key, $identity_prefix, $to_scalar_string, $decrypt_secret, $pick_non_empty ): bool {
	$identity = $normalize_identity_key( $identity );
	$prefix   = $identity_prefix( $identity );
	$payload  = (bool) preg_match( '/<[^>]+>/', $body ) ? $body : wpautop( $body );
	$error_details = '';

	$identities_option = get_option( 'atora_email_identities', array() );
	$identities_option = is_array( $identities_option ) ? $identities_option : array();
	$identity_option_key_map = array(
		'academia' => 'academia',
		'teacher'  => 'comercio',
		'admin'    => 'administracion',
	);
	$candidates = array(
		$identity_option_key_map[ $identity ] ?? 'academia',
		$identity,
	);
	if ( 'teacher' === $identity ) {
		$candidates[] = 'docencia';
	}
	$candidates = array_values( array_unique( array_map( 'sanitize_key', $candidates ) ) );

	$identity_row = array();
	foreach ( $candidates as $candidate ) {
		$value = $identities_option[ $candidate ] ?? null;
		if ( is_array( $value ) ) {
			$identity_row = $value;
			break;
		}
	}

	$from_email = sanitize_email(
		$pick_non_empty(
			array(
				$settings[ $prefix . '_from_email' ] ?? '',
				$identity_row['from_email'] ?? '',
				$settings['from_email'] ?? '',
				get_option( 'admin_email' ),
			),
			(string) get_option( 'admin_email' )
		)
	);
	if ( ! $from_email || ! is_email( $from_email ) ) {
		$from_email = sanitize_email( (string) get_option( 'admin_email' ) );
	}
	$from_name = sanitize_text_field(
		$pick_non_empty(
			array(
				$settings[ $prefix . '_from_name' ] ?? '',
				$identity_row['from_name'] ?? '',
				$settings['from_name'] ?? '',
				get_bloginfo( 'name' ),
			),
			(string) get_bloginfo( 'name' )
		)
	);
	if ( '' === $from_name ) {
		$from_name = sanitize_text_field( (string) get_bloginfo( 'name' ) );
	}
	$reply_to = sanitize_email(
		$pick_non_empty(
			array(
				$settings[ $prefix . '_reply_to' ] ?? '',
				$identity_row['reply_to'] ?? '',
				$settings['reply_to'] ?? '',
				$from_email,
			),
			$from_email
		)
	);
	if ( $reply_to && ! is_email( $reply_to ) ) {
		$reply_to = $from_email;
	}

	$smtp_user = sanitize_text_field(
		$pick_non_empty(
			array(
				$identity_row['smtp_username'] ?? '',
				$identity_row['smtp_user'] ?? '',
				$settings['smtp_user'] ?? '',
			)
		)
	);
	$smtp_pass = '';
	if ( isset( $identity_row['smtp_password'] ) ) {
		$smtp_pass = $to_scalar_string( $identity_row['smtp_password'] );
	} elseif ( isset( $identity_row['smtp_password_encrypted'] ) ) {
		$smtp_pass = $decrypt_secret( (string) $identity_row['smtp_password_encrypted'] );
	} else {
		$smtp_pass = $to_scalar_string( $settings['smtp_pass'] ?? '' );
	}
	if ( '' === trim( $smtp_pass ) ) {
		$smtp_pass = $to_scalar_string( $settings['smtp_pass'] ?? '' );
	}

	$smtp_host = sanitize_text_field(
		$pick_non_empty(
			array(
				$settings['smtp_host'] ?? '',
				$identity_row['smtp_host'] ?? '',
			)
		)
	);
	$smtp_port = absint( $settings['smtp_port'] ?? $identity_row['smtp_port'] ?? 587 );
	if ( $smtp_port <= 0 ) {
		$smtp_port = 587;
	}
	$smtp_encryption = sanitize_key(
		$pick_non_empty(
			array(
				$settings['smtp_encryption'] ?? '',
				$identity_row['smtp_secure'] ?? '',
			)
		)
	);
	if ( ! in_array( $smtp_encryption, array( 'ssl', 'tls' ), true ) ) {
		$smtp_encryption = '';
	}
	$smtp_auth = isset( $identity_row['smtp_auth'] )
		? ! empty( $identity_row['smtp_auth'] )
		: ! empty( $settings['smtp_auth'] );
	if ( ! $smtp_auth && '' !== $smtp_user ) {
		$smtp_auth = true;
	}
	$provider = sanitize_key( $to_scalar_string( $settings['provider'] ?? 'smtp', 'smtp' ) );
	$provider_map = array(
		'smtp'     => array( 'class' => '\ATORA\EmailEngine\Providers\SMTP', 'file' => 'class-smtp.php' ),
		'brevo'    => array( 'class' => '\ATORA\EmailEngine\Providers\Brevo', 'file' => 'class-brevo.php' ),
		'sendgrid' => array( 'class' => '\ATORA\EmailEngine\Providers\SendGrid', 'file' => 'class-sendgrid.php' ),
		'mailgun'  => array( 'class' => '\ATORA\EmailEngine\Providers\Mailgun', 'file' => 'class-mailgun.php' ),
		'ses'      => array( 'class' => '\ATORA\EmailEngine\Providers\Amazon_SES', 'file' => 'class-amazon-ses.php' ),
		'postmark' => array( 'class' => '\ATORA\EmailEngine\Providers\Postmark', 'file' => 'class-postmark.php' ),
	);
	if ( ! isset( $provider_map[ $provider ] ) ) {
		$provider = 'smtp';
	}

	$mail_failed_handler = static function ( $wp_error ) use ( &$error_details ): void {
		if ( $wp_error instanceof \WP_Error ) {
			$messages = $wp_error->get_error_messages();
			if ( ! empty( $messages ) ) {
				$error_details = sanitize_text_field( implode( ' | ', $messages ) );
			}
		}
	};

	add_action( 'wp_mail_failed', $mail_failed_handler, 10, 1 );

	$provider_interface_file = ATORA_LMS_MODULES_DIR . 'email-engine/providers/class-provider-interface.php';
	if ( file_exists( $provider_interface_file ) ) {
		require_once $provider_interface_file;
	}
	$provider_def  = $provider_map[ $provider ] ?? $provider_map['smtp'];
	$provider_file = ATORA_LMS_MODULES_DIR . 'email-engine/providers/' . (string) ( $provider_def['file'] ?? '' );
	if ( file_exists( $provider_file ) ) {
		require_once $provider_file;
	}

	$provider_class = (string) ( $provider_def['class'] ?? '' );
	if ( '' !== $provider_class && class_exists( $provider_class ) && method_exists( $provider_class, 'send' ) ) {
		$sent = (bool) $provider_class::send(
			$to,
			$subject,
			$payload,
			wp_strip_all_tags( $payload ),
			array(
				'atora_queue_id'  => 0,
				'email_identity'  => $identity,
				'identity'        => $identity,
				'identity_key'    => $identity,
				'from_email'      => $from_email,
				'from_name'       => $from_name,
				'reply_to'        => $reply_to,
				'smtp_host'       => $smtp_host,
				'smtp_port'       => $smtp_port,
				'smtp_user'       => $smtp_user,
				'smtp_pass'       => $smtp_pass,
				'smtp_auth'       => $smtp_auth,
				'smtp_encryption' => $smtp_encryption,
			)
		);
		remove_action( 'wp_mail_failed', $mail_failed_handler, 10 );
		if ( ! $sent && '' === $error_details ) {
			$error_details = 'smtp' === $provider
				? __( 'SMTP rechazó el envío o la autenticación.', 'atora-lms' )
				: sprintf(
					/* translators: %s: provider key */
					__( 'El provider %s rechazó el envío o no está configurado correctamente.', 'atora-lms' ),
					strtoupper( $provider )
				);
		}
		return $sent;
	}

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( $reply_to && is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	$from_filter = static function () use ( $from_email ) { return $from_email; };
	$from_name_filter = static function () use ( $from_name ) { return $from_name; };
	add_filter( 'wp_mail_from', $from_filter );
	add_filter( 'wp_mail_from_name', $from_name_filter );

	try {
		$sent = (bool) wp_mail( $to, $subject, $payload, $headers );
		if ( ! $sent && '' === $error_details ) {
			$error_details = __( 'wp_mail devolvió false.', 'atora-lms' );
		}
		return $sent;
	} finally {
		remove_filter( 'wp_mail_from', $from_filter );
		remove_filter( 'wp_mail_from_name', $from_name_filter );
		remove_action( 'wp_mail_failed', $mail_failed_handler, 10 );
	}
};

$register_test_delivery = static function ( array $payload ) use ( $normalize_identity_key, $to_scalar_string ): array {
	global $wpdb;

	$result = array(
		'queue_id'    => 0,
		'user_id'     => 0,
		'crm_synced'  => false,
	);

	$queue_table = "{$wpdb->prefix}atora_email_queue";
	$event_table = "{$wpdb->prefix}atora_email_events";
	$queue_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) === $queue_table;
	if ( ! $queue_exists ) {
		return $result;
	}

	$recipient = sanitize_email( $to_scalar_string( $payload['recipient'] ?? '' ) );
	if ( '' === $recipient || ! is_email( $recipient ) ) {
		return $result;
	}

	$subject  = sanitize_text_field( substr( $to_scalar_string( $payload['subject'] ?? '' ), 0, 500 ) );
	$body     = (string) ( $payload['body_html'] ?? '' );
	$provider = sanitize_key( $to_scalar_string( $payload['provider'] ?? 'smtp', 'smtp' ) );
	if ( ! in_array( $provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
		$provider = 'smtp';
	}

	$status = sanitize_key( $to_scalar_string( $payload['status'] ?? 'failed', 'failed' ) );
	if ( ! in_array( $status, array( 'sent', 'failed', 'pending' ), true ) ) {
		$status = 'failed';
	}
	$identity      = $normalize_identity_key( $payload['identity'] ?? 'academia' );
	$error_message = sanitize_text_field( $to_scalar_string( $payload['error_message'] ?? '' ) );
	$template_key  = sanitize_key( $to_scalar_string( $payload['template_key'] ?? '' ) );
	$source        = sanitize_key( $to_scalar_string( $payload['source'] ?? 'email_engine_test' ) );
	if ( '' === $source ) {
		$source = 'email_engine_test';
	}

	$metadata = is_array( $payload['metadata'] ?? null ) ? $payload['metadata'] : array();
	$metadata['source']           = $source;
	$metadata['template']         = '' !== $template_key ? $template_key : sanitize_key( (string) ( $metadata['template'] ?? 'manual' ) );
	$metadata['email_identity']   = $identity;
	$metadata['identity']         = $identity;
	$metadata['identity_key']     = $identity;
	$metadata['recipient_email']  = $recipient;
	$metadata['test_send']        = 1;
	$metadata['sent_by_user_id']  = get_current_user_id();
	$metadata['sent_by_user']     = sanitize_text_field( wp_get_current_user()->user_login ?? '' );

	$recipient_user = get_user_by( 'email', $recipient );
	$user_id = $recipient_user instanceof \WP_User ? absint( $recipient_user->ID ) : 0;
	if ( $user_id > 0 ) {
		$metadata['user_id'] = $user_id;
	}
	$recipient_name = $recipient_user instanceof \WP_User
		? sanitize_text_field( (string) ( $recipient_user->display_name ?: $recipient_user->user_login ) )
		: '';

	$timestamp = current_time( 'mysql', true );
	$insert_data = array(
		'recipient_email' => $recipient,
		'recipient_name'  => $recipient_name,
		'user_id'         => $user_id,
		'template_id'     => 0,
		'subject'         => $subject,
		'body_html'       => (string) $body,
		'body_text'       => sanitize_textarea_field( wp_strip_all_tags( $body ) ),
		'provider'        => $provider,
		'status'          => $status,
		'scheduled_at'    => $timestamp,
		'error_message'   => $error_message,
		'retry_count'     => 0,
		'priority'        => 5,
		'metadata'        => wp_json_encode( $metadata ),
		'created_at'      => $timestamp,
	);
	$insert_formats = array( '%s','%s','%d','%d','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s' );
	if ( 'sent' === $status ) {
		$insert_data['sent_at'] = $timestamp;
		$insert_formats[]       = '%s';
	}

	$identity_column = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$queue_table} LIKE %s", 'identity_key' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( 'identity_key' === $identity_column ) {
		$insert_data['identity_key'] = $identity;
		$insert_formats[]            = '%s';
	}

	$inserted = $wpdb->insert( $queue_table, $insert_data, $insert_formats );
	if ( ! $inserted ) {
		return $result;
	}

	$queue_id = (int) $wpdb->insert_id;
	$result['queue_id'] = $queue_id;
	$result['user_id']  = $user_id;

	$event_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $event_table ) ) === $event_table;
	if ( $event_exists ) {
		$event_type = 'sent' === $status ? 'test_sent' : 'test_failed';
		$wpdb->insert(
			$event_table,
			array(
				'queue_id'   => $queue_id,
				'event_type' => $event_type,
				'event_data' => wp_json_encode(
					array(
						'source'       => $source,
						'provider'     => $provider,
						'template'     => $template_key,
						'identity_key' => $identity,
						'status'       => $status,
					)
				),
				'created_at' => $timestamp,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	if ( class_exists( '\ATORA\CRM\CRM' ) && method_exists( '\ATORA\CRM\CRM', 'sync_email_queue_to_conversation' ) ) {
		\ATORA\CRM\CRM::sync_email_queue_to_conversation( $queue_id );
		$result['crm_synced'] = true;
	}

	return $result;
};

$encrypt_secret = static function ( string $value ): string {
	$value = (string) $value;
	if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
		return '';
	}

	$key = hash( 'sha256', wp_salt( 'auth' ), true );
	$iv  = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
	$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	return is_string( $encrypted ) ? $encrypted : '';
};

$get_identity_diagnostics = static function ( string $identity ) use ( $normalize_identity_key, $identity_prefix, $to_scalar_string, $decrypt_secret, $pick_non_empty ): array {
	$identity = $normalize_identity_key( $identity );
	$prefix   = $identity_prefix( $identity );
	$settings = get_option( 'atora_email_engine_options', array() );
	$settings = is_array( $settings ) ? $settings : array();
	$provider = sanitize_key( $to_scalar_string( $settings['provider'] ?? 'smtp', 'smtp' ) );
	$valid_providers = array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' );
	if ( ! in_array( $provider, $valid_providers, true ) ) {
		$provider = 'smtp';
	}

	$identities_option = get_option( 'atora_email_identities', array() );
	$identities_option = is_array( $identities_option ) ? $identities_option : array();
	$key_map = array(
		'academia' => 'academia',
		'teacher'  => 'comercio',
		'admin'    => 'administracion',
	);
	$candidates = array(
		$key_map[ $identity ] ?? 'academia',
		$identity,
	);
	if ( 'teacher' === $identity ) {
		$candidates[] = 'docencia';
	}
	$candidates = array_values( array_unique( array_map( 'sanitize_key', $candidates ) ) );

	$identity_row = array();
	foreach ( $candidates as $candidate ) {
		$value = $identities_option[ $candidate ] ?? null;
		if ( is_array( $value ) ) {
			$identity_row = $value;
			break;
		}
	}

	$host = sanitize_text_field(
		$pick_non_empty(
			array(
				$settings['smtp_host'] ?? '',
				$identity_row['smtp_host'] ?? '',
			)
		)
	);
	$port = absint( $settings['smtp_port'] ?? $identity_row['smtp_port'] ?? 0 );
	$encryption = sanitize_key(
		$pick_non_empty(
			array(
				$settings['smtp_encryption'] ?? '',
				$identity_row['smtp_secure'] ?? '',
			)
		)
	);
	$user = sanitize_text_field(
		$pick_non_empty(
			array(
				$identity_row['smtp_username'] ?? '',
				$identity_row['smtp_user'] ?? '',
				$settings['smtp_user'] ?? '',
			)
		)
	);
	$pass = '';
	if ( isset( $identity_row['smtp_password'] ) ) {
		$pass = $to_scalar_string( $identity_row['smtp_password'] );
	} elseif ( isset( $identity_row['smtp_password_encrypted'] ) ) {
		$pass = $decrypt_secret( (string) $identity_row['smtp_password_encrypted'] );
	} else {
		$pass = $to_scalar_string( $settings['smtp_pass'] ?? '' );
	}
	if ( '' === trim( $pass ) ) {
		$pass = $to_scalar_string( $settings['smtp_pass'] ?? '' );
	}

	$from_email = sanitize_email(
		$pick_non_empty(
			array(
				$settings[ $prefix . '_from_email' ] ?? '',
				$identity_row['from_email'] ?? '',
				$settings['from_email'] ?? '',
			)
		)
	);
	$issues = array();

	if ( 'smtp' === $provider ) {
		if ( '' === $host ) {
			$issues[] = __( 'Falta SMTP host.', 'atora-lms' );
		}
		if ( $port <= 0 ) {
			$issues[] = __( 'Falta puerto SMTP.', 'atora-lms' );
		}
		if ( ! in_array( $encryption, array( 'ssl', 'tls' ), true ) ) {
			$issues[] = __( 'Seguridad SMTP debe ser SSL o TLS.', 'atora-lms' );
		}
		if ( '' === $user ) {
			$issues[] = __( 'Falta usuario SMTP.', 'atora-lms' );
		}
		if ( '' === $pass ) {
			$issues[] = __( 'Falta contraseña SMTP.', 'atora-lms' );
		}
		if ( 586 === $port ) {
			$issues[] = __( 'Puerto SMTP 586 parece inválido. Usa 465 (SSL) o 587 (TLS).', 'atora-lms' );
		}
	} else {
		$provider_requirements = array(
			'brevo'    => array( 'brevo_api_key' ),
			'sendgrid' => array( 'sendgrid_api_key' ),
			'mailgun'  => array( 'mailgun_api_key', 'mailgun_domain' ),
			'ses'      => array( 'ses_access_key', 'ses_secret_key' ),
			'postmark' => array( 'postmark_api_key' ),
		);
		$required_keys = (array) ( $provider_requirements[ $provider ] ?? array() );
		$missing_keys  = array();
		foreach ( $required_keys as $required_key ) {
			$required_key = sanitize_key( (string) $required_key );
			if ( '' === $required_key ) {
				continue;
			}
			$value = trim( $to_scalar_string( $settings[ $required_key ] ?? '' ) );
			if ( '' === $value ) {
				$missing_keys[] = $required_key;
			}
		}
		if ( ! empty( $missing_keys ) ) {
			$issues[] = sprintf(
				/* translators: 1: provider key, 2: missing keys */
				__( 'Faltan credenciales de %1$s: %2$s.', 'atora-lms' ),
				strtoupper( $provider ),
				implode( ', ', array_map( 'sanitize_key', $missing_keys ) )
			);
		}
	}
	if ( ! $from_email || ! is_email( $from_email ) ) {
		$issues[] = __( 'Email remitente inválido en la identidad.', 'atora-lms' );
	}
	return array(
		'identity'   => $identity,
		'host'       => $host,
		'port'       => $port,
		'encryption' => $encryption,
		'user'       => $user,
		'has_pass'   => '' !== $pass,
		'from_email' => $from_email,
		'provider'   => $provider,
		'mode'       => 'smtp' === $provider ? 'smtp' : 'api',
		'issues'     => $issues,
		'ready'      => empty( $issues ),
	);
};

$sync_identities_option = static function ( array $source_settings, $identity_to_update = '' ) use ( $encrypt_secret, $normalize_identity_key, $to_scalar_string ): void {
	$source_settings = is_array( $source_settings ) ? $source_settings : array();
	$provider      = sanitize_key( $to_scalar_string( $source_settings['provider'] ?? 'smtp', 'smtp' ) );
	$smtp_host     = sanitize_text_field( $to_scalar_string( $source_settings['smtp_host'] ?? '' ) );
	$smtp_port     = absint( $source_settings['smtp_port'] ?? 0 );
	$smtp_secure   = sanitize_key( $to_scalar_string( $source_settings['smtp_encryption'] ?? '' ) );
	$smtp_user     = sanitize_text_field( $to_scalar_string( $source_settings['smtp_user'] ?? '' ) );
	$smtp_pass     = $to_scalar_string( $source_settings['smtp_pass'] ?? '' );
	$imap_host     = sanitize_text_field( $to_scalar_string( $source_settings['imap_host'] ?? '' ) );
	$imap_port     = absint( $source_settings['imap_port'] ?? 0 );
	$imap_secure   = sanitize_key( $to_scalar_string( $source_settings['imap_secure'] ?? '' ) );
	$imap_user     = sanitize_text_field( $to_scalar_string( $source_settings['imap_user'] ?? '' ) );
	$imap_pass     = $to_scalar_string( $source_settings['imap_password'] ?? '' );
	$imap_enabled  = ! empty( $source_settings['imap_enabled'] ) ? 1 : 0;

	$valid_providers = array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' );
	if ( ! in_array( $provider, $valid_providers, true ) ) {
		$provider = 'smtp';
	}
	if ( ! in_array( $smtp_secure, array( 'ssl', 'tls' ), true ) ) {
		$smtp_secure = '';
	}
	if ( ! in_array( $imap_secure, array( 'ssl', 'tls' ), true ) ) {
		$imap_secure = '';
	}
	if ( $smtp_port <= 0 ) {
		$smtp_port = 587;
	}
	if ( $imap_port <= 0 ) {
		$imap_port = 993;
	}

	$identity_map = array(
		'academia'       => array(
			'label'         => __( 'Academia / Admin', 'atora-lms' ),
			'legacy_prefix' => 'identity_academia',
			'aliases'       => array( 'academia', 'administracion', 'administration', 'tienda', 'store' ),
		),
		'comercio'       => array(
			'label'         => __( 'Docencia', 'atora-lms' ),
			'legacy_prefix' => 'identity_teacher',
			'aliases'       => array( 'comercio', 'teacher', 'docencia', 'docente', 'seguimiento' ),
		),
		'administracion' => array(
			'label'         => __( 'Comercial', 'atora-lms' ),
			'legacy_prefix' => 'identity_admin',
			'aliases'       => array( 'administracion', 'admin', 'comercial', 'commercial', 'sales', 'venta', 'leads' ),
		),
	);

	$public_by_identity = array(
		'academia' => 'academia',
		'teacher'  => 'comercio',
		'admin'    => 'administracion',
	);
	$identity_to_update = '' !== $to_scalar_string( $identity_to_update )
		? $normalize_identity_key( $to_scalar_string( $identity_to_update ) )
		: '';
	$public_to_update   = $public_by_identity[ $identity_to_update ] ?? '';

	$existing = get_option( 'atora_email_identities', array() );
	$existing = is_array( $existing ) ? $existing : array();
	$has_existing = ! empty( $existing );

	$identities = array();
	foreach ( $identity_map as $public_key => $identity_info ) {
		$legacy_prefix = (string) $identity_info['legacy_prefix'];
		$aliases       = array_map( 'sanitize_key', (array) ( $identity_info['aliases'] ?? array() ) );

		$existing_row = array();
		foreach ( $aliases as $alias ) {
			$value = $existing[ $alias ] ?? null;
			if ( is_array( $value ) ) {
				$existing_row = $value;
				break;
			}
		}

		$should_update_transport = ! $has_existing || ( '' !== $public_to_update && $public_to_update === $public_key );
		$smtp_password_encrypted = sanitize_text_field( (string) ( $existing_row['smtp_password_encrypted'] ?? '' ) );
		$imap_password_encrypted = sanitize_text_field( (string) ( $existing_row['imap_password_encrypted'] ?? '' ) );

		if ( $should_update_transport ) {
			if ( '' !== $smtp_pass ) {
				$smtp_password_encrypted = $encrypt_secret( $smtp_pass );
				if ( '' === $smtp_password_encrypted ) {
					$row_plain_password = $smtp_pass;
				} else {
					$row_plain_password = '';
				}
			}
			if ( '' !== $imap_pass ) {
				$imap_password_encrypted = $encrypt_secret( $imap_pass );
				if ( '' === $imap_password_encrypted ) {
					$row_plain_imap_password = $imap_pass;
				} else {
					$row_plain_imap_password = '';
				}
			}
		}

		$active_value = ! empty( $source_settings[ $legacy_prefix . '_active' ] ) || ! isset( $source_settings[ $legacy_prefix . '_active' ] )
			? 1
			: 0;
		if ( isset( $existing_row['active'] ) && ! isset( $source_settings[ $legacy_prefix . '_active' ] ) ) {
			$active_value = ! empty( $existing_row['active'] ) ? 1 : 0;
		}

		$identities[ $public_key ] = array(
			'label'                   => sanitize_text_field( (string) ( $identity_info['label'] ?? ucfirst( $public_key ) ) ),
			'from_name'               => sanitize_text_field( (string) ( $source_settings[ $legacy_prefix . '_from_name' ] ?? $existing_row['from_name'] ?? '' ) ),
			'from_email'              => sanitize_email( (string) ( $source_settings[ $legacy_prefix . '_from_email' ] ?? $existing_row['from_email'] ?? '' ) ),
			'reply_to'                => sanitize_email( (string) ( $source_settings[ $legacy_prefix . '_reply_to' ] ?? $existing_row['reply_to'] ?? '' ) ),
			'provider'                => $should_update_transport
				? $provider
				: sanitize_key( (string) ( $existing_row['provider'] ?? $provider ) ),
			'smtp_host'               => $should_update_transport
				? $smtp_host
				: sanitize_text_field( (string) ( $existing_row['smtp_host'] ?? $smtp_host ) ),
			'smtp_port'               => $should_update_transport
				? $smtp_port
				: max( 1, absint( $existing_row['smtp_port'] ?? $smtp_port ) ),
			'smtp_secure'             => $should_update_transport
				? $smtp_secure
				: sanitize_key( (string) ( $existing_row['smtp_secure'] ?? $smtp_secure ) ),
			'smtp_username'           => $should_update_transport
				? $smtp_user
				: sanitize_text_field( (string) ( $existing_row['smtp_username'] ?? $smtp_user ) ),
			'smtp_password_encrypted' => $smtp_password_encrypted,
			'smtp_password'           => isset( $row_plain_password ) && '' !== $row_plain_password
				? $row_plain_password
				: ( '' === $smtp_password_encrypted ? (string) ( $existing_row['smtp_password'] ?? '' ) : '' ),
			'imap_enabled'            => $should_update_transport
				? $imap_enabled
				: ( ! empty( $existing_row['imap_enabled'] ) ? 1 : 0 ),
			'imap_host'               => $should_update_transport
				? $imap_host
				: sanitize_text_field( (string) ( $existing_row['imap_host'] ?? $imap_host ) ),
			'imap_port'               => $should_update_transport
				? $imap_port
				: max( 1, absint( $existing_row['imap_port'] ?? $imap_port ) ),
			'imap_secure'             => $should_update_transport
				? $imap_secure
				: sanitize_key( (string) ( $existing_row['imap_secure'] ?? $imap_secure ) ),
			'imap_username'           => $should_update_transport
				? $imap_user
				: sanitize_text_field( (string) ( $existing_row['imap_username'] ?? $imap_user ) ),
			'imap_password_encrypted' => $imap_password_encrypted,
			'imap_password'           => isset( $row_plain_imap_password ) && '' !== $row_plain_imap_password
				? $row_plain_imap_password
				: ( '' === $imap_password_encrypted ? (string) ( $existing_row['imap_password'] ?? '' ) : '' ),
			'active'                  => $active_value,
		);
		unset( $row_plain_password, $row_plain_imap_password );
	}

	update_option( 'atora_email_identities', $identities );
};

$csv_preview = array(
	'delimiter' => '',
	'encoding'  => '',
	'headers'   => array(),
	'rows'      => array(),
	'mapping'   => array(),
);

if ( false === get_option( 'atora_email_identities', false ) ) {
	$initial_identity_candidate = $settings['imap_identity'] ?? $settings['identity_key'] ?? '';
	$initial_identity           = sanitize_key( $to_scalar_string( $initial_identity_candidate ) );

	if ( '' !== $initial_identity ) {
		$sync_identities_option( $settings, $initial_identity );
	} else {
		$sync_identities_option( $settings );
	}
}

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['atora_email_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	check_admin_referer( 'atora_email_admin_action', 'atora_email_nonce' );

	$action_raw = wp_unslash( $_POST['atora_email_action'] );
	$action     = sanitize_key( $to_scalar_string( $action_raw ) );

	if ( 'save_wizard' === $action ) {
		$email_main   = sanitize_email( $to_scalar_string( wp_unslash( $_POST['email_main'] ?? '' ) ) );
		$smtp_host    = sanitize_text_field( $to_scalar_string( wp_unslash( $_POST['smtp_host'] ?? '' ) ) );
		$smtp_port    = max( 1, min( 65535, absint( wp_unslash( $_POST['smtp_port'] ?? 465 ) ) ) );
		$smtp_secure  = sanitize_key( $to_scalar_string( wp_unslash( $_POST['smtp_secure'] ?? 'ssl' ), 'ssl' ) );
		$smtp_pass    = $to_scalar_string( wp_unslash( $_POST['smtp_pass'] ?? '' ) );
		$imap_host    = sanitize_text_field( $to_scalar_string( wp_unslash( $_POST['imap_host'] ?? '' ) ) );
		$imap_port    = max( 1, min( 65535, absint( wp_unslash( $_POST['imap_port'] ?? 993 ) ) ) );
		$imap_secure  = sanitize_key( $to_scalar_string( wp_unslash( $_POST['imap_secure'] ?? 'ssl' ), 'ssl' ) );
		$identity_raw = sanitize_key( $to_scalar_string( wp_unslash( $_POST['identity_key'] ?? 'academia' ), 'academia' ) );
		$identity     = $normalize_identity_key( $identity_raw );
		$prefix       = $identity_prefix( $identity );
		$wizard_provider = sanitize_key( $to_scalar_string( wp_unslash( $_POST['wizard_provider'] ?? 'smtp' ), 'smtp' ) );
		if ( ! in_array( $wizard_provider, array( 'smtp', 'brevo', 'sendgrid', 'mailgun', 'ses', 'postmark' ), true ) ) {
			$wizard_provider = 'smtp';
		}

		$settings['provider']        = $wizard_provider;
		$settings['smtp_host']       = $smtp_host;
		$settings['smtp_port']       = $smtp_port;
		$settings['smtp_encryption'] = in_array( $smtp_secure, array( 'ssl', 'tls' ), true ) ? $smtp_secure : 'ssl';
		$settings['smtp_auth']       = 1;
		$settings['smtp_user']       = $email_main;
		if ( '' !== $smtp_pass ) {
			$settings['smtp_pass'] = $smtp_pass;
		}
		$settings['imap_host']      = $imap_host;
		$settings['imap_port']      = $imap_port;
		$settings['imap_secure']    = in_array( $imap_secure, array( 'ssl', 'tls' ), true ) ? $imap_secure : 'ssl';
		$settings['imap_user']      = $email_main;
		$settings['imap_enabled']   = $imap_host ? 1 : 0;
		$settings['imap_password']  = $to_scalar_string( wp_unslash( $_POST['imap_pass'] ?? '' ) );
		$settings['imap_identity']  = $identity;
		$settings['from_email']     = $email_main;
		$settings['from_name']      = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$settings['brand_name']     = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$settings[ $prefix . '_from_email' ] = $email_main;
		if ( empty( $settings[ $prefix . '_from_name' ] ) ) {
			$settings[ $prefix . '_from_name' ] = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		}

		update_option( 'atora_email_engine_options', $settings );
		$sync_identities_option( $settings );
		$status = array(
			'type'    => 'success',
			'message' => __( 'Asistente guardado. SMTP/IMAP e identidad actualizados.', 'atora-lms' ),
		);
	}

	if ( 'save_identities' === $action ) {
		$pairs = array(
			'academia' => 'identity_academia',
			'teacher'  => 'identity_teacher',
			'admin'    => 'identity_admin',
		);
		foreach ( $pairs as $identity => $prefix ) {
			$settings[ $prefix . '_from_name' ]  = sanitize_text_field( $to_scalar_string( wp_unslash( $_POST[ $prefix . '_from_name' ] ?? '' ) ) );
			$settings[ $prefix . '_from_email' ] = sanitize_email( $to_scalar_string( wp_unslash( $_POST[ $prefix . '_from_email' ] ?? '' ) ) );
			$settings[ $prefix . '_reply_to' ]   = sanitize_email( $to_scalar_string( wp_unslash( $_POST[ $prefix . '_reply_to' ] ?? '' ) ) );
			$settings[ $prefix . '_active' ]     = ! empty( $_POST[ $prefix . '_active' ] ) ? 1 : 0;

			if ( empty( $settings[ $prefix . '_from_name' ] ) ) {
				$settings[ $prefix . '_from_name' ] = sanitize_text_field( (string) get_bloginfo( 'name' ) );
			}
			if ( empty( $settings[ $prefix . '_from_email' ] ) ) {
				$settings[ $prefix . '_from_email' ] = sanitize_email( (string) get_option( 'admin_email' ) );
			}
		}

		$settings['from_name']  = $settings['identity_academia_from_name'];
		$settings['from_email'] = $settings['identity_academia_from_email'];
		$settings['reply_to']   = $settings['identity_academia_reply_to'];

		update_option( 'atora_email_engine_options', $settings );
		$sync_identities_option( $settings );

		$identity_option_key_map = array(
			'academia' => 'academia',
			'teacher'  => 'comercio',
			'admin'    => 'administracion',
		);
		$identities_option = get_option( 'atora_email_identities', array() );
		$identities_option = is_array( $identities_option ) ? $identities_option : array();

		foreach ( $pairs as $identity => $prefix ) {
			$option_key = sanitize_key( (string) ( $identity_option_key_map[ $identity ] ?? $identity ) );
			$row        = isset( $identities_option[ $option_key ] ) && is_array( $identities_option[ $option_key ] )
				? $identities_option[ $option_key ]
				: array();

			$smtp_user = sanitize_text_field( $to_scalar_string( wp_unslash( $_POST[ $prefix . '_smtp_user' ] ?? '' ) ) );
			$smtp_pass = $to_scalar_string( wp_unslash( $_POST[ $prefix . '_smtp_pass' ] ?? '' ) );
			$imap_user = sanitize_text_field( $to_scalar_string( wp_unslash( $_POST[ $prefix . '_imap_user' ] ?? '' ) ) );
			$imap_pass = $to_scalar_string( wp_unslash( $_POST[ $prefix . '_imap_pass' ] ?? '' ) );

			if ( '' !== $smtp_user ) {
				$row['smtp_username'] = $smtp_user;
				$row['smtp_user']     = $smtp_user;
			}
			if ( '' !== $smtp_pass ) {
				$smtp_pass_encrypted = $encrypt_secret( $smtp_pass );
				if ( '' !== $smtp_pass_encrypted ) {
					$row['smtp_password_encrypted'] = $smtp_pass_encrypted;
					$row['smtp_password']           = '';
				} else {
					$row['smtp_password']           = $smtp_pass;
				}
				$row['smtp_auth']               = 1;
			}
			if ( '' !== $imap_user ) {
				$row['imap_username'] = $imap_user;
			}
			if ( '' !== $imap_pass ) {
				$imap_pass_encrypted = $encrypt_secret( $imap_pass );
				if ( '' !== $imap_pass_encrypted ) {
					$row['imap_password_encrypted'] = $imap_pass_encrypted;
					$row['imap_password']           = '';
				} else {
					$row['imap_password']           = $imap_pass;
				}
			}

			$row['from_name']  = sanitize_text_field( (string) ( $settings[ $prefix . '_from_name' ] ?? '' ) );
			$row['from_email'] = sanitize_email( (string) ( $settings[ $prefix . '_from_email' ] ?? '' ) );
			$row['reply_to']   = sanitize_email( (string) ( $settings[ $prefix . '_reply_to' ] ?? '' ) );
			$row['active']     = ! empty( $settings[ $prefix . '_active' ] ) ? 1 : 0;

			$identities_option[ $option_key ] = $row;
		}

		update_option( 'atora_email_identities', $identities_option );
		$status = array(
			'type'    => 'success',
			'message' => __( 'Identidades guardadas correctamente (incluyendo credenciales por identidad).', 'atora-lms' ),
		);
	}

	if ( 'test_provider_connection' === $action ) {
		$provider = sanitize_key( $to_scalar_string( $settings['provider'] ?? 'smtp', 'smtp' ) );
		$provider_map = array(
			'smtp'     => array( 'class' => '\ATORA\EmailEngine\Providers\SMTP', 'file' => 'class-smtp.php' ),
			'brevo'    => array( 'class' => '\ATORA\EmailEngine\Providers\Brevo', 'file' => 'class-brevo.php' ),
			'sendgrid' => array( 'class' => '\ATORA\EmailEngine\Providers\SendGrid', 'file' => 'class-sendgrid.php' ),
			'mailgun'  => array( 'class' => '\ATORA\EmailEngine\Providers\Mailgun', 'file' => 'class-mailgun.php' ),
			'ses'      => array( 'class' => '\ATORA\EmailEngine\Providers\Amazon_SES', 'file' => 'class-amazon-ses.php' ),
			'postmark' => array( 'class' => '\ATORA\EmailEngine\Providers\Postmark', 'file' => 'class-postmark.php' ),
		);
		$provider_def = $provider_map[ $provider ] ?? $provider_map['smtp'];

		$interface_file = ATORA_LMS_MODULES_DIR . 'email-engine/providers/class-provider-interface.php';
		if ( file_exists( $interface_file ) ) {
			require_once $interface_file;
		}
		$provider_file = ATORA_LMS_MODULES_DIR . 'email-engine/providers/' . $provider_def['file'];
		if ( file_exists( $provider_file ) ) {
			require_once $provider_file;
		}

		$is_ok = false;
		$provider_class = (string) ( $provider_def['class'] ?? '' );
		if ( '' !== $provider_class && class_exists( $provider_class ) && method_exists( $provider_class, 'test_connection' ) ) {
			$is_ok = (bool) $provider_class::test_connection();
		}

		$status = array(
			'type'    => $is_ok ? 'success' : 'error',
			'message' => $is_ok
				? sprintf(
					/* translators: %s: provider key */
					__( 'Conexión validada con %s.', 'atora-lms' ),
					strtoupper( $provider )
				)
				: sprintf(
					/* translators: %s: provider key */
					__( 'No se pudo validar la conexión con %s. Revisa credenciales y parámetros del provider.', 'atora-lms' ),
					strtoupper( $provider )
				),
		);
	}

	if ( 'send_test' === $action ) {
		$recipient    = sanitize_email( $to_scalar_string( wp_unslash( $_POST['test_recipient'] ?? '' ) ) );
		$template_key = sanitize_key( $to_scalar_string( wp_unslash( $_POST['test_template'] ?? '' ) ) );
		$identity     = $normalize_identity_key( $to_scalar_string( wp_unslash( $_POST['test_identity'] ?? 'academia' ), 'academia' ) );
		$subject      = sanitize_text_field( $to_scalar_string( wp_unslash( $_POST['test_subject'] ?? __( 'Prueba ATORA', 'atora-lms' ) ) ) );
		$message      = wp_kses_post( $to_scalar_string( wp_unslash( $_POST['test_message'] ?? __( 'Prueba de canal enviada desde ATORA.', 'atora-lms' ) ) ) );
		if ( '' === trim( $subject ) ) {
			$subject = __( 'Prueba de canal ATORA', 'atora-lms' );
		}
		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			$message = __( 'Este es un envío de prueba desde ATORA.', 'atora-lms' );
		}

		$sent         = false;
		$provider     = sanitize_text_field( $to_scalar_string( $settings['provider'] ?? 'smtp', 'smtp' ) );
		$result_label = '';
		$error_detail = '';
		$test_log     = array( 'queue_id' => 0, 'user_id' => 0, 'crm_synced' => false );
		$diag         = $get_identity_diagnostics( $identity );
		if ( ! $recipient || ! is_email( $recipient ) ) {
			$status = array(
				'type'    => 'error',
				'message' => __( 'Debes indicar un destinatario válido.', 'atora-lms' ),
			);
		}
		if ( ! $diag['ready'] ) {
			$status = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: 1: identity label, 2: issues */
					__( 'Configuración incompleta para %1$s: %2$s', 'atora-lms' ),
					(string) ( $identity_labels[ $identity ] ?? ucfirst( $identity ) ),
					implode( ' ', array_map( 'sanitize_text_field', $diag['issues'] ) )
				),
			);
		}

		if ( empty( $status['message'] ) && $recipient && is_email( $recipient ) && $template_key && class_exists( '\ATORA\EmailEngine\Email_Templates' ) ) {
			$catalog = (array) \ATORA\EmailEngine\Email_Templates::get_catalog();
			if ( isset( $catalog[ $template_key ] ) ) {
				$testing_user = get_user_by( 'email', $recipient );
				if ( ! ( $testing_user instanceof \WP_User ) ) {
					$testing_user = wp_get_current_user();
				}

				$defaults = array(
					'course_title'  => __( 'Curso de prueba', 'atora-lms' ),
					'lesson_title'  => __( 'Lección de prueba', 'atora-lms' ),
					'program_title' => __( 'Programa de prueba', 'atora-lms' ),
					'module_title'  => __( 'Módulo de prueba', 'atora-lms' ),
					'order_id'      => 'TEST-0001',
					'discount'      => '20',
					'commission'    => 49.99,
				);
				$context  = array(
					'site_name'     => get_bloginfo( 'name' ),
					'display_name'  => __( 'Usuario de prueba', 'atora-lms' ),
					'dashboard_url' => admin_url( 'admin.php?page=clms-dashboard' ),
					'user_email'    => $recipient,
				);
				if ( $testing_user instanceof \WP_User ) {
					$context = \ATORA\EmailEngine\Email_Templates::build_context( $testing_user, $defaults );
					$context['user_email'] = $recipient;
				}

				$template_subject = $to_scalar_string( $catalog[ $template_key ]['subject'] ?? $subject, $subject );
				$subject          = \ATORA\EmailEngine\Email_Templates::render_string( $template_subject, $context );
				$message          = \ATORA\EmailEngine\Email_Templates::render_html( $template_key, $context );
			}
		}

		if ( empty( $status['message'] ) && ! $sent && $recipient && is_email( $recipient ) ) {
			$sent         = $send_direct_email( $recipient, $subject, $message, $identity, $error_detail );
			$test_log     = $register_test_delivery(
				array(
					'recipient'     => $recipient,
					'subject'       => $subject,
					'body_html'     => $message,
					'identity'      => $identity,
					'provider'      => $provider,
					'status'        => $sent ? 'sent' : 'failed',
					'error_message' => $error_detail,
					'template_key'  => $template_key,
					'source'        => $template_key ? 'email_engine_test_template' : 'email_engine_test_manual',
				)
			);
			$result_label = $sent
				? __( 'Solicitud aceptada por el proveedor (verifica bandeja y spam).', 'atora-lms' )
				: sprintf(
					/* translators: %s: error detail */
					__( 'Fallo al enviar prueba. Detalle: %s', 'atora-lms' ),
					$error_detail ?: __( 'sin detalle', 'atora-lms' )
				);
		}

		if ( empty( $status['message'] ) ) {
			$traceability_bits = array();
			if ( absint( $test_log['queue_id'] ?? 0 ) > 0 ) {
				$traceability_bits[] = sprintf(
					/* translators: %d: queue id */
					__( 'Registro #%d en cola/logs', 'atora-lms' ),
					absint( $test_log['queue_id'] )
				);
			}
				if ( ! empty( $test_log['crm_synced'] ) ) {
					$traceability_bits[] = __( 'Sincronizado en CRM (usuario o contacto por email)', 'atora-lms' );
				} elseif ( absint( $test_log['queue_id'] ?? 0 ) > 0 ) {
					$traceability_bits[] = __( 'Visible en Cola y logs', 'atora-lms' );
				}

			$message_suffix = '';
			if ( ! empty( $traceability_bits ) ) {
				$message_suffix = ' · ' . implode( ' · ', array_map( 'sanitize_text_field', $traceability_bits ) );
			}

			$status = array(
				'type'    => $sent ? 'success' : 'error',
				'message' => sprintf(
						/* translators: 1: result label, 2: provider, 3: identity, 4: date, 5: traceability suffix */
						__( '%1$s Provider: %2$s · Identidad: %3$s · Fecha: %4$s%5$s', 'atora-lms' ),
						$result_label ?: __( 'Sin envío', 'atora-lms' ),
						$provider,
						(string) ( $identity_labels[ $identity ] ?? ucfirst( $identity ) ),
						date_i18n( 'd/m/Y H:i:s' ),
						$message_suffix
					),
				);
			}
	}

	if ( 'send_test_all' === $action ) {
		$recipient = sanitize_email( $to_scalar_string( wp_unslash( $_POST['test_recipient_all'] ?? '' ) ) );
		$identity  = $normalize_identity_key( $to_scalar_string( wp_unslash( $_POST['test_identity_all'] ?? 'academia' ), 'academia' ) );
		$provider  = sanitize_text_field( $to_scalar_string( $settings['provider'] ?? 'smtp', 'smtp' ) );
		$diag      = $get_identity_diagnostics( $identity );

		if ( ! $recipient || ! is_email( $recipient ) ) {
			$status = array(
				'type'    => 'error',
				'message' => __( 'Debes indicar un destinatario válido para la prueba masiva.', 'atora-lms' ),
			);
		} elseif ( ! $diag['ready'] ) {
			$status = array(
				'type'    => 'error',
				'message' => sprintf(
					/* translators: 1: identity label, 2: issues */
					__( 'Configuración incompleta para %1$s: %2$s', 'atora-lms' ),
					(string) ( $identity_labels[ $identity ] ?? ucfirst( $identity ) ),
					implode( ' ', array_map( 'sanitize_text_field', $diag['issues'] ) )
				),
			);
		} else {
			$catalog = class_exists( '\ATORA\EmailEngine\Email_Templates' )
				? (array) \ATORA\EmailEngine\Email_Templates::get_catalog()
				: array();
			$catalog = array_filter(
				$catalog,
				static function ( $template ): bool {
					return ! isset( $template['active'] ) || ! empty( $template['active'] );
				}
			);

			$testing_user = get_user_by( 'email', $recipient );
			if ( ! ( $testing_user instanceof \WP_User ) ) {
				$testing_user = wp_get_current_user();
			}

			$defaults = array(
				'course_title'  => __( 'Curso de prueba', 'atora-lms' ),
				'lesson_title'  => __( 'Lección de prueba', 'atora-lms' ),
				'program_title' => __( 'Programa de prueba', 'atora-lms' ),
				'module_title'  => __( 'Módulo de prueba', 'atora-lms' ),
				'order_id'      => 'TEST-0001',
				'discount'      => '20',
				'commission'    => 49.99,
			);

			$sent_count   = 0;
			$failed_count = 0;
			$last_error   = '';
			$queue_log_count = 0;
			$crm_sync_count  = 0;
			foreach ( $catalog as $template_key => $template ) {
				$template_key = sanitize_key( (string) $template_key );
				if ( '' === $template_key ) {
					continue;
				}

				$template_subject = $to_scalar_string( $template['subject'] ?? '' );
				$context = array(
					'site_name'     => get_bloginfo( 'name' ),
					'display_name'  => __( 'Usuario de prueba', 'atora-lms' ),
					'dashboard_url' => admin_url( 'admin.php?page=clms-dashboard' ),
				);
				if ( $testing_user instanceof \WP_User && class_exists( '\ATORA\EmailEngine\Email_Templates' ) ) {
					$context = \ATORA\EmailEngine\Email_Templates::build_context( $testing_user, $defaults );
					$context['user_email'] = $recipient;
				}

				$subject = sprintf(
					'[ATORA TEST %1$s] %2$s',
					strtoupper( $template_key ),
					class_exists( '\ATORA\EmailEngine\Email_Templates' )
						? \ATORA\EmailEngine\Email_Templates::render_string( $template_subject, $context )
						: $template_key
				);

				$body = class_exists( '\ATORA\EmailEngine\Email_Templates' )
					? \ATORA\EmailEngine\Email_Templates::render_html( $template_key, $context )
					: '<p>' . esc_html__( 'Correo de prueba de plantilla ATORA.', 'atora-lms' ) . '</p>';

				$error_detail = '';
				$sent = $send_direct_email( $recipient, $subject, $body, $identity, $error_detail );
				$test_log = $register_test_delivery(
					array(
						'recipient'     => $recipient,
						'subject'       => $subject,
						'body_html'     => $body,
						'identity'      => $identity,
						'provider'      => $provider,
						'status'        => $sent ? 'sent' : 'failed',
						'error_message' => $error_detail,
						'template_key'  => $template_key,
						'source'        => 'email_engine_test_bulk',
					)
				);
				if ( absint( $test_log['queue_id'] ?? 0 ) > 0 ) {
					$queue_log_count++;
				}
				if ( ! empty( $test_log['crm_synced'] ) ) {
					$crm_sync_count++;
				}
				if ( $sent ) {
					$sent_count++;
				} else {
					$failed_count++;
					if ( '' === $last_error && '' !== $error_detail ) {
						$last_error = $error_detail;
					}
				}
			}

			$status = array(
				'type'    => $sent_count > 0 ? 'success' : 'error',
				'message' => sprintf(
					/* translators: 1: sent count, 2: failed count, 3: provider, 4: identity */
					__( 'Prueba masiva completada. Solicitudes aceptadas: %1$d · Fallidas: %2$d · Provider: %3$s · Identidad: %4$s', 'atora-lms' ),
						$sent_count,
						$failed_count,
						$provider,
						(string) ( $identity_labels[ $identity ] ?? ucfirst( $identity ) )
					),
				);
			if ( $failed_count > 0 && '' !== $last_error ) {
				$status['message'] .= ' ' . sprintf(
					/* translators: %s: error detail */
					__( 'Último error: %s', 'atora-lms' ),
					$last_error
				);
			}
			$status['message'] .= ' ' . sprintf(
				/* translators: 1: queue rows, 2: crm synced rows */
				__( 'Trazabilidad registrada: %1$d · Sincronización CRM: %2$d.', 'atora-lms' ),
				$queue_log_count,
				$crm_sync_count
			);
		}
	}

	if ( 'resend_invitation' === $action ) {
		$invitation_id = absint( wp_unslash( $_POST['invitation_id'] ?? 0 ) );
		$sent          = false;
		if ( $invitation_id > 0 && class_exists( 'CLMS_Enrollment_Manager' ) ) {
			$manager = new CLMS_Enrollment_Manager();
			if ( method_exists( $manager, 'send_invitation_email' ) ) {
				$sent = (bool) $manager->send_invitation_email( $invitation_id );
			}
		}
		$status = array(
			'type'    => $sent ? 'success' : 'error',
			'message' => $sent ? __( 'Invitación reenviada.', 'atora-lms' ) : __( 'No se pudo reenviar la invitación.', 'atora-lms' ),
		);
	}

	if ( 'preview_csv' === $action && ! empty( $_FILES['csv_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file_path = (string) $_FILES['csv_file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$contents  = (string) file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$first_line = strtok( $contents, "\r\n" );
		$delimiters = array( ',' => ',', ';' => ';', "\t" => "\t" );
		$best_delim = ',';
		$best_count = -1;
		foreach ( $delimiters as $delimiter ) {
			$count = substr_count( (string) $first_line, $delimiter );
			if ( $count > $best_count ) {
				$best_count = $count;
				$best_delim = $delimiter;
			}
		}

		$encoding = function_exists( 'mb_detect_encoding' )
			? (string) mb_detect_encoding( $contents, array( 'UTF-8', 'ISO-8859-1', 'Windows-1252' ), true )
			: 'desconocida';

		$lines = preg_split( '/\r\n|\r|\n/', $contents );
		$lines = is_array( $lines ) ? array_values( array_filter( $lines ) ) : array();
		$rows  = array();
		foreach ( array_slice( $lines, 0, 10 ) as $line ) {
			$rows[] = str_getcsv( (string) $line, $best_delim );
		}

		$headers = array_map(
			static function ( $value ): string {
				return sanitize_text_field( (string) $value );
			},
			$rows[0] ?? array()
		);

		$synonyms = array(
			'email'     => array( 'email', 'correo', 'e-mail' ),
			'first_name'=> array( 'nombre', 'first_name' ),
			'last_name' => array( 'apellido', 'last_name' ),
			'phone'     => array( 'telefono', 'teléfono', 'phone' ),
			'whatsapp'  => array( 'whatsapp', 'wa' ),
			'course'    => array( 'curso', 'course' ),
			'program'   => array( 'programa', 'program' ),
			'role'      => array( 'rol', 'role' ),
			'status'    => array( 'estado', 'status' ),
		);

		$mapping = array();
		foreach ( $synonyms as $canonical => $keys ) {
			foreach ( $headers as $index => $header ) {
				$normalized = sanitize_key( remove_accents( strtolower( (string) $header ) ) );
				foreach ( $keys as $candidate ) {
					if ( sanitize_key( remove_accents( $candidate ) ) === $normalized ) {
						$mapping[ $canonical ] = (string) $header . ' (#' . ( $index + 1 ) . ')';
						break 2;
					}
				}
			}
		}

		$csv_preview = array(
			'delimiter' => "\t" === $best_delim ? 'tab' : $best_delim,
			'encoding'  => $encoding ?: 'desconocida',
			'headers'   => $headers,
			'rows'      => $rows,
			'mapping'   => $mapping,
		);
		$status = array(
			'type'    => 'success',
			'message' => __( 'Previsualización CSV generada (sin enviar invitaciones ni crear usuarios).', 'atora-lms' ),
		);
	}

	$settings = (array) get_option( 'atora_email_engine_options', array() );
}

$identity_health_rows = array(
	'academia' => $get_identity_diagnostics( 'academia' ),
	'teacher'  => $get_identity_diagnostics( 'teacher' ),
	'admin'    => $get_identity_diagnostics( 'admin' ),
);

$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'wizard';
$tabs = array(
	'wizard'      => __( 'Asistente rápido', 'atora-lms' ),
	'identities'  => __( 'Identidades', 'atora-lms' ),
	'provider'    => __( 'SMTP / Proveedor', 'atora-lms' ),
	'templates'   => __( 'Plantillas', 'atora-lms' ),
	'test-send'   => __( 'Prueba de envío', 'atora-lms' ),
	'queue-logs'  => __( 'Cola y logs', 'atora-lms' ),
	'diagnostics' => __( 'Diagnóstico DNS', 'atora-lms' ),
	'invitations' => __( 'Invitaciones', 'atora-lms' ),
	'csv-import'  => __( 'Importar CSV', 'atora-lms' ),
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'wizard';
}

$view_file = __DIR__ . '/' . $tab . '.php';
if ( ! file_exists( $view_file ) ) {
	$view_file = __DIR__ . '/wizard.php';
}

$app_password_links = array(
	'gmail'   => array(
		'label' => __( 'Google (Gmail / Workspace)', 'atora-lms' ),
		'url'   => 'https://support.google.com/accounts/answer/185833',
	),
	'outlook' => array(
		'label' => __( 'Microsoft (Outlook / 365)', 'atora-lms' ),
		'url'   => 'https://support.microsoft.com/account-billing',
	),
	'yahoo'   => array(
		'label' => __( 'Yahoo Mail', 'atora-lms' ),
		'url'   => 'https://help.yahoo.com/kb/SLN15241.html',
	),
	'apple'   => array(
		'label' => __( 'Apple (iCloud)', 'atora-lms' ),
		'url'   => 'https://support.apple.com/102654',
	),
);

$crm_quick_links = array(
	array(
		'label' => __( 'CRM · Correos', 'atora-lms' ),
		'url'   => admin_url( 'admin.php?page=atora-crm&tab=emails' ),
	),
	array(
		'label' => __( 'CRM · Bandeja', 'atora-lms' ),
		'url'   => admin_url( 'admin.php?page=atora-crm&tab=inbox' ),
	),
	array(
		'label' => __( 'Ajustes de Canales', 'atora-lms' ),
		'url'   => admin_url( 'admin.php?page=clms-settings&tab=channels' ),
	),
);
?>
<div class="wrap atora-emails-admin">
	<style>
		.atora-emails-admin{
			--atora-email-ink:#0f172a;
			--atora-email-muted:#475569;
			--atora-email-line:#dbe2ea;
			--atora-email-card:#ffffff;
			--atora-email-soft:#eef4ff;
		}
		.atora-emails-admin__hero{
			background:linear-gradient(135deg,#0f172a,#1d4ed8);
			color:#fff;
			border-radius:14px;
			padding:20px 22px;
			margin-bottom:14px;
			display:grid;
			gap:8px;
		}
		.atora-emails-admin__hero h1{margin:0;color:#fff;font-size:24px;line-height:1.2}
		.atora-emails-admin__hero p{margin:0;color:rgba(255,255,255,.86);max-width:980px}
		.atora-emails-admin__chips{display:flex;gap:8px;flex-wrap:wrap}
		.atora-emails-admin__chip{
			display:inline-flex;
			align-items:center;
			padding:4px 9px;
			border-radius:999px;
			background:rgba(255,255,255,.14);
			border:1px solid rgba(255,255,255,.25);
			font-size:12px;
			font-weight:700;
		}
		.atora-emails-admin__topbar{
			display:flex;
			gap:8px;
			flex-wrap:wrap;
			margin:0 0 12px;
		}
		.atora-emails-admin__topbar .button{border-radius:8px}
		.atora-emails-admin__guide{
			background:var(--atora-email-soft);
			border:1px solid #d5e2ff;
			border-radius:12px;
			padding:12px 14px;
			margin:0 0 14px;
		}
		.atora-emails-admin__guide h3{margin:0 0 8px;font-size:14px;color:#1e3a8a}
		.atora-emails-admin__steps{
			margin:0;
			padding-left:18px;
			color:#1e293b;
		}
		.atora-emails-admin__steps li{margin:0 0 5px}
		.atora-emails-admin__help-links{
			display:flex;
			flex-wrap:wrap;
			gap:8px;
			margin-top:8px;
		}
		.atora-emails-admin__help-links a{
			display:inline-flex;
			align-items:center;
			padding:5px 10px;
			border-radius:999px;
			background:#fff;
			border:1px solid #bfd1ff;
			text-decoration:none;
		}
		.atora-emails-admin .nav-tab-wrapper{
			margin-bottom:14px;
			border-bottom:1px solid var(--atora-email-line);
		}
		.atora-emails-admin .nav-tab{border-radius:8px 8px 0 0}
		.atora-emails-admin .nav-tab-active{
			background:#fff;
			border-color:var(--atora-email-line);
			color:var(--atora-email-ink);
			font-weight:700;
		}
		.atora-emails-admin__panel{
			background:var(--atora-email-card);
			border:1px solid var(--atora-email-line);
			border-radius:12px;
			padding:16px;
			box-shadow:0 1px 3px rgba(15,23,42,.06);
		}
		.atora-emails-admin__panel h2{margin-top:0}
		.atora-emails-admin__callout{
			border:1px solid #dbe2ea;
			background:#f8fafc;
			border-radius:12px;
			padding:12px 14px;
			margin:0 0 14px;
		}
		.atora-emails-admin__callout h3{
			margin:0 0 8px;
			font-size:14px;
			color:#0f172a;
		}
		.atora-emails-admin__callout p{
			margin:0 0 8px;
			color:#334155;
		}
		.atora-emails-admin__help-grid{
			display:grid;
			grid-template-columns:repeat(auto-fit,minmax(210px,1fr));
			gap:8px;
			margin-top:8px;
		}
		.atora-emails-admin__help-card{
			display:block;
			text-decoration:none;
			padding:10px 12px;
			border-radius:10px;
			border:1px solid #cbd5e1;
			background:#fff;
			color:#0f172a;
			font-weight:600;
		}
		.atora-emails-admin__help-card span{
			display:block;
			margin-top:3px;
			font-size:12px;
			font-weight:500;
			color:#475569;
		}
		.atora-emails-admin__help-card:hover{
			border-color:#3b82f6;
			background:#eff6ff;
			color:#1e3a8a;
		}
		.atora-emails-admin__table-wrap{
			overflow:auto;
			border:1px solid #e2e8f0;
			border-radius:10px;
		}
		.atora-emails-admin__note{
			font-size:12px;
			color:#475569;
			margin:8px 0 0;
		}
		@media (max-width:782px){
			.atora-emails-admin__hero{padding:16px}
			.atora-emails-admin__hero h1{font-size:21px}
		}
	</style>

	<header class="atora-emails-admin__hero">
		<h1><?php esc_html_e( 'Comunicaciones · Correos', 'atora-lms' ); ?></h1>
		<p><?php esc_html_e( 'Configuración guiada y pedagógica para usuarios no técnicos. Configura tu cuenta, prueba envío real y valida trazabilidad en CRM sin salir del flujo.', 'atora-lms' ); ?></p>
		<div class="atora-emails-admin__chips">
			<span class="atora-emails-admin__chip"><?php esc_html_e( 'Wizard paso a paso', 'atora-lms' ); ?></span>
			<span class="atora-emails-admin__chip"><?php esc_html_e( 'App Passwords guiado', 'atora-lms' ); ?></span>
			<span class="atora-emails-admin__chip"><?php esc_html_e( 'Integración con CRM', 'atora-lms' ); ?></span>
		</div>
	</header>

	<div class="atora-emails-admin__topbar">
		<?php foreach ( $crm_quick_links as $quick_link ) : ?>
			<a class="button" href="<?php echo esc_url( (string) $quick_link['url'] ); ?>"><?php echo esc_html( (string) $quick_link['label'] ); ?></a>
		<?php endforeach; ?>
	</div>

	<section class="atora-emails-admin__guide">
			<h3><?php esc_html_e( 'Flujo recomendado para operar sin errores', 'atora-lms' ); ?></h3>
			<ol class="atora-emails-admin__steps">
				<li><?php esc_html_e( 'Elige preset en Asistente rápido (dominio propio, Gmail, Outlook, etc.) o usa modo manual.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Si tu proveedor lo exige, genera una App Password y pégala en SMTP/IMAP.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Valida con “Probar conexión del provider” y luego “Prueba de envío”.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Confirma trazabilidad en CRM → Correos y CRM → Bandeja.', 'atora-lms' ); ?></li>
			</ol>
		<div class="atora-emails-admin__help-links">
			<?php foreach ( $app_password_links as $provider_help ) : ?>
				<a href="<?php echo esc_url( (string) $provider_help['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $provider_help['label'] ); ?> · <?php esc_html_e( 'App Password', 'atora-lms' ); ?></a>
			<?php endforeach; ?>
		</div>
	</section>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( (string) $status['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-emails&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="atora-emails-admin__panel">
		<?php require $view_file; ?>
	</div>
</div>



<?php if ( isset( $active_tab ) && 'suppression' === $active_tab ) : ?>
<section class="atora-email-engine__panel">
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
		<h2 style="font-size:18px;margin:0"><?php esc_html_e( 'Lista de desuscriptos', 'atora-lms' ); ?></h2>
		<form method="get" style="display:flex;gap:6px">
			<input type="hidden" name="page" value="<?php echo esc_attr( sanitize_key( (string) ( $_GET['page'] ?? '' ) ) ); ?>">
			<input type="hidden" name="tab" value="suppression">
			<input type="text" name="search" placeholder="<?php esc_attr_e( 'Buscar email...', 'atora-lms' ); ?>"
				value="<?php echo esc_attr( sanitize_email( (string) ( $_GET['search'] ?? '' ) ) ); ?>"
				style="width:220px">
			<button type="submit" class="button"><?php esc_html_e( 'Buscar', 'atora-lms' ); ?></button>
		</form>
	</div>
	<?php
	$suppression_table = $wpdb->prefix . 'atora_email_suppression';
	$supp_exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $suppression_table ) ) ) === $suppression_table;
	if ( ! $supp_exists ) : ?>
		<p class="atora-crm-v2-empty"><?php esc_html_e( 'Tabla de suppression no encontrada. Asegurate de que la Fase 6 este instalada.', 'atora-lms' ); ?></p>
	<?php else :
		$search = sanitize_email( (string) ( $_GET['search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$limit  = 40;
		$offset = 0;
		$where  = '';
		$params = array();
		if ( $search ) {
			$where    = 'WHERE email LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$total = ! empty( $params )
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$suppression_table} {$where}", ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$suppression_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$list_params = array_merge( $params, array( $limit, $offset ) );
		$rows = ! empty( $list_params )
			? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$suppression_table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...$list_params ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: array();
	?>
	<p style="font-size:13px;color:#64748b;margin-bottom:10px">
		<?php echo esc_html( sprintf( __( '%d emails en la suppression list.', 'atora-lms' ), $total ) ); ?>
	</p>
	<?php if ( empty( $rows ) ) : ?>
		<p style="font-size:13px;color:#64748b"><?php esc_html_e( 'Sin entradas.', 'atora-lms' ); ?></p>
	<?php else : ?>
	<table class="widefat striped" style="font-size:13px">
		<thead><tr>
			<th><?php esc_html_e( 'Email', 'atora-lms' ); ?></th>
			<th><?php esc_html_e( 'Razon', 'atora-lms' ); ?></th>
			<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
			<th><?php esc_html_e( 'Accion', 'atora-lms' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td><?php echo esc_html( sanitize_email( (string) ( $row['email'] ?? '' ) ) ); ?></td>
				<td><?php echo esc_html( sanitize_key( (string) ( $row['reason'] ?? '' ) ) ); ?></td>
				<td><?php echo esc_html( sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ) ); ?></td>
				<td><button type="button" class="button button-small atora-unsuppress-btn"
					data-email="<?php echo esc_attr( sanitize_email( (string) ( $row['email'] ?? '' ) ) ); ?>">
					<?php esc_html_e( 'Re-activar', 'atora-lms' ); ?>
				</button></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<?php endif; ?>
</section>
<script>
document.querySelectorAll('.atora-unsuppress-btn').forEach(function(btn){
	btn.addEventListener('click', function(){
		var email = btn.getAttribute('data-email');
		if(!window.confirm('¿Re-activar ' + email + '?')) return;
		btn.disabled = true;
		var cfg = window.atoraCrmV2 || {};
		fetch((cfg.restBase||'').replace(/\/+$/,'') + '/suppression/' + encodeURIComponent(email), {
			method:'DELETE', credentials:'same-origin',
			headers:{'X-WP-Nonce':cfg.nonce||'','Content-Type':'application/json'}
		}).then(function(r){return r.json();}).then(function(d){
			if(d.success){ btn.closest('tr') && btn.closest('tr').remove(); }
			else { btn.disabled=false; alert(d.message||'Error'); }
		}).catch(function(){ btn.disabled=false; });
	});
});
</script>
<?php endif; ?>
