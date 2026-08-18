<?php
/**
 * Resolver unificado de identidades de email.
 *
 * Mantiene compatibilidad con claves legacy de transporte:
 * - academia
 * - teacher
 * - admin (usada como buzón comercial en CRM)
 *
 * @package ATORA_LMS\EmailEngine
 */

namespace ATORA\EmailEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Email_Identity_Resolver {
	/**
	 * Normaliza una identidad al set de transporte (`academia|teacher|admin`).
	 *
	 * @param string $identity Identidad cruda.
	 * @param string $default  Fallback.
	 * @return string
	 */
	public static function normalize( string $identity, string $default = 'academia' ): string {
		$lane = self::normalize_lane( $identity, self::to_lane( $default ) );
		return self::to_transport( $lane );
	}

	/**
	 * Normaliza una identidad a carril funcional (`academia|teacher|commercial`).
	 *
	 * @param string $identity Identidad cruda.
	 * @param string $default  Fallback.
	 * @return string
	 */
	public static function normalize_lane( string $identity, string $default = 'academia' ): string {
		$identity = sanitize_key( $identity );
		$default  = sanitize_key( $default );
		if ( ! in_array( $default, array( 'academia', 'teacher', 'commercial' ), true ) ) {
			$default = 'academia';
		}
		if ( '' === $identity ) {
			return $default;
		}

		$map = array(
			'academia'       => 'academia',
			'teacher'        => 'teacher',
			'docencia'       => 'teacher',
			'docente'        => 'teacher',
			'seguimiento'    => 'teacher',
			'educacion'      => 'teacher',
			'commercial'     => 'commercial',
			'comercial'      => 'commercial',
			'comercio'       => 'commercial',
			'sales'          => 'commercial',
			'venta'          => 'commercial',
			'ventas'         => 'commercial',
			'lead'           => 'commercial',
			'leads'          => 'commercial',
			'prospect'       => 'commercial',
			'prospecto'      => 'commercial',
			'crm'            => 'commercial',
			'admin'          => 'commercial',
			'administracion' => 'academia',
			'administration' => 'academia',
			'operations'     => 'academia',
			'ops'            => 'academia',
			'plataforma'     => 'academia',
			'sitio'          => 'academia',
			'site'           => 'academia',
			'tienda'         => 'academia',
			'store'          => 'academia',
			'woocommerce'    => 'academia',
		);

		return $map[ $identity ] ?? $default;
	}

	/**
	 * Convierte carril funcional a clave de transporte.
	 *
	 * @param string $lane Carril funcional.
	 * @return string
	 */
	public static function to_transport( string $lane ): string {
		$lane = sanitize_key( $lane );
		if ( 'commercial' === $lane ) {
			return 'admin';
		}
		if ( in_array( $lane, array( 'academia', 'teacher' ), true ) ) {
			return $lane;
		}

		return 'academia';
	}

	/**
	 * Convierte clave de transporte a carril funcional.
	 *
	 * @param string $identity Clave de transporte.
	 * @return string
	 */
	public static function to_lane( string $identity ): string {
		$identity = sanitize_key( $identity );
		if ( 'admin' === $identity ) {
			return 'commercial';
		}
		if ( in_array( $identity, array( 'academia', 'teacher' ), true ) ) {
			return $identity;
		}

		return 'academia';
	}

	/**
	 * Clave primaria usada en `atora_email_identities`.
	 *
	 * @param string $identity Identidad transporte.
	 * @return string
	 */
	public static function to_option_key( string $identity ): string {
		$identity = self::normalize( $identity, 'academia' );
		$map      = array(
			'academia' => 'academia',
			'teacher'  => 'comercio',
			'admin'    => 'administracion',
		);

		return $map[ $identity ] ?? 'academia';
	}

	/**
	 * Candidatos de lectura para `atora_email_identities`.
	 *
	 * @param string $identity Identidad transporte.
	 * @return array<int,string>
	 */
	public static function option_candidates( string $identity ): array {
		$identity = self::normalize( $identity, 'academia' );

		$candidates = array(
			self::to_option_key( $identity ),
			$identity,
		);

		if ( 'teacher' === $identity ) {
			$candidates[] = 'docencia';
		}
		if ( 'admin' === $identity ) {
			$candidates[] = 'commercial';
			$candidates[] = 'comercio';
			$candidates[] = 'comercial';
		}

		return array_values( array_unique( array_map( 'sanitize_key', $candidates ) ) );
	}

	/**
	 * Opciones para selectores en UI CRM.
	 *
	 * @return array<string,string>
	 */
	public static function get_ui_options(): array {
		return array(
			'academia' => __( 'Academia (plataforma y tienda)', 'atora-lms' ),
			'teacher'  => __( 'Docencia (estudiantes inscritos)', 'atora-lms' ),
			'admin'    => __( 'Comercial (leads y prospectos)', 'atora-lms' ),
		);
	}
}

