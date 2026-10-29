<?php
/**
 * Avatares propios del usuario (sin Gravatar).
 *
 * - Meta de usuario: `_atora_avatar_id` (attachment ID).
 * - Política: opción `atora_avatar_policy` (`self` por defecto, o `admin_only`).
 * - Presentación: filtro `pre_get_avatar_data`.
 * - API móvil: `POST /atora-mobile/v1/me/avatar` y `DELETE /atora-mobile/v1/me/avatar`.
 * - Limpieza: al reemplazar y al procesar eliminación de cuenta.
 *
 * @package ATORA_LMS
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Avatar_Service {
	public const META_AVATAR_ID = '_atora_avatar_id';
	public const META_CLIENT_UPLOAD_ID = '_atora_avatar_client_upload_id';
	public const OPTION_POLICY = 'atora_avatar_policy';
	public const POLICY_SELF = 'self';
	public const POLICY_ADMIN_ONLY = 'admin_only';
	private const MAX_BYTES = 5242880; // 5 MB.

	public static function boot(): void {
		add_filter( 'pre_get_avatar_data', array( __CLASS__, 'pre_get_avatar_data' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'rest_post_dispatch_mobile_people' ), 20, 3 );
		add_action( 'atora_account_deletion_processed', array( __CLASS__, 'on_account_deletion_processed' ), 10, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_mobile_routes' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_admin_user_avatar_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_admin_user_avatar_fields' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_admin_user_avatar_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_user_avatar_fields' ) );
	}

	public static function policy(): string {
		$policy = (string) get_option( self::OPTION_POLICY, self::POLICY_SELF );
		return in_array( $policy, array( self::POLICY_SELF, self::POLICY_ADMIN_ONLY ), true ) ? $policy : self::POLICY_SELF;
	}

	public static function can_user_manage_own_avatar( int $user_id ): bool {
		if ( self::POLICY_ADMIN_ONLY !== self::policy() ) {
			return true;
		}
		return user_can( $user_id, 'manage_options' );
	}

	public static function can_admin_manage_user_avatar( int $admin_id, int $target_user_id ): bool {
		if ( $admin_id <= 0 || $target_user_id <= 0 ) {
			return false;
		}
		return user_can( $admin_id, 'manage_options' ) && current_user_can( 'edit_user', $target_user_id );
	}

	public static function avatar_attachment_id( int $user_id ): int {
		return absint( get_user_meta( $user_id, self::META_AVATAR_ID, true ) );
	}

	public static function delete_user_avatar( int $user_id ): void {
		$old_id = self::avatar_attachment_id( $user_id );
		delete_user_meta( $user_id, self::META_AVATAR_ID );
		delete_user_meta( $user_id, self::META_CLIENT_UPLOAD_ID );
		if ( $old_id ) {
			wp_delete_attachment( $old_id, true );
		}
	}

	public static function set_user_avatar_from_upload( int $user_id, array $file, string $client_upload_id = '' ) {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'atora_avatar_user', __( 'Usuario no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_user_manage_own_avatar( $user_id ) ) {
			return new WP_Error( 'atora_avatar_policy', __( 'La institución no permite que el usuario cambie su foto.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$client_upload_id = sanitize_text_field( $client_upload_id );
		if ( '' !== $client_upload_id ) {
			$seen = (string) get_user_meta( $user_id, self::META_CLIENT_UPLOAD_ID, true );
			$existing = self::avatar_attachment_id( $user_id );
			if ( $client_upload_id === $seen && $existing && 'attachment' === get_post_type( $existing ) ) {
				return $existing;
			}
		}

		$prepared = self::validate_and_prepare_image( $file );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$prepared_path = (string) $prepared['path'];
		$prepared_type = (string) $prepared['mime'];

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$old_id = self::avatar_attachment_id( $user_id );

		$filename = 'atora-avatar-' . $user_id . '.' . self::ext_for_mime( $prepared_type );
		$sideload = array(
			'name'     => $filename,
			'type'     => $prepared_type,
			'tmp_name' => $prepared_path,
			'error'    => 0,
			'size'     => filesize( $prepared_path ) ?: 0,
		);

		$overrides = array( 'test_form' => false );
		$result = wp_handle_sideload( $sideload, $overrides );
		if ( file_exists( $prepared_path ) ) {
			@unlink( $prepared_path );
		}
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			return new WP_Error( 'atora_avatar_upload', (string) ( $result['error'] ?? __( 'Error al subir la imagen.', 'atora-lms' ) ), array( 'status' => 400 ) );
		}

		$attachment = array(
			'post_mime_type' => $prepared_type,
			'post_title'     => 'Avatar ' . $user_id,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);
		$attach_id = wp_insert_attachment( $attachment, (string) $result['file'] );
		if ( ! $attach_id || is_wp_error( $attach_id ) ) {
			return new WP_Error( 'atora_avatar_attach', __( 'No se pudo registrar el adjunto.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, (string) $result['file'] ) );
		if ( $old_id ) {
			delete_user_meta( $user_id, self::META_AVATAR_ID );
			delete_user_meta( $user_id, self::META_CLIENT_UPLOAD_ID );
			wp_delete_attachment( $old_id, true );
		}
		update_user_meta( $user_id, self::META_AVATAR_ID, $attach_id );
		if ( '' !== $client_upload_id ) {
			update_user_meta( $user_id, self::META_CLIENT_UPLOAD_ID, $client_upload_id );
		}
		return $attach_id;
	}

	/**
	 * Render: foto propia → foto del docente → iniciales en SVG.
	 */
	public static function pre_get_avatar_data( ?array $args, $id_or_email ): array {
		$args = is_array( $args ) ? $args : array();
		$size = absint( $args['size'] ?? 96 );
		$size = $size > 0 ? $size : 96;
		$user = self::user_from_id_or_email( $id_or_email );
		if ( $user ) {
			$url = self::avatar_url_for_user( (int) $user->ID, $size );
			return array(
				'url'            => $url,
				'found_avatar'   => true,
				'size'           => $size,
				'class'          => array( 'atora-avatar' ),
				'extra_attr'     => '',
				'processed_args' => $args,
			);
		}

		$seed = '';
		if ( is_string( $id_or_email ) ) {
			$seed = strtolower( trim( $id_or_email ) );
		} elseif ( is_object( $id_or_email ) && isset( $id_or_email->comment_author_email ) ) {
			$seed = strtolower( trim( (string) $id_or_email->comment_author_email ) );
		}
		return array(
			'url'            => self::initials_svg_data_uri( '?', $seed, $size ),
			'found_avatar'   => true,
			'size'           => $size,
			'class'          => array( 'atora-avatar' ),
			'extra_attr'     => '',
			'processed_args' => $args,
		);
	}

	private static function avatar_url_for_user( int $user_id, int $size ): string {
		$own = self::avatar_attachment_id( $user_id );
		if ( $own ) {
			$url = wp_get_attachment_image_url( $own, array( $size, $size ) );
			if ( is_string( $url ) && '' !== $url ) {
				return esc_url_raw( $url );
			}
		}
		$teacher_photo = absint( get_user_meta( $user_id, '_clms_instructor_photo_id', true ) );
		if ( $teacher_photo ) {
			$url = wp_get_attachment_image_url( $teacher_photo, array( $size, $size ) );
			if ( is_string( $url ) && '' !== $url ) {
				return esc_url_raw( $url );
			}
		}
		$user = get_userdata( $user_id );
		$name = $user ? (string) $user->display_name : '';
		$initials = self::initials_from_name( $name );
		return self::initials_svg_data_uri( $initials, (string) $user_id, $size );
	}

	private static function initials_and_color_for_user( int $user_id ): array {
		$user = get_userdata( $user_id );
		$initials = self::initials_from_name( $user ? (string) $user->display_name : '' );
		$hash = md5( (string) $user_id );
		$h = hexdec( substr( $hash, 0, 2 ) ) % 360;
		$bg = sprintf( 'hsl(%d, 55%%, 45%%)', $h );
		return array( 'initials' => $initials, 'color' => $bg );
	}

	private static function initials_from_name( string $name ): string {
		$name = trim( preg_replace( '/\s+/', ' ', $name ) ?? '' );
		if ( '' === $name ) {
			return '?';
		}
		$parts = array_values( array_filter( explode( ' ', $name ) ) );
		$first = mb_substr( (string) ( $parts[0] ?? '' ), 0, 1 );
		$last  = mb_substr( (string) ( $parts[ count( $parts ) - 1 ] ?? '' ), 0, 1 );
		$ini   = mb_strtoupper( $first . $last );
		$ini   = trim( $ini );
		return '' !== $ini ? $ini : '?';
	}

	private static function initials_svg_data_uri( string $initials, string $seed, int $size ): string {
		$seed = '' !== $seed ? $seed : $initials;
		$hash = md5( $seed );
		$h = hexdec( substr( $hash, 0, 2 ) ) % 360;
		$bg = sprintf( 'hsl(%d, 55%%, 45%%)', $h );
		$fg = '#ffffff';
		$font = max( 12, (int) round( $size * 0.42 ) );
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">' .
			'<rect width="100%" height="100%" fill="' . esc_attr( $bg ) . '"/>' .
			'<text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial" font-size="' . $font . '" font-weight="700" fill="' . $fg . '">' . esc_html( $initials ) . '</text>' .
			'</svg>';
		return 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $svg );
	}

	private static function mobile_avatar_url_or_empty( int $user_id, int $size ): string {
		$own = self::avatar_attachment_id( $user_id );
		if ( $own ) {
			$url = wp_get_attachment_image_url( $own, array( $size, $size ) );
			return is_string( $url ) && '' !== $url ? esc_url_raw( $url ) : '';
		}
		$teacher_photo = absint( get_user_meta( $user_id, '_clms_instructor_photo_id', true ) );
		if ( $teacher_photo ) {
			$url = wp_get_attachment_image_url( $teacher_photo, array( $size, $size ) );
			return is_string( $url ) && '' !== $url ? esc_url_raw( $url ) : '';
		}
		return '';
	}

	private static function user_from_id_or_email( $id_or_email ): ?WP_User {
		if ( is_numeric( $id_or_email ) ) {
			$user = get_userdata( absint( $id_or_email ) );
			return $user instanceof WP_User ? $user : null;
		}
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email;
		}
		if ( $id_or_email instanceof WP_Comment && $id_or_email->user_id ) {
			$user = get_userdata( (int) $id_or_email->user_id );
			return $user instanceof WP_User ? $user : null;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user instanceof WP_User ? $user : null;
		}
		return null;
	}

	private static function ext_for_mime( string $mime ): string {
		return 'image/webp' === $mime ? 'webp' : ( 'image/png' === $mime ? 'png' : 'jpg' );
	}

	private static function validate_and_prepare_image( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
			return new WP_Error( 'atora_avatar_file', __( 'Archivo no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$bytes = filesize( $file['tmp_name'] ) ?: 0;
		if ( $bytes <= 0 || $bytes > self::MAX_BYTES ) {
			return new WP_Error( 'atora_avatar_size', __( 'La imagen excede 5 MB.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$raw = file_get_contents( $file['tmp_name'] );
		if ( false === $raw || '' === $raw ) {
			return new WP_Error( 'atora_avatar_read', __( 'No se pudo leer el archivo.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$info = @getimagesizefromstring( $raw );
		if ( ! is_array( $info ) || empty( $info['mime'] ) ) {
			return new WP_Error( 'atora_avatar_type', __( 'Formato de imagen no válido.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$mime = (string) $info['mime'];
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return new WP_Error( 'atora_avatar_type', __( 'Solo se admite JPG, PNG o WebP.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$tmp = wp_tempnam( 'atora-avatar-' );
		if ( ! $tmp ) {
			return new WP_Error( 'atora_avatar_tmp', __( 'No se pudo crear archivo temporal.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		file_put_contents( $tmp, $raw );
		$processed = self::square_resize_strip_exif( $tmp, $mime );
		if ( is_wp_error( $processed ) ) {
			@unlink( $tmp );
			return $processed;
		}
		return array( 'path' => $processed, 'mime' => $mime );
	}

	/**
	 * Recorta al centro en cuadrado, redimensiona a 512 y re-encoda.
	 * Retorna la ruta del archivo procesado (se sobrescribe el archivo temporal).
	 */
	private static function square_resize_strip_exif( string $path, string $mime ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error( 'atora_avatar_editor', __( 'No se pudo procesar la imagen.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$size = $editor->get_size();
		$w = (int) ( $size['width'] ?? 0 );
		$h = (int) ( $size['height'] ?? 0 );
		if ( $w <= 0 || $h <= 0 ) {
			return new WP_Error( 'atora_avatar_dimensions', __( 'Imagen inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$side = min( $w, $h );
		$x = (int) floor( ( $w - $side ) / 2 );
		$y = (int) floor( ( $h - $side ) / 2 );
		$editor->crop( $x, $y, $side, $side );
		$editor->resize( 512, 512, true );
		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( 90 );
		}
		$saved = $editor->save( $path, $mime );
		if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) ) {
			return new WP_Error( 'atora_avatar_save', __( 'No se pudo guardar la imagen.', 'atora-lms' ), array( 'status' => 500 ) );
		}
		return (string) $saved['path'];
	}

	public static function on_account_deletion_processed( int $user_id, string $mode, int $request_id ): void {
		self::delete_user_avatar( $user_id );
	}

	public static function register_mobile_routes(): void {
		$ns = defined( 'ATORA_Mobile_REST_Controller::REST_NAMESPACE' ) ? ATORA_Mobile_REST_Controller::REST_NAMESPACE : 'atora-mobile/v1';
		register_rest_route( $ns, '/me/avatar', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mobile_post_me_avatar' ),
				'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
				'args'                => array(
					'client_upload_id' => array( 'type' => 'string', 'required' => false ),
				),
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'mobile_delete_me_avatar' ),
				'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
			),
		) );
	}

	public static function mobile_post_me_avatar( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params = $request->get_file_params();
		$file = $params['file'] ?? $params['avatar'] ?? null;
		if ( ! is_array( $file ) ) {
			return new WP_Error( 'atora_avatar_missing', __( 'Falta el archivo.', 'atora-lms' ), array( 'status' => 400 ) );
		}
		$client_upload_id = (string) $request->get_param( 'client_upload_id' );
		$result = self::set_user_avatar_from_upload( $user_id, $file, $client_upload_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$attach_id = (int) $result;
		return new WP_REST_Response( array(
			'avatar_id'  => $attach_id,
			'avatar_url' => self::avatar_url_for_user( $user_id, 192 ),
		), 200 );
	}

	public static function mobile_delete_me_avatar( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( ! self::can_user_manage_own_avatar( $user_id ) ) {
			return new WP_Error( 'atora_avatar_policy', __( 'La institución no permite que el usuario cambie su foto.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		self::delete_user_avatar( $user_id );
		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/**
	 * Sin tocar `includes/mobile/class-mobile-teacher-controller.php`, añade `avatar_url`
	 * a objetos de tipo {id, name} cuando `id` corresponde a un usuario.
	 */
	public static function rest_post_dispatch_mobile_people( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/' . ATORA_Mobile_REST_Controller::REST_NAMESPACE . '/' ) ) {
			return $result;
		}

		$response = rest_ensure_response( $result );
		$data = $response->get_data();
		$changed = false;
		$data = self::augment_mobile_response_people( $route, $data, $changed );
		if ( $changed ) {
			$response->set_data( $data );
		}
		return $response;
	}

	private static function augment_mobile_response_people( string $route, $data, bool &$changed ) {
		// /me and /dashboard: normalize current user payload.
		if ( in_array( $route, array( '/' . ATORA_Mobile_REST_Controller::REST_NAMESPACE . '/me', '/' . ATORA_Mobile_REST_Controller::REST_NAMESPACE . '/dashboard' ), true ) ) {
			if ( is_array( $data ) && isset( $data['user'] ) && is_array( $data['user'] ) ) {
				$data['user'] = self::augment_mobile_user_card( $data['user'], $changed );
			}
			return $data;
		}

		// Messages: author + recipients lists.
		if ( 0 === strpos( $route, '/' . ATORA_Mobile_REST_Controller::REST_NAMESPACE . '/messages' ) ) {
			return self::augment_people_by_keys( $data, $changed, array( 'author', 'recipients' ) );
		}

		// Teacher endpoints: only known person keys, never generic {id,name}.
		if ( false !== strpos( $route, '/' . ATORA_Mobile_REST_Controller::REST_NAMESPACE . '/teacher/' ) ) {
			return self::augment_people_by_keys( $data, $changed, array( 'student', 'students', 'members', 'submitted_by', 'recipients', 'author' ) );
		}

		return $data;
	}

	private static function augment_people_by_keys( $data, bool &$changed, array $keys ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = $data[ $key ];
			if ( is_array( $value ) ) {
				// Single person card: {id, name}.
				if ( isset( $value['id'], $value['name'] ) ) {
					$data[ $key ] = self::augment_mobile_person_card( $value, $changed );
					continue;
				}
				// List of person cards.
				$list_changed = false;
				foreach ( $value as $i => $row ) {
					if ( is_array( $row ) && isset( $row['id'] ) && ( isset( $row['name'] ) || isset( $row['display_name'] ) ) ) {
						$value[ $i ] = self::augment_mobile_person_card( $row, $list_changed );
					}
				}
				if ( $list_changed ) {
					$data[ $key ] = $value;
					$changed = true;
				}
			}
		}
		// Nested: only follow a few known containers to avoid scanning entire response.
		foreach ( array( 'items', 'threads', 'messages' ) as $container ) {
			if ( isset( $data[ $container ] ) && is_array( $data[ $container ] ) ) {
				$local_changed = false;
				foreach ( $data[ $container ] as $i => $row ) {
					$data[ $container ][ $i ] = self::augment_people_by_keys( $row, $local_changed, $keys );
				}
				if ( $local_changed ) {
					$changed = true;
				}
			}
		}
		return $data;
	}

	private static function augment_mobile_user_card( array $user, bool &$changed ): array {
		$user_id = absint( $user['id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return $user;
		}
		$src = self::initials_and_color_for_user( $user_id );
		$user['avatar_initials'] = (string) $src['initials'];
		$user['avatar_color'] = (string) $src['color'];
		$avatar = self::mobile_avatar_url_or_empty( $user_id, 192 );
		$user['avatar_url'] = $avatar;
		$changed = true;
		return $user;
	}

	private static function augment_mobile_person_card( array $person, bool &$changed ): array {
		$user_id = absint( $person['id'] ?? 0 );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return $person;
		}
		$src = self::initials_and_color_for_user( $user_id );
		$person['avatar_initials'] = (string) $src['initials'];
		$person['avatar_color'] = (string) $src['color'];
		$person['avatar_url'] = self::mobile_avatar_url_or_empty( $user_id, 192 );
		$changed = true;
		return $person;
	}

	public static function render_admin_user_avatar_fields( WP_User $user ): void {
		$current = get_current_user_id();
		if ( ! self::can_admin_manage_user_avatar( $current, (int) $user->ID ) ) {
			return;
		}
		$has = self::avatar_attachment_id( (int) $user->ID );
		$img = (string) get_avatar_url( (int) $user->ID, array( 'size' => 96 ) );
		wp_nonce_field( 'atora_admin_avatar_' . (int) $user->ID, 'atora_admin_avatar_nonce' );
		echo '<h2>' . esc_html__( 'Foto de perfil', 'atora-lms' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Avatar', 'atora-lms' ) . '</th><td>';
		echo '<img src="' . esc_url( $img ) . '" alt="" width="72" height="72" style="border-radius:50%;object-fit:cover">';
		echo '<p><input type="file" name="atora_admin_avatar_file" accept="image/jpeg,image/png,image/webp"></p>';
		if ( $has ) {
			echo '<p><label><input type="checkbox" name="atora_admin_avatar_remove" value="1"> ' . esc_html__( 'Quitar foto', 'atora-lms' ) . '</label></p>';
		}
		echo '<p class="description">' . esc_html__( 'JPG/PNG/WebP, máximo 5 MB. Se recorta a cuadrado y se guarda en la biblioteca.', 'atora-lms' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
	}

	public static function save_admin_user_avatar_fields( int $user_id ): void {
		$current = get_current_user_id();
		if ( ! self::can_admin_manage_user_avatar( $current, $user_id ) ) {
			return;
		}
		$nonce = sanitize_text_field( (string) wp_unslash( $_POST['atora_admin_avatar_nonce'] ?? '' ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'atora_admin_avatar_' . $user_id ) ) {
			return;
		}
		if ( ! empty( $_POST['atora_admin_avatar_remove'] ) ) {
			self::delete_user_avatar( $user_id );
			return;
		}
		if ( ! empty( $_FILES['atora_admin_avatar_file']['tmp_name'] ) ) {
			self::set_user_avatar_from_upload( $user_id, (array) $_FILES['atora_admin_avatar_file'] );
		}
	}
}

