<?php

/**
 * CLMS_Grading — Calificaciones, resumen académico y SpeedGrade.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Grading_Helpers_Trait {
	protected function get_quiz_grade( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return null;
		}

		$key   = 'clms_quiz_attempt_' . $lesson_id;
		$value = get_user_meta( $user_id, $key, true );

		if ( is_array( $value ) && isset( $value['score'] ) ) {
			return max( 0, min( 100, absint( $value['score'] ) ) );
		}

		if ( '' !== (string) $value ) {
			return max( 0, min( 100, absint( $value ) ) );
		}

		return null;
	}

	public function get_user_submission_for_grading( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return array();
		}

		$submission_ids = get_posts(
			array(
				'post_type'      => self::SUBMISSION_CPT,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_clms_submission_user_id',
						'value' => $user_id,
					),
					array(
						'key'   => '_clms_submission_lesson_id',
						'value' => $lesson_id,
					),
				),
			)
		);

		if ( empty( $submission_ids ) ) {
			return array();
		}

		$sid = absint( $submission_ids[0] );

		return array(
			'submission_id' => $sid,
			'status'        => (string) get_post_meta( $sid, '_clms_submission_status', true ),
			'grade'         => get_post_meta( $sid, '_clms_submission_grade', true ),
			'feedback'      => (string) get_post_meta( $sid, '_clms_submission_feedback', true ),
			'comment'       => (string) get_post_meta( $sid, '_clms_submission_comment', true ),
			'files'         => get_post_meta( $sid, '_clms_submission_files', true ),
		);
	}

	public function is_lesson_completed_by_user( $user_id, $lesson_id ) {
		$user_id   = absint( $user_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $user_id || ! $lesson_id ) {
			return false;
		}

		$completed = get_user_meta( $user_id, '_clms_completed_lessons', true );
		$completed = is_array( $completed ) ? array_map( 'absint', $completed ) : array();

		if ( in_array( $lesson_id, $completed, true ) ) {
			return true;
		}

		$submission = $this->get_user_submission_for_grading( $user_id, $lesson_id );
		if ( empty( $submission ) ) {
			return false;
		}

		$status = isset( $submission['status'] ) ? (string) $submission['status'] : '';
		$grade  = isset( $submission['grade'] ) ? $submission['grade'] : '';

		return ( 'graded' === $status || '' !== (string) $grade );
	}

	protected function calculate_average( array $values ) {
		if ( empty( $values ) ) {
			return 0;
		}

		return (int) round( array_sum( $values ) / count( $values ) );
	}

	protected function get_empty_summary() {
		return array(
			'completed_lessons'  => 0,
			'total_lessons'      => 0,
			'progress_percent'   => 0,
			'quiz_average'       => 0,
			'assignment_average' => 0,
			'final_average'      => 0,
			'graded_lessons'     => 0,
			'source_breakdown'   => array(),
			'updated_at'         => '',
		);
	}

	protected function get_submission_status_label( $status ) {
		switch ( (string) $status ) {
			case 'graded':
				return __( 'Calificada', 'atora-lms' );
			case 'needs_revision':
				return __( 'Requiere revisión', 'atora-lms' );
			case 'returned':
				return __( 'Devuelta para mejora', 'atora-lms' );
			case 'in_review':
				return __( 'En revisión', 'atora-lms' );
			case 'submitted':
				return __( 'Enviada', 'atora-lms' );
			default:
				return __( 'Actualizada', 'atora-lms' );
		}
	}

	protected function format_datetime( $datetime ) {
		$datetime = (string) $datetime;

		if ( ! $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( ! $timestamp ) {
			return $datetime;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	protected function format_assessment_label( $value ) {
		$value = sanitize_key( (string) $value );

		$labels = array(
			'manual'        => __( 'Manual', 'atora-lms' ),
			'ai_assisted'   => __( 'IA asistida', 'atora-lms' ),
			'ai_auto_grade' => __( 'IA automática', 'atora-lms' ),
			'peer_review'   => __( 'Revisión entre pares', 'atora-lms' ),
			'hybrid'        => __( 'Híbrido', 'atora-lms' ),
			'lectura'       => __( 'Lectura', 'atora-lms' ),
			'tarea'         => __( 'Tarea', 'atora-lms' ),
			'quiz'          => __( 'Cuestionario', 'atora-lms' ),
			'practice'      => __( 'Práctica', 'atora-lms' ),
			'read_only'     => __( 'Solo lectura', 'atora-lms' ),
			'assignment'    => __( 'Tarea', 'atora-lms' ),
			'partial_exam'  => __( 'Evaluación parcial', 'atora-lms' ),
			'final_exam'    => __( 'Evaluación final', 'atora-lms' ),
			'certifiable_evidence' => __( 'Evidencia certificable', 'atora-lms' ),
			'required'      => __( 'Requisito obligatorio', 'atora-lms' ),
			'sin_evidencia' => __( 'Sin evidencia', 'atora-lms' ),
			'en_desarrollo' => __( 'En desarrollo', 'atora-lms' ),
			'competente'    => __( 'Competente', 'atora-lms' ),
			'destacado'     => __( 'Destacado', 'atora-lms' ),
		);

		return isset( $labels[ $value ] ) ? $labels[ $value ] : ( $value ? ucfirst( str_replace( '_', ' ', $value ) ) : __( '—', 'atora-lms' ) );
	}

	protected function format_risk_label( $risk ) {
		$risk = sanitize_key( (string) $risk );

		$labels = array(
			'high'      => __( 'Alto', 'atora-lms' ),
			'medium'    => __( 'Medio', 'atora-lms' ),
			'normal'    => __( 'Normal', 'atora-lms' ),
			'en_riesgo' => __( 'En riesgo', 'atora-lms' ),
			'al_dia'    => __( 'Al día', 'atora-lms' ),
		);

		return isset( $labels[ $risk ] ) ? $labels[ $risk ] : __( 'Sin datos', 'atora-lms' );
	}

	protected function format_certificate_status_label( $status ) {
		$status = sanitize_key( (string) $status );

		$labels = array(
			'valid'    => __( 'Válido', 'atora-lms' ),
			'issued'   => __( 'Emitido', 'atora-lms' ),
			'eligible' => __( 'Elegible', 'atora-lms' ),
			'pending'  => __( 'En progreso', 'atora-lms' ),
			'revoked'  => __( 'Revocado', 'atora-lms' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Sin datos', 'atora-lms' );
	}

	protected function get_return_url() {
		if ( empty( $_GET[ self::SPEEDGRADE_RETURN ] ) ) {
			return '';
		}

		return esc_url_raw( rawurldecode( wp_unslash( $_GET[ self::SPEEDGRADE_RETURN ] ) ) );
	}

	protected function get_return_url_from_post() {
		if ( empty( $_POST[ self::SPEEDGRADE_RETURN ] ) ) {
			return '';
		}

		return esc_url_raw( rawurldecode( wp_unslash( $_POST[ self::SPEEDGRADE_RETURN ] ) ) );
	}

	protected function render_notice_card( $title, $text ) {
		$this->enqueue_assets();

		ob_start();
		?>
		<div class="clms-sg-wrap">
			<div class="clms-sg-shell">
				<div class="clms-sg-card">
					<div class="clms-sg-eyebrow"><?php esc_html_e( 'SpeedGrade', 'atora-lms' ); ?></div>
					<h2 class="clms-sg-subtitle"><?php echo esc_html( $title ); ?></h2>
					<p class="clms-sg-copy"><?php echo esc_html( $text ); ?></p>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	protected function enqueue_assets() {
		if ( ! wp_style_is( 'clms-speedgrade', 'registered' ) ) {
			wp_register_style(
				'clms-speedgrade',
				false,
				array(),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0'
			);
		}

		wp_enqueue_style( 'clms-speedgrade' );
		$base_url = defined( 'ATORA_LMS_URL' )
			? ATORA_LMS_URL
			: ATORA_LMS_URL;
		wp_enqueue_script(
			'atora-ui',
			$base_url . 'assets/js/atora-ui.js',
			array(),
			defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0',
			true
		);
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'add_ui_i18n_script' ) ) {
			CLMS_Helper::add_ui_i18n_script( 'atora-ui' );
		}

		if ( self::$assets_enqueued ) {
			return;
		}

		wp_add_inline_style( 'clms-speedgrade', $this->get_inline_css() );

		self::$assets_enqueued = true;
	}

	protected function get_inline_css() {
		return '
		.clms-sg-wrap,.clms-sg-wrap *{box-sizing:border-box}
		.clms-sg-wrap{
			--sg-bg:#f8fafc;
			--sg-card:#ffffff;
			--sg-ink:#0f172a;
			--sg-ink-2:#334155;
			--sg-muted:#64748b;
			--sg-border:#e2e8f0;
			--sg-accent:#6366f1;
			--sg-accent-2:#4f46e5;
			--sg-shadow:0 12px 32px rgba(15,23,42,.08);
			background:var(--sg-bg);
			min-height:100vh;
			padding:28px 16px;
			color:var(--sg-ink);
			font-family:inherit;
		}
			.clms-sg-layout{
				max-width:1520px;
				margin:0 auto;
				display:grid;
				grid-template-columns:260px minmax(0,1fr);
				gap:20px;
				align-items:start;
			}
			.clms-sg-shell{max-width:1280px;margin:0 auto;display:grid;gap:20px}
			.clms-sg-layout .clms-sg-shell{max-width:none;margin:0}
			.clms-sg-queue-sidebar{
				position:sticky;
				top:16px;
				max-height:calc(100vh - 40px);
				overflow:auto;
				background:var(--sg-card);
				border:1px solid var(--sg-border);
				border-radius:18px;
				box-shadow:var(--sg-shadow);
				padding:12px 0;
			}
			.clms-sg-queue-header{
				padding:0 12px 10px;
				display:grid;
				gap:10px;
				border-bottom:1px solid var(--sg-border);
			}
			.clms-sg-queue-counter{font-size:13px;font-weight:700;color:var(--sg-ink)}
			.clms-sg-queue-filter{
				width:100%;
				border:1px solid var(--sg-border);
				border-radius:10px;
				padding:7px 9px;
				background:#fff;
				color:var(--sg-ink);
			}
			.clms-sg-queue-list{list-style:none;margin:0;padding:0}
			.clms-sg-queue-item{border-bottom:1px solid #eef2f7}
			.clms-sg-queue-item a{
				text-decoration:none;
				color:inherit;
				display:grid;
				gap:4px;
				padding:10px 12px;
			}
			.clms-sg-queue-item strong{font-size:13px;color:var(--sg-ink)}
			.clms-sg-queue-item span{font-size:12px;color:var(--sg-ink-2);line-height:1.4}
			.clms-sg-queue-status{
				display:inline-flex;
				width:max-content;
				padding:2px 8px;
				border-radius:999px;
				background:#eef2ff;
				color:#4338ca !important;
				font-size:11px !important;
				font-weight:700;
			}
			.clms-sg-queue-priority{
				display:inline-flex;
				width:max-content;
				padding:2px 8px;
				border-radius:999px;
				font-size:11px !important;
				font-weight:700;
			}
			.clms-sg-queue-priority.is-warning{background:#fef3c7;color:#92400e !important}
			.clms-sg-queue-priority.is-success{background:#dcfce7;color:#166534 !important}
			.clms-sg-queue-priority.is-info{background:#dbeafe;color:#1e40af !important}
			.clms-sg-queue-priority.is-urgent{background:#fee2e2;color:#991b1b !important}
			.clms-sg-queue-item.is-active{
				background:#eef2ff;
				border-left:3px solid var(--sg-accent);
			}
			.clms-sg-queue-hint{
				padding:10px 12px 0;
				font-size:12px;
				color:var(--sg-muted);
				display:flex;
				gap:6px;
				align-items:center;
				flex-wrap:wrap;
			}
			.clms-sg-queue-hint kbd{
				padding:2px 6px;
				border:1px solid var(--sg-border);
				border-radius:6px;
				background:#f8fafc;
				font-size:11px;
				color:var(--sg-ink);
			}
		.clms-sg-topbar{display:flex;justify-content:flex-end;align-items:center;gap:12px;flex-wrap:wrap}
		.clms-sg-topbar__actions{display:flex;gap:12px;flex-wrap:wrap}
		.clms-sg-hero{
			display:grid;
			grid-template-columns:minmax(0,1.2fr) minmax(320px,.8fr);
			gap:20px;
			padding:28px;
			border-radius:28px;
			color:#fff;
			background:linear-gradient(135deg,#0f172a 0%, #172554 55%, #1e1b4b 100%);
		}
		.clms-sg-hero__main,.clms-sg-hero__stats{min-width:0;display:grid;gap:16px;align-content:start}
		.clms-sg-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:24px;align-items:start}
		.clms-sg-main,.clms-sg-side{min-width:0;display:grid;gap:24px}
		.clms-sg-card{
			min-width:0;
			background:var(--sg-card);
			border:1px solid var(--sg-border);
			border-radius:24px;
			box-shadow:var(--sg-shadow);
			padding:24px;
			overflow:hidden;
		}
		.clms-sg-card--accent{
			background:linear-gradient(135deg,#0f172a 0%, #172554 45%, #312e81 100%);
			color:#fff;
			border-color:transparent;
		}
		.clms-sg-section-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:18px}
		.clms-sg-eyebrow{
			display:inline-flex;align-items:center;gap:8px;
			padding:7px 12px;border-radius:999px;background:#eef2ff;color:#4338ca;
			font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
		}
		.clms-sg-eyebrow--light{background:rgba(255,255,255,.12);color:#e0e7ff}
		.clms-sg-pill{
			display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;
			background:rgba(255,255,255,.12);color:#e2e8f0;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;width:max-content;
		}
		.clms-sg-title,.clms-sg-subtitle,.clms-sg-file__title{overflow-wrap:anywhere;word-break:break-word}
		.clms-sg-title{margin:0;font-size:30px;line-height:1.08;font-weight:800}
		.clms-sg-subtitle{margin:6px 0 0;font-size:22px;line-height:1.2;font-weight:800;color:inherit}
		.clms-sg-subtitle--light{color:#fff}
		.clms-sg-copy{margin:0;color:var(--sg-ink-2);line-height:1.7}
		.clms-sg-copy--light{color:#e2e8f0}
		.clms-sg-stat{
			background:rgba(255,255,255,.08);
			border:1px solid rgba(255,255,255,.12);
			border-radius:18px;
			padding:16px 18px;
		}
		.clms-sg-stat__label{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#cbd5e1}
		.clms-sg-stat__value{margin-top:8px;font-size:24px;font-weight:800;color:#fff}
		.clms-sg-stat__value--sm{font-size:16px;line-height:1.45}
		.clms-sg-meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
		.clms-sg-meta-item{
			background:#f8fafc;border:1px solid var(--sg-border);border-radius:18px;padding:16px;
			display:grid;gap:6px;
		}
		.clms-sg-meta-item span{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--sg-muted)}
		.clms-sg-meta-item strong{font-size:15px;color:var(--sg-ink)}
		.clms-sg-comment{margin-top:20px;display:grid;gap:10px}
		.clms-sg-comment h3,.clms-sg-ai-block h3{margin:0;font-size:16px;line-height:1.3}
		.clms-sg-files{display:grid;gap:14px}
		.clms-sg-file{
			display:flex;justify-content:space-between;gap:12px;align-items:center;
			padding:16px;border:1px solid var(--sg-border);border-radius:18px;background:#fff;
		}
		.clms-sg-file__content{min-width:0;display:grid;gap:6px}
		.clms-sg-file__title{margin:0;font-size:15px;font-weight:700;color:var(--sg-ink)}
		.clms-sg-file__meta{margin:0;font-size:13px;color:var(--sg-muted)}
		.clms-sg-history-list{display:grid;gap:12px}
		.clms-sg-history-item{
			display:flex;
			justify-content:space-between;
			gap:12px;
			align-items:flex-start;
			padding:14px;
			border:1px solid var(--sg-border);
			border-radius:16px;
			background:#fff;
		}
		.clms-sg-history-item strong{display:block;margin-bottom:4px;font-size:14px}
		.clms-sg-audit-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}
		.clms-sg-audit-kpi{padding:12px 14px;border:1px solid var(--sg-border);border-radius:16px;background:#fff}
		.clms-sg-audit-kpi span{display:block;font-size:12px;color:var(--sg-muted);margin-bottom:4px}
		.clms-sg-audit-summary{display:grid;gap:4px;margin-top:14px}
		.clms-sg-audit-log{display:grid;gap:12px;margin-top:14px}
		.clms-sg-audit-entry{padding:14px;border:1px solid var(--sg-border);border-radius:16px;background:rgba(255,255,255,.92)}
		.clms-sg-audit-entry__top{display:flex;justify-content:space-between;gap:10px;align-items:center;font-size:13px;margin-bottom:6px}
		.clms-sg-form,.clms-sg-ai-block{display:grid;gap:14px}
		.clms-sg-field{display:grid;gap:8px}
		.clms-sg-field label{font-size:14px;font-weight:700;color:inherit}
		.clms-sg-field input,.clms-sg-field select,.clms-sg-field textarea,.clms-sg-ai-draft{
			width:100%;max-width:100%;
			border:1px solid #cbd5e1;border-radius:14px;padding:12px 14px;
			background:#fff;color:#0f172a;font:inherit;
		}
		.clms-sg-ai-draft{resize:vertical;background:#f8fafc}
		.clms-sg-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
		.clms-sg-btn{
			display:inline-flex;align-items:center;justify-content:center;
			padding:11px 16px;border-radius:14px;border:1px solid transparent;
			text-decoration:none;font-weight:700;cursor:pointer;transition:.18s ease;
		}
		.clms-sg-btn-spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:clms-sg-spin .9s linear infinite}
		.clms-sg-btn--secondary .clms-sg-btn-spinner{border-color:rgba(15,23,42,.25);border-top-color:#0f172a}
		.clms-sg-btn--primary{background:var(--sg-accent);color:#fff}
		.clms-sg-btn--primary:hover{background:var(--sg-accent-2);color:#fff}
		.clms-sg-btn--secondary{background:#fff;color:var(--sg-ink);border-color:var(--sg-border)}
		.clms-sg-btn--secondary:hover{background:#f8fafc;color:var(--sg-ink)}
		.clms-sg-flash{
			padding:14px 16px;border-radius:16px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;
		}
		.clms-sg-flash.is-error{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
		.clms-sg-ai-status{margin-bottom:14px;color:var(--sg-ink-2)}
		.clms-sg-ai-list{margin:0;padding-left:18px;display:grid;gap:8px;color:var(--sg-ink-2)}
			@media (max-width: 960px){
				.clms-sg-layout{grid-template-columns:1fr}
				.clms-sg-queue-sidebar{display:none}
				.clms-sg-hero,.clms-sg-grid{grid-template-columns:1fr}
				.clms-sg-meta-grid{grid-template-columns:1fr}
				.clms-sg-audit-meta{grid-template-columns:1fr}
				.clms-sg-file{flex-direction:column;align-items:flex-start}
				.clms-sg-history-item{flex-direction:column;align-items:flex-start}
			}
		@keyframes clms-sg-spin{to{transform:rotate(360deg)}}';
	}
}
