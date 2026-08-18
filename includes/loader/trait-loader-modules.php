<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Loader_Modules_Trait {
	protected function load_module_group( $group, $modules ) {
		unset( $group );

		if ( empty( $modules ) || ! is_array( $modules ) ) {
			return;
		}

		foreach ( $modules as $module ) {
			$this->include_module_file( $module, false );
		}
	}

	protected function boot_module_group( $group, $modules ) {
		unset( $group );

		if ( empty( $modules ) || ! is_array( $modules ) ) {
			return;
		}

		// Módulos cuya condición opcional (ej. 'woocommerce') no se cumple se
		// omiten silenciosamente: no instalar un plugin de terceros no es un
		// fallo de arranque y no debe llenar el error_log en cada request.
		$remaining = array();
		foreach ( $modules as $module ) {
			if ( ! $this->module_condition_passes( $module ) ) {
				continue;
			}
			$remaining[] = $module;
		}

		$last_count     = count( $remaining );
		$booted_in_pass = true;

		while ( ! empty( $remaining ) && $booted_in_pass ) {
			$booted_in_pass = false;
			$next_round     = array();

			foreach ( $remaining as $module ) {
				if ( $this->instantiate_module( $module, false ) ) {
					$booted_in_pass = true;
				} else {
					$next_round[] = $module;
				}
			}

			$remaining = $next_round;

			if ( count( $remaining ) === $last_count ) {
				break;
			}

			$last_count = count( $remaining );
		}

		if ( ! empty( $remaining ) ) {
			foreach ( $remaining as $module ) {
				$class = isset( $module['class'] ) ? (string) $module['class'] : 'módulo desconocido';
				$this->log( 'No se pudo iniciar: ' . $class );
			}
		}
	}

	protected function include_module_file( $module, $strict = false ) {
		$module = is_array( $module ) ? $module : array();

		$file = isset( $module['file'] ) ? trim( (string) $module['file'] ) : '';

		if ( '' === $file ) {
			return false;
		}

		if ( ! $strict && ! $this->module_condition_passes( $module ) ) {
			return false;
		}

		$path = $this->resolve_file_path( $file );

		if ( ! $path ) {
			if ( $strict ) {
				$this->log( 'Archivo obligatorio no encontrado: ' . $file );
			}
			return false;
		}

		require_once $path;

		return true;
	}

	protected function instantiate_module( $module, $strict = false ) {
		$module = is_array( $module ) ? $module : array();

		$class = isset( $module['class'] ) ? trim( (string) $module['class'] ) : '';

		if ( '' === $class ) {
			return false;
		}

		if ( isset( $this->instances[ $class ] ) ) {
			return true;
		}

		if ( ! $strict && ! $this->module_condition_passes( $module ) ) {
			return false;
		}

		if ( ! $this->dependencies_pass( $module ) ) {
			if ( $strict ) {
				$this->log( 'Dependencias no resueltas para: ' . $class );
			}
			return false;
		}

		if ( interface_exists( $class, false ) ) {
			return true;
		}

		if ( ! class_exists( $class, false ) ) {
			if ( $strict ) {
				$this->log( 'Clase obligatoria no encontrada: ' . $class );
			}
			return false;
		}

		try {
			$reflection = new ReflectionClass( $class );

			if ( $reflection->isAbstract() || $reflection->isInterface() ) {
				return true;
			}

			if ( ! $reflection->isInstantiable() ) {
				return false;
			}

			$constructor = $reflection->getConstructor();

			if ( $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
				$this->log( 'Constructor con argumentos requeridos no soportado en: ' . $class );
				return false;
			}

			$instance = $reflection->newInstance();

			if ( is_object( $instance ) ) {
				$this->instances[ $class ] = $instance;
				return true;
			}
		} catch ( Throwable $e ) {
			$this->log(
				sprintf(
					'Error al iniciar %s: %s en %s:%d',
					$class,
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
		}

		return false;
	}

	protected function dependencies_pass( $module ) {
		$module = is_array( $module ) ? $module : array();

		$dependencies = isset( $module['dependencies'] ) && is_array( $module['dependencies'] )
			? $module['dependencies']
			: array();

		if ( empty( $dependencies ) ) {
			return true;
		}

		foreach ( $dependencies as $dependency ) {
			$dependency = is_string( $dependency ) ? trim( $dependency ) : '';

			if ( '' === $dependency ) {
				continue;
			}

			if ( interface_exists( $dependency, false ) ) {
				continue;
			}

			if ( isset( $this->instances[ $dependency ] ) ) {
				continue;
			}

			if ( class_exists( $dependency, false ) ) {
				continue;
			}

			return false;
		}

		return true;
	}

	protected function module_condition_passes( $module ) {
		$module = is_array( $module ) ? $module : array();

		$condition = isset( $module['condition'] ) ? trim( (string) $module['condition'] ) : '';

		if ( '' === $condition ) {
			return true;
		}

		// PT-2: 'module:SLUG' delega en el registro de módulos (6.3.0).
		if ( 0 === strpos( $condition, 'module:' ) ) {
			$slug = substr( $condition, 7 );
			return ! class_exists( 'CLMS_Module_Registry' ) || CLMS_Module_Registry::is_active( $slug );
		}

		switch ( $condition ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
		}

		return true;
	}

	protected function resolve_file_path( $file ) {
		$file = trim( (string) $file );

		if ( '' === $file ) {
			return false;
		}

		if ( defined( 'ATORA_LMS_DIR' ) && ATORA_LMS_DIR ) {
			$base = ATORA_LMS_DIR;
		} elseif ( defined( 'CLMS_PLUGIN_DIR' ) && CLMS_PLUGIN_DIR ) {
			$base = CLMS_PLUGIN_DIR;
		} else {
			$base = trailingslashit( dirname( __FILE__, 2 ) );
		}

		$path = trailingslashit( $base ) . ltrim( $file, '/\\' );

		return file_exists( $path ) ? $path : false;
	}

	// ── Módulo UI / Template Engine ───────────────────────────────────────────

	/**
	 * Carga e inicializa el motor de templates y secciones de ATORA.
	 * Se ejecuta después de boot_base_modules_only() para que CLMS_Helper
	 * ya esté disponible cuando los contextos de UI necesiten consultar acceso.
	 */
}
