<?php
/**
 * CLMS_Profile_Labels — PT-3.4 (sprint 6.3.0)
 *
 * Vocabulario alternativo para el perfil `institucional`: "Estudiantes" en
 * vez de "Contactos", "Programa de formación" en vez de "Producto",
 * "Sección" en vez de "Cohorte comercial". Un solo mapa centralizado en
 * vez de tocar cadenas hardcodeadas una por una en cada vista.
 *
 * Uso: en vez de imprimir 'Contactos' directamente, el código de vistas
 * que quiera adoptar este vocabulario debe llamar
 * `atora_profile_label( 'contacts', 'Contactos' )`. Esto es opt-in por
 * diseño — no reemplaza automáticamente cadenas existentes (regla del
 * sprint: no tocar cadenas hardcodeadas una por una, no reescribir vistas
 * fuera de alcance).
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Profile_Labels {

	/**
	 * Mapa clave => etiqueta académica, solo usado cuando el perfil activo
	 * es 'institucional'.
	 *
	 * @return array<string,string>
	 */
	public static function get_institutional_map(): array {
		return array(
			'contacts'        => __( 'Estudiantes', 'atora-lms' ),
			'contact'         => __( 'Estudiante', 'atora-lms' ),
			'product'         => __( 'Programa de formación', 'atora-lms' ),
			'products'        => __( 'Programas de formación', 'atora-lms' ),
			'cohort'          => __( 'Sección', 'atora-lms' ),
			'cohorts'         => __( 'Secciones', 'atora-lms' ),
			'lead'            => __( 'Prospecto académico', 'atora-lms' ),
			'deal'            => __( 'Matrícula en curso', 'atora-lms' ),
			'pipeline'        => __( 'Proceso de admisión', 'atora-lms' ),
			'customer'        => __( 'Estudiante activo', 'atora-lms' ),
		);
	}

	/**
	 * @param string $key     Clave del mapa (p.ej. 'contacts').
	 * @param string $default Etiqueta a usar si el perfil no es institucional
	 *                        o la clave no está en el mapa.
	 * @return string
	 */
	public static function label( string $key, string $default ): string {
		if ( ! class_exists( 'CLMS_Install_Profiles' ) || 'institucional' !== CLMS_Install_Profiles::current() ) {
			return $default;
		}
		$map = self::get_institutional_map();
		return $map[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'atora_profile_label' ) ) {
	/**
	 * @param string $key     Clave del mapa de vocabulario.
	 * @param string $default Etiqueta por defecto (vocabulario "academia"/"corporativo").
	 * @return string
	 */
	function atora_profile_label( string $key, string $default ): string {
		return apply_filters( 'atora_profile_label', CLMS_Profile_Labels::label( $key, $default ), $key, $default );
	}
}
