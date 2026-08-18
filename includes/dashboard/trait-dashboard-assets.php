<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Dashboard_Assets_Trait {
	protected function enqueue_assets() {
		wp_register_style( 'clms-dashboard', false, array(), defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '1.0.0' );
		wp_enqueue_style( 'clms-dashboard' );

		$assets_url  = defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL : '';
		$assets_path = dirname( __DIR__ ) . '/assets/';
		$student_css = $assets_path . 'css/student-dashboard.css';
		$student_js  = $assets_path . 'js/student-dashboard.js';

		if ( $assets_url && $assets_path && file_exists( $student_css ) ) {
			wp_enqueue_style(
				'clms-student-dashboard-ui',
				$assets_url . 'css/student-dashboard.css',
				array( 'clms-dashboard' ),
				(string) filemtime( $student_css )
			);
		}

		if ( ! self::$assets_enqueued ) {
			wp_add_inline_style( 'clms-dashboard', $this->get_inline_css() );
			self::$assets_enqueued = true;
		}

		// Grade Breakdown component (v4.22).
		if ( ! wp_script_is( 'atora-grade-breakdown', 'enqueued' ) ) {
			wp_enqueue_script(
				'atora-grade-breakdown',
				defined( 'ATORA_LMS_ASSETS_URL' ) ? ATORA_LMS_ASSETS_URL . 'js/grade-breakdown.js' : '',
				array(),
				defined( 'CLMS_VERSION' ) ? CLMS_VERSION : '4.22',
				true
			);
		}

		if ( $assets_url && $assets_path && file_exists( $student_js ) ) {
			wp_enqueue_script(
				'clms-student-dashboard-ui',
				$assets_url . 'js/student-dashboard.js',
				array(),
				(string) filemtime( $student_js ),
				true
			);
		}
	}

	protected function get_inline_css() {
		return '
.clms-sd,.clms-sd *{box-sizing:border-box}
.clms-sd{
	--sd-card:var(--clms-bg,#fff);
	--sd-ink:var(--clms-ink,#0f172a);
	--sd-ink-2:var(--clms-ink-2,#334155);
	--sd-muted:var(--clms-muted,#64748b);
	--sd-border:var(--clms-border-soft,#e2e8f0);
	--sd-accent:#6366f1;
	--sd-accent-2:#4f46e5;
	--sd-success:var(--clms-badge-green-ink,#16a34a);
	--sd-success-bg:var(--clms-badge-green-bg,#f0fdf4);
	--sd-warn:var(--clms-badge-yellow-ink,#b45309);
	--sd-warn-bg:var(--clms-badge-yellow-bg,#fef3c7);
	--sd-info:#1d4ed8;
	--sd-info-bg:#dbeafe;
	--sd-shadow:0 10px 30px rgba(15,23,42,.06);
	color:var(--sd-ink);
	font-family:inherit;
	display:grid;
	gap:24px;
	max-width:1280px;
	margin:0 auto;
	padding:0 0 24px;
}
.clms-sd a{text-decoration:none}
.clms-sd__guest{display:grid}
.clms-sd-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:24px;align-items:start}
.clms-sd-main,.clms-sd-side{min-width:0;display:grid;gap:24px}
.clms-sd-card{background:var(--sd-card);border:1px solid var(--sd-border);border-radius:24px;box-shadow:var(--sd-shadow);padding:24px;overflow:hidden}
.clms-sd-card--accent{background:linear-gradient(135deg,#0f172a 0%,#172554 45%,#312e81 100%);color:#fff;border-color:transparent}
.clms-sd-card__body{display:grid;gap:12px}
.clms-sd-card__actions{margin-top:18px}
.clms-sd-eyebrow{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;width:max-content;max-width:100%}
.clms-sd-eyebrow--light{background:rgba(255,255,255,.12);color:#e0e7ff}
.clms-sd-pill{display:inline-flex;align-items:center;padding:8px 14px;border-radius:999px;background:rgba(255,255,255,.12);color:#e2e8f0;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;width:max-content;max-width:100%}
.clms-sd-title,.clms-sd-hero__title,.clms-sd-course__title,.clms-sd-list-card__title,.clms-sd-mini__title{overflow-wrap:anywhere;word-break:break-word}
.clms-sd-title{font-size:28px;line-height:1.15;font-weight:800;letter-spacing:-.02em;margin:0;color:var(--sd-ink)}
.clms-sd-title--light{color:#fff}
.clms-sd-hero{display:flex;flex-wrap:wrap;align-items:start;gap:24px;padding:28px;border-radius:28px;background:radial-gradient(circle at top right, rgba(99,102,241,.28), transparent 35%),linear-gradient(135deg,#0f172a 0%,#172554 55%,#1e1b4b 100%);color:#fff;overflow:hidden}
.clms-sd-hero__copy,.clms-sd-hero__stats{min-width:0;display:grid;gap:18px;align-content:start;flex:1 1 100%}
.clms-sd-hero__copy{flex:1.55 1 480px}
.clms-sd-hero__stats{flex:.95 1 280px}
.clms-sd-hero__title{margin:0;font-size:clamp(32px,5vw,54px);line-height:1.02;font-weight:800;letter-spacing:-.04em;color:#fff}
.clms-sd-hero__text,.clms-sd-text{margin:0;font-size:15px;line-height:1.7;color:var(--sd-ink-2);overflow-wrap:anywhere}
.clms-sd-hero__text{max-width:62ch;color:rgba(255,255,255,.82);font-size:16px}
.clms-sd-hero__meta{margin:0;font-size:13px;color:rgba(255,255,255,.72)}
.clms-sd-text--light{color:rgba(255,255,255,.82)}
.clms-sd-text--muted{color:var(--sd-muted)}
.clms-sd-text--sm{font-size:14px}
.clms-sd-hero__actions,.clms-sd-course__actions{display:flex;flex-wrap:wrap;gap:12px}
.clms-sd-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 18px;border-radius:14px;font-size:14px;font-weight:700;line-height:1;border:1px solid transparent;transition:all .18s ease;white-space:nowrap;max-width:100%}
.clms-sd-btn:hover{transform:translateY(-1px)}
.clms-sd-btn--primary{background:var(--sd-accent);border-color:var(--sd-accent);color:#fff;box-shadow:0 10px 24px rgba(99,102,241,.28)}
.clms-sd-btn--primary:hover{background:var(--sd-accent-2);border-color:var(--sd-accent-2);color:#fff}
.clms-sd-btn--secondary{background:var(--sd-card);border-color:var(--sd-border);color:var(--sd-ink)}
.clms-sd-btn--ghost{background:transparent;border-color:rgba(255,255,255,.22);color:#fff}
.clms-sd-btn--white{background:#fff;border-color:#fff;color:#111827}
.clms-sd-stat-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.clms-sd-stat{display:grid;gap:8px;padding:18px;border-radius:20px;background:var(--sd-card);border:1px solid var(--sd-border)}
.clms-sd-stat--hero{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.14)}
.clms-sd-stat__label{font-size:13px;line-height:1.4;color:var(--sd-muted)}
.clms-sd-stat--hero .clms-sd-stat__label{color:rgba(255,255,255,.78)}
.clms-sd-stat__value{font-size:42px;line-height:1;font-weight:800;letter-spacing:-.04em;color:var(--sd-ink)}
.clms-sd-stat--hero .clms-sd-stat__value{color:#fff}
.clms-sd-stat__value--sm{font-size:28px}
.clms-sd-progress{width:100%;height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden}
.clms-sd-stat--hero .clms-sd-progress{background:rgba(255,255,255,.16)}
.clms-sd-progress span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#6366f1 0%,#818cf8 100%)}
.clms-sd-stat--hero .clms-sd-progress span{background:linear-gradient(90deg,#ffffff 0%,#c7d2fe 100%)}
.clms-sd-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.clms-sd-card--next{border-color:rgba(99,102,241,.25);background:linear-gradient(135deg,var(--sd-card) 0%,var(--clms-accent-lt,#eef2ff) 100%)}
.clms-sd-card--onboarding{border-color:rgba(99,102,241,.2);background:var(--sd-card)}
.clms-sd-onboard-list{display:grid;gap:12px;margin-top:14px}
.clms-sd-onboard-item{display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:center;padding:12px 14px;border-radius:16px;border:1px solid var(--sd-border);background:var(--sd-card)}
.clms-sd-onboard-item.is-done{border-color:var(--sd-success-bg);background:var(--sd-success-bg)}
.clms-sd-onboard-dot{width:24px;height:24px;border-radius:999px;background:var(--sd-border);color:var(--sd-ink-2);display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:800}
.clms-sd-onboard-item.is-done .clms-sd-onboard-dot{background:var(--sd-success);color:#fff}
.clms-sd-onboard-content{display:grid;gap:4px;min-width:0}
.clms-sd-onboard-title{margin:0;font-size:15px;font-weight:700;color:var(--sd-ink)}
.clms-sd-step{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
.clms-sd-step__title{margin:0 0 4px;font-size:20px;font-weight:800;color:var(--sd-ink)}
.clms-sd-step__meta{margin:0 0 8px;font-size:13px;color:var(--sd-muted)}
.clms-sd-btn--wide{width:100%}
.clms-sd-modules{display:grid;gap:14px}
.clms-sd-module{display:grid;grid-template-columns:minmax(0,1fr) minmax(140px,.35fr);gap:16px;align-items:center;border:1px solid var(--sd-border);border-radius:18px;padding:16px;background:var(--sd-card)}
.clms-sd-module__title{margin:0 0 6px;font-size:17px;font-weight:800;color:var(--sd-ink)}
.clms-sd-module__meta{margin:0;font-size:13px;color:var(--sd-muted)}
.clms-sd-module__meta-row{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.clms-sd-module__progress{display:grid;gap:8px}
.clms-sd-module__value{font-size:20px;font-weight:800;color:var(--sd-ink);text-align:right}
.clms-sd-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.clms-sd-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:12px;font-weight:700}
.clms-sd-memory-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:12px}
.clms-sd-memory-block{display:grid;gap:8px}
.clms-sd-profile{display:grid;gap:6px;margin-top:14px;padding:12px 14px;border-radius:16px;background:var(--clms-bg-soft,#f8fafc);border:1px solid var(--sd-border)}
.clms-sd-inline-meta{margin:0;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--sd-muted)}
.clms-sd-eval{display:grid;gap:8px;padding:16px;border-radius:18px;border:1px solid var(--sd-border);background:var(--clms-bg-soft,#f8fafc);margin-bottom:14px}
.clms-sd-eval .clms-submission-timeline{padding:4px 0 0;gap:12px}
.clms-sd-eval .clms-submission-step{font-size:12px}
.clms-sd-details{display:grid;gap:10px;border:1px dashed var(--sd-border);border-radius:16px;padding:12px;background:var(--sd-card)}
.clms-sd-details summary{cursor:pointer;font-weight:800;font-size:13px;color:var(--sd-ink);list-style:none}
.clms-sd-details summary::-webkit-details-marker{display:none}
.clms-sd-details[open]{border-style:solid}
.clms-sd-details .clms-sd-list{margin-top:12px}
.clms-sd-journey{display:grid;gap:12px}
.clms-sd-route-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(260px,.8fr);gap:16px}
.clms-sd-route-main,.clms-sd-route-aside{display:grid;gap:12px;align-content:start}
.clms-sd-route-course{margin:0;font-size:14px;font-weight:700;color:var(--sd-ink)}
.clms-sd-route-progress{display:grid;gap:8px;padding:12px;border-radius:14px;background:var(--clms-bg-soft,#f8fafc);border:1px solid var(--sd-border)}
.clms-sd-route-progress strong{font-size:26px;line-height:1;color:var(--sd-ink)}
.clms-sd-route-note{display:grid;gap:6px;padding:10px;border-radius:12px;border:1px solid var(--sd-border);background:var(--sd-card)}
.clms-sd-journey-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px;border:1px solid var(--sd-border);border-radius:16px;background:var(--sd-card)}
.clms-sd-journey-dot{width:10px;height:10px;border-radius:999px;background:var(--sd-accent)}
.clms-sd-journey-title{margin:0;font-size:15px;font-weight:700;color:var(--sd-ink)}
.clms-sd-journey-meta{margin:0;font-size:12px;color:var(--sd-muted)}
.clms-sd-journey-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.clms-sd-assistant-widget{margin-top:8px}
.clms-sd-incident-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.clms-sd-incident-card{padding:14px;border:1px solid var(--sd-border);border-radius:16px;background:var(--sd-card);display:grid;gap:6px}
.clms-sd-incident-card.is-warning{border-color:rgba(180,83,9,.2);background:var(--sd-warn-bg)}
.clms-sd-incident-card.is-success{border-color:rgba(22,163,74,.2);background:var(--sd-success-bg)}
.clms-sd-incident-label{font-size:11px;color:var(--sd-muted);text-transform:uppercase;letter-spacing:.08em;font-weight:700}
.clms-sd-incident-value{font-size:24px;font-weight:800;color:var(--sd-ink)}
.clms-sd-incident-note{margin:0;font-size:12px;color:var(--sd-muted)}
.clms-sd-courses{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.clms-sd-course{display:grid;grid-template-rows:1fr auto;border:1px solid var(--sd-border);border-radius:20px;background:var(--sd-card);overflow:hidden}
.clms-sd-course__body{display:grid;gap:12px;padding:18px 18px 16px}
.clms-sd-course__title{margin:0;font-size:18px;line-height:1.35;font-weight:800;letter-spacing:-.02em;color:var(--sd-ink)}
.clms-sd-course__meta,.clms-sd-list-card__meta,.clms-sd-mini__meta,.clms-sd-mini__date,.clms-sd-inline-meta{margin:0;font-size:13px;line-height:1.5;color:var(--sd-muted);overflow-wrap:anywhere}
.clms-sd-course__progress-head{display:flex;align-items:center;justify-content:space-between;gap:12px;font-size:13px;color:var(--sd-muted)}
.clms-sd-course__progress-head strong{color:var(--sd-ink);font-weight:800}
.clms-sd-course__actions{padding:16px 18px 18px;border-top:1px solid var(--sd-border)}
.clms-sd-course__actions .clms-sd-btn{flex:1 1 160px}
.clms-sd-list,.clms-sd-mini-list{display:grid;gap:14px}
.clms-sd-list-card,.clms-sd-mini{border:1px solid var(--sd-border);border-radius:18px;background:var(--sd-card);padding:16px}
.clms-sd-list-card{display:grid;gap:12px}
.clms-sd-list-card__head,.clms-sd-mini{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:start;gap:14px}
.clms-sd-list-card__main,.clms-sd-mini__content{min-width:0;display:grid;gap:6px}
.clms-sd-list-card__aside{display:flex;align-items:center;flex-wrap:wrap;gap:8px}
.clms-sd-list-card__aside--stack{flex-direction:column;align-items:flex-end}
.clms-sd-list-card__title,.clms-sd-mini__title{margin:0;font-size:17px;line-height:1.35;font-weight:800;letter-spacing:-.02em;color:var(--sd-ink)}
.clms-sd-list-card__foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.clms-sd-badge,.clms-sd-grade{display:inline-flex;align-items:center;justify-content:center;max-width:100%;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:800;line-height:1.2;text-align:center}
.clms-sd-badge{background:#e5e7eb;color:#111827}
.clms-sd-badge.is-warning{background:var(--sd-warn-bg);color:var(--sd-warn)}
.clms-sd-badge.is-success{background:var(--sd-success-bg);color:var(--sd-success)}
.clms-sd-badge.is-info{background:var(--sd-info-bg);color:var(--sd-info)}
.clms-sd-badge.is-muted{background:#f1f5f9;color:#475569}
.clms-sd-grade{background:#0f172a;color:#fff}
.clms-sd-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:var(--sd-accent-2)}
.clms-sd-sticky-cta{position:sticky;bottom:12px;z-index:20;display:flex;justify-content:flex-end;padding:0 4px}
.clms-sd-sticky-cta .clms-sd-btn{box-shadow:0 10px 25px rgba(15,23,42,.18)}
@media (max-width:1120px){
	.clms-sd-hero,.clms-sd-layout{grid-template-columns:1fr}
	.clms-sd-courses{grid-template-columns:1fr}
	.clms-sd-route-grid{grid-template-columns:1fr}
}
	@media (max-width:782px){
		.clms-sd{gap:18px}
		.clms-sd-hero,.clms-sd-card{padding:20px;border-radius:22px}
		.clms-sd-list-card__head,.clms-sd-mini{grid-template-columns:1fr}
		.clms-sd-list-card__aside,.clms-sd-list-card__aside--stack{align-items:flex-start}
	.clms-sd-hero__actions,.clms-sd-course__actions{flex-direction:column;align-items:stretch}
	.clms-sd-btn{width:100%}
	.clms-sd-step{flex-direction:column;align-items:flex-start}
	.clms-sd-module{grid-template-columns:1fr}
	.clms-sd-module__value{text-align:left}
	.clms-sd-memory-grid{grid-template-columns:1fr}
	.clms-sd-details{padding:10px}
	.clms-sd-journey-item{grid-template-columns:1fr}
	.clms-sd-journey-actions{justify-content:flex-start}
	.clms-sd-sticky-cta{position:static;padding:0}
	}
@media (max-width:640px){
	.clms-sd-hero{padding:18px;border-radius:20px}
	.clms-sd-card{padding:18px;border-radius:18px}
	.clms-sd-hero__title{font-size:clamp(28px,11vw,42px)}
	.clms-sd-title{font-size:24px}
	.clms-sd-stat-grid{grid-template-columns:1fr}
	.clms-sd-stat__value{font-size:34px}
	.clms-sd-stat__value--sm{font-size:26px}
	.clms-sd-section-head{display:grid;gap:10px}
	.clms-sd-hero__text,.clms-sd-text{font-size:14px;line-height:1.65}
	.clms-sd-link{min-height:40px;align-items:center}
	.clms-sd-list-card,.clms-sd-mini{padding:14px}
	.clms-sd-course__body,.clms-sd-course__actions{padding-left:14px;padding-right:14px}
}';
	}
}
