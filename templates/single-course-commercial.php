<?php
/**
 * Single Course Template — Commercial / Marketplace
 * ATORA-LMS
 *
 * Página de ventas para usuarios que aún no están inscritos.
 * Se activa cuando el curso tiene _clms_commercial_mode = 'commercial'
 * y el visitante no tiene acceso.
 *
 * Este archivo es un thin wrapper: prepara contexto, resuelve schema,
 * delega el render al motor de templates y ensambla el layout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$disable_cache = apply_filters( 'clms_course_commercial_disable_cache', true );
if ( $disable_cache ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( function_exists( 'nocache_headers' ) ) {
		nocache_headers();
	}
}

get_header();

if ( ! have_posts() ) {
	get_footer();
	return;
}

the_post();

$course_id        = get_the_ID();
$course_permalink = get_permalink( $course_id );

?>
<style>
/* ── Comercial layout ───────────────────────────────────────────────────────── */
.cc-wrap{max-width:1140px;margin:0 auto;padding:0 20px 60px;font-family:inherit;box-sizing:border-box;
	--cc-bg:var(--atora-bg,#ffffff);
	--cc-card:var(--atora-surface,#f9fafb);
	--cc-card-alt:var(--atora-surface-2,#f8fafc);
	--cc-text:var(--atora-text,#111827);
	--cc-muted:var(--atora-text-muted,#374151);
	--cc-subtle:var(--atora-text-subtle,#6b7280);
	--cc-border:var(--atora-border,#e5e7eb);
	--cc-accent:var(--atora-accent,#6366f1);
	--cc-accent-hover:var(--atora-accent-hover,#4f46e5);
	--cc-accent-soft:var(--atora-accent-soft,#eef2ff);
	--cc-success:var(--atora-success,#16a34a);
	--cc-success-bg:var(--atora-success-bg,#f0fdf4);
	--cc-success-text:var(--atora-success-text,#166534);
	--cc-warning:var(--atora-warning,#d97706);
	--cc-warning-bg:var(--atora-warning-bg,#fffbeb);
	--cc-warning-text:var(--atora-warning-text,#78350f);
	--cc-danger:var(--atora-danger,#ef4444);
	--cc-danger-bg:var(--atora-danger-bg,#fee2e2);
	--cc-danger-text:var(--atora-danger-text,#991b1b);
	--cc-on-accent:var(--atora-on-accent,#ffffff);
}
.cc-wrap *{box-sizing:border-box}

/* Hero */
.cc-hero{display:grid;gap:18px;padding:28px 0 14px}
.cc-hero-media-top{margin:0}
.cc-hero-media-top img{width:100%;display:block;border-radius:16px;box-shadow:0 14px 34px rgba(15,23,42,.18)}
.cc-hero-heading{padding:0 2px}
.cc-hero-kicker{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--cc-accent);margin:0 0 10px}
.cc-hero-title{font-size:clamp(28px,5vw,46px);font-weight:800;line-height:1.15;margin:0 0 16px;color:var(--cc-text)}
.cc-hero-tagline{font-size:18px;color:var(--cc-muted);line-height:1.6;margin:0 0 20px}
.cc-hero-meta{display:flex;flex-wrap:wrap;gap:8px 16px;font-size:14px;color:var(--cc-subtle);margin:0 0 12px}
.cc-hero-meta span{display:flex;align-items:center;gap:4px}
.cc-hero-media-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:20px;align-items:stretch}
.cc-hero-media-grid--single{grid-template-columns:1fr}
.cc-hero-media-card{background:linear-gradient(160deg,var(--cc-card) 0%,var(--cc-card-alt) 100%);border:1px solid var(--cc-border);border-radius:16px;padding:14px;box-shadow:0 10px 24px rgba(15,23,42,.09)}
.cc-hero-cta-box{background:linear-gradient(140deg,var(--cc-card) 0%,#ffffff 100%);border:1px solid var(--cc-border);border-radius:16px;padding:24px;display:flex;flex-direction:column;justify-content:center}
.cc-hero-price{font-size:32px;font-weight:800;color:var(--cc-text);margin:0 0 4px}
.cc-hero-price-label{font-size:13px;color:var(--cc-subtle);margin:0 0 18px}
.cc-btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:14px 28px;background:var(--cc-accent);color:var(--cc-on-accent);font-size:16px;font-weight:700;border-radius:10px;text-decoration:none;width:100%;text-align:center}
.cc-btn-primary:hover{background:var(--cc-accent-hover);color:var(--cc-on-accent)}
.cc-hero-video-ratio{position:relative;width:100%;padding-bottom:56.25%}
.cc-hero-video-ratio iframe,.cc-hero-video-ratio video{position:absolute;inset:0;width:100%;height:100%;border:0;border-radius:14px}

/* Secciones */
.cc-section{padding:40px 0;border-top:1px solid var(--cc-border)}
.cc-section-title{font-size:22px;font-weight:700;color:var(--cc-text);margin:0 0 20px}
.cc-section--summary{padding-top:28px}
.cc-include-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.cc-include-card{position:relative;padding:14px 14px 14px 36px;background:var(--cc-card-alt);border:1px solid var(--cc-border);border-radius:12px;font-size:14px;color:var(--cc-muted);line-height:1.5}
.cc-include-card::before{content:"✓";position:absolute;left:14px;top:12px;font-weight:700;color:var(--cc-accent-hover)}

/* Beneficios */
.cc-benefits-list,.cc-req-list{padding:0;margin:0;list-style:none;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px}
.cc-benefits-list li,.cc-req-list li{padding:10px 14px 10px 34px;background:var(--cc-success-bg);border-radius:8px;color:var(--cc-success-text);font-size:14px;line-height:1.5;position:relative}
.cc-benefits-list li::before{content:"✓";position:absolute;left:12px;top:10px;font-weight:700;color:var(--cc-success)}
.cc-req-list li{background:var(--cc-warning-bg);color:var(--cc-warning-text)}
.cc-req-list li::before{content:"·";color:var(--cc-warning);font-size:20px;top:6px}

/* Split row: instructor + benefits */
.cc-section--split{padding:40px 0;border-top:1px solid var(--cc-border)}
.cc-split-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(0,1fr);gap:28px;align-items:start}
.cc-split-grid > .cc-section{
	padding:20px;
	border-top:none;
	background:linear-gradient(180deg,var(--cc-card) 0%,#ffffff 100%);
	border:1px solid var(--cc-border);
	border-radius:16px;
	box-shadow:0 8px 20px rgba(15,23,42,.07);
}
.cc-split-grid > .cc-section .cc-section-title{margin-bottom:14px;font-size:20px}
.cc-split-grid .atora-teacher-grid{display:grid;gap:12px}
.cc-split-grid .atora-teacher-card,
.cc-split-grid .clms-instructor-box{
	margin:0;
	padding:16px;
	border-radius:14px;
	background:#ffffff;
	border:1px solid var(--cc-border);
	box-shadow:0 4px 14px rgba(15,23,42,.05);
}
.cc-split-grid .atora-teacher-head,
.cc-split-grid .clms-instructor-head{gap:12px}
.cc-split-grid .atora-teacher-avatar img,
.cc-split-grid .clms-instructor-avatar img{width:68px;height:68px}
.cc-split-grid .atora-teacher-name,
.cc-split-grid .clms-instructor-name{font-size:20px}
.cc-split-grid .cc-section--benefits .cc-benefits-list{grid-template-columns:1fr}
.cc-split-grid .cc-section--benefits .cc-benefits-list li{
	background:linear-gradient(135deg,#f6fff9 0%,#ebfff4 100%);
	border:1px solid #6fd2a1;
	color:#0f3f29;
	font-size:16px;
	font-weight:700;
	line-height:1.65;
}
.cc-split-grid .cc-section--benefits .cc-benefits-list li::before{
	color:#0f8a51;
	font-size:16px;
}

/* Temario preview */
.cc-lessons-list{padding:0;margin:0;list-style:none;display:grid;gap:6px}
.cc-lessons-list li{display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--cc-card);border-radius:8px;font-size:14px;color:var(--cc-muted)}
.cc-lessons-list .cc-lesson-num{width:26px;height:26px;border-radius:50%;background:var(--cc-accent-soft);color:var(--cc-accent);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.cc-lessons-locked{font-size:13px;color:var(--cc-subtle);margin-top:10px}

/* Perfiles */
.cc-profiles{display:grid;grid-template-columns:1fr 1fr;gap:24px}
.cc-profile-box{background:var(--cc-card);border:1px solid var(--cc-border);border-radius:12px;padding:20px}
.cc-profile-title{font-size:14px;font-weight:700;color:var(--cc-accent);text-transform:uppercase;letter-spacing:.06em;margin:0 0 10px}
.cc-profile-text{font-size:15px;color:var(--cc-muted);line-height:1.7;margin:0;white-space:pre-line}

/* Galería */
.cc-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px}
.cc-gallery img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:10px;display:block}

/* Testimonios */
.cc-testimonials{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:20px}
.cc-testimonial{background:var(--cc-card);border:1px solid var(--cc-border);border-radius:12px;padding:20px}
.cc-testimonial-rating{display:flex;gap:3px;margin:0 0 8px}
.cc-star{font-size:15px;color:var(--cc-border)}
.cc-star.is-filled{color:#f59e0b}
.cc-testimonial-quote{font-size:15px;color:var(--cc-muted);line-height:1.7;margin:0 0 16px;font-style:italic}
.cc-testimonial-author{font-size:13px;color:var(--cc-subtle)}
.cc-testimonial-author strong{color:var(--cc-text);display:block}

/* FAQ */
.cc-faq{display:grid;gap:12px}
.cc-faq-item{background:var(--cc-card);border:1px solid var(--cc-border);border-radius:10px;padding:16px 20px}
.cc-faq-q{font-weight:700;color:var(--cc-text);font-size:15px;margin:0 0 8px}
.cc-faq-a{font-size:14px;color:var(--cc-muted);line-height:1.6;margin:0}
.cc-program-chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}
.cc-program-chip{display:inline-flex;align-items:center;padding:8px 12px;background:var(--cc-accent-soft);border:1px solid var(--cc-border);border-radius:999px;color:var(--cc-accent-hover);font-size:13px;text-decoration:none}
.cc-related-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}
.cc-related-card{background:var(--cc-card);border:1px solid var(--cc-border);border-radius:14px;padding:18px}
.cc-related-type{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--cc-accent);font-weight:700;margin:0 0 10px}
.cc-related-title{font-size:18px;font-weight:700;color:var(--cc-text);margin:0 0 8px}
.cc-related-copy{font-size:14px;color:var(--cc-muted);line-height:1.6;margin:0 0 14px}
.cc-related-meta{font-size:13px;color:var(--cc-text);font-weight:600;margin:0 0 12px}
.cc-related-link{display:inline-flex;align-items:center;color:var(--cc-accent-hover);text-decoration:none;font-weight:700}
.cc-related-link:hover{text-decoration:underline}

/* CTA bottom */
.cc-cta-bottom{text-align:center;padding:48px 20px;background:linear-gradient(135deg,var(--cc-accent) 0%,var(--cc-accent-hover) 100%);border-radius:16px;margin-top:48px}
.cc-cta-bottom .cc-cta-title{color:var(--cc-on-accent);font-size:26px;font-weight:800;margin:0 0 10px}
.cc-cta-bottom p{color:rgba(255,255,255,.85);font-size:16px;margin:0 0 24px}
.cc-cta-bottom .cc-btn-white{display:inline-block;padding:14px 32px;background:var(--cc-on-accent);color:var(--cc-accent-hover);font-size:16px;font-weight:700;border-radius:10px;text-decoration:none}
.cc-cta-bottom .cc-btn-white:hover{background:var(--cc-accent-soft)}
.cc-mobile-cta{display:none}

@media(max-width:768px){
	.cc-hero-media-grid{grid-template-columns:1fr;gap:14px}
	.cc-profiles{grid-template-columns:1fr}
	.cc-split-grid{grid-template-columns:1fr;gap:18px}
	.cc-section--split{padding:28px 0}
	.cc-split-grid > .cc-section{padding:16px}
	.cc-hero-media-card{padding:10px}
	.cc-wrap{padding:0 16px 110px}
	.cc-hero{gap:14px;padding:22px 0 10px}
	.cc-section{padding:28px 0}
	.cc-hero-tagline{font-size:16px}
	.cc-hero-meta{gap:10px 14px}
	.cc-hero-cta-box{padding:18px}
	.cc-section-title{font-size:20px;margin-bottom:16px}
	.cc-cta-bottom{padding:32px 16px;border-radius:14px}
	.cc-cta-bottom .cc-cta-title{font-size:22px}
	.cc-mobile-cta{display:block;position:fixed;left:0;right:0;bottom:0;z-index:40;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border-top:1px solid #e5e7eb}
	.cc-mobile-cta__inner{max-width:1140px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center}
	.cc-mobile-cta__copy{min-width:0}
	.cc-mobile-cta__price{display:block;font-size:15px;font-weight:800;color:#111827}
	.cc-mobile-cta__label{display:block;font-size:12px;color:#6b7280;line-height:1.4}
	.cc-mobile-cta .cc-btn-primary{width:auto;min-width:168px;padding:0 18px}
}
@media(max-width:520px){
	.cc-mobile-cta__inner{grid-template-columns:1fr}
	.cc-mobile-cta .cc-btn-primary{width:100%}
}
</style>

<div class="cc-wrap">
	<?php
	// ── Resolver schema y contexto ────────────────────────────────────────
		// Alumnos inscritos en preview ven el layout académico (todas las lecciones).
		// Admins siempre ven el layout comercial para previsualizar la landing.
		$_ctx_uid_c   = get_current_user_id();
		$_ctx_admin_c = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
		$_ctx_enr_c   = false;
		if ( $_ctx_uid_c && ! $_ctx_admin_c && class_exists( 'CLMS_Helper' ) ) {
			$_ctx_enr_c = (bool) CLMS_Helper::user_is_enrolled_in_course( $_ctx_uid_c, $course_id );
		}
		$_cc_schema_context = ( $_ctx_enr_c && ! $_ctx_admin_c ) ? 'course_overview' : 'course_commercial';

		$resolver = new CLMS_UI_Template_Resolver();
		$schema   = apply_filters(
			'clms_course_commercial_ui_schema',
			$resolver->resolve( $course_id, $_cc_schema_context ),
			$course_id
		);
		$repository = $resolver->repository();
		if ( ! $repository->validate( $schema ) || empty( $schema['sections'] ) ) {
			$schema = $resolver->resolve( $course_id, $_cc_schema_context );
		}

		$ctx             = CLMS_UI_Template_Context::make( $course_id, $_cc_schema_context );
		$engine          = new CLMS_UI_Template_Engine();
		$sections_output = $engine->render_to_array( $ctx, $schema );
		$sections_order  = array_keys( $sections_output );

		// ── Split grid: instructor + benefits ─────────────────────────────────
		$split_pair_enabled = isset( $sections_output['instructor'] ) && isset( $sections_output['benefits'] );
		$split_pair_first   = '';
		$split_pair_second  = '';

		if ( $split_pair_enabled ) {
			$instructor_index = array_search( 'instructor', $sections_order, true );
			$benefits_index   = array_search( 'benefits', $sections_order, true );
			if ( false !== $instructor_index && false !== $benefits_index ) {
				if ( $instructor_index <= $benefits_index ) {
					$split_pair_first  = 'instructor';
					$split_pair_second = 'benefits';
				} else {
					$split_pair_first  = 'benefits';
					$split_pair_second = 'instructor';
				}
			} else {
				$split_pair_enabled = false;
			}
		}
		?>
		<?php foreach ( $sections_order as $section_id ) : ?>
			<?php if ( $split_pair_enabled && $section_id === $split_pair_first ) : ?>
				<div class="cc-section--split">
					<div class="cc-split-grid">
						<?php echo $sections_output[ $split_pair_first ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $sections_output[ $split_pair_second ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</div>
				<?php continue; ?>
			<?php endif; ?>
			<?php if ( $split_pair_enabled && $section_id === $split_pair_second ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<?php if ( ! empty( $sections_output[ $section_id ] ) ) : ?>
				<?php echo $sections_output[ $section_id ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
		<?php endforeach; ?>
</div><!-- .cc-wrap -->

<?php
// ── Mobile CTA sticky bar ──────────────────────────────────────────────────────
$_d        = class_exists( 'CLMS_UI_Course_Commercial_Sections' ) ? CLMS_UI_Course_Commercial_Sections::data( $course_id ) : array();
$_cta_url  = $_d['cta_url'] ?? '';
$_price    = $_d['price'] ?? '';
$_price_lbl = $_d['price_label'] ?? '';
$_tagline  = $_d['tagline'] ?? '';
$_subtitle = $_d['subtitle'] ?? '';
$_cta_lbl  = $_d['cta_label'] ?? '';

if ( $_cta_url || ! is_user_logged_in() ) :
?>
	<div class="cc-mobile-cta" aria-hidden="false">
		<div class="cc-mobile-cta__inner">
			<div class="cc-mobile-cta__copy">
				<?php if ( $_price ) : ?>
					<span class="cc-mobile-cta__price"><?php echo esc_html( $_price ); ?></span>
				<?php endif; ?>
				<span class="cc-mobile-cta__label"><?php echo esc_html( $_price_lbl ? $_price_lbl : ( $_tagline ? $_tagline : $_subtitle ) ); ?></span>
			</div>
			<?php if ( $_cta_url ) : ?>
				<a class="cc-btn-primary" href="<?php echo esc_url( $_cta_url ); ?>">
					<?php echo esc_html( $_cta_lbl ); ?>
				</a>
			<?php elseif ( ! is_user_logged_in() ) : ?>
				<a class="cc-btn-primary" href="<?php echo esc_url( wp_login_url( $course_permalink ) ); ?>">
					<?php esc_html_e( 'Iniciar sesión', 'atora-lms' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>

<?php get_footer(); ?>
