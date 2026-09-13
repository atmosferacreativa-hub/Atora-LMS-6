<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Submission_Core_Trait {
	public function register_post_type() {
		register_post_type(
			self::CPT,
			array(
				'labels' => array(
					'name'          => 'Entregas',
					'singular_name' => 'Entrega',
					'menu_name'     => '🟦 Entregas',
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'clms-dashboard',
				'show_in_rest'       => false,
				'supports'           => array( 'title', 'author' ),
				'capability_type'    => array( 'clms_submission', 'clms_submissions' ),
				'map_meta_cap'       => true,
				'capabilities'       => array(
					'edit_post'              => 'edit_clms_submission',
					'read_post'              => 'read_clms_submission',
					'delete_post'            => 'delete_clms_submission',
					'edit_posts'             => 'edit_clms_submissions',
					'edit_others_posts'      => 'edit_others_clms_submissions',
					'publish_posts'          => 'publish_clms_submissions',
					'read_private_posts'     => 'read_private_clms_submissions',
					'delete_posts'           => 'delete_clms_submissions',
					'delete_private_posts'   => 'delete_private_clms_submissions',
					'delete_published_posts' => 'delete_published_clms_submissions',
					'delete_others_posts'    => 'delete_others_clms_submissions',
					'edit_private_posts'     => 'edit_private_clms_submissions',
					'edit_published_posts'   => 'edit_published_clms_submissions',
					'create_posts'           => 'create_clms_submissions',
				),
				'has_archive'        => false,
				'publicly_queryable' => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-portfolio',
			)
		);
	}

	/**
	 * Shortcode del formulario de entrega.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public function render_submission_form_shortcode( $atts = array() ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="clms-message clms-message-error">Debes iniciar sesión para enviar una tarea.</div>';
		}

		$atts = shortcode_atts(
			array(
				'lesson_id' => 0,
			),
			(array) $atts,
			'clms_submission_form'
		);

		$user_id   = get_current_user_id();
		$lesson_id = absint( $atts['lesson_id'] );

		if ( ! $lesson_id && is_singular( 'lm_lesson' ) ) {
			$lesson_id = get_the_ID();
		}

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return '<div class="clms-message clms-message-error">No se pudo detectar una lección válida para esta entrega.</div>';
		}

		if ( ! $this->user_can_submit_to_lesson( $user_id, $lesson_id ) ) {
			return '<div class="clms-message clms-message-error">No tienes acceso para enviar esta tarea.</div>';
		}

		$submission = $this->get_user_submission_for_grading( $user_id, $lesson_id );
		$evaluation_mode = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_evaluation_mode', true ) );
		$group_context   = array();

		$display_files = ( ! empty( $submission['files'] ) && is_array( $submission['files'] ) )
			? array_values( array_filter( array_map( 'absint', $submission['files'] ) ) )
			: array();

		$comment_prefill = isset( $submission['comment'] ) ? (string) $submission['comment'] : '';

		if ( 'group' === $evaluation_mode ) {
			$shadow_id = ! empty( $submission['submission_id'] ) ? absint( $submission['submission_id'] ) : 0;
			$master_id = 0;

			if ( $shadow_id ) {
				$master_id = absint( get_post_meta( $shadow_id, '_clms_submission_group_master_id', true ) );
				if ( ! $master_id && '1' === (string) get_post_meta( $shadow_id, '_clms_submission_group_master', true ) ) {
					$master_id = $shadow_id;
				}
			}

			if ( $master_id ) {
				$group_context = array(
					'master_id'    => $master_id,
					'group_id'     => absint( get_post_meta( $master_id, '_clms_submission_group_id', true ) ),
					'submitted_by' => absint( get_post_meta( $master_id, '_clms_submission_submitted_by', true ) ),
					'submitted_at' => sanitize_text_field( (string) get_post_meta( $master_id, '_clms_submission_submitted_at', true ) ),
				);

				if ( empty( $display_files ) ) {
					$files = get_post_meta( $master_id, '_clms_submission_files', true );
					if ( ! is_array( $files ) || empty( $files ) ) {
						$files = get_post_meta( $master_id, '_clms_submission_attachments', true );
					}
					$display_files = is_array( $files ) ? array_values( array_filter( array_map( 'absint', $files ) ) ) : array();
				}

				if ( '' === trim( $comment_prefill ) ) {
					$comment_prefill = (string) get_post_meta( $master_id, '_clms_submission_comment', true );
				}
			}
		}

		$has_files = ! empty( $display_files );
		$student_status = isset( $submission['status'] ) ? sanitize_key( (string) $submission['status'] ) : '';
		$can_view_grade = $this->can_student_view_published_grade( $submission );
		$can_view_feedback = $this->can_student_view_feedback( $submission );
		$student_guidance = $this->build_submission_student_message( $lesson_id, $submission );

		$this->enqueue_assets();

		$delivery_mode = sanitize_key( (string) get_post_meta( $lesson_id, '_clms_delivery_mode', true ) );
		if ( ! in_array( $delivery_mode, array( 'read_only', 'quiz', 'file', 'text', 'both' ), true ) ) {
			$delivery_mode = 'both';
		}
		$show_comment_field = ( 'file' !== $delivery_mode );
		$show_file_field    = ( 'text' !== $delivery_mode );

		$task_title       = (string) get_post_meta( $lesson_id, 'lm_task_title', true );
		$task_description = (string) get_post_meta( $lesson_id, 'lm_task_description', true );

		ob_start();
		$allowed_exts = array_keys( $this->allowed_mimes );
		$accept       = '.' . implode( ',.', $allowed_exts );
		$max_files    = absint( $this->max_files );
		$max_size     = absint( $this->max_file_size );
		$max_size_txt = size_format( $max_size );
		$block_submit = false;
		?>
		<div class="clms-submission-box">
			<?php if ( '' !== trim( $task_title ) || '' !== trim( $task_description ) ) : ?>
				<div class="clms-submission-instructions">
					<?php if ( '' !== trim( $task_title ) ) : ?>
						<h3 class="clms-submission-instructions-title"><?php echo esc_html( $task_title ); ?></h3>
					<?php endif; ?>
					<?php if ( '' !== trim( $task_description ) ) : ?>
						<div class="clms-submission-instructions-body"><?php echo wp_kses_post( wpautop( $task_description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<?php echo $this->render_submission_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

			<?php if ( 'group' === $evaluation_mode ) : ?>
				<?php
				$course_id = absint( get_post_meta( $lesson_id, '_clms_lesson_course_id', true ) );
				if ( ! $course_id ) { $course_id = absint( get_post_meta( $lesson_id, '_clms_course_id', true ) ); }
				if ( ! $course_id ) { $course_id = absint( get_post_meta( $lesson_id, 'course_id', true ) ); }

				$groups_enabled = $course_id ? ( '1' === (string) get_post_meta( $course_id, '_clms_course_groups_enabled', true ) ) : false;
				$group_id       = $course_id ? absint( (int) apply_filters( 'atora/groups/user_group_id', 0, $user_id, $course_id, $lesson_id ) ) : 0;
				$block_submit   = ( ! $groups_enabled || ! $group_id );

				$group_name   = '';
				$member_names = array();

				if ( $groups_enabled && $group_id && class_exists( '\ATORA\Groups\Group_Service' ) ) {
					$service = new \ATORA\Groups\Group_Service();
					$g       = $service->get_group( $group_id );
					$group_name = is_array( $g ) ? (string) ( $g['name'] ?? '' ) : '';
					$member_ids = $service->get_group_member_ids( $group_id );
					foreach ( (array) $member_ids as $mid ) {
						$u = get_userdata( absint( $mid ) );
						if ( $u && ! empty( $u->display_name ) ) {
							$member_names[] = (string) $u->display_name;
						}
					}
				}
				?>
				<div class="clms-message">
					<strong><?php esc_html_e( 'Trabajo en grupo', 'atora-lms' ); ?></strong><br>
					<?php if ( ! $groups_enabled ) : ?>
						<span><?php esc_html_e( 'Este curso no tiene habilitada la evaluación por grupos. Contacta a tu docente.', 'atora-lms' ); ?></span>
					<?php elseif ( ! $group_id ) : ?>
						<span><?php esc_html_e( 'No tienes un grupo asignado para esta actividad. Contacta a tu docente.', 'atora-lms' ); ?></span>
					<?php else : ?>
						<span>
							<?php
							printf(
								/* translators: 1: group name, 2: group id */
								esc_html__( 'Tu grupo: %1$s (ID %2$d).', 'atora-lms' ),
								esc_html( $group_name ? $group_name : __( 'Sin nombre', 'atora-lms' ) ),
								(int) $group_id
							);
							?>
						</span>
						<?php if ( ! empty( $member_names ) ) : ?>
							<br><span class="description"><?php echo esc_html( implode( ' · ', $member_names ) ); ?></span>
						<?php endif; ?>

						<?php if ( ! empty( $group_context['master_id'] ) ) : ?>
							<?php
							$submitted_by_id = absint( $group_context['submitted_by'] ?? 0 );
							$submitted_by_u  = $submitted_by_id ? get_userdata( $submitted_by_id ) : null;
							$submitted_by_name = $submitted_by_u ? (string) ( $submitted_by_u->display_name ?? '' ) : '';
							$submitted_at = sanitize_text_field( (string) ( $group_context['submitted_at'] ?? '' ) );
							?>
							<?php if ( $submitted_by_name || $submitted_at ) : ?>
								<br><span class="description">
									<?php
									if ( $submitted_by_name && $submitted_at ) {
										printf(
											/* translators: 1: name, 2: datetime */
											esc_html__( 'Última entrega: %1$s (%2$s).', 'atora-lms' ),
											esc_html( $submitted_by_name ),
											esc_html( $submitted_at )
										);
									} elseif ( $submitted_at ) {
										printf(
											/* translators: 1: datetime */
											esc_html__( 'Última entrega: %s.', 'atora-lms' ),
											esc_html( $submitted_at )
										);
									}
									?>
								</span>
							<?php endif; ?>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

				<?php if ( ! empty( $submission['submission_id'] ) ) : ?>
					<div class="clms-message">
						<strong>Estado actual:</strong>
						<?php echo esc_html( $this->get_status_label( $student_status ) ); ?>

					<?php if ( $can_view_grade ) : ?>
						<br><strong>Nota:</strong> <?php echo esc_html( $this->normalize_grade_display( $submission['grade'] ) ); ?>/100
					<?php endif; ?>
					</div>
					<?php if ( ! empty( $student_guidance['text'] ) ) : ?>
						<div class="<?php echo esc_attr( ! empty( $student_guidance['class'] ) ? $student_guidance['class'] : 'clms-message' ); ?>">
							<?php echo esc_html( $student_guidance['text'] ); ?>
						</div>
					<?php endif; ?>
					<?php echo $this->render_submission_timeline( $submission ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<?php if ( $can_view_feedback && ! empty( $submission['feedback'] ) ) : ?>
					<div class="clms-message">
						<strong>Retroalimentación:</strong><br>
						<?php echo wp_kses_post( wpautop( $submission['feedback'] ) ); ?>
					</div>
				<?php endif; ?>
				<?php
				$rubric_feedback = $can_view_feedback ? $this->build_student_rubric_feedback( $lesson_id, $submission ) : array();
				?>
				<?php if ( ! empty( $rubric_feedback['rows'] ) ) : ?>
					<div class="clms-message">
						<strong><?php esc_html_e( 'Feedback por criterio', 'atora-lms' ); ?></strong>
						<table style="width:100%;border-collapse:collapse;margin-top:8px;font-size:13px">
							<thead>
								<tr>
									<th style="text-align:left;border-bottom:1px solid #d1d5db;padding:6px"><?php esc_html_e( 'Criterio', 'atora-lms' ); ?></th>
									<th style="text-align:left;border-bottom:1px solid #d1d5db;padding:6px"><?php esc_html_e( 'Competencia', 'atora-lms' ); ?></th>
									<th style="text-align:left;border-bottom:1px solid #d1d5db;padding:6px"><?php esc_html_e( 'Puntaje', 'atora-lms' ); ?></th>
									<th style="text-align:left;border-bottom:1px solid #d1d5db;padding:6px"><?php esc_html_e( 'Comentario', 'atora-lms' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rubric_feedback['rows'] as $row ) : ?>
									<tr>
										<td style="border-bottom:1px solid #e5e7eb;padding:6px"><?php echo esc_html( $row['name'] ); ?></td>
										<td style="border-bottom:1px solid #e5e7eb;padding:6px"><?php echo esc_html( $row['competency'] ); ?></td>
										<td style="border-bottom:1px solid #e5e7eb;padding:6px"><?php echo esc_html( $row['score'] ); ?>/<?php echo esc_html( $row['max'] ); ?></td>
										<td style="border-bottom:1px solid #e5e7eb;padding:6px"><?php echo esc_html( $row['feedback'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( ! empty( $rubric_feedback['strengths'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Criterios fuertes:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $rubric_feedback['strengths'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $rubric_feedback['reinforce'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Criterios a reforzar:', 'atora-lms' ); ?></strong> <?php echo esc_html( implode( ' · ', $rubric_feedback['reinforce'] ) ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $rubric_feedback['recommendation'] ) ) : ?>
							<p><strong><?php esc_html_e( 'Recomendación de mejora:', 'atora-lms' ); ?></strong> <?php echo esc_html( $rubric_feedback['recommendation'] ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $has_files ) : ?>
					<div class="clms-message">
						<strong>Archivos enviados:</strong>
						<ul>
							<?php foreach ( $display_files as $file_id ) : ?>
								<?php
								$file_id = absint( $file_id );

								if ( ! $this->user_can_view_attachment( $user_id, $file_id ) ) {
									continue;
								}

								$url   = wp_get_attachment_url( $file_id );
								$path  = get_attached_file( $file_id );
								$label = $path ? basename( (string) $path ) : 'Archivo ' . $file_id;

								if ( ! $url ) {
									continue;
								}
								?>
								<li>
									<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( $label ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( ! $block_submit ) : ?>
			<form method="post" enctype="multipart/form-data" class="clms-submission-form">
				<?php wp_nonce_field( 'clms_submit_assignment_' . $lesson_id, 'clms_submission_nonce' ); ?>
				<input type="hidden" name="clms_action" value="submit_assignment">
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( $lesson_id ); ?>">

				<?php if ( $show_comment_field ) : ?>
				<p>
					<label for="clms_submission_comment_<?php echo esc_attr( $lesson_id ); ?>"><strong>Comentario</strong></label><br>
					<textarea
						id="clms_submission_comment_<?php echo esc_attr( $lesson_id ); ?>"
						name="clms_submission_comment"
						rows="5"
						style="width:100%;"
					><?php echo '' !== trim( $comment_prefill ) ? esc_textarea( $comment_prefill ) : ''; ?></textarea>
				</p>
				<?php endif; ?>

				<?php if ( $show_file_field ) : ?>
				<div class="clms-submission-field">
					<label for="clms_submission_files_<?php echo esc_attr( $lesson_id ); ?>"><strong><?php esc_html_e( 'Archivos', 'atora-lms' ); ?></strong></label>
					<div
						class="clms-submission-upload"
						data-atora-submission-upload
						data-atora-max-files="<?php echo esc_attr( $max_files ); ?>"
						data-atora-max-size="<?php echo esc_attr( $max_size ); ?>"
						data-atora-allowed="<?php echo esc_attr( implode( ',', $allowed_exts ) ); ?>"
						data-atora-msg-too-many="<?php echo esc_attr( sprintf( __( 'Máximo %d archivos por entrega.', 'atora-lms' ), $max_files ) ); ?>"
						data-atora-msg-too-large="<?php echo esc_attr( sprintf( __( 'Archivo demasiado grande. Máximo %s.', 'atora-lms' ), $max_size_txt ) ); ?>"
						data-atora-msg-invalid-type="<?php echo esc_attr__( 'Tipo de archivo no permitido.', 'atora-lms' ); ?>"
						data-atora-msg-ready="<?php echo esc_attr__( 'Archivos listos para enviar (%d).', 'atora-lms' ); ?>"
						data-atora-msg-empty="<?php echo esc_attr__( 'No se seleccionaron archivos.', 'atora-lms' ); ?>"
					>
						<div class="clms-dropzone" data-atora-dropzone tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Arrastra tus archivos o toca para seleccionarlos', 'atora-lms' ); ?>">
							<span class="clms-dropzone-title"><?php esc_html_e( 'Arrastra tus archivos aquí', 'atora-lms' ); ?></span>
							<span class="clms-dropzone-sub"><?php esc_html_e( 'o toca para seleccionarlos', 'atora-lms' ); ?></span>
						</div>
						<div class="clms-submission-input-row">
							<input
								id="clms_submission_files_<?php echo esc_attr( $lesson_id ); ?>"
								type="file"
								name="clms_submission_files[]"
								multiple
								accept="<?php echo esc_attr( $accept ); ?>"
								data-atora-file-input
							>
						</div>
						<div class="clms-submission-file-list" data-atora-file-list></div>
						<div class="clms-submission-upload-status" data-atora-status aria-live="polite"></div>
						<small class="clms-submission-hint">
							<?php
							printf(
								/* translators: 1: max files, 2: allowed extensions, 3: max size */
								esc_html__( 'Puedes subir hasta %1$d archivos. Tipos permitidos: %2$s. Máximo %3$s por archivo.', 'atora-lms' ),
								$max_files,
								implode( ', ', $allowed_exts ),
								$max_size_txt
							);
							?>
						</small>
					</div>
				</div>
				<?php endif; ?>

				<p>
					<button type="submit"><?php esc_html_e( 'Enviar tarea', 'atora-lms' ); ?></button>
				</p>
			</form>
			<?php else : ?>
				<div class="clms-message clms-message-error">
					<?php esc_html_e( 'No puedes enviar esta tarea hasta tener un grupo asignado y que el curso tenga habilitada la evaluación por grupos.', 'atora-lms' ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Timeline simple del estado de evaluación para el alumno.
	 *
	 * @param array $submission
	 * @return string
	 */
	protected function render_submission_timeline( $submission ) {
		$status = isset( $submission['status'] ) ? sanitize_key( (string) $submission['status'] ) : '';

		$submitted = ! empty( $submission['submission_id'] );
		$graded    = $this->can_student_view_published_grade( $submission );
		$needs_revision = in_array( $status, array( 'needs_revision', 'returned' ), true );
		$in_review = $submitted && ! $graded && ! $needs_revision;
		$review_label = $needs_revision
			? __( 'Requiere ajustes', 'atora-lms' )
			: __( 'En revisión', 'atora-lms' );

		$steps = array(
			array( 'label' => __( 'Enviado', 'atora-lms' ), 'active' => $submitted, 'done' => $submitted ),
			array( 'label' => $review_label, 'active' => ( $in_review || $needs_revision ), 'done' => $graded ),
			array( 'label' => __( 'Calificado', 'atora-lms' ), 'active' => $graded, 'done' => $graded ),
		);

		ob_start();
		?>
		<div class="clms-submission-timeline" role="list">
			<?php foreach ( $steps as $step ) :
				$classes = array( 'clms-submission-step' );
				if ( ! empty( $step['done'] ) ) { $classes[] = 'is-done'; }
				if ( ! empty( $step['active'] ) ) { $classes[] = 'is-active'; }
				?>
				<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" role="listitem">
					<span class="clms-submission-dot" aria-hidden="true"></span>
					<span class="clms-submission-label"><?php echo esc_html( $step['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Procesa envío del formulario.
	 *
	 * @return void
	 */
	public function handle_submission_request() {
		if ( is_admin() ) {
			return;
		}

		if ( 'POST' !== strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			return;
		}

		$action = isset( $_POST['clms_action'] ) ? sanitize_key( wp_unslash( $_POST['clms_action'] ) ) : '';

		if ( 'submit_assignment' !== $action ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		$nonce     = isset( $_POST['clms_submission_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_submission_nonce'] ) ) : '';

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $nonce, 'clms_submit_assignment_' . $lesson_id ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $this->user_can_submit_to_lesson( $user_id, $lesson_id ) ) {
			$this->redirect_back_with_submission_message( $lesson_id, 'error', 'No tienes acceso para enviar esta tarea.' );
		}

		$result = $this->save_submission(
			$user_id,
			$lesson_id,
			array(
				'comment' => isset( $_POST['clms_submission_comment'] ) ? wp_kses_post( wp_unslash( $_POST['clms_submission_comment'] ) ) : '',
			),
			isset( $_FILES['clms_submission_files'] ) ? $_FILES['clms_submission_files'] : array()
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_back_with_submission_message( $lesson_id, 'error', $result->get_error_message() );
		}

			$notice = $this->build_submission_success_notice( $lesson_id, absint( $result ), $user_id );
			$this->redirect_back_with_submission_message( $lesson_id, 'success', $notice );
		}

	/**
	 * Guarda o actualiza una entrega.
	 *
	 * @param int   $user_id    Usuario.
	 * @param int   $lesson_id  Lección.
	 * @param array $data       Datos.
	 * @param array $files_data Files.
	 * @return int|WP_Error
	 */
}
