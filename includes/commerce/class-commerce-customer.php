<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Commerce_Customer {

	/**
	 * Meta en orden para guardar el usuario resuelto por CLMS.
	 *
	 * @var string
	 */
	const ORDER_USER_META = '_clms_resolved_user_id';

	/**
	 * Resuelve el usuario asociado a una orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return int
	 */
	public function resolve_order_user_id( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return 0;
		}

		// 1) Usuario ya ligado directamente a la orden.
		$user_id = absint( $order->get_user_id() );
		if ( $user_id ) {
			$this->store_resolved_user_id( $order, $user_id );
			return $user_id;
		}

		// 2) Usuario ya resuelto antes por CLMS.
		$saved_user_id = absint( $order->get_meta( self::ORDER_USER_META, true ) );
		if ( $saved_user_id && get_user_by( 'id', $saved_user_id ) ) {
			return $saved_user_id;
		}

		// 3) Buscar por email de facturación.
		$email = sanitize_email( (string) $order->get_billing_email() );
		if ( $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user && ! empty( $user->ID ) ) {
				$user_id = absint( $user->ID );
				$this->store_resolved_user_id( $order, $user_id );
				return $user_id;
			}
		}

		// 4) Fallback por metas WooCommerce comunes.
		$user_id = $this->resolve_user_id_from_order_meta( $order );
		if ( $user_id ) {
			$this->store_resolved_user_id( $order, $user_id );
			return $user_id;
		}

		// 5) Compra como invitado: crear cuenta automáticamente desde los datos
		//    de facturación para poder otorgar acceso inmediato al curso.
		$user_id = $this->maybe_create_user_from_order( $order );
		if ( $user_id ) {
			$this->store_resolved_user_id( $order, $user_id );
			return $user_id;
		}

		return 0;
	}

	/**
	 * Crea una cuenta de WordPress a partir de los datos de facturación de una
	 * orden cuando no existe ningún usuario asociado (checkout como invitado).
	 *
	 * @param WC_Order $order Orden.
	 * @return int ID del usuario creado, o 0 si no fue posible.
	 */
	protected function maybe_create_user_from_order( $order ) {
		$email = sanitize_email( (string) $order->get_billing_email() );

		if ( ! $email || ! is_email( $email ) ) {
			return 0;
		}

		// Re-comprueba por si la cuenta se creó entre el paso 3 y este.
		$existing = get_user_by( 'email', $email );
		if ( $existing && ! empty( $existing->ID ) ) {
			return absint( $existing->ID );
		}

		$first_name = sanitize_text_field( (string) $order->get_billing_first_name() );
		$last_name  = sanitize_text_field( (string) $order->get_billing_last_name() );

		$base_login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base_login ) {
			$base_login = 'estudiante';
		}

		$login = $base_login;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base_login . $i;
			$i++;
		}

		$password = wp_generate_password( 12, false );
		$user_id  = wp_create_user( $login, $password, $email );

		if ( is_wp_error( $user_id ) ) {
			return 0;
		}

		$user_id = absint( $user_id );

		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'display_name' => trim( $first_name . ' ' . $last_name ),
			)
		);

		$user = get_userdata( $user_id );
		if ( $user ) {
			$role = get_role( 'lms_student' ) ? 'lms_student' : 'subscriber';
			$user->set_role( $role );
		}

		// Vincula la cuenta nueva al pedido para que aparezca en "Mis pedidos".
		if ( method_exists( $order, 'set_customer_id' ) ) {
			$order->set_customer_id( $user_id );
			$order->save();
		}

		// Marca el pedido para que el email de acceso incluya el enlace de
		// configuración de contraseña (la cuenta se creó sin que el comprador
		// definiera una).
		update_post_meta( $order->get_id(), '_clms_account_autocreated', 1 );

		do_action( 'clms_commerce_customer_autocreated', $user_id, $order );

		return $user_id;
	}

	/**
	 * Determina si la orden tiene un usuario resoluble.
	 *
	 * @param WC_Order $order Orden.
	 * @return bool
	 */
	public function order_has_resolvable_user( $order ) {
		return (bool) $this->resolve_order_user_id( $order );
	}

	/**
	 * Obtiene datos básicos del cliente desde la orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return array
	 */
	public function get_order_customer_data( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return array();
		}

		$user_id    = $this->resolve_order_user_id( $order );
		$email      = sanitize_email( (string) $order->get_billing_email() );
		$first_name = sanitize_text_field( (string) $order->get_billing_first_name() );
		$last_name  = sanitize_text_field( (string) $order->get_billing_last_name() );

		$data = array(
			'user_id'      => $user_id,
			'email'        => $email,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
			'display_name' => trim( $first_name . ' ' . $last_name ),
		);

		if ( $user_id ) {
			$user = get_user_by( 'id', $user_id );

			if ( $user ) {
				$data['user_login'] = $user->user_login;

				if ( '' === $data['display_name'] ) {
					$data['display_name'] = $user->display_name ? $user->display_name : $user->user_login;
				}

				if ( empty( $data['email'] ) ) {
					$data['email'] = sanitize_email( $user->user_email );
				}
			}
		}

		return $data;
	}

	/**
	 * Guarda el user_id resuelto en la orden.
	 *
	 * @param WC_Order $order Orden.
	 * @param int      $user_id Usuario.
	 * @return void
	 */
	protected function store_resolved_user_id( $order, $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order->update_meta_data( self::ORDER_USER_META, $user_id );
		$order->save_meta_data();
	}

	/**
	 * Intenta resolver usuario por metadatos de la orden.
	 *
	 * @param WC_Order $order Orden.
	 * @return int
	 */
	protected function resolve_user_id_from_order_meta( $order ) {
		$candidate_keys = array(
			'_customer_user',
			'_clms_resolved_user_id',
			'_created_customer_id',
		);

		foreach ( $candidate_keys as $key ) {
			$value = absint( $order->get_meta( $key, true ) );

			if ( $value && get_user_by( 'id', $value ) ) {
				return $value;
			}
		}

		return 0;
	}
}