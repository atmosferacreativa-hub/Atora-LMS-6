<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Loader_Bootstrap_Trait {
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->load_base_files_only();
		$this->boot_base_modules_only();
		$this->load_ui_module();

		foreach ( $this->module_groups as $group => $modules ) {
			$this->load_module_group( $group, $modules );
			$this->boot_module_group( $group, $modules );
		}

		$this->register_template_hooks();

		// Registrar filtros de UI de roles ATORA.
		if ( class_exists( 'CLMS_Access' ) && method_exists( 'CLMS_Access', 'register_hooks' ) ) {
			CLMS_Access::register_hooks();
		}

		// Registrar puente Gradebook ↔ SpeedGrade ↔ Rubrics.
		if ( class_exists( 'CLMS_Gradebook_Bridge_Service' ) && method_exists( 'CLMS_Gradebook_Bridge_Service', 'register_hooks' ) ) {
			CLMS_Gradebook_Bridge_Service::register_hooks();
		}

		// Registrar servicio de auditoría académica.
		if ( class_exists( 'CLMS_Gradebook_Audit_Service' ) && method_exists( 'CLMS_Gradebook_Audit_Service', 'register_hooks' ) ) {
			CLMS_Gradebook_Audit_Service::register_hooks();
		}

		$this->initialized = true;
	}

	public function load_base_files_only() {
		foreach ( $this->base_modules as $module ) {
			$this->include_module_file( $module, true );
		}
	}

	public function boot_base_modules_only() {
		foreach ( $this->base_modules as $module ) {
			$this->instantiate_module( $module, true );
		}
	}

	public function get_instance( $class ) {
		$class = is_string( $class ) ? trim( $class ) : '';

		if ( '' === $class ) {
			return null;
		}

		return isset( $this->instances[ $class ] ) ? $this->instances[ $class ] : null;
	}

	public function get_module( $class ) {
		return $this->get_instance( $class );
	}

	public function get( $class ) {
		return $this->get_instance( $class );
	}

	public function is_loaded( $class ) {
		$class = is_string( $class ) ? trim( $class ) : '';

		return ( '' !== $class && isset( $this->instances[ $class ] ) );
	}

	public function get_loaded_modules() {
		return array_keys( $this->instances );
	}

}
