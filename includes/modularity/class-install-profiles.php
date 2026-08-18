<?php
/**
 * CLMS_Install_Profiles — PT-3 (sprint 6.3.0)
 *
 * Tres perfiles de instalación, cada uno un conjunto de slugs de
 * CLMS_Module_Registry a activar. No cargan ni descargan nada por sí
 * mismos: `apply()` solo escribe la option `atora_active_modules` que
 * CLMS_Module_Registry ya sabe leer.
 *
 * Los slugs no mencionados explícitamente por la OT para `institucional`
 * (automation, webhooks, mcp) se tratan como excluidos — encajan en el
 * espíritu de "sin módulos comerciales" del perfil aunque no aparezcan en
 * la lista "sin X" explícita. Documentado también en DEUDA-TECNICA.md.
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Install_Profiles {

	const OPTION = 'atora_install_profile';

	const INSTITUCIONAL_BASE = array(
		'lms', 'academic', 'gradebook', 'certificates', 'security',
		'messaging', 'calendar', 'analytics', 'ai',
	);

	/**
	 * @return array<string,array{label:string,description:string,modules:string[]}>
	 */
	public static function get_profiles(): array {
		$all             = array_keys( CLMS_Module_Registry::get_modules() );
		$institucional   = self::INSTITUCIONAL_BASE;
		$corporativo     = array_values( array_unique( array_merge( $institucional, array( 'crm', 'automation', 'email-engine' ) ) ) );

		return array(
			'academia'      => array(
				'label'       => __( 'Academia', 'atora-lms' ),
				'description' => __( 'Todos los módulos activos — comportamiento actual, incluye CRM, comercio, afiliados y marketing.', 'atora-lms' ),
				'modules'     => $all,
			),
			'institucional' => array(
				'label'       => __( 'Institucional', 'atora-lms' ),
				'description' => __( 'Panel académico puro: LMS, académico, gradebook, certificados, seguridad, mensajería, calendario, analítica e IA. Sin CRM, comercio, afiliados, newsletter ni streaming en vivo.', 'atora-lms' ),
				'modules'     => $institucional,
			),
			'corporativo'   => array(
				'label'       => __( 'Corporativo', 'atora-lms' ),
				'description' => __( 'Institucional + CRM, automatización y email engine. Sin comercio ni afiliados.', 'atora-lms' ),
				'modules'     => $corporativo,
			),
		);
	}

	/**
	 * @param string $profile
	 * @return string[]|null null si el perfil no existe.
	 */
	public static function get_profile_modules( string $profile ): ?array {
		$profiles = self::get_profiles();
		return $profiles[ $profile ]['modules'] ?? null;
	}

	/**
	 * Vista previa de lo que cambiaría al aplicar `$profile` sobre el
	 * estado actual — para mostrar antes de confirmar (PT-3.3).
	 *
	 * @param string $profile
	 * @return array{activates:string[], deactivates:string[]}|null
	 */
	public static function preview( string $profile ): ?array {
		$target = self::get_profile_modules( $profile );
		if ( null === $target ) { return null; }

		$current = CLMS_Module_Registry::get_active_slugs();

		return array(
			'activates'   => array_values( array_diff( $target, $current ) ),
			'deactivates' => array_values( array_diff( $current, $target ) ),
		);
	}

	/**
	 * Aplica el perfil: guarda `atora_install_profile` y el set de módulos
	 * correspondiente en `atora_active_modules`. No borra datos de los
	 * módulos que queden desactivados — solo apaga su carga.
	 *
	 * @param string $profile
	 * @return bool false si el perfil no existe.
	 */
	public static function apply( string $profile ): bool {
		$modules = self::get_profile_modules( $profile );
		if ( null === $modules ) { return false; }

		update_option( self::OPTION, $profile );
		update_option( 'atora_active_modules', $modules );
		CLMS_Module_Registry::flush_cache();

		return true;
	}

	/**
	 * @return string Perfil actual, 'academia' si nunca se configuró
	 * (comportamiento por defecto = todo activo, igual que el perfil academia).
	 */
	public static function current(): string {
		$stored = (string) get_option( self::OPTION, '' );
		return isset( self::get_profiles()[ $stored ] ) ? $stored : 'academia';
	}
}
