<?php
/**
 * Single Product Template — Capa comercial LMS (WooCommerce)
 * ATORA-LMS
 *
 * Se activa para productos vinculados a curso/programa/bundle por medio de
 * CLMS_Loader::maybe_load_single_product_template().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

if ( ! have_posts() ) {
	get_footer();
	return;
}

the_post();

$product_id = get_the_ID();
$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

if ( ! $product ) {
	echo '<div class="clms-product-wrap"><p>' . esc_html__( 'No se pudo cargar el producto.', 'atora-lms' ) . '</p></div>';
	get_footer();
	return;
}

$is_builder_preview = function_exists( 'atora_lms_is_builder_preview_request' )
	? atora_lms_is_builder_preview_request( 'product', $product_id )
	: false;

$builder_edit_mode = (bool) apply_filters( 'clms_product_builder_edit_mode_enabled', $is_builder_preview, $product_id );

if ( $builder_edit_mode ) {
	$builder_content = function_exists( 'atora_lms_get_entry_content_html' )
		? atora_lms_get_entry_content_html( $product_id, true )
		: '';
	?>
	<style>
	.clms-product-builder-wrap{max-width:1100px;margin:0 auto;padding:24px 20px 60px}
	.clms-product-builder-note{margin:0 0 18px;padding:12px 14px;border:1px solid #dbeafe;background:#eff6ff;border-radius:10px;color:#1e3a8a;font-size:13px;line-height:1.45}
	.clms-product-builder-content{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;box-shadow:0 8px 22px rgba(15,23,42,.08)}
	</style>
	<div class="clms-product-builder-wrap">
		<p class="clms-product-builder-note"><?php esc_html_e( 'Modo edición de maquetador activo. Esta vista prioriza el contenido editable del producto.', 'atora-lms' ); ?></p>
		<div class="clms-product-builder-content">
			<?php
			if ( '' !== trim( wp_strip_all_tags( $builder_content ) ) ) {
				echo $builder_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo '<h1>' . esc_html( get_the_title( $product_id ) ) . '</h1>';
				echo '<p>' . esc_html__( 'Añade contenido en el editor para construir esta plantilla comercial.', 'atora-lms' ) . '</p>';
			}
			?>
		</div>
	</div>
	<?php
	get_footer();
	return;
}

$map = class_exists( 'CLMS_WooCommerce' ) ? CLMS_WooCommerce::get_product_access_map( $product_id ) : array();

$mode            = sanitize_key( (string) ( $map['mode'] ?? 'course' ) );
$linked_course   = absint( $map['course_id'] ?? 0 );
$linked_program  = absint( $map['program_id'] ?? 0 );
$bundle_courses  = isset( $map['bundle_courses'] ) && is_array( $map['bundle_courses'] ) ? array_values( array_filter( array_map( 'absint', $map['bundle_courses'] ) ) ) : array();
$bundle_programs = isset( $map['bundle_programs'] ) && is_array( $map['bundle_programs'] ) ? array_values( array_filter( array_map( 'absint', $map['bundle_programs'] ) ) ) : array();
$access_days     = absint( $map['access_days'] ?? 0 );

$mode_labels = array(
	'course'     => __( 'Curso individual', 'atora-lms' ),
	'program'    => __( 'Programa completo', 'atora-lms' ),
	'bundle'     => __( 'Bundle / Paquete', 'atora-lms' ),
	'membership' => __( 'Membresía', 'atora-lms' ),
);

$hero_title = (string) get_the_title( $product_id );
$hero_copy  = trim( (string) $product->get_short_description() );

if ( '' === $hero_copy && $linked_program ) {
	$hero_copy = trim( (string) get_post_meta( $linked_program, '_clms_commercial_tagline', true ) );
	if ( '' === $hero_copy ) {
		$hero_copy = trim( (string) get_post_meta( $linked_program, '_clms_program_subtitle', true ) );
	}
}
if ( '' === $hero_copy && $linked_course ) {
	$hero_copy = trim( (string) get_post_meta( $linked_course, '_clms_commercial_tagline', true ) );
	if ( '' === $hero_copy ) {
		$hero_copy = trim( (string) get_post_meta( $linked_course, '_clms_course_subtitle', true ) );
	}
}

$entity_url   = '';
$entity_label = '';
if ( $linked_program ) {
	$entity_url   = (string) get_permalink( $linked_program );
	$entity_label = __( 'Ver programa académico', 'atora-lms' );
} elseif ( $linked_course ) {
	$entity_url   = (string) get_permalink( $linked_course );
	$entity_label = __( 'Ver curso académico', 'atora-lms' );
}

$duration = '';
if ( $linked_program ) {
	$duration = trim( (string) get_post_meta( $linked_program, '_clms_program_duration', true ) );
} elseif ( $linked_course ) {
	$duration = trim( (string) get_post_meta( $linked_course, '_clms_course_duration', true ) );
}

$primary_image_id  = absint( $product->get_image_id() );
$primary_image     = $primary_image_id ? wp_get_attachment_image( $primary_image_id, 'large', false, array( 'loading' => 'eager' ) ) : '';
$gallery_image_ids = method_exists( $product, 'get_gallery_image_ids' ) ? (array) $product->get_gallery_image_ids() : array();

ob_start();
the_content();
$content_html = (string) ob_get_clean();

if ( '' === trim( wp_strip_all_tags( $content_html ) ) && function_exists( 'atora_lms_get_entry_content_html' ) ) {
	$content_html = atora_lms_get_entry_content_html( $product_id, true );
}

$has_description = '' !== trim( wp_strip_all_tags( $content_html ) );

$purchase_box       = do_shortcode( '[atora_product_purchase_box id="' . absint( $product_id ) . '"]' );
$mobile_price_label = trim( wp_strip_all_tags( (string) $product->get_price_html() ) );

$bundle_course_labels = array();
foreach ( $bundle_courses as $course_id ) {
	$title = trim( (string) get_the_title( $course_id ) );
	if ( '' !== $title ) {
		$bundle_course_labels[] = $title;
	}
}

$bundle_program_labels = array();
foreach ( $bundle_programs as $program_id ) {
	$title = trim( (string) get_the_title( $program_id ) );
	if ( '' !== $title ) {
		$bundle_program_labels[] = $title;
	}
}

$mode_label = $mode_labels[ $mode ] ?? __( 'Acceso académico', 'atora-lms' );

?>
<style>
.clms-product-wrap{max-width:1220px;margin:0 auto;padding:20px 20px 90px;box-sizing:border-box;
	--clms-p-bg:var(--atora-bg,#ffffff);
	--clms-p-card:var(--atora-surface,#ffffff);
	--clms-p-card-soft:var(--atora-surface-2,#f8fafc);
	--clms-p-text:var(--atora-text,#111827);
	--clms-p-muted:var(--atora-text-muted,#374151);
	--clms-p-subtle:var(--atora-text-subtle,#6b7280);
	--clms-p-border:var(--atora-border,#e5e7eb);
	--clms-p-accent:var(--atora-accent,#6366f1);
	--clms-p-accent-hover:var(--atora-accent-hover,#4f46e5);
	--clms-p-accent-soft:var(--atora-accent-soft,#eef2ff);
	--clms-p-on-accent:var(--atora-on-accent,#ffffff);
	color:var(--clms-p-text)}
.clms-product-wrap *{box-sizing:border-box}
.clms-product-hero{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(320px,.92fr);gap:30px;align-items:start;padding:28px 0}
.clms-product-eyebrow{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--clms-p-accent);margin:0 0 10px}
.clms-product-title{font-size:clamp(30px,4.8vw,48px);line-height:1.08;letter-spacing:-.02em;margin:0 0 14px;color:var(--clms-p-text)}
.clms-product-copy{font-size:18px;line-height:1.62;margin:0 0 18px;color:var(--clms-p-muted)}
.clms-product-meta{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 20px}
.clms-product-chip{display:inline-flex;align-items:center;padding:7px 12px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid var(--clms-p-border);background:var(--clms-p-card-soft);color:var(--clms-p-muted)}
.clms-product-entity-link{display:inline-flex;align-items:center;gap:8px;text-decoration:none;font-weight:700;color:var(--clms-p-accent)}
.clms-product-entity-link:hover{text-decoration:underline}
.clms-product-media{background:var(--clms-p-card);border:1px solid var(--clms-p-border);border-radius:18px;padding:12px;box-shadow:0 16px 36px rgba(15,23,42,.11)}
.clms-product-media-main{border-radius:14px;overflow:hidden;background:#f3f4f6}
.clms-product-media-main img{display:block;width:100%;height:auto;aspect-ratio:16/10;object-fit:cover}
.clms-product-media-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-top:10px}
.clms-product-media-grid img{display:block;width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:10px}
.clms-product-buy{position:sticky;top:94px}
.clms-product-buy-card{background:linear-gradient(180deg,var(--clms-p-card) 0%,#ffffff 100%);border:1px solid var(--clms-p-border);border-radius:18px;padding:18px;box-shadow:0 14px 32px rgba(15,23,42,.09)}
.clms-product-buy-title{margin:0 0 12px;font-size:18px;font-weight:800}
.clms-product-buy .atora-purchase-box{padding:0;border:none;box-shadow:none;background:transparent}
.clms-product-buy .atora-purchase-box__price{font-size:34px}
.clms-product-buy-note{margin:10px 0 0;font-size:12px;color:var(--clms-p-subtle)}
.clms-product-section{padding:34px 0;border-top:1px solid var(--clms-p-border)}
.clms-product-section h2{margin:0 0 16px;font-size:27px;line-height:1.2}
.clms-product-richtext{max-width:920px;color:var(--clms-p-muted);font-size:17px;line-height:1.76}
.clms-product-richtext h1,.clms-product-richtext h2,.clms-product-richtext h3,.clms-product-richtext h4{color:var(--clms-p-text);line-height:1.25;margin:1.2em 0 .55em}
.clms-product-richtext p{margin:0 0 1em}
.clms-product-richtext ul,.clms-product-richtext ol{margin:0 0 1em 1.25em;padding:0}
.clms-product-richtext li{margin:0 0 .56em}
.clms-product-richtext strong{color:var(--clms-p-text)}
.clms-product-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}
.clms-product-info-card{padding:14px;border:1px solid var(--clms-p-border);border-radius:12px;background:var(--clms-p-card-soft)}
.clms-product-info-label{display:block;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--clms-p-subtle);margin:0 0 6px}
.clms-product-info-value{display:block;font-size:16px;line-height:1.45;color:var(--clms-p-text)}
.clms-product-list{margin:0;padding-left:18px;color:var(--clms-p-muted)}
.clms-product-mobile-cta{display:none}
@media (max-width:1024px){
	.clms-product-hero{grid-template-columns:1fr;gap:18px}
	.clms-product-buy{position:relative;top:auto}
}
@media (max-width:768px){
	.clms-product-wrap{padding:10px 14px 118px}
	.clms-product-hero{padding:16px 0 12px}
	.clms-product-title{font-size:clamp(28px,10vw,38px)}
	.clms-product-copy{font-size:16px}
	.clms-product-section{padding:24px 0}
	.clms-product-section h2{font-size:24px}
	.clms-product-richtext{font-size:16px;line-height:1.7}
	.clms-product-mobile-cta{display:block;position:fixed;left:0;right:0;bottom:0;z-index:45;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:rgba(255,255,255,.97);backdrop-filter:blur(12px);border-top:1px solid var(--clms-p-border)}
	.clms-product-mobile-cta__inner{max-width:1220px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center}
	.clms-product-mobile-cta__price{display:block;font-size:17px;font-weight:800;color:var(--clms-p-text)}
	.clms-product-mobile-cta__label{display:block;font-size:12px;color:var(--clms-p-subtle)}
	.clms-product-mobile-cta__btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:10px 16px;background:var(--clms-p-accent);color:var(--clms-p-on-accent);border-radius:10px;text-decoration:none;font-weight:700}
	.clms-product-mobile-cta__btn:hover{background:var(--clms-p-accent-hover);color:var(--clms-p-on-accent)}
}
@media (max-width:520px){
	.clms-product-mobile-cta__inner{grid-template-columns:1fr}
	.clms-product-mobile-cta__btn{width:100%}
}
</style>

<div class="clms-product-wrap">
	<?php if ( function_exists( 'woocommerce_output_all_notices' ) ) : ?>
		<?php woocommerce_output_all_notices(); ?>
	<?php endif; ?>

	<section class="clms-product-hero">
		<div>
			<p class="clms-product-eyebrow"><?php esc_html_e( 'Oferta académica', 'atora-lms' ); ?></p>
			<h1 class="clms-product-title"><?php echo esc_html( $hero_title ); ?></h1>
			<?php if ( '' !== $hero_copy ) : ?>
				<p class="clms-product-copy"><?php echo esc_html( $hero_copy ); ?></p>
			<?php endif; ?>

			<div class="clms-product-meta">
				<span class="clms-product-chip"><?php echo esc_html( $mode_label ); ?></span>
				<?php if ( $access_days > 0 ) : ?>
					<span class="clms-product-chip">
						<?php
						printf(
							/* translators: %d: days */
							esc_html__( 'Acceso por %d días', 'atora-lms' ),
							$access_days
						);
						?>
					</span>
				<?php else : ?>
					<span class="clms-product-chip"><?php esc_html_e( 'Acceso sin expiración', 'atora-lms' ); ?></span>
				<?php endif; ?>
				<?php if ( $duration ) : ?>
					<span class="clms-product-chip"><?php echo esc_html( $duration ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $entity_url && '' !== $entity_label ) : ?>
				<p>
					<a class="clms-product-entity-link" href="<?php echo esc_url( $entity_url ); ?>">
						<?php echo esc_html( $entity_label ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $primary_image ) : ?>
				<div class="clms-product-media">
					<div class="clms-product-media-main">
						<?php echo $primary_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<?php if ( ! empty( $gallery_image_ids ) ) : ?>
						<div class="clms-product-media-grid">
							<?php foreach ( array_slice( $gallery_image_ids, 0, 4 ) as $image_id ) : ?>
								<?php
								$image_html = wp_get_attachment_image( absint( $image_id ), 'medium', false, array( 'loading' => 'lazy' ) );
								if ( ! $image_html ) {
									continue;
								}
								echo $image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<aside class="clms-product-buy">
			<div id="clms-product-buy-box" class="clms-product-buy-card">
				<p class="clms-product-buy-title"><?php esc_html_e( 'Inscripción', 'atora-lms' ); ?></p>
				<?php echo $purchase_box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="clms-product-buy-note"><?php esc_html_e( 'Pago seguro. Activación académica automática al confirmar la compra.', 'atora-lms' ); ?></p>
			</div>
		</aside>
	</section>

	<section class="clms-product-section">
		<h2><?php esc_html_e( 'Resumen de acceso', 'atora-lms' ); ?></h2>
		<div class="clms-product-grid">
			<div class="clms-product-info-card">
				<span class="clms-product-info-label"><?php esc_html_e( 'Tipo', 'atora-lms' ); ?></span>
				<span class="clms-product-info-value"><?php echo esc_html( $mode_label ); ?></span>
			</div>
			<?php if ( $linked_program ) : ?>
				<div class="clms-product-info-card">
					<span class="clms-product-info-label"><?php esc_html_e( 'Programa vinculado', 'atora-lms' ); ?></span>
					<span class="clms-product-info-value"><?php echo esc_html( (string) get_the_title( $linked_program ) ); ?></span>
				</div>
			<?php endif; ?>
			<?php if ( $linked_course ) : ?>
				<div class="clms-product-info-card">
					<span class="clms-product-info-label"><?php esc_html_e( 'Curso vinculado', 'atora-lms' ); ?></span>
					<span class="clms-product-info-value"><?php echo esc_html( (string) get_the_title( $linked_course ) ); ?></span>
				</div>
			<?php endif; ?>
			<div class="clms-product-info-card">
				<span class="clms-product-info-label"><?php esc_html_e( 'Acceso', 'atora-lms' ); ?></span>
				<span class="clms-product-info-value">
					<?php echo esc_html( $access_days > 0 ? sprintf( __( '%d días', 'atora-lms' ), $access_days ) : __( 'Sin vencimiento', 'atora-lms' ) ); ?>
				</span>
			</div>
		</div>
	</section>

	<?php if ( ! empty( $bundle_course_labels ) || ! empty( $bundle_program_labels ) ) : ?>
		<section class="clms-product-section">
			<h2><?php esc_html_e( 'Contenido del paquete', 'atora-lms' ); ?></h2>
			<?php if ( ! empty( $bundle_program_labels ) ) : ?>
				<p><strong><?php esc_html_e( 'Programas incluidos', 'atora-lms' ); ?></strong></p>
				<ul class="clms-product-list">
					<?php foreach ( $bundle_program_labels as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $bundle_course_labels ) ) : ?>
				<p><strong><?php esc_html_e( 'Cursos incluidos', 'atora-lms' ); ?></strong></p>
				<ul class="clms-product-list">
					<?php foreach ( $bundle_course_labels as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( $has_description ) : ?>
		<section class="clms-product-section">
			<h2><?php esc_html_e( 'Descripción del producto', 'atora-lms' ); ?></h2>
			<div class="clms-product-richtext">
				<?php echo $content_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</section>
	<?php endif; ?>
</div>

<div class="clms-product-mobile-cta">
	<div class="clms-product-mobile-cta__inner">
		<div>
			<?php if ( '' !== $mobile_price_label ) : ?>
				<span class="clms-product-mobile-cta__price"><?php echo esc_html( $mobile_price_label ); ?></span>
			<?php endif; ?>
			<span class="clms-product-mobile-cta__label"><?php esc_html_e( 'Inscripción y activación inmediata', 'atora-lms' ); ?></span>
		</div>
		<a class="clms-product-mobile-cta__btn" href="#clms-product-buy-box"><?php esc_html_e( 'Comprar ahora', 'atora-lms' ); ?></a>
	</div>
</div>

<?php get_footer(); ?>
