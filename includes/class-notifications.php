<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Notifications {

	const META_KEY = 'clms_notifications';
	const MAX_ITEMS = 50;

	public function __construct() {
		add_action( 'clms_submission_created', array( $this, 'notify_teacher_new_submission' ), 10, 3 );
		add_action( 'clms_submission_graded', array( $this, 'notify_student_graded_submission' ), 10, 5 );

		add_action( 'transition_post_status', array( $this, 'notify_students_when_lesson_published' ), 10, 3 );
		add_action( 'init', array( $this, 'handle_mark_notification_read' ) );
	}

	/* ---------------------------------------------------------------
	   EVENTOS
	--------------------------------------------------------------- */

	/**
	 * Notifica a admin/editores cuando entra una nueva entrega.
	 *
	 * Firma:
	 * (submission_id, lesson_id, student_id)
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $lesson_id Lesson ID.
	 * @param int $student_id Student ID.
	 * @return void
	 */
	public function notify_teacher_new_submission( $submission_id = 0, $lesson_id = 0, $student_id = 0 ) {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );
		$student_id    = absint( $student_id );

		if ( $submission_id && '1' === (string) get_post_meta( $submission_id, '_clms_submission_is_shadow', true ) ) {
			return;
		}

		if ( ! $lesson_id && $submission_id ) {
			$lesson_id = absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) );
		}

		if ( ! $student_id && $submission_id ) {
			$student_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		}

		if ( ! $lesson_id || ! $student_id ) {
			return;
		}

		$lesson_title = get_the_title( $lesson_id );
		$student      = get_user_by( 'id', $student_id );
		$student_name = $student ? ( $student->display_name ? $student->display_name : $student->user_login ) : 'Estudiante';

		$course_id    = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
		$lesson_link  = get_permalink( $lesson_id );
		$grading       = clms_core('CLMS_Grading');
		$speedgrade_url = ( $grading && method_exists( $grading, 'get_speedgrade_url' ) ) ? $grading->get_speedgrade_url( $submission_id ) : '';
		$admin_link    = $speedgrade_url ? $speedgrade_url : ( $submission_id ? get_edit_post_link( $submission_id, '' ) : admin_url( 'edit.php?post_type=clms_submission' ) );

		$subject = 'Nueva entrega recibida';

		$email_body  = '<p>Se recibió una nueva entrega.</p>';
		$email_body .= '<p><strong>Lección:</strong> ' . esc_html( $lesson_title ) . '<br>';
		$email_body .= '<strong>Estudiante:</strong> ' . esc_html( $student_name );
		if ( $course_id ) {
			$email_body .= '<br><strong>Curso:</strong> ' . esc_html( get_the_title( $course_id ) );
		}
		$email_body .= '</p>';

		$email_args = array(
			'headline'    => 'Nueva entrega recibida',
			'button_text' => $admin_link ? 'Revisar entrega' : '',
			'button_url'  => $admin_link ? $admin_link : '',
		);

		$admins = get_users(
			array(
				'role__in' => array( 'administrator', 'editor' ),
				'fields'   => array( 'ID', 'user_email', 'display_name' ),
			)
		);

		$recipient_ids = array();

		foreach ( (array) $admins as $admin ) {
			if ( ! empty( $admin->ID ) ) {
				$recipient_ids[ absint( $admin->ID ) ] = true;
			}
		}

		$course_author = $course_id ? absint( get_post_field( 'post_author', $course_id ) ) : 0;
		$lesson_author = $lesson_id ? absint( get_post_field( 'post_author', $lesson_id ) ) : 0;

		foreach ( array( $course_author, $lesson_author ) as $author_id ) {
			if ( $author_id && $this->should_notify_teacher_user( $author_id ) ) {
				$recipient_ids[ $author_id ] = true;
			}
		}

		if ( empty( $recipient_ids ) ) {
			return;
		}

		foreach ( array_keys( $recipient_ids ) as $recipient_id ) {
			$recipient = get_user_by( 'id', $recipient_id );
			if ( ! $recipient ) {
				continue;
			}

			if ( ! empty( $recipient->user_email ) ) {
				CLMS_Email::send( $recipient->user_email, $subject, $email_body, $email_args );
			}

			$this->add_notification(
				$recipient_id,
				array(
					'type'          => 'submission_created',
					'title'         => 'Nueva entrega recibida',
					'message'       => sprintf( 'Se recibió una nueva entrega de %1$s en "%2$s".', $student_name, $lesson_title ),
					'link'          => $admin_link ? $admin_link : $lesson_link,
					'course_id'     => $course_id,
					'lesson_id'     => $lesson_id,
					'submission_id' => $submission_id,
				)
			);
		}
	}

	/**
	 * Notifica al estudiante cuando califican una entrega.
	 *
	 * Firma:
	 * (submission_id, student_id, status, grade, feedback)
	 *
	 * @param int    $submission_id Submission ID.
	 * @param int    $student_id Student ID.
	 * @param string $status Status.
	 * @param mixed  $grade Grade.
	 * @param string $feedback Feedback.
	 * @return void
	 */
	public function notify_student_graded_submission( $submission_id = 0, $student_id = 0, $status = '', $grade = '', $feedback = '' ) {
		$submission_id = absint( $submission_id );
		$student_id    = absint( $student_id );
		$status        = sanitize_key( (string) $status );
		$feedback      = (string) $feedback;

		$lesson_id = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_lesson_id', true ) ) : 0;
		$user_id   = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) ) : 0;
		if ( ! $user_id ) {
			$user_id = $submission_id ? absint( get_post_meta( $submission_id, '_clms_submission_student_id', true ) ) : 0;
		}
		if ( ! $user_id ) {
			$user_id = $student_id;
		}

		if ( ! $lesson_id || ! $user_id ) {
			return;
		}

		$lesson_title = get_the_title( $lesson_id );
		$lesson_link  = get_permalink( $lesson_id );
		$course_id    = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
		if ( '' === trim( $feedback ) && $submission_id ) {
			$feedback = (string) get_post_meta( $submission_id, '_clms_submission_feedback', true );
		}

		$status_label = $this->get_submission_status_label( $status );

		$title   = 'Tu entrega fue actualizada';
		$message = sprintf(
			'Tu entrega en "%1$s" fue actualizada. Estado: %2$s.',
			$lesson_title,
			$status_label
		);

		if ( '' !== (string) $grade ) {
			$message .= ' Nota: ' . absint( $grade ) . '/100.';
		}

		$this->add_notification(
			$user_id,
			array(
				'type'          => 'submission_graded',
				'title'         => $title,
				'message'       => $message,
				'link'          => $lesson_link,
				'course_id'     => $course_id,
				'lesson_id'     => $lesson_id,
				'submission_id' => $submission_id,
				'status'        => $status,
				'grade'         => '' !== (string) $grade ? absint( $grade ) : '',
				'feedback'      => $feedback,
			)
		);

		$user = get_user_by( 'id', $user_id );

		if ( $user && ! empty( $user->user_email ) ) {
			$email_subject = 'Tu entrega fue revisada';

			$email_body = '<p>' . esc_html( $message ) . '</p>';
			if ( $feedback ) {
				$email_body .= '<div style="border-left:3px solid #e5e7eb;padding:0 0 0 1em;margin-top:1em;">';
				$email_body .= '<p><strong>Retroalimentación:</strong></p>';
				$email_body .= '<p>' . wp_kses_post( $feedback ) . '</p>';
				$email_body .= '</div>';
			}

			CLMS_Email::send(
				$user->user_email,
				$email_subject,
				$email_body,
				array(
					'headline'    => 'Tu entrega fue revisada',
					'button_text' => 'Ver lección',
					'button_url'  => $lesson_link,
				)
			);
		}
	}

	/**
	 * Notifica a estudiantes inscritos cuando una lección pasa a published.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function notify_students_when_lesson_published( $new_status, $old_status, $post ) {
		if ( ! $post || 'lm_lesson' !== $post->post_type ) {
			return;
		}

		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		$lesson_id  = absint( $post->ID );
		$course_id  = CLMS_Helper::get_course_id_from_lesson( $lesson_id );
		$lesson_url = get_permalink( $lesson_id );

		if ( ! $course_id ) {
			return;
		}

		$student_ids = CLMS_Helper::get_enrolled_student_ids( $course_id );

		if ( empty( $student_ids ) ) {
			return;
		}

		$lesson_title = get_the_title( $lesson_id );
		$course_title = get_the_title( $course_id );

		foreach ( $student_ids as $student_id ) {
			$student_id = absint( $student_id );

			if ( ! $student_id ) {
				continue;
			}

			$this->add_notification(
				$student_id,
				array(
					'type'      => 'lesson_published',
					'title'     => 'Nueva lección disponible',
					'message'   => sprintf( 'Ya está disponible la lección "%1$s" en el curso "%2$s".', $lesson_title, $course_title ),
					'link'      => $lesson_url,
					'course_id' => $course_id,
					'lesson_id' => $lesson_id,
				)
			);
		}
	}

	/* ---------------------------------------------------------------
	   GESTIÓN DE NOTIFICACIONES
	--------------------------------------------------------------- */

	/**
	 * Agrega una notificación al usuario.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data Data.
	 * @return bool
	 */
	public function add_notification( $user_id, $data ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			return false;
		}

		$notifications = get_user_meta( $user_id, self::META_KEY, true );
		$notifications = is_array( $notifications ) ? $notifications : array();

		$item = array(
			'id'            => wp_generate_uuid4(),
			'type'          => isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'general',
			'title'         => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : 'Notificación',
			'message'       => isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : '',
			'link'          => isset( $data['link'] ) ? esc_url_raw( $data['link'] ) : '',
			'course_id'     => isset( $data['course_id'] ) ? absint( $data['course_id'] ) : 0,
			'lesson_id'     => isset( $data['lesson_id'] ) ? absint( $data['lesson_id'] ) : 0,
			'submission_id' => isset( $data['submission_id'] ) ? absint( $data['submission_id'] ) : 0,
			'status'        => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : '',
			'grade'         => isset( $data['grade'] ) && '' !== (string) $data['grade'] ? absint( $data['grade'] ) : '',
			'feedback'      => isset( $data['feedback'] ) ? sanitize_textarea_field( $data['feedback'] ) : '',
			'is_read'       => 0,
			'created_at'    => current_time( 'mysql' ),
		);

		array_unshift( $notifications, $item );

		if ( count( $notifications ) > self::MAX_ITEMS ) {
			$notifications = array_slice( $notifications, 0, self::MAX_ITEMS );
		}

		update_user_meta( $user_id, self::META_KEY, $notifications );

		return true;
	}

	/**
	 * Obtiene notificaciones del usuario.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $unread_only Solo no leídas.
	 * @return array
	 */
	public function get_notifications( $user_id, $unread_only = false ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return array();
		}

		$notifications = get_user_meta( $user_id, self::META_KEY, true );
		$notifications = is_array( $notifications ) ? $notifications : array();

		$clean = array();

		foreach ( $notifications as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			$item = wp_parse_args(
				$item,
				array(
					'id'            => '',
					'type'          => 'general',
					'title'         => '',
					'message'       => '',
					'link'          => '',
					'course_id'     => 0,
					'lesson_id'     => 0,
					'submission_id' => 0,
					'status'        => '',
					'grade'         => '',
					'feedback'      => '',
					'is_read'       => 0,
					'created_at'    => '',
				)
			);

			$item['id']            = sanitize_text_field( $item['id'] );
			$item['type']          = sanitize_key( $item['type'] );
			$item['title']         = sanitize_text_field( $item['title'] );
			$item['message']       = sanitize_textarea_field( $item['message'] );
			$item['link']          = esc_url_raw( $item['link'] );
			$item['course_id']     = absint( $item['course_id'] );
			$item['lesson_id']     = absint( $item['lesson_id'] );
			$item['submission_id'] = absint( $item['submission_id'] );
			$item['status']        = sanitize_key( $item['status'] );
			$item['grade']         = '' !== (string) $item['grade'] ? absint( $item['grade'] ) : '';
			$item['feedback']      = sanitize_textarea_field( $item['feedback'] );
			$item['is_read']       = ! empty( $item['is_read'] ) ? 1 : 0;
			$item['created_at']    = sanitize_text_field( $item['created_at'] );

			if ( $unread_only && ! empty( $item['is_read'] ) ) {
				continue;
			}

			$clean[] = $item;
		}

		return $clean;
	}

	/**
	 * Marca una notificación como leída.
	 *
	 * @param int    $user_id User ID.
	 * @param string $notification_id Notification ID.
	 * @return bool
	 */
	public function mark_notification_read( $user_id, $notification_id ) {
		$user_id         = absint( $user_id );
		$notification_id = sanitize_text_field( $notification_id );

		if ( ! $user_id || ! $notification_id ) {
			return false;
		}

		$notifications = get_user_meta( $user_id, self::META_KEY, true );
		$notifications = is_array( $notifications ) ? $notifications : array();

		$updated = false;

		foreach ( $notifications as $index => $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}

			if ( $notification_id === (string) $item['id'] ) {
				$notifications[ $index ]['is_read'] = 1;
				$updated = true;
				break;
			}
		}

		if ( $updated ) {
			update_user_meta( $user_id, self::META_KEY, $notifications );
		}

		return $updated;
	}

	/**
	 * Marca todas como leídas.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function mark_all_read( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$notifications = get_user_meta( $user_id, self::META_KEY, true );
		$notifications = is_array( $notifications ) ? $notifications : array();

		if ( empty( $notifications ) ) {
			return true;
		}

		foreach ( $notifications as $index => $item ) {
			if ( is_array( $item ) ) {
				$notifications[ $index ]['is_read'] = 1;
			}
		}

		update_user_meta( $user_id, self::META_KEY, $notifications );

		return true;
	}

	/**
	 * Cuenta no leídas.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_unread_count( $user_id ) {
		$items = $this->get_notifications( $user_id, true );
		return count( $items );
	}

	/* ---------------------------------------------------------------
	   ACCIONES FRONT
	--------------------------------------------------------------- */

	/**
	 * Maneja marcar como leída vía GET.
	 *
	 * @return void
	 */
	public function handle_mark_notification_read() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( empty( $_GET['clms_mark_notification'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}

		$notification_id = sanitize_text_field( wp_unslash( $_GET['clms_mark_notification'] ) );
		$nonce           = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		if ( ! wp_verify_nonce( $nonce, 'clms_mark_notification_' . $notification_id ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$this->mark_notification_read( $user_id, $notification_id );

		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );

		wp_safe_redirect( $redirect );
		exit;
	}

	/* ---------------------------------------------------------------
	   HELPERS
	--------------------------------------------------------------- */

	/**
	 * Etiqueta humana del estado de entrega.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	protected function get_submission_status_label( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return 'Calificada';
			case 'needs_revision':
				return 'Requiere ajustes';
			case 'returned':
				return 'Devuelta para ajustes';
			case 'in_review':
				return 'En revisión';
			case 'submitted':
				return 'Enviada';
			default:
				return 'Actualizada';
		}
	}

	protected function should_notify_teacher_user( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		return user_can( $user_id, 'manage_options' )
			|| user_can( $user_id, 'clms_view_teacher_dashboard' )
			|| user_can( $user_id, 'clms_grade_submissions' )
			|| user_can( $user_id, 'clms_manage_courses' );
	}
}
