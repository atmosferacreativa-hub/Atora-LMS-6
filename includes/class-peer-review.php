<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CLMS_Peer_Review
 *
 * Canvas-style peer assessment module.
 *
 * Workflow:
 *  1. Teacher enables peer review on a lesson (meta _clms_peer_review_enabled = 1).
 *  2. Teacher (or REST API) calls assign_for_lesson() after the submission deadline.
 *  3. Each reviewer sees pending assignments in [clms_peer_review_inbox] shortcode.
 *  4. Reviewer fills out the rubric and submits via AJAX (clms_pr_submit).
 *  5. Once all peer reviews for a submission are completed, aggregate_grades() is called
 *     and the student's grade is updated.
 *
 * CPT: clms_peer_review
 *  Meta keys:
 *   _clms_pr_lesson_id      — lesson being reviewed
 *   _clms_pr_submission_id  — clms_submission post ID
 *   _clms_pr_reviewee_id    — student whose work is being reviewed
 *   _clms_pr_reviewer_id    — student who must do the review
 *   _clms_pr_status         — pending | completed
 *   _clms_pr_scores         — array of criterion => points
 *   _clms_pr_comment        — free-text overall comment
 *   _clms_pr_submitted_at   — Y-m-d H:i:s UTC
 */
class CLMS_Peer_Review {

	public function __construct() {
		add_action( 'init',              array( $this, 'register_cpt' ) );
		add_action( 'wp_ajax_clms_pr_submit', array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_clms_pr_training_complete', array( $this, 'ajax_complete_training' ) );
		add_shortcode( 'clms_peer_review_inbox', array( $this, 'render_inbox' ) );
		add_shortcode( 'clms_peer_review_training', array( $this, 'render_training_module' ) );
		add_action( 'clms_peer_review_completed', array( $this, 'maybe_aggregate' ), 10, 2 );
		add_action( 'clms_peer_review_completed', array( $this, 'capture_review_quality' ), 20, 2 );
		add_action( 'add_meta_boxes', array( $this, 'register_lesson_metabox' ) );
		add_action( 'save_post_lm_lesson', array( $this, 'save_lesson_metabox' ) );

		if ( is_admin() ) {
			add_action( 'atora_lms_admin_menu', array( $this, 'register_admin_menu' ) );
			add_action( 'admin_post_clms_peer_review_assign_now', array( $this, 'handle_assign_now' ) );
			add_action( 'admin_notices', array( $this, 'render_assign_now_notice' ) );
		}
	}

	/* ----------------------------------------------------------------
	 * CPT
	 * -------------------------------------------------------------- */

	public function register_cpt() {
		// clms_rubric is registered by CLMS_Rubric (base module) — do not duplicate.
		register_post_type( 'clms_peer_review', array(
			'labels'              => array(
				'name'          => __( 'Revisiones entre pares', 'atora-lms' ),
				'singular_name' => __( 'Revisión entre pares', 'atora-lms' ),
			),
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'supports'            => array( 'custom-fields' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		) );
	}

	/* ----------------------------------------------------------------
	 * LESSON SETTINGS (metabox)
	 * -------------------------------------------------------------- */

	public function register_lesson_metabox(): void {
		add_meta_box(
			'clms_peer_review_settings',
			__( 'Coevaluación (Peer review)', 'atora-lms' ),
			array( $this, 'render_lesson_metabox' ),
			'lm_lesson',
			'normal',
			'default'
		);
	}

	public function render_lesson_metabox( $post ): void {
		if ( ! $post || empty( $post->ID ) ) {
			return;
		}

		if ( class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_manage_lms( $post->ID ) ) {
			return;
		}

		wp_nonce_field( 'clms_peer_review_settings_save', 'clms_peer_review_settings_nonce' );

		$peer_enabled   = (bool) get_post_meta( $post->ID, '_clms_peer_review_enabled', true );
		$blind          = (bool) get_post_meta( $post->ID, '_clms_peer_review_blind', true );
		$training_req   = self::is_training_required_for_lesson( absint( $post->ID ) );
		$cal_enabled    = (bool) get_post_meta( $post->ID, '_clms_pr_calibration_enabled', true );
		$cal_submission = absint( get_post_meta( $post->ID, '_clms_pr_calibration_submission_id', true ) );
		$teacher_grade  = max( 0, min( 100, absint( get_post_meta( $post->ID, '_clms_pr_calibration_teacher_grade', true ) ) ) );
		$default_reviews_per_student = 2;
		?>
		<p style="margin:0 0 10px;color:#555;font-size:13px">
			<?php esc_html_e( 'Configura coevaluación por lección: modo ciego, entrenamiento y calibración.', 'atora-lms' ); ?>
		</p>

		<p>
			<label>
				<input type="checkbox" name="clms_peer_review_enabled" value="1" <?php checked( $peer_enabled ); ?>>
				<strong><?php esc_html_e( 'Activar coevaluación en esta lección', 'atora-lms' ); ?></strong>
			</label>
		</p>

		<div style="margin:10px 0 14px;padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#f9fafb;">
			<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Asignación (docente)', 'atora-lms' ); ?></strong></p>
			<p style="margin:0 0 10px;color:#666;font-size:12px">
				<?php esc_html_e( 'Cuando la fecha de entrega ya pasó, asigna revisiones a los estudiantes. (También disponible vía REST).', 'atora-lms' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="clms_peer_review_assign_now">
				<input type="hidden" name="lesson_id" value="<?php echo esc_attr( (string) absint( $post->ID ) ); ?>">
				<?php wp_nonce_field( 'clms_peer_review_assign_' . absint( $post->ID ), 'clms_peer_review_assign_nonce' ); ?>
				<label for="clms_peer_review_reviews_per_student"><strong><?php esc_html_e( 'Revisiones por estudiante', 'atora-lms' ); ?></strong></label><br>
				<input
					type="number"
					min="1"
					max="5"
					step="1"
					name="reviews_per_student"
					id="clms_peer_review_reviews_per_student"
					value="<?php echo esc_attr( (string) $default_reviews_per_student ); ?>"
					style="width: 120px;"
				>
				<button type="submit" class="button button-secondary" style="margin-left:8px"><?php esc_html_e( 'Asignar ahora', 'atora-lms' ); ?></button>
			</form>
		</div>

		<p>
			<label>
				<input type="checkbox" name="clms_peer_review_blind" value="1" <?php checked( $blind ); ?>>
				<?php esc_html_e( 'Modo ciego (oculta identidades a estudiantes)', 'atora-lms' ); ?>
			</label>
		</p>

		<p>
			<label>
				<input type="checkbox" name="clms_peer_review_training_required" value="1" <?php checked( $training_req ); ?>>
				<?php esc_html_e( 'Requiere completar entrenamiento antes de revisar', 'atora-lms' ); ?>
			</label>
		</p>

		<hr style="margin:12px 0;">

		<p style="margin:0 0 8px"><strong><?php esc_html_e( 'Calibración (opcional)', 'atora-lms' ); ?></strong></p>
		<p style="margin:0 0 10px;color:#666;font-size:12px">
			<?php esc_html_e( 'Si está activa, cada revisor evalúa un ejemplar y el sistema calcula un score de calibración. Para aprobar el envío de reviews reales, el revisor debe completar esta calibración.', 'atora-lms' ); ?>
		</p>

		<p>
			<label>
				<input type="checkbox" name="clms_pr_calibration_enabled" value="1" <?php checked( $cal_enabled ); ?>>
				<?php esc_html_e( 'Activar calibración', 'atora-lms' ); ?>
			</label>
		</p>

		<p>
			<label for="clms_pr_calibration_submission_id"><strong><?php esc_html_e( 'Submission ID del ejemplar', 'atora-lms' ); ?></strong></label><br>
			<input
				type="number"
				min="0"
				step="1"
				name="clms_pr_calibration_submission_id"
				id="clms_pr_calibration_submission_id"
				value="<?php echo esc_attr( (string) $cal_submission ); ?>"
				style="width: 220px;"
				placeholder="0"
			>
			<span class="description"><?php esc_html_e( 'Debe ser un post `clms_submission`.', 'atora-lms' ); ?></span>
		</p>

		<p>
			<label for="clms_pr_calibration_teacher_grade"><strong><?php esc_html_e( 'Pauta docente (0–100)', 'atora-lms' ); ?></strong></label><br>
			<input
				type="number"
				min="0"
				max="100"
				step="1"
				name="clms_pr_calibration_teacher_grade"
				id="clms_pr_calibration_teacher_grade"
				value="<?php echo esc_attr( (string) $teacher_grade ); ?>"
				style="width: 120px;"
			>
		</p>
		<?php
	}

	public function save_lesson_metabox( $post_id ): void {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		if ( ! isset( $_POST['clms_peer_review_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['clms_peer_review_settings_nonce'] ) ), 'clms_peer_review_settings_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_manage_lms( $post_id ) ) {
			return;
		}

		$peer_enabled = ! empty( $_POST['clms_peer_review_enabled'] ) ? '1' : '0';
		$blind        = ! empty( $_POST['clms_peer_review_blind'] ) ? '1' : '0';
		$training_req = ! empty( $_POST['clms_peer_review_training_required'] ) ? '1' : '0';
		$cal_enabled  = ! empty( $_POST['clms_pr_calibration_enabled'] ) ? '1' : '0';

		update_post_meta( $post_id, '_clms_peer_review_enabled', $peer_enabled );
		update_post_meta( $post_id, '_clms_peer_review_blind', $blind );
		update_post_meta( $post_id, '_clms_peer_review_training_required', $training_req );
		update_post_meta( $post_id, '_clms_pr_calibration_enabled', $cal_enabled );

		$submission_id = isset( $_POST['clms_pr_calibration_submission_id'] ) ? absint( wp_unslash( $_POST['clms_pr_calibration_submission_id'] ) ) : 0;
		if ( $submission_id && 'clms_submission' !== get_post_type( $submission_id ) ) {
			$submission_id = 0;
		}
		update_post_meta( $post_id, '_clms_pr_calibration_submission_id', $submission_id );

		$teacher_grade = isset( $_POST['clms_pr_calibration_teacher_grade'] ) ? absint( wp_unslash( $_POST['clms_pr_calibration_teacher_grade'] ) ) : 0;
		$teacher_grade = max( 0, min( 100, $teacher_grade ) );
		update_post_meta( $post_id, '_clms_pr_calibration_teacher_grade', $teacher_grade );
	}

	public function handle_assign_now(): void {
		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		if ( ! $lesson_id ) {
			wp_die( esc_html__( 'Lección inválida.', 'atora-lms' ) );
		}

		if ( ! isset( $_POST['clms_peer_review_assign_nonce'] ) ) {
			wp_die( esc_html__( 'Nonce faltante.', 'atora-lms' ) );
		}

		check_admin_referer( 'clms_peer_review_assign_' . $lesson_id, 'clms_peer_review_assign_nonce' );

		if ( class_exists( 'CLMS_Helper' ) && ! CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$per_student = isset( $_POST['reviews_per_student'] ) ? absint( wp_unslash( $_POST['reviews_per_student'] ) ) : 2;
		$per_student = max( 1, min( 5, $per_student ) );

		$result = self::assign_for_lesson( $lesson_id, $per_student );

		$redirect = admin_url( 'post.php?post=' . $lesson_id . '&action=edit' );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg(
				array(
					'clms_pr_assign' => 'error',
					'code'          => sanitize_key( $result->get_error_code() ),
				),
				$redirect
			);
			wp_safe_redirect( $redirect );
			exit;
		}

		$redirect = add_query_arg(
			array(
				'clms_pr_assign' => 'ok',
				'assigned'       => absint( $result['assigned'] ?? 0 ),
				'skipped'        => absint( $result['skipped'] ?? 0 ),
			),
			$redirect
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function render_assign_now_notice(): void {
		if ( empty( $_GET['clms_pr_assign'] ) ) {
			return;
		}

		$status = sanitize_key( (string) wp_unslash( $_GET['clms_pr_assign'] ) );
		if ( 'ok' === $status ) {
			$assigned = isset( $_GET['assigned'] ) ? absint( wp_unslash( $_GET['assigned'] ) ) : 0;
			$skipped  = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( 'Coevaluación asignada. Nuevas: %d — Omitidas: %d.', 'atora-lms' ), $assigned, $skipped ) ) . '</p></div>';
			return;
		}

		if ( 'error' === $status ) {
			$code = isset( $_GET['code'] ) ? sanitize_key( (string) wp_unslash( $_GET['code'] ) ) : 'error';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sprintf( __( 'No se pudo asignar coevaluación (%s). Revisa que haya suficientes entregas y que esté habilitada.', 'atora-lms' ), $code ) ) . '</p></div>';
			return;
		}
	}

	/* ----------------------------------------------------------------
	 * ASSIGNMENT ENGINE
	 * -------------------------------------------------------------- */

	/**
	 * Ajustes de calibración por lección.
	 *
	 * @param int $lesson_id
	 * @return array{enabled:bool,submission_id:int,teacher_grade:int}
	 */
	protected static function get_calibration_settings_for_lesson( int $lesson_id ): array {
		$lesson_id = absint( $lesson_id );
		$enabled   = (bool) get_post_meta( $lesson_id, '_clms_pr_calibration_enabled', true );
		$submission_id = absint( get_post_meta( $lesson_id, '_clms_pr_calibration_submission_id', true ) );
		$teacher_grade = max( 0, min( 100, absint( get_post_meta( $lesson_id, '_clms_pr_calibration_teacher_grade', true ) ) ) );

		if ( ! $enabled || ! $submission_id ) {
			return array(
				'enabled'       => false,
				'submission_id' => 0,
				'teacher_grade' => $teacher_grade,
			);
		}

		// Defensive: the exemplar must be a submission.
		if ( $submission_id && 'clms_submission' !== get_post_type( $submission_id ) ) {
			$submission_id = 0;
		}

		return array(
			'enabled'       => $enabled && $submission_id > 0,
			'submission_id' => $submission_id,
			'teacher_grade' => $teacher_grade,
		);
	}

	public static function is_calibration_required_for_lesson( int $lesson_id ): bool {
		$settings = self::get_calibration_settings_for_lesson( absint( $lesson_id ) );
		return ! empty( $settings['enabled'] ) && ! empty( $settings['submission_id'] );
	}

	public static function is_reviewer_calibrated_for_lesson( int $reviewer_id, int $lesson_id ): bool {
		$reviewer_id = absint( $reviewer_id );
		$lesson_id   = absint( $lesson_id );
		if ( ! $reviewer_id || ! $lesson_id ) {
			return false;
		}

		$cache = get_user_meta( $reviewer_id, '_clms_pr_calibration_status_' . $lesson_id, true );
		if ( is_string( $cache ) && '' !== $cache ) {
			return 'pass' === sanitize_key( $cache ) || 'warn' === sanitize_key( $cache );
		}

		$done = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_clms_pr_lesson_id', 'value' => $lesson_id, 'type' => 'NUMERIC' ),
					array( 'key' => '_clms_pr_reviewer_id', 'value' => $reviewer_id, 'type' => 'NUMERIC' ),
					array( 'key' => '_clms_pr_is_calibration', 'value' => '1' ),
					array( 'key' => '_clms_pr_status', 'value' => 'completed' ),
				),
			)
		);

		if ( empty( $done[0] ) ) {
			return false;
		}

		$status = sanitize_key( (string) get_post_meta( absint( $done[0] ), '_clms_pr_calibration_status', true ) );
		if ( '' === $status ) {
			// Si no hay scoring aún, permitimos avanzar — pero se registrará en reportes.
			return true;
		}

		return 'pass' === $status || 'warn' === $status;
	}

	/**
	 * Devuelve el % 0–100 de una asignación (sum(scores)/max).
	 *
	 * @param int $assignment_id
	 * @param int $lesson_id
	 * @return int
	 */
	protected static function get_assignment_percent( int $assignment_id, int $lesson_id ): int {
		$assignment_id = absint( $assignment_id );
		$lesson_id     = absint( $lesson_id );
		if ( ! $assignment_id ) {
			return 0;
		}

		$scores = (array) get_post_meta( $assignment_id, '_clms_pr_scores', true );
		$total  = 0;
		foreach ( $scores as $v ) {
			$total += absint( $v );
		}

		$max_score = 100;
		$rubric_id = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;
		if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$derived = CLMS_Rubric::get_total_points( $rubric_id );
			if ( $derived > 0 ) {
				$max_score = $derived;
			}
		}

		if ( $max_score <= 0 ) {
			return 0;
		}

		return max( 0, min( 100, absint( round( ( $total / $max_score ) * 100 ) ) ) );
	}

	public static function score_calibration_assignment( int $assignment_id ): void {
		$assignment_id = absint( $assignment_id );
		if ( ! $assignment_id || '1' !== (string) get_post_meta( $assignment_id, '_clms_pr_is_calibration', true ) ) {
			return;
		}

		$lesson_id   = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		$reviewer_id = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );

		$settings = self::get_calibration_settings_for_lesson( $lesson_id );
		$teacher_grade = absint( $settings['teacher_grade'] ?? 0 );

		$percent = self::get_assignment_percent( $assignment_id, $lesson_id );
		$delta   = (int) ( $percent - $teacher_grade );
		$abs     = abs( $delta );

		$status = 'pass';
		if ( $abs > 20 ) {
			$status = 'fail';
		} elseif ( $abs > 10 ) {
			$status = 'warn';
		}

		$score = max( 0, 100 - $abs );

		update_post_meta( $assignment_id, '_clms_pr_total_percent', $percent );
		update_post_meta( $assignment_id, '_clms_pr_calibration_delta', $delta );
		update_post_meta( $assignment_id, '_clms_pr_calibration_score', $score );
		update_post_meta( $assignment_id, '_clms_pr_calibration_status', $status );

		if ( $reviewer_id && $lesson_id ) {
			update_user_meta( $reviewer_id, '_clms_pr_calibration_status_' . $lesson_id, $status );
			update_user_meta( $reviewer_id, '_clms_pr_calibration_delta_' . $lesson_id, $delta );
			update_user_meta( $reviewer_id, '_clms_pr_calibration_scored_at_' . $lesson_id, gmdate( 'Y-m-d H:i:s' ) );
		}

		self::audit(
			'calibration_scored',
			array(
				'assignment_id' => $assignment_id,
				'lesson_id'     => $lesson_id,
				'submission_id' => absint( get_post_meta( $assignment_id, '_clms_pr_submission_id', true ) ),
				'reviewer_id'   => $reviewer_id,
				'reviewee_id'   => 0,
				'actor_id'      => $reviewer_id,
				'meta'          => array(
					'teacher_grade' => $teacher_grade,
					'percent'       => $percent,
					'delta'         => $delta,
					'status'        => $status,
				),
			)
		);
	}

	protected static function score_consistency_for_submission( int $submission_id, int $peer_grade, int $lesson_id ): void {
		$submission_id = absint( $submission_id );
		$peer_grade    = absint( $peer_grade );
		$lesson_id     = absint( $lesson_id );
		if ( ! $submission_id ) {
			return;
		}

		$assignments = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_clms_pr_submission_id', 'value' => $submission_id, 'type' => 'NUMERIC' ),
					array( 'key' => '_clms_pr_status', 'value' => 'completed' ),
					array( 'key' => '_clms_pr_is_calibration', 'value' => '0' ),
				),
			)
		);

		foreach ( (array) $assignments as $assignment_id ) {
			$assignment_id = absint( $assignment_id );
			if ( ! $assignment_id ) {
				continue;
			}

			$percent = self::get_assignment_percent( $assignment_id, $lesson_id );
			$delta   = (int) ( $percent - $peer_grade );
			$excluded = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_excluded', true );
			if ( $excluded ) {
				$flag = 'excluded';
			} else {
				$flag = abs( $delta ) >= 20 ? 'outlier' : 'ok';
			}

			update_post_meta( $assignment_id, '_clms_pr_total_percent', $percent );
			update_post_meta( $assignment_id, '_clms_pr_consistency_delta', $delta );
			update_post_meta( $assignment_id, '_clms_pr_consistency_flag', $flag );
		}

		self::audit(
			'consistency_scored',
			array(
				'assignment_id' => 0,
				'lesson_id'     => $lesson_id,
				'submission_id' => $submission_id,
				'reviewer_id'   => 0,
				'reviewee_id'   => absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) ),
				'actor_id'      => get_current_user_id(),
				'meta'          => array(
					'peer_grade' => $peer_grade,
				),
			)
		);
	}

	/**
	 * Distributes submissions of a lesson to peer reviewers.
	 *
	 * @param int $lesson_id
	 * @param int $reviews_per_student Number of peers each student reviews (default 2).
	 * @return array|WP_Error  { assigned => int, skipped => int }
	 */
	public static function assign_for_lesson( $lesson_id, $reviews_per_student = 2 ) {
		$lesson_id = absint( $lesson_id );

		if ( ! (bool) get_post_meta( $lesson_id, '_clms_peer_review_enabled', true ) ) {
			return new WP_Error( 'not_enabled', __( 'La revisión entre pares no está habilitada para esta lección.', 'atora-lms' ) );
		}

		// Get all submissions for this lesson.
		$submissions = get_posts( array(
			'post_type'      => 'clms_submission',
			'post_status'    => array( 'publish', 'pending' ),
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'   => '_clms_submission_lesson_id',
					'value' => $lesson_id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		if ( count( $submissions ) < 2 ) {
			return new WP_Error( 'insufficient_submissions', __( 'Se necesitan al menos 2 entregas para asignar revisiones.', 'atora-lms' ) );
		}

		// Build map: student_id => submission_id.
		$student_map = array();
		foreach ( $submissions as $sub ) {
			$sid = absint( get_post_meta( $sub->ID, '_clms_submission_user_id', true ) );
			if ( $sid ) {
				$student_map[ $sid ] = $sub->ID;
			}
		}

		$student_ids = array_keys( $student_map );
		$n           = count( $student_ids );
		$assigned    = 0;
		$skipped     = 0;

		// Fisher-Yates shuffle for fairness.
		for ( $i = $n - 1; $i > 0; $i-- ) {
			$j                        = wp_rand( 0, $i );
			$tmp                      = $student_ids[ $i ];
			$student_ids[ $i ]        = $student_ids[ $j ];
			$student_ids[ $j ]        = $tmp;
		}

		foreach ( $student_ids as $idx => $reviewer_id ) {
			$count = 0;
			for ( $k = 1; $k <= $n - 1 && $count < $reviews_per_student; $k++ ) {
				$reviewee_id   = $student_ids[ ( $idx + $k ) % $n ];
				$submission_id = $student_map[ $reviewee_id ];

				// Skip if already assigned.
				$exists = get_posts( array(
					'post_type'      => 'clms_peer_review',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array( 'key' => '_clms_pr_lesson_id',     'value' => $lesson_id,     'type' => 'NUMERIC' ),
						array( 'key' => '_clms_pr_reviewer_id',   'value' => $reviewer_id,   'type' => 'NUMERIC' ),
						array( 'key' => '_clms_pr_submission_id', 'value' => $submission_id, 'type' => 'NUMERIC' ),
					),
				) );

				if ( $exists ) {
					++$skipped;
					continue;
				}

				$pr_id = wp_insert_post( array(
					'post_type'   => 'clms_peer_review',
					'post_status' => 'draft',
					'post_title'  => "PR: Lesson {$lesson_id} / Reviewer {$reviewer_id}",
					'post_author' => $reviewer_id,
				) );

				if ( ! is_wp_error( $pr_id ) ) {
					update_post_meta( $pr_id, '_clms_pr_lesson_id',     $lesson_id );
					update_post_meta( $pr_id, '_clms_pr_submission_id', $submission_id );
					update_post_meta( $pr_id, '_clms_pr_reviewee_id',   $reviewee_id );
					update_post_meta( $pr_id, '_clms_pr_reviewer_id',   $reviewer_id );
					update_post_meta( $pr_id, '_clms_pr_status',        'pending' );
					update_post_meta( $pr_id, '_clms_pr_is_calibration', '0' );
					self::audit(
						'assignment_created',
						array(
							'assignment_id' => $pr_id,
							'lesson_id'     => $lesson_id,
							'submission_id' => $submission_id,
							'reviewer_id'   => $reviewer_id,
							'reviewee_id'   => $reviewee_id,
							'actor_id'      => get_current_user_id(),
						)
					);
					++$assigned;
					++$count;
				}
			}
		}

		// Calibration (optional): create one shared exemplar review per reviewer.
		$calibration = self::get_calibration_settings_for_lesson( $lesson_id );
		if ( ! empty( $calibration['enabled'] ) && ! empty( $calibration['submission_id'] ) ) {
			foreach ( $student_ids as $reviewer_id ) {
				$reviewer_id = absint( $reviewer_id );
				if ( ! $reviewer_id ) {
					continue;
				}

				$exists = get_posts(
					array(
						'post_type'      => 'clms_peer_review',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							array( 'key' => '_clms_pr_lesson_id',     'value' => $lesson_id, 'type' => 'NUMERIC' ),
							array( 'key' => '_clms_pr_reviewer_id',   'value' => $reviewer_id, 'type' => 'NUMERIC' ),
							array( 'key' => '_clms_pr_submission_id', 'value' => absint( $calibration['submission_id'] ), 'type' => 'NUMERIC' ),
							array( 'key' => '_clms_pr_is_calibration','value' => '1' ),
						),
					)
				);
				if ( $exists ) {
					continue;
				}

				$pr_id = wp_insert_post(
					array(
						'post_type'   => 'clms_peer_review',
						'post_status' => 'draft',
						'post_title'  => "PR Calibration: Lesson {$lesson_id} / Reviewer {$reviewer_id}",
						'post_author' => $reviewer_id,
					)
				);
				if ( is_wp_error( $pr_id ) ) {
					continue;
				}

				update_post_meta( $pr_id, '_clms_pr_lesson_id',     $lesson_id );
				update_post_meta( $pr_id, '_clms_pr_submission_id', absint( $calibration['submission_id'] ) );
				update_post_meta( $pr_id, '_clms_pr_reviewee_id',   0 );
				update_post_meta( $pr_id, '_clms_pr_reviewer_id',   $reviewer_id );
				update_post_meta( $pr_id, '_clms_pr_status',        'pending' );
				update_post_meta( $pr_id, '_clms_pr_is_calibration', '1' );

				self::audit(
					'calibration_assigned',
					array(
						'assignment_id' => $pr_id,
						'lesson_id'     => $lesson_id,
						'submission_id' => absint( $calibration['submission_id'] ),
						'reviewer_id'   => $reviewer_id,
						'reviewee_id'   => 0,
						'actor_id'      => get_current_user_id(),
						'meta'          => array(
							'teacher_grade' => absint( $calibration['teacher_grade'] ?? 0 ),
						),
					)
				);
			}
		}

		return array( 'assigned' => $assigned, 'skipped' => $skipped );
	}

	/* ----------------------------------------------------------------
	 * AJAX SUBMIT
	 * -------------------------------------------------------------- */

	public function ajax_submit() {
		check_ajax_referer( 'clms_pr_nonce', 'nonce' );

		$reviewer_id   = get_current_user_id();
		$assignment_id = absint( $_POST['assignment_id'] ?? 0 );

		if ( ! $reviewer_id || ! $assignment_id ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'atora-lms' ) ), 400 );
		}

		$assignment = get_post( $assignment_id );
		if ( ! $assignment || 'clms_peer_review' !== $assignment->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Asignación no encontrada.', 'atora-lms' ) ), 404 );
		}

		if ( absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) ) !== $reviewer_id ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para esta revisión.', 'atora-lms' ) ), 403 );
		}

		if ( 'completed' === get_post_meta( $assignment_id, '_clms_pr_status', true ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya enviaste esta revisión.', 'atora-lms' ) ), 409 );
		}

		$lesson_id = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		if ( self::is_training_required_for_lesson( $lesson_id ) && ! self::is_reviewer_trained( $reviewer_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes completar el entrenamiento de revisión antes de enviar evaluaciones entre pares.', 'atora-lms' ) ), 403 );
		}

		$is_calibration = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_is_calibration', true );
		if ( ! $is_calibration && self::is_calibration_required_for_lesson( $lesson_id ) && ! self::is_reviewer_calibrated_for_lesson( $reviewer_id, $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Debes completar la calibración antes de enviar revisiones entre pares.', 'atora-lms' ) ), 403 );
		}

		$raw_scores = isset( $_POST['scores'] ) && is_array( $_POST['scores'] ) ? wp_unslash( $_POST['scores'] ) : array();
		$scores     = self::validate_scores_for_assignment( $assignment_id, $raw_scores );

		if ( is_wp_error( $scores ) ) {
			wp_send_json_error( array( 'message' => $scores->get_error_message() ), 400 );
		}

		$comment = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

		update_post_meta( $assignment_id, '_clms_pr_scores',       $scores );
		update_post_meta( $assignment_id, '_clms_pr_comment',      $comment );
		update_post_meta( $assignment_id, '_clms_pr_status',       'completed' );
		update_post_meta( $assignment_id, '_clms_pr_submitted_at', gmdate( 'Y-m-d H:i:s' ) );
		wp_update_post( array( 'ID' => $assignment_id, 'post_status' => 'publish' ) );

		$submission_id = absint( get_post_meta( $assignment_id, '_clms_pr_submission_id', true ) );

		self::audit(
			'review_submitted',
			array(
				'assignment_id' => $assignment_id,
				'lesson_id'     => $lesson_id,
				'submission_id' => $submission_id,
				'reviewer_id'   => $reviewer_id,
				'reviewee_id'   => absint( get_post_meta( $assignment_id, '_clms_pr_reviewee_id', true ) ),
				'actor_id'      => $reviewer_id,
				'meta'          => array(
					'is_calibration' => $is_calibration ? 1 : 0,
				),
			)
		);

		if ( $is_calibration ) {
			self::score_calibration_assignment( $assignment_id );
		}

		do_action( 'clms_peer_review_completed', $assignment_id, $submission_id );

		wp_send_json_success( array( 'message' => __( 'Revisión enviada correctamente.', 'atora-lms' ) ) );
	}

	/**
	 * Marca entrenamiento de revisor como completado.
	 *
	 * @return void
	 */
	public function ajax_complete_training() {
		check_ajax_referer( 'clms_pr_training_nonce', 'nonce' );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión para completar el entrenamiento.', 'atora-lms' ) ), 403 );
		}

		$status = self::mark_reviewer_training_completed( $user_id );

		wp_send_json_success(
			array(
				'message' => __( 'Entrenamiento completado. Ya puedes enviar revisiones.', 'atora-lms' ),
				'status'  => $status,
			)
		);
	}

	/**
	 * Valida y normaliza puntajes recibidos para una asignación.
	 *
	 * @param int   $assignment_id Asignación.
	 * @param array $raw_scores    Puntajes sin procesar.
	 * @return array|WP_Error
	 */
	public static function validate_scores_for_assignment( $assignment_id, $raw_scores ) {
		$assignment_id = absint( $assignment_id );
		$raw_scores    = is_array( $raw_scores ) ? $raw_scores : array();

		if ( ! $assignment_id ) {
			return new WP_Error( 'invalid_assignment', __( 'Asignación inválida.', 'atora-lms' ) );
		}

		$lesson_id = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		$rubric_id = $lesson_id ? absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) ) : 0;

		if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
			$criteria = CLMS_Rubric::get_criteria( $rubric_id );
			$scores   = array();

			foreach ( $criteria as $index => $criterion ) {
				$key       = (string) $index;
				$score_raw = isset( $raw_scores[ $key ] ) ? trim( (string) $raw_scores[ $key ] ) : '';
				$max       = isset( $criterion['max_points'] ) ? absint( $criterion['max_points'] ) : 0;

				if ( '' === $score_raw || ! is_numeric( $score_raw ) ) {
					return new WP_Error( 'invalid_score', __( 'Debes completar todos los criterios de la rúbrica.', 'atora-lms' ) );
				}

				$score = (int) round( (float) $score_raw );

				if ( $score < 0 || $score > $max ) {
					return new WP_Error( 'invalid_score_range', __( 'Uno de los puntajes excede el máximo permitido por la rúbrica.', 'atora-lms' ) );
				}

				$scores[ sanitize_key( $key ) ] = $score;
			}

			foreach ( array_keys( $raw_scores ) as $raw_key ) {
				if ( ! array_key_exists( (string) $raw_key, $scores ) ) {
					return new WP_Error( 'unexpected_score_key', __( 'Se detectaron criterios inválidos en la revisión.', 'atora-lms' ) );
				}
			}

			return $scores;
		}

		$scores = array();

		foreach ( $raw_scores as $key => $value ) {
			$score_raw = trim( (string) $value );

			if ( '' === $score_raw || ! is_numeric( $score_raw ) ) {
				return new WP_Error( 'invalid_score', __( 'Todos los puntajes deben ser numéricos.', 'atora-lms' ) );
			}

			$score = (int) round( (float) $score_raw );

			if ( $score < 0 || $score > 100 ) {
				return new WP_Error( 'invalid_score_range', __( 'Los puntajes deben estar entre 0 y 100.', 'atora-lms' ) );
			}

			$scores[ sanitize_key( (string) $key ) ] = $score;
		}

		return $scores;
	}

	/* ----------------------------------------------------------------
	 * GRADE AGGREGATION
	 * -------------------------------------------------------------- */

	/**
	 * Called after each peer review is submitted.
	 * If all assigned reviewers have completed their review, calculate the aggregate grade.
	 */
	public function maybe_aggregate( $assignment_id, $submission_id ) {
		if ( ! $submission_id ) {
			return;
		}

		$lesson_id = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );

		// El ejemplar de calibración nunca debe afectar calificaciones.
		if ( self::is_submission_calibration_exemplar( absint( $submission_id ), $lesson_id ) ) {
			return;
		}

		$calc = self::compute_peer_grade_for_submission( absint( $submission_id ), $lesson_id );
		if ( empty( $calc['expected'] ) ) {
			return;
		}

		if ( absint( $calc['completed'] ?? 0 ) < absint( $calc['expected'] ?? 0 ) ) {
			return;
		}

		$peer_grade = absint( $calc['peer_grade'] ?? 0 );
		$avg_scores = isset( $calc['avg_scores'] ) && is_array( $calc['avg_scores'] ) ? $calc['avg_scores'] : array();
		$included_completed = absint( $calc['included_completed'] ?? 0 );

		$teacher_grade = absint( get_post_meta( $submission_id, '_clms_submission_grade', true ) );
		$final_grade   = $peer_grade;
		if ( $teacher_grade > 0 && $peer_grade > 0 ) {
			$final_grade = round( ( $teacher_grade * 0.6 ) + ( $peer_grade * 0.4 ) );
		} elseif ( $teacher_grade > 0 && 0 === $peer_grade ) {
			$final_grade = $teacher_grade;
		}

		update_post_meta( $submission_id, '_clms_peer_grade',    $peer_grade );
		update_post_meta( $submission_id, '_clms_peer_scores',   $avg_scores );
		update_post_meta( $submission_id, '_clms_peer_grade_at', gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $submission_id, '_clms_final_grade',   $final_grade );

		self::score_consistency_for_submission( $submission_id, $peer_grade, $lesson_id );

		// Notify the reviewee.
		$reviewee_id = absint( get_post_meta( $submission_id, '_clms_submission_user_id', true ) );
		if ( $reviewee_id && $included_completed > 0 ) {
			$this->notify_reviewee( $reviewee_id, $lesson_id, $peer_grade );
		}

		do_action( 'clms_peer_grades_aggregated', $submission_id, $peer_grade, $avg_scores );
	}

	/**
	 * Captura calidad de una revisión al completarse.
	 *
	 * @param int $assignment_id Asignación.
	 * @param int $submission_id Entrega revisada.
	 * @return void
	 */
	public function capture_review_quality( $assignment_id, $submission_id ) {
		$assignment_id = absint( $assignment_id );
		$submission_id = absint( $submission_id );

		if ( ! $assignment_id || 'completed' !== get_post_meta( $assignment_id, '_clms_pr_status', true ) ) {
			return;
		}

		$already_scored = get_post_meta( $assignment_id, '_clms_pr_quality_scored_at', true );
		if ( ! empty( $already_scored ) ) {
			return;
		}

		$scores  = (array) get_post_meta( $assignment_id, '_clms_pr_scores', true );
		$comment = (string) get_post_meta( $assignment_id, '_clms_pr_comment', true );
		$quality = self::calculate_quality_score( $assignment_id, $scores, $comment );

		update_post_meta( $assignment_id, '_clms_pr_quality_score', absint( $quality['score'] ) );
		update_post_meta( $assignment_id, '_clms_pr_quality_status', sanitize_key( (string) $quality['status'] ) );
		update_post_meta( $assignment_id, '_clms_pr_quality_flags', (array) $quality['flags'] );
		update_post_meta( $assignment_id, '_clms_pr_quality_scored_at', gmdate( 'Y-m-d H:i:s' ) );

		$reviewer_id = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
		do_action(
			'clms_peer_review_quality_scored',
			$assignment_id,
			array(
				'score'         => absint( $quality['score'] ),
				'status'        => sanitize_key( (string) $quality['status'] ),
				'flags'         => (array) $quality['flags'],
				'reviewer_id'   => $reviewer_id,
				'submission_id' => $submission_id,
			),
			$submission_id
		);
	}

	protected function notify_reviewee( $user_id, $lesson_id, $grade ) {
		$user    = get_userdata( $user_id );
		$lesson  = get_post( $lesson_id );

		if ( ! $user || ! $lesson ) {
			return;
		}

		$lesson_title = $lesson->post_title;
		$subject      = sprintf(
			/* translators: %s: lesson title */
			__( 'Revisión entre pares completada: %s', 'atora-lms' ),
			$lesson_title
		);

		$message  = '<p>' . sprintf(
			/* translators: %s: user display name */
			esc_html__( 'Hola %s,', 'atora-lms' ),
			esc_html( $user->display_name )
		) . '</p>';
		$message .= '<p>' . sprintf(
			/* translators: %s: lesson title */
			esc_html__( 'Tus compañeros han completado la revisión de tu entrega en %s.', 'atora-lms' ),
			'<strong>' . esc_html( $lesson_title ) . '</strong>'
		) . '</p>';
		$message .= '<p>' . sprintf(
			/* translators: %d: peer review average grade */
			esc_html__( 'Calificación promedio de revisión entre pares: %d/100.', 'atora-lms' ),
			absint( $grade )
		) . '</p>';
		$message .= '<p>' . esc_html__( 'Puedes ver el detalle en tu perfil.', 'atora-lms' ) . '</p>';

		CLMS_Email::send(
			$user->user_email,
			$subject,
			$message,
			array(
				'headline' => sprintf(
					/* translators: %s: lesson title */
					__( 'Revisión entre pares completada: %s', 'atora-lms' ),
					$lesson_title
				),
			)
		);
	}

	/* ----------------------------------------------------------------
	 * SHORTCODE: [clms_peer_review_inbox]
	 * -------------------------------------------------------------- */

	public function render_inbox( $atts ) {
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			return '<p class="atora-notice">' . esc_html__( 'Debes iniciar sesión para ver tus revisiones.', 'atora-lms' ) . '</p>';
		}

		$reviewer_id = get_current_user_id();

		$assignments = get_posts( array(
			'post_type'      => 'clms_peer_review',
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => '_clms_pr_reviewer_id',
					'value' => $reviewer_id,
					'type'  => 'NUMERIC',
				),
			),
		) );

		ob_start();

		// Enqueue nonce for AJAX.
		wp_enqueue_script( 'clms-peer-review', ATORA_LMS_URL . 'assets/js/peer-review.js', array( 'jquery' ), ATORA_LMS_VERSION, true );
		wp_localize_script( 'clms-peer-review', 'clmsPR', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'clms_pr_nonce' ),
			'trainingNonce' => wp_create_nonce( 'clms_pr_training_nonce' ),
			'i18n'    => array(
				'sending'            => __( 'Enviando…', 'atora-lms' ),
				'review_sent_success'=> __( '✔ Revisión enviada correctamente.', 'atora-lms' ),
				'completed'          => __( 'Completada', 'atora-lms' ),
				'send_error'         => __( 'Error al enviar.', 'atora-lms' ),
				'network_error_retry'=> __( 'Error de red. Intenta de nuevo.', 'atora-lms' ),
				'send_review'        => __( 'Enviar revisión', 'atora-lms' ),
				'complete_training'  => __( 'Completar entrenamiento', 'atora-lms' ),
				'training_done'      => __( 'Entrenamiento completado', 'atora-lms' ),
				'training_error'     => __( 'No se pudo completar el entrenamiento.', 'atora-lms' ),
			),
		) );

		echo '<div class="clms-pr-inbox">';
		echo '<h2 class="clms-pr-inbox__title">' . esc_html__( 'Mis revisiones asignadas', 'atora-lms' ) . '</h2>';

		$training_status = self::get_reviewer_training_status( $reviewer_id );
		if ( empty( $training_status['completed'] ) ) {
			echo $this->render_training_notice_card();
		}

		if ( empty( $assignments ) ) {
			echo '<p class="clms-pr-inbox__empty">' . esc_html__( 'No tienes revisiones asignadas por el momento.', 'atora-lms' ) . '</p>';
		} else {
			$pending   = array_filter( $assignments, fn( $a ) => 'completed' !== get_post_meta( $a->ID, '_clms_pr_status', true ) );
			$completed = array_filter( $assignments, fn( $a ) => 'completed' === get_post_meta( $a->ID, '_clms_pr_status', true ) );

			if ( ! empty( $pending ) ) {
				usort(
					$pending,
					static function ( $a, $b ) {
						$ac = '1' === (string) get_post_meta( $a->ID, '_clms_pr_is_calibration', true );
						$bc = '1' === (string) get_post_meta( $b->ID, '_clms_pr_is_calibration', true );
						if ( $ac !== $bc ) {
							return $ac ? -1 : 1;
						}
						return $b->post_date_gmt <=> $a->post_date_gmt;
					}
				);
				echo '<h3 class="clms-pr-inbox__section-title">' . sprintf( esc_html__( 'Pendientes (%d)', 'atora-lms' ), count( $pending ) ) . '</h3>';
				echo '<div class="clms-pr-cards">';
				foreach ( $pending as $a ) {
					echo $this->render_assignment_card( $a, false );
				}
				echo '</div>';
			}

			if ( ! empty( $completed ) ) {
				echo '<h3 class="clms-pr-inbox__section-title clms-pr-inbox__section-title--done">' . sprintf( esc_html__( 'Completadas (%d)', 'atora-lms' ), count( $completed ) ) . '</h3>';
				echo '<div class="clms-pr-cards">';
				foreach ( $completed as $a ) {
					echo $this->render_assignment_card( $a, true );
				}
				echo '</div>';
			}
		}

		echo '</div>';

		$this->render_inline_styles();

		return ob_get_clean();
	}

	/* ----------------------------------------------------------------
	 * ADMIN: REPORTES
	 * -------------------------------------------------------------- */

	public function register_admin_menu(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Coevaluación', 'atora-lms' ),
			__( 'Coevaluación', 'atora-lms' ),
			'edit_posts',
			'clms-peer-review-reports',
			array( $this, 'render_admin_reports_page' )
		);

		add_action( 'admin_post_clms_peer_review_export', array( $this, 'handle_export_csv' ) );
		add_action( 'admin_post_clms_peer_review_toggle_exclude', array( $this, 'handle_toggle_exclude_assignment' ) );
	}

	public function render_admin_reports_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( wp_unslash( $_GET['lesson_id'] ) ) : 0;
		$focus_assignment_id = isset( $_GET['assignment_id'] ) ? absint( wp_unslash( $_GET['assignment_id'] ) ) : 0;

		echo '<div class="wrap"><h1>' . esc_html__( 'Coevaluación — Reporte', 'atora-lms' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'MVP: calibración + consistencia (outliers) + auditoría básica.', 'atora-lms' ) . '</p>';

		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="clms-peer-review-reports">';
		echo '<label><strong>' . esc_html__( 'Lesson ID', 'atora-lms' ) . '</strong></label><br>';
		echo '<input type="number" name="lesson_id" value="' . esc_attr( (string) $lesson_id ) . '" min="1" style="width:180px">';
		echo '<button class="button button-primary" type="submit" style="margin-left:8px">' . esc_html__( 'Cargar', 'atora-lms' ) . '</button>';
		echo '</form>';

		if ( ! $lesson_id ) {
			echo '<p class="description">' . esc_html__( 'Indica un lesson_id para ver el reporte.', 'atora-lms' ) . '</p>';
			echo '</div>';
			return;
		}

		$lesson = get_post( $lesson_id );
		if ( ! $lesson ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Lección no encontrada.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$calibration = self::get_calibration_settings_for_lesson( $lesson_id );
		echo '<h2>' . esc_html( $lesson->post_title ) . ' <span class="description">#' . esc_html( (string) $lesson_id ) . '</span></h2>';

		$peer_enabled = (bool) get_post_meta( $lesson_id, '_clms_peer_review_enabled', true );
		$blind        = (bool) get_post_meta( $lesson_id, '_clms_peer_review_blind', true );

		echo '<p class="description">';
		echo esc_html__( 'Peer review:', 'atora-lms' ) . ' <strong>' . ( $peer_enabled ? esc_html__( 'sí', 'atora-lms' ) : esc_html__( 'no', 'atora-lms' ) ) . '</strong>';
		echo ' — ' . esc_html__( 'Modo ciego:', 'atora-lms' ) . ' <strong>' . ( $blind ? esc_html__( 'sí', 'atora-lms' ) : esc_html__( 'no', 'atora-lms' ) ) . '</strong>';
		echo ' — ' . esc_html__( 'Calibración:', 'atora-lms' ) . ' <strong>' . ( ! empty( $calibration['enabled'] ) ? esc_html__( 'sí', 'atora-lms' ) : esc_html__( 'no', 'atora-lms' ) ) . '</strong>';
		if ( ! empty( $calibration['enabled'] ) ) {
			echo ' <span class="description">';
			echo esc_html__( '(ejemplar', 'atora-lms' ) . ' #' . esc_html( (string) absint( $calibration['submission_id'] ?? 0 ) ) . ', ';
			echo esc_html__( 'pauta', 'atora-lms' ) . ': ' . esc_html( (string) absint( $calibration['teacher_grade'] ?? 0 ) ) . '/100)';
			echo '</span>';
		}
		echo '</p>';

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=clms_peer_review_export&lesson_id=' . $lesson_id ),
			'clms_peer_review_export_' . $lesson_id
		);
		echo '<p style="margin: 10px 0">';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Exportar CSV', 'atora-lms' ) . '</a>';
		echo '</p>';

		$assignments = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_clms_pr_lesson_id', 'value' => $lesson_id, 'type' => 'NUMERIC' ),
				),
			)
		);

		if ( empty( $assignments ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No hay asignaciones de peer review para esta lección.', 'atora-lms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		if ( $focus_assignment_id ) {
			$assignment = get_post( $focus_assignment_id );
			if ( $assignment && 'clms_peer_review' === $assignment->post_type ) {
				$assignment_lesson_id = absint( get_post_meta( $focus_assignment_id, '_clms_pr_lesson_id', true ) );
				if ( $assignment_lesson_id === $lesson_id ) {
					echo '<div class="notice notice-info" style="padding: 12px 14px;">';
					echo '<p style="margin:0 0 8px 0"><strong>' . esc_html__( 'Detalle de asignación', 'atora-lms' ) . '</strong> <span class="description">#' . esc_html( (string) $focus_assignment_id ) . '</span></p>';

					$rid = absint( get_post_meta( $focus_assignment_id, '_clms_pr_reviewer_id', true ) );
					$eid = absint( get_post_meta( $focus_assignment_id, '_clms_pr_reviewee_id', true ) );
					$is_cal = '1' === (string) get_post_meta( $focus_assignment_id, '_clms_pr_is_calibration', true );
					$status = sanitize_key( (string) get_post_meta( $focus_assignment_id, '_clms_pr_status', true ) );
					$submitted_at = (string) get_post_meta( $focus_assignment_id, '_clms_pr_submitted_at', true );
					$excluded = '1' === (string) get_post_meta( $focus_assignment_id, '_clms_pr_excluded', true );
					$exclude_reason = sanitize_text_field( (string) get_post_meta( $focus_assignment_id, '_clms_pr_excluded_reason', true ) );

					$ruser = $rid ? get_userdata( $rid ) : null;
					$euser = $eid ? get_userdata( $eid ) : null;
					$rname = $ruser ? ( $ruser->display_name ? $ruser->display_name : $ruser->user_login ) : (string) $rid;
					$ename = $euser ? ( $euser->display_name ? $euser->display_name : $euser->user_login ) : ( $is_cal ? __( 'Ejemplar', 'atora-lms' ) : (string) $eid );

					$delta = $is_cal ? get_post_meta( $focus_assignment_id, '_clms_pr_calibration_delta', true ) : get_post_meta( $focus_assignment_id, '_clms_pr_consistency_delta', true );
					$flag  = $is_cal ? sanitize_key( (string) get_post_meta( $focus_assignment_id, '_clms_pr_calibration_status', true ) ) : sanitize_key( (string) get_post_meta( $focus_assignment_id, '_clms_pr_consistency_flag', true ) );
					$delta_i = ( '' !== (string) $delta && is_numeric( $delta ) ) ? (int) $delta : null;

					$quality = absint( get_post_meta( $focus_assignment_id, '_clms_pr_quality_score', true ) );
					$qstatus = sanitize_key( (string) get_post_meta( $focus_assignment_id, '_clms_pr_quality_status', true ) );
					$qflags  = (array) get_post_meta( $focus_assignment_id, '_clms_pr_quality_flags', true );
					$qflags  = array_values( array_filter( array_map( 'sanitize_text_field', (array) $qflags ) ) );

					$saved_scores  = (array) get_post_meta( $focus_assignment_id, '_clms_pr_scores', true );
					$saved_comment = sanitize_textarea_field( (string) get_post_meta( $focus_assignment_id, '_clms_pr_comment', true ) );

					echo '<table class="widefat striped" style="max-width: 1100px; background: #fff;">';
					echo '<tbody>';
					echo '<tr><th style="width:220px">' . esc_html__( 'Revisor', 'atora-lms' ) . '</th><td>' . esc_html( $rname ) . ' <span class="description">#' . esc_html( (string) $rid ) . '</span></td></tr>';
					echo '<tr><th>' . esc_html__( 'Reviewee', 'atora-lms' ) . '</th><td>' . esc_html( $ename ) . ( $eid ? ' <span class="description">#' . esc_html( (string) $eid ) . '</span>' : '' ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Tipo', 'atora-lms' ) . '</th><td>' . esc_html( $is_cal ? __( 'Calibración', 'atora-lms' ) : __( 'Normal', 'atora-lms' ) ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th><td>' . esc_html( $status ? $status : 'pending' ) . ( $submitted_at ? ' <span class="description">(' . esc_html( $submitted_at ) . ')</span>' : '' ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Δ / flag', 'atora-lms' ) . '</th><td>' . esc_html( $flag ? $flag : '—' ) . ( null !== $delta_i ? ' <span class="description">Δ ' . esc_html( ( $delta_i > 0 ? '+' : '' ) . (string) $delta_i ) . '</span>' : '' ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Calidad', 'atora-lms' ) . '</th><td>' . esc_html( $quality ? ( (string) $quality . '/100' ) : '—' ) . ( $qstatus ? ' <span class="description">(' . esc_html( $qstatus ) . ')</span>' : '' ) . ( $qflags ? '<br><span class="description">' . esc_html( implode( ' | ', $qflags ) ) . '</span>' : '' ) . '</td></tr>';
					echo '<tr><th>' . esc_html__( 'Excluida', 'atora-lms' ) . '</th><td>' . esc_html( $excluded ? __( 'sí', 'atora-lms' ) : __( 'no', 'atora-lms' ) ) . ( $exclude_reason ? '<br><span class="description">' . esc_html__( 'Motivo:', 'atora-lms' ) . ' ' . esc_html( $exclude_reason ) . '</span>' : '' ) . '</td></tr>';
					echo '</tbody></table>';

					if ( ! $is_cal ) {
						echo '<div style="margin-top:10px;padding:12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;">';
						echo '<p style="margin:0 0 8px 0"><strong>' . esc_html__( 'Acción docente', 'atora-lms' ) . '</strong></p>';

						echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
						echo '<input type="hidden" name="action" value="clms_peer_review_toggle_exclude">';
						echo '<input type="hidden" name="lesson_id" value="' . esc_attr( (string) $lesson_id ) . '">';
						echo '<input type="hidden" name="assignment_id" value="' . esc_attr( (string) $focus_assignment_id ) . '">';
						echo '<input type="hidden" name="exclude" value="' . esc_attr( (string) ( $excluded ? 0 : 1 ) ) . '">';
						wp_nonce_field( 'clms_peer_review_toggle_exclude_' . $focus_assignment_id );

						if ( ! $excluded ) {
							echo '<label for="clms_pr_exclude_reason"><strong>' . esc_html__( 'Motivo (opcional)', 'atora-lms' ) . '</strong></label><br>';
							echo '<input type="text" name="reason" id="clms_pr_exclude_reason" value="" maxlength="240" style="width: 520px; max-width: 100%;" placeholder="' . esc_attr( __( 'Ej: outlier extremo / comentario inapropiado / plagio', 'atora-lms' ) ) . '">';
							echo '<br><span class="description">' . esc_html__( 'Se guardará en auditoría y en el export.', 'atora-lms' ) . '</span><br><br>';
							echo '<button class="button button-secondary" type="submit" onclick="return confirm(\'' . esc_js( __( '¿Excluir esta revisión del cálculo?', 'atora-lms' ) ) . '\')">' . esc_html__( 'Excluir del cálculo', 'atora-lms' ) . '</button>';
						} else {
							echo '<button class="button button-secondary" type="submit" onclick="return confirm(\'' . esc_js( __( '¿Reincluir esta revisión en el cálculo?', 'atora-lms' ) ) . '\')">' . esc_html__( 'Reincluir en el cálculo', 'atora-lms' ) . '</button>';
						}
						echo '</form>';
						echo '</div>';
					}

					if ( ! empty( $saved_scores ) || $saved_comment ) {
						echo '<div style="margin-top:12px">';
						echo '<p style="margin:0 0 6px 0"><strong>' . esc_html__( 'Contenido de la revisión', 'atora-lms' ) . '</strong></p>';
						if ( ! empty( $saved_scores ) ) {
							echo '<ul style="margin:0 0 6px 18px">';
							foreach ( (array) $saved_scores as $crit => $pts ) {
								echo '<li><strong>' . esc_html( (string) $crit ) . ':</strong> ' . esc_html( (string) absint( $pts ) ) . '</li>';
							}
							echo '</ul>';
						}
						if ( $saved_comment ) {
							echo '<blockquote style="margin:0;padding:10px 12px;background:#f9fafb;border-left:4px solid #e5e7eb;">' . esc_html( $saved_comment ) . '</blockquote>';
						}
						echo '</div>';
					}

					echo '</div>';
				}
			}
		}

		$by_reviewer = array();
		foreach ( $assignments as $assignment_id ) {
			$assignment_id = absint( $assignment_id );
			$reviewer_id   = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
			if ( ! $reviewer_id ) {
				continue;
			}

			if ( empty( $by_reviewer[ $reviewer_id ] ) ) {
				$by_reviewer[ $reviewer_id ] = array(
					'reviewer_id'    => $reviewer_id,
					'completed'      => 0,
					'pending'        => 0,
					'excluded'       => 0,
					'outliers'       => 0,
					'avg_abs_delta'  => null,
					'deltas'         => array(),
					'cal_status'     => '',
					'cal_delta'      => null,
					'cal_score'      => null,
				);
			}

			$status = sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_status', true ) );
			$is_cal = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_is_calibration', true );

			if ( 'completed' === $status ) {
				$by_reviewer[ $reviewer_id ]['completed']++;
			} else {
				$by_reviewer[ $reviewer_id ]['pending']++;
			}

			if ( $is_cal ) {
				$by_reviewer[ $reviewer_id ]['cal_status'] = sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_calibration_status', true ) );
				$by_reviewer[ $reviewer_id ]['cal_delta']  = get_post_meta( $assignment_id, '_clms_pr_calibration_delta', true );
				$by_reviewer[ $reviewer_id ]['cal_score']  = get_post_meta( $assignment_id, '_clms_pr_calibration_score', true );
				continue;
			}

			$flag = sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_consistency_flag', true ) );
			if ( 'excluded' === $flag || '1' === (string) get_post_meta( $assignment_id, '_clms_pr_excluded', true ) ) {
				$by_reviewer[ $reviewer_id ]['excluded']++;
				continue;
			}
			if ( 'outlier' === $flag ) {
				$by_reviewer[ $reviewer_id ]['outliers']++;
			}

			$delta = get_post_meta( $assignment_id, '_clms_pr_consistency_delta', true );
			if ( '' !== (string) $delta && is_numeric( $delta ) ) {
				$by_reviewer[ $reviewer_id ]['deltas'][] = abs( (int) $delta );
			}
		}

		foreach ( $by_reviewer as $rid => $row ) {
			$deltas = $row['deltas'];
			if ( ! empty( $deltas ) ) {
				$by_reviewer[ $rid ]['avg_abs_delta'] = round( array_sum( $deltas ) / count( $deltas ), 1 );
			}
			unset( $by_reviewer[ $rid ]['deltas'] );
		}

		$rows = array_values( $by_reviewer );
		usort(
			$rows,
			static function ( $a, $b ) {
				return absint( $b['outliers'] ?? 0 ) <=> absint( $a['outliers'] ?? 0 );
			}
		);

		echo '<h3>' . esc_html__( 'Resumen por revisor', 'atora-lms' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width: 1200px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Revisor', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Completadas', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Pendientes', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Excluidas', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Outliers', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Δ promedio', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Calibración', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$rid = absint( $row['reviewer_id'] ?? 0 );
			$u   = $rid ? get_userdata( $rid ) : null;
			$name = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : (string) $rid;

			$cal_status = sanitize_key( (string) ( $row['cal_status'] ?? '' ) );
			$cal_delta  = isset( $row['cal_delta'] ) && is_numeric( $row['cal_delta'] ) ? (int) $row['cal_delta'] : null;
			$cal_score  = isset( $row['cal_score'] ) && is_numeric( $row['cal_score'] ) ? absint( $row['cal_score'] ) : null;

			$cal_label = $cal_status ? $cal_status : '—';
			if ( null !== $cal_delta ) {
				$cal_label .= ' (Δ ' . ( $cal_delta > 0 ? '+' : '' ) . (string) $cal_delta . ')';
			}
			if ( null !== $cal_score ) {
				$cal_label .= ' — ' . (string) $cal_score . '/100';
			}

			echo '<tr>';
			echo '<td><strong>' . esc_html( $name ) . '</strong><br><span class="description">#' . esc_html( (string) $rid ) . '</span></td>';
			echo '<td>' . esc_html( (string) absint( $row['completed'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) absint( $row['pending'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) absint( $row['excluded'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( (string) absint( $row['outliers'] ?? 0 ) ) . '</td>';
			echo '<td>' . esc_html( null !== ( $row['avg_abs_delta'] ?? null ) ? (string) $row['avg_abs_delta'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $cal_label ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Mapa reviewer ↔ reviewee.
		echo '<h3 style="margin-top:18px">' . esc_html__( 'Mapa reviewer ↔ reviewee', 'atora-lms' ) . '</h3>';
		echo '<table class="widefat striped" style="max-width: 1200px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Asignación', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Revisor', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Reviewee', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Tipo', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Estado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Δ / flag', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Calidad', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Enviado', 'atora-lms' ) . '</th>';
		echo '<th>' . esc_html__( 'Acción', 'atora-lms' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $assignments as $assignment_id ) {
			$assignment_id = absint( $assignment_id );
			$rid = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
			$eid = absint( get_post_meta( $assignment_id, '_clms_pr_reviewee_id', true ) );
			$is_cal = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_is_calibration', true );
			$status = sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_status', true ) );
			$submitted_at = (string) get_post_meta( $assignment_id, '_clms_pr_submitted_at', true );

			$ruser = $rid ? get_userdata( $rid ) : null;
			$euser = $eid ? get_userdata( $eid ) : null;
			$rname = $ruser ? ( $ruser->display_name ? $ruser->display_name : $ruser->user_login ) : (string) $rid;
			$ename = $euser ? ( $euser->display_name ? $euser->display_name : $euser->user_login ) : ( $is_cal ? __( 'Ejemplar', 'atora-lms' ) : (string) $eid );

			$delta = $is_cal ? get_post_meta( $assignment_id, '_clms_pr_calibration_delta', true ) : get_post_meta( $assignment_id, '_clms_pr_consistency_delta', true );
			$flag  = $is_cal ? sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_calibration_status', true ) ) : sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_consistency_flag', true ) );
			$delta_i = ( '' !== (string) $delta && is_numeric( $delta ) ) ? (int) $delta : null;

			$quality = absint( get_post_meta( $assignment_id, '_clms_pr_quality_score', true ) );
			$qstatus = sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_quality_status', true ) );

			$delta_label = '—';
			if ( null !== $delta_i ) {
				$delta_label = ( $delta_i > 0 ? '+' : '' ) . (string) $delta_i;
			}
			$flag_label = $flag ? $flag : '—';

			$q_label = $quality ? ( (string) $quality . '/100' ) : '—';
			if ( $qstatus ) {
				$q_label .= ' (' . $qstatus . ')';
			}

			$excluded = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_excluded', true );
			$exclude_reason = sanitize_text_field( (string) get_post_meta( $assignment_id, '_clms_pr_excluded_reason', true ) );
			$detail_url = add_query_arg(
				array(
					'page'          => 'clms-peer-review-reports',
					'lesson_id'     => $lesson_id,
					'assignment_id' => $assignment_id,
				),
				admin_url( 'admin.php' )
			);
			$toggle_url = wp_nonce_url(
				admin_url(
					'admin-post.php?action=clms_peer_review_toggle_exclude'
					. '&lesson_id=' . $lesson_id
					. '&assignment_id=' . $assignment_id
					. '&exclude=' . ( $excluded ? 0 : 1 )
				),
				'clms_peer_review_toggle_exclude_' . $assignment_id
			);

			echo '<tr>';
			echo '<td><a href="' . esc_url( $detail_url ) . '">#' . esc_html( (string) $assignment_id ) . '</a>' . ( $excluded ? '<br><span class="description">' . esc_html__( 'excluida', 'atora-lms' ) . ( $exclude_reason ? ': ' . esc_html( $exclude_reason ) : '' ) . '</span>' : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td><strong>' . esc_html( $rname ) . '</strong><br><span class="description">#' . esc_html( (string) $rid ) . '</span></td>';
			echo '<td><strong>' . esc_html( $ename ) . '</strong><br><span class="description">' . ( $eid ? '#' . esc_html( (string) $eid ) : '—' ) . '</span></td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . esc_html( $is_cal ? __( 'Calibración', 'atora-lms' ) : __( 'Normal', 'atora-lms' ) ) . '</td>';
			echo '<td>' . esc_html( $status ? $status : 'pending' ) . '</td>';
			echo '<td><strong>' . esc_html( $flag_label ) . '</strong><br><span class="description">Δ ' . esc_html( $delta_label ) . '</span></td>';
			echo '<td>' . esc_html( $q_label ) . '</td>';
			echo '<td><span class="description">' . esc_html( $submitted_at ? $submitted_at : '—' ) . '</span></td>';
			if ( $is_cal ) {
				echo '<td><span class="description">—</span></td>';
			} else {
				echo '<td><a class="button button-small" href="' . esc_url( $toggle_url ) . '">' . esc_html( $excluded ? __( 'Reincluir', 'atora-lms' ) : __( 'Excluir', 'atora-lms' ) ) . '</a></td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Auditoría básica desde tabla.
		global $wpdb;
		$audit_table = $wpdb->prefix . 'clms_peer_review_audit_log';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $audit_table ) );
		if ( $exists ) {
			$logs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT event_type, reviewer_id, reviewee_id, assignment_id, submission_id, actor_id, created_at
					 FROM {$audit_table}
					 WHERE lesson_id = %d
					 ORDER BY id DESC
					 LIMIT 50",
					$lesson_id
				),
				ARRAY_A
			);
			$logs = is_array( $logs ) ? $logs : array();

			echo '<h3 style="margin-top:18px">' . esc_html__( 'Auditoría (últimos 50 eventos)', 'atora-lms' ) . '</h3>';
			if ( empty( $logs ) ) {
				echo '<p class="description">' . esc_html__( 'No hay eventos aún.', 'atora-lms' ) . '</p>';
			} else {
				echo '<table class="widefat striped" style="max-width: 1200px">';
				echo '<thead><tr>';
				echo '<th>' . esc_html__( 'Fecha', 'atora-lms' ) . '</th>';
				echo '<th>' . esc_html__( 'Evento', 'atora-lms' ) . '</th>';
				echo '<th>' . esc_html__( 'Actor', 'atora-lms' ) . '</th>';
				echo '<th>' . esc_html__( 'Revisor', 'atora-lms' ) . '</th>';
				echo '<th>' . esc_html__( 'Reviewee', 'atora-lms' ) . '</th>';
				echo '<th>' . esc_html__( 'Assignment', 'atora-lms' ) . '</th>';
				echo '<th>' . esc_html__( 'Submission', 'atora-lms' ) . '</th>';
				echo '</tr></thead><tbody>';

				foreach ( $logs as $log ) {
					$actor = absint( $log['actor_id'] ?? 0 );
					$ar = $actor ? get_userdata( $actor ) : null;
					$actor_name = $ar ? ( $ar->display_name ? $ar->display_name : $ar->user_login ) : (string) $actor;

					echo '<tr>';
					echo '<td><span class="description">' . esc_html( (string) ( $log['created_at'] ?? '' ) ) . '</span></td>';
					echo '<td><strong>' . esc_html( sanitize_key( (string) ( $log['event_type'] ?? '' ) ) ) . '</strong></td>';
					echo '<td>' . esc_html( $actor_name ) . '</td>';
					echo '<td>' . esc_html( (string) absint( $log['reviewer_id'] ?? 0 ) ) . '</td>';
					echo '<td>' . esc_html( (string) absint( $log['reviewee_id'] ?? 0 ) ) . '</td>';
					echo '<td>' . esc_html( (string) absint( $log['assignment_id'] ?? 0 ) ) . '</td>';
					echo '<td>' . esc_html( (string) absint( $log['submission_id'] ?? 0 ) ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody></table>';
			}
		}

		echo '</div>';
	}

	public function handle_export_csv(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$lesson_id = isset( $_GET['lesson_id'] ) ? absint( wp_unslash( $_GET['lesson_id'] ) ) : 0;
		check_admin_referer( 'clms_peer_review_export_' . $lesson_id );

		if ( ! $lesson_id ) {
			wp_die( esc_html__( 'Lección inválida.', 'atora-lms' ) );
		}

		$assignments = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => '_clms_pr_lesson_id', 'value' => $lesson_id, 'type' => 'NUMERIC' ),
				),
			)
		);

		$stream = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $stream ) {
			wp_die( esc_html__( 'No se pudo generar el CSV.', 'atora-lms' ) );
		}

		fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$stream,
			array(
				'lesson_id',
				'assignment_id',
				'is_calibration',
				'excluded',
				'excluded_reason',
				'reviewer_id',
				'reviewee_id',
				'status',
				'submitted_at',
				'consistency_delta',
				'consistency_flag',
				'calibration_delta',
				'calibration_score',
				'calibration_status',
				'quality_score',
				'quality_status',
			)
		);

		foreach ( (array) $assignments as $assignment_id ) {
			$assignment_id = absint( $assignment_id );
			$is_cal = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_is_calibration', true );
			$excluded = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_excluded', true );
			$exclude_reason = sanitize_text_field( (string) get_post_meta( $assignment_id, '_clms_pr_excluded_reason', true ) );
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$stream,
				array(
					$lesson_id,
					$assignment_id,
					$is_cal ? 1 : 0,
					$excluded ? 1 : 0,
					$exclude_reason,
					absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) ),
					absint( get_post_meta( $assignment_id, '_clms_pr_reviewee_id', true ) ),
					sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_status', true ) ),
					(string) get_post_meta( $assignment_id, '_clms_pr_submitted_at', true ),
					$is_cal ? '' : (string) get_post_meta( $assignment_id, '_clms_pr_consistency_delta', true ),
					$is_cal ? '' : sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_consistency_flag', true ) ),
					$is_cal ? (string) get_post_meta( $assignment_id, '_clms_pr_calibration_delta', true ) : '',
					$is_cal ? (string) get_post_meta( $assignment_id, '_clms_pr_calibration_score', true ) : '',
					$is_cal ? sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_calibration_status', true ) ) : '',
					absint( get_post_meta( $assignment_id, '_clms_pr_quality_score', true ) ),
					sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_quality_status', true ) ),
				)
			);
		}

		rewind( $stream );
		$content = stream_get_contents( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( sprintf( 'atora-peer-review-lesson-%d-%s.csv', $lesson_id, gmdate( 'Ymd-His' ) ) ) );
		echo (string) $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function handle_toggle_exclude_assignment(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
		}

		$lesson_id     = isset( $_REQUEST['lesson_id'] ) ? absint( wp_unslash( $_REQUEST['lesson_id'] ) ) : 0;
		$assignment_id = isset( $_REQUEST['assignment_id'] ) ? absint( wp_unslash( $_REQUEST['assignment_id'] ) ) : 0;
		$exclude       = isset( $_REQUEST['exclude'] ) ? absint( wp_unslash( $_REQUEST['exclude'] ) ) : 0;
		$reason        = isset( $_REQUEST['reason'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['reason'] ) ) : '';
		if ( function_exists( 'mb_substr' ) ) {
			$reason = mb_substr( $reason, 0, 240 );
		} else {
			$reason = substr( $reason, 0, 240 );
		}

		check_admin_referer( 'clms_peer_review_toggle_exclude_' . $assignment_id );

		if ( ! $lesson_id || ! $assignment_id ) {
			wp_die( esc_html__( 'Solicitud inválida.', 'atora-lms' ) );
		}

		$assignment = get_post( $assignment_id );
		if ( ! $assignment || 'clms_peer_review' !== $assignment->post_type ) {
			wp_die( esc_html__( 'Asignación no encontrada.', 'atora-lms' ) );
		}

		$assignment_lesson_id = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		if ( $assignment_lesson_id !== $lesson_id ) {
			wp_die( esc_html__( 'Asignación no pertenece a esta lección.', 'atora-lms' ) );
		}

		$is_cal = '1' === (string) get_post_meta( $assignment_id, '_clms_pr_is_calibration', true );
		if ( $is_cal ) {
			wp_safe_redirect( admin_url( 'admin.php?page=clms-peer-review-reports&lesson_id=' . $lesson_id ) );
			exit;
		}

		update_post_meta( $assignment_id, '_clms_pr_excluded', $exclude ? '1' : '0' );
		if ( $exclude ) {
			update_post_meta( $assignment_id, '_clms_pr_excluded_reason', $reason );
			update_post_meta( $assignment_id, '_clms_pr_excluded_at', gmdate( 'Y-m-d H:i:s' ) );
			update_post_meta( $assignment_id, '_clms_pr_excluded_by', absint( get_current_user_id() ) );
		} else {
			update_post_meta( $assignment_id, '_clms_pr_excluded_reason', '' );
		}

		$submission_id = absint( get_post_meta( $assignment_id, '_clms_pr_submission_id', true ) );
		$reviewer_id   = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
		$reviewee_id   = absint( get_post_meta( $assignment_id, '_clms_pr_reviewee_id', true ) );

		self::audit(
			$exclude ? 'assignment_excluded' : 'assignment_included',
			array(
				'assignment_id' => $assignment_id,
				'lesson_id'     => $lesson_id,
				'submission_id' => $submission_id,
				'reviewer_id'   => $reviewer_id,
				'reviewee_id'   => $reviewee_id,
				'actor_id'      => get_current_user_id(),
				'meta'          => array(
					'excluded' => $exclude ? 1 : 0,
					'reason'   => $reason,
				),
			)
		);

		if ( $submission_id ) {
			self::recalculate_submission_grade( $submission_id, $lesson_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=clms-peer-review-reports&lesson_id=' . $lesson_id . '&assignment_id=' . $assignment_id ) );
		exit;
	}

	/* ----------------------------------------------------------------
	 * AUDIT LOG
	 * -------------------------------------------------------------- */

	protected static function is_submission_calibration_exemplar( int $submission_id, int $lesson_id ): bool {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );
		if ( ! $submission_id || ! $lesson_id ) {
			return false;
		}

		$settings = self::get_calibration_settings_for_lesson( $lesson_id );
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		return absint( $settings['submission_id'] ?? 0 ) === $submission_id;
	}

	protected static function compute_peer_grade_for_submission( int $submission_id, int $lesson_id ): array {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );

		$all = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_clms_pr_submission_id', 'value' => $submission_id, 'type' => 'NUMERIC' ),
				),
			)
		);

		$expected = 0;
		$completed = 0;
		$included_completed = 0;
		$total_scores = array();

		foreach ( (array) $all as $pr_id ) {
			$pr_id = absint( $pr_id );
			if ( ! $pr_id ) {
				continue;
			}

			$is_cal = '1' === (string) get_post_meta( $pr_id, '_clms_pr_is_calibration', true );
			if ( $is_cal ) {
				continue;
			}

			++$expected;

			if ( 'completed' !== get_post_meta( $pr_id, '_clms_pr_status', true ) ) {
				continue;
			}

			++$completed;

			$excluded = '1' === (string) get_post_meta( $pr_id, '_clms_pr_excluded', true );
			if ( $excluded ) {
				continue;
			}

			++$included_completed;
			$scores = (array) get_post_meta( $pr_id, '_clms_pr_scores', true );
			foreach ( $scores as $criterion => $points ) {
				if ( ! isset( $total_scores[ $criterion ] ) ) {
					$total_scores[ $criterion ] = array();
				}
				$total_scores[ $criterion ][] = absint( $points );
			}
		}

		$avg_scores  = array();
		$peer_grade  = 0;

		if ( $included_completed > 0 && ! empty( $total_scores ) ) {
			$grand_total = 0;
			foreach ( $total_scores as $criterion => $values ) {
				$avg                      = array_sum( $values ) / count( $values );
				$avg_scores[ $criterion ] = round( $avg, 1 );
				$grand_total             += $avg;
			}

			$rubric_id = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
			$max_score = 100;

			if ( $rubric_id && class_exists( 'CLMS_Rubric' ) ) {
				$derived = CLMS_Rubric::get_total_points( $rubric_id );
				if ( $derived > 0 ) {
					$max_score = $derived;
				}
			}

			$peer_grade = $max_score > 0 ? min( 100, round( ( $grand_total / $max_score ) * 100 ) ) : 0;
		}

		return array(
			'expected'          => $expected,
			'completed'         => $completed,
			'included_completed'=> $included_completed,
			'peer_grade'        => $peer_grade,
			'avg_scores'        => $avg_scores,
		);
	}

	protected static function recalculate_submission_grade( int $submission_id, int $lesson_id ): void {
		$submission_id = absint( $submission_id );
		$lesson_id     = absint( $lesson_id );
		if ( ! $submission_id || ! $lesson_id ) {
			return;
		}

		if ( self::is_submission_calibration_exemplar( $submission_id, $lesson_id ) ) {
			return;
		}

		$calc = self::compute_peer_grade_for_submission( $submission_id, $lesson_id );
		if ( empty( $calc['expected'] ) ) {
			return;
		}
		if ( absint( $calc['completed'] ?? 0 ) < absint( $calc['expected'] ?? 0 ) ) {
			return;
		}

		$peer_grade = absint( $calc['peer_grade'] ?? 0 );
		$avg_scores = isset( $calc['avg_scores'] ) && is_array( $calc['avg_scores'] ) ? $calc['avg_scores'] : array();

		$teacher_grade = absint( get_post_meta( $submission_id, '_clms_submission_grade', true ) );

		$final_grade = $peer_grade;
		if ( $teacher_grade > 0 && $peer_grade > 0 ) {
			$final_grade = round( ( $teacher_grade * 0.6 ) + ( $peer_grade * 0.4 ) );
		} elseif ( $teacher_grade > 0 && 0 === $peer_grade ) {
			$final_grade = $teacher_grade;
		}

		update_post_meta( $submission_id, '_clms_peer_grade',     $peer_grade );
		update_post_meta( $submission_id, '_clms_peer_scores',    $avg_scores );
		update_post_meta( $submission_id, '_clms_peer_grade_at',  gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $submission_id, '_clms_final_grade',    $final_grade );

		self::score_consistency_for_submission( $submission_id, $peer_grade, $lesson_id );
	}

	public static function audit( string $event_type, array $payload ): void {
		global $wpdb;

		$event_type = sanitize_key( $event_type );
		if ( '' === $event_type ) {
			return;
		}

		$table = $wpdb->prefix . 'clms_peer_review_audit_log';
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( ! $exists ) {
			return;
		}

		$lesson_id     = absint( $payload['lesson_id'] ?? 0 );
		$submission_id = absint( $payload['submission_id'] ?? 0 );
		$assignment_id = absint( $payload['assignment_id'] ?? 0 );
		$reviewer_id   = absint( $payload['reviewer_id'] ?? 0 );
		$reviewee_id   = absint( $payload['reviewee_id'] ?? 0 );
		$actor_id      = absint( $payload['actor_id'] ?? 0 );
		$meta          = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : array();
		if ( $lesson_id && ! array_key_exists( 'is_blind', $meta ) ) {
			$meta['is_blind'] = (bool) get_post_meta( $lesson_id, '_clms_peer_review_blind', true );
		}

		$wpdb->insert(
			$table,
			array(
				'event_type'    => $event_type,
				'lesson_id'     => $lesson_id,
				'submission_id' => $submission_id,
				'assignment_id' => $assignment_id,
				'reviewer_id'   => $reviewer_id,
				'reviewee_id'   => $reviewee_id,
				'actor_id'      => $actor_id,
				'meta_json'     => $meta ? wp_json_encode( $meta ) : null,
				'created_at'    => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Shortcode para renderizar módulo de entrenamiento independiente.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public function render_training_module( $atts ) {
		unset( $atts );
		if ( ! is_user_logged_in() ) {
			return '<p class="atora-notice">' . esc_html__( 'Debes iniciar sesión para acceder al entrenamiento.', 'atora-lms' ) . '</p>';
		}

		ob_start();
		echo '<div class="clms-pr-inbox">';
		echo '<h2 class="clms-pr-inbox__title">' . esc_html__( 'Entrenamiento para revisión entre pares', 'atora-lms' ) . '</h2>';
		echo $this->render_training_notice_card();
		echo '</div>';
		$this->render_inline_styles();
		return ob_get_clean();
	}

	/**
	 * Tarjeta visual de entrenamiento para revisión entre pares.
	 *
	 * @return string
	 */
	protected function render_training_notice_card() {
		ob_start();
		?>
		<div class="clms-pr-training" data-clms-pr-training-card="1">
			<h3 class="clms-pr-training__title"><?php esc_html_e( 'Antes de revisar: entrenamiento rápido', 'atora-lms' ); ?></h3>
			<p class="clms-pr-training__text"><?php esc_html_e( 'Una revisión útil es clara, respetuosa y conectada a la rúbrica. Completa este checklist antes de enviar evaluaciones.', 'atora-lms' ); ?></p>
			<ul class="clms-pr-training__list">
				<li><?php esc_html_e( 'Reviso cada criterio de la rúbrica y justifico mis puntajes.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Incluyo al menos una fortaleza y un aspecto a mejorar.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Propongo una acción concreta para la siguiente entrega.', 'atora-lms' ); ?></li>
				<li><?php esc_html_e( 'Evito comentarios ambiguos o descalificadores.', 'atora-lms' ); ?></li>
			</ul>
			<div class="clms-pr-training__actions">
				<button type="button" class="atora-btn atora-btn-secondary clms-pr-training-btn"><?php esc_html_e( 'Completar entrenamiento', 'atora-lms' ); ?></button>
				<span class="clms-pr-training__msg" aria-live="polite"></span>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Indica si lección exige entrenamiento para revisar.
	 *
	 * @param int $lesson_id Lección.
	 * @return bool
	 */
	public static function is_training_required_for_lesson( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return false;
		}

		$required = get_post_meta( $lesson_id, '_clms_peer_review_training_required', true );
		return in_array( (string) $required, array( '1', 'yes', 'true' ), true );
	}

	/**
	 * Estado de entrenamiento del revisor.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,mixed>
	 */
	public static function get_reviewer_training_status( $user_id ) {
		$user_id = absint( $user_id );
		$status  = get_user_meta( $user_id, '_clms_peer_reviewer_training', true );
		$status  = is_array( $status ) ? $status : array();

		return array(
			'completed'    => ! empty( $status['completed'] ),
			'completed_at' => isset( $status['completed_at'] ) ? sanitize_text_field( (string) $status['completed_at'] ) : '',
			'version'      => isset( $status['version'] ) ? absint( $status['version'] ) : 1,
		);
	}

	/**
	 * Determina si el revisor completó entrenamiento.
	 *
	 * @param int $user_id Usuario.
	 * @return bool
	 */
	public static function is_reviewer_trained( $user_id ) {
		$status = self::get_reviewer_training_status( $user_id );
		return ! empty( $status['completed'] );
	}

	/**
	 * Marca entrenamiento como completado.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string,mixed>
	 */
	public static function mark_reviewer_training_completed( $user_id ) {
		$user_id = absint( $user_id );
		$status  = array(
			'completed'    => true,
			'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			'version'      => 1,
		);

		update_user_meta( $user_id, '_clms_peer_reviewer_training', $status );
		do_action( 'clms_peer_reviewer_training_completed', $user_id, $status );

		return $status;
	}

	/**
	 * Calcula calidad de revisión entre pares.
	 *
	 * @param int   $assignment_id Asignación.
	 * @param array $scores        Puntajes enviados.
	 * @param string $comment      Comentario global.
	 * @return array<string,mixed>
	 */
	public static function calculate_quality_score( $assignment_id, $scores = array(), $comment = '' ) {
		$assignment_id = absint( $assignment_id );
		$scores        = is_array( $scores ) ? $scores : array();
		$comment       = sanitize_textarea_field( (string) $comment );

		$quality = array(
			'score'  => 0,
			'status' => 'low',
			'flags'  => array(),
		);

		if ( ! $assignment_id ) {
			return $quality;
		}

		$coverage_score = ! empty( $scores ) ? 40 : 0;
		$comment_length = function_exists( 'mb_strlen' ) ? mb_strlen( trim( $comment ) ) : strlen( trim( $comment ) );
		$depth_score    = 0;
		if ( $comment_length >= 180 ) {
			$depth_score = 35;
		} elseif ( $comment_length >= 90 ) {
			$depth_score = 24;
		} elseif ( $comment_length >= 40 ) {
			$depth_score = 14;
		}

		$timeliness_score = 0;
		$lesson_id        = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
		$submitted_at_raw = (string) get_post_meta( $assignment_id, '_clms_pr_submitted_at', true );
		$submitted_ts     = $submitted_at_raw ? strtotime( $submitted_at_raw ) : 0;
		$due_date         = $lesson_id ? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' ) : '';
		$due_time         = $lesson_id ? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' ) : '';
		$due_ts           = '' !== trim( $due_date ) ? strtotime( trim( $due_date . ' ' . $due_time ) ) : 0;

		if ( $submitted_ts ) {
			if ( ! $due_ts || $submitted_ts <= $due_ts ) {
				$timeliness_score = 25;
			} else {
				$timeliness_score = 10;
				$quality['flags'][] = __( 'Revisión entregada fuera de plazo.', 'atora-lms' );
			}
		}

		if ( empty( $scores ) ) {
			$quality['flags'][] = __( 'La revisión no incluyó puntajes por criterio.', 'atora-lms' );
		}
		if ( $comment_length < 40 ) {
			$quality['flags'][] = __( 'El comentario es demasiado breve para orientar mejoras.', 'atora-lms' );
		}

		$total = max( 0, min( 100, $coverage_score + $depth_score + $timeliness_score ) );
		$status = 'low';
		if ( $total >= 85 ) {
			$status = 'high';
		} elseif ( $total >= 60 ) {
			$status = 'medium';
		}

		$quality['score']  = $total;
		$quality['status'] = $status;
		$quality['flags']  = array_values( array_unique( array_map( 'sanitize_text_field', (array) $quality['flags'] ) ) );

		return $quality;
	}

	/**
	 * Resumen de revisiones del usuario.
	 *
	 * @param int $reviewer_id Revisor.
	 * @return array<string,mixed>
	 */
	public static function get_reviewer_summary( $reviewer_id ) {
		$reviewer_id = absint( $reviewer_id );
		if ( ! $reviewer_id ) {
			return array(
				'pending'      => 0,
				'completed'    => 0,
				'avg_quality'  => 0,
				'training'     => self::get_reviewer_training_status( 0 ),
			);
		}

		$assignments = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_clms_pr_reviewer_id',
						'value' => $reviewer_id,
						'type'  => 'NUMERIC',
					),
				),
			)
		);

		$pending = 0;
		$done    = 0;
		$quality_scores = array();
		foreach ( $assignments as $assignment_id ) {
			$status = (string) get_post_meta( $assignment_id, '_clms_pr_status', true );
			if ( 'completed' === $status ) {
				++$done;
				$quality = get_post_meta( $assignment_id, '_clms_pr_quality_score', true );
				if ( is_numeric( $quality ) ) {
					$quality_scores[] = (float) $quality;
				}
			} else {
				++$pending;
			}
		}

		$avg_quality = ! empty( $quality_scores ) ? round( array_sum( $quality_scores ) / count( $quality_scores ), 1 ) : 0;

		return array(
			'pending'     => $pending,
			'completed'   => $done,
			'avg_quality' => $avg_quality,
			'training'    => self::get_reviewer_training_status( $reviewer_id ),
		);
	}

	/**
	 * Analítica inicial de efectividad de peer review.
	 *
	 * @param array $args Argumentos.
	 * @return array<string,mixed>
	 */
	public static function get_effectiveness_analytics( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		$limit = max( 1, min( 500, absint( $args['limit'] ?? 200 ) ) );

		$posts = get_posts(
			array(
				'post_type'      => 'clms_peer_review',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$reviewer_counts = array();
		$quality_by_reviewer = array();
		$late_reviews = 0;
		$useful_reviews = 0;
		$by_lesson_quality = array();

		foreach ( $posts as $assignment_id ) {
			$reviewer_id = absint( get_post_meta( $assignment_id, '_clms_pr_reviewer_id', true ) );
			$lesson_id   = absint( get_post_meta( $assignment_id, '_clms_pr_lesson_id', true ) );
			$status      = sanitize_key( (string) get_post_meta( $assignment_id, '_clms_pr_status', true ) );

			if ( ! $reviewer_id || 'completed' !== $status ) {
				continue;
			}

			if ( ! isset( $reviewer_counts[ $reviewer_id ] ) ) {
				$reviewer_counts[ $reviewer_id ] = 0;
			}
			++$reviewer_counts[ $reviewer_id ];

			$quality_score = get_post_meta( $assignment_id, '_clms_pr_quality_score', true );
			if ( is_numeric( $quality_score ) ) {
				if ( ! isset( $quality_by_reviewer[ $reviewer_id ] ) ) {
					$quality_by_reviewer[ $reviewer_id ] = array();
				}
				$quality_by_reviewer[ $reviewer_id ][] = (float) $quality_score;

				if ( ! isset( $by_lesson_quality[ $lesson_id ] ) ) {
					$by_lesson_quality[ $lesson_id ] = array();
				}
				$by_lesson_quality[ $lesson_id ][] = (float) $quality_score;
			}

			$flags = (array) get_post_meta( $assignment_id, '_clms_pr_quality_flags', true );
			$joined_flags = strtolower( implode( ' ', array_map( 'sanitize_text_field', $flags ) ) );
			if ( false !== strpos( $joined_flags, 'fuera de plazo' ) ) {
				++$late_reviews;
			}

			if ( '1' === (string) get_post_meta( $assignment_id, '_clms_pr_marked_useful', true ) ) {
				++$useful_reviews;
			}
		}

		arsort( $reviewer_counts );
		$top_active = array();
		foreach ( array_slice( $reviewer_counts, 0, 5, true ) as $reviewer_id => $count ) {
			$user = get_userdata( absint( $reviewer_id ) );
			$top_active[] = array(
				'reviewer_id'   => absint( $reviewer_id ),
				'reviewer_name' => $user ? sanitize_text_field( $user->display_name ) : __( 'Revisor', 'atora-lms' ),
				'reviews'       => absint( $count ),
				'avg_quality'   => ! empty( $quality_by_reviewer[ $reviewer_id ] ) ? round( array_sum( $quality_by_reviewer[ $reviewer_id ] ) / count( $quality_by_reviewer[ $reviewer_id ] ), 1 ) : 0,
			);
		}

		$lessons = array();
		foreach ( $by_lesson_quality as $lesson_id => $scores ) {
			$lesson = get_post( $lesson_id );
			$lessons[] = array(
				'lesson_id'    => absint( $lesson_id ),
				'lesson_title' => $lesson ? sanitize_text_field( $lesson->post_title ) : __( 'Actividad', 'atora-lms' ),
				'avg_quality'  => round( array_sum( $scores ) / count( $scores ), 1 ),
				'reviews'      => count( $scores ),
			);
		}

		usort(
			$lessons,
			static function ( $a, $b ) {
				return (float) $b['avg_quality'] <=> (float) $a['avg_quality'];
			}
		);

		return array(
			'total_completed'   => array_sum( $reviewer_counts ),
			'late_reviews'      => $late_reviews,
			'useful_reviews'    => $useful_reviews,
			'top_reviewers'     => $top_active,
			'lesson_quality'    => array_slice( $lessons, 0, 8 ),
		);
	}

	protected function render_assignment_card( WP_Post $assignment, $readonly ) {
		$lesson_id     = absint( get_post_meta( $assignment->ID, '_clms_pr_lesson_id',     true ) );
		$submission_id = absint( get_post_meta( $assignment->ID, '_clms_pr_submission_id', true ) );
		$rubric_id     = absint( get_post_meta( $lesson_id, '_clms_rubric_id', true ) );
		$is_calibration = '1' === (string) get_post_meta( $assignment->ID, '_clms_pr_is_calibration', true );
		$lesson        = get_post( $lesson_id );
		$submission    = get_post( $submission_id );
		$lesson_title  = $lesson ? esc_html( $lesson->post_title ) : sprintf(
			/* translators: %d: lesson id */
			esc_html__( 'Lección #%d', 'atora-lms' ),
			$lesson_id
		);
		$sub_content   = $submission ? wp_kses_post( $submission->post_content ) : '';
		$status_label  = $readonly ? __( 'Completada', 'atora-lms' ) : __( 'Pendiente', 'atora-lms' );
		$status_class  = $readonly ? 'is-done' : 'is-pending';

		ob_start();
		?>
		<div class="clms-pr-card <?php echo esc_attr( $status_class ); ?>" id="clms-pr-card-<?php echo esc_attr( $assignment->ID ); ?>">
			<div class="clms-pr-card__header">
				<span class="clms-pr-card__lesson"><?php echo $lesson_title; ?></span>
				<?php if ( $is_calibration ) : ?>
					<span class="clms-pr-card__badge" style="background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe"><?php esc_html_e( 'Calibración', 'atora-lms' ); ?></span>
				<?php endif; ?>
				<span class="clms-pr-card__badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
			</div>

			<?php if ( $sub_content ) : ?>
			<div class="clms-pr-card__submission">
				<strong><?php echo esc_html( $is_calibration ? __( 'Ejemplar de calibración:', 'atora-lms' ) : __( 'Entrega del estudiante:', 'atora-lms' ) ); ?></strong>
				<div class="clms-pr-card__submission-body"><?php echo $sub_content; ?></div>
			</div>
			<?php endif; ?>

			<?php if ( ! $readonly ) : ?>
			<form class="clms-pr-form" data-assignment="<?php echo esc_attr( $assignment->ID ); ?>">

				<?php if ( $rubric_id ) : ?>
					<?php
					$criteria  = class_exists( 'CLMS_Rubric' ) ? CLMS_Rubric::get_criteria( $rubric_id ) : (array) get_post_meta( $rubric_id, '_clms_rubric_criteria', true );
					$max_score = class_exists( 'CLMS_Rubric' ) ? CLMS_Rubric::get_total_points( $rubric_id ) : 100;
					if ( ! $max_score ) { $max_score = 100; }
					?>
					<p class="clms-pr-form__rubric-note"><?php echo esc_html( sprintf( __( 'Califica cada criterio según la rúbrica (máx. %d pts. total).', 'atora-lms' ), $max_score ) ); ?></p>
					<?php foreach ( $criteria as $idx => $criterion ) :
						$ckey = sanitize_key( $criterion['name'] );
						$max  = absint( $criterion['max_points'] );
					?>
					<div class="clms-pr-criterion">
						<label class="clms-pr-criterion__label">
							<?php echo esc_html( $criterion['name'] ); ?>
							<span class="clms-pr-criterion__max"><?php echo esc_html( sprintf( __( '(máx. %d pts.)', 'atora-lms' ), $max ) ); ?></span>
						</label>
						<?php if ( ! empty( $criterion['description'] ) ) : ?>
						<p class="clms-pr-criterion__desc"><?php echo esc_html( $criterion['description'] ); ?></p>
						<?php endif; ?>
						<?php if ( ! empty( $criterion['levels'] ) ) : ?>
						<div class="clms-pr-levels">
							<?php foreach ( $criterion['levels'] as $level ) : ?>
							<label class="clms-pr-level">
								<input type="radio" name="scores[<?php echo esc_attr( $ckey ); ?>]" value="<?php echo esc_attr( $level['points'] ); ?>" required>
								<span class="clms-pr-level__label"><?php echo esc_html( $level['label'] ); ?></span>
								<span class="clms-pr-level__pts"><?php echo esc_html( sprintf( __( '%d pts.', 'atora-lms' ), absint( $level['points'] ) ) ); ?></span>
								<?php if ( ! empty( $level['description'] ) ) : ?>
								<span class="clms-pr-level__desc"><?php echo esc_html( $level['description'] ); ?></span>
								<?php endif; ?>
							</label>
							<?php endforeach; ?>
						</div>
						<?php else : ?>
						<input type="number" name="scores[<?php echo esc_attr( $ckey ); ?>]" min="0" max="<?php echo esc_attr( $max ); ?>" placeholder="0–<?php echo esc_attr( $max ); ?>" class="clms-pr-score-input" required>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="clms-pr-criterion">
						<label class="clms-pr-criterion__label"><?php esc_html_e( 'Calificación (0–100)', 'atora-lms' ); ?></label>
						<input type="number" name="scores[overall]" min="0" max="100" placeholder="0–100" class="clms-pr-score-input" required>
					</div>
				<?php endif; ?>

				<div class="clms-pr-form__comment">
					<label for="clms-pr-comment-<?php echo esc_attr( $assignment->ID ); ?>" class="clms-pr-form__comment-label"><?php esc_html_e( 'Comentario general (opcional)', 'atora-lms' ); ?></label>
					<textarea id="clms-pr-comment-<?php echo esc_attr( $assignment->ID ); ?>" name="comment" rows="4" class="clms-pr-comment" placeholder="<?php echo esc_attr__( 'Escribe aquí tus observaciones...', 'atora-lms' ); ?>"></textarea>
				</div>

				<div class="clms-pr-form__actions">
					<button type="submit" class="atora-btn atora-btn-primary clms-pr-submit-btn">
						<?php esc_html_e( 'Enviar revisión', 'atora-lms' ); ?>
					</button>
					<span class="clms-pr-form__msg" aria-live="polite"></span>
				</div>
			</form>
			<?php else :
				$saved_scores  = (array) get_post_meta( $assignment->ID, '_clms_pr_scores',  true );
				$saved_comment = get_post_meta( $assignment->ID, '_clms_pr_comment', true );
				$submitted_at  = get_post_meta( $assignment->ID, '_clms_pr_submitted_at', true );
				$cal_status    = sanitize_key( (string) get_post_meta( $assignment->ID, '_clms_pr_calibration_status', true ) );
				$cal_delta     = get_post_meta( $assignment->ID, '_clms_pr_calibration_delta', true );
				$cal_score     = get_post_meta( $assignment->ID, '_clms_pr_calibration_score', true );
				$con_delta     = get_post_meta( $assignment->ID, '_clms_pr_consistency_delta', true );
				$con_flag      = sanitize_key( (string) get_post_meta( $assignment->ID, '_clms_pr_consistency_flag', true ) );
			?>
			<div class="clms-pr-result">
				<p class="clms-pr-result__date"><?php echo esc_html( sprintf( __( 'Enviada el %s', 'atora-lms' ), date_i18n( 'd/m/Y H:i', strtotime( $submitted_at ) ) ) ); ?></p>
				<?php if ( $is_calibration ) : ?>
					<p class="description">
						<?php
						$delta_i = ( '' !== (string) $cal_delta && is_numeric( $cal_delta ) ) ? (int) $cal_delta : null;
						$score_i = ( '' !== (string) $cal_score && is_numeric( $cal_score ) ) ? absint( $cal_score ) : null;
						$label = $cal_status ? $cal_status : '—';
						if ( null !== $delta_i ) {
							$label .= ' (Δ ' . ( $delta_i > 0 ? '+' : '' ) . (string) $delta_i . ')';
						}
						if ( null !== $score_i ) {
							$label .= ' — ' . (string) $score_i . '/100';
						}
						echo esc_html__( 'Calibración:', 'atora-lms' ) . ' <strong>' . esc_html( $label ) . '</strong>';
						?>
					</p>
				<?php else : ?>
					<?php if ( '' !== (string) $con_delta && is_numeric( $con_delta ) ) : ?>
						<p class="description">
							<?php
							$delta_i = (int) $con_delta;
							$flag    = $con_flag ? $con_flag : '—';
							echo esc_html__( 'Consistencia:', 'atora-lms' ) . ' <strong>' . esc_html( $flag ) . '</strong> ';
							echo '<span class="description">Δ ' . esc_html( ( $delta_i > 0 ? '+' : '' ) . (string) $delta_i ) . '</span>';
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( ! empty( $saved_scores ) ) : ?>
				<ul class="clms-pr-result__scores">
					<?php foreach ( $saved_scores as $crit => $pts ) : ?>
					<li><strong><?php echo esc_html( $crit ); ?>:</strong> <?php echo esc_html( $pts ); ?> pts.</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php if ( $saved_comment ) : ?>
				<blockquote class="clms-pr-result__comment"><?php echo esc_html( $saved_comment ); ?></blockquote>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function render_inline_styles() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
		.clms-pr-inbox { max-width: 860px; margin: 0 auto; font-family: inherit; }
		.clms-pr-inbox__title { font-size: 1.5rem; font-weight: 700; margin-bottom: 1.5rem; }
		.clms-pr-inbox__section-title { font-size: 1.1rem; font-weight: 600; margin: 2rem 0 1rem; color: #374151; }
		.clms-pr-inbox__section-title--done { color: #6b7280; }
		.clms-pr-inbox__empty { color: #6b7280; }

		.clms-pr-cards { display: flex; flex-direction: column; gap: 1.5rem; }

		.clms-pr-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
		.clms-pr-card.is-done { opacity: .75; }
		.clms-pr-card__header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; }
		.clms-pr-card__lesson { font-weight: 600; font-size: 1rem; }
		.clms-pr-card__badge { font-size: .75rem; font-weight: 600; padding: .25em .75em; border-radius: 99px; }
		.clms-pr-card__badge.is-pending { background: #fef3c7; color: #92400e; }
		.clms-pr-card__badge.is-done { background: #d1fae5; color: #065f46; }

		.clms-pr-card__submission { background: #f9fafb; border-left: 4px solid #e5e7eb; padding: 1rem; border-radius: 0 8px 8px 0; margin-bottom: 1.25rem; font-size: .9rem; }
		.clms-pr-card__submission-body { margin-top: .5rem; max-height: 200px; overflow-y: auto; }

		.clms-pr-criterion { margin-bottom: 1.25rem; }
		.clms-pr-criterion__label { font-weight: 600; display: block; margin-bottom: .35rem; }
		.clms-pr-criterion__max { font-weight: 400; color: #6b7280; font-size: .85em; }
		.clms-pr-criterion__desc { font-size: .85rem; color: #6b7280; margin-bottom: .5rem; }

		.clms-pr-levels { display: flex; flex-direction: column; gap: .5rem; }
		.clms-pr-level { display: grid; grid-template-columns: auto 1fr auto; align-items: start; gap: .5rem; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem 1rem; cursor: pointer; transition: border-color .15s; }
		.clms-pr-level:has(input:checked) { border-color: #4353ff; background: #eef0ff; }
		.clms-pr-level input[type="radio"] { margin-top: .15rem; }
		.clms-pr-level__label { font-weight: 600; font-size: .9rem; }
		.clms-pr-level__pts { font-weight: 700; color: #4353ff; font-size: .9rem; white-space: nowrap; }
		.clms-pr-level__desc { font-size: .8rem; color: #6b7280; grid-column: 2; }

		.clms-pr-score-input { width: 100%; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; }
		.clms-pr-form__comment { margin-top: 1rem; }
		.clms-pr-form__comment-label { font-weight: 600; display: block; margin-bottom: .35rem; }
		.clms-pr-comment { width: 100%; padding: .75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: .9rem; resize: vertical; }
		.clms-pr-form__actions { display: flex; align-items: center; gap: 1rem; margin-top: 1.25rem; }
		.clms-pr-form__msg { font-size: .9rem; color: #065f46; }
		.clms-pr-form__msg.is-error { color: #991b1b; }
		.clms-pr-form__rubric-note { font-size: .85rem; color: #6b7280; margin-bottom: 1rem; }

		.clms-pr-result__date { font-size: .85rem; color: #6b7280; margin-bottom: .75rem; }
		.clms-pr-result__scores { list-style: none; margin: 0 0 .75rem; padding: 0; display: flex; flex-wrap: wrap; gap: .5rem; }
		.clms-pr-result__scores li { background: #f3f4f6; padding: .3em .75em; border-radius: 99px; font-size: .85rem; }
		.clms-pr-result__comment { border-left: 3px solid #d1d5db; padding-left: .75rem; color: #374151; font-style: italic; margin: 0; }
		.clms-pr-training { border: 1px solid #dbeafe; background: #eff6ff; border-radius: 12px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
		.clms-pr-training__title { margin: 0 0 .5rem; font-size: 1rem; color: #1e3a8a; }
		.clms-pr-training__text { margin: 0 0 .75rem; color: #1f2937; font-size: .9rem; }
		.clms-pr-training__list { margin: 0 0 .75rem 1rem; padding: 0; color: #1f2937; font-size: .88rem; }
		.clms-pr-training__actions { display: flex; align-items: center; gap: .75rem; }
		.clms-pr-training__msg { font-size: .85rem; color: #065f46; }
		.clms-pr-training__msg.is-error { color: #991b1b; }
		</style>
		<?php
	}
}
