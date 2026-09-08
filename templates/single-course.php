<?php
/**
 * Single Course Template — Course Overview & Navigation
 * ATORA-LMS
 *
 * Thin wrapper: resuelve schema, delega render al motor de templates y ensambla el layout.
 * Muestra la vista académica del curso para alumnos inscritos (y funnel para no inscritos).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$disable_cache = apply_filters( 'clms_course_overview_disable_cache', true );
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

	$course_id  = get_the_ID();
	$cov_scheme = apply_filters( 'clms_course_overview_color_scheme', 'light', $course_id );
	$cov_scheme = in_array( $cov_scheme, array( 'light', 'dark', 'auto' ), true ) ? sanitize_key( $cov_scheme ) : 'light';
	$ui_ready   = class_exists( 'CLMS_UI_Template_Resolver', false )
		&& class_exists( 'CLMS_UI_Template_Context', false )
		&& class_exists( 'CLMS_UI_Template_Engine', false );

	?>

	<div class="cov-wrap" data-cov-scheme="<?php echo esc_attr( $cov_scheme ); ?>">
		<div class="cov-stack">
			<?php
			if ( ! $ui_ready ) {
				?>
				<main class="cov-fallback" style="max-width:920px;margin:0 auto;padding:28px 20px">
					<h1><?php the_title(); ?></h1>
					<?php the_content(); ?>
				</main>
				<?php
				get_footer();
				return;
			}

			try {
				// ── Resolver schema y contexto ──────────────────────────────────────
				$resolver = new CLMS_UI_Template_Resolver();
				$schema   = apply_filters(
					'clms_course_overview_ui_schema',
					$resolver->resolve( $course_id, 'course_overview' ),
					$course_id
				);

				$repository = method_exists( $resolver, 'repository' ) ? $resolver->repository() : null;
				if ( $repository && method_exists( $repository, 'validate' ) && ( ! $repository->validate( $schema ) || empty( $schema['sections'] ) ) ) {
					$schema = $resolver->resolve( $course_id, 'course_overview' );
				}

				$ctx             = CLMS_UI_Template_Context::make( $course_id, 'course_overview' );
				$engine          = new CLMS_UI_Template_Engine();
				$sections_output = $engine->render_to_array( $ctx, $schema );
				$sections_order  = array_keys( $sections_output );

				// ── Datos de layout para el CTA footer (no-inscritos) ───────────────
				$_d           = class_exists( 'CLMS_UI_Course_Overview_Sections', false )
					? CLMS_UI_Course_Overview_Sections::data( $course_id )
					: array();
				$_user_id     = $_d['user_id'] ?? get_current_user_id();
				$_is_enrolled = $_d['is_enrolled'] ?? false;
				$_tagline     = $_d['tagline'] ?? '';
				$_excerpt     = $_d['course_excerpt'] ?? '';
			} catch ( Throwable $e ) {
				?>
				<main class="cov-fallback" style="max-width:920px;margin:0 auto;padding:28px 20px">
					<h1><?php the_title(); ?></h1>
					<?php the_content(); ?>
				</main>
				<?php
				get_footer();
				return;
			}
				?>
				<?php foreach ( $sections_order as $section_id ) : ?>
					<?php if ( ! empty( $sections_output[ $section_id ] ) ) : ?>
						<?php echo $sections_output[ $section_id ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
			<?php endforeach; ?>

			<?php if ( ! $_user_id || ! $_is_enrolled ) : ?>
			<div class="cov-cta-footer">
				<p class="cov-cta-footer-kicker"><?php esc_html_e( '¿Listo para comenzar?', 'atora-lms' ); ?></p>
				<h2 class="cov-cta-footer-title"><?php the_title(); ?></h2>
				<?php if ( $_tagline ) : ?>
					<p class="cov-cta-footer-sub"><?php echo esc_html( $_tagline ); ?></p>
				<?php elseif ( $_excerpt ) : ?>
					<p class="cov-cta-footer-sub"><?php echo esc_html( wp_trim_words( $_excerpt, 18 ) ); ?></p>
				<?php endif; ?>
				<?php if ( ! $_user_id ) : ?>
					<a class="cov-btn cov-btn-primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">
						<?php esc_html_e( 'Iniciar sesión para acceder', 'atora-lms' ); ?>
					</a>
				<?php else : ?>
					<p style="color:rgba(255,255,255,.75);font-size:15px;margin:0;"><?php esc_html_e( 'Inscríbete en este curso para comenzar.', 'atora-lms' ); ?></p>
				<?php endif; ?>
			</div>
			<?php endif; ?>
	</div>
</div><!-- .cov-wrap -->

<?php get_footer(); ?>
