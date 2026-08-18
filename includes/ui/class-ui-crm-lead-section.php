<?php
/**
 * CLMS_UI_CRM_Lead_Section
 *
 * Sección reutilizable de captura CRM para templates comerciales.
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_UI_CRM_Lead_Section {

	/** @var array<string,array<string,mixed>> */
	private static array $cache = array();

	/**
	 * Datos normalizados de la sección CRM lead.
	 *
	 * @return array<string,mixed>
	 */
	public static function data( int $entity_id, string $schema_context = '' ): array {
		$cache_key = absint( $entity_id ) . ':' . sanitize_key( $schema_context );
		if ( isset( self::$cache[ $cache_key ] ) ) {
			return self::$cache[ $cache_key ];
		}

		$form_id = self::get_meta_first_int(
			$entity_id,
			array(
				'_clms_crm_lead_form_id',
				'_clms_commercial_lead_form_id',
				'_clms_lead_widget_form_id', // Compatibilidad avanzada.
			)
		);

		if ( ! $form_id ) {
			$form_id = absint( get_option( 'clms_crm_lead_form_id', 0 ) );
		}

		$title = self::get_meta_first_string(
			$entity_id,
			array(
				'_clms_crm_lead_title',
				'_clms_commercial_lead_title',
				'_clms_lead_widget_title', // Compatibilidad avanzada.
			)
		);
		if ( '' === trim( $title ) ) {
			$title = (string) get_option( 'clms_crm_lead_title', '' );
		}
		if ( '' === trim( $title ) ) {
			$title = __( '¿Quieres hablar con nuestro equipo?', 'atora-lms' );
		}

		$copy = self::get_meta_first_string(
			$entity_id,
			array(
				'_clms_crm_lead_copy',
				'_clms_commercial_lead_copy',
				'_clms_lead_widget_copy', // Compatibilidad avanzada.
			)
		);
		if ( '' === trim( $copy ) ) {
			$copy = (string) get_option( 'clms_crm_lead_copy', '' );
		}
		if ( '' === trim( $copy ) ) {
			$copy = __( 'Déjanos tus datos y te ayudamos a elegir la mejor ruta académica para tus objetivos.', 'atora-lms' );
		}

		$shortcode_ready = function_exists( 'shortcode_exists' ) && shortcode_exists( 'atora_form' );

		$data = array(
			'form_id'         => $form_id,
			'title'           => $title,
			'copy'            => $copy,
			'shortcode_ready' => $shortcode_ready,
			'should_render'   => $form_id > 0 && $shortcode_ready,
		);

		/**
		 * Permite enriquecer datos de captura CRM desde módulos externos.
		 *
		 * @param array  $data           Datos normalizados.
		 * @param int    $entity_id      ID de entidad actual.
		 * @param string $schema_context Contexto de schema actual.
		 */
		$data = apply_filters( 'clms_ui_crm_lead_data', $data, $entity_id, sanitize_key( $schema_context ) );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		self::$cache[ $cache_key ] = $data;
		return self::$cache[ $cache_key ];
	}

	/**
	 * Render de sección reusable.
	 *
	 * @param array<string,mixed> $view_args
	 */
	public static function render( int $entity_id, string $schema_context, array $view_args = array() ): void {
		$data = self::data( $entity_id, $schema_context );
		if ( empty( $data['should_render'] ) ) {
			return;
		}

		$classes = self::resolve_context_classes( $schema_context );

		$crm_lead_title          = (string) ( $data['title'] ?? '' );
		$crm_lead_copy           = (string) ( $data['copy'] ?? '' );
		$crm_lead_form_id        = absint( $data['form_id'] ?? 0 );
		$crm_lead_form_shortcode = '[atora_form id="' . $crm_lead_form_id . '"]';
		$crm_lead_scope_class    = (string) ( $view_args['scope_class'] ?? $classes['scope_class'] );
		$crm_lead_title_class    = (string) ( $view_args['title_class'] ?? $classes['title_class'] );
		$crm_lead_context        = sanitize_key( $schema_context );

		$partial = self::resolve_partial( 'crm/section-lead-capture.php' );
		if ( ! $partial ) {
			return;
		}

		include $partial;
	}

	/**
	 * Resuelve clases base según el contexto.
	 *
	 * @return array{scope_class:string,title_class:string}
	 */
	private static function resolve_context_classes( string $schema_context ): array {
		$schema_context = sanitize_key( $schema_context );
		if ( 0 === strpos( $schema_context, 'program_' ) ) {
			return array(
				'scope_class' => 'apl-section',
				'title_class' => 'apl-section-title',
			);
		}
		if ( 0 === strpos( $schema_context, 'course_' ) || 'landing_course' === $schema_context ) {
			return array(
				'scope_class' => 'cc-section',
				'title_class' => 'cc-section-title',
			);
		}

		return array(
			'scope_class' => 'clms-crm-lead-section',
			'title_class' => 'clms-crm-lead-title',
		);
	}

	private static function resolve_partial( string $relative ): string {
		$relative = ltrim( $relative, '/\\' );
		$paths    = array(
			trailingslashit( get_stylesheet_directory() ) . 'templates/partials/' . $relative,
			trailingslashit( get_stylesheet_directory() ) . 'atora-lms/templates/partials/' . $relative,
			trailingslashit( ATORA_LMS_DIR ) . 'templates/partials/' . $relative,
		);

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	private static function get_meta_first_string( int $post_id, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, (string) $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	private static function get_meta_first_int( int $post_id, array $keys ): int {
		foreach ( $keys as $key ) {
			$value = absint( get_post_meta( $post_id, (string) $key, true ) );
			if ( $value > 0 ) {
				return $value;
			}
		}
		return 0;
	}
}
