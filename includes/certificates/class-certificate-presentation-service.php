<?php
/**
 * Servicio de presentación y compartición de certificados.
 *
 * @package CustomLMSCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Certificate_Presentation_Service {

	const OPT_SIGNATURE_NAME = 'clms_certificate_signature_name';
	const OPT_SIGNATURE_ROLE = 'clms_certificate_signature_role';
	const OPT_SEAL_TEXT      = 'clms_certificate_seal_text';

	/**
	 * Contexto visual/institucional del certificado.
	 *
	 * @return array<string,string>
	 */
	public function get_branding_context() {
		$academy = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_academy_settings' )
			? (array) CLMS_Settings::get_academy_settings()
			: array();

		$academy_name = ! empty( $academy['academy_name'] )
			? sanitize_text_field( (string) $academy['academy_name'] )
			: sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$logo_id      = ! empty( $academy['logo_id'] ) ? absint( $academy['logo_id'] ) : 0;
		$logo_url     = $logo_id ? wp_get_attachment_url( $logo_id ) : '';

		$signature_name = sanitize_text_field( (string) get_option( self::OPT_SIGNATURE_NAME, '' ) );
		$signature_role = sanitize_text_field( (string) get_option( self::OPT_SIGNATURE_ROLE, '' ) );
		$seal_text      = sanitize_text_field( (string) get_option( self::OPT_SEAL_TEXT, '' ) );

		if ( '' === $signature_name ) {
			$signature_name = __( 'Coordinación académica', 'atora-lms' );
		}
		if ( '' === $signature_role ) {
			$signature_role = __( 'Dirección de estudios', 'atora-lms' );
		}
		if ( '' === $seal_text ) {
			$seal_text = sprintf(
				/* translators: %s: nombre de academia */
				__( 'Sello oficial · %s', 'atora-lms' ),
				$academy_name
			);
		}

		return array(
			'academy_name'   => $academy_name,
			'logo_url'       => esc_url_raw( (string) $logo_url ),
			'signature_name' => $signature_name,
			'signature_role' => $signature_role,
			'seal_text'      => $seal_text,
		);
	}

	/**
	 * Construye acciones de compartición.
	 *
	 * @param array<string,mixed> $args Contexto del certificado.
	 * @return array<string,string>
	 */
	public function build_share_actions( $args ) {
		$args = is_array( $args ) ? $args : array();

		$title            = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		$academy_name     = sanitize_text_field( (string) ( $args['academy_name'] ?? get_bloginfo( 'name' ) ) );
		$certificate_code = sanitize_text_field( (string) ( $args['certificate_code'] ?? '' ) );
		$verification_url = esc_url_raw( (string) ( $args['verification_url'] ?? '' ) );
		$view_url         = esc_url_raw( (string) ( $args['view_url'] ?? '' ) );
		$issued_at        = sanitize_text_field( (string) ( $args['issued_at'] ?? '' ) );

		$share_url = $verification_url ? $verification_url : $view_url;
		$timestamp = $issued_at ? strtotime( $issued_at ) : false;
		$year      = $timestamp ? gmdate( 'Y', (int) $timestamp ) : gmdate( 'Y' );
		$month     = $timestamp ? gmdate( 'n', (int) $timestamp ) : gmdate( 'n' );

		$linkedin_url = add_query_arg(
			array(
				'startTask'        => 'CERTIFICATION_NAME',
				'name'             => $title,
				'organizationName' => $academy_name,
				'issueYear'        => absint( $year ),
				'issueMonth'       => absint( $month ),
				'certUrl'          => $verification_url ? $verification_url : $view_url,
				'certId'           => $certificate_code,
			),
			'https://www.linkedin.com/profile/add'
		);

		$whatsapp_message = sprintf(
			/* translators: 1: nombre certificado, 2: academia, 3: url */
			__( 'Obtuve mi credencial "%1$s" emitida por %2$s. Verificación: %3$s', 'atora-lms' ),
			$title,
			$academy_name,
			$share_url
		);

		return array(
			'share_url'    => $share_url,
			'verification' => $verification_url,
			'linkedin'     => esc_url_raw( $linkedin_url ),
			'whatsapp'     => esc_url_raw( 'https://wa.me/?text=' . rawurlencode( $whatsapp_message ) ),
		);
	}

	/**
	 * Obtiene etiquetas de competencias certificadas.
	 *
	 * @param int              $course_id        Curso.
	 * @param array<int,mixed> $competency_ids   IDs requeridos/logrados.
	 * @param int              $limit            Límite visual.
	 * @return array<int,string>
	 */
	public function get_course_competency_labels( $course_id, $competency_ids = array(), $limit = 6 ) {
		$course_id      = absint( $course_id );
		$competency_ids = is_array( $competency_ids ) ? array_values( array_filter( array_map( 'sanitize_key', $competency_ids ) ) ) : array();
		$limit          = max( 1, absint( $limit ) );

		if ( ! $course_id ) {
			return array();
		}

		$service = class_exists( 'CLMS_Helper' ) ? clms_core('CLMS_Competency_Service') : null;
		if ( ! $service || ! method_exists( $service, 'get_course_competencies' ) ) {
			return array();
		}

		$competencies = (array) $service->get_course_competencies( $course_id );
		$map          = array();

		foreach ( $competencies as $competency ) {
			$competency = is_array( $competency ) ? $competency : array();
			$id         = sanitize_key( (string) ( $competency['id'] ?? '' ) );
			$title      = sanitize_text_field( (string) ( $competency['title'] ?? '' ) );
			if ( '' === $id || '' === $title ) {
				continue;
			}
			$map[ $id ] = $title;
		}

		$labels = array();
		if ( empty( $competency_ids ) ) {
			$labels = array_values( $map );
		} else {
			foreach ( $competency_ids as $id ) {
				if ( isset( $map[ $id ] ) ) {
					$labels[] = $map[ $id ];
				}
			}
		}

		return array_slice( array_values( array_unique( $labels ) ), 0, $limit );
	}
}
