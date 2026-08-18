<?php
/**
 * Partial: Galería con lightbox (inscritos) o estática (visitantes) — single-course (overview)
 * Variables: $gallery_image_ids, $gallery_interactive, $course_id
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="cov-gallery">
	<h2 class="cov-section-title"><?php esc_html_e( 'Galería', 'atora-lms' ); ?></h2>
	<div class="cov-gallery-grid" <?php if ( $gallery_interactive ) : ?>data-cov-lightbox="<?php echo esc_attr( 'gallery-' . $course_id ); ?>"<?php endif; ?>>
		<?php foreach ( $gallery_image_ids as $gid ) :
			$img_url  = wp_get_attachment_image_url( $gid, 'medium_large' );
			$img_full = wp_get_attachment_image_url( $gid, 'full' );
			$img_alt  = (string) get_post_meta( $gid, '_wp_attachment_image_alt', true );
			if ( ! $img_url ) { continue; }
		?>
			<?php if ( $gallery_interactive ) : ?>
			<button
				class="cov-gallery-item cov-gallery-trigger"
				type="button"
				data-full="<?php echo esc_url( $img_full ?: $img_url ); ?>"
				data-alt="<?php echo esc_attr( $img_alt ); ?>"
				aria-label="<?php echo esc_attr( $img_alt ?: __( 'Ver imagen', 'atora-lms' ) ); ?>"
			>
				<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $img_alt ); ?>" loading="lazy" />
			</button>
			<?php else : ?>
			<div class="cov-gallery-item cov-gallery-static">
				<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $img_alt ); ?>" loading="lazy" draggable="false" />
			</div>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</div>

<?php if ( $gallery_interactive ) : ?>
<div
	id="cov-lb-<?php echo esc_attr( $course_id ); ?>"
	class="cov-lightbox"
	role="dialog"
	aria-modal="true"
	aria-label="<?php esc_attr_e( 'Galería de imágenes', 'atora-lms' ); ?>"
	hidden
>
	<div class="cov-lb-backdrop" data-cov-lb-close></div>
	<div class="cov-lb-dialog">
		<button class="cov-lb-close" type="button" data-cov-lb-close aria-label="<?php esc_attr_e( 'Cerrar', 'atora-lms' ); ?>">
			<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
		</button>
		<button class="cov-lb-prev" type="button" data-cov-lb-prev aria-label="<?php esc_attr_e( 'Anterior', 'atora-lms' ); ?>">
			<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M13 4l-8 6 8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</button>
		<div class="cov-lb-img-wrap">
			<img class="cov-lb-img" src="" alt="" />
		</div>
		<button class="cov-lb-next" type="button" data-cov-lb-next aria-label="<?php esc_attr_e( 'Siguiente', 'atora-lms' ); ?>">
			<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M7 4l8 6-8 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</button>
		<p class="cov-lb-caption"></p>
	</div>
</div>
<script>
(function(){
	var lb = document.getElementById('cov-lb-<?php echo esc_js( (string) $course_id ); ?>');
	if (!lb) return;
	var img      = lb.querySelector('.cov-lb-img');
	var cap      = lb.querySelector('.cov-lb-caption');
	var grid     = document.querySelector('[data-cov-lightbox="gallery-<?php echo esc_js( (string) $course_id ); ?>"]');
	if (!grid) return;
	var triggers = Array.prototype.slice.call(grid.querySelectorAll('.cov-gallery-trigger'));
	var current  = 0;

	function show(index){
		current = (index + triggers.length) % triggers.length;
		var t   = triggers[current];
		img.src = t.getAttribute('data-full');
		img.alt = t.getAttribute('data-alt') || '';
		cap.textContent = img.alt;
		cap.hidden = !img.alt;
		lb.hidden  = false;
		document.body.style.overflow = 'hidden';
		lb.querySelector('.cov-lb-close').focus();
	}
	function close(){
		lb.hidden = true;
		document.body.style.overflow = '';
	}
	triggers.forEach(function(btn, i){
		btn.addEventListener('click', function(){ show(i); });
	});
	lb.querySelectorAll('[data-cov-lb-close]').forEach(function(el){
		el.addEventListener('click', close);
	});
	lb.querySelector('[data-cov-lb-prev]').addEventListener('click', function(){ show(current - 1); });
	lb.querySelector('[data-cov-lb-next]').addEventListener('click', function(){ show(current + 1); });
	lb.addEventListener('keydown', function(e){
		if (e.key === 'Escape')    { close(); }
		if (e.key === 'ArrowLeft') { show(current - 1); }
		if (e.key === 'ArrowRight'){ show(current + 1); }
	});
})();
</script>
<?php endif; ?>
