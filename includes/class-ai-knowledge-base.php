<?php
/**
 * CLMS_AI_Knowledge_Base
 *
 * Indexa el contenido de todos los cursos y lecciones para que la IA
 * pueda recuperar fragmentos relevantes por búsqueda de palabras clave
 * (RAG ligero — sin embeddings, sin base de datos extra).
 *
 * Almacenamiento:
 *   wp_options  key: _clms_kb_{course_id}
 *   Valor: JSON array de chunks:
 *     { source, lesson_id, title, text, updated_at }
 *
 * Capacidades:
 *   - Indexa: cuerpo de lección, subtítulo, transcripción, beneficios del curso,
 *             objetivos, FAQ, testimonios
 *   - Búsqueda por solapamiento de palabras (TF simple)
 *   - get_context_for_query() → devuelve los top-N fragmentos como string de contexto
 *   - Reconstruye el índice automáticamente al guardar una lección
 *     o al completar una transcripción
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_AI_Knowledge_Base {

	const OPTION_PREFIX   = '_clms_kb_';
	const EMBED_PREFIX    = '_clms_kb_emb_';   // embeddings por curso
	const MAX_CHUNK_WORDS = 300;
	const MAX_CHUNKS      = 60;
	const EMBED_MODEL     = 'text-embedding-3-small'; // 1536 dims, coste mínimo
	const LESSON_PUBLIC_SNIPPET_META = '_clms_lesson_public_snippet';

	public function __construct() {
		add_action( 'save_post_lm_lesson', array( $this, 'on_lesson_saved' ), 30, 2 );
		add_action( 'updated_post_meta',   array( $this, 'on_meta_updated' ), 10, 4 );
		add_action( 'wp_ajax_clms_kb_rebuild',        array( $this, 'ajax_rebuild' ) );
		add_action( 'wp_ajax_clms_kb_embed',          array( $this, 'ajax_embed' ) );
		add_action( 'clms_kb_embed_async',            array( $this, 'embed_course_async' ), 10, 1 );
	}

	// ── Hooks ────────────────────────────────────────────────────────────────────

	public function on_lesson_saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $post_id ) ) : 0;
		if ( $course_id ) {
			$this->build_index( $course_id );
		}
	}

	public function on_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_clms_transcription_status' !== $meta_key || 'completed' !== $meta_value ) {
			return;
		}
		$course_id = class_exists( 'CLMS_Helper' ) ? absint( CLMS_Helper::get_course_id_from_lesson( $object_id ) ) : 0;
		if ( $course_id ) {
			$this->build_index( $course_id );
		}
	}

	public function ajax_rebuild() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_send_json_error( 'Sin permisos.', 403 );
		}
		check_ajax_referer( 'clms_kb_rebuild' );
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		if ( $course_id ) {
			$count = $this->build_index( $course_id );
			wp_send_json_success( array( 'chunks' => $count ) );
		}
		// Sin course_id: reconstruir todos los cursos
		$courses = get_posts( array(
			'post_type'      => 'lm_course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$total = 0;
		foreach ( $courses as $cid ) {
			$total += $this->build_index( $cid );
		}
		wp_send_json_success( array( 'courses' => count( $courses ), 'chunks' => $total ) );
	}

	// ── Indexación ────────────────────────────────────────────────────────────────

	/**
	 * Construye (o reconstruye) el índice de un curso.
	 *
	 * @param int $course_id
	 * @return int Número de chunks almacenados.
	 */
	public function build_index( $course_id ) {
		$course_id = absint( $course_id );

		if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
			return 0;
		}

		$chunks = array();

		// ── Metadatos del curso ──────────────────────────────────────────────────
		$course_post    = get_post( $course_id );
		$course_title   = $course_post ? get_the_title( $course_id ) : '';
		$course_excerpt = (string) get_post_meta( $course_id, '_clms_course_excerpt', true );
		$benefits       = (string) get_post_meta( $course_id, '_clms_course_benefits', true );
		$requirements   = (string) get_post_meta( $course_id, '_clms_course_requirements', true );
		$target         = (string) get_post_meta( $course_id, '_clms_course_target_audience', true );
		$ingress        = (string) get_post_meta( $course_id, '_clms_commercial_ingress_profile', true );
		$egress         = (string) get_post_meta( $course_id, '_clms_commercial_egress_profile', true );
		$faq_raw        = (string) get_post_meta( $course_id, '_clms_commercial_faq', true );
		$testimonials   = (string) get_post_meta( $course_id, '_clms_commercial_testimonials', true );

		$course_meta_text = implode( "\n", array_filter( array(
			$course_excerpt,
			$benefits ? 'Beneficios: ' . $benefits : '',
			$requirements ? 'Requisitos: ' . $requirements : '',
			$target ? 'Para quién: ' . $target : '',
			$ingress ? 'Perfil de ingreso: ' . $ingress : '',
			$egress ? 'Perfil de egreso: ' . $egress : '',
		) ) );

			if ( $course_meta_text ) {
				$chunks[] = array(
					'source'     => 'course_meta',
					'lesson_id'  => 0,
					'title'      => $course_title . ' — Descripción general',
					'text'       => $this->clean_text( $course_meta_text ),
					'public'     => true,
					'updated_at' => current_time( 'mysql' ),
				);
			}

			if ( $faq_raw ) {
				$chunks[] = array(
					'source'     => 'course_faq',
					'lesson_id'  => 0,
					'title'      => $course_title . ' — Preguntas frecuentes',
					'text'       => $this->clean_text( $faq_raw ),
					'public'     => true,
					'updated_at' => current_time( 'mysql' ),
				);
			}

			if ( $testimonials ) {
				$chunks[] = array(
					'source'     => 'course_testimonials',
					'lesson_id'  => 0,
					'title'      => $course_title . ' — Testimonios de alumnos',
					'text'       => $this->clean_text( $testimonials ),
					'public'     => true,
					'updated_at' => current_time( 'mysql' ),
				);
			}

		// ── Lecciones ────────────────────────────────────────────────────────────
		$lesson_ids = class_exists( 'CLMS_Helper' )
			? CLMS_Helper::get_course_lessons( $course_id )
			: array();

		foreach ( (array) $lesson_ids as $lesson_id ) {
			$lesson = get_post( $lesson_id );
			if ( ! $lesson || 'publish' !== $lesson->post_status ) {
				continue;
			}

			$lesson_title    = get_the_title( $lesson_id );
				$lesson_subtitle = (string) get_post_meta( $lesson_id, '_clms_lesson_subtitle', true );
				$lesson_public_snippet = (string) get_post_meta( $lesson_id, self::LESSON_PUBLIC_SNIPPET_META, true );
				$lesson_content  = wp_strip_all_tags( apply_filters( 'the_content', $lesson->post_content ) );
			$transcription   = class_exists( 'CLMS_Transcription' )
				? CLMS_Transcription::get_text( $lesson_id )
				: '';

			// Encabezado de la lección
			$header = $lesson_title . ( $lesson_subtitle ? ' — ' . $lesson_subtitle : '' );

			// Contenido escrito de la lección
				if ( $lesson_content ) {
					foreach ( $this->split_into_chunks( $lesson_content ) as $i => $chunk ) {
						$chunks[] = array(
							'source'     => 'lesson_content',
							'lesson_id'  => $lesson_id,
							'title'      => $header . ( $i > 0 ? ' (parte ' . ( $i + 1 ) . ')' : '' ),
							'text'       => $chunk,
							'public'     => false,
							'updated_at' => current_time( 'mysql' ),
						);
					}
				}

			// Transcripción
				if ( $transcription ) {
					foreach ( $this->split_into_chunks( $transcription ) as $i => $chunk ) {
						$chunks[] = array(
							'source'     => 'transcription',
							'lesson_id'  => $lesson_id,
							'title'      => $header . ' [transcripción' . ( $i > 0 ? ' parte ' . ( $i + 1 ) : '' ) . ']',
							'text'       => $chunk,
							'public'     => false,
							'updated_at' => current_time( 'mysql' ),
						);
					}
				}

				if ( $lesson_public_snippet ) {
					$chunks[] = array(
						'source'     => 'lesson_public_snippet',
						'lesson_id'  => $lesson_id,
						'title'      => $header . ' — Extracto público',
						'text'       => $this->clean_text( $lesson_public_snippet ),
						'public'     => true,
						'updated_at' => current_time( 'mysql' ),
					);
				}

			if ( count( $chunks ) >= self::MAX_CHUNKS ) {
				break;
			}
		}

		$chunks = array_slice( $chunks, 0, self::MAX_CHUNKS );

		update_option( self::OPTION_PREFIX . $course_id, $chunks, false );

		// Programar embedding asíncrono (no bloqueante)
		// Se ejecuta en el siguiente tick de WP-Cron.
		if ( ! wp_next_scheduled( 'clms_kb_embed_async', array( $course_id ) ) ) {
			wp_schedule_single_event( time() + 5, 'clms_kb_embed_async', array( $course_id ) );
		}

		return count( $chunks );
	}

	// ── Embeddings ────────────────────────────────────────────────────────────────

	/**
	 * AJAX manual: genera embeddings para un curso.
	 */
	public function ajax_embed() {
		if ( ! CLMS_Access::can_access_admin() ) {
			wp_send_json_error( 'Sin permisos.', 403 );
		}
		check_ajax_referer( 'clms_kb_embed' );
		$course_id = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
		if ( ! $course_id ) {
			wp_send_json_error( 'course_id requerido.' );
		}
		$count = $this->embed_course( $course_id );
		wp_send_json_success( array( 'embedded' => $count ) );
	}

	/**
	 * Gancho async (cron de un solo disparo) para generar embeddings sin bloquear.
	 */
	public function embed_course_async( $course_id ) {
		$this->embed_course( absint( $course_id ) );
	}

	/**
	 * Genera y almacena embeddings para todos los chunks de un curso.
	 * Si la API key no está configurada, no hace nada (el fallback TF seguirá funcionando).
	 *
	 * @param int $course_id
	 * @return int Número de chunks embebidos.
	 */
	public function embed_course( $course_id ) {
		$course_id = absint( $course_id );
		$chunks    = $this->get_chunks( $course_id );

		if ( empty( $chunks ) ) {
			return 0;
		}

		$api_key = $this->get_openai_key();
		if ( ! $api_key ) {
			return 0;
		}

		$texts = array_map( function( $c ) {
			return mb_substr( $c['title'] . ' ' . $c['text'], 0, 8000 );
		}, $chunks );

		$embeddings = $this->fetch_embeddings_batch( $texts, $api_key );

		if ( is_wp_error( $embeddings ) || empty( $embeddings ) ) {
			return 0;
		}

		update_option( self::EMBED_PREFIX . $course_id, $embeddings, false );

		return count( $embeddings );
	}

	/**
	 * Llama a la API de embeddings de OpenAI con hasta 20 textos a la vez.
	 *
	 * @param array  $texts   Textos a embeber.
	 * @param string $api_key
	 * @return array|WP_Error Array de vectores (array de floats) en el mismo orden que $texts.
	 */
	protected function fetch_embeddings_batch( $texts, $api_key ) {
		$results = array();

		// La API acepta hasta 2048 inputs, pero limitamos a 20 por llamada
		// para respetar los rate limits en planes gratuitos.
		$batches = array_chunk( $texts, 20 );

		foreach ( $batches as $batch ) {
			$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
			if ( ! $manager || ! method_exists( $manager, 'create_embeddings' ) ) {
				return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
			}

			$batch_embeddings = $manager->create_embeddings(
				$batch,
				array(
					'model'   => self::EMBED_MODEL,
					'api_key' => $api_key,
					'timeout' => 60,
				)
			);

			if ( is_wp_error( $batch_embeddings ) ) {
				return $batch_embeddings;
			}

			foreach ( $batch_embeddings as $embedding ) {
				$results[] = $embedding;
			}
		}

		return $results;
	}

	/**
	 * Genera el embedding de una sola consulta.
	 *
	 * @param string $query
	 * @param string $api_key
	 * @return array|WP_Error
	 */
	protected function fetch_query_embedding( $query, $api_key ) {
		$manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
		if ( ! $manager || ! method_exists( $manager, 'create_query_embedding' ) ) {
			return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ) );
		}

		return $manager->create_query_embedding(
			$query,
			array(
				'model'   => self::EMBED_MODEL,
				'api_key' => $api_key,
				'timeout' => 20,
			)
		);
	}

	/**
	 * Similitud coseno entre dos vectores.
	 *
	 * @param float[] $a
	 * @param float[] $b
	 * @return float 0.0 – 1.0
	 */
	protected function cosine_similarity( $a, $b ) {
		$dot  = 0.0;
		$norm_a = 0.0;
		$norm_b = 0.0;
		$len  = min( count( $a ), count( $b ) );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot    += $a[ $i ] * $b[ $i ];
			$norm_a += $a[ $i ] * $a[ $i ];
			$norm_b += $b[ $i ] * $b[ $i ];
		}

		$denom = sqrt( $norm_a ) * sqrt( $norm_b );
		return $denom > 0 ? $dot / $denom : 0.0;
	}

	// ── Búsqueda ─────────────────────────────────────────────────────────────────

	/**
	 * Devuelve los chunks más relevantes para una consulta.
	 * Usa embeddings semánticos si están disponibles; si no, cae en TF.
	 *
	 * @param int    $course_id
	 * @param string $query      Pregunta o tema del usuario.
	 * @param int    $top_n      Máximo de chunks a devolver.
	 * @return array
	 */
	public function search( $course_id, $query, $top_n = 4 ) {
		$course_id = absint( $course_id );
		$chunks    = $this->get_chunks( $course_id );

		if ( empty( $chunks ) ) {
			return array();
		}

		// ── Intento 1: búsqueda semántica con embeddings ──────────────────────
		$embeddings = $this->get_embeddings( $course_id );
		$api_key    = $this->get_openai_key();

		if ( $api_key && count( $embeddings ) === count( $chunks ) ) {
			$q_vec = $this->fetch_query_embedding( $query, $api_key );

			if ( ! is_wp_error( $q_vec ) && ! empty( $q_vec ) ) {
				$scored = array();
				foreach ( $embeddings as $i => $vec ) {
					$sim = $this->cosine_similarity( $q_vec, $vec );

					// Boosts semánticos por tipo de fuente
					if ( isset( $chunks[ $i ]['source'] ) ) {
						if ( 'transcription' === $chunks[ $i ]['source'] ) {
							$sim *= 1.15;
						} elseif ( 'course_faq' === $chunks[ $i ]['source'] ) {
							$sim *= 1.3;
						}
					}

					$scored[ $i ] = $sim;
				}

				arsort( $scored );
				$results = array();
				foreach ( array_slice( array_keys( $scored ), 0, $top_n, true ) as $idx ) {
					$results[] = $chunks[ $idx ];
				}
				return $results;
			}
		}

		// ── Fallback: búsqueda TF por palabras clave ──────────────────────────
		return $this->search_chunks_tf( $chunks, $query, $top_n );
	}

	/**
	 * Devuelve los chunks públicos más relevantes para una consulta.
	 *
	 * @param int    $course_id
	 * @param string $query
	 * @param int    $top_n
	 * @return array
	 */
	public function search_public( $course_id, $query, $top_n = 3 ) {
		$course_id = absint( $course_id );
		$chunks    = $this->get_chunks( $course_id );

		if ( empty( $chunks ) ) {
			return array();
		}

		$public_chunks = array_values(
			array_filter(
				$chunks,
				array( $this, 'is_public_chunk' )
			)
		);

		if ( empty( $public_chunks ) ) {
			return array();
		}

		return $this->search_chunks_tf( $public_chunks, $query, $top_n );
	}

	/**
	 * Construye un string de contexto listo para incluir en un prompt.
	 *
	 * @param int    $course_id
	 * @param string $query
	 * @param int    $top_n
	 * @return string
	 */
	public function get_context_for_query( $course_id, $query, $top_n = 4 ) {
		$chunks = $this->search( $course_id, $query, $top_n );

		if ( empty( $chunks ) ) {
			return '';
		}

		$parts = array();
		foreach ( $chunks as $chunk ) {
			$parts[] = '### ' . $chunk['title'] . "\n" . $chunk['text'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Construye un string de contexto público para modo ventas.
	 *
	 * @param int    $course_id
	 * @param string $query
	 * @param int    $top_n
	 * @return string
	 */
	public function get_public_context_for_query( $course_id, $query, $top_n = 3 ) {
		$chunks = $this->search_public( $course_id, $query, $top_n );

		if ( empty( $chunks ) ) {
			return '';
		}

		$parts = array();
		foreach ( $chunks as $chunk ) {
			$parts[] = '### ' . $chunk['title'] . "\n" . $chunk['text'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Devuelve el número de chunks indexados para un curso.
	 */
	public function get_chunk_count( $course_id ) {
		return count( $this->get_chunks( absint( $course_id ) ) );
	}

	/**
	 * Indica si el índice existe y tiene contenido.
	 */
	public function has_index( $course_id ) {
		return count( $this->get_chunks( absint( $course_id ) ) ) > 0;
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	protected function get_chunks( $course_id ) {
		$stored = get_option( self::OPTION_PREFIX . $course_id, array() );
		return is_array( $stored ) ? $stored : array();
	}

	protected function is_public_chunk( $chunk ) {
		if ( ! is_array( $chunk ) ) {
			return false;
		}

		if ( isset( $chunk['public'] ) ) {
			return (bool) $chunk['public'];
		}

		$source = isset( $chunk['source'] ) ? $chunk['source'] : '';
		return in_array(
			$source,
			array( 'course_meta', 'course_faq', 'course_testimonials', 'lesson_public_snippet' ),
			true
		);
	}

	protected function search_chunks_tf( $chunks, $query, $top_n ) {
		$query_words = $this->tokenize( $query );

		if ( empty( $query_words ) ) {
			return array_slice( $chunks, 0, $top_n );
		}

		$scored = array();

		foreach ( $chunks as $i => $chunk ) {
			$chunk_words = $this->tokenize( $chunk['text'] . ' ' . $chunk['title'] );
			$score       = 0;

			foreach ( $query_words as $word ) {
				$count = 0;
				foreach ( $chunk_words as $cw ) {
					if ( $cw === $word || ( strlen( $word ) > 4 && strpos( $cw, $word ) !== false ) ) {
						$count++;
					}
				}
				$score += $count > 0 ? ( $count / max( 1, count( $chunk_words ) ) ) : 0;
			}

			if ( 'transcription' === $chunk['source'] ) { $score *= 1.2; }
			if ( 'course_faq'    === $chunk['source'] ) { $score *= 1.5; }

			$scored[ $i ] = $score;
		}

		arsort( $scored );
		$top_indices = array_slice( array_keys( $scored ), 0, $top_n, true );

		$results = array();
		foreach ( $top_indices as $idx ) {
			if ( $scored[ $idx ] > 0 ) {
				$results[] = $chunks[ $idx ];
			}
		}

		if ( empty( $results ) ) {
			$results = array_slice( $chunks, 0, min( 2, $top_n ) );
		}

		return $results;
	}

	protected function get_embeddings( $course_id ) {
		$stored = get_option( self::EMBED_PREFIX . $course_id, array() );
		return is_array( $stored ) ? $stored : array();
	}

	protected function get_openai_key() {
		if ( class_exists( 'CLMS_AI_Settings_Service' ) && method_exists( 'CLMS_AI_Settings_Service', 'get_provider_api_key' ) ) {
			return (string) CLMS_AI_Settings_Service::get_provider_api_key( 'openai' );
		}

		$opts = (array) get_option( 'clms_ai_settings', array() );
		$key  = isset( $opts['openai_api_key'] ) ? trim( $opts['openai_api_key'] ) : '';
		if ( ! $key ) {
			$key = trim( (string) get_option( 'clms_openai_api_key', '' ) );
		}
		return $key;
	}

	/**
	 * Divide un texto largo en chunks de MAX_CHUNK_WORDS palabras
	 * con solape de 30 palabras para no perder contexto.
	 */
	protected function split_into_chunks( $text ) {
		$text  = $this->clean_text( $text );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $words ) <= self::MAX_CHUNK_WORDS ) {
			return array( $text );
		}

		$chunks  = array();
		$overlap = 30;
		$step    = self::MAX_CHUNK_WORDS - $overlap;
		$total   = count( $words );

		for ( $i = 0; $i < $total; $i += $step ) {
			$slice    = array_slice( $words, $i, self::MAX_CHUNK_WORDS );
			$chunks[] = implode( ' ', $slice );
			if ( ( $i + self::MAX_CHUNK_WORDS ) >= $total ) {
				break;
			}
		}

		return $chunks;
	}

	protected function clean_text( $text ) {
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	protected function tokenize( $text ) {
		$text  = mb_strtolower( $this->clean_text( $text ) );
		$words = preg_split( '/[\s,;:.!?()"\'-]+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		// Eliminar stopwords del español e inglés
		$stopwords = array(
			'de', 'la', 'el', 'en', 'un', 'una', 'los', 'las', 'del', 'al',
			'con', 'por', 'para', 'que', 'es', 'se', 'su', 'sus', 'si', 'no',
			'lo', 'le', 'les', 'como', 'más', 'pero', 'o', 'y', 'a', 'e',
			'the', 'a', 'an', 'is', 'in', 'on', 'at', 'to', 'of', 'and',
			'or', 'for', 'with', 'that', 'this', 'it', 'be', 'are', 'was',
		);

		return array_values( array_filter( $words, function( $w ) use ( $stopwords ) {
			return strlen( $w ) >= 3 && ! in_array( $w, $stopwords, true );
		} ) );
	}

	// ── API estática ─────────────────────────────────────────────────────────────

	/**
	 * Instancia singleton para usar desde otras clases.
	 *
	 * @return static
	 */
	public static function instance() {
		static $inst = null;
		if ( null === $inst ) {
			$inst = new static();
		}
		return $inst;
	}

	/**
	 * Atajo: devuelve contexto relevante para un curso y consulta.
	 *
	 * @param int    $course_id
	 * @param string $query
	 * @param int    $top_n
	 * @return string
	 */
	public static function context_for( $course_id, $query, $top_n = 4 ) {
		return self::instance()->get_context_for_query( $course_id, $query, $top_n );
	}
}
