<?php
/**
 * Webhooks Admin UI.
 *
 * @package ATORA_LMS\Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar webhooks.', 'atora-lms' ) );
}

$status = array(
	'type'    => '',
	'message' => '',
);

$trigger_options = array(
	'user_registered'    => __( 'Usuario registrado', 'atora-lms' ),
	'course_enrolled'    => __( 'Inscripción en curso', 'atora-lms' ),
	'lesson_completed'   => __( 'Lección completada', 'atora-lms' ),
	'course_completed'   => __( 'Curso completado', 'atora-lms' ),
	'purchase_completed' => __( 'Compra completada', 'atora-lms' ),
);

$sanitize_webhooks = static function ( array $items ): array {
	$normalized = array();
	foreach ( $items as $item ) {
		$row = (array) $item;
		$id  = absint( $row['id'] ?? 0 );
		if ( ! $id ) {
			continue;
		}
		$normalized[] = (object) array(
			'id'               => $id,
			'name'             => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			'url'              => esc_url_raw( (string) ( $row['url'] ?? '' ) ),
			'trigger'          => sanitize_key( (string) ( $row['trigger'] ?? '' ) ),
			'method'           => sanitize_key( (string) ( $row['method'] ?? 'POST' ) ),
			'payload_template' => wp_kses_post( (string) ( $row['payload_template'] ?? '{}' ) ),
			'active'           => ! empty( $row['active'] ) ? 1 : 0,
		);
	}
	return array_values( $normalized );
};

$webhooks = $sanitize_webhooks( (array) get_option( 'atora_outbound_webhooks', array() ) );

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['atora_webhook_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	check_admin_referer( 'atora_webhook_admin_page', 'atora_webhook_nonce' );

	$action = sanitize_key( (string) wp_unslash( $_POST['atora_webhook_action'] ) );

	if ( 'save' === $action ) {
		$id = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		if ( ! $id ) {
			$id = wp_rand( 1000, 999999 );
		}

		$entry = (object) array(
			'id'               => $id,
			'name'             => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
			'url'              => esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) ),
			'trigger'          => sanitize_key( (string) wp_unslash( $_POST['trigger'] ?? 'user_registered' ) ),
			'method'           => sanitize_key( (string) wp_unslash( $_POST['method'] ?? 'POST' ) ),
			'payload_template' => wp_kses_post( (string) wp_unslash( $_POST['payload_template'] ?? '{}' ) ),
			'active'           => ! empty( $_POST['active'] ) ? 1 : 0,
		);

		$replaced = false;
		foreach ( $webhooks as $index => $webhook ) {
			if ( absint( $webhook->id ?? 0 ) === $id ) {
				$webhooks[ $index ] = $entry;
				$replaced           = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$webhooks[] = $entry;
		}

		$webhooks = $sanitize_webhooks( $webhooks );
		update_option( 'atora_outbound_webhooks', $webhooks, false );
		$status = array(
			'type'    => 'success',
			'message' => __( 'Webhook guardado correctamente.', 'atora-lms' ),
		);
	}

	if ( 'delete' === $action ) {
		$id       = absint( wp_unslash( $_POST['id'] ?? 0 ) );
		$webhooks = array_values(
			array_filter(
				$webhooks,
				static function ( $webhook ) use ( $id ): bool {
					return absint( $webhook->id ?? 0 ) !== $id;
				}
			)
		);
		$webhooks = $sanitize_webhooks( $webhooks );
		update_option( 'atora_outbound_webhooks', $webhooks, false );
		$status = array(
			'type'    => 'success',
			'message' => __( 'Webhook eliminado.', 'atora-lms' ),
		);
	}

	if ( 'test' === $action ) {
		$url     = esc_url_raw( (string) wp_unslash( $_POST['url'] ?? '' ) );
		$payload = wp_json_encode(
			array(
				'test'      => true,
				'source'    => 'ATORA LMS',
				'timestamp' => gmdate( 'c' ),
			)
		);
		$success = false;

		if ( class_exists( '\ATORA\Automation\Outbound_Webhooks' ) && method_exists( '\ATORA\Automation\Outbound_Webhooks', 'dispatch' ) ) {
			$success = (bool) \ATORA\Automation\Outbound_Webhooks::dispatch( $url, 'POST', (string) $payload );
		} else {
			$response = wp_remote_post(
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => $payload,
					'timeout' => 10,
				)
			);
			$success  = ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400;
		}

		$status = array(
			'type'    => $success ? 'success' : 'error',
			'message' => $success
				? __( 'Webhook de prueba enviado correctamente.', 'atora-lms' )
				: __( 'Falló el envío de prueba. Verifica URL y endpoint.', 'atora-lms' ),
		);
	}
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Webhooks salientes', 'atora-lms' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Configura endpoints externos para recibir eventos de ATORA (CRM, academia y comercio).', 'atora-lms' ); ?></p>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( (string) $status['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;margin-top:16px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Nuevo webhook', 'atora-lms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'atora_webhook_admin_page', 'atora_webhook_nonce' ); ?>
			<input type="hidden" name="atora_webhook_action" value="save">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="atora-wh-name"><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></label></th>
					<td><input id="atora-wh-name" name="name" type="text" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="atora-wh-url"><?php esc_html_e( 'URL destino', 'atora-lms' ); ?></label></th>
					<td><input id="atora-wh-url" name="url" type="url" class="regular-text code" placeholder="https://hooks.example.com/..." required></td>
				</tr>
				<tr>
					<th scope="row"><label for="atora-wh-trigger"><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></label></th>
					<td>
						<select id="atora-wh-trigger" name="trigger">
							<?php foreach ( $trigger_options as $trigger_key => $trigger_label ) : ?>
								<option value="<?php echo esc_attr( (string) $trigger_key ); ?>"><?php echo esc_html( (string) $trigger_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="atora-wh-method"><?php esc_html_e( 'Método HTTP', 'atora-lms' ); ?></label></th>
					<td>
						<select id="atora-wh-method" name="method">
							<option value="POST">POST</option>
							<option value="PUT">PUT</option>
							<option value="PATCH">PATCH</option>
							<option value="GET">GET</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="atora-wh-payload"><?php esc_html_e( 'Payload JSON', 'atora-lms' ); ?></label></th>
					<td>
						<textarea id="atora-wh-payload" name="payload_template" rows="5" class="large-text code">{ "event":"{{trigger}}", "site":"{{site_url}}", "user":"{{user_email}}" }</textarea>
						<p class="description"><?php esc_html_e( 'Puedes usar variables como {{user_id}}, {{user_email}}, {{user_name}}, {{site_url}}, {{event_timestamp}}.', 'atora-lms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Activo', 'atora-lms' ); ?></th>
					<td><label><input type="checkbox" name="active" value="1" checked> <?php esc_html_e( 'Enviar en producción', 'atora-lms' ); ?></label></td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar webhook', 'atora-lms' ); ?></button></p>
		</form>
	</div>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;margin-top:16px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Webhooks registrados', 'atora-lms' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Método', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'URL', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $webhooks ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No hay webhooks configurados todavía.', 'atora-lms' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $webhooks as $webhook ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $webhook->name ?? '' ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $webhook->trigger ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) strtoupper( (string) ( $webhook->method ?? 'POST' ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $webhook->url ?? '' ) ); ?></code></td>
							<td><?php echo ! empty( $webhook->active ) ? esc_html__( 'Activo', 'atora-lms' ) : esc_html__( 'Inactivo', 'atora-lms' ); ?></td>
							<td style="white-space:nowrap;">
								<form method="post" style="display:inline-block;margin-right:6px;">
									<?php wp_nonce_field( 'atora_webhook_admin_page', 'atora_webhook_nonce' ); ?>
									<input type="hidden" name="atora_webhook_action" value="test">
									<input type="hidden" name="url" value="<?php echo esc_attr( (string) ( $webhook->url ?? '' ) ); ?>">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Probar', 'atora-lms' ); ?></button>
								</form>
								<form method="post" style="display:inline-block;">
									<?php wp_nonce_field( 'atora_webhook_admin_page', 'atora_webhook_nonce' ); ?>
									<input type="hidden" name="atora_webhook_action" value="delete">
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) absint( $webhook->id ?? 0 ) ); ?>">
									<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Eliminar', 'atora-lms' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
