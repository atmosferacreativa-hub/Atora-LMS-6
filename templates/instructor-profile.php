<?php
/**
 * Perfil público de docente — single-atora_teacher.php
 * Para rutas legacy /docentes/{slug} se resuelve el usuario.
 *
 * ATORA-LMS
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Modo 1: CPT atora_teacher ─────────────────────────────────────────────────
if ( is_singular( 'atora_teacher' ) ) {
	$teacher_post = get_post();

	if ( ! $teacher_post || 'atora_teacher' !== $teacher_post->post_type ) {
		global $wp_query;
		if ( $wp_query ) { $wp_query->set_404(); }
		status_header( 404 );
		nocache_headers();
		get_header();
		echo '<main style="max-width:960px;margin:0 auto;padding:40px 20px"><h1>'
			. esc_html__( 'Perfil docente no encontrado', 'atora-lms' ) . '</h1></main>';
		get_footer();
		return;
	}

	$teacher_id = absint( $teacher_post->ID );

	// Motor UI ───────────────────────────────────────────────────────────────
	$use_ui = class_exists( 'CLMS_UI_Template_Resolver' )
		&& class_exists( 'CLMS_UI_Template_Context' )
		&& class_exists( 'CLMS_UI_Template_Engine' );

	if ( $use_ui ) {
		$schema          = apply_filters(
			'clms_teacher_ui_schema',
			( new CLMS_UI_Template_Resolver() )->resolve( $teacher_id, 'teacher' ),
			$teacher_id
		);
		$ctx             = CLMS_UI_Template_Context::make( $teacher_id, 'teacher' );
		$sections_output = ( new CLMS_UI_Template_Engine() )->render_to_array( $ctx, $schema );
	} else {
		// Fallback minimal sin motor UI
		$sections_output = array();
		$name      = $teacher_post->post_title;
		$specialty = (string) get_post_meta( $teacher_id, '_clms_teacher_specialty', true );
		$short_bio = (string) get_post_field( 'post_excerpt', $teacher_id );
		$long_bio  = (string) get_post_field( 'post_content', $teacher_id );
		$photo_id  = get_post_thumbnail_id( $teacher_id );
		ob_start();
		?>
		<div class="atora-teacher-profile">
			<div class="atora-teacher-profile__head">
				<div class="atora-teacher-profile__avatar">
					<?php if ( $photo_id ) : ?>
						<?php echo wp_get_attachment_image( $photo_id, 'large', false, array( 'alt' => $name ) ); ?>
					<?php else : ?>
						<span><?php echo esc_html( mb_substr( $name, 0, 1 ) ); ?></span>
					<?php endif; ?>
				</div>
				<div>
					<?php if ( $specialty ) : ?><p class="atora-teacher-profile__title"><?php echo esc_html( $specialty ); ?></p><?php endif; ?>
					<?php if ( $short_bio ) : ?><p class="atora-teacher-profile__tagline"><?php echo esc_html( $short_bio ); ?></p><?php endif; ?>
				</div>
			</div>
			<?php if ( $long_bio ) : ?>
				<div class="atora-teacher-profile__bio"><?php echo wp_kses_post( wpautop( $long_bio ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
		$sections_output['_fallback'] = ob_get_clean();
	}

	get_header();
	?>

	<style>
	/* ── Teacher Profile ── */
	.tp-wrap{max-width:1080px;margin:0 auto;padding:0 20px 80px}

	/* Hero card */
	.tp-hero{padding:48px 0 0}
	.tp-hero__inner{
		background:var(--atora-bg,#fff);
		border:1px solid var(--atora-border,#e2e8f0);
		border-radius:var(--ac-radius-xl,24px);
		padding:40px;
		display:grid;
		grid-template-columns:180px minmax(0,1fr);
		gap:36px;
		align-items:center;
		box-shadow:var(--ac-shadow-sm,0 1px 3px rgba(0,0,0,.07));
	}
	.tp-hero__avatar{
		width:180px;height:180px;border-radius:999px;overflow:hidden;
		background:var(--atora-accent-soft,#eef2ff);
		display:flex;align-items:center;justify-content:center;
		border:4px solid var(--atora-border,#e2e8f0);
		flex-shrink:0;
	}
	.tp-hero__photo{width:100%;height:100%;object-fit:cover;display:block}
	.tp-hero__initials{font-size:56px;font-weight:800;color:var(--atora-accent,#6366f1)}
	.tp-hero__kicker{
		margin:0 0 10px;font-size:11px;font-weight:700;letter-spacing:.1em;
		text-transform:uppercase;color:var(--atora-accent,#6366f1);
		background:var(--atora-accent-soft,#eef2ff);
		display:inline-block;padding:4px 12px;border-radius:999px;
	}
	.tp-hero__name{margin:0 0 10px;font-size:clamp(26px,3.5vw,40px);line-height:1.1;font-weight:800;color:var(--atora-text,#0f172a)}
	.tp-hero__tagline{margin:0 0 18px;font-size:17px;color:var(--atora-text-muted,#475569);line-height:1.6}
	.tp-hero__socials{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px}
	.tp-hero__socials a{
		font-size:13px;font-weight:600;color:var(--atora-text-muted,#475569);
		text-decoration:none;padding:5px 12px;border:1px solid var(--atora-border,#e2e8f0);
		border-radius:999px;transition:all .18s;
	}
	.tp-hero__socials a:hover{border-color:var(--atora-accent,#6366f1);color:var(--atora-accent,#6366f1);background:var(--atora-accent-soft,#eef2ff)}
	.tp-hero__meta{margin:0;font-size:13px;color:var(--atora-text-subtle,#64748b);font-weight:600}

	/* Secciones */
	.tp-section-title{
		margin:0 0 22px;font-size:20px;font-weight:700;color:var(--atora-text,#0f172a);
		display:flex;align-items:center;gap:10px;
	}
	.tp-section-title::after{content:'';flex:1;height:1px;background:var(--atora-border,#e2e8f0)}
	.tp-bio,.tp-achievements,.tp-courses,.tp-stats{margin-top:44px}
	.tp-bio__inner,.tp-achievements__inner,.tp-courses__inner,.tp-stats__inner{max-width:880px}
	.tp-bio__content{font-size:16px;line-height:1.85;color:var(--atora-text-muted,#475569)}
	.tp-bio__content p{margin:0 0 16px}
	.tp-bio__content p:last-child{margin-bottom:0}

	/* Logros */
	.tp-achievements__list{margin:0;padding:0;list-style:none;display:grid;gap:10px}
	.tp-achievements__item{
		display:flex;gap:12px;align-items:flex-start;
		font-size:15px;color:var(--atora-text,#0f172a);
		padding:12px 16px;
		background:var(--atora-surface,#f8fafc);
		border:1px solid var(--atora-border,#e2e8f0);
		border-radius:var(--ac-radius,12px);
	}
	.tp-achievements__icon{
		color:var(--atora-success,#22c55e);font-weight:700;flex-shrink:0;margin-top:2px;
		background:var(--atora-success-bg,#dcfce7);width:22px;height:22px;
		border-radius:999px;display:flex;align-items:center;justify-content:center;
		font-size:12px;
	}

	/* Cursos */
	.tp-courses__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px}
	.tp-course-card{
		border:1px solid var(--atora-border,#e2e8f0);
		border-radius:var(--ac-radius-lg,16px);
		overflow:hidden;
		background:var(--atora-bg,#fff);
		transition:box-shadow .18s ease,transform .18s ease;
	}
	.tp-course-card:hover{box-shadow:var(--ac-shadow-md,0 10px 20px rgba(0,0,0,.07));transform:translateY(-2px)}
	.tp-course-card__link{display:block;text-decoration:none;color:inherit}
	.tp-course-card__thumb{aspect-ratio:16/9;background:var(--atora-surface-2,#f1f5f9);overflow:hidden}
	.tp-course-card__thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .4s}
	.tp-course-card:hover .tp-course-card__thumb img{transform:scale(1.04)}
	.tp-course-card__thumb-fallback{display:block;width:100%;height:100%;background:linear-gradient(135deg,var(--atora-accent-soft,#eef2ff) 0%,var(--atora-accent,#6366f1) 100%);opacity:.3}
	.tp-course-card__body{padding:16px}
	.tp-course-card__title{margin:0 0 6px;font-size:15px;font-weight:700;color:var(--atora-text,#0f172a);line-height:1.35;transition:color .18s}
	.tp-course-card__link:hover .tp-course-card__title{color:var(--atora-accent,#6366f1)}
	.tp-course-card__excerpt{margin:0;font-size:13px;color:var(--atora-text-muted,#475569);line-height:1.55;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

	/* Stats */
	.tp-stats__grid{display:flex;flex-wrap:wrap;gap:20px;margin:0;padding:0}
	.tp-stat{
		display:flex;flex-direction:column;gap:4px;
		padding:20px 28px;
		background:var(--atora-surface,#f8fafc);
		border:1px solid var(--atora-border,#e2e8f0);
		border-radius:var(--ac-radius-lg,16px);
		min-width:120px;
	}
	.tp-stat__value{font-size:34px;font-weight:800;color:var(--atora-accent,#6366f1);margin:0;line-height:1}
	.tp-stat__label{font-size:13px;color:var(--atora-text-muted,#475569);margin:0;font-weight:600}

	/* Fallback (sin motor UI) */
	.atora-teacher-profile{background:var(--atora-bg,#fff);border:1px solid var(--atora-border,#e2e8f0);border-radius:20px;padding:32px;display:grid;gap:20px;margin-top:32px;box-shadow:var(--ac-shadow-sm)}
	.atora-teacher-profile__head{display:grid;grid-template-columns:100px minmax(0,1fr);gap:20px;align-items:start}
	.atora-teacher-profile__avatar{width:100px;height:100px;border-radius:999px;overflow:hidden;background:var(--atora-accent-soft,#eef2ff);display:flex;align-items:center;justify-content:center}
	.atora-teacher-profile__avatar img{width:100%;height:100%;object-fit:cover;display:block}
	.atora-teacher-profile__avatar span{font-weight:800;color:var(--atora-accent,#6366f1);font-size:28px}
	.atora-teacher-profile__title{margin:0 0 4px;font-size:11px;color:var(--atora-accent,#6366f1);font-weight:700;text-transform:uppercase;letter-spacing:.08em}
	.atora-teacher-profile__tagline{margin:0;font-size:17px;color:var(--atora-text,#0f172a);font-weight:700}
	.atora-teacher-profile__bio{font-size:15px;line-height:1.75;color:var(--atora-text-muted,#475569)}

	/* Achievements + video (2 columnas) */
	.tp-achievements__inner--split{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:start;max-width:100%}
	.tp-achievements__col{min-width:0}
	.tp-achievements__video{min-width:0}
	.tp-video-embed{position:relative;padding-bottom:56.25%;height:0;border-radius:var(--ac-radius-lg,16px);overflow:hidden;background:var(--atora-surface-2,#f1f5f9)}
	.tp-video-embed iframe,.tp-video-embed video{position:absolute;top:0;left:0;width:100%;height:100%;border:0}

	/* Sección extra */
	.tp-extra{margin-top:44px;padding-top:44px;border-top:1px solid var(--atora-border,#e2e8f0)}
	.tp-extra__inner{max-width:880px}
	.tp-extra__title{font-size:28px;font-weight:800;color:var(--atora-text,#0f172a);margin:0 0 20px;line-height:1.2}
	.tp-extra__content{font-size:16px;line-height:1.85;color:var(--atora-text-muted,#475569)}
	.tp-extra__content p{margin:0 0 16px}
	.tp-extra__content p:last-child{margin-bottom:0}

	/* Responsive */
	@media (max-width:700px){
		.tp-achievements__inner--split{grid-template-columns:1fr}
		.tp-hero__inner{grid-template-columns:1fr;text-align:center;padding:28px 20px}
		.tp-hero__avatar{width:110px;height:110px;margin:0 auto}
		.tp-hero__socials{justify-content:center}
		.tp-hero__name{font-size:26px}
		.atora-teacher-profile__head{grid-template-columns:1fr;text-align:center}
		.atora-teacher-profile__avatar{margin:0 auto}
	}
	</style>

	<main class="tp-wrap clms-ui">
		<?php if ( $use_ui ) : ?>
			<?php foreach ( $sections_output as $html ) : ?>
				<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endforeach; ?>
		<?php else : ?>
			<?php echo $sections_output['_fallback'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
	</main>

	<?php
	get_footer();
	return;
}

// ── Modo 2: Ruta legacy /docentes/{slug} (basada en usuario WP) ───────────────
$instructor = null;
if ( function_exists( 'clms_core' ) && clms_core() && method_exists( clms_core(), 'get_module' ) ) {
	$instructor = clms_core()->get_module( 'CLMS_Instructor' );
}
if ( ! $instructor && class_exists( 'CLMS_Instructor' ) ) {
	$instructor = new CLMS_Instructor();
}

$user = ( $instructor && method_exists( $instructor, 'get_profile_user_from_request' ) )
	? $instructor->get_profile_user_from_request()
	: false;

if ( ! $user ) {
	global $wp_query;
	if ( $wp_query ) { $wp_query->set_404(); }
	status_header( 404 );
	nocache_headers();
	get_header();
	echo '<main style="max-width:960px;margin:0 auto;padding:40px 20px"><h1>'
		. esc_html__( 'Perfil docente no encontrado', 'atora-lms' ) . '</h1></main>';
	get_footer();
	return;
}

$user_id      = absint( $user->ID );
$display_name = $user->display_name ? $user->display_name : $user->user_login;
$hero_title   = (string) get_user_meta( $user_id, '_clms_instructor_title', true );
$hero_tagline = (string) get_user_meta( $user_id, '_clms_instructor_tagline', true );
$profile_html = $instructor
	? $instructor->get_instructor_profile_html( $user_id, array(
		'show_courses'  => true,
		'courses_limit' => 8,
		'show_meta'     => true,
		'show_socials'  => true,
		'show_bio'      => true,
	) )
	: '';

get_header();
?>
<?php echo method_exists( $instructor, 'get_inline_style_tag' ) ? $instructor->get_inline_style_tag() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>

<style>
.atora-ip-wrap{max-width:1080px;margin:0 auto;padding:0 20px 60px}
.atora-ip-hero{padding:52px 0 30px}
.atora-ip-kicker{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--atora-accent,#4f46e5);margin:0 0 10px}
.atora-ip-title{font-size:clamp(30px,5vw,48px);line-height:1.08;color:var(--atora-text,#111827);margin:0 0 14px}
.atora-ip-copy{font-size:18px;line-height:1.6;color:var(--atora-text-muted,#4b5563);margin:0}
</style>

<main class="atora-ip-wrap">
	<section class="atora-ip-hero">
		<p class="atora-ip-kicker"><?php esc_html_e( 'Docente ATORA', 'atora-lms' ); ?></p>
		<h1 class="atora-ip-title"><?php echo esc_html( $display_name ); ?></h1>
		<?php if ( $hero_title || $hero_tagline ) : ?>
			<p class="atora-ip-copy"><?php echo esc_html( trim( $hero_title . ( $hero_title && $hero_tagline ? ' · ' : '' ) . $hero_tagline ) ); ?></p>
		<?php endif; ?>
	</section>
	<?php echo $profile_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</main>

<?php get_footer(); ?>
