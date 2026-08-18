<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait CLMS_Frontend_Navigation_Trait {
	public function shortcode_user_nav( $atts ) {
		$atts = shortcode_atts(
			array(
				'courses_url'  => home_url( '/cursos/' ),
				'login_url'    => '',
				'register_url' => '',
				'class'        => '',
			),
			(array) $atts,
			'clms_user_nav'
		);

		$role         = $this->get_frontend_role_context();
		$courses_url  = esc_url( $atts['courses_url'] );
		$login_url    = $atts['login_url'] ? esc_url( $atts['login_url'] ) : wp_login_url( get_permalink() );
		$register_url = $atts['register_url'] ? esc_url( $atts['register_url'] ) : wp_registration_url();
		$extra_class  = sanitize_html_class( $atts['class'] );
		$nav_class    = trim( 'clms-user-nav clms-role-' . $role . ( $extra_class ? ' ' . $extra_class : '' ) );

		$items = $this->get_frontend_nav_items( $role, $courses_url, $login_url, $register_url );

		if ( empty( $items ) ) {
			return '';
		}

		$items = apply_filters( 'clms_user_nav_items', $items, $role );

		ob_start();
		echo '<nav class="' . esc_attr( $nav_class ) . '" aria-label="' . esc_attr__( 'Navegación de usuario', 'atora-lms' ) . '">';
		echo '<ul>';
		foreach ( $items as $item ) {
			if ( empty( $item['url'] ) || empty( $item['label'] ) ) {
				continue;
			}
			$li_class = isset( $item['class'] ) ? ' class="' . esc_attr( $item['class'] ) . '"' : '';
			echo '<li' . $li_class . '>';
			echo '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</nav>';

		return ob_get_clean();
	}

	/**
	 * Builds the nav item list for a given role.
	 *
	 * @param string $role
	 * @param string $courses_url
	 * @param string $login_url
	 * @param string $register_url
	 * @return array
	 */
	protected function get_frontend_nav_items( $role, $courses_url, $login_url, $register_url ) {
		switch ( $role ) {

			case 'guest':
				return array(
					array( 'label' => __( 'Cursos', 'atora-lms' ),     'url' => $courses_url ),
					array( 'label' => __( 'Ingresar', 'atora-lms' ),   'url' => $login_url,    'class' => 'clms-nav-login' ),
					array( 'label' => __( 'Registrarse', 'atora-lms' ), 'url' => $register_url, 'class' => 'clms-nav-register' ),
				);

			case 'student':
				$s_dashboard = $this->resolve_frontend_page_url( 'dashboard' ) ?: home_url( '/' );
				$s_profile   = $this->resolve_frontend_page_url( 'profile' )   ?: home_url( '/' );
				$s_messages  = $this->resolve_frontend_page_url( 'messages' )  ?: home_url( '/' );
				return array(
					array( 'label' => __( 'Mi panel', 'atora-lms' ),      'url' => $s_dashboard ),
					array( 'label' => __( 'Mis cursos', 'atora-lms' ),    'url' => $courses_url ),
					array( 'label' => __( 'Mensajes', 'atora-lms' ),      'url' => $s_messages ),
					array( 'label' => __( 'Mi perfil', 'atora-lms' ),     'url' => $s_profile ),
					array( 'label' => __( 'Cerrar sesión', 'atora-lms' ), 'url' => wp_logout_url( $s_dashboard ), 'class' => 'clms-nav-logout' ),
				);

			case 'instructor':
				return array(
					array( 'label' => __( 'Mis cursos', 'atora-lms' ),        'url' => admin_url( 'edit.php?post_type=lm_course' ) ),
					array( 'label' => __( 'Calificaciones', 'atora-lms' ),    'url' => admin_url( 'admin.php?page=clms-gradebook' ) ),
					array( 'label' => __( 'SpeedGrade', 'atora-lms' ),        'url' => admin_url( 'admin.php?page=clms-speedgrader' ) ),
					array( 'label' => __( 'IA académica', 'atora-lms' ),      'url' => admin_url( 'admin.php?page=clms-ai-hub' ) ),
					array( 'label' => __( 'Perfil docente', 'atora-lms' ),    'url' => admin_url( 'admin.php?page=clms-instructor-profile' ) ),
					array( 'label' => __( 'Mensajes', 'atora-lms' ),          'url' => admin_url( 'admin.php?page=clms-messages' ) ),
					array( 'label' => __( 'Cerrar sesión', 'atora-lms' ),     'url' => wp_logout_url( home_url( '/' ) ), 'class' => 'clms-nav-logout' ),
				);

			case 'collaborator':
				return array(
					array( 'label' => __( 'Panel', 'atora-lms' ),         'url' => admin_url( 'admin.php?page=clms-dashboard' ) ),
					array( 'label' => __( 'Cursos', 'atora-lms' ),        'url' => admin_url( 'edit.php?post_type=lm_course' ) ),
					array( 'label' => __( 'Mensajes', 'atora-lms' ),      'url' => admin_url( 'admin.php?page=clms-messages' ) ),
					array( 'label' => __( 'Mi perfil', 'atora-lms' ),     'url' => admin_url( 'admin.php?page=clms-my-profile' ) ),
					array( 'label' => __( 'Cerrar sesión', 'atora-lms' ), 'url' => wp_logout_url( home_url( '/' ) ), 'class' => 'clms-nav-logout' ),
				);

			case 'admin':
			default:
				return array(
					array( 'label' => __( 'Panel ATORA', 'atora-lms' ),   'url' => admin_url( 'admin.php?page=clms-dashboard' ) ),
					array( 'label' => __( 'Cursos', 'atora-lms' ),        'url' => admin_url( 'edit.php?post_type=lm_course' ) ),
					array( 'label' => __( 'Analítica', 'atora-lms' ),     'url' => admin_url( 'admin.php?page=clms-analytics' ) ),
					array( 'label' => __( 'Mensajes', 'atora-lms' ),      'url' => admin_url( 'admin.php?page=clms-messages' ) ),
					array( 'label' => __( 'Cerrar sesión', 'atora-lms' ), 'url' => wp_logout_url( admin_url() ), 'class' => 'clms-nav-logout' ),
				);
		}
	}

	// -------------------------------------------------------------------------
	// [clms_if role="..."] conditional shortcode
	// -------------------------------------------------------------------------

	/**
	 * Shows enclosed content only when the current visitor matches the role.
	 *
	 * Usage:
	 *   [clms_if role="student"]...content...[/clms_if]
	 *   [clms_if role="instructor,admin"]...content...[/clms_if]
	 *
	 * Accepted roles: guest, student, collaborator, instructor, admin, logged-in, logged-out
	 *
	 * @param array  $atts
	 * @param string $content
	 * @return string
	 */
	public function shortcode_clms_if( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array( 'role' => '' ),
			(array) $atts,
			'clms_if'
		);

		if ( '' === trim( (string) $atts['role'] ) || null === $content ) {
			return '';
		}

		$allowed_roles = array_map( 'trim', explode( ',', strtolower( (string) $atts['role'] ) ) );
		$current_role  = $this->get_frontend_role_context();

		$match = false;

		foreach ( $allowed_roles as $r ) {
			if ( 'logged-in' === $r && is_user_logged_in() ) {
				$match = true;
				break;
			}
			if ( 'logged-out' === $r && ! is_user_logged_in() ) {
				$match = true;
				break;
			}
			if ( $r === $current_role ) {
				$match = true;
				break;
			}
		}

		return $match ? do_shortcode( $content ) : '';
	}

	/**
	 * Sobrescribe las asignaciones de ubicación de menú a nivel de tema-mod,
	 * de modo que temas que llaman get_nav_menu_locations() directamente
	 * (en lugar de depender de wp_nav_menu_args) también respeten la
	 * configuración de navegación de Atora.
	 *
	 * @param array $locations Mapa location_key => menu_term_id.
	 * @return array
	 */
	public function filter_nav_menu_locations_by_role( $locations ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $locations;
		}

		if ( ! class_exists( 'CLMS_Settings' ) ) {
			return $locations;
		}

		$nav_settings = CLMS_Settings::get_navigation_settings();

		if ( ! empty( $nav_settings['header_location'] ) ) {
			$role     = $this->get_frontend_role_context();
			$menu_key = 'header_menu_' . $role;
			if ( ! isset( $nav_settings[ $menu_key ] ) ) {
				$menu_key = 'header_menu_guest';
			}
			$menu_id = absint( $nav_settings[ $menu_key ] ?? 0 );
			if ( $menu_id > 0 ) {
				$locations[ $nav_settings['header_location'] ] = $menu_id;
			}
		}

		if ( ! empty( $nav_settings['footer_location'] ) ) {
			$footer_id = absint( $nav_settings['footer_menu'] ?? 0 );
			if ( $footer_id > 0 ) {
				$locations[ $nav_settings['footer_location'] ] = $footer_id;
			}
		}

		return $locations;
	}

	/**
	 * Reemplaza el menú del tema por otro configurado en Atora según el
	 * contexto del usuario.
	 *
	 * Path 1 — tema clásico: intercepta via theme_location.
	 * Path 2 — Elementor / page builders: intercepta via menu ID directo,
	 *           comparando contra el menú que WordPress tiene asignado a la
	 *           ubicación controlada (leído sin filtros para evitar recursión).
	 *
	 * @param array $args Argumentos originales de wp_nav_menu().
	 * @return array
	 */
	public function filter_nav_menu_args_by_context( $args ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $args;
		}

		if ( ! class_exists( 'CLMS_Settings' ) ) {
			return $args;
		}

		$nav_settings = CLMS_Settings::get_navigation_settings();
		$location     = isset( $args['theme_location'] ) ? sanitize_key( (string) $args['theme_location'] ) : '';

		// ------------------------------------------------------------------
		// Path 1: el tema pasa theme_location (comportamiento clásico de WP).
		// ------------------------------------------------------------------
		if ( '' !== $location ) {
			if ( ! empty( $nav_settings['header_location'] ) && $location === $nav_settings['header_location'] ) {
				$menu_id = absint( $nav_settings[ $this->menu_option_for_role() ] ?? 0 );
				if ( $menu_id > 0 ) {
					$args['menu'] = $menu_id;
				}
			} elseif ( ! empty( $nav_settings['footer_location'] ) && $location === $nav_settings['footer_location'] ) {
				$footer_id = absint( $nav_settings['footer_menu'] ?? 0 );
				if ( $footer_id > 0 ) {
					$args['menu'] = $footer_id;
				}
			}
			return $args;
		}

		// ------------------------------------------------------------------
		// Path 2: Elementor y otros builders pasan $args['menu'] directamente
		// sin theme_location. En lugar de depender de asignaciones de WP,
		// detectamos si el menú es uno de los gestionados por Atora para
		// la cabecera y lo intercambiamos al del rol actual.
		// ------------------------------------------------------------------
		$menu_arg = isset( $args['menu'] ) ? $args['menu'] : '';
		if ( empty( $menu_arg ) ) {
			return $args;
		}

		$menu_obj = wp_get_nav_menu_object( $menu_arg );
		if ( ! $menu_obj ) {
			return $args;
		}
		$incoming_id = absint( $menu_obj->term_id );

		// Cabecera: ¿el menú que llega es alguno de los configurados en Atora?
		// Nota: no requerimos header_location porque Elementor Theme Builder
		// gestiona su propia ranura — solo necesitamos que los menús por rol
		// estén configurados en Paso 2 de Ajustes → Navegación.
		$managed_ids = array_filter( array_map( 'absint', array(
			$nav_settings['header_menu_guest']        ?? 0,
			$nav_settings['header_menu_student']      ?? 0,
			$nav_settings['header_menu_instructor']   ?? 0,
			$nav_settings['header_menu_collaborator'] ?? 0,
			$nav_settings['header_menu_admin']        ?? 0,
		) ) );

		if ( ! empty( $managed_ids ) && in_array( $incoming_id, $managed_ids, true ) ) {
			$role_menu_id = absint( $nav_settings[ $this->menu_option_for_role() ] ?? 0 );
			if ( $role_menu_id > 0 ) {
				$args['menu'] = $role_menu_id;
			}
			return $args;
		}

		return $args;
	}

	/**
	 * Devuelve la clave del menú de cabecera para el rol actual.
	 *
	 * @return string
	 */
	protected function menu_option_for_role() {
		switch ( $this->get_frontend_role_context() ) {
			case 'admin':        return 'header_menu_admin';
			case 'instructor':   return 'header_menu_instructor';
			case 'collaborator': return 'header_menu_collaborator';
			case 'student':      return 'header_menu_student';
			default:             return 'header_menu_guest';
		}
	}

	// -------------------------------------------------------------------------
	// wp_nav_menu_objects filter — CSS-class-based visibility
	// -------------------------------------------------------------------------

	/**
	 * Hides WordPress theme nav menu items that carry a CLMS role class.
	 *
	 * Assign one of these CSS classes to any menu item in Appearance → Menus
	 * to control who sees it:
	 *
	 *   clms-role-guest       — only non-logged-in visitors
	 *   clms-role-logged-out  — alias for clms-role-guest
	 *   clms-role-logged-in   — any authenticated user
	 *   clms-role-student     — students only
	 *   clms-role-instructor  — instructors only
	 *   clms-role-collaborator — collaborators only
	 *   clms-role-admin       — admins only
	 *
	 * Items without any clms-role-* class are always shown.
	 *
	 * @param WP_Post[] $items  Nav menu items.
	 * @param object    $args   Nav menu args.
	 * @return WP_Post[]
	 */
	public function filter_nav_menu_by_role( $items, $args ) {
		$role       = $this->get_frontend_role_context();
		$logged_in  = is_user_logged_in();

		$role_classes = array(
			'clms-role-guest',
			'clms-role-logged-out',
			'clms-role-logged-in',
			'clms-role-student',
			'clms-role-collaborator',
			'clms-role-instructor',
			'clms-role-admin',
		);

		$visible = array();

		foreach ( $items as $item ) {
			$item_classes = is_array( $item->classes ) ? $item->classes : array();

			// Collect which role-visibility classes this item carries.
			$has_role_class = array_intersect( $role_classes, $item_classes );

			if ( empty( $has_role_class ) ) {
				// No visibility class → always show.
				$visible[] = $item;
				continue;
			}

			$show = false;

			foreach ( $has_role_class as $cls ) {
				switch ( $cls ) {
					case 'clms-role-guest':
					case 'clms-role-logged-out':
						if ( ! $logged_in ) {
							$show = true;
						}
						break;

					case 'clms-role-logged-in':
						if ( $logged_in ) {
							$show = true;
						}
						break;

					case 'clms-role-student':
						if ( 'student' === $role ) {
							$show = true;
						}
						break;

					case 'clms-role-collaborator':
						if ( 'collaborator' === $role ) {
							$show = true;
						}
						break;

					case 'clms-role-instructor':
						if ( 'instructor' === $role ) {
							$show = true;
						}
						break;

					case 'clms-role-admin':
						if ( 'admin' === $role ) {
							$show = true;
						}
						break;
				}

				if ( $show ) {
					break;
				}
			}

			if ( $show ) {
				$visible[] = $item;
			}
		}

		return $visible;
	}
}
