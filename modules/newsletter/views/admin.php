<?php
/**
 * Newsletter admin fallback view.
 *
 * @package ATORA_LMS\Newsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'clms_access_admin' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'No tienes permisos para gestionar Newsletter.', 'atora-lms' ) );
}

$status = array(
	'type'    => '',
	'message' => '',
);

if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$action = sanitize_key( (string) wp_unslash( $_POST['atora_nl_admin_action'] ?? '' ) );

	if ( 'quick_create' === $action && check_admin_referer( 'atora_nl_quick_create', 'atora_nl_nonce' ) ) {
		$title = sanitize_text_field( (string) wp_unslash( $_POST['title'] ?? '' ) );
		$type  = sanitize_key( (string) wp_unslash( $_POST['type'] ?? 'academic' ) );

		if ( '' === $title ) {
			$status = array(
				'type'    => 'error',
				'message' => __( 'Debes indicar un título para crear la newsletter.', 'atora-lms' ),
			);
		} elseif ( class_exists( '\ATORA\Newsletter\Newsletter' ) ) {
			$result = \ATORA\Newsletter\Newsletter::save(
				array(
					'title'           => $title,
					'excerpt'         => '',
					'type'            => in_array( $type, array( 'academic', 'commercial' ), true ) ? $type : 'academic',
					'status'          => 'draft',
					'sections'        => array(),
					'schedule_type'   => 'once',
					'schedule_config' => array(),
					'segment'         => array(),
					'ab_enabled'      => 0,
					'ab_variants'     => array(),
				)
			);

			$status = is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					/* translators: %d: newsletter id */
					'message' => sprintf( __( 'Newsletter creada (ID #%d).', 'atora-lms' ), absint( $result ) ),
				);
		}
	}

	if ( 'send_now' === $action && check_admin_referer( 'atora_nl_send_now', 'atora_nl_nonce' ) ) {
		$post_id = absint( wp_unslash( $_POST['newsletter_id'] ?? 0 ) );
		if ( $post_id > 0 && class_exists( '\ATORA\Newsletter\Newsletter' ) ) {
			$counts = \ATORA\Newsletter\Newsletter::send( $post_id );
			$status = array(
				'type'    => 'success',
				/* translators: 1: sent count 2: failed count */
				'message' => sprintf( __( 'Envío ejecutado. Enviados: %1$d · Fallidos: %2$d', 'atora-lms' ), absint( $counts['sent'] ?? 0 ), absint( $counts['failed'] ?? 0 ) ),
			);
		}
	}
}

$newsletters = get_posts(
	array(
		'post_type'      => 'atora_newsletter',
		'posts_per_page' => 20,
		'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Newsletter ATORA', 'atora-lms' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Gestión rápida de newsletters: borradores, envío inmediato y acceso al archivo.', 'atora-lms' ); ?></p>

	<?php if ( ! empty( $status['message'] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( 'error' === $status['type'] ? 'error' : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( (string) $status['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div style="background:#fff;border:1px solid #dbe0e6;border-radius:10px;padding:16px;max-width:980px;">
		<h2 style="margin-top:0;"><?php esc_html_e( 'Crear newsletter rápida', 'atora-lms' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'atora_nl_quick_create', 'atora_nl_nonce' ); ?>
			<input type="hidden" name="atora_nl_admin_action" value="quick_create">
			<p>
				<label for="atora-nl-title"><strong><?php esc_html_e( 'Título', 'atora-lms' ); ?></strong></label><br>
				<input id="atora-nl-title" type="text" name="title" class="regular-text" required>
			</p>
			<p>
				<label for="atora-nl-type"><strong><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></strong></label><br>
				<select id="atora-nl-type" name="type">
					<option value="academic"><?php esc_html_e( 'Académica', 'atora-lms' ); ?></option>
					<option value="commercial"><?php esc_html_e( 'Comercial', 'atora-lms' ); ?></option>
				</select>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Crear borrador', 'atora-lms' ); ?></button></p>
		</form>
	</div>

	<h2 style="margin-top:20px;"><?php esc_html_e( 'Últimas newsletters', 'atora-lms' ); ?></h2>
	<?php if ( empty( $newsletters ) ) : ?>
		<p><?php esc_html_e( 'Aún no hay newsletters registradas.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Título', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Enviados', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $newsletters as $newsletter ) : ?>
					<?php
					$type      = sanitize_key( (string) get_post_meta( $newsletter->ID, 'atora_nl_type', true ) );
					$status_nl = sanitize_key( (string) get_post_meta( $newsletter->ID, 'atora_nl_status', true ) );
					$sent      = absint( get_post_meta( $newsletter->ID, 'atora_nl_sent_count', true ) );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $newsletter->post_title ); ?></strong><br>
							<small>#<?php echo esc_html( (string) absint( $newsletter->ID ) ); ?></small>
						</td>
						<td><?php echo esc_html( 'commercial' === $type ? __( 'Comercial', 'atora-lms' ) : __( 'Académica', 'atora-lms' ) ); ?></td>
						<td><?php echo esc_html( '' !== $status_nl ? $status_nl : __( 'draft', 'atora-lms' ) ); ?></td>
						<td><?php echo esc_html( (string) $sent ); ?></td>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $newsletter->post_date ) ) ); ?></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $newsletter->ID ) ?: '' ); ?>"><?php esc_html_e( 'Editar', 'atora-lms' ); ?></a>
							<form method="post" style="display:inline;">
								<?php wp_nonce_field( 'atora_nl_send_now', 'atora_nl_nonce' ); ?>
								<input type="hidden" name="atora_nl_admin_action" value="send_now">
								<input type="hidden" name="newsletter_id" value="<?php echo esc_attr( (string) absint( $newsletter->ID ) ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Enviar ahora', 'atora-lms' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
