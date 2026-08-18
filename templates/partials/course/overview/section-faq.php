<?php
/**
 * Partial: FAQ con accordion JS — single-course (overview)
 * Variables: $faq_items (array of ['q','a']), $course_id
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$faq_id_prefix = 'cov-faq-' . $course_id;
?>
<div class="cov-faq">
	<h2 class="cov-section-title"><?php esc_html_e( 'Preguntas frecuentes', 'atora-lms' ); ?></h2>
	<dl class="cov-faq-list">
		<?php foreach ( $faq_items as $fi => $faq ) :
			$item_id = esc_attr( $faq_id_prefix . '-' . $fi );
		?>
			<div class="cov-faq-item">
				<dt>
					<button
						class="cov-faq-btn"
						aria-expanded="false"
						aria-controls="<?php echo $item_id; ?>"
					>
						<?php echo esc_html( $faq['q'] ); ?>
						<svg class="cov-faq-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
							<path d="M4 6l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</button>
				</dt>
				<dd class="cov-faq-answer" id="<?php echo $item_id; ?>" hidden>
					<?php echo nl2br( esc_html( $faq['a'] ) ); ?>
				</dd>
			</div>
		<?php endforeach; ?>
	</dl>
</div>
<script>
(function() {
	var list = document.querySelector('.cov-faq-list');
	if (!list) return;
	list.addEventListener('click', function(e) {
		var btn = e.target.closest('.cov-faq-btn');
		if (!btn) return;
		var expanded = btn.getAttribute('aria-expanded') === 'true';
		var answer   = document.getElementById(btn.getAttribute('aria-controls'));
		btn.setAttribute('aria-expanded', String(!expanded));
		if (answer) { answer.hidden = expanded; }
	});
})();
</script>
