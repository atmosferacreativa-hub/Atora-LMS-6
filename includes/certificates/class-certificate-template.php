<?php
/**
 * Plantilla institucional del certificado (6.33.0), configurable por academia:
 * logo, colores, textos, campos visibles y hasta tres firmas (imagen, nombre y
 * cargo). Pantalla: ATORA LMS → Plantilla de certificado.
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Certificate_Template {

	const OPTION = 'atora_certificate_template';
	const PAGE   = 'atora-certificate-template';
	const MAX_SIGNATURES = 3;

	public static function boot(): void {
		add_action( 'atora_lms_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_atora_certificate_template', array( __CLASS__, 'save_from_admin' ) );
	}

	public static function defaults(): array {
		return array(
			'logo_id'         => 0,
			'primary_color'   => '#1B3A8C',
			'accent_color'    => '#E0A100',
			'title'           => __( 'Certificado', 'atora-lms' ),
			'intro'           => __( 'Se certifica que', 'atora-lms' ),
			'body'            => __( 'completó satisfactoriamente', 'atora-lms' ),
			'footer'          => '',
			'show_hours'      => true,
			'show_date'       => true,
			'show_grade'      => false,
			'signatures'      => array(),
		);
	}

	/** Plantilla vigente. Sin firmas configuradas, usa la firma de los ajustes anteriores (6.29). */
	public static function get(): array {
		$saved    = get_option( self::OPTION, array() );
		$template = array_merge( self::defaults(), is_array( $saved ) ? array_intersect_key( $saved, self::defaults() ) : array() );
		$template['signatures'] = self::clean_signatures( (array) $template['signatures'] );
		if ( ! $template['signatures'] ) {
			$name = sanitize_text_field( (string) get_option( 'clms_certificate_signature_name', '' ) );
			$role = sanitize_text_field( (string) get_option( 'clms_certificate_signature_role', '' ) );
			if ( '' !== $name || '' !== $role ) {
				$template['signatures'] = array( array( 'image_id' => 0, 'name' => $name, 'role' => $role ) );
			}
		}
		if ( ! $template['logo_id'] && class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' ) ) {
			$template['logo_id'] = absint( ( (array) CLMS_Settings::get_academy_settings() )['logo_id'] ?? 0 );
		}
		return $template;
	}

	public static function save( array $input ): void {
		$defaults = self::defaults();
		$clean    = array(
			'logo_id'       => absint( $input['logo_id'] ?? 0 ),
			'primary_color' => sanitize_hex_color( (string) ( $input['primary_color'] ?? '' ) ) ?: $defaults['primary_color'],
			'accent_color'  => sanitize_hex_color( (string) ( $input['accent_color'] ?? '' ) ) ?: $defaults['accent_color'],
			'title'         => sanitize_text_field( (string) ( $input['title'] ?? $defaults['title'] ) ),
			'intro'         => sanitize_text_field( (string) ( $input['intro'] ?? $defaults['intro'] ) ),
			'body'          => sanitize_text_field( (string) ( $input['body'] ?? $defaults['body'] ) ),
			'footer'        => sanitize_textarea_field( (string) ( $input['footer'] ?? '' ) ),
			'show_hours'    => ! empty( $input['show_hours'] ),
			'show_date'     => ! empty( $input['show_date'] ),
			'show_grade'    => ! empty( $input['show_grade'] ),
			'signatures'    => self::clean_signatures( (array) ( $input['signatures'] ?? array() ) ),
		);
		update_option( self::OPTION, $clean, false );
	}

	private static function clean_signatures( array $signatures ): array {
		$out = array();
		foreach ( $signatures as $signature ) {
			if ( ! is_array( $signature ) ) {
				continue;
			}
			$row = array(
				'image_id' => absint( $signature['image_id'] ?? 0 ),
				'name'     => sanitize_text_field( (string) ( $signature['name'] ?? '' ) ),
				'role'     => sanitize_text_field( (string) ( $signature['role'] ?? '' ) ),
			);
			if ( $row['image_id'] || '' !== $row['name'] ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, 0, self::MAX_SIGNATURES );
	}

	/** Cambia cuando cambia la plantilla (o sus imágenes): los PDF guardados se regeneran. */
	public static function version(): string {
		$template = self::get();
		$files    = array( (int) $template['logo_id'] => get_post_modified_time( 'U', true, (int) $template['logo_id'] ) );
		foreach ( $template['signatures'] as $signature ) {
			$files[ (int) $signature['image_id'] ] = get_post_modified_time( 'U', true, (int) $signature['image_id'] );
		}
		return substr( md5( wp_json_encode( array( $template, $files, get_bloginfo( 'name' ) ) ) ), 0, 12 );
	}

	public static function menu(): void {
		add_submenu_page( 'clms-dashboard', __( 'Plantilla de certificado', 'atora-lms' ), __( 'Plantilla de certificado', 'atora-lms' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function save_from_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		check_admin_referer( 'atora_certificate_template' );
		self::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- se sanea campo por campo en save().
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function image_field( string $name, int $attachment_id, string $label ): string {
		$preview = $attachment_id ? wp_get_attachment_image( $attachment_id, 'medium', false, array( 'style' => 'max-height:70px;width:auto;display:block;margin:6px 0' ) ) : '';
		return sprintf(
			'<div class="atora-cert-image"><input type="hidden" name="%1$s" value="%2$d"><span class="atora-cert-preview">%3$s</span><button type="button" class="button atora-cert-pick">%4$s</button> <button type="button" class="button-link atora-cert-clear">%5$s</button></div>',
			esc_attr( $name ),
			$attachment_id,
			$preview, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de wp_get_attachment_image.
			esc_html( $label ),
			esc_html__( 'Quitar', 'atora-lms' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}
		wp_enqueue_media();
		$t = self::get();
		echo '<div class="wrap"><h1>' . esc_html__( 'Plantilla de certificado', 'atora-lms' ) . '</h1>';
		if ( ! empty( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Plantilla guardada. Los certificados se regeneran con ella la próxima vez que se descarguen.', 'atora-lms' ) . '</p></div>';
		}
		if ( ! ATORA_Certificate_PDF::available() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Este servidor no puede generar PDF (falta la extensión mbstring o dom de PHP).', 'atora-lms' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'atora_certificate_template' );
		echo '<input type="hidden" name="action" value="atora_certificate_template"><table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__( 'Logo', 'atora-lms' ) . '</th><td>' . self::image_field( 'logo_id', (int) $t['logo_id'], __( 'Elegir logo', 'atora-lms' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf( '<tr><th>%s</th><td><input type="color" name="primary_color" value="%s"> <input type="color" name="accent_color" value="%s"></td></tr>', esc_html__( 'Colores (principal y acento)', 'atora-lms' ), esc_attr( $t['primary_color'] ), esc_attr( $t['accent_color'] ) );
		foreach ( array( 'title' => __( 'Título', 'atora-lms' ), 'intro' => __( 'Texto antes del nombre', 'atora-lms' ), 'body' => __( 'Texto antes del curso o programa', 'atora-lms' ) ) as $key => $label ) {
			printf( '<tr><th>%s</th><td><input type="text" class="regular-text" name="%s" value="%s"></td></tr>', esc_html( $label ), esc_attr( $key ), esc_attr( (string) $t[ $key ] ) );
		}
		printf( '<tr><th>%s</th><td><textarea name="footer" rows="2" class="large-text">%s</textarea></td></tr>', esc_html__( 'Pie (opcional)', 'atora-lms' ), esc_textarea( (string) $t['footer'] ) );
		echo '<tr><th>' . esc_html__( 'Campos', 'atora-lms' ) . '</th><td>';
		foreach ( array( 'show_hours' => __( 'Horas', 'atora-lms' ), 'show_date' => __( 'Fecha de emisión', 'atora-lms' ), 'show_grade' => __( 'Nota final (si aplica)', 'atora-lms' ) ) as $key => $label ) {
			printf( '<label style="margin-right:16px"><input type="checkbox" name="%s" value="1" %s> %s</label>', esc_attr( $key ), checked( ! empty( $t[ $key ] ), true, false ), esc_html( $label ) );
		}
		echo '</td></tr>';
		for ( $i = 0; $i < self::MAX_SIGNATURES; $i++ ) {
			$s = $t['signatures'][ $i ] ?? array( 'image_id' => 0, 'name' => '', 'role' => '' );
			/* translators: %d: número de firma */
			echo '<tr><th>' . esc_html( sprintf( __( 'Firma %d', 'atora-lms' ), $i + 1 ) ) . '</th><td>';
			echo self::image_field( "signatures[{$i}][image_id]", (int) $s['image_id'], __( 'Elegir imagen de la firma', 'atora-lms' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf( '<input type="text" name="signatures[%1$d][name]" value="%2$s" placeholder="%3$s"> <input type="text" name="signatures[%1$d][role]" value="%4$s" placeholder="%5$s">', $i, esc_attr( $s['name'] ), esc_attr__( 'Nombre', 'atora-lms' ), esc_attr( $s['role'] ), esc_attr__( 'Cargo', 'atora-lms' ) );
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( __( 'Guardar plantilla', 'atora-lms' ) );
		echo '</form></div>';
		?>
		<script>
		document.querySelectorAll('.atora-cert-image').forEach(function (box) {
			var input = box.querySelector('input'), preview = box.querySelector('.atora-cert-preview');
			box.querySelector('.atora-cert-pick').addEventListener('click', function () {
				var frame = wp.media({ library: { type: 'image' }, multiple: false });
				frame.on('select', function () {
					var file = frame.state().get('selection').first().toJSON();
					input.value = file.id;
					preview.innerHTML = '<img src="' + (file.sizes && file.sizes.medium ? file.sizes.medium.url : file.url) + '" style="max-height:70px;width:auto;display:block;margin:6px 0">';
				});
				frame.open();
			});
			box.querySelector('.atora-cert-clear').addEventListener('click', function () { input.value = 0; preview.innerHTML = ''; });
		});
		</script>
		<?php
	}
}
