<?php
/**
 * Vista: página de ajustes de seguridad en el admin.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar seguridad.', 'atora-lms' ) );
}

$options = (array) get_option( 'atora_security_options', array() );
$today   = wp_date( get_option( 'date_format' ) );

$policy_key = sanitize_key( (string) ( $options['twofa_policy'] ?? 'optional' ) );
$policy_map = array(
	'disabled'    => __( 'Desactivado', 'atora-lms' ),
	'optional'    => __( 'Opcional', 'atora-lms' ),
	'admins_only' => __( 'Solo admins', 'atora-lms' ),
	'everyone'    => __( 'Todos', 'atora-lms' ),
);
$policy_label = $policy_map[ $policy_key ] ?? __( 'Opcional', 'atora-lms' );

$methods_count = count( (array) ( $options['twofa_methods'] ?? array() ) );
if ( $methods_count < 1 ) {
	$methods_count = 1;
}

$captcha_provider = sanitize_key( (string) ( $options['captcha_provider'] ?? '' ) );
$captcha_label    = '' !== $captcha_provider
	? strtoupper( $captcha_provider )
	: __( 'No configurado', 'atora-lms' );

$header_stats = array(
	array(
		'label' => __( 'Política 2FA', 'atora-lms' ),
		'value' => $policy_label,
	),
	array(
		'label' => __( 'Métodos 2FA', 'atora-lms' ),
		'value' => number_format_i18n( $methods_count ),
	),
	array(
		'label' => __( 'Captcha', 'atora-lms' ),
		'value' => $captcha_label,
	),
	array(
		'label' => __( 'Versión', 'atora-lms' ),
		'value' => 'v' . ATORA_LMS_VERSION,
	),
);

$guide_steps = array(
	__( 'Define política 2FA según el riesgo operativo del campus.', 'atora-lms' ),
	__( 'Configura captcha y valida llaves en tu entorno actual.', 'atora-lms' ),
	__( 'Guarda cambios y prueba login/registro con un usuario real.', 'atora-lms' ),
);

$quick_links = array(
	array(
		'title'       => __( 'Hub ajustes', 'atora-lms' ),
		'description' => __( 'Volver al panel central de configuración.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=clms-settings-hub' ),
	),
	array(
		'title'       => __( 'Ajustes ATORA', 'atora-lms' ),
		'description' => __( 'Parámetros globales del LMS y APIs.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=clms-settings' ),
	),
	array(
		'title'       => __( 'Ajustes de email', 'atora-lms' ),
		'description' => __( 'Identidades y entregabilidad de correo.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=atora-emails' ),
	),
	array(
		'title'       => __( 'Ajustes de mensajería', 'atora-lms' ),
		'description' => __( 'Canales WhatsApp y Telegram.', 'atora-lms' ),
		'url'         => admin_url( 'admin.php?page=atora-messaging' ),
	),
);
?>

<div class="wrap atora-hub atora-hub--settings atora-security-hub">
	<?php if ( class_exists( 'ATORA_Token_Crypto' ) && ! ATORA_Token_Crypto::has_dedicated_key() ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Los tokens OAuth (Calendar, Meet, Drive) se cifran con AUTH_KEY porque no hay una clave dedicada. Define ATORA_TOKEN_KEY en wp-config.php para una clave independiente de rotación.', 'atora-lms' ); ?>
			</p>
		</div>
	<?php endif; ?>
	<div class="atora-hub__header">
		<div>
			<span class="atora-hub__context"><?php esc_html_e( 'Hub ajustes', 'atora-lms' ); ?></span>
			<h1 class="atora-hub__title"><?php esc_html_e( 'Seguridad de plataforma', 'atora-lms' ); ?></h1>
			<p class="atora-hub__date"><?php echo esc_html( $today ); ?> · <?php esc_html_e( '2FA, captcha y protección de acceso', 'atora-lms' ); ?></p>
			<p class="atora-hub__subtitle"><?php esc_html_e( 'Protege la operación académica y comercial con políticas de acceso coherentes, claras y fáciles de administrar.', 'atora-lms' ); ?></p>
		</div>
		<div class="atora-hub__meta">
			<?php foreach ( $header_stats as $header_stat ) : ?>
				<div class="atora-hub__meta-item">
					<strong class="atora-hub__meta-num"><?php echo esc_html( (string) $header_stat['value'] ); ?></strong>
					<span class="atora-hub__meta-lbl"><?php echo esc_html( (string) $header_stat['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="atora-hub__guide">
		<h3 class="atora-hub__guide-title"><?php esc_html_e( 'Flujo recomendado en 3 pasos', 'atora-lms' ); ?></h3>
		<div class="atora-hub__guide-list">
			<?php foreach ( $guide_steps as $step_index => $step_label ) : ?>
				<div class="atora-hub__guide-step">
					<span class="atora-hub__guide-badge"><?php echo esc_html( (string) ( $step_index + 1 ) ); ?></span>
					<span><?php echo esc_html( (string) $step_label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="atora-hub__quick">
		<h2 class="atora-hub__quick-title"><?php esc_html_e( 'Configuración de seguridad', 'atora-lms' ); ?></h2>
		<?php settings_errors( 'atora_security_settings' ); ?>
		<form method="post" action="options.php" class="atora-security-hub__form">
			<?php
			settings_fields( 'atora_security_settings' );
			do_settings_sections( 'atora-security' );
			submit_button( __( 'Guardar cambios', 'atora-lms' ) );
			?>
		</form>
	</div>

	<div class="atora-hub__quick">
		<h3 class="atora-hub__quick-title"><?php esc_html_e( 'Navegación rápida', 'atora-lms' ); ?></h3>
		<div class="atora-hub__quick-grid">
			<?php foreach ( $quick_links as $item ) : ?>
				<a class="atora-hub__quick-link" href="<?php echo esc_url( (string) $item['url'] ); ?>">
					<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
					<span><?php echo esc_html( (string) $item['description'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<style>
	.atora-security-hub .atora-security-hub__form .form-table th {
		width: 220px;
	}
	.atora-security-hub .atora-security-hub__form .submit {
		margin-top: 18px;
		padding-top: 0;
	}
</style>
