<?php
/**
 * Certificado en PDF (6.33.0), generado en el servidor con Dompdf (PHP puro:
 * solo mbstring y dom, sin dependencias de sistema) y QR de verificación con
 * chillerlan/php-qrcode (salida SVG, sin GD). Las dos librerías van empaquetadas
 * en `lib/packages`.
 *
 * - Cada certificado lleva el código de su credencial institucional (UUID
 *   aleatorio, no adivinable) y un QR a `/verificar/{codigo}`.
 * - Los certificados emitidos antes reciben su credencial la primera vez que se
 *   pide el PDF, conservando la fecha de emisión del certificado.
 * - El PDF se guarda en una carpeta privada de uploads y se regenera si cambia
 *   la plantilla.
 *
 * @package ATORA_LMS
 * @since 6.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Certificate_PDF {

	const DIR = 'atora-private/certificates';

	/** ¿Puede este servidor generar PDF? */
	public static function available(): bool {
		return extension_loaded( 'mbstring' ) && extension_loaded( 'dom' ) && is_readable( self::autoload_path() );
	}

	private static function autoload_path(): string {
		return dirname( __DIR__, 2 ) . '/lib/packages/autoload.php';
	}

	private static function load_libraries(): bool {
		if ( ! self::available() ) {
			return false;
		}
		require_once self::autoload_path();
		return class_exists( '\\Dompdf\\Dompdf' ) && class_exists( '\\chillerlan\\QRCode\\QRCode' );
	}

	/**
	 * Credencial del certificado: la existente, o una nueva con los datos
	 * congelados del certificado y su fecha de emisión original.
	 *
	 * @return array{uuid:string,status:string}|WP_Error
	 */
	public static function credential( int $user_id, string $type, int $target_id, array $record ) {
		if ( ! class_exists( 'CLMS_Credential_Service' ) ) {
			return new WP_Error( 'atora_certificate_credentials', __( 'El registro de credenciales no está disponible.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$service  = new CLMS_Credential_Service();
		$existing = $service->find_for_target( $user_id, $type, $target_id );
		if ( is_array( $existing ) && ! empty( $existing['credential_uuid'] ) && 'superseded' !== $existing['status'] ) {
			return array( 'uuid' => (string) $existing['credential_uuid'], 'status' => (string) $existing['status'] );
		}
		$issued = $service->issue(
			array(
				'user_id'          => $user_id,
				'target_type'      => $type,
				'target_id'        => $target_id,
				'cert_code'        => (string) ( $record['certificate_code'] ?? '' ),
				'holder_name'      => (string) ( $record['student_name'] ?? '' ) ?: ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : '' ),
				'achievement_name' => (string) ( $record['target_title'] ?? get_the_title( $target_id ) ),
				'issuer_name'      => (string) ( $record['academy'] ?? get_bloginfo( 'name' ) ),
				'grade'            => $record['final_average'] ?? null,
			),
			get_current_user_id()
		);
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}
		// Certificado anterior a la credencial: conserva su fecha de emisión.
		$original = (string) ( $record['issued_at'] ?? '' );
		if ( '' !== $original && strtotime( $original ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'atora_credentials', array( 'issued_at' => get_gmt_from_date( $original ) ), array( 'id' => (int) $issued['id'] ) ); // phpcs:ignore WordPress.DB
		}
		return array( 'uuid' => (string) $issued['credential_uuid'], 'status' => (string) $issued['status'] );
	}

	/** Lo que muestra el certificado (todo congelado al emitirse). */
	public static function data( int $user_id, string $type, int $target_id, array $record, string $uuid ): array {
		$template = ATORA_Certificate_Template::get();
		$issued   = (string) ( $record['issue_date'] ?? $record['issued_at'] ?? '' );
		return array(
			'template'     => $template,
			'student_name' => (string) ( $record['student_name'] ?? '' ) ?: ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : '' ),
			'target_type'  => $type,
			'target_title' => (string) ( $record['target_title'] ?? '' ) ?: (string) get_the_title( $target_id ),
			'hours'        => absint( $record['academic_hours'] ?? 0 ),
			'issue_date'   => $issued ? wp_date( get_option( 'date_format' ), strtotime( $issued ) ) : '',
			'grade'        => isset( $record['final_average'] ) && (float) $record['final_average'] > 0 ? (float) $record['final_average'] : null,
			'code'         => (string) ( $record['certificate_code'] ?? '' ),
			'academy'      => (string) ( $record['academy'] ?? get_bloginfo( 'name' ) ),
			'verify_code'  => $uuid,
			'verify_url'   => ATORA_Certificate_Verify::url( $uuid ),
		);
	}

	/** QR de la URL de verificación, como imagen SVG embebida. */
	public static function qr_data_uri( string $url ): string {
		if ( ! self::load_libraries() ) {
			return '';
		}
		$options = new \chillerlan\QRCode\QROptions( array(
			'outputType'      => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
			'eccLevel'        => \chillerlan\QRCode\Common\EccLevel::M,
			'outputBase64'    => true,
			'addQuietzone'    => true,
			'svgUseFillAttributes' => true,
		) );
		return (string) ( new \chillerlan\QRCode\QRCode( $options ) )->render( $url );
	}

	/**
	 * Imagen lista para el PDF: PNG sin canal alfa, aplanado sobre el color de
	 * fondo real de la plantilla. Así una firma o un logo transparentes no dejan
	 * recuadro sobre fondos de color, y Dompdf incrusta el PNG sin procesarlo
	 * (con alfa, Dompdf usa Imagick, que en algunos servidores falla con un error
	 * fatal). Se hace con GD; si no hay GD, con Imagick en un try/catch; sin
	 * ninguno, la imagen se omite (el resto del certificado sale igual).
	 */
	private static function image_data_uri( int $attachment_id, string $background ): string {
		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $path || ! is_readable( $path ) ) {
			return '';
		}
		$png = self::flatten( $path, $background );
		return '' !== $png ? 'data:image/png;base64,' . base64_encode( $png ) : '';
	}

	/** @return int[] RGB de un color #rrggbb. */
	private static function rgb( string $hex ): array {
		$hex = ltrim( (string) sanitize_hex_color( $hex ) ?: '#ffffff', '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** PNG truecolor sin alfa, con lo transparente pintado del color de fondo. */
	public static function flatten( string $path, string $background = '#ffffff' ): string {
		$mime = (string) wp_check_filetype( $path )['type'];
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) {
			return '';
		}
		list( $r, $g, $b ) = self::rgb( $background );
		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagepng' ) ) {
			$source = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( $source ) {
				$width  = imagesx( $source );
				$height = imagesy( $source );
				$canvas = imagecreatetruecolor( $width, $height );
				imagealphablending( $canvas, true );
				imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, $r, $g, $b ) );
				imagecopy( $canvas, $source, 0, 0, 0, 0, $width, $height );
				imagesavealpha( $canvas, false );
				ob_start();
				imagepng( $canvas, null, 6 );
				return (string) ob_get_clean();
			}
		}
		if ( class_exists( 'Imagick' ) ) {
			try {
				$image = new Imagick( $path );
				$image->setImageBackgroundColor( sprintf( 'rgb(%d,%d,%d)', $r, $g, $b ) );
				$image = $image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
				$image->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
				$image->setImageFormat( 'png24' );
				return (string) $image->getImageBlob();
			} catch ( Throwable $e ) {
				return '';
			}
		}
		return '';
	}

	/** HTML que se convierte en PDF (horizontal, A4). */
	public static function html( array $d ): string {
		$t       = $d['template'];
		$primary = esc_attr( $t['primary_color'] );
		$accent  = esc_attr( $t['accent_color'] );
		$paper   = esc_attr( $t['background_color'] );
		$logo    = self::image_data_uri( (int) $t['logo_id'], $t['background_color'] );
		$qr      = self::qr_data_uri( $d['verify_url'] );
		$facts   = array();
		if ( ! empty( $t['show_hours'] ) && $d['hours'] ) {
			/* translators: %d: horas académicas */
			$facts[] = sprintf( _n( '%d hora académica', '%d horas académicas', $d['hours'], 'atora-lms' ), $d['hours'] );
		}
		if ( ! empty( $t['show_grade'] ) && null !== $d['grade'] ) {
			/* translators: %s: nota final */
			$facts[] = sprintf( __( 'Nota final: %s', 'atora-lms' ), number_format_i18n( $d['grade'], 0 ) );
		}
		if ( ! empty( $t['show_date'] ) && $d['issue_date'] ) {
			/* translators: %s: fecha */
			$facts[] = sprintf( __( 'Emitido el %s', 'atora-lms' ), $d['issue_date'] );
		}
		$signatures = '';
		$count      = max( 1, count( $t['signatures'] ) );
		foreach ( $t['signatures'] as $signature ) {
			$image       = self::image_data_uri( (int) $signature['image_id'], $t['background_color'] );
			$signatures .= '<td class="signature" style="width:' . floor( 100 / $count ) . '%">'
				. ( $image ? '<img class="signature-image" src="' . esc_attr( $image ) . '">' : '<div class="signature-space"></div>' )
				. '<div class="signature-line"></div><div class="signature-name">' . esc_html( $signature['name'] ) . '</div><div class="signature-role">' . esc_html( $signature['role'] ) . '</div></td>';
		}
		$kind = 'program' === $d['target_type'] ? __( 'el programa', 'atora-lms' ) : __( 'el curso', 'atora-lms' );
		ob_start();
		?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><style>
@page { margin: 0; size: A4 landscape; }
html, body { background-color: <?php echo $paper; // phpcs:ignore ?>; }
body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #1f2937; }
.frame { position: absolute; top: 18px; left: 18px; right: 18px; bottom: 18px; border: 6px solid <?php echo $primary; // phpcs:ignore ?>; }
.inner { position: absolute; top: 30px; left: 30px; right: 30px; bottom: 30px; border: 1.5px solid <?php echo $accent; // phpcs:ignore ?>; padding: 34px 44px 0; text-align: center; }
.logo { max-height: 80px; max-width: 240px; }
.academy { font-size: 13px; letter-spacing: 3px; text-transform: uppercase; color: <?php echo $primary; // phpcs:ignore ?>; margin-top: 8px; }
.title { font-size: 44px; font-weight: bold; color: <?php echo $primary; // phpcs:ignore ?>; margin: 18px 0 10px; letter-spacing: 2px; text-transform: uppercase; }
.intro, .body { font-size: 16px; color: #4b5563; margin: 12px 0; }
.name { font-size: 34px; font-weight: bold; margin: 8px 0; border-bottom: 2px solid <?php echo $accent; // phpcs:ignore ?>; display: inline-block; padding: 0 30px 6px; }
.target { font-size: 24px; font-weight: bold; color: <?php echo $primary; // phpcs:ignore ?>; margin: 8px 0; }
.facts { font-size: 14px; color: #374151; margin-top: 10px; }
.signatures { width: 100%; margin-top: 44px; border-collapse: collapse; }
.signature { text-align: center; vertical-align: bottom; padding: 0 14px; }
.signature-image { max-height: 52px; max-width: 170px; }
.signature-space { height: 52px; }
.signature-line { border-top: 1px solid #374151; margin: 4px 20px 4px; }
.signature-name { font-size: 12px; font-weight: bold; }
.signature-role { font-size: 11px; color: #6b7280; }
/* A4 horizontal = 1122 × 793 px: el pie va dentro del marco, con ancho explícito (Dompdf no combina left y right). */
.bottom { position: absolute; left: 62px; top: 640px; width: 998px; border-collapse: collapse; }
.bottom td { font-size: 8.5px; color: #6b7280; vertical-align: bottom; }
.code { text-align: left; }
.verify { text-align: right; }
.qr { width: 84px; padding-left: 8px; }
.qr img { width: 84px; height: 84px; background-color: #ffffff; padding: 3px; }
.footer { font-size: 10px; color: #6b7280; margin-top: 8px; }
</style></head><body>
<div class="frame"></div>
<div class="inner">
	<?php if ( $logo ) : ?><img class="logo" src="<?php echo esc_attr( $logo ); ?>"><?php endif; ?>
	<div class="academy"><?php echo esc_html( $d['academy'] ); ?></div>
	<div class="title"><?php echo esc_html( $t['title'] ); ?></div>
	<div class="intro"><?php echo esc_html( $t['intro'] ); ?></div>
	<div class="name"><?php echo esc_html( $d['student_name'] ); ?></div>
	<div class="body"><?php echo esc_html( $t['body'] . ' ' . $kind ); ?></div>
	<div class="target"><?php echo esc_html( $d['target_title'] ); ?></div>
	<?php if ( $facts ) : ?><div class="facts"><?php echo esc_html( implode( '  ·  ', $facts ) ); ?></div><?php endif; ?>
	<?php if ( $signatures ) : ?><table class="signatures"><tr><?php echo $signatures; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado al armarse. ?></tr></table><?php endif; ?>
	<?php if ( '' !== (string) $t['footer'] ) : ?><div class="footer"><?php echo esc_html( $t['footer'] ); ?></div><?php endif; ?>
</div>
	<table class="bottom"><tr>
		<td class="code"><?php /* translators: %s: código del certificado */ echo esc_html( $d['code'] ? sprintf( __( 'Certificado %s', 'atora-lms' ), $d['code'] ) : '' ); ?></td>
		<td class="verify"><?php esc_html_e( 'Verifica este certificado en', 'atora-lms' ); ?><br><?php echo esc_html( $d['verify_url'] ); ?><br><?php esc_html_e( 'Código de verificación:', 'atora-lms' ); ?> <?php echo esc_html( $d['verify_code'] ); ?></td>
		<?php if ( $qr ) : ?><td class="qr"><img src="<?php echo esc_attr( $qr ); ?>"></td><?php endif; ?>
	</tr></table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	public static function render( string $html ): string {
		if ( ! self::load_libraries() ) {
			return '';
		}
		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'tempDir', get_temp_dir() );
		$options->set( 'fontCache', get_temp_dir() );
		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'landscape' );
		$dompdf->render();
		return (string) $dompdf->output();
	}

	private static function directory(): string {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Carpeta privada: el PDF solo se entrega por PHP, con permisos.
			file_put_contents( dirname( $dir ) . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( dirname( $dir ) . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/**
	 * PDF del certificado de un estudiante (lo genera la primera vez, o si cambió la plantilla).
	 *
	 * @param array $resolved Resultado de CLMS_Certificates::resolve_certificate_for_user().
	 * @return string|WP_Error Bytes del PDF.
	 */
	public static function for_user( int $user_id, array $resolved ) {
		if ( ! self::available() ) {
			return new WP_Error( 'atora_certificate_pdf_unavailable', __( 'Este servidor no puede generar el certificado en PDF.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		$type      = 'program' === ( $resolved['target_type'] ?? '' ) ? 'program' : 'course';
		$target_id = 'program' === $type ? absint( $resolved['program_id'] ?? 0 ) : absint( $resolved['course_id'] ?? 0 );
		$record    = (array) ( $resolved['record'] ?? array() );
		$credential = self::credential( $user_id, $type, $target_id, $record );
		if ( is_wp_error( $credential ) ) {
			return $credential;
		}
		if ( 'revoked' === $credential['status'] ) {
			return new WP_Error( 'atora_certificate_revoked', __( 'Este certificado fue revocado.', 'atora-lms' ), array( 'status' => 410 ) );
		}
		$file = trailingslashit( self::directory() ) . sanitize_file_name( $credential['uuid'] . '-' . ATORA_Certificate_Template::version() . '.pdf' );
		if ( is_readable( $file ) ) {
			return (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$pdf = self::render( self::html( self::data( $user_id, $type, $target_id, $record, $credential['uuid'] ) ) );
		if ( '' === $pdf ) {
			return new WP_Error( 'atora_certificate_pdf_failed', __( 'No se pudo generar el certificado. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		// Versiones anteriores del mismo certificado ya no sirven.
		foreach ( (array) glob( trailingslashit( self::directory() ) . $credential['uuid'] . '-*.pdf' ) as $old ) {
			wp_delete_file( $old );
		}
		file_put_contents( $file, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $pdf;
	}
}
