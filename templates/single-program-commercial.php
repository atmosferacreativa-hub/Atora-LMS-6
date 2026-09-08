<?php
/**
 * Single Program Template — Commercial Landing
 * ATORA-LMS
 *
 * Wrapper delgado: resuelve schema comercial de programa y delega
 * el render de secciones al motor UI común.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$disable_cache = apply_filters( 'clms_program_commercial_disable_cache', true );
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

	$program_id = get_the_ID();

	$content_html      = function_exists( 'atora_lms_get_entry_content_html' ) ? atora_lms_get_entry_content_html( $program_id, true ) : '';
	$has_block_content = function_exists( 'atora_lms_entry_has_block_content' ) ? atora_lms_entry_has_block_content( $program_id ) : false;
	$ui_ready          = class_exists( 'CLMS_UI_Template_Resolver', false )
		&& class_exists( 'CLMS_UI_Template_Context', false )
		&& class_exists( 'CLMS_UI_Template_Engine', false );
	?>

<style>
.apl-wrap{max-width:1140px;margin:0 auto;padding:0 20px 60px;font-family:inherit;box-sizing:border-box;
	--apl-bg:var(--atora-bg,#ffffff);
	--apl-card:var(--atora-surface,#f9fafb);
	--apl-card-alt:var(--atora-surface-2,#f8fafc);
	--apl-text:var(--atora-text,#111827);
	--apl-muted:var(--atora-text-muted,#374151);
	--apl-subtle:var(--atora-text-subtle,#6b7280);
	--apl-border:var(--atora-border,#e5e7eb);
	--apl-accent:var(--atora-accent,#6366f1);
	--apl-accent-hover:var(--atora-accent-hover,#4f46e5);
	--apl-accent-soft:var(--atora-accent-soft,#eef2ff);
	--apl-on-accent:var(--atora-on-accent,#ffffff);
	color:var(--apl-text)}
.apl-wrap *{box-sizing:border-box}
.apl-hero{display:grid;grid-template-columns:1.1fr .9fr;gap:36px;align-items:start;padding:48px 0 36px}
.apl-kicker{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--apl-accent);margin:0 0 10px}
.apl-title{font-size:clamp(30px,5vw,48px);line-height:1.08;color:var(--apl-text);margin:0 0 16px}
.apl-copy{font-size:18px;line-height:1.6;color:var(--apl-muted);margin:0 0 20px}
.apl-meta{display:flex;flex-wrap:wrap;gap:8px 16px;color:var(--apl-muted);font-size:14px;margin:0 0 22px}
.apl-cta{background:var(--apl-card);border:1px solid var(--apl-border);border-radius:16px;padding:24px}
.apl-price{font-size:34px;font-weight:800;color:var(--apl-text);margin:0 0 4px}
.apl-price-copy{font-size:13px;color:var(--apl-subtle);margin:0 0 16px}
.apl-btn{display:inline-flex;align-items:center;justify-content:center;width:100%;padding:14px 24px;background:var(--apl-accent);color:var(--apl-on-accent);text-decoration:none;border-radius:12px;font-weight:700}
.apl-btn:hover{background:var(--apl-accent-hover);color:var(--apl-on-accent)}
.apl-media{border-radius:18px;overflow:hidden;background:var(--apl-border)}
.apl-media iframe,.apl-media video,.apl-media img{display:block;width:100%;aspect-ratio:16/10;border:0;object-fit:cover}
.apl-section{padding:32px 0;border-top:1px solid var(--apl-border)}
.apl-section-title{font-size:24px;font-weight:800;color:var(--apl-text);margin:0 0 18px}
.apl-summary-copy{font-size:16px;line-height:1.6;color:var(--apl-muted);margin:0 0 16px}
.apl-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.apl-summary-card{position:relative;padding:14px 14px 14px 36px;background:var(--apl-card-alt);border:1px solid var(--apl-border);border-radius:12px;font-size:14px;color:var(--apl-muted);line-height:1.5}
.apl-summary-card::before{content:"✓";position:absolute;left:14px;top:12px;font-weight:700;color:var(--apl-accent)}
.apl-offer-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.apl-offer-chip{display:inline-flex;gap:6px;align-items:center;border:1px solid var(--apl-border);border-radius:999px;padding:6px 12px;background:var(--apl-card-alt);font-size:12px;color:var(--apl-muted)}
.apl-offer-chip strong{color:var(--apl-text)}
.apl-modules{display:grid;gap:14px}
.apl-module{background:var(--apl-card);border:1px solid var(--apl-border);border-radius:14px;overflow:hidden}
.apl-module-cover{position:relative;aspect-ratio:16/9;background:linear-gradient(120deg,#e2e8f0,#cbd5e1);overflow:hidden}
.apl-module-cover::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,0) 35%,rgba(15,23,42,.36) 100%);pointer-events:none}
.apl-module-cover-image{display:block;width:100%;height:100%;object-fit:cover;transition:transform .45s ease}
.apl-module-cover-fallback{height:100%;display:flex;align-items:center;justify-content:center}
.apl-module-cover-fallback span{display:inline-flex;width:58px;height:58px;border-radius:999px;align-items:center;justify-content:center;background:rgba(255,255,255,.84);font-size:22px;font-weight:800;color:#334155}
.apl-module-lessons-badge{position:absolute;left:12px;bottom:12px;display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#0f172a;background:rgba(255,255,255,.9);backdrop-filter:blur(4px);box-shadow:0 4px 14px rgba(15,23,42,.16);z-index:1}
.apl-module-body{padding:16px;display:flex;flex-direction:column;gap:10px}
.apl-module-title{font-size:18px;font-weight:700;color:var(--apl-text);margin:0;line-height:1.25}
.apl-module-copy{font-size:14px;color:var(--apl-muted);line-height:1.6;margin:0 0 10px}
.apl-module-meta{display:flex;flex-wrap:wrap;gap:10px 14px;font-size:14px;color:var(--apl-muted);margin:0}
.apl-module-progress{height:8px;background:var(--apl-card-alt);border-radius:999px;overflow:hidden;margin:0 0 10px}
.apl-module-progress span{display:block;height:100%;background:linear-gradient(90deg,var(--apl-accent),var(--apl-accent-hover))}
.apl-tag{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:var(--apl-accent-soft);color:var(--apl-accent);font-size:12px;font-weight:700}
.apl-tag-warning{background:#fef3c7;color:#92400e}
.apl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px}
.apl-card{background:var(--apl-card);border:1px solid var(--apl-border);border-radius:14px;padding:18px}
.apl-card-type{font-size:12px;color:var(--apl-accent);font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin:0 0 8px}
.apl-card-title{font-size:18px;font-weight:700;color:var(--apl-text);margin:0 0 8px}
.apl-card-copy{font-size:14px;line-height:1.6;color:var(--apl-muted);margin:0 0 10px}
.apl-card-meta{font-size:13px;font-weight:700;color:var(--apl-text);margin:0 0 10px}
.apl-card-link{display:inline-flex;color:var(--apl-accent);text-decoration:none;font-weight:700}
.apl-card-link:hover{text-decoration:underline}
.apl-cta-bottom{margin-top:40px;background:linear-gradient(135deg,var(--apl-accent) 0%,var(--apl-accent-hover) 100%);border-radius:16px;padding:36px 24px;text-align:center}
.apl-cta-bottom-title{color:var(--apl-on-accent);font-size:24px;font-weight:800;margin:0 0 10px}
.apl-cta-bottom p{color:var(--apl-on-accent);opacity:.86;margin:0 0 20px}
.apl-btn-inline{width:auto;min-width:220px}
.apl-progress-card,.apl-academic-card{background:var(--apl-card);border:1px solid var(--apl-border);border-radius:14px;padding:18px}
.apl-offer-hint{margin:12px 0 0;font-size:12px;color:var(--apl-subtle)}
.apl-offer-hint a{color:var(--apl-accent);text-decoration:none}
.apl-offer-hint a:hover{text-decoration:underline}
.apl-hero-links{display:flex;gap:12px;margin-top:10px}
.apl-hero-links a{font-size:13px;color:var(--apl-accent);text-decoration:none;font-weight:600}
.apl-hero-links a:hover{text-decoration:underline}
.apl-quicknav{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 18px}
.apl-quicknav a{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;border:1px solid var(--apl-border);background:var(--apl-card-alt);color:var(--apl-muted);font-size:12px;font-weight:700;letter-spacing:.02em;text-decoration:none}
.apl-quicknav a:hover{color:var(--apl-accent);border-color:var(--apl-accent-soft);background:var(--apl-accent-soft)}
.apl-progress-head{margin:0 0 10px;font-weight:700;color:var(--apl-text)}
.apl-progress-bar{height:12px;background:var(--apl-card-alt);border-radius:999px;overflow:hidden}
.apl-progress-bar span{display:block;height:100%;background:linear-gradient(90deg,var(--apl-accent),var(--apl-accent-hover))}
.apl-progress-meta{margin:10px 0 0;color:var(--apl-muted);font-size:14px}
.apl-academic-card p{margin:0 0 12px;color:var(--apl-muted);line-height:1.6}
.apl-academic-card details{margin-top:10px}
.apl-academic-card summary{cursor:pointer;font-weight:700;color:var(--apl-text)}
.apl-academic-card ul{margin:8px 0 0 18px;padding:0}
.apl-wrap{width:min(1240px,100%);padding:14px 24px 110px}
.apl-hero{grid-template-columns:1.12fr .88fr;gap:42px}
.apl-media{box-shadow:0 14px 32px rgba(15,23,42,.14);position:sticky;top:96px}
.apl-section{padding:38px 0}
.apl-section-title{font-size:28px;line-height:1.2}
.apl-summary-grid{grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.apl-modules{grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.apl-module{height:100%;display:flex;flex-direction:column;background:linear-gradient(180deg,var(--apl-card) 0%,#ffffff 100%);box-shadow:0 8px 20px rgba(15,23,42,.06);transition:transform .24s ease,box-shadow .24s ease,border-color .24s ease}
.apl-module:hover{transform:translateY(-3px);box-shadow:0 18px 34px rgba(15,23,42,.13);border-color:rgba(99,102,241,.24)}
.apl-module:hover .apl-module-cover-image{transform:scale(1.04)}
.apl-module-actions{margin-top:12px}
.apl-btn-inline{min-width:176px}
.apl-progress-card,.apl-academic-card{padding:22px;background:linear-gradient(180deg,var(--apl-card) 0%,#ffffff 100%)}
.apl-academic-card details{padding:10px 0;border-top:1px dashed var(--apl-border)}
.apl-academic-card details:first-of-type{border-top:none;padding-top:4px}
.apl-section .atora-teacher-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px}
.apl-section .atora-teacher-card{height:100%;display:flex;flex-direction:column;padding:0;border:1px solid var(--apl-border);border-radius:16px;background:linear-gradient(180deg,var(--apl-card) 0%,#ffffff 100%);box-shadow:0 10px 24px rgba(15,23,42,.08);overflow:hidden}
.apl-section .atora-teacher-card__body{padding:16px 18px 8px;display:flex;flex-direction:column;gap:10px;flex:1}
.apl-section .atora-teacher-name{font-size:20px;line-height:1.25;margin:0}
.apl-section .atora-teacher-specialty{font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--apl-accent);margin:0}
.apl-section .atora-teacher-bio{margin:0;font-size:14px;line-height:1.6;color:var(--apl-muted)}
.apl-section .atora-teacher-card__footer{padding:0 18px 18px;margin-top:auto}
.apl-section .atora-teacher-link{display:inline-flex;align-items:center;gap:8px;font-weight:700;text-decoration:none;color:var(--apl-accent)}
.apl-section .atora-teacher-link:hover{text-decoration:underline}
.apl-mobile-cta{display:none}
@media (max-width:1024px){
	.apl-wrap{padding:10px 18px 110px}
	.apl-hero{grid-template-columns:1fr;gap:24px}
	.apl-media{position:relative;top:auto}
	.apl-modules{grid-template-columns:1fr}
}
@media (max-width:768px){
	.apl-wrap{padding:6px 14px 118px}
	.apl-section{padding:28px 0}
	.apl-section-title{font-size:24px}
	.apl-btn-inline{width:100%}
	.apl-mobile-cta{display:block;position:fixed;left:0;right:0;bottom:0;z-index:40;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:rgba(255,255,255,.96);backdrop-filter:blur(12px);border-top:1px solid var(--apl-border)}
	.apl-mobile-cta__inner{max-width:1240px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center}
	.apl-mobile-cta__price{display:block;font-size:15px;font-weight:800;color:var(--apl-text)}
	.apl-mobile-cta__label{display:block;font-size:12px;color:var(--apl-subtle);line-height:1.4}
	.apl-mobile-cta .apl-btn{width:auto;min-width:164px;padding:12px 16px;border-radius:10px}
}
@media (max-width:520px){
	.apl-mobile-cta__inner{grid-template-columns:1fr}
	.apl-mobile-cta .apl-btn{width:100%}
}
</style>

<div class="apl-wrap">
	<?php if ( $has_block_content ) : ?>
		<?php if ( ! preg_match( '/<h1\b/i', $content_html ) ) : ?>
			<h1 class="apl-title"><?php echo esc_html( get_the_title( $program_id ) ); ?></h1>
		<?php endif; ?>
		<?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php else : ?>
		<?php
		$_ctx_uid   = get_current_user_id();
		$_ctx_admin = current_user_can( 'manage_options' ) || current_user_can( 'clms_manage_courses' );
		$_ctx_enr   = false;
		if ( $_ctx_uid && ! $_ctx_admin && class_exists( 'CLMS_Helper' ) ) {
			$_ctx_enr = (bool) CLMS_Helper::user_is_enrolled_in_program( $_ctx_uid, $program_id );
		}
		// Solo los alumnos inscritos (no admins) ven el layout de overview con progreso.
		// Admins ven el layout comercial para poder previsualizar el CTA y el contenido.
		$schema_context = ( $_ctx_enr && ! $_ctx_admin ) ? 'program_overview' : 'program_commercial';

		$fallback_html   = '';
		$sections_output = array();
		$sections_order  = array();

		$build_fallback = static function () use ( $content_html, $program_id ): string {
			ob_start();
			if ( ! preg_match( '/<h1\\b/i', $content_html ) ) {
				echo '<h1 class="apl-title">' . esc_html( get_the_title( $program_id ) ) . '</h1>';
			}
			echo '' !== trim( $content_html ) ? $content_html : wp_kses_post( apply_filters( 'the_content', get_post_field( 'post_content', $program_id ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return (string) ob_get_clean();
		};

		if ( ! $ui_ready ) {
			$fallback_html = $build_fallback();
		} else {
			try {
				$resolver = new CLMS_UI_Template_Resolver();
				$schema   = apply_filters(
					'clms_program_commercial_ui_schema',
					$resolver->resolve( $program_id, $schema_context ),
					$program_id
				);

				$repository = method_exists( $resolver, 'repository' ) ? $resolver->repository() : null;
				if ( $repository && method_exists( $repository, 'validate' ) && ( ! $repository->validate( $schema ) || empty( $schema['sections'] ) ) ) {
					$schema = $resolver->resolve( $program_id, $schema_context );
				}

				$ctx             = CLMS_UI_Template_Context::make( $program_id, $schema_context );
				$engine          = new CLMS_UI_Template_Engine();
				$sections_output = $engine->render_to_array( $ctx, $schema );
				$sections_order  = array_keys( $sections_output );
			} catch ( Throwable $e ) {
				$fallback_html = $build_fallback();
			}
		}
		?>
		<?php if ( '' !== $fallback_html ) : ?>
			<?php echo $fallback_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php else : ?>
			<?php foreach ( $sections_order as $section_id ) : ?>
				<?php if ( ! empty( $sections_output[ $section_id ] ) ) : ?>
					<?php echo $sections_output[ $section_id ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php
$_d          = class_exists( 'CLMS_UI_Program_Commercial_Sections' ) ? CLMS_UI_Program_Commercial_Sections::data( $program_id ) : array();
$_cta_url    = (string) ( $_d['cta_url'] ?? '' );
$_cta_label  = (string) ( $_d['cta_label'] ?? '' );
$_price      = (string) ( $_d['price'] ?? '' );
$_price_copy = (string) ( $_d['price_label'] ?? '' );
$_tagline    = (string) ( $_d['tagline'] ?? '' );
$_subtitle   = (string) ( $_d['subtitle'] ?? '' );
$_enrolled   = ! empty( $_d['is_enrolled'] );

if ( ( ( '' !== trim( $_cta_url ) && ! $_enrolled ) || ! is_user_logged_in() ) && ! $has_block_content ) :
	$_mobile_url   = '' !== trim( $_cta_url ) ? $_cta_url : wp_login_url( get_permalink( $program_id ) );
	$_mobile_label = '' !== trim( $_cta_url ) ? ( '' !== trim( $_cta_label ) ? $_cta_label : __( 'Inscribirme ahora', 'atora-lms' ) ) : __( 'Iniciar sesión', 'atora-lms' );
	$_mobile_copy  = '' !== trim( $_price_copy ) ? $_price_copy : ( '' !== trim( $_tagline ) ? $_tagline : $_subtitle );
	?>
	<div class="apl-mobile-cta" aria-hidden="false">
		<div class="apl-mobile-cta__inner">
			<div class="apl-mobile-cta__copy">
				<?php if ( '' !== trim( $_price ) ) : ?>
					<span class="apl-mobile-cta__price"><?php echo esc_html( $_price ); ?></span>
				<?php endif; ?>
				<span class="apl-mobile-cta__label"><?php echo esc_html( $_mobile_copy ); ?></span>
			</div>
			<a class="apl-btn" href="<?php echo esc_url( $_mobile_url ); ?>"><?php echo esc_html( $_mobile_label ); ?></a>
		</div>
	</div>
<?php endif; ?>

<?php get_footer(); ?>
