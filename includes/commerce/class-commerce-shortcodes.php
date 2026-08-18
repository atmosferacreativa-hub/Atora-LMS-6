<?php
/**
 * CLMS_Commerce_Shortcodes — Shortcodes de soporte para páginas de producto WooCommerce.
 *
 * Registra:
 *   [atora_product_purchase_box]          — usa el producto del post actual.
 *   [atora_product_purchase_box id="123"] — usa el producto con ese ID.
 *
 * Maneja explícitamente los cuatro tipos de producto de WooCommerce:
 *   simple / variable / virtual / downloadable → botón estándar de WC.
 *   external / affiliate                       → botón de enlace externo de WC.
 *   grouped                                    → enlace a la página del producto
 *                                                (la tabla de hijos es demasiado
 *                                                 compleja para un widget embebido).
 *
 * Compatible con Elementor, Gutenberg y plantillas PHP puras.
 * Restaura correctamente los globales $post y $product tras el render.
 *
 * El color del botón CTA se inyecta desde los ajustes de academia vía
 * wp_add_inline_style para que siempre coincida con primary_color.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Commerce_Shortcodes {

	/**
	 * Tipos de producto cuyo add-to-cart es seguro renderizar en línea.
	 * "grouped" queda fuera deliberadamente.
	 */
	const INLINE_CART_TYPES = array(
		'simple',
		'variable',
		'virtual',
		'downloadable',
		'external',
		'subscription',          // WooCommerce Subscriptions.
		'variable-subscription', // WooCommerce Subscriptions.
		'bundle',                // WooCommerce Product Bundles.
		'composite',             // WooCommerce Composite Products.
	);

	public function __construct() {
		add_shortcode( 'atora_product_purchase_box', array( $this, 'render_purchase_box' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	// ── Shortcode ─────────────────────────────────────────────────────────────

	/**
	 * Punto de entrada del shortcode.
	 *
	 * @param array|string $atts Atributos. Acepta `id` (int).
	 * @return string HTML.
	 */
	public function render_purchase_box( $atts ) {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$atts       = shortcode_atts( array( 'id' => 0 ), $atts, 'atora_product_purchase_box' );
		$product_id = absint( $atts['id'] ) ?: absint( get_the_ID() );

		if ( ! $product_id ) {
			return $this->render_warning( __( 'No se pudo determinar el producto.', 'atora-lms' ) );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return $this->render_warning( __( 'Producto no encontrado.', 'atora-lms' ) );
		}

		if ( ! $product->is_visible() && ! current_user_can( 'edit_products' ) ) {
			return $this->render_warning( __( 'Este producto no está disponible en este momento.', 'atora-lms' ) );
		}

		return $this->render_with_product_context( $product_id, $product );
	}

	// ── Render con contexto WooCommerce ───────────────────────────────────────

	/**
	 * Configura los globales de WooCommerce, renderiza precio + carrito
	 * con dispatch explícito por tipo de producto, y restaura el estado anterior.
	 *
	 * @param int        $product_id ID del producto.
	 * @param WC_Product $product    Instancia del producto.
	 * @return string HTML.
	 */
	private function render_with_product_context( $product_id, $product ) {
		$saved_post    = isset( $GLOBALS['post'] )    ? $GLOBALS['post']    : null;
		$saved_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;

		$product_post = get_post( $product_id );

		if ( ! $product_post ) {
			return $this->render_warning( __( 'No se encontró el post del producto.', 'atora-lms' ) );
		}

		$GLOBALS['post']    = $product_post;
		$GLOBALS['product'] = $product;
		setup_postdata( $product_post );

		ob_start();
		echo '<div class="atora-purchase-box">';

		// ── Precio ────────────────────────────────────────────────────────────
		// woocommerce_template_single_price() es segura para todos los tipos:
		// simple muestra precio fijo, variable muestra rango, grouped muestra
		// rango de hijos, external muestra precio si lo tiene.
		echo '<div class="atora-purchase-box__price">';
		woocommerce_template_single_price();
		echo '</div>';

		// ── Carrito — dispatch por tipo ───────────────────────────────────────
		echo '<div class="atora-purchase-box__cart">';
		$this->render_cart_by_type( $product, $product_id );
		echo '</div>';

		echo '</div>';
		$html = ob_get_clean();

		// Restaurar globales.
		$GLOBALS['post']    = $saved_post;
		$GLOBALS['product'] = $saved_product;
		if ( $saved_post instanceof WP_Post ) {
			setup_postdata( $saved_post );
		}

		return $html;
	}

	/**
	 * Renderiza la acción de carrito adecuada según el tipo de producto.
	 *
	 * Grouped: la plantilla de WC genera una tabla compleja con todos los
	 * productos hijos, que no es apta para un widget incrustado. Se muestra
	 * un enlace a la página completa del producto.
	 *
	 * External / todos los demás tipos soportados: se delega en la plantilla
	 * nativa de WooCommerce, que ya maneja correctamente cada tipo.
	 *
	 * @param WC_Product $product    Producto.
	 * @param int        $product_id ID del producto.
	 * @return void
	 */
	private function render_cart_by_type( $product, $product_id ) {
		$type = $product->get_type();

		if ( 'grouped' === $type ) {
			// Los productos agrupados muestran una tabla de hijos que rompe
			// los layouts embebidos. Redirigir a la página completa del producto.
			printf(
				'<a href="%s" class="single_add_to_cart_button button alt">%s</a>',
				esc_url( get_permalink( $product_id ) ),
				esc_html__( 'Ver opciones de compra', 'atora-lms' )
			);
			return;
		}

		// Para productos externos, WC verifica is_purchasable() de forma
		// diferente (siempre false en WC_Product_External), pero la plantilla
		// externa renderiza el botón de enlace correcto.
		if ( 'external' === $type ) {
			woocommerce_template_single_add_to_cart();
			return;
		}

		// Tipos estándar + extensiones conocidas.
		if ( in_array( $type, self::INLINE_CART_TYPES, true ) ) {
			if ( $product->is_purchasable() && $product->is_in_stock() ) {
				woocommerce_template_single_add_to_cart();
			} elseif ( ! $product->is_in_stock() ) {
				echo '<p class="atora-commerce-warning">' . esc_html__( 'Agotado', 'atora-lms' ) . '</p>';
			} else {
				echo '<p class="atora-commerce-warning">' . esc_html__( 'Este producto no está disponible para compra en este momento.', 'atora-lms' ) . '</p>';
			}
			return;
		}

		// Tipo desconocido (extensión no listada): intentar con la plantilla
		// estándar si es comprable; si no, enlace a la página del producto.
		if ( $product->is_purchasable() && $product->is_in_stock() ) {
			woocommerce_template_single_add_to_cart();
		} else {
			printf(
				'<a href="%s" class="single_add_to_cart_button button alt">%s</a>',
				esc_url( get_permalink( $product_id ) ),
				esc_html__( 'Ver producto', 'atora-lms' )
			);
		}
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	/**
	 * Encola el CSS del bloque de compra e inyecta el color de academia
	 * configurado en los ajustes de ATORA como variable CSS :root,
	 * sobrescribiendo el valor por defecto del archivo estático.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$css_path = defined( 'ATORA_LMS_DIR' ) ? ATORA_LMS_DIR . 'assets/css/frontend/commerce.css' : '';
		$css_url  = defined( 'ATORA_LMS_URL' ) ? ATORA_LMS_URL . 'assets/css/frontend/commerce.css' : '';

		if ( ! $css_path || ! $css_url || ! file_exists( $css_path ) ) {
			return;
		}

		wp_enqueue_style(
			'atora-commerce',
			$css_url,
			array( 'woocommerce-general' ),
			defined( 'ATORA_LMS_VERSION' ) ? ATORA_LMS_VERSION : '1.0'
		);

		// Inyectar color de academia como tokens CSS, sobrescribiendo los
		// valores por defecto del archivo estático. Se hace aquí (PHP) para
		// que el color siempre coincida con primary_color en ajustes de ATORA.
		$primary      = $this->get_primary_color();
		$primary_dark = $this->darken_hex( $primary, 0.12 );

		wp_add_inline_style(
			'atora-commerce',
			sprintf(
				':root{--atora-btn-bg:%s;--atora-btn-bg-hover:%s;}',
				esc_attr( $primary ),
				esc_attr( $primary_dark )
			)
		);
	}

	// ── Helpers de color ─────────────────────────────────────────────────────

	/**
	 * Devuelve el color principal de la academia desde CLMS_Settings.
	 * Fallback a morado ATORA si los ajustes no están disponibles.
	 *
	 * @return string Código hex validado (p. ej. '#6366f1').
	 */
	private function get_primary_color(): string {
		if ( class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' ) ) {
			$settings = CLMS_Settings::get_academy_settings();
			$color    = sanitize_hex_color( (string) ( $settings['primary_color'] ?? '' ) );
			if ( $color ) {
				return $color;
			}
		}

		return '#6366f1';
	}

	/**
	 * Oscurece un color hexadecimal multiplicando cada canal RGB por (1 - $amount).
	 *
	 * @param string $hex    Código hex con o sin '#' (3 o 6 dígitos).
	 * @param float  $amount Fracción de oscurecimiento en [0, 1]. 0.12 = 12 % más oscuro.
	 * @return string Código hex resultante con '#'.
	 */
	private function darken_hex( string $hex, float $amount ): string {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#4f46e5';
		}

		$factor = max( 0.0, min( 1.0, 1.0 - $amount ) );

		$r = (int) round( hexdec( substr( $hex, 0, 2 ) ) * $factor );
		$g = (int) round( hexdec( substr( $hex, 2, 2 ) ) * $factor );
		$b = (int) round( hexdec( substr( $hex, 4, 2 ) ) * $factor );

		return sprintf( '#%02x%02x%02x', max( 0, $r ), max( 0, $g ), max( 0, $b ) );
	}

	// ── Helper de aviso ───────────────────────────────────────────────────────

	/**
	 * @param string $message Mensaje ya internacionalizado.
	 * @return string
	 */
	private function render_warning( $message ) {
		return '<p class="atora-commerce-warning">' . esc_html( $message ) . '</p>';
	}
}
