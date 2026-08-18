<?php
/**
 * Forms admin fallback view.
 *
 * @package ATORA_LMS\Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar formularios.', 'atora-lms' ) );
}

$status = array(
	'type'    => '',
	'message' => '',
);

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$action = sanitize_key( (string) wp_unslash( $_POST['atora_form_admin_action'] ?? '' ) );

	if ( 'quick_create' === $action && check_admin_referer( 'atora_form_quick_create', 'atora_form_nonce' ) ) {
		$title = sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) );
		if ( '' === $title ) {
			$status = array(
				'type'    => 'error',
				'message' => __( 'Debes indicar un título para crear el formulario.', 'atora-lms' ),
			);
		} else {
			$form_id = wp_insert_post(
				array(
					'post_type'   => 'atora_form',
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);

			if ( is_wp_error( $form_id ) ) {
				$status = array(
					'type'    => 'error',
					'message' => $form_id->get_error_message(),
				);
			} else {
				$default_schema = array(
					'fields'          => array(
						array(
							'type'        => 'text',
							'name'        => 'nombre',
							'label'       => __( 'Nombre', 'atora-lms' ),
							'required'    => true,
							'placeholder' => __( 'Tu nombre', 'atora-lms' ),
						),
						array(
							'type'        => 'email',
							'name'        => 'email',
							'label'       => __( 'Correo electrónico', 'atora-lms' ),
							'required'    => true,
							'placeholder' => __( 'tu@email.com', 'atora-lms' ),
						),
						array(
							'type'        => 'textarea',
							'name'        => 'mensaje',
							'label'       => __( 'Mensaje', 'atora-lms' ),
							'required'    => false,
							'placeholder' => __( 'Cuéntanos en qué te ayudamos', 'atora-lms' ),
						),
					),
					'submit_text'     => __( 'Enviar', 'atora-lms' ),
					'success_message' => __( '¡Gracias! Tu mensaje fue enviado.', 'atora-lms' ),
				);

				update_post_meta( (int) $form_id, 'atora_form_schema', wp_json_encode( $default_schema ) );

				$status = array(
					'type'    => 'success',
					/* translators: %d: form id */
					'message' => sprintf( __( 'Formulario creado (ID #%d).', 'atora-lms' ), absint( $form_id ) ),
				);
			}
		}
	}
}

$forms = get_posts(
	array(
		'post_type'      => 'atora_form',
		'posts_per_page' => 50,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);

global $wpdb;
$entries_table = $wpdb->prefix . 'atora_form_entries';
$table_exists  = ( $entries_table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entries_table ) ) );
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Formularios ATORA', 'atora-lms' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Panel básico para crear formularios rápidos y copiar shortcodes.', 'atora-lms' ); ?></p>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( (string) $status['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;max-width:760px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Crear formulario rápido', 'atora-lms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'atora_form_quick_create', 'atora_form_nonce' ); ?>
			<input type="hidden" name="atora_form_admin_action" value="quick_create">
			<p>
				<label for="atora-form-title"><strong><?php esc_html_e( 'Título', 'atora-lms' ); ?></strong></label><br>
				<input id="atora-form-title" type="text" name="title" class="regular-text" required>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Crear', 'atora-lms' ); ?></button></p>
		</form>
	</div>

	<h2 style="margin-top:20px;"><?php esc_html_e( 'Formularios existentes', 'atora-lms' ); ?></h2>
	<?php if ( empty( $forms ) ) : ?>
		<p><?php esc_html_e( 'Aún no hay formularios creados.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:980px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Título', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Shortcode', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Entradas', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $forms as $form ) : ?>
					<?php
					$entries_count = 0;
					if ( $table_exists ) {
						$entries_count = absint(
							$wpdb->get_var(
								$wpdb->prepare(
									"SELECT COUNT(*) FROM {$entries_table} WHERE form_id = %d",
									$form->ID
								)
							)
						);
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $form->post_title ); ?></strong><br>
							<small>#<?php echo esc_html( (string) absint( $form->ID ) ); ?></small>
						</td>
						<td><code>[atora_form id="<?php echo esc_html( (string) absint( $form->ID ) ); ?>"]</code></td>
						<td><?php echo esc_html( (string) $entries_count ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $form->post_date ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! $table_exists ) : ?>
		<p style="margin-top:12px;">
			<em><?php esc_html_e( 'Nota: la tabla de entradas de formularios no está creada todavía en esta instalación.', 'atora-lms' ); ?></em>
		</p>
	<?php endif; ?>
</div>
