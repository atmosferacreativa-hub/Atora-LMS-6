<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Commerce_Notifications {

	/**
	 * Envía notificación interna + email por acceso al curso.
	 *
	 * @param int           $user_id   Usuario.
	 * @param int           $course_id Curso.
	 * @param WC_Order|null $order     Orden.
	 * @param array         $args      Args opcionales.
	 * @return void
	 */
	public function send_course_access_notification( $user_id, $course_id, $order = null, $args = array() ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );
		$args      = is_array( $args ) ? $args : array();

		if ( ! $user_id || ! $course_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );

		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$is_new_access = isset( $args['is_new_access'] ) ? (bool) $args['is_new_access'] : true;
		$new_account   = ! empty( $args['new_account'] );

		$course_title = get_the_title( $course_id );
		$course_url   = get_permalink( $course_id );
		$dashboard    = $this->get_student_dashboard_url();
		$order_id     = ( $order && is_a( $order, 'WC_Order' ) ) ? absint( $order->get_id() ) : 0;

		$set_password_url = $new_account ? $this->get_set_password_url( $user, $course_url ) : '';

		$subject = $is_new_access
			? __( 'Tu acceso al curso ya está activo', 'atora-lms' )
			: __( 'Tu curso sigue disponible en tu cuenta', 'atora-lms' );

		$notification_title = $is_new_access
			? __( 'Acceso al curso activado', 'atora-lms' )
			: __( 'Curso disponible en tu cuenta', 'atora-lms' );

		$notification_message = $is_new_access
			? sprintf(
				/* translators: %s: course title */
				__( 'Tu acceso al curso "%s" ya está activo.', 'atora-lms' ),
				$course_title
			)
			: sprintf(
				/* translators: %s: course title */
				__( 'El curso "%s" ya está disponible en tu cuenta.', 'atora-lms' ),
				$course_title
			);

		$this->add_internal_notification(
			$user_id,
			array(
				'type'      => 'course_access',
				'title'     => $notification_title,
				'message'   => $notification_message,
				'link'      => $course_url,
				'course_id' => $course_id,
			)
		);

		$email_body = $this->build_email_body(
			$user,
			$course_id,
			array(
				'course_title'      => $course_title,
				'course_url'        => $course_url,
				'dashboard_url'     => $dashboard,
				'order_id'          => $order_id,
				'is_new_access'     => $is_new_access,
				'set_password_url'  => $set_password_url,
			)
		);

		$button_url  = $set_password_url ? $set_password_url : $course_url;
		$button_text = $set_password_url
			? __( 'Configura tu contraseña y entra al curso', 'atora-lms' )
			: __( 'Ir al curso', 'atora-lms' );

		$this->send_branded_email(
			$user->user_email,
			$subject,
			$email_body,
			$order,
			array(
				'headline'    => $notification_title,
				'button_text' => $button_text,
				'button_url'  => $button_url,
				'preheader'   => $notification_message,
			)
		);

		do_action( 'clms_commerce_course_access_notified', $user_id, $course_id, $order_id, $is_new_access );
	}

	// ── Email centralizado ────────────────────────────────────────────────────

	/**
	 * Envía email de branding institucional usando la pasarela central de ATORA.
	 *
	 * @param string        $to      Destinatario.
	 * @param string        $subject Asunto.
	 * @param string        $message Cuerpo HTML.
	 * @param WC_Order|null $order   Orden (para notas en caso de fallo).
	 * @param array         $args    Args de CLMS_Email::send() (headline, button_text, etc.).
	 * @return bool
	 */
	protected function send_branded_email( $to, $subject, $message, $order = null, array $args = array() ) {
		$to      = sanitize_email( (string) $to );
		$subject = sanitize_text_field( (string) $subject );

		if ( ! $to || ! is_email( $to ) ) {
			return false;
		}

		$sent = false;

		if ( class_exists( 'ATORA_Email_Gateway' ) && method_exists( 'ATORA_Email_Gateway', 'send' ) ) {
			$sent = (bool) ATORA_Email_Gateway::send( $to, $subject, $message, $args );
		} elseif ( class_exists( 'CLMS_Email' ) && method_exists( 'CLMS_Email', 'send' ) ) {
			$sent = (bool) CLMS_Email::send( $to, $subject, $message, $args );
		}

		if ( ! $sent && $order && is_a( $order, 'WC_Order' ) && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					'CLMS: no se pudo enviar el email de acceso al curso a %s.',
					esc_html( $to )
				)
			);
		}

		return (bool) $sent;
	}

	// ── Notificación interna ──────────────────────────────────────────────────

	/**
	 * Crea una notificación interna usando CLMS_Notifications si existe.
	 *
	 * @param int   $user_id Usuario.
	 * @param array $data    Data.
	 * @return void
	 */
	protected function add_internal_notification( $user_id, $data ) {
		$user_id = absint( $user_id );
		$data    = is_array( $data ) ? $data : array();

		if ( ! $user_id ) {
			return;
		}

		$notifications_module = null;

		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'module' ) ) {
			$notifications_module = clms_core('CLMS_Notifications');
		}

		if ( $notifications_module && method_exists( $notifications_module, 'add_notification' ) ) {
			$notifications_module->add_notification( $user_id, $data );
			$this->cleanup_user_notifications_meta( $user_id );
			return;
		}

		// Fallback mínimo si no está disponible CLMS_Notifications.
		$items = get_user_meta( $user_id, 'clms_notifications', true );
		$items = is_array( $items ) ? $items : array();

		$items[] = array(
			'id'            => wp_generate_uuid4(),
			'type'          => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'general',
			'title'         => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'Notificación', 'atora-lms' ),
			'message'       => isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : '',
			'link'          => isset( $data['link'] ) ? esc_url_raw( $data['link'] ) : '',
			'course_id'     => isset( $data['course_id'] ) ? absint( $data['course_id'] ) : 0,
			'lesson_id'     => 0,
			'submission_id' => 0,
			'status'        => 'info',
			'grade'         => '',
			'feedback'      => '',
			'is_read'       => 0,
			'created_at'    => current_time( 'mysql' ),
		);

		$items = $this->normalize_notifications_items( $items );

		update_user_meta( $user_id, 'clms_notifications', $items );
	}

	/**
	 * Limpia notificaciones legacy de user_meta sin depender del módulo de notificaciones.
	 *
	 * @param int $user_id Usuario.
	 * @return void
	 */
	protected function cleanup_user_notifications_meta( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return;
		}

		$items = get_user_meta( $user_id, 'clms_notifications', true );
		$items = is_array( $items ) ? $items : array();
		$items = $this->normalize_notifications_items( $items );

		update_user_meta( $user_id, 'clms_notifications', $items );
	}

	/**
	 * Normaliza colección legacy de notificaciones:
	 * - elimina entradas con más de 90 días;
	 * - mantiene entradas sin fecha por compatibilidad;
	 * - ordena por fecha descendente;
	 * - limita a 50.
	 *
	 * @param array $items Colección cruda.
	 * @return array
	 */
	protected function normalize_notifications_items( $items ) {
		$items = is_array( $items ) ? $items : array();

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$items  = array_filter(
			$items,
			static function ( $item ) use ( $cutoff ) {
				if ( ! is_array( $item ) ) {
					return false;
				}
				if ( empty( $item['created_at'] ) ) {
					return true;
				}
				return $item['created_at'] >= $cutoff;
			}
		);

		usort(
			$items,
			static function ( $a, $b ) {
				$date_a = isset( $a['created_at'] ) ? (string) $a['created_at'] : '';
				$date_b = isset( $b['created_at'] ) ? (string) $b['created_at'] : '';
				return strcmp( $date_b, $date_a );
			}
		);

		if ( count( $items ) > 50 ) {
			$items = array_slice( $items, 0, 50 );
		}

		return array_values( $items );
	}

	// ── Construcción de mensaje ───────────────────────────────────────────────

	/**
	 * Construye el cuerpo HTML del email de acceso.
	 * La salida se pasa a la pasarela central de email.
	 *
	 * @param WP_User $user      Usuario.
	 * @param int     $course_id Curso.
	 * @param array   $args      Args.
	 * @return string HTML del cuerpo.
	 */
	protected function build_email_body( $user, $course_id, $args = array() ) {
		$course_id        = absint( $course_id );
		$course_title     = isset( $args['course_title'] ) ? sanitize_text_field( (string) $args['course_title'] ) : get_the_title( $course_id );
		$course_url       = isset( $args['course_url'] ) ? esc_url_raw( (string) $args['course_url'] ) : get_permalink( $course_id );
		$dashboard_url    = isset( $args['dashboard_url'] ) ? esc_url_raw( (string) $args['dashboard_url'] ) : home_url( '/' );
		$order_id         = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
		$is_new_access    = isset( $args['is_new_access'] ) ? (bool) $args['is_new_access'] : true;
		$set_password_url = isset( $args['set_password_url'] ) ? esc_url_raw( (string) $args['set_password_url'] ) : '';
		$display_name     = $user->display_name ? $user->display_name : $user->user_login;

		$body = '<p>' . sprintf(
			/* translators: %s: display name */
			esc_html__( 'Hola %s,', 'atora-lms' ),
			esc_html( $display_name )
		) . '</p>';

		if ( $is_new_access ) {
			$body .= '<p>' . esc_html__( 'Tu compra fue procesada correctamente y tu acceso al curso ya está activo.', 'atora-lms' ) . '</p>';
		} else {
			$body .= '<p>' . esc_html__( 'Tu curso sigue disponible en tu cuenta y puedes entrar cuando quieras.', 'atora-lms' ) . '</p>';
		}

		$body .= '<p><strong>' . esc_html__( 'Curso:', 'atora-lms' ) . '</strong> ' . esc_html( $course_title ) . '</p>';

		if ( $order_id ) {
			$body .= '<p><strong>' . esc_html__( 'Pedido:', 'atora-lms' ) . '</strong> #' . absint( $order_id ) . '</p>';
		}

		if ( $set_password_url ) {
			$body .= '<p>' . esc_html__( 'Creamos una cuenta para ti con este correo. Antes de entrar, configura tu contraseña:', 'atora-lms' ) . '</p>';
			$body .= '<p><a href="' . esc_url( $set_password_url ) . '">' . esc_html__( 'Configurar mi contraseña y entrar al curso', 'atora-lms' ) . '</a></p>';
		}

		if ( $dashboard_url ) {
			$body .= '<p><a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Ir a tu panel de estudiante', 'atora-lms' ) . '</a></p>';
		}

		$body .= '<p><a href="' . esc_url( $course_url ) . '">' . esc_html__( 'Entrar directo al curso', 'atora-lms' ) . '</a></p>';
		$body .= '<p>' . esc_html__( 'Si tienes dudas con tu acceso, responde este correo y te ayudamos.', 'atora-lms' ) . '</p>';

		return $body;
	}

	/**
	 * Genera el enlace propio de ATORA para definir contraseña, con
	 * redirección al curso tras completarlo. Usa la clave de restablecimiento
	 * estándar de WordPress, pero la procesa en una página propia (ver
	 * Extended_Registration::maybe_handle_set_password()) para no depender de
	 * wp-login.php, que en algunos hostings queda detrás de cachés o reglas de
	 * seguridad que pueden romper el flujo.
	 *
	 * @param WP_User $user        Usuario.
	 * @param string  $redirect_to URL a la que volver después de definir la contraseña.
	 * @return string URL, o cadena vacía si no se pudo generar.
	 */
	protected function get_set_password_url( $user, $redirect_to = '' ) {
		if ( ! $user instanceof WP_User ) {
			return '';
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return '';
		}

		$args = array(
			'clms_set_password' => 1,
			'login'             => $user->user_login,
			'key'               => $key,
		);

		if ( $redirect_to ) {
			$args['redirect_to'] = $redirect_to;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * @deprecated Usar build_email_body() para HTML. Conservado por compatibilidad.
	 *
	 * @param WP_User $user      Usuario.
	 * @param int     $course_id Curso.
	 * @param array   $args      Args.
	 * @return string
	 */
	protected function build_email_message( $user, $course_id, $args = array() ) {
		return $this->build_email_body( $user, $course_id, $args );
	}

	// ── Dashboard ─────────────────────────────────────────────────────────────

	/**
	 * URL del dashboard del estudiante.
	 *
	 * @return string
	 */
	protected function get_student_dashboard_url() {
		$dashboard_page_id = absint( get_option( 'clms_student_dashboard_page_id' ) );

		if ( $dashboard_page_id ) {
			$url = get_permalink( $dashboard_page_id );
			if ( $url ) {
				return $url;
			}
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				's'              => 'dashboard',
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $pages ) ) {
			$url = get_permalink( absint( $pages[0] ) );
			if ( $url ) {
				return $url;
			}
		}

		return home_url( '/' );
	}
}
