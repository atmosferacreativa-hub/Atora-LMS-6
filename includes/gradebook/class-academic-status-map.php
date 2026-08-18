<?php
/**
 * Mapa completo de estados académicos de ATORA.
 * Centraliza todos los estados, etiquetas y compatibilidad con valores legacy.
 *
 * Estados soportados:
 *   draft          → Borrador (guardado sin enviar)
 *   submitted      → Enviada
 *   late           → Enviada tarde
 *   resubmitted    → Re-enviada después de revisión
 *   in_review      → En revisión por el docente
 *   needs_revision → Requiere corrección por el estudiante
 *   needs_review   → Nota publicada pero requiere segunda revisión docente
 *   returned       → Devuelta al estudiante
 *   graded         → Calificada y publicada
 *   excused        → Excusada (el docente eximió al estudiante)
 *   locked         → Nota bloqueada, no editable
 *   overridden     → Override manual del docente sobre nota anterior
 *   missing        → Sin entrega registrada
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLMS_Academic_Status_Map {

	/** Estados nuevos completos. */
	const ALL_STATUSES = array(
		'draft',
		'submitted',
		'late',
		'resubmitted',
		'in_review',
		'needs_revision',
		'needs_review',
		'returned',
		'graded',
		'excused',
		'locked',
		'overridden',
		'missing',
	);

	/** Estados que implican trabajo pendiente para el docente. */
	const PENDING_TEACHER = array(
		'submitted',
		'late',
		'resubmitted',
		'in_review',
		'needs_review',
	);

	/** Estados finales (la nota está publicada). */
	const FINAL_STATUSES = array(
		'graded',
		'excused',
		'locked',
		'overridden',
	);

	/**
	 * Mapa de compatibilidad legacy → nuevo estado.
	 * Permite que valores guardados con el sistema anterior sigan funcionando.
	 */
	const LEGACY_MAP = array(
		''             => 'missing',
		'submitted'    => 'submitted',
		'in_review'    => 'in_review',
		'graded'       => 'graded',
		'needs_revision' => 'needs_revision',
		'returned'     => 'returned',
	);

	/**
	 * Etiquetas en español para cada estado.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'draft'          => __( 'Borrador', 'atora-lms' ),
			'submitted'      => __( 'Enviada', 'atora-lms' ),
			'late'           => __( 'Enviada tarde', 'atora-lms' ),
			'resubmitted'    => __( 'Re-enviada', 'atora-lms' ),
			'in_review'      => __( 'En revisión', 'atora-lms' ),
			'needs_revision' => __( 'Requiere corrección', 'atora-lms' ),
			'needs_review'   => __( 'Requiere segunda revisión', 'atora-lms' ),
			'returned'       => __( 'Devuelta', 'atora-lms' ),
			'graded'         => __( 'Calificada', 'atora-lms' ),
			'excused'        => __( 'Excusada', 'atora-lms' ),
			'locked'         => __( 'Bloqueada', 'atora-lms' ),
			'overridden'     => __( 'Override manual', 'atora-lms' ),
			'missing'        => __( 'Sin entrega', 'atora-lms' ),
		);
	}

	/**
	 * Clases CSS para cada estado (compatibles con el sistema visual ATORA).
	 *
	 * @return array<string, string>
	 */
	public static function css_classes(): array {
		return array(
			'draft'          => 'atora-status is-pending',
			'submitted'      => 'atora-status is-submitted',
			'late'           => 'atora-status is-pending',
			'resubmitted'    => 'atora-status is-submitted',
			'in_review'      => 'atora-status is-in-review',
			'needs_revision' => 'atora-status needs-review',
			'needs_review'   => 'atora-status needs-review',
			'returned'       => 'atora-status is-returned',
			'graded'         => 'atora-status is-graded',
			'excused'        => 'atora-status is-approved',
			'locked'         => 'atora-status is-graded',
			'overridden'     => 'atora-status manual-override',
			'missing'        => 'atora-status is-missing',
		);
	}

	/**
	 * Normaliza un estado, convirtiendo valores legacy al nuevo mapa.
	 * Si el estado no existe, devuelve 'missing' (valor neutro seguro).
	 *
	 * @param string $status Estado crudo.
	 * @return string Estado normalizado.
	 */
	public static function normalize( string $status ): string {
		$status = sanitize_key( $status );

		// Ya es un estado válido.
		if ( in_array( $status, self::ALL_STATUSES, true ) ) {
			return $status;
		}

		// Mapa legacy.
		return self::LEGACY_MAP[ $status ] ?? 'missing';
	}

	/**
	 * Devuelve la etiqueta legible de un estado.
	 *
	 * @param string $status Estado.
	 * @return string Etiqueta.
	 */
	public static function label( string $status ): string {
		$labels = self::labels();
		$status = self::normalize( $status );
		return $labels[ $status ] ?? sanitize_text_field( $status );
	}

	/**
	 * Devuelve la clase CSS de un estado.
	 *
	 * @param string $status Estado.
	 * @return string Clase CSS.
	 */
	public static function css_class( string $status ): string {
		$classes = self::css_classes();
		$status  = self::normalize( $status );
		return $classes[ $status ] ?? 'atora-status';
	}

	/**
	 * Verifica si un estado implica que el docente debe actuar.
	 *
	 * @param string $status Estado.
	 * @return bool
	 */
	public static function needs_teacher_action( string $status ): bool {
		return in_array( self::normalize( $status ), self::PENDING_TEACHER, true );
	}

	/**
	 * Verifica si un estado es final (nota publicada).
	 *
	 * @param string $status Estado.
	 * @return bool
	 */
	public static function is_final( string $status ): bool {
		return in_array( self::normalize( $status ), self::FINAL_STATUSES, true );
	}

	/**
	 * Devuelve los estados que el Gradebook puede usar para filtrar.
	 * Incluye todos los estados más 'missing' para celdas sin entrega.
	 *
	 * @return array<string, string> [key => label]
	 */
	public static function filter_options(): array {
		$options = array( '' => __( 'Todos', 'atora-lms' ) );
		foreach ( self::labels() as $key => $label ) {
			$options[ $key ] = $label;
		}
		return $options;
	}
}
