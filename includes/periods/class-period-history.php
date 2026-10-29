<?php
/**
 * Historial académico por período (6.41.0).
 *
 * El estudiante descarga su historial: cursos agrupados por período (el más reciente arriba; los cursos sin período,
 * primero), con nota final, estado y si Control académico corrigió la nota. Incluye los cursos de períodos cerrados
 * aunque estén ocultos: el historial es el registro de notas, no el acceso al contenido.
 *
 * - Web: botón en el panel del estudiante (`admin-post.php?action=atora_history_pdf`, con nonce).
 * - App: `GET atora-mobile/v1/me/history` (datos y enlace firmado) y `GET …/me/history/document` (PDF; exige el token
 *   del mismo usuario y la firma vigente, como el certificado).
 *
 * @package ATORA_LMS
 * @since 6.41.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATORA_Period_History {

	const ACTION = 'atora_history_pdf';
	const LINK_TTL = 900;

	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'web_download' ) );
	}

	public static function routes(): void {
		if ( ! class_exists( 'ATORA_Mobile_REST_Controller' ) ) {
			return;
		}
		register_rest_route( 'atora-mobile/v1', '/me/history', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_history' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
		) );
		register_rest_route( 'atora-mobile/v1', '/me/history/document', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'rest_document' ),
			'permission_callback' => array( 'ATORA_Mobile_REST_Controller', 'authorize' ),
		) );
	}

	// ── Datos ─────────────────────────────────────────────────────────────

	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'approved':
				return __( 'Aprobado', 'atora-lms' );
			case 'not_approved':
				return __( 'No aprobado', 'atora-lms' );
			case 'at_risk':
				return __( 'En riesgo', 'atora-lms' );
			case 'in_progress':
				return __( 'En curso', 'atora-lms' );
			case 'not_started':
				return __( 'Sin iniciar', 'atora-lms' );
		}
		return $status;
	}

	/**
	 * Historial del estudiante.
	 *
	 * @return array{student:string,generated_at:string,groups:array<int,array{period:?array,courses:array<int,array>}>}
	 */
	public static function data( int $user_id ): array {
		$user = get_userdata( $user_id );
		$out  = array(
			'student'      => $user ? (string) $user->display_name : '',
			'generated_at' => wp_date( 'Y-m-d' ),
			'groups'       => array(),
		);
		if ( ! $user ) {
			return $out;
		}

		$ids = class_exists( '\\ATORA\\LMS\\LMS_Enrollment_Service' ) ? (array) \ATORA\LMS\LMS_Enrollment_Service::get_enrolled_wp_course_ids( $user_id ) : array();
		if ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' ) ) {
			$ids = array_merge( $ids, (array) CLMS_Helper::get_user_enrolled_courses( $user_id ) );
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		// Misma regla que la lista de cursos: con institución activa, solo los de esa institución.
		$institution = 0;
		if ( class_exists( '\\ATORA\\LMS\\Tenant_Context' ) ) {
			$resolved    = \ATORA\LMS\Tenant_Context::resolve_institution_for_user( $user_id );
			$institution = is_wp_error( $resolved ) ? 0 : absint( $resolved );
		}

		$groups = array();
		foreach ( $ids as $wp_course ) {
			if ( 'lm_course' !== get_post_type( $wp_course ) ) {
				continue;
			}
			if ( $institution > 0 && class_exists( 'ATORA_Teacher_Scope' ) ) {
				$course_inst = ATORA_Teacher_Scope::course_institution( $wp_course );
				if ( $course_inst > 0 && $course_inst !== $institution ) {
					continue;
				}
			}
			$summary = CLMS_Student_Grades_Service::course_summary( $user_id, $wp_course );
			$period  = class_exists( 'ATORA_Periods_Service' ) ? ATORA_Periods_Service::period_payload( $wp_course ) : null;
			$key     = $period ? (string) $period['id'] : 'none';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'period' => $period, 'courses' => array() );
			}
			$groups[ $key ]['courses'][] = array(
				'id'           => $wp_course,
				'title'        => (string) get_the_title( $wp_course ),
				'final_grade'  => $summary['final_grade'],
				'status'       => (string) $summary['status'],
				'status_label' => self::status_label( (string) $summary['status'] ),
				'adjusted'     => ! empty( $summary['adjusted'] ),
			);
		}
		foreach ( $groups as &$group ) {
			usort( $group['courses'], static fn( $a, $b ) => strnatcasecmp( remove_accents( $a['title'] ), remove_accents( $b['title'] ) ) );
		}
		unset( $group );

		// Sin período primero; después los períodos del más reciente al más antiguo.
		$groups = array_values( $groups );
		usort( $groups, static function ( array $a, array $b ): int {
			if ( null === $a['period'] || null === $b['period'] ) {
				return null === $a['period'] ? ( null === $b['period'] ? 0 : -1 ) : 1;
			}
			$cmp = strcmp( (string) $b['period']['ends_on'], (string) $a['period']['ends_on'] );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			$cmp = strcmp( (string) $b['period']['starts_on'], (string) $a['period']['starts_on'] );
			return 0 !== $cmp ? $cmp : $b['period']['id'] <=> $a['period']['id'];
		} );
		$out['groups'] = $groups;
		return $out;
	}

	// ── PDF ───────────────────────────────────────────────────────────────

	public static function html( array $d ): string {
		$academy = get_bloginfo( 'name' );
		ob_start();
		?>
<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:"DejaVu Sans",sans-serif;font-size:11px;color:#1f2937;margin:28px}
h1{font-size:18px;margin:0 0 2px}.meta{color:#6b7280;margin:0 0 18px}
h2{font-size:13px;margin:18px 0 6px;border-bottom:1px solid #d1d5db;padding-bottom:3px}
table{width:100%;border-collapse:collapse}td,th{padding:5px 4px;text-align:left;border-bottom:1px solid #eef0f3}
th{font-size:10px;color:#6b7280;text-transform:uppercase}.n{text-align:right;width:70px}.s{width:120px}
.adj{color:#6b7280;font-size:9px}.empty{color:#6b7280}
</style></head><body>
<h1><?php echo esc_html__( 'Historial académico', 'atora-lms' ); ?></h1>
<p class="meta"><?php echo esc_html( $d['student'] ); ?> · <?php echo esc_html( $academy ); ?> · <?php echo esc_html( sprintf( /* translators: %s fecha */ __( 'Emitido el %s', 'atora-lms' ), $d['generated_at'] ) ); ?></p>
<?php if ( empty( $d['groups'] ) ) : ?>
<p class="empty"><?php echo esc_html__( 'Todavía no hay cursos en tu historial.', 'atora-lms' ); ?></p>
<?php endif; ?>
<?php foreach ( $d['groups'] as $group ) : ?>
<h2><?php
	if ( $group['period'] ) {
		$dates = trim( (string) $group['period']['starts_on'] . ' – ' . (string) $group['period']['ends_on'], ' –' );
		echo esc_html( $group['period']['name'] . ( '' !== $dates ? ' (' . $dates . ')' : '' ) );
	} else {
		echo esc_html__( 'Cursos sin período', 'atora-lms' );
	}
?></h2>
<table><thead><tr><th><?php echo esc_html__( 'Curso', 'atora-lms' ); ?></th><th class="n"><?php echo esc_html__( 'Nota final', 'atora-lms' ); ?></th><th class="s"><?php echo esc_html__( 'Estado', 'atora-lms' ); ?></th></tr></thead><tbody>
<?php foreach ( $group['courses'] as $c ) : ?>
<tr><td><?php echo esc_html( $c['title'] ); ?></td><td class="n"><?php echo esc_html( null === $c['final_grade'] ? '—' : number_format_i18n( (float) $c['final_grade'], 1 ) ); ?><?php if ( $c['adjusted'] ) : ?><br><span class="adj"><?php echo esc_html__( 'corregida', 'atora-lms' ); ?></span><?php endif; ?></td><td class="s"><?php echo esc_html( $c['status_label'] ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endforeach; ?>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	/** @return string|WP_Error Bytes del PDF. */
	public static function pdf( int $user_id ) {
		$autoload = dirname( __DIR__, 2 ) . '/lib/packages/autoload.php';
		if ( ! extension_loaded( 'mbstring' ) || ! extension_loaded( 'dom' ) || ! is_readable( $autoload ) ) {
			return new WP_Error( 'atora_history_pdf_unavailable', __( 'Este servidor no puede generar el historial en PDF.', 'atora-lms' ), array( 'status' => 503 ) );
		}
		require_once $autoload;
		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'tempDir', get_temp_dir() );
		$options->set( 'fontCache', get_temp_dir() );
		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( self::html( self::data( $user_id ) ), 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();
		$pdf = (string) $dompdf->output();
		return '' !== $pdf ? $pdf : new WP_Error( 'atora_history_pdf_failed', __( 'No se pudo generar el historial. Intenta de nuevo.', 'atora-lms' ), array( 'status' => 500 ) );
	}

	// ── Entrega ───────────────────────────────────────────────────────────

	public static function signature( int $user_id, int $expires ): string {
		return hash_hmac( 'sha256', 'history|' . $user_id . '|' . $expires, wp_salt( 'auth' ) );
	}

	public static function rest_history( WP_REST_Request $request ) {
		unset( $request );
		$user_id = get_current_user_id();
		$expires = time() + self::LINK_TTL;
		return new WP_REST_Response( self::data( $user_id ) + array(
			'document_url' => add_query_arg( array( 'expires' => $expires, 'sig' => self::signature( $user_id, $expires ) ), rest_url( 'atora-mobile/v1/me/history/document' ) ),
		), 200 );
	}

	public static function rest_document( WP_REST_Request $request ) {
		$user_id   = get_current_user_id();
		$expires   = absint( $request->get_param( 'expires' ) );
		$signature = (string) $request->get_param( 'sig' );
		if ( $expires < time() || '' === $signature || ! hash_equals( self::signature( $user_id, $expires ), $signature ) ) {
			return new WP_Error( 'atora_history_link', __( 'El enlace del historial venció o no es válido.', 'atora-lms' ), array( 'status' => 403 ) );
		}
		$pdf = self::pdf( $user_id );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $pdf ) {
			if ( ! headers_sent() ) {
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: inline; filename="historial-academico.pdf"' );
				header( 'Content-Length: ' . strlen( $pdf ) );
			}
			echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
			return true;
		} );
		return new WP_REST_Response( null, 200 );
	}

	public static function web_url(): string {
		return wp_nonce_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public static function web_download(): void {
		check_admin_referer( self::ACTION );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_die( esc_html__( 'Inicia sesión para descargar tu historial.', 'atora-lms' ), '', array( 'response' => 403 ) );
		}
		$pdf = self::pdf( $user_id );
		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), '', array( 'response' => 503 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="historial-academico.pdf"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binario PDF.
		exit;
	}
}
