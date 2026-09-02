<?php
/**
 * CLMS_Install_Profiles — PT-3 (sprint 6.3.0), renombrado y ampliado a
 * cuatro perfiles en PT-1 (sprint 6.12.0).
 *
 * Cuatro perfiles de instalación, cada uno un conjunto de slugs de
 * CLMS_Module_Registry a activar. No cargan ni descargan nada por sí
 * mismos: `apply()` solo escribe la option `atora_active_modules` que
 * CLMS_Module_Registry ya sabe leer.
 *
 * "Personalizado" no es un perfil: es el estado derivado
 * `atora_profile_modified` (bool). apply() lo pone a false; cualquier
 * cambio manual de módulos desde ATORA → Módulos lo pone a true. La UI
 * nunca ofrece "Personalizado" como opción elegible, solo lo muestra como
 * sufijo del perfil guardado (p.ej. "Creadores (modificado)").
 *
 * Migración de perfiles 6.3.0–6.11.0: academia→academia,
 * institucional→institucion, corporativo→creadores (con
 * atora_profile_modified=true porque el set de módulos no coincide
 * exactamente). Ver maybe_migrate_legacy_profile().
 *
 * @package ATORA_LMS
 * @since   6.3.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CLMS_Install_Profiles {

	const OPTION          = 'atora_install_profile';
	const MODIFIED_OPTION = 'atora_profile_modified';
	const MIGRATED_OPTION = 'atora_profile_migrated_6_12_0';

	const DOCENTE_BASE = array(
		'lms', 'academic', 'gradebook', 'security', 'certificates', 'calendar', 'google',
	);

	const INSTITUCION_EXTRA = array(
		'messaging', 'analytics', 'ai', 'live-streaming', 'gamification',
	);

	const CREADORES_EXTRA = array(
		'crm', 'commerce', 'affiliates', 'email-engine', 'newsletter',
		'automation', 'webhooks', 'mcp',
	);

	/** Slugs de perfiles 6.3.0–6.11.0 → slug nuevo equivalente. */
	const LEGACY_PROFILE_MAP = array(
		'academia'      => 'academia',
		'institucional' => 'institucion',
		'corporativo'   => 'creadores',
	);

	/**
	 * @return array<string,array{label:string,description:string,modules:string[]}>
	 */
	public static function get_profiles(): array {
		$all         = array_keys( CLMS_Module_Registry::get_modules() );
		$docente     = self::DOCENTE_BASE;
		$institucion = array_values( array_unique( array_merge( $docente, self::INSTITUCION_EXTRA ) ) );
		$creadores   = array_values( array_unique( array_merge( $institucion, self::CREADORES_EXTRA ) ) );

		return array(
			'docente'     => array(
				'label'       => __( 'Docente', 'atora-lms' ),
				'description' => __( 'Un profesor publicando sus cursos en su propia web. Lo mínimo para enseñar.', 'atora-lms' ),
				'modules'     => $docente,
			),
			'institucion' => array(
				'label'       => __( 'Institución', 'atora-lms' ),
				'description' => __( 'Universidades, empresas y equipos que forman sin vender. Sin nada comercial.', 'atora-lms' ),
				'modules'     => $institucion,
			),
			'creadores'   => array(
				'label'       => __( 'Creadores', 'atora-lms' ),
				'description' => __( 'Vendes cursos: matrículas, pagos, CRM, marketing y afiliados.', 'atora-lms' ),
				'modules'     => $creadores,
			),
			'academia'    => array(
				'label'       => __( 'Academia', 'atora-lms' ),
				'description' => __( 'Todo activo. Para quien combina formación institucional y venta.', 'atora-lms' ),
				'modules'     => $all,
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
	 * módulos que queden desactivados — solo apaga su carga. Limpia el
	 * estado "modificado": aplicar un perfil es, por definición, dejar de
	 * estar personalizado.
	 *
	 * @param string $profile
	 * @return bool false si el perfil no existe.
	 */
	public static function apply( string $profile ): bool {
		$modules = self::get_profile_modules( $profile );
		if ( null === $modules ) { return false; }

		update_option( self::OPTION, $profile );
		update_option( 'atora_active_modules', $modules );
		update_option( self::MODIFIED_OPTION, false );
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

	/**
	 * @return bool true si el set de módulos activos ya no coincide con el
	 * perfil guardado por haberse tocado manualmente desde ATORA → Módulos.
	 */
	public static function is_modified(): bool {
		return (bool) get_option( self::MODIFIED_OPTION, false );
	}

	/** Marca el perfil actual como personalizado. Llamar tras un guardado manual de módulos. */
	public static function mark_modified(): void {
		update_option( self::MODIFIED_OPTION, true );
	}

	/**
	 * @param string $profile Slug de perfil.
	 * @return string Etiqueta del perfil, con sufijo "(modificado)" si aplica.
	 */
	public static function display_label( string $profile ): string {
		$label = self::get_profiles()[ $profile ]['label'] ?? $profile;
		if ( self::is_modified() ) {
			/* translators: %s: nombre del perfil */
			return sprintf( __( '%s (modificado)', 'atora-lms' ), $label );
		}
		return $label;
	}

	/**
	 * Migra instalaciones 6.3.0–6.11.0 a los cuatro perfiles nuevos.
	 * Se ejecuta una sola vez (guardada tras correr, sin importar si había
	 * o no perfil legado que traducir): academia→academia,
	 * institucional→institucion, corporativo→creadores. El mapeo de
	 * corporativo no es 1:1 con creadores (creadores incluye más módulos),
	 * así que esa migración se marca como perfil modificado para no fingir
	 * una equivalencia exacta que no existe. Sin option guardada, no hay
	 * nada que migrar — sigue todo activo, regla de cero cambio de
	 * comportamiento por defecto.
	 */
	public static function maybe_migrate_legacy_profile(): void {
		if ( get_option( self::MIGRATED_OPTION, false ) ) { return; }

		$stored = (string) get_option( self::OPTION, '' );
		if ( isset( self::LEGACY_PROFILE_MAP[ $stored ] ) && ! isset( self::get_profiles()[ $stored ] ) ) {
			$new_profile = self::LEGACY_PROFILE_MAP[ $stored ];
			$target      = self::get_profile_modules( $new_profile ) ?? array();
			$current     = class_exists( 'CLMS_Module_Registry' ) ? CLMS_Module_Registry::get_active_slugs() : $target;
			$matches     = ! array_diff( $current, $target ) && ! array_diff( $target, $current );

			update_option( self::OPTION, $new_profile );
			update_option( self::MODIFIED_OPTION, ! $matches );
			if ( class_exists( 'CLMS_Module_Registry' ) ) { CLMS_Module_Registry::flush_cache(); }
		}

		update_option( self::MIGRATED_OPTION, true );
	}
}
