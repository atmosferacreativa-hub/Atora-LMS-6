<?php
/**
 * Tab: Plantillas.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$catalog = class_exists( '\ATORA\EmailEngine\Email_Templates' )
	? (array) \ATORA\EmailEngine\Email_Templates::get_catalog()
	: array();
?>
<h2><?php esc_html_e( 'Plantillas disponibles', 'atora-lms' ); ?></h2>
<p><?php esc_html_e( 'Listado de plantillas cargadas por el motor de email.', 'atora-lms' ); ?></p>

<?php if ( empty( $catalog ) ) : ?>
	<p><?php esc_html_e( 'No se encontró catálogo de plantillas.', 'atora-lms' ); ?></p>
<?php else : ?>
	<table class="widefat striped" style="max-width:980px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Clave', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Categoría', 'atora-lms' ); ?></th>
				<th><?php esc_html_e( 'Asunto base', 'atora-lms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $catalog as $key => $template ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) $key ); ?></code></td>
					<td><?php echo esc_html( (string) ( $template['name'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $template['category'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $template['subject'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
