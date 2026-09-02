<?php
/**
 * CLMS_Module_Admin_Page — PT-2.5 / PT-3.3 (sprint 6.3.0)
 *
 * Admin → ATORA → Módulos. Lista de módulos con toggle, validación de
 * dependencias (bloquea desactivar si hay dependientes activos, activa en
 * cascada lo requerido) y advertencia de que desactivar no borra datos.
 * Incluye además el cambio de perfil de instalación con vista previa
 * (activados/desactivados) antes de confirmar (PT-3.3).
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Module_Admin_Page {

	const NONCE_ACTION         = 'atora_modules_save';
	const PROFILE_NONCE_ACTION = 'atora_modules_profile';

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

		$profile_preview = null;
		$previewed_profile = '';
		if ( ! empty( $_POST['atora_profile_nonce'] ) && check_admin_referer( self::PROFILE_NONCE_ACTION, 'atora_profile_nonce' ) ) {
			list( $notices2, $profile_preview, $previewed_profile ) = self::handle_profile_switch();
			$notices = array_merge( $notices, $notices2 );
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

			<?php if ( class_exists( 'CLMS_Install_Profiles' ) ) : self::render_profile_switcher( $profile_preview, $previewed_profile ); endif; ?>

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
	 * PT-3.3: sección "cambiar perfil" con vista previa antes de confirmar.
	 *
	 * @param array{activates:string[],deactivates:string[]}|null $preview
	 * @param string                                               $previewed_profile
	 */
	private static function render_profile_switcher( ?array $preview, string $previewed_profile ): void {
		$profiles = CLMS_Install_Profiles::get_profiles();
		$current  = CLMS_Install_Profiles::current();
		$modules  = CLMS_Module_Registry::get_modules();
		?>
		<h2 style="margin-top:28px"><?php esc_html_e( 'Perfil de instalación', 'atora-lms' ); ?></h2>
		<p><?php esc_html_e( 'Cambiar de perfil activa/desactiva el conjunto de módulos correspondiente. No borra datos de los módulos que queden desactivados.', 'atora-lms' ); ?></p>

		<form method="post" style="max-width:900px">
			<?php wp_nonce_field( self::PROFILE_NONCE_ACTION, 'atora_profile_nonce' ); ?>
			<div style="display:grid;gap:8px;margin-bottom:12px">
				<?php foreach ( $profiles as $key => $def ) : ?>
				<label style="display:flex;gap:10px;align-items:flex-start">
					<input type="radio" name="install_profile" value="<?php echo esc_attr( $key ); ?>" <?php checked( $current, $key ); ?> style="margin-top:3px">
					<span>
						<strong><?php echo esc_html( $def['label'] ); ?></strong>
						<?php if ( $current === $key ) : ?>
							<span style="color:#16a34a;font-size:11px">(<?php echo CLMS_Install_Profiles::is_modified() ? esc_html__( 'actual, modificado', 'atora-lms' ) : esc_html__( 'actual', 'atora-lms' ); ?>)</span>
						<?php endif; ?>
						<br><span style="color:#64748b;font-size:12px"><?php echo esc_html( $def['description'] ); ?></span>
					</span>
				</label>
				<?php endforeach; ?>
			</div>

			<?php if ( null !== $preview ) : ?>
				<div style="background:#f8fafc;border:.5px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:13px">
					<strong><?php echo esc_html( sprintf( /* translators: %s: perfil */ __( 'Vista previa: %s', 'atora-lms' ), $profiles[ $previewed_profile ]['label'] ?? $previewed_profile ) ); ?></strong>
					<?php if ( ! $preview['activates'] && ! $preview['deactivates'] ) : ?>
						<p style="margin:6px 0 0;color:#64748b"><?php esc_html_e( 'Sin cambios respecto al estado actual.', 'atora-lms' ); ?></p>
					<?php else : ?>
						<?php if ( $preview['activates'] ) : ?>
							<p style="margin:6px 0 0;color:#166534">✓ <?php esc_html_e( 'Se activarán:', 'atora-lms' ); ?> <?php echo esc_html( implode( ', ', array_map( static fn( $s ) => $modules[ $s ]['label'] ?? $s, $preview['activates'] ) ) ); ?></p>
						<?php endif; ?>
						<?php if ( $preview['deactivates'] ) : ?>
							<p style="margin:4px 0 0;color:#991b1b">✕ <?php esc_html_e( 'Se desactivarán:', 'atora-lms' ); ?> <?php echo esc_html( implode( ', ', array_map( static fn( $s ) => $modules[ $s ]['label'] ?? $s, $preview['deactivates'] ) ) ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
					<input type="hidden" name="confirm_profile" value="<?php echo esc_attr( $previewed_profile ); ?>">
					<button type="submit" name="atora_profile_action" value="confirm" class="button button-primary" style="margin-top:10px">
						<?php esc_html_e( 'Confirmar cambio de perfil', 'atora-lms' ); ?>
					</button>
				</div>
			<?php else : ?>
				<button type="submit" name="atora_profile_action" value="preview" class="button"><?php esc_html_e( 'Ver cambios', 'atora-lms' ); ?></button>
			<?php endif; ?>
		</form>
		<hr style="margin:24px 0">
		<?php
	}

	/**
	 * @return array{0:array<int,array{type:string,message:string}>,1:array{activates:string[],deactivates:string[]}|null,2:string}
	 */
	private static function handle_profile_switch(): array {
		$action = sanitize_key( (string) wp_unslash( $_POST['atora_profile_action'] ?? '' ) );

		if ( 'confirm' === $action ) {
			$profile = sanitize_key( (string) wp_unslash( $_POST['confirm_profile'] ?? '' ) );
			if ( CLMS_Install_Profiles::apply( $profile ) ) {
				// P3 (6.12.0): el perfil nuevo puede activar módulos que no
				// tenían tablas creadas todavía.
				if ( class_exists( '\ATORA\V5_Installer' ) ) {
					\ATORA\V5_Installer::ensure_active_module_tables();
				}
				$label = CLMS_Install_Profiles::get_profiles()[ $profile ]['label'] ?? $profile;
				return array(
					array( array( 'type' => 'success', 'message' => sprintf( __( 'Perfil "%s" aplicado.', 'atora-lms' ), $label ) ) ),
					null,
					'',
				);
			}
			return array( array( array( 'type' => 'error', 'message' => __( 'Perfil desconocido.', 'atora-lms' ) ) ), null, '' );
		}

		// 'preview' (o cualquier otro valor): mostrar vista previa sin aplicar.
		$profile = sanitize_key( (string) wp_unslash( $_POST['install_profile'] ?? '' ) );
		$preview = CLMS_Install_Profiles::preview( $profile );
		if ( null === $preview ) {
			return array( array( array( 'type' => 'error', 'message' => __( 'Perfil desconocido.', 'atora-lms' ) ) ), null, '' );
		}
		return array( array(), $preview, $profile );
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
		if ( class_exists( 'CLMS_Install_Profiles' ) ) { CLMS_Install_Profiles::mark_modified(); }

		// P3 (6.12.0): un módulo recién activado necesita sus tablas ya —
		// no esperar a que cambie SCHEMA_VERSION. dbDelta() es idempotente,
		// así que llamar a esto para módulos ya activos antes no hace nada.
		if ( class_exists( '\ATORA\V5_Installer' ) ) {
			\ATORA\V5_Installer::ensure_active_module_tables();
		}

		$notices[] = array(
			'type'    => 'success',
			'message' => __( 'Cambios guardados. Algunos módulos requieren recargar la página para aplicarse por completo.', 'atora-lms' ),
		);

		return $notices;
	}
}
