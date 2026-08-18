<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Compatibilidad mínima para entornos de pruebas sin WooCommerce activo.
if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		/**
		 * Shim para entornos sin WooCommerce.
		 *
		 * @return int
		 */
		public function get_id() {
			return 0;
		}
	}
}

class CLMS_Commerce {

	/**
	 * Servicios internos.
	 *
	 * @var array
	 */
	protected $services = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->boot_services();
	}

	/**
	 * Carga archivos de la rama commerce.
	 *
	 * @return void
	 */
	protected function load_dependencies() {
		$base_path = trailingslashit( dirname( __FILE__ ) );

		$files = array(
			'class-commerce-customer.php',
			'class-commerce-enrollment.php',
			'class-commerce-notifications.php',
			'class-commerce-readiness.php',
			'class-commerce-hooks.php',
			'class-commerce-shortcodes.php',
		);

		foreach ( $files as $file ) {
			$path = $base_path . $file;

			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Instancia servicios.
	 *
	 * @return void
	 */
	protected function boot_services() {
		if ( class_exists( 'CLMS_Commerce_Customer' ) ) {
			$this->services['customer'] = new CLMS_Commerce_Customer();
		}

		if ( class_exists( 'CLMS_Commerce_Enrollment' ) ) {
			$this->services['enrollment'] = new CLMS_Commerce_Enrollment();
		}

		if ( class_exists( 'CLMS_Commerce_Notifications' ) ) {
			$this->services['notifications'] = new CLMS_Commerce_Notifications();
		}

		if ( class_exists( 'CLMS_Commerce_Readiness' ) ) {
			$this->services['readiness'] = new CLMS_Commerce_Readiness();
		}

		if ( class_exists( 'CLMS_Commerce_Hooks' ) ) {
			$this->services['hooks'] = new CLMS_Commerce_Hooks();
		}

		if ( class_exists( 'CLMS_Commerce_Shortcodes' ) ) {
			$this->services['shortcodes'] = new CLMS_Commerce_Shortcodes();
		}
	}

	/**
	 * Obtiene un servicio.
	 *
	 * @param string $service Nombre.
	 * @return object|null
	 */
	public function get( $service ) {
		$service = sanitize_key( (string) $service );

		if ( isset( $this->services[ $service ] ) ) {
			return $this->services[ $service ];
		}

		return null;
	}

	/**
	 * Devuelve todos los servicios.
	 *
	 * @return array
	 */
	public function all() {
		return $this->services;
	}

	/**
	 * Maneja un evento de orden.
	 * Sirve como punto de entrada simple desde otros m��dulos.
	 *
	 * @param int|WC_Order $order Order o ID.
	 * @return void
	 */
	public function handle_order_event( $order ) {
		$hooks = $this->get( 'hooks' );

		if ( $hooks && method_exists( $hooks, 'handle_order_event' ) ) {
			$hooks->handle_order_event( $order );
		}
	}

	/**
	 * Resuelve usuario desde una orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return int
	 */
	public function resolve_order_user_id( $order ) {
		$customer = $this->get( 'customer' );

		if ( $customer && method_exists( $customer, 'resolve_order_user_id' ) ) {
			return absint( $customer->resolve_order_user_id( $order ) );
		}

		return 0;
	}

	/**
	 * Matricula usuario desde una orden.
	 *
	 * @param int      $user_id Usuario.
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	public function enroll_user_from_order( $user_id, $order ) {
		$enrollment = $this->get( 'enrollment' );

		if ( $enrollment && method_exists( $enrollment, 'enroll_user_from_order' ) ) {
			$result = $enrollment->enroll_user_from_order( $user_id, $order );
			return is_array( $result ) ? $result : array();
		}

		return array();
	}

	/**
	 * Env��a aviso de acceso a curso.
	 *
	 * @param int      $user_id Usuario.
	 * @param int      $course_id Curso.
	 * @param WC_Order $order Orden.
	 * @param array    $args Args.
	 * @return void
	 */
	public function notify_course_access( $user_id, $course_id, $order = null, $args = array() ) {
		$notifications = $this->get( 'notifications' );

		if ( $notifications && method_exists( $notifications, 'send_course_access_notification' ) ) {
			$notifications->send_course_access_notification( $user_id, $course_id, $order, $args );
		}
	}

	/**
	 * Comprueba si un servicio existe.
	 *
	 * @param string $service Nombre.
	 * @return bool
	 */
	public function has( $service ) {
		$service = sanitize_key( (string) $service );
		return isset( $this->services[ $service ] );
	}
}
