<?php
/**
 * CLMS_Learning_Path — Rutas de aprendizaje personalizadas con IA
 *
 * Analiza el progreso del estudiante (quizzes, entregas, memoria de aprendizaje)
 * y genera recomendaciones de qué lección hacer a continuación y por qué.
 *
 * Características:
 *   - Recomendación "siguiente paso" basada en progreso + temas difíciles
 *   - Shortcode [clms_learning_path] para insertar el widget en el dashboard
 *   - AJAX clms_get_learning_path — cachea recomendación 24h en user_meta
 *   - Compatible con multi-provider (OpenAI / Anthropic / Gemini)
 *   - Funciona sin IA (fallback heurístico si no hay API key configurada)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Learning_Path {

	const META_CACHE   = '_clms_lp_cache_';
	const CACHE_TTL    = DAY_IN_SECONDS;

	private static $instance = null;
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_shortcode( 'clms_learning_path',          array( $this, 'shortcode' ) );
		add_action( 'wp_ajax_clms_get_learning_path', array( $this, 'ajax_get' ) );
		// Invalidar caché cuando hay nueva actividad
		add_action( 'clms_quiz_attempt_saved',        array( $this, 'invalidate_cache' ), 10, 2 );
		add_action( 'clms_submission_graded',         array( $this, 'invalidate_cache_submission' ), 10, 2 );
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────────

	public function shortcode( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>Inicia sesión para ver tu ruta de aprendizaje.</p>';
		}

		$atts      = shortcode_atts( array( 'course_id' => 0 ), $atts );
		$course_id = absint( $atts['course_id'] );
		$user_id   = get_current_user_id();

		if ( ! $course_id ) {
			return '<p>Indica el ID del curso: <code>[clms_learning_path course_id="123"]</code></p>';
		}

		$nonce = wp_create_nonce( 'clms_learning_path_' . $user_id );

		ob_start();
		?>
		<div id="clms-lp-widget-<?php echo $course_id; ?>"
		     class="clms-lp-widget"
		     style="border:1px solid #e0e0e0;border-radius:8px;padding:20px;max-width:680px;font-family:sans-serif">

			<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
				<span style="font-size:24px">🗺️</span>
				<h3 style="margin:0;font-size:17px">Tu ruta de aprendizaje personalizada</h3>
			</div>

			<div id="clms-lp-content-<?php echo $course_id; ?>"
			     style="color:#555;font-size:14px;min-height:60px">
				<span style="color:#aaa">Cargando recomendaciones...</span>
			</div>

			<div style="margin-top:12px;text-align:right">
				<button id="clms-lp-refresh-<?php echo $course_id; ?>"
				        class="button button-small"
				        style="font-size:12px;cursor:pointer">
					↺ Actualizar
				</button>
			</div>
		</div>

		<script>
		(function(){
			var courseId = <?php echo $course_id; ?>;
			var nonce    = '<?php echo esc_js( $nonce ); ?>';
			var ajaxUrl  = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
			var content  = document.getElementById('clms-lp-content-' + courseId);
			var refresh  = document.getElementById('clms-lp-refresh-' + courseId);

			function load(force) {
				content.innerHTML = '<span style="color:#aaa">Analizando tu progreso...</span>';
				var fd = new FormData();
				fd.append('action', 'clms_get_learning_path');
				fd.append('nonce', nonce);
				fd.append('course_id', courseId);
				if (force) fd.append('force', '1');
				fetch(ajaxUrl, { method:'POST', body:fd })
					.then(function(r){ return r.json(); })
					.then(function(res){
						if (!res.success) { content.innerHTML = '<span style="color:#c00">' + (res.data.message || 'Error') + '</span>'; return; }
						renderPath(res.data);
					})
					.catch(function(){ content.innerHTML = '<span style="color:#c00">Error de conexión.</span>'; });
			}

			function renderPath(data) {
				var html = '';

				if (data.next_lessons && data.next_lessons.length) {
					html += '<div style="margin-bottom:14px">';
					html += '<strong style="display:block;margin-bottom:8px;color:#333">📌 Próximas lecciones recomendadas:</strong>';
					data.next_lessons.forEach(function(l, i){
						var bg = i === 0 ? '#e8f5e9' : '#f9f9f9';
						var border = i === 0 ? '#4caf50' : '#e0e0e0';
						html += '<div style="background:' + bg + ';border:1px solid ' + border + ';border-radius:6px;padding:10px 14px;margin-bottom:8px">';
						html += '<div style="font-weight:600;margin-bottom:4px">' + escHtml(l.title) + '</div>';
						if (l.reason) html += '<div style="font-size:12px;color:#666">' + escHtml(l.reason) + '</div>';
						if (l.url) html += '<a href="' + l.url + '" style="display:inline-block;margin-top:6px;font-size:12px;color:#1976d2">Ir a la lección →</a>';
						html += '</div>';
					});
					html += '</div>';
				}

				if (data.review_topics && data.review_topics.length) {
					html += '<div style="background:#fff8e1;border-left:3px solid #ffc107;padding:10px 14px;border-radius:4px;margin-bottom:14px">';
					html += '<strong style="font-size:13px">⚠️ Temas para reforzar:</strong>';
					html += '<ul style="margin:6px 0 0;padding-left:18px;font-size:13px">';
					data.review_topics.forEach(function(t){ html += '<li>' + escHtml(t) + '</li>'; });
					html += '</ul></div>';
				}

				if (data.summary) {
					html += '<p style="color:#666;font-size:13px;margin:0">' + escHtml(data.summary) + '</p>';
				}

				if (!html) html = '<p style="color:#888">¡Buen trabajo! Completa más lecciones para obtener recomendaciones personalizadas.</p>';

				html += '<p style="color:#bbb;font-size:11px;margin:10px 0 0">Actualizado: ' + (data.generated_at || '') + '</p>';
				content.innerHTML = html;
			}

			function escHtml(s) {
				return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
			}

			refresh.addEventListener('click', function(){ load(true); });
			load(false);
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	// ── AJAX ──────────────────────────────────────────────────────────────────────

	public function ajax_get() {
		$user_id   = get_current_user_id();
		$nonce     = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		$course_id = absint( $_POST['course_id'] ?? 0 );
		$force     = ! empty( $_POST['force'] );

		if ( ! wp_verify_nonce( $nonce, 'clms_learning_path_' . $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
		}

		if ( ! $user_id || ! $course_id ) {
			wp_send_json_error( array( 'message' => __( 'Parámetros inválidos.', 'atora-lms' ) ) );
		}

		// Caché de 24h
		if ( ! $force ) {
			$cached = get_user_meta( $user_id, self::META_CACHE . $course_id, true );
			if ( is_array( $cached ) && ! empty( $cached['generated_at'] ) ) {
				$age = time() - strtotime( $cached['generated_at'] );
				if ( $age < self::CACHE_TTL ) {
					wp_send_json_success( $cached );
				}
			}
		}

		$result = $this->generate( $user_id, $course_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		update_user_meta( $user_id, self::META_CACHE . $course_id, $result );
		wp_send_json_success( $result );
	}

	// ── Motor de recomendación ────────────────────────────────────────────────────

	/**
	 * Genera recomendaciones personalizadas.
	 *
	 * @param int $user_id
	 * @param int $course_id
	 * @return array|WP_Error
	 */
	public function generate( $user_id, $course_id ) {
		// Recopilar progreso del estudiante
		$progress_data = $this->collect_progress( $user_id, $course_id );

		// Intentar con IA; si falla, usar heurística
		$options = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_settings' )
			? (array) CLMS_AI_Settings_Service::get_settings()
			: (array) get_option( 'clms_ai_settings', array() );
		$provider = class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider' )
			? (string) CLMS_AI_Settings_Service::get_provider()
			: (string) ( $options['provider'] ?? 'openai' );
		$has_key  = $this->has_api_key( $provider, $options );

		if ( $has_key ) {
			$result = $this->generate_with_ai( $progress_data, $options, $provider );
			if ( ! is_wp_error( $result ) ) {
				do_action( 'atora/lms/learning_path_updated', absint( $user_id ), $result ); // Fase III S10
				return $result;
			}
		}

		// Fallback heurístico
		$heuristic = $this->generate_heuristic( $progress_data );
		do_action( 'atora/lms/learning_path_updated', absint( $user_id ), $heuristic ); // Fase III S10
		return $heuristic;
	}

	private function collect_progress( $user_id, $course_id ) {
		// Todas las lecciones del curso
		$lessons = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::get_course_lessons( $course_id )
			: get_posts( array(
				'post_type'      => 'lm_lesson',
				'posts_per_page' => -1,
				'meta_key'       => '_clms_lesson_course_id',
				'meta_value'     => $course_id,
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'fields'         => 'ids',
			) );

		$memory   = class_exists( 'CLMS_Student_Memory' )
			? CLMS_Student_Memory::instance()->load( $user_id, $course_id )
			: array();

		$completed   = array();
		$in_progress = array();
		$not_started = array();

		foreach ( $lessons as $lid ) {
			$lesson_data = $memory['lessons'][ $lid ] ?? null;
			$url         = get_permalink( $lid );
			$title       = get_the_title( $lid );

			if ( $lesson_data ) {
				if ( isset( $lesson_data['submission_score'] ) || isset( $lesson_data['quiz_best_pct'] ) ) {
					$completed[ $lid ] = array(
						'title'       => $title,
						'url'         => $url,
						'quiz_pct'    => $lesson_data['quiz_best_pct'] ?? null,
						'sub_score'   => $lesson_data['submission_score'] ?? null,
					);
				} else {
					$in_progress[ $lid ] = array( 'title' => $title, 'url' => $url );
				}
			} else {
				$not_started[ $lid ] = array( 'title' => $title, 'url' => $url );
			}
		}

		$difficult_topics = class_exists( 'CLMS_Student_Memory' )
			? CLMS_Student_Memory::get_difficult_topics( $user_id, $course_id )
			: array();

		return array(
			'course_title'    => get_the_title( $course_id ),
			'completed'       => $completed,
			'in_progress'     => $in_progress,
			'not_started'     => $not_started,
			'difficult_topics'=> $difficult_topics,
			'total_lessons'   => count( $lessons ),
		);
	}

	private function generate_with_ai( $data, $options, $provider ) {
		// Construir resumen para el prompt
		$completed_count = count( $data['completed'] );
		$total           = $data['total_lessons'];
		$pct             = $total > 0 ? round( $completed_count / $total * 100 ) : 0;

		$completed_titles = array_map( fn( $l ) => $l['title'], array_slice( $data['completed'], -3, 3, true ) );
		$pending_titles   = array_map( fn( $l ) => $l['title'], array_slice( $data['not_started'], 0, 5, true ) );
		$diff_topics      = array_map( fn( $t ) => $t['topic'] . ' (' . $t['last_score'] . '%)', array_slice( $data['difficult_topics'], 0, 3 ) );

		$prompt_parts = array(
			"Eres un tutor académico IA. El estudiante está tomando el curso \"" . $data['course_title'] . "\".",
			"Progreso: {$completed_count}/{$total} lecciones completadas ({$pct}%).",
		);

		if ( $completed_titles ) {
			$prompt_parts[] = "Últimas lecciones completadas: " . implode( ', ', $completed_titles ) . '.';
		}
		if ( $pending_titles ) {
			$prompt_parts[] = "Próximas lecciones pendientes: " . implode( ', ', $pending_titles ) . '.';
		}
		if ( $diff_topics ) {
			$prompt_parts[] = "Temas con dificultad: " . implode( ', ', $diff_topics ) . '.';
		}

		$next_lesson_list = '';
		$i = 1;
		foreach ( array_slice( $data['not_started'], 0, 5, true ) as $lid => $l ) {
			$next_lesson_list .= "{$i}. {$l['title']} (ID:{$lid})\n";
			$i++;
		}
		foreach ( array_slice( $data['in_progress'], 0, 3, true ) as $lid => $l ) {
			$next_lesson_list .= "{$i}. {$l['title']} (ID:{$lid}) [en progreso]\n";
			$i++;
		}

		$prompt = implode( ' ', $prompt_parts ) . "\n\n";
		$prompt .= "Lecciones disponibles:\n{$next_lesson_list}\n";
		$prompt .= "Genera un plan de estudio en JSON con este formato exacto:\n";
		$prompt .= "{\n";
		$prompt .= "  \"next_lessons\": [{\"id\": <int>, \"title\": \"<str>\", \"reason\": \"<por qué esta primero, 1 oración>\"}, ...],\n";
		$prompt .= "  \"review_topics\": [\"<tema a repasar>\", ...],\n";
		$prompt .= "  \"summary\": \"<motivación breve 1-2 oraciones en español>\"\n";
		$prompt .= "}\n";
		$prompt .= "Máximo 3 lecciones recomendadas. Solo JSON, sin texto adicional.";

		$raw = $this->call_provider( $provider, $options, $prompt );
		if ( is_wp_error( $raw ) ) { return $raw; }

		$json = $raw;
		if ( preg_match( '/\{[\s\S]+\}/', $raw, $m ) ) { $json = $m[0]; }
		$ai_data = json_decode( $json, true );
		if ( ! is_array( $ai_data ) ) {
			return new WP_Error( 'parse_error', __( 'Respuesta IA inválida.', 'atora-lms' ) );
		}

		// Añadir URLs a las lecciones recomendadas
		$all_lessons = array_merge( $data['not_started'], $data['in_progress'], $data['completed'] );
		$next = array();
		foreach ( (array) ( $ai_data['next_lessons'] ?? array() ) as $rec ) {
			$lid   = absint( $rec['id'] ?? 0 );
			$entry = array(
				'title'  => sanitize_text_field( (string) ( $rec['title'] ?? '' ) ),
				'reason' => sanitize_text_field( (string) ( $rec['reason'] ?? '' ) ),
				'url'    => $lid ? get_permalink( $lid ) : '',
			);
			$next[] = $entry;
		}

		return array(
			'next_lessons'   => $next,
			'review_topics'  => array_map( 'sanitize_text_field', (array) ( $ai_data['review_topics'] ?? array() ) ),
			'summary'        => sanitize_textarea_field( (string) ( $ai_data['summary'] ?? '' ) ),
			'generated_at'   => current_time( 'mysql' ),
			'method'         => 'ai',
		);
	}

	/**
	 * Fallback: siguiente lección sin completar + temas difíciles.
	 */
	private function generate_heuristic( $data ) {
		$next = array();

		// Primero: lecciones en progreso
		foreach ( array_slice( $data['in_progress'], 0, 2, true ) as $lid => $l ) {
			$next[] = array(
				'title'  => $l['title'],
				'reason' => 'Tienes esta lección iniciada — continúa para completarla.',
				'url'    => $l['url'],
			);
		}

		// Luego: siguientes no iniciadas
		foreach ( array_slice( $data['not_started'], 0, max( 1, 3 - count( $next ) ), true ) as $lid => $l ) {
			$next[] = array(
				'title'  => $l['title'],
				'reason' => 'Es la siguiente lección del curso.',
				'url'    => $l['url'],
			);
		}

		$review = array_map( fn( $t ) => $t['topic'], array_slice( $data['difficult_topics'], 0, 3 ) );

		$completed_count = count( $data['completed'] );
		$total           = $data['total_lessons'];
		$summary         = $total > 0
			? "Has completado {$completed_count} de {$total} lecciones. ¡Sigue así!"
			: "Comienza con la primera lección para iniciar tu camino.";

		return array(
			'next_lessons'  => $next,
			'review_topics' => $review,
			'summary'       => $summary,
			'generated_at'  => current_time( 'mysql' ),
			'method'        => 'heuristic',
		);
	}

	// ── Invalidar caché ───────────────────────────────────────────────────────────

	public function invalidate_cache( $user_id, $lesson_id ) {
		$course_id = class_exists( 'CLMS_Helper' ) ? CLMS_Helper::get_lesson_course_id( $lesson_id ) : 0;
		if ( $course_id ) {
			delete_user_meta( $user_id, self::META_CACHE . $course_id );
		}
	}

	public function invalidate_cache_submission( $submission_id, $user_id ) {
		$lesson_id = (int) get_post_meta( $submission_id, '_clms_submission_lesson_id', true );
		$this->invalidate_cache( $user_id, $lesson_id );
	}

	// ── Provider helper ───────────────────────────────────────────────────────────

	private function has_api_key( $provider, $options ) {
		unset( $options );
		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( $manager && method_exists( $manager, 'get_api_key' ) ) {
			return (bool) $manager->get_api_key( $provider );
		}
		return false;
	}

	private function call_provider( $provider, $options, $prompt ) {
		unset( $options );

		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'chat' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return $manager->chat(
			array( array( 'role' => 'user', 'content' => (string) $prompt ) ),
			array(
				'provider'    => (string) $provider,
				'max_tokens'  => 500,
				'temperature' => 0.4,
				'timeout'     => 30,
			)
		);
	}
}
