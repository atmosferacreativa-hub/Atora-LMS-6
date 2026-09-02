<?php
/**
 * ATORA-LMS - AI Exams Module
 * 
 * Generates quiz questions from video transcriptions + course materials
 * using OpenAI GPT-4 API with high-quality pedagogical prompts.
 * 
 * @author @mundocap
 * @package ATORA_LMS
 * @subpackage AI_Exams
 * @version 1.0.0
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class CLMS_AI_Exams {

    /**
     * Initialize
     */
    public function __construct() {
        
        // Register REST routes — P2 (6.12.0): gateado por módulo 'ai'.
        // Fail-open si el registry aún no cargó, mismo criterio que el
        // resto del gate de módulos.
        if ( ! class_exists( 'CLMS_Module_Registry' ) || CLMS_Module_Registry::is_active( 'ai' ) ) {
            add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        }
        
        // Settings menu
        add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        
        // AJAX handlers
        add_action( 'wp_ajax_clms_generate_quiz', [ $this, 'ajax_generate_quiz' ] );
        add_action( 'wp_ajax_clms_get_quizzes', [ $this, 'ajax_get_quizzes' ] );
        add_action( 'wp_ajax_clms_save_quiz', [ $this, 'ajax_save_quiz' ] );
    }
    
    /**
     * Register REST Routes
     */
    public function register_routes() {
        // Upload content (video URL, PDF, text)
        register_rest_route( 'clms/v1', '/ai/upload', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_upload' ],
            'permission_callback' => function() { return CLMS_Helper::user_can_manage_lms(); }
        ] );
        
        // Generate quiz from uploaded content
        register_rest_route( 'clms/v1', '/ai/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'generate_quiz' ],
            'permission_callback' => function() { return CLMS_Helper::user_can_manage_lms(); }
        ] );
        
        // Get quizzes by course
        register_rest_route( 'clms/v1', '/ai/quizzes/(?P<course_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_quizzes' ],
            'permission_callback' => function() { return CLMS_Helper::user_can_manage_lms(); }
        ] );
        
        // Get single quiz
        register_rest_route( 'clms/v1', '/ai/quiz/(?P<quiz_id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_quiz' ],
            'permission_callback' => function() { return CLMS_Helper::user_can_manage_lms(); }
        ] );
        
        // Delete quiz
        register_rest_route( 'clms/v1', '/ai/quiz/(?P<quiz_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'delete_quiz' ],
            'permission_callback' => function() { return CLMS_Helper::user_can_manage_lms(); }
        ] );
    }
    
    /**
     * Handle Content Upload
     * Accepts: video URL, PDF file, text content
     */
    public function handle_upload( $request ) {
        $params = $request->get_json_params();
        
        // Validate input
        if ( ! isset( $params['content_type'] ) || ! isset( $params['content'] ) ) {
            return new WP_Error( 'invalid_request', __( 'Missing content_type or content', 'atora-lms' ), [ 'status' => 400 ] );
        }
        
        $content_type = sanitize_text_field( $params['content_type'] ); // 'video', 'pdf', 'text'
        $content = wp_kses_post( $params['content'] );
        
        // If video URL, transcribe it first
        if ( $content_type === 'video' ) {
            $content = $this->transcribe_video( $content );
            if ( is_wp_error( $content ) ) {
                return $content;
            }
        }
        
        // If PDF URL, extract text
        if ( $content_type === 'pdf' ) {
            $content = $this->extract_pdf_text( $content );
            if ( is_wp_error( $content ) ) {
                return $content;
            }
        }
        
        // Chunk content if too long (GPT-4 context limit)
        $chunks = $this->chunk_content( $content, 3000 );
        
        return rest_ensure_response( [
            'success'    => true,
            'file_id'    => md5( $content ),
            'content'    => $chunks[0], // First chunk for analysis
            'chunk_count' => count( $chunks ),
            'length'     => strlen( $content )
        ] );
    }
    
    /**
     * Generate Quiz from Content
     * Main endpoint for generating questions
     */
    public function generate_quiz( $request ) {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) || empty( $params ) ) {
            $params = $request->get_body_params();
        }
        if ( ! is_array( $params ) || empty( $params ) ) {
            $params = $request->get_params();
        }
        
        // Validate required parameters
        if ( ! isset( $params['content'] ) || ! isset( $params['course_id'] ) ) {
            return new WP_Error( 'invalid_request', __( 'Missing content or course_id', 'atora-lms' ), [ 'status' => 400 ] );
        }
        
        $content      = wp_kses_post( $params['content'] );
        $course_id    = intval( $params['course_id'] );
        $count        = intval( $params['count'] ?? 10 );
        $difficulty   = sanitize_text_field( $params['difficulty'] ?? 'mixed' ); // easy, medium, hard, mixed

        if ( ! $this->current_user_can_manage_quiz_generation_context( $course_id ) ) {
            return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para gestionar este curso.', 'atora-lms' ), [ 'status' => 403 ] );
        }
        
        // Validate count (limit to 20 max)
        if ( $count > 20 ) {
            $count = 20;
        }
        
        // Build prompt
        $prompt = $this->build_quiz_prompt( $content, $count, $difficulty );

        // Call via CLMS_AI_Manager — uses the active provider/key from centralised settings.
        $copilots = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Copilots') : null;
        if ( $copilots && method_exists( $copilots, 'run_with_meta' ) ) {
            $result = $copilots->run_with_meta(
                'teacher',
                'generate_quiz',
                array( array( 'role' => 'user', 'content' => $prompt ) ),
                array(
                    'system'      => 'You are an expert educational assessment specialist. Generate high-quality quiz questions only. Always respond with valid JSON arrays.',
                    'temperature' => 0.7,
                    'max_tokens'  => 2000,
                    'timeout'     => 30,
                ),
                array(
                    'course_id' => $course_id,
                    'screen'    => 'ai_exams',
                )
            );
        } else {
            $manager = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_AI_Manager') : null;
            if ( ! $manager || ! method_exists( $manager, 'chat_with_meta' ) ) {
                return new WP_Error( 'clms_ai_manager_missing', __( 'CLMS_AI_Manager no está disponible.', 'atora-lms' ), [ 'status' => 500 ] );
            }
            $result = $manager->chat_with_meta(
                array( array( 'role' => 'user', 'content' => $prompt ) ),
                array(
                    'system'      => 'You are an expert educational assessment specialist. Generate high-quality quiz questions only. Always respond with valid JSON arrays.',
                    'temperature' => 0.7,
                    'max_tokens'  => 2000,
                    'timeout'     => 30,
                )
            );
        }

        if ( is_wp_error( $result ) ) {
            error_log( 'CLMS AI request failed: ' . $result->get_error_message() );
            return $result;
        }

        $usage = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array();
        $total_cost = $this->estimate_cost_from_usage( $usage );
        $response = array(
            'content'    => isset( $result['text'] ) ? $result['text'] : '',
            'usage'      => $usage,
            'confidence' => 0.85,
            'cost'       => $total_cost,
        );

        // Parse response
        $quiz_data = $this->extract_json_array( $response['content'] );
        
        if ( ! is_array( $quiz_data ) ) {
            error_log( 'CLMS AI returned invalid JSON: ' . $response['content'] );
            return new WP_Error( 'invalid_response', __( 'Failed to parse AI response', 'atora-lms' ), [ 'status' => 500 ] );
        }
        
        // Save quiz to database
        $post_id = wp_insert_post( [
            'post_type'    => 'clms_ai_quiz',
            'post_title'   => 'AI Generated Quiz - ' . date( 'Y-m-d H:i' ),
            'post_content' => json_encode( $quiz_data ),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id()
        ] );
        
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        
        // Save metadata
        update_post_meta( $post_id, '_clms_course_id', $course_id );
        update_post_meta( $post_id, '_clms_quiz_source', 'ai_generated' );
        update_post_meta( $post_id, '_clms_quiz_confidence', $response['usage']['confidence'] ?? 0.85 );
        update_post_meta( $post_id, '_clms_quiz_type', 'multiple_choice' );
        update_post_meta( $post_id, '_clms_openai_cost', $response['cost'] ?? 0.05 );
        update_post_meta( $post_id, '_clms_teacher_reviewed', false );
        
        return rest_ensure_response( [
            'success'    => true,
            'quiz_id'    => $post_id,
            'questions'  => $quiz_data,
            'count'      => count( $quiz_data ),
            'cost'       => $response['cost'] ?? 0.05,
            'message'    => __( 'Quiz generated successfully', 'atora-lms' )
        ] );
    }
    
    /**
     * Build AI Prompt for Quiz Generation
     */
    private function build_quiz_prompt( $content, $count, $difficulty ) {
        $difficulty_guidance = '';
        
        if ( $difficulty === 'easy' ) {
            $difficulty_guidance = "All questions should be straightforward recall questions (30% of Bloom's taxonomy).";
        } elseif ( $difficulty === 'hard' ) {
            $difficulty_guidance = "All questions should be challenging, requiring analysis and synthesis (top 2 levels of Bloom's taxonomy).";
        } else {
            $difficulty_guidance = "Mix difficulty: 30% easy (recall), 50% medium (comprehension/application), 20% hard (analysis/synthesis).";
        }
        
        return <<<PROMPT
You are an expert educational content specialist with 20+ years of teaching experience.

TASK: Generate exactly $count multiple-choice questions from the provided educational content.

CONTENT:
---
{$content}
---

REQUIREMENTS:
1. Generate exactly $count questions (no more, no less)
2. Each question must have exactly 4 options (A, B, C, D)
3. Exactly ONE correct answer per question
4. Difficulty distribution: {$difficulty_guidance}
5. Questions should test understanding, not just recall
6. Distractors should be plausible but clearly incorrect
7. Language: Return ALL text in Spanish (es-ES)
8. Each question should align with learning objectives

QUALITY STANDARDS:
- Questions must be clear and unambiguous
- Avoid trick questions or wordplay
- Respect educational best practices
- Questions should be useful for actual assessment
- All options should be grammatically consistent

RETURN FORMAT:
Return ONLY a valid JSON array with NO additional text or markdown.
Structure for each question:
{
  "question": "¿Cuál es...?",
  "options": ["A) ...", "B) ...", "C) ...", "D) ..."],
  "correct_answer": "A",
  "difficulty": "medium",
  "explanation": "La respuesta es A porque..."
}

Generate exactly $count questions in JSON array format. NO OTHER TEXT.
PROMPT;
    }
    
    /**
     * Estima costo de uso OpenAI a partir de tokens.
     */
    private function estimate_cost_from_usage( $usage ) {
        $prompt_tokens     = isset( $usage['prompt_tokens'] ) ? (int) $usage['prompt_tokens'] : 0;
        $completion_tokens = isset( $usage['completion_tokens'] ) ? (int) $usage['completion_tokens'] : 0;

        if ( ! $prompt_tokens && ! $completion_tokens ) {
            return 0.05;
        }

        $input_cost  = ( $prompt_tokens / 1000 ) * 0.0005;
        $output_cost = ( $completion_tokens / 1000 ) * 0.0015;
        return round( $input_cost + $output_cost, 4 );
    }

    /**
     * Extrae un array JSON desde texto (maneja code fences).
     */
    private function extract_json_array( $text ) {
        $text = is_string( $text ) ? trim( $text ) : '';
        if ( '' === $text ) {
            return null;
        }

        if ( preg_match( '/```(?:json)?([\s\S]*?)```/i', $text, $m ) ) {
            $text = trim( $m[1] );
        }

        $data = json_decode( $text, true );
        if ( is_array( $data ) ) {
            return $data;
        }

        if ( preg_match( '/\[[\s\S]+\]/', $text, $m ) ) {
            $data = json_decode( $m[0], true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }

        return null;
    }
    
    /**
     * Get Quizzes by Course
     */
    public function get_quizzes( $request ) {
        $course_id = intval( $request['course_id'] );

        if ( ! $this->current_user_can_manage_course( $course_id ) ) {
            return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para gestionar este curso.', 'atora-lms' ), [ 'status' => 403 ] );
        }
        
        $args = [
            'post_type'      => 'clms_ai_quiz',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => '_clms_course_id',
                    'value' => $course_id
                ]
            ]
        ];
        
        $quizzes = new WP_Query( $args );
        
        $quiz_list = [];
        
        foreach ( $quizzes->posts as $quiz ) {
            $quiz_list[] = [
                'id'        => $quiz->ID,
                'title'     => $quiz->post_title,
                'date'      => $quiz->post_date,
                'reviewed'  => get_post_meta( $quiz->ID, '_clms_teacher_reviewed', true ),
                'cost'      => get_post_meta( $quiz->ID, '_clms_openai_cost', true )
            ];
        }
        
        return rest_ensure_response( $quiz_list );
    }
    
    /**
     * Get Single Quiz
     */
    public function get_quiz( $request ) {
        $quiz_id = intval( $request['quiz_id'] );
        
        $quiz = get_post( $quiz_id );
        
        if ( ! $quiz || 'clms_ai_quiz' !== $quiz->post_type ) {
            return new WP_Error( 'not_found', __( 'Quiz not found', 'atora-lms' ), [ 'status' => 404 ] );
        }

        $course_id = $this->get_quiz_course_id( $quiz->ID );
        if ( ! $this->current_user_can_manage_course( $course_id ) ) {
            return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para gestionar este quiz.', 'atora-lms' ), [ 'status' => 403 ] );
        }
        
        $questions = json_decode( $quiz->post_content, true );
        
        return rest_ensure_response( [
            'id'        => $quiz->ID,
            'title'     => $quiz->post_title,
            'questions' => $questions,
            'course_id' => $course_id,
            'reviewed'  => get_post_meta( $quiz->ID, '_clms_teacher_reviewed', true ),
            'cost'      => get_post_meta( $quiz->ID, '_clms_openai_cost', true )
        ] );
    }
    
    /**
     * Delete Quiz
     */
    public function delete_quiz( $request ) {
        $quiz_id = intval( $request['quiz_id'] );

        $quiz = get_post( $quiz_id );
        if ( ! $quiz || 'clms_ai_quiz' !== $quiz->post_type ) {
            return new WP_Error( 'not_found', __( 'Quiz not found', 'atora-lms' ), [ 'status' => 404 ] );
        }

        $course_id = $this->get_quiz_course_id( $quiz->ID );
        if ( ! $this->current_user_can_manage_course( $course_id ) ) {
            return new WP_Error( 'clms_forbidden', __( 'No tienes permisos para eliminar este quiz.', 'atora-lms' ), [ 'status' => 403 ] );
        }
        
        $deleted = wp_delete_post( $quiz_id, true );
        
        if ( ! $deleted ) {
            return new WP_Error( 'delete_failed', __( 'Could not delete quiz', 'atora-lms' ), [ 'status' => 500 ] );
        }
        
        return rest_ensure_response( [ 'success' => true, 'message' => __( 'Quiz deleted', 'atora-lms' ) ] );
    }

    /**
     * Comprueba permisos de gestión sobre un curso LMS concreto.
     *
     * @param int $course_id ID de curso lm_course.
     * @return bool
     */
    private function current_user_can_manage_course( $course_id ) {
        $course_id = absint( $course_id );
        if ( ! $course_id || 'lm_course' !== get_post_type( $course_id ) ) {
            return false;
        }

        return class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_manage_lms( $course_id );
    }

    /**
     * Contexto de generación de quizzes (curso o lección en compatibilidad).
     *
     * @param int $context_id ID de curso o lección.
     * @return bool
     */
    private function current_user_can_manage_quiz_generation_context( $context_id ) {
        $context_id = absint( $context_id );
        if ( ! $context_id ) {
            return false;
        }

        $post_type = get_post_type( $context_id );
        if ( 'lm_course' === $post_type ) {
            return $this->current_user_can_manage_course( $context_id );
        }

        if ( 'lm_lesson' === $post_type ) {
            return class_exists( 'CLMS_Helper' ) && CLMS_Helper::user_can_manage_lms( $context_id );
        }

        return false;
    }

    /**
     * Devuelve el curso asociado a un quiz IA.
     *
     * @param int $quiz_id ID de quiz.
     * @return int
     */
    private function get_quiz_course_id( $quiz_id ) {
        $quiz_id = absint( $quiz_id );
        if ( ! $quiz_id ) {
            return 0;
        }

        return absint( get_post_meta( $quiz_id, '_clms_course_id', true ) );
    }
    
    /**
     * Chunk Content (for long contexts)
     */
    private function chunk_content( $content, $chunk_size = 3000 ) {
        $words = explode( ' ', $content );
        $chunks = [];
        $current_chunk = '';
        
        foreach ( $words as $word ) {
            if ( strlen( $current_chunk ) + strlen( $word ) > $chunk_size ) {
                $chunks[] = $current_chunk;
                $current_chunk = $word;
            } else {
                $current_chunk .= ' ' . $word;
            }
        }
        
        if ( ! empty( $current_chunk ) ) {
            $chunks[] = $current_chunk;
        }
        
        return $chunks;
    }
    
    /**
     * Transcribe Video (uses existing CLMS_Video_Transcriptions if available)
     */
    private function transcribe_video( $url ) {
        // Check if CLMS_Video_Transcriptions module exists
        if ( class_exists( 'CLMS_Video_Transcriptions' ) ) {
            return CLMS_Video_Transcriptions::transcribe_from_url( $url );
        }
        
        return new WP_Error( 'no_transcription', __( 'Video transcription module not available', 'atora-lms' ) );
    }
    
    /**
     * Extract PDF Text
     */
    private function extract_pdf_text( $url ) {
        // Placeholder for PDF extraction
        // In production, use library like pdfparser or smalot/pdfparser
        return "PDF extraction not yet implemented. Please paste text content instead.";
    }
    
    /**
     * Add Settings Menu — redirects to the centralised AI settings page.
     */
    public function add_settings_menu() {
        // Página oculta del sidebar: accesible por URL directa.
        add_submenu_page(
            'clms-settings',
            __( 'AI Exams', 'atora-lms' ),
            __( 'AI Exams', 'atora-lms' ),
            'clms_access_admin',
            'clms-ai-exams',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render Settings Page.
     *
     * API keys and provider selection are managed centrally in CLMS_AI_Manager
     * (Settings → APIs e IA). This page shows usage stats and a link there.
     */
    public function render_settings_page() {
        $settings_url = admin_url( 'admin.php?page=clms-settings&tab=apis' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'AI Exams', 'atora-lms' ); ?></h1>

            <div class="notice notice-info inline">
                <p>
                    <?php
                    printf(
                        /* translators: %s: settings page link */
                        wp_kses(
                            __( 'API keys, provider, and model are managed in <a href="%s">Settings → APIs e IA</a>.', 'atora-lms' ),
                            array( 'a' => array( 'href' => array() ) )
                        ),
                        esc_url( $settings_url )
                    );
                    ?>
                </p>
            </div>

            <h2><?php esc_html_e( 'Usage Statistics', 'atora-lms' ); ?></h2>
            <table class="widefat" style="max-width:480px">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Metric', 'atora-lms' ); ?></th>
                        <th><?php esc_html_e( 'Value', 'atora-lms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php esc_html_e( 'Quizzes Generated', 'atora-lms' ); ?></td>
                        <td><?php echo intval( get_option( 'clms_ai_quizzes_generated', 0 ) ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'API Calls', 'atora-lms' ); ?></td>
                        <td><?php echo intval( get_option( 'clms_ai_api_calls', 0 ) ); ?></td>
                    </tr>
                    <tr>
                        <td><?php esc_html_e( 'Total Cost (Month)', 'atora-lms' ); ?></td>
                        <td>$<?php echo number_format( floatval( get_option( 'clms_ai_month_cost', 0 ) ), 2 ); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Register Settings — kept for backward compatibility; no new options registered here.
     */
    public function register_settings() {
        // All AI settings are owned by CLMS_AI_Manager (option: clms_ai_settings).
    }
    
    /**
     * AJAX: Generate Quiz
     *
     * Acepta:
     *   nonce      — wp_create_nonce('clms_generate_quiz_nonce')
     *   lesson_id  — ID de la lección
     *   file_id    — ID del attachment (cuando source = file/mixed)
     *   count      — número de preguntas (default 10)
     *   difficulty — easy | medium | hard | mixed
     */
    public function ajax_generate_quiz() {
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'clms_generate_quiz_nonce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Nonce inválido.', 'atora-lms' ) ), 403 );
        }

        if ( ! CLMS_Helper::user_can_manage_lms() ) {
            wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'atora-lms' ) ), 403 );
        }

	        $lesson_id  = isset( $_POST['lesson_id'] )  ? absint( wp_unslash( $_POST['lesson_id'] ) )  : 0;
	        $file_id    = isset( $_POST['file_id'] )     ? absint( wp_unslash( $_POST['file_id'] ) )    : 0;
	        $count      = isset( $_POST['count'] )       ? min( 20, max( 1, absint( wp_unslash( $_POST['count'] ) ) ) ) : 10;
	        $difficulty = isset( $_POST['difficulty'] )  ? sanitize_key( wp_unslash( $_POST['difficulty'] ) ) : 'mixed';
	        $replace_existing_raw = isset( $_POST['replace_existing'] ) ? sanitize_text_field( wp_unslash( $_POST['replace_existing'] ) ) : '0';
	        $replace_existing     = in_array( $replace_existing_raw, array( '1', 'true', 'yes' ), true );

        if ( ! $lesson_id || 'lm_lesson' !== get_post_type( $lesson_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Lección no válida.', 'atora-lms' ) ) );
        }

        if ( ! CLMS_Helper::user_can_manage_lms( $lesson_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No tienes permisos para gestionar esta lección.', 'atora-lms' ) ), 403 );
        }

        // ── Obtener contenido ──────────────────────────────────────────────────

        $content = '';

        // Texto del cuerpo de la lección.
        $lesson = get_post( $lesson_id );
        if ( $lesson ) {
            $content .= wp_strip_all_tags( $lesson->post_content );
        }

        // Archivo guía (PDF u otro texto extraíble).
        if ( $file_id ) {
            $file_path = get_attached_file( $file_id );
            $mime      = get_post_mime_type( $file_id );

            if ( $file_path && file_exists( $file_path ) ) {
                if ( 'application/pdf' === $mime ) {
                    // Extracción básica de texto plano desde PDF.
                    $text = $this->extract_pdf_text_local( $file_path );
                    if ( $text && ! is_wp_error( $text ) ) {
                        $content .= "\n\n" . $text;
                    }
                } elseif ( strpos( $mime, 'text/' ) === 0 ) {
                    $content .= "\n\n" . file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
                }
            }
        }

        $content = trim( $content );

        if ( '' === $content ) {
            wp_send_json_error( array( 'message' => __( 'No se encontró contenido para generar preguntas. Agrega texto a la lección o selecciona un archivo.', 'atora-lms' ) ) );
        }

        // ── Generar ────────────────────────────────────────────────────────────

        $course_id = class_exists( 'CLMS_Helper' ) ? (int) CLMS_Helper::get_course_id_from_lesson( $lesson_id ) : 0;

        $request = new WP_REST_Request( 'POST', '/clms/v1/ai/generate' );
        $payload = array(
            'content'    => $content,
            'course_id'  => $course_id ?: $lesson_id,
            'count'      => $count,
            'difficulty' => $difficulty,
        );

        if ( method_exists( $request, 'set_json_params' ) ) {
            $request->set_json_params( $payload );
        } else {
            $request->set_body_params( $payload );
        }

        $response = $this->generate_quiz( $request );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => $response->get_error_message() ) );
        }

        $data = $response->get_data();

	        // Guardar preguntas directamente en la meta de la lección.
	        if ( ! empty( $data['questions'] ) && is_array( $data['questions'] ) ) {
	            $existing = $replace_existing ? array() : (array) get_post_meta( $lesson_id, '_clms_quiz_questions', true );
	            $merged   = array_values( array_merge( $existing, array_values( $data['questions'] ) ) );
	            update_post_meta( $lesson_id, '_clms_quiz_questions', $merged );
	            update_post_meta( $lesson_id, '_clms_ai_question_bank_size', count( $merged ) );
	            update_post_meta( $lesson_id, '_clms_quiz_generated_at', current_time( 'mysql' ) );

            // Si el docente genera banco por IA, habilitamos la evaluación en la lección
            // para que el bloque de quiz pueda mostrarse inmediatamente en frontend.
            update_post_meta( $lesson_id, '_clms_quiz_enabled', '1' );
            update_post_meta( $lesson_id, '_lm_quiz_has_eval', 'yes' );
            update_post_meta( $lesson_id, 'lm_activity_type', 'quiz' );
            update_post_meta( $lesson_id, '_clms_activity_mode', 'quiz' );
	        }

	        $generated_count = count( $data['questions'] ?? array() );
	        $bank_count      = count( (array) get_post_meta( $lesson_id, '_clms_quiz_questions', true ) );
	        wp_send_json_success( array(
	            'message'         => $replace_existing
	                ? sprintf( '%d preguntas regeneradas. El banco anterior fue reemplazado.', $generated_count )
	                : sprintf( '%d preguntas generadas y guardadas en el banco.', $generated_count ),
	            'questions_count' => $generated_count,
	            'bank_count'      => $bank_count,
	            'replaced'        => $replace_existing,
	        ) );
	    }

    /**
     * Extrae texto plano de un PDF local usando pdftotext si está disponible,
     * o un fallback de lectura de texto embebido.
     */
    private function extract_pdf_text_local( $file_path ) {
        // Intentar con pdftotext (poppler-utils) si está instalado en el servidor.
        if ( $this->exec_available() ) {
            $escaped = escapeshellarg( $file_path );
            $text    = array();
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
            exec( "pdftotext {$escaped} - 2>/dev/null", $text );
            if ( ! empty( $text ) ) {
                return implode( "\n", $text );
            }
        }

        // Fallback: leer texto plano embebido en el PDF (funciona solo para PDFs simples).
        $raw = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( false === $raw ) {
            return '';
        }
        // Extraer texto entre paréntesis del stream PDF (muy básico).
        preg_match_all( '/\(([^\)]{4,})\)/', $raw, $m );
        return isset( $m[1] ) ? implode( ' ', array_filter( $m[1], fn( $s ) => ctype_print( $s ) ) ) : '';
    }

    /**
     * Verifica si exec() está disponible y no está en disable_functions.
     *
     * @return bool
     */
    private function exec_available() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }

        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

        return ! in_array( 'exec', $disabled, true );
    }

    /**
     * AJAX: Get Quizzes
     */
    public function ajax_get_quizzes() {
        check_ajax_referer( 'clms_nonce' );
        $course_id = isset( $_GET['course_id'] ) ? intval( $_GET['course_id'] ) : 0;
        
        if ( empty( $course_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta course_id.', 'atora-lms' ) ), 400 );
        }

        if ( ! $this->current_user_can_manage_course( $course_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No tienes permisos para gestionar este curso.', 'atora-lms' ) ), 403 );
        }
        
        $request = new WP_REST_Request( 'GET', '/clms/v1/ai/quizzes/' . $course_id );
        $response = $this->get_quizzes( $request );

        if ( is_wp_error( $response ) ) {
            $error_data = $response->get_error_data();
            $status     = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 403;
            wp_send_json_error( array( 'message' => $response->get_error_message() ), $status );
        }
        
        wp_send_json_success( $response );
    }
    
    /**
     * AJAX: Save Quiz
     */
    public function ajax_save_quiz() {
        check_ajax_referer( 'clms_nonce' );
        
        $quiz_id = isset( $_POST['quiz_id'] ) ? intval( $_POST['quiz_id'] ) : 0;
        
        if ( empty( $quiz_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Falta quiz_id.', 'atora-lms' ) ), 400 );
        }

        $quiz = get_post( $quiz_id );
        if ( ! $quiz || 'clms_ai_quiz' !== $quiz->post_type ) {
            wp_send_json_error( array( 'message' => __( 'Quiz no válido.', 'atora-lms' ) ), 404 );
        }

        $course_id = $this->get_quiz_course_id( $quiz_id );
        if ( ! $this->current_user_can_manage_course( $course_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No tienes permisos para gestionar este quiz.', 'atora-lms' ) ), 403 );
        }
        
        // Mark as teacher reviewed
        update_post_meta( $quiz_id, '_clms_teacher_reviewed', true );
        
        wp_send_json_success( [ 'message' => __( 'Quiz saved', 'atora-lms' ) ] );
    }
}

// La clase es instanciada por CLMS_Loader. No se instancia aquí.

// Register CPT for AI Generated Quizzes
if ( ! function_exists( 'clms_register_ai_quiz_cpt' ) ) {
    function clms_register_ai_quiz_cpt() {
        register_post_type( 'clms_ai_quiz', [
            'label'               => __( 'AI Quizzes', 'atora-lms' ),
            'public'              => false,
            'show_ui'             => false,
            'supports'            => [ 'title', 'editor', 'custom-fields' ],
            'has_archive'         => false,
            'rewrite'             => false,
            'hierarchical'        => false,
            'query_var'           => false,
            'can_export'          => true,
        ] );
    }
    
    add_action( 'init', 'clms_register_ai_quiz_cpt' );
}
?>
