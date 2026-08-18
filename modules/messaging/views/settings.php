<?php
/**
 * Mensajería Admin UI.
 *
 * @package ATORA_LMS\Messaging
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar mensajería.', 'atora-lms' ) );
}

$status = array(
	'type'    => '',
	'message' => '',
);
$routing_defaults = array(
	'email'    => array( 'email:academia', 'email:teacher', 'whatsapp' ),
	'whatsapp' => array( 'email:teacher', 'email:academia', 'telegram' ),
	'telegram' => array( 'email:teacher', 'email:academia', 'whatsapp' ),
	'sms'      => array( 'email:academia', 'email:teacher' ),
);
$normalize_dispatch_channel = static function ( string $channel ): string {
	$channel = strtolower( trim( $channel ) );
	if ( '' === $channel ) {
		return '';
	}

	if ( false !== strpos( $channel, ':' ) ) {
		list( $base, $identity ) = array_pad( explode( ':', $channel, 2 ), 2, '' );
		$base = sanitize_key( $base );
		if ( 'email' !== $base ) {
			return '';
		}

		$identity = sanitize_key( $identity );
		$aliases  = array(
			'docencia'       => 'teacher',
			'comercio'       => 'admin',
			'comercial'      => 'admin',
			'commercial'     => 'admin',
			'administracion' => 'academia',
			'administration' => 'academia',
		);
		if ( isset( $aliases[ $identity ] ) ) {
			$identity = $aliases[ $identity ];
		}
		if ( ! in_array( $identity, array( 'academia', 'teacher', 'admin' ), true ) ) {
			$identity = 'academia';
		}

		return 'email:' . $identity;
	}

	$channel = sanitize_key( $channel );
	return in_array( $channel, array( 'email', 'whatsapp', 'telegram', 'sms' ), true ) ? $channel : '';
};
$parse_dispatch_list = static function ( string $raw ) use ( $normalize_dispatch_channel ): array {
	$parts  = array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $raw ) ?: array() ) );
	$output = array();
	foreach ( $parts as $part ) {
		$normalized = $normalize_dispatch_channel( (string) $part );
		if ( '' === $normalized ) {
			continue;
		}
		$output[] = $normalized;
	}

	return array_values( array_unique( $output ) );
};

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && ! empty( $_POST['atora_messaging_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	check_admin_referer( 'atora_messaging_admin_action', 'atora_messaging_nonce' );
	$action = sanitize_key( (string) wp_unslash( $_POST['atora_messaging_action'] ) );

	if ( 'test_whatsapp' === $action ) {
		$phone = sanitize_text_field( (string) wp_unslash( $_POST['test_phone'] ?? '' ) );
		$template = sanitize_key( (string) wp_unslash( $_POST['test_template'] ?? 'inactivity_reminder' ) );
		$sent = class_exists( '\ATORA\Messaging\WhatsApp' )
			? (bool) \ATORA\Messaging\WhatsApp::send_template( $phone, $template, array( 'Prueba ATORA' ) )
			: false;
		$status = array(
			'type'    => $sent ? 'success' : 'error',
			'message' => $sent ? __( 'Prueba de WhatsApp enviada.', 'atora-lms' ) : __( 'No se pudo enviar prueba de WhatsApp.', 'atora-lms' ),
		);
	}

	if ( 'test_telegram' === $action ) {
		$chat_id = sanitize_text_field( (string) wp_unslash( $_POST['test_chat_id'] ?? '' ) );
		$message = sanitize_text_field( (string) wp_unslash( $_POST['test_message'] ?? 'Prueba Telegram ATORA' ) );
		$sent = class_exists( '\ATORA\Messaging\Telegram_Bot' )
			? (bool) \ATORA\Messaging\Telegram_Bot::api_send_message( $chat_id, $message )
			: false;
		$status = array(
			'type'    => $sent ? 'success' : 'error',
			'message' => $sent ? __( 'Prueba de Telegram enviada.', 'atora-lms' ) : __( 'No se pudo enviar prueba de Telegram.', 'atora-lms' ),
		);
	}

	if ( 'save_routing' === $action ) {
		$routing_options = get_option( 'atora_messaging_options', array() );
		$routing_options = is_array( $routing_options ) ? $routing_options : array();
		$input_fallbacks = isset( $_POST['fallback_channels'] ) && is_array( $_POST['fallback_channels'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			? (array) wp_unslash( $_POST['fallback_channels'] )
			: array();

		$stored_fallbacks = array();
		foreach ( $routing_defaults as $primary => $default_list ) {
			$raw_list = sanitize_textarea_field( (string) ( $input_fallbacks[ $primary ] ?? '' ) );
			$parsed   = $parse_dispatch_list( $raw_list );
			if ( empty( $parsed ) ) {
				$parsed = $default_list;
			}
			$stored_fallbacks[ $primary ] = $parsed;
		}

		$routing_options['fallback_channels'] = $stored_fallbacks;
		update_option( 'atora_messaging_options', $routing_options );
		$status = array(
			'type'    => 'success',
			'message' => __( 'Enrutamiento guardado. El fallback global ya está activo en la cola multicanal.', 'atora-lms' ),
		);
	}

	if ( 'test_routing' === $action ) {
		$user_id  = absint( wp_unslash( $_POST['routing_user_id'] ?? get_current_user_id() ) );
		$type     = sanitize_key( (string) wp_unslash( $_POST['routing_type'] ?? 'marketing' ) );
		$template = sanitize_key( (string) wp_unslash( $_POST['routing_template'] ?? 'inactivity_reminder' ) );
		$variables_raw = (string) wp_unslash( $_POST['routing_variables'] ?? '' );
		$variables = json_decode( $variables_raw, true );
		$variables = is_array( $variables ) ? $variables : array();

		$sent = false;
		if ( $user_id > 0 && class_exists( '\ATORA\Messaging\Messaging_Router' ) ) {
			$sent = (bool) \ATORA\Messaging\Messaging_Router::send( $user_id, $type, $template, $variables, array( 'allow_duplicate' => true ) );
		}
		$status = array(
			'type'    => $sent ? 'success' : 'error',
			'message' => $sent
				? __( 'Prueba de enrutamiento encolada. Revisa la pestaña Bandeja para confirmar canal y fallback.', 'atora-lms' )
				: __( 'No se pudo encolar la prueba de enrutamiento. Verifica consentimientos/canales del usuario.', 'atora-lms' ),
		);
	}
}

$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'inbox'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tabs = array(
	'inbox'       => __( 'Bandeja', 'atora-lms' ),
	'whatsapp'    => __( 'WhatsApp', 'atora-lms' ),
	'telegram'    => __( 'Telegram', 'atora-lms' ),
	'routing'     => __( 'Enrutamiento', 'atora-lms' ),
	'preferences' => __( 'Preferencias de estudiantes', 'atora-lms' ), // PT-4.5 (6.4.0)
);
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'inbox';
}

$routing_options = get_option( 'atora_messaging_options', array() );
$routing_options = is_array( $routing_options ) ? $routing_options : array();
$routing_config  = is_array( $routing_options['fallback_channels'] ?? null ) ? $routing_options['fallback_channels'] : array();
$routing_values  = array();
foreach ( $routing_defaults as $primary => $default_list ) {
	$current = is_array( $routing_config[ $primary ] ?? null ) ? $routing_config[ $primary ] : $default_list;
	$current = array_values( array_filter( array_map( 'strval', $current ) ) );
	if ( empty( $current ) ) {
		$current = $default_list;
	}
	$routing_values[ $primary ] = implode( ', ', $current );
}

$view_file = __DIR__ . '/' . $tab . '.php';
if ( ! file_exists( $view_file ) ) {
	$view_file = __DIR__ . '/inbox.php';
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Mensajería ATORA', 'atora-lms' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Cabina operativa para WhatsApp, Telegram y trazabilidad de mensajería.', 'atora-lms' ); ?></p>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible"><p><?php echo esc_html( (string) $status['message'] ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-messaging&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;">
		<?php require $view_file; ?>
	</div>
</div>
