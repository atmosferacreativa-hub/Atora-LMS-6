<?php
/**
 * IA (6.32.1): filtro de datos personales, aplicado por `CLMS_AI_Manager` a
 * todo lo que sale hacia el proveedor.
 *
 * - Correos: siempre se reemplazan por "[correo]".
 * - Nombres: los de las personas de la petición (`subject_users` de quien
 *   llama, y el usuario actual si no es docente) se reemplazan por un marcador
 *   ("{{nombre}}", "{{nombre_2}}"…). Al recibir la respuesta, el marcador se
 *   cambia por el nombre de pila: el nombre nunca viaja al proveedor.
 *
 * Es una red de seguridad: cada módulo ya arma sus prompts sin nombre, correo
 * ni identificadores, y usa "{{nombre}}" cuando el texto debe dirigirse al
 * estudiante.
 *
 * @package ATORA_LMS
 * @since 6.32.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_AI_Privacy {

	const PLACEHOLDER = '{{nombre}}';
	const EMAIL       = '[correo]';

	/**
	 * Plan de reemplazos para una petición.
	 *
	 * @param int[] $user_ids Personas de la petición (la primera recibe "{{nombre}}").
	 * @return array{mask: array<string,string>, restore: array<string,string>}
	 */
	public static function plan( array $user_ids ): array {
		$mask    = array();
		$restore = array();
		$n       = 0;
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) ) as $user_id ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			++$n;
			$placeholder = 1 === $n ? self::PLACEHOLDER : '{{nombre_' . $n . '}}';
			$first       = trim( (string) $user->first_name );
			$last        = trim( (string) $user->last_name );
			$candidates  = array( (string) $user->display_name, trim( $first . ' ' . $last ), $first, $last );
			if ( false === strpos( (string) $user->user_login, '@' ) ) {
				$candidates[] = (string) $user->user_login;
			}
			foreach ( $candidates as $name ) {
				$name = trim( $name );
				// Nombres de una o dos letras no se buscan: romperían palabras comunes.
				if ( mb_strlen( $name ) >= 3 && ! isset( $mask[ $name ] ) ) {
					$mask[ $name ] = $placeholder;
				}
			}
			$given                   = '' !== $first ? $first : strtok( trim( (string) $user->display_name ), ' ' );
			$restore[ $placeholder ] = $given ? (string) $given : __( 'estudiante', 'atora-lms' );
		}
		// Primero los nombres largos ("Ana Pérez" antes que "Ana").
		uksort( $mask, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		return array( 'mask' => $mask, 'restore' => $restore );
	}

	/** Personas de la petición: las que declara quien llama y el usuario actual si no es docente. */
	public static function subjects( array $options ): array {
		$ids     = array_map( 'absint', (array) ( $options['subject_users'] ?? array() ) );
		$current = get_current_user_id();
		if ( $current > 0 && ! ( class_exists( 'ATORA_Teacher_Scope' ) && ATORA_Teacher_Scope::has_teacher_role( $current ) ) && ! user_can( $current, 'manage_options' ) ) {
			$ids[] = $current;
		}
		return $ids;
	}

	public static function scrub( string $text, array $plan ): string {
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', self::EMAIL, $text );
		foreach ( $plan['mask'] as $name => $placeholder ) {
			// Distingue mayúsculas: un apellido como "Mar" no debe tocar "el mar".
			$text = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/u', $placeholder, $text );
		}
		return $text;
	}

	/** Recorre mensajes, cuerpos y listas: cada texto pasa por el filtro. */
	public static function scrub_deep( $value, array $plan ) {
		if ( is_string( $value ) ) {
			return self::scrub( $value, $plan );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::scrub_deep( $item, $plan );
			}
		}
		return $value;
	}

	/** Al recibir la respuesta: el marcador vuelve a ser el nombre de pila. */
	public static function restore_deep( $value, array $plan ) {
		if ( is_string( $value ) ) {
			return $plan['restore'] ? strtr( $value, $plan['restore'] ) : $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::restore_deep( $item, $plan );
			}
		}
		return $value;
	}
}
