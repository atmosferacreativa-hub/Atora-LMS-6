<?php
/**
 * CLMS_Module_Admin_Page — PT-2.5 (sprint 6.3.0)
 *
 * Admin → ATORA → Módulos. Lista de módulos con toggle, validación de
 * dependencias (bloquea desactivar si hay dependientes activos, activa en
 * cascada lo requerido) y advertencia de que desactivar no borra datos.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Module_Admin_Page {

	const NONCE_ACTION = 'atora_modules_save';

	public static function init(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Módulos', 'atora-lms' ),
			__( '🧩 Módulos', 'atora-lms' ),
			'manage_options',
			'atora-modules',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'atora-lms' ) );
		}

		$notices = array();
		if ( ! empty( $_POST['atora_modules_nonce'] ) && check_admin_referer( self::NONCE_ACTION, 'atora_modules_nonce' ) ) {
			$notices = self::handle_save();
		}

		$modules = CLMS_Module_Registry::get_modules();
		$active  = array_fill_keys( CLMS_Module_Registry::get_active_slugs(), true );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Módulos ATORA', 'atora-lms' ); ?></h1>
			<p><?php esc_html_e( 'Desactivar un módulo apaga su carga (archivos, clases, menús, shortcodes) pero no borra sus datos ni sus tablas. Reactivarlo restaura la funcionalidad completa.', 'atora-lms' ); ?></p>

			<?php foreach ( $notices as $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endforeach; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, 'atora_modules_nonce' ); ?>
				<table class="widefat striped" style="max-width:900px">
					<thead>
						<tr>
							<th style="width:60px"><?php esc_html_e( 'Activo', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Módulo', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Descripción', 'atora-lms' ); ?></th>
							<th><?php esc_html_e( 'Requiere', 'atora-lms' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $modules as $slug => $def ) : ?>
						<tr>
							<td>
								<?php if ( ! empty( $def['core'] ) ) : ?>
									<input type="checkbox" checked disabled title="<?php esc_attr_e( 'Módulo núcleo — no se puede desactivar', 'atora-lms' ); ?>">
									<input type="hidden" name="modules[]" value="<?php echo esc_attr( $slug ); ?>">
								<?php else : ?>
									<input type="checkbox" name="modules[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( ! empty( $active[ $slug ] ) ); ?>>
								<?php endif; ?>
							</td>
							<td><strong><?php echo esc_html( $def['label'] ); ?></strong><?php echo ! empty( $def['core'] ) ? ' <span style="color:#64748b;font-size:11px">(' . esc_html__( 'núcleo', 'atora-lms' ) . ')</span>' : ''; ?></td>
							<td><?php echo esc_html( $def['description'] ); ?></td>
							<td><?php echo $def['requires'] ? esc_html( implode( ', ', $def['requires'] ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar cambios', 'atora-lms' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @return array<int,array{type:string,message:string}>
	 */
	private static function handle_save(): array {
		$modules   = CLMS_Module_Registry::get_modules();
		$submitted = isset( $_POST['modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$submitted = array_intersect( $submitted, array_keys( $modules ) );

		$current = CLMS_Module_Registry::get_active_slugs();
		$notices = array();

		// Cascada de activación: cualquier slug que se está activando trae
		// consigo sus dependencias, sin necesidad de confirmación (dirección
		// segura, nunca desactiva nada).
		$final = $submitted;
		foreach ( $submitted as $slug ) {
			if ( in_array( $slug, $current, true ) ) { continue; } // ya estaba activo
			$closure = CLMS_Module_Registry::resolve_activation_closure( $slug );
			$added   = array_diff( $closure, $final );
			if ( $added ) {
				$final = array_values( array_unique( array_merge( $final, $closure ) ) );
				$notices[] = array(
					'type'    => 'info',
					'message' => sprintf(
						/* translators: 1: módulo activado, 2: lista de dependencias */
						__( '"%1$s" requiere: %2$s — también se activaron.', 'atora-lms' ),
						$modules[ $slug ]['label'],
						implode( ', ', array_map( static fn( $s ) => $modules[ $s ]['label'] ?? $s, $added ) )
					),
				);
			}
		}

		// Bloquear desactivación si hay dependientes activos que no se
		// están desactivando también en este mismo submit.
		foreach ( $current as $slug ) {
			if ( in_array( $slug, $final, true ) ) { continue; } // sigue activo, no aplica
			$dependents       = CLMS_Module_Registry::get_active_dependents( $slug );
			$dependents_kept  = array_intersect( $dependents, $final );
			if ( $dependents_kept ) {
				$final[]   = $slug; // revertir: no se puede desactivar
				$notices[] = array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: 1: módulo que no se pudo desactivar, 2: lista de dependientes */
						__( 'No se pudo desactivar "%1$s": %2$s depende de él. Desactívalos primero.', 'atora-lms' ),
						$modules[ $slug ]['label'] ?? $slug,
						implode( ', ', array_map( static fn( $s ) => $modules[ $s ]['label'] ?? $s, $dependents_kept ) )
					),
				);
			}
		}

		// Los core siempre quedan, sin importar lo que se haya enviado.
		foreach ( $modules as $slug => $def ) {
			if ( ! empty( $def['core'] ) && ! in_array( $slug, $final, true ) ) {
				$final[] = $slug;
			}
		}

		update_option( 'atora_active_modules', array_values( array_unique( $final ) ) );
		CLMS_Module_Registry::flush_cache();

		$notices[] = array(
			'type'    => 'success',
			'message' => __( 'Cambios guardados. Algunos módulos requieren recargar la página para aplicarse por completo.', 'atora-lms' ),
		);

		return $notices;
	}
}
