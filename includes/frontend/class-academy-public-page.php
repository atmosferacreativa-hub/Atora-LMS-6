<?php
/** Shared, academy-branded shell for public plugin endpoints. */
defined( 'ABSPATH' ) || exit;

final class CLMS_Academy_Public_Page {

	/** Render trusted HTML produced by the endpoint's escaping content() method. */
	public static function render( string $title, string $content ): void {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}
		// Avoid a second, unrelated title generated from the intercepted WP query.
		$title_filter = static function () use ( $title ) {
			return $title . ' · ' . get_bloginfo( 'name' );
		};
		add_filter( 'pre_get_document_title', $title_filter );
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<meta name="robots" content="noindex,nofollow">
			<?php if ( ! current_theme_supports( 'title-tag' ) ) : ?>
				<title><?php echo esc_html( $title_filter() ); ?></title>
			<?php endif; ?>
			<?php wp_head(); ?>
			<style>
			body.atora-public-page{margin:0;padding:24px;background:var(--atora-bg,#f5f7fb);color:var(--atora-text,#1f2937);font-family:var(--atora-font-body,system-ui,sans-serif)}
			.atora-public-page .atora-public-card{box-sizing:border-box;width:100%;max-width:580px;margin:40px auto;padding:clamp(20px,5vw,32px);background:var(--atora-surface,#fff);border:1px solid var(--atora-border,#d1d5db);border-radius:14px;overflow-wrap:anywhere}
			.atora-public-card h1{font-family:var(--atora-font-display,inherit);color:inherit;font-size:clamp(24px,5vw,32px);line-height:1.25}
			.atora-public-card .academy{display:grid;gap:12px;margin-bottom:24px;color:var(--atora-text-muted,#6b7280)}
			.atora-public-card .custom-logo{max-width:200px;max-height:80px;width:auto;height:auto;object-fit:contain}
			.atora-public-card a{color:var(--atora-accent,#1B3A8C)}
			.atora-public-card input,.atora-public-card textarea{box-sizing:border-box;width:100%;max-width:100%;padding:10px;border:1px solid var(--atora-border,#d1d5db);border-radius:8px;background:var(--atora-surface,#fff);color:inherit;font:inherit}
			.atora-public-card button{padding:12px 18px;border:0;border-radius:8px;background:var(--atora-accent,#1B3A8C);color:var(--atora-on-accent,#fff);font:inherit;font-weight:700;min-height:44px;margin-top:12px;cursor:pointer;white-space:normal}
			.atora-public-card button:hover{background:var(--atora-accent-hover,var(--atora-accent,#1B3A8C))}
			.atora-public-card :focus-visible{outline:2px solid var(--atora-accent,#1B3A8C);outline-offset:3px}
			.atora-public-card .state{display:inline-block;font-size:22px;font-weight:800;padding:8px 18px;border-radius:999px;margin-bottom:16px}
			.atora-public-card .ok{background:#e3f4ec;color:#11683f}
			.atora-public-card .bad{background:#fdecec;color:#a11d1d}
			.atora-public-card dt{font-size:13px;color:var(--atora-text-muted,#6b7280);margin-top:12px}
			.atora-public-card dd{margin:2px 0 0;font-size:17px;font-weight:600}
			</style>
		</head>
		<body <?php body_class( 'atora-public-page' ); ?>>
		<?php wp_body_open(); ?>
		<main class="atora-public-card">
			<div class="academy">
				<?php echo get_custom_logo(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress generates escaped logo markup. ?>
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
			</div>
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Endpoint content() escapes all dynamic data. ?>
		</main>
		<?php wp_footer(); ?>
		</body>
		</html>
		<?php
		remove_filter( 'pre_get_document_title', $title_filter );
	}
}
