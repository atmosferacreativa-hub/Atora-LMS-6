<?php
/**
 * Tab: Plantillas (6.38.0: editor con bloques, variables validadas y vista previa escritorio/móvil).
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ATORA\EmailEngine\Email_Template_Editor;
use ATORA\EmailEngine\Email_Templates;

$catalog = class_exists( Email_Templates::class ) ? (array) Email_Templates::get_catalog() : array();
$editing = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( (string) $_GET['template'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$notice  = null;
$preview = null;
$draft   = null;

if ( '' !== $editing && isset( $catalog[ $editing ] ) && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && current_user_can( 'manage_options' ) ) {
	check_admin_referer( 'atora_email_template_' . $editing );
	$op    = sanitize_key( (string) wp_unslash( $_POST['atora_template_op'] ?? '' ) );
	$draft = array(
		'subject' => (string) wp_unslash( $_POST['subject'] ?? '' ),
		'blocks'  => array_values( (array) wp_unslash( $_POST['blocks'] ?? array() ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validate() sanea cada campo.
	);
	if ( 'reset' === $op ) {
		Email_Template_Editor::reset( $editing );
		$draft  = null;
		$notice = array( 'success', __( 'Plantilla restaurada a la original.', 'atora-lms' ) );
	} elseif ( 'save' === $op ) {
		$result = Email_Template_Editor::save( $editing, $draft );
		$notice = is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', __( 'Plantilla guardada.', 'atora-lms' ) );
		if ( ! is_wp_error( $result ) ) {
			$draft = null;
		}
	} else {
		$preview = Email_Template_Editor::preview( $editing, $draft, wp_get_current_user() );
		if ( is_wp_error( $preview ) ) {
			$notice  = array( 'error', $preview->get_error_message() );
			$preview = null;
		}
	}
}

if ( $notice ) {
	printf( '<div class="notice notice-%1$s inline"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
}

if ( '' !== $editing && isset( $catalog[ $editing ] ) ) :
	$original = $catalog[ $editing ];
	$override = Email_Template_Editor::override_for( $editing );
	$current  = $draft ?? $override ?? array( 'subject' => (string) ( $original['subject'] ?? '' ), 'blocks' => array() );
	$blocks   = is_array( $current['blocks'] ?? null ) ? $current['blocks'] : array();
	if ( ! $blocks ) {
		$blocks = array(
			array( 'type' => 'heading', 'text' => (string) ( $original['name'] ?? '' ) ),
			array( 'type' => 'text', 'text' => __( 'Hola {{display_name}},', 'atora-lms' ) ),
		);
	}
	$preview = $preview ?? Email_Template_Editor::preview( $editing, null, wp_get_current_user() );
	$labels  = array(
		'heading' => __( 'Título', 'atora-lms' ),
		'text'    => __( 'Texto', 'atora-lms' ),
		'button'  => __( 'Botón', 'atora-lms' ),
		'divider' => __( 'Separador', 'atora-lms' ),
	);
	?>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-emails&tab=templates' ) ); ?>">&larr; <?php esc_html_e( 'Todas las plantillas', 'atora-lms' ); ?></a></p>
	<h2><?php echo esc_html( (string) ( $original['name'] ?? $editing ) ); ?> <code><?php echo esc_html( $editing ); ?></code></h2>
	<p class="description">
		<?php echo $override ? esc_html__( 'Personalizada. Si la restauras, vuelve la original.', 'atora-lms' ) : esc_html__( 'Original del sistema. Al guardar se crea tu versión.', 'atora-lms' ); ?>
		<?php esc_html_e( 'El texto plano se genera solo.', 'atora-lms' ); ?>
	</p>

	<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">
		<form method="post" style="flex:1 1 420px;max-width:620px;" id="atora-template-form">
			<?php wp_nonce_field( 'atora_email_template_' . $editing ); ?>
			<p>
				<label for="atora-template-subject"><strong><?php esc_html_e( 'Asunto', 'atora-lms' ); ?></strong></label><br>
				<input id="atora-template-subject" type="text" class="large-text" name="subject" value="<?php echo esc_attr( (string) ( $current['subject'] ?? '' ) ); ?>" required>
			</p>
			<div id="atora-template-blocks">
				<?php foreach ( $blocks as $i => $block ) : $type = (string) ( $block['type'] ?? 'text' ); ?>
					<fieldset class="atora-template-block" style="border:1px solid #dcdcde;border-radius:6px;padding:10px;margin:0 0 10px;background:#fff;">
						<select name="blocks[<?php echo (int) $i; ?>][type]" aria-label="<?php esc_attr_e( 'Tipo de bloque', 'atora-lms' ); ?>">
							<?php foreach ( $labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="button" class="button button-small" data-move="-1" aria-label="<?php esc_attr_e( 'Subir', 'atora-lms' ); ?>">&uarr;</button>
						<button type="button" class="button button-small" data-move="1" aria-label="<?php esc_attr_e( 'Bajar', 'atora-lms' ); ?>">&darr;</button>
						<button type="button" class="button button-small" data-remove="1"><?php esc_html_e( 'Quitar', 'atora-lms' ); ?></button>
						<textarea name="blocks[<?php echo (int) $i; ?>][text]" rows="3" class="large-text" style="margin-top:6px;" aria-label="<?php esc_attr_e( 'Contenido', 'atora-lms' ); ?>"><?php echo esc_textarea( (string) ( $block['text'] ?? '' ) ); ?></textarea>
						<input type="text" name="blocks[<?php echo (int) $i; ?>][url]" class="large-text" aria-label="<?php esc_attr_e( 'Enlace del botón', 'atora-lms' ); ?>" placeholder="https://… o {{course_url}}" value="<?php echo esc_attr( (string) ( $block['url'] ?? '' ) ); ?>">
					</fieldset>
				<?php endforeach; ?>
			</div>
			<p><button type="button" class="button" id="atora-template-add"><?php esc_html_e( 'Agregar bloque', 'atora-lms' ); ?></button></p>
			<p><strong><?php esc_html_e( 'Variables disponibles', 'atora-lms' ); ?>:</strong>
				<?php foreach ( Email_Template_Editor::allowed_variables( $editing ) as $var ) : ?>
					<code style="display:inline-block;margin:2px;">{{<?php echo esc_html( $var ); ?>}}</code>
				<?php endforeach; ?>
			</p>
			<p>
				<button type="submit" class="button" name="atora_template_op" value="preview"><?php esc_html_e( 'Vista previa', 'atora-lms' ); ?></button>
				<button type="submit" class="button button-primary" name="atora_template_op" value="save"><?php esc_html_e( 'Guardar', 'atora-lms' ); ?></button>
				<?php if ( $override ) : ?>
					<button type="submit" class="button button-link-delete" name="atora_template_op" value="reset" formnovalidate><?php esc_html_e( 'Restaurar original', 'atora-lms' ); ?></button>
				<?php endif; ?>
			</p>
		</form>

		<?php if ( is_array( $preview ) ) : ?>
			<div style="flex:1 1 420px;">
				<p><strong><?php esc_html_e( 'Asunto', 'atora-lms' ); ?>:</strong> <?php echo esc_html( $preview['subject'] ); ?></p>
				<div style="display:flex;gap:16px;flex-wrap:wrap;">
					<figure style="margin:0;">
						<figcaption><?php esc_html_e( 'Escritorio', 'atora-lms' ); ?></figcaption>
						<iframe title="<?php esc_attr_e( 'Vista previa en escritorio', 'atora-lms' ); ?>" sandbox="" style="width:640px;max-width:100%;height:560px;border:1px solid #dcdcde;background:#fff;" srcdoc="<?php echo esc_attr( $preview['html'] ); ?>"></iframe>
					</figure>
					<figure style="margin:0;">
						<figcaption><?php esc_html_e( 'Móvil', 'atora-lms' ); ?></figcaption>
						<iframe title="<?php esc_attr_e( 'Vista previa en móvil', 'atora-lms' ); ?>" sandbox="" style="width:375px;max-width:100%;height:560px;border:1px solid #dcdcde;background:#fff;" srcdoc="<?php echo esc_attr( $preview['html'] ); ?>"></iframe>
					</figure>
				</div>
				<details style="margin-top:8px;"><summary><?php esc_html_e( 'Texto plano', 'atora-lms' ); ?></summary><pre style="white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:8px;"><?php echo esc_html( $preview['text'] ); ?></pre></details>
			</div>
		<?php endif; ?>
	</div>
	<script>
	( function () {
		var box = document.getElementById( 'atora-template-blocks' );
		var add = document.getElementById( 'atora-template-add' );
		if ( ! box || ! add ) { return; }
		function renumber() {
			Array.prototype.forEach.call( box.querySelectorAll( '.atora-template-block' ), function ( set, i ) {
				Array.prototype.forEach.call( set.querySelectorAll( '[name]' ), function ( el ) {
					el.name = el.name.replace( /blocks\[\d+\]/, 'blocks[' + i + ']' );
				} );
			} );
		}
		add.addEventListener( 'click', function () {
			var sets = box.querySelectorAll( '.atora-template-block' );
			if ( ! sets.length ) { return; }
			var copy = sets[ sets.length - 1 ].cloneNode( true );
			Array.prototype.forEach.call( copy.querySelectorAll( 'textarea, input' ), function ( el ) { el.value = ''; } );
			copy.querySelector( 'select' ).value = 'text';
			box.appendChild( copy );
			renumber();
		} );
		box.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( 'button' );
			if ( ! btn ) { return; }
			var set = btn.closest( '.atora-template-block' );
			if ( btn.dataset.remove && box.querySelectorAll( '.atora-template-block' ).length > 1 ) {
				set.remove();
			} else if ( '-1' === btn.dataset.move && set.previousElementSibling ) {
				box.insertBefore( set, set.previousElementSibling );
			} else if ( '1' === btn.dataset.move && set.nextElementSibling ) {
				box.insertBefore( set.nextElementSibling, set );
			}
			renumber();
		} );
	}() );
	</script>
<?php else : ?>
	<h2><?php esc_html_e( 'Plantillas', 'atora-lms' ); ?></h2>
	<p><?php esc_html_e( 'Personaliza el asunto y el contenido de cada correo. Una variable desconocida no se puede guardar.', 'atora-lms' ); ?></p>
	<?php if ( empty( $catalog ) ) : ?>
		<p><?php esc_html_e( 'No se encontró catálogo de plantillas.', 'atora-lms' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:1100px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nombre', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Categoría', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Asunto', 'atora-lms' ); ?></th>
					<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
					<th><span class="screen-reader-text"><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $catalog as $key => $template ) : $live = Email_Templates::get( (string) $key ); ?>
					<tr>
						<td><?php echo esc_html( (string) ( $template['name'] ?? '' ) ); ?><br><code><?php echo esc_html( (string) $key ); ?></code></td>
						<td><?php echo esc_html( (string) ( $template['category'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $live['subject'] ?? '' ) ); ?></td>
						<td><?php echo ! empty( $live['customized'] ) ? esc_html__( 'Personalizada', 'atora-lms' ) : esc_html__( 'Original', 'atora-lms' ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'atora-emails', 'tab' => 'templates', 'template' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Editar', 'atora-lms' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>
