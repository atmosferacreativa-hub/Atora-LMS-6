<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Quiz {

	const SHORTCODE             = 'clms_quiz';
	const QUIZ_META_KEY         = '_clms_quiz_questions';
	const QUIZ_ENABLED_META_KEY = '_clms_quiz_enabled';

	const MAX_ANSWER_ITEMS    = 60;
	const MAX_TEXT_ANSWER_LEN = 2000;
	const SUBMIT_LOCK_WINDOW  = 15;
	const QUESTION_TOKEN_TTL  = 3600;
	const TIMER_GRACE_SECONDS = 30;

	protected static $assets_enqueued = false;

	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_quiz_shortcode' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_clms_submit_quiz', array( $this, 'ajax_submit_quiz' ) );
	}

	/* ---------------------------------------------------------------
	   ASSETS
	--------------------------------------------------------------- */

	public function register_assets() {
		wp_register_script( 'clms-quiz', false, array(), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0', true );
		wp_register_style( 'clms-quiz', false, array( 'clms-ui' ), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
	}

	protected function enqueue_assets() {
		wp_enqueue_script( 'clms-quiz' );
		wp_enqueue_style( 'clms-quiz' );

		if ( self::$assets_enqueued ) {
			return;
		}

		wp_add_inline_style( 'clms-quiz', $this->get_inline_css() );
		wp_add_inline_script( 'clms-quiz', $this->get_inline_js() );

		self::$assets_enqueued = true;
	}

	protected function get_inline_css() {
		return '
		.clms-ui,.clms-ui *{box-sizing:border-box}
		.clms-quiz-wrap{margin:20px 0}
		.clms-quiz-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:20px}
		.clms-quiz-title{margin:0 0 14px;line-height:1.2}
		.clms-quiz-timer{display:inline-flex;gap:8px;align-items:center;padding:8px 12px;border-radius:999px;background:#eef2ff;color:#3730a3;font-weight:600;margin-bottom:14px}
		.clms-quiz-timer.is-urgent{background:#fef2f2;color:#991b1b}
		.clms-quiz-list{display:grid;gap:14px}
		.clms-quiz-question{padding:14px;border:1px solid #e5e7eb;border-radius:12px;background:#fff}
		.clms-quiz-question-title{font-weight:700;margin-bottom:10px;line-height:1.5;color:#111827}
		.clms-quiz-question .clms-quiz-option{display:flex;align-items:flex-start;gap:8px;margin:8px 0;line-height:1.5;padding:8px 10px;border-radius:10px;border:1px solid #e5e7eb;background:#f8fafc}
		.clms-quiz-question .clms-quiz-option input{margin:2px 0 0;flex:0 0 auto}
		.clms-quiz-textarea,
		.clms-quiz-text,
		.clms-quiz-number{width:100%;max-width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:10px}
		.clms-quiz-actions{margin-top:16px}
		.clms-quiz-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:10px;background:#111827;color:#fff;border:1px solid #111827;cursor:pointer}
		.clms-quiz-btn:hover{background:#0f172a;border-color:#0f172a}
		.clms-quiz-message{margin-top:12px;font-size:14px;line-height:1.45;display:none}
		.clms-quiz-message:not(:empty){display:block}
		.clms-quiz-message.is-success{color:#065f46}
		.clms-quiz-message.is-error{color:#991b1b}
		.clms-quiz-result{margin-bottom:16px;padding:14px 16px;border-radius:12px;background:#f8fafc;border:1px solid #e5e7eb}
		.clms-quiz-result-score{font-size:18px;font-weight:700;margin-bottom:8px}
		.clms-quiz-result-meta{font-size:13px;line-height:1.5;color:#334155}
		.clms-quiz-feedback{margin-top:10px;line-height:1.6}
		.clms-quiz-guidance{margin-top:12px;padding:12px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;line-height:1.55}
		.clms-quiz-guidance--warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}
		.clms-quiz-guidance--danger{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
		.clms-quiz-breakdown-wrap{margin-top:12px;border:1px solid #e5e7eb;border-radius:10px;background:#fff}
		.clms-quiz-breakdown-toggle{padding:10px 12px;cursor:pointer;font-weight:600;color:#1f2937}
		.clms-quiz-breakdown{padding:0 12px 10px}
		.clms-quiz-breakdown-item{padding:10px 0;border-top:1px solid #e5e7eb}
		.clms-quiz-breakdown-item:first-child{border-top:0}
		.clms-quiz-answer-ok{color:#065f46;font-weight:600}
		.clms-quiz-answer-bad{color:#991b1b;font-weight:600}
		.clms-quiz-form.is-collapsed{display:none}
		.clms-quiz-retry{margin-top:12px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
		.clms-quiz-retry-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 14px;border-radius:10px;background:#1d4ed8;color:#fff;border:1px solid #1d4ed8;font-weight:600;cursor:pointer}
		.clms-quiz-retry-btn:hover{background:#1e40af;border-color:#1e40af}
		.clms-quiz-retry-note{font-size:13px;color:#334155;line-height:1.45}
		@media (max-width: 782px){
			.clms-quiz-card{padding:14px}
			.clms-quiz-question{padding:12px}
			.clms-quiz-question .clms-quiz-option{padding:8px}
		}
		';
	}

	protected function get_inline_js() {
		return "
		document.addEventListener('submit', function(e){
			var form = e.target.closest('.clms-quiz-form');
			if (!form) { return; }

			e.preventDefault();

			var message = form.querySelector('.clms-quiz-message');
			var btn = form.querySelector('button[type=\"submit\"]');

			if (message) {
				message.className = 'clms-quiz-message';
				message.textContent = 'Enviando evaluación...';
			}

			if (btn) {
				btn.disabled = true;
			}

			var formData = new FormData(form);

			fetch('" . esc_js( admin_url( 'admin-ajax.php' ) ) . "', {
				method: 'POST',
				credentials: 'same-origin',
				body: formData
			})
			.then(function(res){ return res.json(); })
			.then(function(json){
				if (!json || !message) {
					if (btn) { btn.disabled = false; }
					return;
				}

				if (json.success) {
					message.className = 'clms-quiz-message is-success';
					message.textContent = json.data && json.data.message ? json.data.message : 'Evaluación enviada correctamente.';

					if (json.data && json.data.html) {
						var wrap = form.closest('.clms-quiz-card');
						if (wrap) {
							wrap.querySelectorAll('.clms-quiz-result').forEach(function(node){ node.remove(); });
							form.insertAdjacentHTML('beforebegin', json.data.html);
						}
					}
					if (json.data && json.data.allow_retry) {
						form.reset();
						form.classList.add('is-collapsed');
						form.setAttribute('hidden', 'hidden');
						if (message) {
							message.className = 'clms-quiz-message';
							message.textContent = '';
						}
						if (btn) { btn.disabled = false; }
					} else {
						form.remove();
					}
				} else {
					message.className = 'clms-quiz-message is-error';
					message.textContent = json.data && json.data.message ? json.data.message : 'No se pudo enviar la evaluación.';
					if (btn) { btn.disabled = false; }
				}
			})
			.catch(function(){
				if (message) {
					message.className = 'clms-quiz-message is-error';
					message.textContent = 'Error de conexión.';
				}
				if (btn) { btn.disabled = false; }
			});
		});

		document.addEventListener('click', function(e){
			var retryBtn = e.target.closest('.clms-quiz-retry-btn');
			if (!retryBtn) { return; }
			e.preventDefault();

			var card = retryBtn.closest('.clms-quiz-card');
			if (!card) { return; }
			var form = card.querySelector('.clms-quiz-form');
			if (!form) { return; }

			form.reset();
			form.classList.remove('is-collapsed');
			form.removeAttribute('hidden');

			var message = form.querySelector('.clms-quiz-message');
			if (message) {
				message.className = 'clms-quiz-message';
				message.textContent = '';
			}

			var firstField = form.querySelector('input:not([type=\"hidden\"]), textarea, select');
			if (firstField && typeof firstField.focus === 'function') {
				firstField.focus();
			}

			form.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});

		document.addEventListener('DOMContentLoaded', function(){
			document.querySelectorAll('.clms-quiz-form.is-collapsed').forEach(function(form){
				if (!form.hasAttribute('hidden')) {
					form.setAttribute('hidden', 'hidden');
				}
			});

			var timers = document.querySelectorAll('.clms-quiz-timer[data-seconds]');

			timers.forEach(function(timer){
				var display = timer.querySelector('.clms-quiz-timer-display');
				var seconds = parseInt(timer.getAttribute('data-seconds'), 10) || 0;

				function render(){
					var min = Math.floor(seconds / 60);
					var sec = seconds % 60;

					if (display) {
						display.textContent = String(min).padStart(2, '0') + ':' + String(sec).padStart(2, '0');
					}

					if (seconds <= 60) {
						timer.classList.add('is-urgent');
					}
				}

				render();

				if (seconds <= 0) {
					return;
				}

				var interval = setInterval(function(){
					seconds--;
					render();

					if (seconds <= 0) {
						clearInterval(interval);
						var card = timer.closest('.clms-quiz-card');
						if (!card) { return; }
						var quizForm = card.querySelector('.clms-quiz-form');
						if (quizForm) {
							quizForm.requestSubmit();
						}
					}
				}, 1000);
			});
		});
		";
	}

	/* ---------------------------------------------------------------
	   SHORTCODE
	--------------------------------------------------------------- */

	public function render_quiz_shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			$this->enqueue_assets();
			return '<div class="clms-ui clms-quiz-wrap"><div class="clms-quiz-card"><p>Debes iniciar sesión para responder esta evaluación.</p></div></div>';
		}

		$atts = shortcode_atts(
			array(
				'lesson_id'     => 0,
				'show_result'   => 1,
				'show_feedback' => 1,
				'allow_retry'   => 1,
			),
			(array) $atts,
			self::SHORTCODE
		);

		$lesson_id = absint( $atts['lesson_id'] );

		if ( ! $lesson_id && is_singular( 'lm_lesson' ) ) {
			$lesson_id = get_the_ID();
		}

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			$this->enqueue_assets();
			return '<div class="clms-ui clms-quiz-wrap"><div class="clms-quiz-card"><p>No se encontró una lección válida para esta evaluación.</p></div></div>';
		}

		$user_id = get_current_user_id();

		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			$this->enqueue_assets();
			return '<div class="clms-ui clms-quiz-wrap"><div class="clms-quiz-card"><p>No tienes acceso a esta lección.</p></div></div>';
		}

		if ( ! $this->is_quiz_enabled( $lesson_id ) ) {
			$this->enqueue_assets();
			return '<div class="clms-ui clms-quiz-wrap"><div class="clms-quiz-card"><p>Esta evaluación no está habilitada todavía.</p></div></div>';
		}

		$date_check = $this->validate_availability_window( $lesson_id );
		if ( true !== $date_check ) {
			$this->enqueue_assets();
			return '<div class="clms-ui clms-quiz-wrap"><div class="clms-quiz-card"><p>' . esc_html( $date_check ) . '</p></div></div>';
		}

		$all_questions = $this->get_questions( $lesson_id );

		if ( empty( $all_questions ) ) {
			$this->enqueue_assets();
			return '<div class="clms-ui clms-quiz-wrap"><div class="clms-quiz-card"><p>Esta evaluación no tiene preguntas válidas.</p></div></div>';
		}

		$attempt       = $this->get_quiz_attempt( $user_id, $lesson_id );
		$can_retry     = ! empty( $atts['allow_retry'] ) ? $this->can_retry_quiz( $lesson_id, $attempt ) : empty( $attempt );
		$show_result   = ! empty( $atts['show_result'] );
		$show_feedback = ! empty( $atts['show_feedback'] );
		$show_details  = $this->should_show_result_details( $lesson_id );
		$retry_context = $this->get_quiz_retry_context( $lesson_id, $attempt );
		$collapse_form = ! empty( $attempt ) && $can_retry && $show_result;

		$selection  = $this->select_questions_for_render( $user_id, $lesson_id, $all_questions );
		$questions  = $selection['questions'];
		$quiz_token = $selection['token'];

		$time_limit        = absint( get_post_meta( $lesson_id, '_lm_quiz_time_limit', true ) );
		$remaining_seconds = 0;

		if ( $time_limit > 0 && $can_retry ) {
			$start             = $this->get_or_create_quiz_start( $user_id, $lesson_id, $time_limit * 60 );
			$elapsed           = max( 0, time() - $start );
			$remaining_seconds = max( 0, ( $time_limit * 60 ) - $elapsed );
		}

		$this->enqueue_assets();

		ob_start();
		?>
		<div class="clms-ui clms-quiz-wrap">
			<div class="clms-quiz-card">
				<h3 class="clms-quiz-title">Evaluación</h3>

				<?php if ( $time_limit > 0 && $can_retry ) : ?>
					<div class="clms-quiz-timer <?php echo $remaining_seconds < 60 ? 'is-urgent' : ''; ?>" data-seconds="<?php echo esc_attr( $remaining_seconds ); ?>" aria-live="polite">
						<span class="clms-quiz-timer-label">Tiempo restante:</span>
						<span class="clms-quiz-timer-display">--:--</span>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $attempt ) && $show_result ) : ?>
					<?php echo $this->render_result_box( $attempt, $show_feedback && $show_details, $lesson_id ); ?>
				<?php endif; ?>

				<?php if ( ! $can_retry ) : ?>
					<p><?php echo esc_html( $this->build_quiz_no_retry_message( $retry_context ) ); ?></p>
				<?php else : ?>
					<form class="clms-quiz-form<?php echo $collapse_form ? ' is-collapsed' : ''; ?>" method="post" data-lesson-id="<?php echo esc_attr( $lesson_id ); ?>"<?php echo $collapse_form ? ' hidden' : ''; ?>>
						<input type="hidden" name="action" value="clms_submit_quiz">
						<input type="hidden" name="lesson_id" value="<?php echo esc_attr( $lesson_id ); ?>">
						<input type="hidden" name="clms_quiz_token" value="<?php echo esc_attr( $quiz_token ); ?>">
						<?php wp_nonce_field( 'clms_submit_quiz_' . $lesson_id, 'clms_quiz_nonce' ); ?>

						<div class="clms-quiz-list">
							<?php foreach ( $questions as $index => $question ) : ?>
								<div class="clms-quiz-question">
									<div class="clms-quiz-question-title">
										<?php echo esc_html( ( $index + 1 ) . '. ' . $this->get_question_text( $question ) ); ?>
									</div>

									<?php echo $this->render_question_input( $question, $index ); ?>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="clms-quiz-actions">
							<button type="submit" class="clms-quiz-btn">Enviar evaluación</button>
						</div>

						<div class="clms-quiz-message" aria-live="polite"></div>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return ob_get_clean();
	}

	/* ---------------------------------------------------------------
	   AJAX
	--------------------------------------------------------------- */

	public function ajax_submit_quiz() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Debes iniciar sesión.', 'atora-lms' ) ), 403 );
		}

		$lesson_id = isset( $_POST['lesson_id'] ) ? absint( wp_unslash( $_POST['lesson_id'] ) ) : 0;
		$user_id   = get_current_user_id();

		if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Lección inválida.', 'atora-lms' ) ), 400 );
		}

		$nonce = isset( $_POST['clms_quiz_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_quiz_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'clms_submit_quiz_' . $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Solicitud inválida.', 'atora-lms' ) ), 403 );
		}

		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes acceso.', 'atora-lms' ) ), 403 );
		}

		if ( ! $this->is_quiz_enabled( $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Quiz no habilitado.', 'atora-lms' ) ), 400 );
		}

		$date_check = $this->validate_availability_window( $lesson_id );
		if ( true !== $date_check ) {
			wp_send_json_error( array( 'message' => $date_check ), 400 );
		}

		$time_check = $this->validate_time_limit( $user_id, $lesson_id );
		if ( is_wp_error( $time_check ) ) {
			wp_send_json_error( array( 'message' => $time_check->get_error_message() ), 400 );
		}

		$quiz_token      = isset( $_POST['clms_quiz_token'] ) ? sanitize_text_field( wp_unslash( $_POST['clms_quiz_token'] ) ) : '';
		$token_questions = $quiz_token ? $this->get_questions_by_token( $user_id, $lesson_id, $quiz_token ) : array();
		$questions       = ! empty( $token_questions ) ? $token_questions : $this->get_questions( $lesson_id );

		if ( empty( $questions ) ) {
			wp_send_json_error( array( 'message' => __( 'No hay preguntas válidas.', 'atora-lms' ) ), 400 );
		}

		$attempt = $this->get_quiz_attempt( $user_id, $lesson_id );

		if ( ! $this->can_retry_quiz( $lesson_id, $attempt ) ) {
			wp_send_json_error( array( 'message' => __( 'Ya no tienes más intentos disponibles.', 'atora-lms' ) ), 409 );
		}

		if ( $this->is_submission_locked( $user_id, $lesson_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Tu evaluación ya se está procesando. Espera unos segundos.', 'atora-lms' ) ), 429 );
		}

		$this->lock_submission( $user_id, $lesson_id );

		$raw_answers = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? wp_unslash( $_POST['answers'] ) : array();
		$answers     = $this->sanitize_quiz_answers( $questions, $raw_answers );

		if ( is_wp_error( $answers ) ) {
			$this->unlock_submission( $user_id, $lesson_id );
			wp_send_json_error( array( 'message' => $answers->get_error_message() ), 400 );
		}

		$result = $this->grade_quiz( $lesson_id, $questions, $answers );
		$stored = $this->store_result( $user_id, $lesson_id, $result );

		$this->unlock_submission( $user_id, $lesson_id );
		delete_transient( $this->get_timer_start_key( $user_id, $lesson_id ) );

		do_action(
			'clms_quiz_submitted',
			$user_id,
			$lesson_id,
			array(
				'score'              => isset( $stored['score'] ) ? $stored['score'] : 0,
				'best_score'         => isset( $stored['best_score'] ) ? $stored['best_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
				'latest_score'       => isset( $stored['last_score'] ) ? $stored['last_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
				'feedback'           => isset( $stored['feedback'] ) ? $stored['feedback'] : '',
				'student_message'    => isset( $stored['performance_message'] ) ? $stored['performance_message'] : '',
				'retry_context'      => isset( $stored['retry_context'] ) && is_array( $stored['retry_context'] ) ? $stored['retry_context'] : array(),
				'attempts'           => isset( $stored['attempts'] ) ? $stored['attempts'] : 1,
				'answers'            => isset( $stored['answers'] ) ? $stored['answers'] : array(),
			)
		);

		$this->mark_lesson_completed_for_user( $user_id, $lesson_id );

		$quiz_response = array(
			'message'        => isset( $stored['performance_message'] ) && '' !== (string) $stored['performance_message']
				? (string) $stored['performance_message']
				: __( 'Evaluación enviada correctamente.', 'atora-lms' ),
			'score'          => isset( $stored['score'] ) ? $stored['score'] : 0,
			'latest_score'   => isset( $stored['last_score'] ) ? $stored['last_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
			'best_score'     => isset( $stored['best_score'] ) ? $stored['best_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
			'student_message'=> isset( $stored['performance_message'] ) ? (string) $stored['performance_message'] : '',
			'retry_context'  => isset( $stored['retry_context'] ) && is_array( $stored['retry_context'] ) ? $stored['retry_context'] : array(),
			'allow_retry'    => isset( $stored['retry_context']['can_retry'] ) ? ! empty( $stored['retry_context']['can_retry'] ) : false,
			'html'           => $this->render_result_box( $stored, $this->should_show_result_details( $lesson_id ), $lesson_id ),
		);

		$quiz_response = apply_filters( 'clms_quiz_result_response', $quiz_response, $user_id, $lesson_id );

		wp_send_json_success( $quiz_response );
	}

	/* ---------------------------------------------------------------
	   REST
	--------------------------------------------------------------- */

	/**
	 * Prepara una evaluación para clientes móviles sin exponer claves ni feedback.
	 */
	public function get_quiz_rest( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id || ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_quiz_forbidden', __( 'No tienes acceso a esta evaluación.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		if ( ! $this->is_quiz_enabled( $lesson_id ) ) {
			return new WP_Error( 'clms_quiz_disabled', __( 'Esta evaluación no está habilitada.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$date_check = $this->validate_availability_window( $lesson_id );
		if ( true !== $date_check ) {
			return new WP_Error( 'clms_quiz_unavailable', $date_check, array( 'status' => 409 ) );
		}
		$all_questions = $this->get_questions( $lesson_id );
		if ( empty( $all_questions ) ) {
			return new WP_Error( 'clms_quiz_empty', __( 'No hay preguntas válidas.', 'atora-lms' ), array( 'status' => 404 ) );
		}
		$attempt       = $this->get_quiz_attempt( $user_id, $lesson_id );
		$retry_context = $this->get_quiz_retry_context( $lesson_id, $attempt );
		$can_retry     = $this->can_retry_quiz( $lesson_id, $attempt );
		$selection     = $can_retry ? $this->select_questions_for_render( $user_id, $lesson_id, $all_questions ) : array( 'questions' => array(), 'token' => '' );
		$time_limit    = absint( get_post_meta( $lesson_id, '_lm_quiz_time_limit', true ) );
		$remaining     = 0;
		if ( $time_limit > 0 && $can_retry ) {
			$start     = $this->get_or_create_quiz_start( $user_id, $lesson_id, $time_limit * 60 );
			$remaining = max( 0, ( $time_limit * 60 ) - max( 0, time() - $start ) );
		}

		$questions = array();
		foreach ( $selection['questions'] as $index => $question ) {
			$questions[] = array(
				'id'       => $index,
				'type'     => sanitize_key( (string) ( $question['type'] ?? 'single' ) ),
				'question' => sanitize_text_field( $this->get_question_text( $question ) ),
				'options'  => array_values( array_map( 'sanitize_text_field', (array) ( $question['options'] ?? array() ) ) ),
				'weight'   => max( 1, absint( $question['weight'] ?? 1 ) ),
			);
		}

		return array(
			'lesson_id'        => $lesson_id,
			'token'            => (string) $selection['token'],
			'questions'        => $questions,
			'can_submit'       => $can_retry,
			'remaining_seconds'=> $remaining,
			'attempts'         => absint( $attempt['attempts'] ?? 0 ),
			'best_score'       => isset( $attempt['best_score'] ) ? absint( $attempt['best_score'] ) : null,
			'retry_context'    => $retry_context,
		);
	}

	/**
	 * Califica exactamente la selección firmada entregada al cliente móvil.
	 */
	public function grade_mobile_quiz_rest( $user_id, $lesson_id, $answers, $token ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$token     = sanitize_text_field( (string) $token );
		if ( ! $user_id || ! $lesson_id || ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_quiz_forbidden', __( 'No tienes acceso a esta evaluación.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$date_check = $this->validate_availability_window( $lesson_id );
		if ( true !== $date_check ) {
			return new WP_Error( 'clms_quiz_unavailable', $date_check, array( 'status' => 409 ) );
		}
		$time_check = $this->validate_time_limit( $user_id, $lesson_id );
		if ( is_wp_error( $time_check ) ) {
			return $time_check;
		}
		$questions = $this->get_questions_by_token( $user_id, $lesson_id, $token );
		if ( empty( $questions ) ) {
			return new WP_Error( 'clms_quiz_token_expired', __( 'La evaluación venció. Vuelve a abrirla para continuar.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		$attempt = $this->get_quiz_attempt( $user_id, $lesson_id );
		if ( ! $this->can_retry_quiz( $lesson_id, $attempt ) ) {
			return new WP_Error( 'clms_quiz_attempts_exceeded', __( 'Ya no tienes más intentos disponibles.', 'atora-lms' ), array( 'status' => 409 ) );
		}
		if ( $this->is_submission_locked( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_quiz_submission_locked', __( 'Tu evaluación ya se está procesando.', 'atora-lms' ), array( 'status' => 429 ) );
		}
		$this->lock_submission( $user_id, $lesson_id );
		$clean_answers = $this->sanitize_quiz_answers( $questions, is_array( $answers ) ? $answers : array() );
		if ( is_wp_error( $clean_answers ) ) {
			$this->unlock_submission( $user_id, $lesson_id );
			return $clean_answers;
		}
		$result = $this->grade_quiz( $lesson_id, $questions, $clean_answers );
		$stored = $this->store_result( $user_id, $lesson_id, $result );
		$this->unlock_submission( $user_id, $lesson_id );
		delete_transient( $this->get_timer_start_key( $user_id, $lesson_id ) );
		do_action( 'clms_quiz_submitted', $user_id, $lesson_id, array(
			'score' => $stored['score'] ?? 0, 'best_score' => $stored['best_score'] ?? 0,
			'latest_score' => $stored['last_score'] ?? 0, 'attempts' => $stored['attempts'] ?? 1,
			'answers' => $stored['answers'] ?? array(),
		) );
		$this->mark_lesson_completed_for_user( $user_id, $lesson_id );
		return array(
			'score'           => absint( $stored['last_score'] ?? 0 ),
			'best_score'      => absint( $stored['best_score'] ?? 0 ),
			'attempt'         => absint( $stored['attempts'] ?? 1 ),
			'student_message' => sanitize_textarea_field( (string) ( $stored['performance_message'] ?? '' ) ),
			'can_retry'       => $this->can_retry_quiz( $lesson_id, $stored ),
		);
	}

	public function grade_quiz_rest( $user_id, $lesson_id, $answers ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$answers   = is_array( $answers ) ? $answers : array();

		if ( ! $user_id || ! $lesson_id ) {
			return new WP_Error( 'clms_invalid_quiz_request', __( 'Solicitud inválida.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		if ( ! CLMS_Helper::user_can_access_lesson( $user_id, $lesson_id ) ) {
			return new WP_Error( 'clms_quiz_forbidden', __( 'No tienes acceso a esta lección.', 'atora-lms' ), array( 'status' => 403 ) );
		}

		$questions = $this->get_questions( $lesson_id );

		if ( empty( $questions ) ) {
			return new WP_Error( 'clms_quiz_empty', __( 'No hay preguntas válidas.', 'atora-lms' ), array( 'status' => 400 ) );
		}

		$attempt = $this->get_quiz_attempt( $user_id, $lesson_id );

		if ( ! $this->can_retry_quiz( $lesson_id, $attempt ) ) {
			return new WP_Error( 'clms_quiz_attempts_exceeded', __( 'Ya no tienes más intentos disponibles.', 'atora-lms' ), array( 'status' => 409 ) );
		}

		$clean_answers = $this->sanitize_quiz_answers( $questions, $answers );

		if ( is_wp_error( $clean_answers ) ) {
			return $clean_answers;
		}

		$result = $this->grade_quiz( $lesson_id, $questions, $clean_answers );
		$stored = $this->store_result( $user_id, $lesson_id, $result );

		do_action(
			'clms_quiz_submitted',
			$user_id,
			$lesson_id,
			array(
				'score'              => isset( $stored['score'] ) ? $stored['score'] : 0,
				'best_score'         => isset( $stored['best_score'] ) ? $stored['best_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
				'latest_score'       => isset( $stored['last_score'] ) ? $stored['last_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
				'feedback'           => isset( $stored['feedback'] ) ? $stored['feedback'] : '',
				'student_message'    => isset( $stored['performance_message'] ) ? $stored['performance_message'] : '',
				'retry_context'      => isset( $stored['retry_context'] ) && is_array( $stored['retry_context'] ) ? $stored['retry_context'] : array(),
				'attempts'           => isset( $stored['attempts'] ) ? $stored['attempts'] : 1,
				'answers'            => isset( $stored['answers'] ) ? $stored['answers'] : array(),
			)
		);

		$this->mark_lesson_completed_for_user( $user_id, $lesson_id );

		return array(
			'success'   => true,
			'lesson_id' => $lesson_id,
			'score'     => isset( $stored['score'] ) ? $stored['score'] : 0,
			'best_score'=> isset( $stored['best_score'] ) ? $stored['best_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
			'latest_score' => isset( $stored['last_score'] ) ? $stored['last_score'] : ( isset( $stored['score'] ) ? $stored['score'] : 0 ),
			'attempt'   => isset( $stored['attempts'] ) ? $stored['attempts'] : 1,
			'student_message' => isset( $stored['performance_message'] ) ? $stored['performance_message'] : '',
			'retry_context'   => isset( $stored['retry_context'] ) && is_array( $stored['retry_context'] ) ? $stored['retry_context'] : array(),
			'result'    => $stored,
		);
	}

	protected function mark_lesson_completed_for_user( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		if ( ! $user_id || ! $lesson_id ) {
			return;
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_values( array_map( 'absint', $completed ) ) : array();
		$updated   = false;

		if ( ! in_array( $lesson_id, $completed, true ) ) {
			$completed[] = $lesson_id;
			$completed   = array_values( array_unique( $completed ) );
			update_user_meta( $user_id, '_clms_completed_lessons', $completed );
			$updated = true;
		}

		if ( $updated ) {
			do_action( 'clms_lesson_completed', $user_id, $lesson_id );
		}

		if ( ! class_exists( 'CLMS_Helper' ) ) {
			return;
		}

		$course_id = absint( CLMS_Helper::get_course_id_from_lesson( $lesson_id ) );
		$lessons   = $course_id ? CLMS_Helper::get_course_lessons( $course_id ) : array();
		$lessons   = is_array( $lessons ) ? array_map( 'absint', $lessons ) : array();
		if ( ! $course_id || empty( $lessons ) ) {
			return;
		}

		$done_in_course = count( array_intersect( $lessons, $completed ) );
		if ( $done_in_course >= count( $lessons ) ) {
			do_action( 'clms_course_completed', $user_id, $course_id );
		}
	}

	/* ---------------------------------------------------------------
	   SELECCIÓN DE PREGUNTAS
	--------------------------------------------------------------- */

	protected function select_questions_for_render( $user_id, $lesson_id, $all_questions ) {
		$randomize     = 'yes' === get_post_meta( $lesson_id, '_lm_quiz_randomize', true );
		$num_questions = absint( get_post_meta( $lesson_id, '_lm_quiz_num_questions', true ) );

		$questions = array_values( $all_questions );

		if ( $randomize ) {
			shuffle( $questions );
		}

		if ( $num_questions > 0 && $num_questions < count( $questions ) ) {
			$questions = array_slice( $questions, 0, $num_questions );
		}

		$token     = wp_generate_password( 20, false );
		$cache_key = $this->get_question_token_key( $user_id, $lesson_id, $token );

		set_transient( $cache_key, $questions, self::QUESTION_TOKEN_TTL );

		return array(
			'questions' => $questions,
			'token'     => $token,
		);
	}

	protected function get_questions_by_token( $user_id, $lesson_id, $token ) {
		if ( empty( $token ) || strlen( $token ) > 40 ) {
			return array();
		}

		$cache_key = $this->get_question_token_key( $user_id, $lesson_id, $token );
		$questions = get_transient( $cache_key );

		return is_array( $questions ) ? $questions : array();
	}

	protected function get_question_token_key( $user_id, $lesson_id, $token ) {
		return 'clms_qt_' . absint( $user_id ) . '_' . absint( $lesson_id ) . '_' . substr( sanitize_key( $token ), 0, 20 );
	}

	/* ---------------------------------------------------------------
	   TEMPORIZADOR
	--------------------------------------------------------------- */

	protected function get_or_create_quiz_start( $user_id, $lesson_id, $time_limit_seconds ) {
		$key   = $this->get_timer_start_key( $user_id, $lesson_id );
		$start = get_transient( $key );

		if ( false === $start ) {
			$start = time();
			set_transient( $key, $start, $time_limit_seconds + self::TIMER_GRACE_SECONDS + 60 );
		}

		return (int) $start;
	}

	protected function validate_time_limit( $user_id, $lesson_id ) {
		$time_limit = absint( get_post_meta( $lesson_id, '_lm_quiz_time_limit', true ) );

		if ( ! $time_limit ) {
			return true;
		}

		$key   = $this->get_timer_start_key( $user_id, $lesson_id );
		$start = get_transient( $key );

		if ( false === $start ) {
			return true;
		}

		$allowed = ( $time_limit * 60 ) + self::TIMER_GRACE_SECONDS;
		$elapsed = time() - (int) $start;

		if ( $elapsed > $allowed ) {
			return new WP_Error( 'quiz_time_expired', __( 'El tiempo para responder esta evaluación ha terminado.', 'atora-lms' ) );
		}

		return true;
	}

	protected function get_timer_start_key( $user_id, $lesson_id ) {
		return 'clms_qs_' . absint( $user_id ) . '_' . absint( $lesson_id );
	}

	/* ---------------------------------------------------------------
	   DATOS DEL QUIZ
	--------------------------------------------------------------- */

	protected function is_quiz_enabled( $lesson_id ) {
		$enabled = get_post_meta( $lesson_id, self::QUIZ_ENABLED_META_KEY, true );

		if ( '' === $enabled ) {
			$has_eval = get_post_meta( $lesson_id, '_lm_quiz_has_eval', true );
			return 'yes' === $has_eval;
		}

		return in_array( (string) $enabled, array( '1', 'yes', 'true' ), true );
	}

	protected function should_show_result_details( $lesson_id ) {
		$value = get_post_meta( $lesson_id, '_lm_quiz_show_results', true );
		if ( '' === (string) $value ) {
			return true;
		}
		return in_array( (string) $value, array( '1', 'yes', 'true' ), true );
	}

	protected function get_questions( $lesson_id ) {
		$questions = get_post_meta( $lesson_id, self::QUIZ_META_KEY, true );
		$questions = is_array( $questions ) ? array_values( $questions ) : array();

		$valid = array();

		foreach ( $questions as $question ) {
			$normalized = $this->normalize_question( $question );

			if ( ! empty( $normalized['question'] ) ) {
				$valid[] = $normalized;
			}
		}

		return $valid;
	}

	protected function normalize_question( $question ) {
		$question = is_array( $question ) ? $question : array();

		$type = isset( $question['type'] ) ? sanitize_key( $question['type'] ) : 'single';

		if ( ! in_array( $type, array( 'single', 'multiple', 'text', 'textarea', 'true_false', 'number' ), true ) ) {
			$type = 'single';
		}

		$text = isset( $question['question'] ) ? sanitize_text_field( $question['question'] ) : '';
		if ( '' === $text && isset( $question['text'] ) ) {
			$text = sanitize_text_field( $question['text'] );
		}

		$options = array();
		if ( isset( $question['options'] ) && is_array( $question['options'] ) ) {
			foreach ( $question['options'] as $option ) {
				$options[] = sanitize_text_field( $option );
			}
		}

		$correct = array();
		if ( isset( $question['correct'] ) ) {
			if ( is_array( $question['correct'] ) ) {
				foreach ( $question['correct'] as $item ) {
					$correct[] = sanitize_text_field( $item );
				}
			} else {
				$correct[] = sanitize_text_field( $question['correct'] );
			}
		} elseif ( isset( $question['answer'] ) ) {
			if ( is_array( $question['answer'] ) ) {
				foreach ( $question['answer'] as $item ) {
					$correct[] = sanitize_text_field( $item );
				}
			} else {
				$correct[] = sanitize_text_field( $question['answer'] );
			}
		}

		$feedback = isset( $question['feedback'] ) ? sanitize_textarea_field( $question['feedback'] ) : '';
		$weight   = isset( $question['weight'] ) ? max( 1, absint( $question['weight'] ) ) : 1;

		return array(
			'type'     => $type,
			'question' => $text,
			'options'  => array_values( array_filter( $options, 'strlen' ) ),
			'correct'  => array_values( array_filter( $correct, 'strlen' ) ),
			'feedback' => $feedback,
			'weight'   => $weight,
		);
	}

	protected function get_question_text( $question ) {
		return isset( $question['question'] ) ? (string) $question['question'] : '';
	}

	/* ---------------------------------------------------------------
	   RENDER INPUTS
	--------------------------------------------------------------- */

	protected function render_question_input( $question, $index ) {
		$type    = isset( $question['type'] ) ? $question['type'] : 'single';
		$options = isset( $question['options'] ) && is_array( $question['options'] ) ? $question['options'] : array();
		$name    = 'answers[' . absint( $index ) . ']';

		ob_start();

		switch ( $type ) {
			case 'multiple':
				foreach ( $options as $option ) {
					?>
					<label class="clms-quiz-option">
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $option ); ?>">
						<?php echo esc_html( $option ); ?>
					</label>
					<?php
				}
				break;

			case 'text':
				?>
				<input type="text" class="clms-quiz-text" name="<?php echo esc_attr( $name ); ?>" value="">
				<?php
				break;

			case 'textarea':
				?>
				<textarea class="clms-quiz-textarea" name="<?php echo esc_attr( $name ); ?>" rows="5"></textarea>
				<?php
				break;

			case 'number':
				?>
				<input type="number" class="clms-quiz-number" name="<?php echo esc_attr( $name ); ?>" value="">
				<?php
				break;

			case 'true_false':
				?>
				<label class="clms-quiz-option">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="true">
					Verdadero
				</label>
				<label class="clms-quiz-option">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="false">
					Falso
				</label>
				<?php
				break;

			case 'single':
			default:
				foreach ( $options as $option ) {
					?>
					<label class="clms-quiz-option">
						<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $option ); ?>">
						<?php echo esc_html( $option ); ?>
					</label>
					<?php
				}
				break;
		}

		return ob_get_clean();
	}

	/* ---------------------------------------------------------------
	   SANITIZACIÓN DE RESPUESTAS
	--------------------------------------------------------------- */

	protected function sanitize_quiz_answers( $questions, $raw_answers ) {
		$questions     = is_array( $questions ) ? array_values( $questions ) : array();
		$raw_answers   = is_array( $raw_answers ) ? $raw_answers : array();
		$clean_answers = array();

		foreach ( $questions as $index => $question ) {
			$type = isset( $question['type'] ) ? $question['type'] : 'single';
			$raw  = isset( $raw_answers[ $index ] ) ? $raw_answers[ $index ] : '';

			switch ( $type ) {
				case 'multiple':
					$items = is_array( $raw ) ? $raw : array();
					$items = array_slice( $items, 0, self::MAX_ANSWER_ITEMS );
					$items = array_map( 'sanitize_text_field', $items );
					$items = array_values( array_unique( array_filter( $items, 'strlen' ) ) );
					$clean_answers[ $index ] = $items;
					break;

				case 'textarea':
					$value = is_string( $raw ) ? trim( wp_strip_all_tags( $raw ) ) : '';
					$value = mb_substr( $value, 0, self::MAX_TEXT_ANSWER_LEN );
					$clean_answers[ $index ] = $value;
					break;

				case 'text':
				case 'number':
				case 'true_false':
				case 'single':
				default:
					$value = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : '';
					$value = mb_substr( $value, 0, self::MAX_TEXT_ANSWER_LEN );
					$clean_answers[ $index ] = $value;
					break;
			}
		}

		return $clean_answers;
	}

	/* ---------------------------------------------------------------
	   CALIFICACIÓN
	--------------------------------------------------------------- */

	protected function grade_quiz( $lesson_id, $questions, $answers ) {
		unset( $lesson_id );

		$total_weight  = 0;
		$earned_weight = 0;
		$breakdown     = array();

		foreach ( $questions as $index => $question ) {
			$type     = isset( $question['type'] ) ? $question['type'] : 'single';
			$weight   = isset( $question['weight'] ) ? max( 1, absint( $question['weight'] ) ) : 1;
			$correct  = isset( $question['correct'] ) && is_array( $question['correct'] ) ? $question['correct'] : array();
			$user_raw = isset( $answers[ $index ] ) ? $answers[ $index ] : '';
			$is_right = false;

			$total_weight += $weight;

			switch ( $type ) {
				case 'multiple':
					$user_values = is_array( $user_raw ) ? array_values( array_map( 'strval', $user_raw ) ) : array();
					$right       = array_values( array_map( 'strval', $correct ) );
					sort( $user_values );
					sort( $right );
					$is_right = ( $user_values === $right );
					break;

				case 'text':
				case 'textarea':
				case 'number':
				case 'true_false':
				case 'single':
				default:
					$user_value = is_array( $user_raw ) ? '' : (string) $user_raw;
					$right      = isset( $correct[0] ) ? (string) $correct[0] : '';
					$is_right   = ( mb_strtolower( trim( $user_value ) ) === mb_strtolower( trim( $right ) ) );
					break;
			}

			if ( $is_right ) {
				$earned_weight += $weight;
			}

			$breakdown[] = array(
				'question'    => $this->get_question_text( $question ),
				'user_answer' => $user_raw,
				'correct'     => $correct,
				'is_correct'  => $is_right,
				'feedback'    => isset( $question['feedback'] ) ? (string) $question['feedback'] : '',
				'weight'      => $weight,
			);
		}

		$score = $total_weight > 0 ? round( ( $earned_weight / $total_weight ) * 100 ) : 0;

		return array(
			'score'     => (int) $score,
			'answers'   => $answers,
			'breakdown' => $breakdown,
			'feedback'  => '',
			'submitted' => current_time( 'mysql' ),
		);
	}

	protected function store_result( $user_id, $lesson_id, $result ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );
		$result    = is_array( $result ) ? $result : array();

		$key     = $this->get_attempt_meta_key( $lesson_id );
		$current = get_user_meta( $user_id, $key, true );
		$current = is_array( $current ) ? $current : array();

		$attempts       = isset( $current['attempts'] ) ? absint( $current['attempts'] ) + 1 : 1;
		$current_score  = isset( $result['score'] ) ? absint( $result['score'] ) : 0;
		$previous_best  = isset( $current['best_score'] ) ? absint( $current['best_score'] ) : ( isset( $current['score'] ) ? absint( $current['score'] ) : 0 );
		$best_score     = max( $previous_best, $current_score );
		$is_new_best    = $current_score > $previous_best || 1 === $attempts;

		$stored = array(
			'score'       => $best_score,
			'best_score'  => $best_score,
			'last_score'  => $current_score,
			'is_new_best' => $is_new_best,
			'answers'     => isset( $result['answers'] ) ? $result['answers'] : array(),
			'breakdown'   => isset( $result['breakdown'] ) ? $result['breakdown'] : array(),
			'feedback'    => isset( $result['feedback'] ) ? sanitize_textarea_field( $result['feedback'] ) : '',
			'submitted'   => isset( $result['submitted'] ) ? sanitize_text_field( $result['submitted'] ) : current_time( 'mysql' ),
			'attempts'    => $attempts,
		);

		$retry_context                  = $this->get_quiz_retry_context( $lesson_id, $stored );
		$stored['retry_context']        = $retry_context;
		$stored['performance_message']  = $this->build_quiz_performance_message( $stored, $retry_context );

		update_user_meta( $user_id, $key, $stored );

		return $stored;
	}

	protected function get_quiz_attempt( $user_id, $lesson_id ) {
		$key   = $this->get_attempt_meta_key( $lesson_id );
		$value = get_user_meta( absint( $user_id ), $key, true );

		return is_array( $value ) ? $value : array();
	}

	public function get_user_attempt_for_progress( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$key   = $this->get_attempt_meta_key( $lesson_id );
		$value = get_user_meta( $user_id, $key, true );

		return is_array( $value ) ? $value : array();
	}

	protected function can_retry_quiz( $lesson_id, $attempt ) {
		$max_attempts = absint( get_post_meta( $lesson_id, '_lm_quiz_max_attempts', true ) );

		if ( $max_attempts <= 0 ) {
			return true;
		}

		$current_attempts = isset( $attempt['attempts'] ) ? absint( $attempt['attempts'] ) : 0;

		return $current_attempts < $max_attempts;
	}

	/**
	 * Resume la disponibilidad de reintentos y plazo para el estudiante.
	 *
	 * @param int   $lesson_id Lección.
	 * @param array $attempt   Intento guardado.
	 * @return array<string,mixed>
	 */
	protected function get_quiz_retry_context( $lesson_id, $attempt ) {
		$lesson_id = absint( $lesson_id );
		$attempt   = is_array( $attempt ) ? $attempt : array();

		$max_attempts     = absint( get_post_meta( $lesson_id, '_lm_quiz_max_attempts', true ) );
		$current_attempts = isset( $attempt['attempts'] ) ? absint( $attempt['attempts'] ) : 0;
		$unlimited        = $max_attempts <= 0;
		$remaining        = $unlimited ? -1 : max( 0, $max_attempts - $current_attempts );
		$deadline_ts      = $this->get_quiz_deadline_timestamp( $lesson_id );
		$deadline_label   = $deadline_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $deadline_ts ) : '';
		$deadline_expired = $deadline_ts > 0 && time() > $deadline_ts;
		$can_retry        = $this->can_retry_quiz( $lesson_id, $attempt ) && ! $deadline_expired;

		return array(
			'max_attempts'      => $max_attempts,
			'current_attempts'  => $current_attempts,
			'remaining_attempts'=> $remaining,
			'unlimited'         => $unlimited,
			'deadline_ts'       => $deadline_ts,
			'deadline_label'    => $deadline_label,
			'deadline_expired'  => $deadline_expired,
			'can_retry'         => $can_retry,
		);
	}

	/**
	 * Construye mensaje pedagógico según rendimiento y oportunidades.
	 *
	 * @param array $attempt       Intento guardado.
	 * @param array $retry_context Contexto de reintentos.
	 * @return string
	 */
	protected function build_quiz_performance_message( $attempt, $retry_context ) {
		$attempt       = is_array( $attempt ) ? $attempt : array();
		$retry_context = is_array( $retry_context ) ? $retry_context : array();

		$best_score = isset( $attempt['best_score'] ) ? absint( $attempt['best_score'] ) : ( isset( $attempt['score'] ) ? absint( $attempt['score'] ) : 0 );
		$last_score = isset( $attempt['last_score'] ) ? absint( $attempt['last_score'] ) : $best_score;
		$is_new_best = ! empty( $attempt['is_new_best'] );
		$can_retry   = ! empty( $retry_context['can_retry'] );
		$remaining   = isset( $retry_context['remaining_attempts'] ) ? (int) $retry_context['remaining_attempts'] : -1;
		$deadline    = isset( $retry_context['deadline_label'] ) ? (string) $retry_context['deadline_label'] : '';

		if ( $last_score >= 90 ) {
			$message = __( 'Excelente nota. Tu desempeño fue muy sólido.', 'atora-lms' );
		} elseif ( $last_score >= 70 ) {
			$message = __( 'Muy buen avance. Vas por un camino consistente.', 'atora-lms' );
		} else {
			$message = __( 'En esta oportunidad no fuiste tan preciso. Repasa tus apuntes y el material de apoyo para volver a intentarlo con más claridad.', 'atora-lms' );
		}

		if ( $is_new_best && $best_score > 0 ) {
			$message .= ' ' . sprintf( __( '¡Nuevo mejor resultado: %d%%!', 'atora-lms' ), $best_score );
		} elseif ( $best_score > $last_score ) {
			$message .= ' ' . sprintf( __( 'Tu mejor marca sigue en %d%%. Puedes superarla.', 'atora-lms' ), $best_score );
		}

		if ( $can_retry ) {
			if ( $remaining > 0 ) {
				$message .= ' ' . sprintf( __( 'Aún tienes %d intento(s). ¿Te animas a presentarla otra vez?', 'atora-lms' ), $remaining );
			} elseif ( ! empty( $retry_context['unlimited'] ) ) {
				$message .= ' ' . __( 'Puedes volver a presentarla cuando quieras para seguir mejorando.', 'atora-lms' );
			}
			if ( $deadline ) {
				$message .= ' ' . sprintf( __( 'Tienes hasta %s para intentarlo de nuevo.', 'atora-lms' ), $deadline );
			}
		} elseif ( ! empty( $retry_context['deadline_expired'] ) ) {
			$message .= ' ' . __( 'El plazo para nuevos intentos ya finalizó.', 'atora-lms' );
		} else {
			$message .= ' ' . __( 'Ya no tienes intentos disponibles en esta evaluación.', 'atora-lms' );
		}

		return sanitize_text_field( $message );
	}

	/**
	 * Mensaje cuando ya no hay opciones de reintento.
	 *
	 * @param array $retry_context Contexto de reintentos.
	 * @return string
	 */
	protected function build_quiz_no_retry_message( $retry_context ) {
		$retry_context = is_array( $retry_context ) ? $retry_context : array();

		if ( ! empty( $retry_context['deadline_expired'] ) ) {
			return __( 'El periodo de esta evaluación ya cerró. Revisa tus resultados y consulta a tu docente si necesitas apoyo.', 'atora-lms' );
		}

		$message = __( 'Ya no tienes más intentos disponibles para esta evaluación.', 'atora-lms' );
		if ( ! empty( $retry_context['deadline_label'] ) ) {
			$message .= ' ' . sprintf( __( 'Fecha límite registrada: %s.', 'atora-lms' ), (string) $retry_context['deadline_label'] );
		}
		return $message;
	}

	protected function get_attempt_meta_key( $lesson_id ) {
		return 'clms_quiz_attempt_' . absint( $lesson_id );
	}

	/* ---------------------------------------------------------------
	   BLOQUEO DE ENVÍO
	--------------------------------------------------------------- */

	protected function is_submission_locked( $user_id, $lesson_id ) {
		return (bool) get_transient( $this->get_submit_lock_key( $user_id, $lesson_id ) );
	}

	protected function lock_submission( $user_id, $lesson_id ) {
		set_transient( $this->get_submit_lock_key( $user_id, $lesson_id ), 1, self::SUBMIT_LOCK_WINDOW );
	}

	protected function unlock_submission( $user_id, $lesson_id ) {
		delete_transient( $this->get_submit_lock_key( $user_id, $lesson_id ) );
	}

	protected function get_submit_lock_key( $user_id, $lesson_id ) {
		return 'clms_qlock_' . absint( $user_id ) . '_' . absint( $lesson_id );
	}

	/* ---------------------------------------------------------------
	   DISPONIBILIDAD
	--------------------------------------------------------------- */

	protected function validate_availability_window( $lesson_id ) {
		$enabled = get_post_meta( $lesson_id, '_lm_quiz_enable_dates', true );

		if ( 'yes' !== $enabled ) {
			return true;
		}

		$open_date = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' );
		$late_date = CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_date', '_clms_due_date_late' ), '' );
		$today     = current_time( 'Y-m-d' );

		if ( $open_date && $today < $open_date ) {
			return 'La evaluación aún no está disponible.';
		}

		if ( $late_date && $today > $late_date ) {
			return 'La fecha de esta evaluación ya venció.';
		}

		return true;
	}

	/**
	 * Obtiene el timestamp de fecha/hora límite efectiva de un quiz.
	 * Prioriza fecha límite con retraso; luego fecha de entrega.
	 *
	 * @param int $lesson_id Lección.
	 * @return int
	 */
	protected function get_quiz_deadline_timestamp( $lesson_id ) {
		$lesson_id = absint( $lesson_id );
		if ( ! $lesson_id ) {
			return 0;
		}

		$late_date = class_exists( 'CLMS_Helper' ) ? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_date', '_clms_due_date_late' ), '' ) : '';
		$late_time = class_exists( 'CLMS_Helper' ) ? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_late_time' ), '' ) : '';
		$due_date  = class_exists( 'CLMS_Helper' ) ? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_date', '_clms_due_date' ), '' ) : '';
		$due_time  = class_exists( 'CLMS_Helper' ) ? (string) CLMS_Helper::get_post_meta_first( $lesson_id, array( 'lm_due_time', '_clms_due_time' ), '' ) : '';

		$date = trim( $late_date );
		$time = trim( $late_time );

		if ( '' === $date ) {
			$date = trim( $due_date );
			$time = trim( $due_time );
		}

		if ( '' === $date ) {
			return 0;
		}

		if ( '' === $time ) {
			$time = '23:59';
		}

		$ts = strtotime( $date . ' ' . $time );
		return $ts ? (int) $ts : 0;
	}

	/* ---------------------------------------------------------------
	   RESULTADOS
	--------------------------------------------------------------- */

	protected function render_result_box( $attempt, $show_feedback = true, $lesson_id = 0 ) {
		$attempt   = is_array( $attempt ) ? $attempt : array();
		$score     = isset( $attempt['score'] ) ? absint( $attempt['score'] ) : 0;
		$best_score = isset( $attempt['best_score'] ) ? absint( $attempt['best_score'] ) : $score;
		$last_score = isset( $attempt['last_score'] ) ? absint( $attempt['last_score'] ) : $score;
		$attempts   = isset( $attempt['attempts'] ) ? absint( $attempt['attempts'] ) : 1;
		$feedback  = isset( $attempt['feedback'] ) ? (string) $attempt['feedback'] : '';
		$performance_message = isset( $attempt['performance_message'] ) ? (string) $attempt['performance_message'] : '';
		$breakdown = isset( $attempt['breakdown'] ) && is_array( $attempt['breakdown'] ) ? $attempt['breakdown'] : array();
		$retry_context = isset( $attempt['retry_context'] ) && is_array( $attempt['retry_context'] )
			? $attempt['retry_context']
			: $this->get_quiz_retry_context( $lesson_id, $attempt );
		$guidance_class = 'clms-quiz-guidance';
		if ( $best_score < 70 ) {
			$guidance_class .= ' clms-quiz-guidance--danger';
		} elseif ( $best_score < 85 ) {
			$guidance_class .= ' clms-quiz-guidance--warning';
		}
		$remaining_attempts = isset( $retry_context['remaining_attempts'] ) ? (int) $retry_context['remaining_attempts'] : -1;
		$deadline_label     = isset( $retry_context['deadline_label'] ) ? (string) $retry_context['deadline_label'] : '';
		$can_retry          = ! empty( $retry_context['can_retry'] );

		ob_start();
		?>
		<div class="clms-quiz-result">
			<div class="clms-quiz-result-score"><?php esc_html_e( 'Mejor resultado:', 'atora-lms' ); ?> <?php echo esc_html( $best_score ); ?>%</div>
			<div class="clms-quiz-result-meta">
				<?php
				printf(
					/* translators: 1: latest score, 2: attempt count */
					esc_html__( 'Último intento: %1$s%% · Intento #%2$s', 'atora-lms' ),
					esc_html( $last_score ),
					esc_html( $attempts )
				);
				?>
			</div>

			<?php if ( $performance_message ) : ?>
				<div class="<?php echo esc_attr( $guidance_class ); ?>">
					<?php echo esc_html( $performance_message ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $retry_context ) ) : ?>
				<div class="clms-quiz-result-meta" style="margin-top:10px">
					<?php if ( ! empty( $retry_context['can_retry'] ) ) : ?>
						<?php if ( ! empty( $retry_context['unlimited'] ) ) : ?>
							<?php esc_html_e( 'Puedes volver a presentar esta evaluación sin límite de intentos.', 'atora-lms' ); ?>
						<?php elseif ( $remaining_attempts >= 0 ) : ?>
							<?php
							printf(
								/* translators: %d: remaining attempts */
								esc_html__( 'Te quedan %d intento(s).', 'atora-lms' ),
								esc_html( $remaining_attempts )
							);
							?>
						<?php endif; ?>
					<?php else : ?>
						<?php esc_html_e( 'Ya alcanzaste el límite de intentos para esta evaluación.', 'atora-lms' ); ?>
					<?php endif; ?>
					<?php if ( $deadline_label ) : ?>
						<br><?php printf( esc_html__( 'Fecha límite: %s', 'atora-lms' ), esc_html( $deadline_label ) ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $can_retry ) : ?>
				<div class="clms-quiz-retry">
					<button type="button" class="clms-quiz-retry-btn"><?php esc_html_e( 'Presentar nuevo intento', 'atora-lms' ); ?></button>
					<span class="clms-quiz-retry-note">
						<?php
						if ( ! empty( $retry_context['unlimited'] ) ) {
							esc_html_e( 'Puedes activarlo cuando estés listo para mejorar tu resultado.', 'atora-lms' );
						} elseif ( $remaining_attempts > 0 ) {
							printf(
								/* translators: %d: remaining attempts */
								esc_html__( 'Te quedan %d intento(s). El nuevo examen se mostrará al activarlo.', 'atora-lms' ),
								absint( $remaining_attempts )
							);
						}
						?>
					</span>
				</div>
			<?php endif; ?>

			<?php if ( $show_feedback && $feedback ) : ?>
				<div class="clms-quiz-feedback"><?php echo esc_html( $feedback ); ?></div>
			<?php endif; ?>

			<?php if ( $show_feedback && ! empty( $breakdown ) ) : ?>
				<details class="clms-quiz-breakdown-wrap">
					<summary class="clms-quiz-breakdown-toggle"><?php esc_html_e( 'Ver revisión detallada de respuestas', 'atora-lms' ); ?></summary>
					<div class="clms-quiz-breakdown">
						<?php foreach ( $breakdown as $item ) : ?>
							<?php
							$user_answer_text = isset( $item['user_answer'] ) ? trim( $this->answer_to_text( $item['user_answer'] ) ) : '';
							$correct_text     = isset( $item['correct'] ) ? trim( $this->answer_to_text( $item['correct'] ) ) : '';
							?>
							<div class="clms-quiz-breakdown-item">
								<div><strong><?php echo esc_html( isset( $item['question'] ) ? $item['question'] : '' ); ?></strong></div>
								<div>
									<?php if ( ! empty( $item['is_correct'] ) ) : ?>
										<span class="clms-quiz-answer-ok">Correcta</span>
									<?php else : ?>
										<span class="clms-quiz-answer-bad">Incorrecta</span>
									<?php endif; ?>
								</div>

								<?php if ( '' !== $user_answer_text ) : ?>
									<div><strong>Tu respuesta:</strong> <?php echo esc_html( $user_answer_text ); ?></div>
								<?php endif; ?>

								<?php if ( '' !== $correct_text ) : ?>
									<div><strong>Respuesta correcta:</strong> <?php echo esc_html( $correct_text ); ?></div>
								<?php endif; ?>

								<?php if ( ! empty( $item['feedback'] ) ) : ?>
									<div><?php echo esc_html( $item['feedback'] ); ?></div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}

	protected function answer_to_text( $value ) {
		if ( is_array( $value ) ) {
			$value = array_map( 'strval', $value );
			return implode( ', ', $value );
		}

		return (string) $value;
	}
}
