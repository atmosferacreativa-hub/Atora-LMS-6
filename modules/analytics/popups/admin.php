<?php
/**
 * Popups admin fallback view.
 *
 * @package ATORA_LMS\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar popups.', 'atora-lms' ) );
}

$status = array(
	'type'    => '',
	'message' => '',
);

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$action = sanitize_key( (string) wp_unslash( $_POST['atora_popup_admin_action'] ?? '' ) );

	if ( 'quick_create' === $action && check_admin_referer( 'atora_popup_quick_create', 'atora_popup_nonce' ) ) {
		$title = sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) );
		if ( '' === $title ) {
			$status = array(
				'type'    => 'error',
				'message' => __( 'Debes indicar un título para crear el popup.', 'atora-lms' ),
			);
		} else {
			$popup_id = wp_insert_post(
				array(
					'post_type'   => 'atora_popup',
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $popup_id ) ) {
				$status = array(
					'type'    => 'error',
					'message' => $popup_id->get_error_message(),
				);
			} else {
				update_post_meta( (int) $popup_id, 'atora_popup_active', 1 );
				update_post_meta(
					(int) $popup_id,
					'atora_popup_config',
					wp_json_encode(
						array(
							'title'         => $title,
							'content'       => __( 'Contenido del popup. Edítalo según tu campaña.', 'atora-lms' ),
							'trigger'       => 'entry',
							'frequency'     => 'once_session',
							'delay_seconds' => 3,
						)
					)
				);
				update_post_meta( (int) $popup_id, 'atora_popup_targeting', wp_json_encode( array( 'pages' => 'all' ) ) );

				$status = array(
					'type'    => 'success',
					/* translators: %d: popup id */
					'message' => sprintf( __( 'Popup creado (ID #%d).', 'atora-lms' ), absint( $popup_id ) ),
				);
			}
		}
	}
}

$popups = get_posts(
	array(
		'post_type'      => 'atora_popup',
		'posts_per_page' => 50,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Popups ATORA', 'atora-lms' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Panel base para popups de captación, anuncios o activación.', 'atora-lms' ); ?></p>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( (string) $status['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;max-width:760px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Crear popup rápido', 'atora-lms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'atora_popup_quick_create', 'atora_popup_nonce' ); ?>
			<input type="hidden" name="atora_popup_admin_action" value="quick_create">
			<p>
				<label for="atora-popup-title"><strong><?php esc_html_e( 'Título', 'atora-lms' ); ?></strong></label><br>
				<input id="atora-popup-title" type="text" name="title" class="regular-text" required>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Crear', 'atora-lms' ); ?></button></p>
		</form>
	</div>

	<h2 style="margin-top:20px;"><?php esc_html_e( 'Popups existentes', 'atora-lms' ); ?></h2>
	<?php if ( empty( $popups ) ) : ?>
		<p><?php esc_html_e( 'Aún no hay popups creados.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:980px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Título', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Activo', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Frecuencia', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $popups as $popup ) : ?>
					<?php
					$is_active = (bool) get_post_meta( $popup->ID, 'atora_popup_active', true );
					$config    = json_decode( (string) get_post_meta( $popup->ID, 'atora_popup_config', true ), true );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $popup->post_title ); ?></strong><br>
							<small>#<?php echo esc_html( (string) absint( $popup->ID ) ); ?></small>
						</td>
						<td><?php echo esc_html( $is_active ? __( 'Sí', 'atora-lms' ) : __( 'No', 'atora-lms' ) ); ?></td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $config['trigger'] ?? 'entry' ) ) ); ?></td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $config['frequency'] ?? 'once_session' ) ) ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $popup->post_date ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
