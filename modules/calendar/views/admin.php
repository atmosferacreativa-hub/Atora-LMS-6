<?php
/**
 * Calendario Admin View — Eventos, Agenda y Bookings
 *
 * @package ATORA_LMS\Calendar
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! isset( $atora_calendar_can_view ) ) {
	$atora_calendar_can_view = false;
}
if ( ! isset( $atora_calendar_can_manage ) ) {
	$atora_calendar_can_manage = false;
}
if ( ! isset( $atora_calendar_can_manage_academic ) ) {
	$atora_calendar_can_manage_academic = false;
}
if ( ! isset( $atora_calendar_can_manage_commercial ) ) {
	$atora_calendar_can_manage_commercial = false;
}
if ( ! isset( $atora_calendar_can_view_academic ) ) {
	$atora_calendar_can_view_academic = false;
}
if ( ! isset( $atora_calendar_can_view_commercial ) ) {
	$atora_calendar_can_view_commercial = false;
}

if ( ! $atora_calendar_can_view ) {
	$atora_calendar_can_view = current_user_can( 'manage_options' )
		|| current_user_can( 'clms_access_admin' )
		|| current_user_can( 'clms_manage_courses' )
		|| current_user_can( 'clms_manage_lessons' )
		|| current_user_can( 'clms_view_teacher_dashboard' )
		|| current_user_can( 'clms_grade_submissions' )
		|| current_user_can( 'clms_manage_commerce' )
		|| current_user_can( 'edit_posts' );
}
if ( ! $atora_calendar_can_view_academic ) {
	$atora_calendar_can_view_academic = current_user_can( 'manage_options' )
		|| current_user_can( 'clms_access_admin' )
		|| current_user_can( 'clms_manage_courses' )
		|| current_user_can( 'clms_manage_lessons' )
		|| current_user_can( 'clms_view_teacher_dashboard' )
		|| current_user_can( 'clms_grade_submissions' )
		|| current_user_can( 'edit_posts' );
}
if ( ! $atora_calendar_can_view_commercial ) {
	$atora_calendar_can_view_commercial = current_user_can( 'manage_options' )
		|| current_user_can( 'clms_access_admin' )
		|| current_user_can( 'clms_manage_commerce' )
		|| current_user_can( 'edit_posts' );
}
if ( ! $atora_calendar_can_manage_academic ) {
	$atora_calendar_can_manage_academic = current_user_can( 'manage_options' )
		|| current_user_can( 'clms_manage_courses' )
		|| current_user_can( 'clms_manage_lessons' )
		|| current_user_can( 'clms_view_teacher_dashboard' );
}
if ( ! $atora_calendar_can_manage_commercial ) {
	$atora_calendar_can_manage_commercial = current_user_can( 'manage_options' )
		|| current_user_can( 'clms_manage_commerce' );
}
if ( ! $atora_calendar_can_manage ) {
	$atora_calendar_can_manage = $atora_calendar_can_manage_academic || $atora_calendar_can_manage_commercial;
}

if ( ! $atora_calendar_can_view ) {
	wp_die( esc_html__( 'No tienes permisos.', 'atora-lms' ) );
}

global $wpdb;
$scope_f = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : '';
if ( ! in_array( $scope_f, array( 'academic', 'commercial' ), true ) ) {
	$scope_f = '';
}
if ( 'academic' === $scope_f && ! $atora_calendar_can_view_academic ) {
	$scope_f = $atora_calendar_can_view_commercial ? 'commercial' : '';
}
if ( 'commercial' === $scope_f && ! $atora_calendar_can_view_commercial ) {
	$scope_f = $atora_calendar_can_view_academic ? 'academic' : '';
}
if ( '' === $scope_f ) {
	if ( $atora_calendar_can_view_academic && ! $atora_calendar_can_view_commercial ) {
		$scope_f = 'academic';
	} elseif ( $atora_calendar_can_view_commercial && ! $atora_calendar_can_view_academic ) {
		$scope_f = 'commercial';
	}
}

$academic_event_types   = array( 'assignment_deadline', 'exam', 'live_class', 'academy_event' );
$commercial_event_types = array( 'webinar', 'meeting' );
$viewable_event_type_keys = array();
if ( $atora_calendar_can_view_academic ) {
	$viewable_event_type_keys = array_merge( $viewable_event_type_keys, $academic_event_types );
}
if ( $atora_calendar_can_view_commercial ) {
	$viewable_event_type_keys = array_merge( $viewable_event_type_keys, $commercial_event_types );
}
$viewable_event_type_keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $viewable_event_type_keys ) ) ) );

$can_manage_event_type = static function ( string $event_type ) use ( $academic_event_types, $commercial_event_types, $atora_calendar_can_manage_academic, $atora_calendar_can_manage_commercial ): bool {
	if ( in_array( $event_type, $academic_event_types, true ) ) {
		return (bool) $atora_calendar_can_manage_academic;
	}
	if ( in_array( $event_type, $commercial_event_types, true ) ) {
		return (bool) $atora_calendar_can_manage_commercial;
	}
	return false;
};
$event_in_scope = static function ( string $event_type ) use ( $scope_f, $academic_event_types, $commercial_event_types ): bool {
	if ( 'academic' === $scope_f ) {
		return in_array( $event_type, $academic_event_types, true );
	}
	if ( 'commercial' === $scope_f ) {
		return in_array( $event_type, $commercial_event_types, true );
	}
	return true;
};

// ── Acciones POST (crear/eliminar) ──────────────────────────────────────────
if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['atora_cal_action'] ) ) {
	check_admin_referer( 'atora_calendar_admin' );

	$calendar_action = sanitize_key( wp_unslash( $_POST['atora_cal_action'] ) );
	$event_type      = sanitize_key( wp_unslash( $_POST['event_type'] ?? 'academy_event' ) );
	$redirect_args   = array( 'page' => 'atora-calendar' );
	if ( '' !== $scope_f ) {
		$redirect_args['scope'] = $scope_f;
	}

	if ( 'create' === $calendar_action && $atora_calendar_can_manage && $can_manage_event_type( $event_type ) && $event_in_scope( $event_type ) ) {
		$data = array(
			'title'            => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'event_type'       => $event_type,
			'start_datetime'   => sanitize_text_field( wp_unslash( $_POST['start_datetime'] ?? '' ) ),
			'end_datetime'     => sanitize_text_field( wp_unslash( $_POST['end_datetime'] ?? '' ) ),
			'location'         => sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) ),
			'max_participants' => absint( wp_unslash( $_POST['max_participants'] ?? 0 ) ),
			'created_by'       => get_current_user_id(),
			'created_at'       => current_time( 'mysql', true ),
			'updated_at'       => current_time( 'mysql', true ),
		);
		if ( $data['title'] && $data['start_datetime'] ) {
			$wpdb->insert( "{$wpdb->prefix}atora_calendar_events", $data );
		}
		$redirect_args['created'] = 1;
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	if ( 'create' === $calendar_action ) {
		$redirect_args['denied'] = 1;
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

// ── Eliminar evento ──────────────────────────────────────────────────────────
if ( isset( $_GET['atora_action'], $_GET['event_id'], $_GET['_wpnonce'] ) ) {
	$ev_action = sanitize_key( wp_unslash( $_GET['atora_action'] ) );
	$ev_id     = absint( wp_unslash( $_GET['event_id'] ) );
	$ev_nonce  = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
	$redirect_args = array( 'page' => 'atora-calendar' );
	if ( '' !== $scope_f ) {
		$redirect_args['scope'] = $scope_f;
	}

	if ( 'delete' === $ev_action && $ev_id && $atora_calendar_can_manage && wp_verify_nonce( $ev_nonce, 'atora_cal_delete_' . $ev_id ) ) {
		$event_type = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT event_type FROM {$wpdb->prefix}atora_calendar_events WHERE id = %d LIMIT 1",
				$ev_id
			)
		);

		$event_type = sanitize_key( $event_type );
		if ( $event_type && $can_manage_event_type( $event_type ) && $event_in_scope( $event_type ) ) {
			$wpdb->delete( "{$wpdb->prefix}atora_calendar_events", array( 'id' => $ev_id ), array( '%d' ) );
			$redirect_args['deleted'] = 1;
		} else {
			$redirect_args['denied'] = 1;
		}
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

// ── Parámetros de filtrado ───────────────────────────────────────────────────
$view_mode  = isset( $_GET['cal_view'] ) ? sanitize_key( wp_unslash( $_GET['cal_view'] ) ) : 'upcoming';
$type_f     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
$month_f    = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
$show_form  = isset( $_GET['add'] );

$has_table = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}atora_calendar_events'" ); // phpcs:ignore

$event_types = array(
	'assignment_deadline' => '📝 ' . __( 'Entrega de tarea', 'atora-lms' ),
	'exam'                => '📋 ' . __( 'Examen', 'atora-lms' ),
	'live_class'          => '🎥 ' . __( 'Clase en vivo', 'atora-lms' ),
	'webinar'             => '📡 ' . __( 'Webinar', 'atora-lms' ),
	'meeting'             => '🤝 ' . __( 'Reunión 1:1', 'atora-lms' ),
	'academy_event'       => '📌 ' . __( 'Evento general', 'atora-lms' ),
);

$event_colors = array(
	'assignment_deadline' => array( 'bg' => '#fef3c7', 'color' => '#b45309' ),
	'exam'                => array( 'bg' => '#fee2e2', 'color' => '#dc2626' ),
	'live_class'          => array( 'bg' => '#dbeafe', 'color' => '#1d4ed8' ),
	'webinar'             => array( 'bg' => '#ede9fe', 'color' => '#7c3aed' ),
	'meeting'             => array( 'bg' => '#dcfce7', 'color' => '#16a34a' ),
	'academy_event'       => array( 'bg' => '#f3f4f6', 'color' => '#374151' ),
);

$creatable_event_type_keys = array();
if ( $atora_calendar_can_manage_academic ) {
	$creatable_event_type_keys = array_merge( $creatable_event_type_keys, $academic_event_types );
}
if ( $atora_calendar_can_manage_commercial ) {
	$creatable_event_type_keys = array_merge( $creatable_event_type_keys, $commercial_event_types );
}
$creatable_event_type_keys = array_values( array_unique( array_filter( array_map( 'sanitize_key', $creatable_event_type_keys ) ) ) );

$visible_event_types = array_intersect_key( $event_types, array_flip( $viewable_event_type_keys ) );
if ( 'academic' === $scope_f ) {
	$visible_event_types = array_intersect_key( $visible_event_types, array_flip( $academic_event_types ) );
} elseif ( 'commercial' === $scope_f ) {
	$visible_event_types = array_intersect_key( $visible_event_types, array_flip( $commercial_event_types ) );
}

$create_event_types = array_intersect_key( $event_types, array_flip( $creatable_event_type_keys ) );
$create_event_types = array_intersect_key( $create_event_types, array_flip( $viewable_event_type_keys ) );
if ( 'academic' === $scope_f ) {
	$create_event_types = array_intersect_key( $create_event_types, array_flip( $academic_event_types ) );
} elseif ( 'commercial' === $scope_f ) {
	$create_event_types = array_intersect_key( $create_event_types, array_flip( $commercial_event_types ) );
}
$has_creatable_event_types = ! empty( $create_event_types );
if ( $type_f && ! isset( $visible_event_types[ $type_f ] ) ) {
	$type_f = '';
}

// ── Cargar eventos ───────────────────────────────────────────────────────────
$events = array();

if ( $has_table ) {
	$where  = '1=1';
	$params = array();
	$scope_event_types = $viewable_event_type_keys;

	if ( 'academic' === $scope_f ) {
		$scope_event_types = array_values( array_intersect( $scope_event_types, $academic_event_types ) );
	} elseif ( 'commercial' === $scope_f ) {
		$scope_event_types = array_values( array_intersect( $scope_event_types, $commercial_event_types ) );
	}

	if ( empty( $scope_event_types ) ) {
		$where .= ' AND 1=0';
	} else {
		$scope_values = "'" . implode( "','", array_map( 'esc_sql', $scope_event_types ) ) . "'";
		$where       .= " AND event_type IN ({$scope_values})";
	}

	if ( $type_f ) {
		$where   .= ' AND event_type = %s';
		$params[] = $type_f;
	}

	if ( 'upcoming' === $view_mode ) {
		$where   .= ' AND start_datetime >= %s';
		$params[] = current_time( 'mysql', true );
	} elseif ( 'past' === $view_mode ) {
		$where   .= ' AND start_datetime < %s';
		$params[] = current_time( 'mysql', true );
	} elseif ( 'month' === $view_mode ) {
		$month_start = $month_f . '-01 00:00:00';
		$month_end   = gmdate( 'Y-m-t 23:59:59', strtotime( $month_start ) );
		$where      .= ' AND start_datetime >= %s AND start_datetime <= %s';
		$params[]    = $month_start;
		$params[]    = $month_end;
	}

	$sql    = "SELECT * FROM {$wpdb->prefix}atora_calendar_events WHERE {$where} ORDER BY start_datetime ASC LIMIT 100";
	$events = $params
		? (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) // phpcs:ignore
		: (array) $wpdb->get_results( $sql ); // phpcs:ignore
}

// Agrupar por día para la vista de agenda
$by_day = array();
foreach ( $events as $ev ) {
	$day = substr( $ev->start_datetime, 0, 10 );
	$by_day[ $day ][] = $ev;
}

$base_args = array( 'page' => 'atora-calendar' );
if ( '' !== $scope_f ) {
	$base_args['scope'] = $scope_f;
}
$base_url = add_query_arg( $base_args, admin_url( 'admin.php' ) );
$add_url  = add_query_arg( 'add', '1', $base_url );
$calendar_heading = __( 'Calendario', 'atora-lms' );
$calendar_copy    = __( 'Gestiona eventos, sesiones y agenda académica.', 'atora-lms' );
if ( 'academic' === $scope_f ) {
	$calendar_heading = __( 'Calendario académico', 'atora-lms' );
	$calendar_copy    = __( 'Coordina clases, entregas y evaluación académica.', 'atora-lms' );
} elseif ( 'commercial' === $scope_f ) {
	$calendar_heading = __( 'Calendario comercial', 'atora-lms' );
	$calendar_copy    = __( 'Organiza reuniones, webinars y seguimiento comercial.', 'atora-lms' );
}
?>
<div class="wrap atora-cal-wrap">

<style>
.atora-cal-wrap { max-width: 1100px; padding-bottom: 40px; }
.atora-cal-topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.atora-cal-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.atora-cal-toolbar select, .atora-cal-toolbar input[type="month"] {
	padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;
}
.atora-cal-views { display: flex; gap: 4px; }
.atora-cal-view-btn {
	padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 8px;
	font-size: 13px; font-weight: 600; text-decoration: none; color: #374151;
	background: #fff; transition: all .15s;
}
.atora-cal-view-btn:hover { border-color: #1d4ed8; color: #1d4ed8; }
.atora-cal-view-btn.is-active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; }
.atora-cal-add-btn {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 10px 18px; background: #1d4ed8; color: #fff;
	border: none; border-radius: 10px; font-size: 14px; font-weight: 700;
	text-decoration: none; cursor: pointer; transition: background .15s;
}
.atora-cal-add-btn:hover { background: #1e40af; color: #fff; }

/* Form */
.atora-cal-form-card {
	background: #fff; border: 1px solid #e5e7eb; border-radius: 16px;
	padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.atora-cal-form-card h2 { margin: 0 0 16px; font-size: 18px; color: #111827; }
.atora-cal-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.atora-cal-form-grid .full { grid-column: 1/-1; }
.atora-cal-form-grid label { display: grid; gap: 4px; font-size: 13px; font-weight: 600; color: #374151; }
.atora-cal-form-grid input, .atora-cal-form-grid select, .atora-cal-form-grid textarea {
	padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-family: inherit;
}
.atora-cal-form-grid textarea { resize: vertical; min-height: 80px; }
.atora-cal-form-actions { display: flex; gap: 10px; margin-top: 18px; align-items: center; }
.atora-cal-btn-primary {
	padding: 10px 20px; background: #1d4ed8; color: #fff; border: none;
	border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer;
}
.atora-cal-btn-cancel {
	padding: 10px 16px; border: 1px solid #d1d5db; border-radius: 9px;
	font-size: 14px; font-weight: 600; background: #fff; color: #374151; text-decoration: none;
}

/* Agenda */
.atora-cal-agenda { display: grid; gap: 20px; }
.atora-cal-day-group { display: grid; gap: 0; }
.atora-cal-day-label {
	display: flex; align-items: center; gap: 10px; padding: 8px 0;
	font-size: 13px; font-weight: 700; text-transform: uppercase;
	letter-spacing: .07em; color: #6b7280; margin-bottom: 8px;
}
.atora-cal-day-label .atora-cal-day-dot {
	width: 8px; height: 8px; border-radius: 50%; background: #1d4ed8; flex-shrink: 0;
}
.atora-cal-day-label.is-today { color: #1d4ed8; }
.atora-cal-day-label.is-today .atora-cal-day-dot { background: #1d4ed8; }
.atora-cal-event-list { display: grid; gap: 8px; }
.atora-cal-event {
	display: grid; grid-template-columns: auto 1fr auto;
	align-items: center; gap: 14px;
	background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
	padding: 14px 16px; transition: box-shadow .15s;
}
.atora-cal-event:hover { box-shadow: 0 4px 14px rgba(0,0,0,.07); }
.atora-cal-event-time {
	text-align: center; min-width: 50px;
	font-size: 12px; font-weight: 700; color: #6b7280; line-height: 1.3;
}
.atora-cal-event-body { min-width: 0; }
.atora-cal-event-title {
	margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #111827;
	overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.atora-cal-event-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.atora-cal-event-badge {
	display: inline-flex; align-items: center; padding: 2px 9px;
	border-radius: 99px; font-size: 11px; font-weight: 700;
}
.atora-cal-event-loc { font-size: 12px; color: #6b7280; }
.atora-cal-event-actions { display: flex; gap: 6px; align-items: center; flex-shrink: 0; }
.atora-cal-ev-btn {
	display: inline-flex; align-items: center; padding: 5px 10px;
	border-radius: 7px; font-size: 12px; font-weight: 600; text-decoration: none;
	cursor: pointer; border: none; transition: all .15s;
}
.atora-cal-ev-btn--del { background: #fee2e2; color: #dc2626; }
.atora-cal-ev-btn--del:hover { background: #fecaca; color: #dc2626; }
.atora-cal-empty { padding: 48px 24px; text-align: center; color: #9ca3af; font-size: 15px; }
.atora-cal-notice { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; font-weight: 600; }
.atora-cal-notice--success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
@media (max-width: 640px) {
	.atora-cal-form-grid { grid-template-columns: 1fr; }
	.atora-cal-event { grid-template-columns: 1fr; }
	.atora-cal-event-time { text-align: left; }
	.atora-cal-topbar { flex-direction: column; align-items: stretch; }
}
</style>

<div class="atora-cal-topbar">
	<div>
		<h1 style="margin:0;font-size:24px;font-weight:800;color:#111827;">
			📅 <?php echo esc_html( $calendar_heading ); ?>
		</h1>
		<p style="margin:4px 0 0;font-size:14px;color:#6b7280;">
			<?php echo esc_html( $calendar_copy ); ?>
		</p>
	</div>
		<?php if ( $has_creatable_event_types ) : ?>
			<a class="atora-cal-add-btn" href="<?php echo esc_url( $add_url ); ?>">
				+ <?php esc_html_e( 'Nuevo evento', 'atora-lms' ); ?>
			</a>
		<?php endif; ?>
</div>

<?php if ( ! empty( $_GET['created'] ) ) : ?>
<div class="atora-cal-notice atora-cal-notice--success">✓ <?php esc_html_e( 'Evento creado correctamente.', 'atora-lms' ); ?></div>
<?php endif; ?>
<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
<div class="atora-cal-notice atora-cal-notice--success">✓ <?php esc_html_e( 'Evento eliminado.', 'atora-lms' ); ?></div>
<?php endif; ?>
<?php if ( ! empty( $_GET['denied'] ) ) : ?>
<div class="atora-cal-notice" style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;">
	<?php esc_html_e( 'No tienes permisos para gestionar ese tipo de evento.', 'atora-lms' ); ?>
</div>
<?php endif; ?>

<!-- Formulario de nuevo evento -->
<?php if ( $show_form && $has_creatable_event_types ) : ?>
<div class="atora-cal-form-card">
	<h2>➕ <?php esc_html_e( 'Crear nuevo evento', 'atora-lms' ); ?></h2>
	<form method="post" action="<?php echo esc_url( $base_url ); ?>">
		<?php wp_nonce_field( 'atora_calendar_admin' ); ?>
		<input type="hidden" name="atora_cal_action" value="create">
		<div class="atora-cal-form-grid">
			<label class="full">
				<?php esc_html_e( 'Título del evento *', 'atora-lms' ); ?>
				<input type="text" name="title" required placeholder="<?php esc_attr_e( 'Ej: Clase de Python — Módulo 3', 'atora-lms' ); ?>">
			</label>
				<label>
					<?php esc_html_e( 'Tipo de evento', 'atora-lms' ); ?>
					<select name="event_type">
						<?php foreach ( $create_event_types as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<label>
				<?php esc_html_e( 'Ubicación / Link', 'atora-lms' ); ?>
				<input type="text" name="location" placeholder="<?php esc_attr_e( 'Zoom link o aula', 'atora-lms' ); ?>">
			</label>
			<label>
				<?php esc_html_e( 'Inicio *', 'atora-lms' ); ?>
				<input type="datetime-local" name="start_datetime" required>
			</label>
			<label>
				<?php esc_html_e( 'Fin', 'atora-lms' ); ?>
				<input type="datetime-local" name="end_datetime">
			</label>
			<label>
				<?php esc_html_e( 'Máx. participantes (0 = ilimitado)', 'atora-lms' ); ?>
				<input type="number" name="max_participants" value="0" min="0">
			</label>
			<label class="full">
				<?php esc_html_e( 'Descripción', 'atora-lms' ); ?>
				<textarea name="description" placeholder="<?php esc_attr_e( 'Descripción opcional del evento…', 'atora-lms' ); ?>"></textarea>
			</label>
		</div>
		<div class="atora-cal-form-actions">
			<button type="submit" class="atora-cal-btn-primary"><?php esc_html_e( 'Crear evento', 'atora-lms' ); ?></button>
			<a class="atora-cal-btn-cancel" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Cancelar', 'atora-lms' ); ?></a>
		</div>
	</form>
</div>
<?php endif; ?>
<?php if ( $show_form && ! $has_creatable_event_types ) : ?>
<div class="atora-cal-notice atora-cal-notice--success" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;">
	<?php esc_html_e( 'Puedes visualizar el calendario, pero no crear ni eliminar eventos con tu rol actual.', 'atora-lms' ); ?>
</div>
<?php endif; ?>

<!-- Filtros + vistas -->
<form method="get" action="<?php echo esc_url( $base_url ); ?>">
	<input type="hidden" name="page" value="atora-calendar">
	<div class="atora-cal-toolbar" style="margin-bottom:20px;">
		<div class="atora-cal-views">
			<?php
			$views = array(
				'upcoming' => __( '📅 Próximos', 'atora-lms' ),
				'past'     => __( '📆 Pasados', 'atora-lms' ),
				'month'    => __( '🗓 Por mes', 'atora-lms' ),
				'all'      => __( '☰ Todos', 'atora-lms' ),
			);
			foreach ( $views as $vk => $vl ) :
				$v_url = add_query_arg( array( 'cal_view' => $vk, 'type' => $type_f ), $base_url );
			?>
			<a class="atora-cal-view-btn <?php echo $view_mode === $vk ? 'is-active' : ''; ?>"
			   href="<?php echo esc_url( $v_url ); ?>"><?php echo esc_html( $vl ); ?></a>
			<?php endforeach; ?>
		</div>
		<select name="type" onchange="this.form.submit()">
			<option value=""><?php esc_html_e( 'Todos los tipos', 'atora-lms' ); ?></option>
			<?php foreach ( $visible_event_types as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type_f, $key ); ?>>
				<?php echo esc_html( $label ); ?>
			</option>
			<?php endforeach; ?>
		</select>
		<?php if ( 'month' === $view_mode ) : ?>
		<input type="month" name="month" value="<?php echo esc_attr( $month_f ); ?>"
		       onchange="this.form.submit()" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;">
		<?php endif; ?>
		<input type="hidden" name="cal_view" value="<?php echo esc_attr( $view_mode ); ?>">
	</div>
</form>

<!-- Lista de eventos -->
<?php if ( ! $has_table ) : ?>
<p class="atora-cal-empty">
	<?php esc_html_e( 'La tabla de eventos no existe aún. Activa el módulo Calendario desde la configuración.', 'atora-lms' ); ?>
</p>
<?php elseif ( empty( $by_day ) ) : ?>
<p class="atora-cal-empty">
	<?php esc_html_e( 'No hay eventos en este período.', 'atora-lms' ); ?>
	<?php if ( $has_creatable_event_types ) : ?>
		<br><a href="<?php echo esc_url( $add_url ); ?>" style="color:#1d4ed8;font-weight:700;">+ <?php esc_html_e( 'Crear el primero', 'atora-lms' ); ?></a>
	<?php endif; ?>
</p>
<?php else : ?>
<div class="atora-cal-agenda">
	<?php
	$today_str = wp_date( 'Y-m-d' );
	foreach ( $by_day as $day => $day_events ) :
		$is_today  = ( $day === $today_str );
		$day_label = date_i18n( 'l, d \d\e F Y', strtotime( $day ) );
	?>
	<div class="atora-cal-day-group">
		<div class="atora-cal-day-label <?php echo $is_today ? 'is-today' : ''; ?>">
			<span class="atora-cal-day-dot"></span>
			<?php echo esc_html( $is_today ? __( 'Hoy', 'atora-lms' ) . ' — ' . $day_label : $day_label ); ?>
			<span style="background:#f3f4f6;border-radius:99px;padding:1px 8px;font-size:11px;font-weight:700;color:#6b7280;">
				<?php echo esc_html( count( $day_events ) ); ?>
			</span>
		</div>
		<div class="atora-cal-event-list">
				<?php foreach ( $day_events as $ev ) :
					$ec       = $event_colors[ $ev->event_type ?? 'academy_event' ] ?? array( 'bg' => '#f3f4f6', 'color' => '#374151' );
					$et_label = $event_types[ $ev->event_type ?? 'academy_event' ] ?? '📌 ' . $ev->event_type;
					$time_str = date_i18n( 'H:i', strtotime( $ev->start_datetime ) );
					$time_end = $ev->end_datetime ? date_i18n( 'H:i', strtotime( $ev->end_datetime ) ) : '';
					$del_url  = '';
					if ( $can_manage_event_type( sanitize_key( (string) $ev->event_type ) ) ) {
						$del_nonce = wp_create_nonce( 'atora_cal_delete_' . $ev->id );
						$delete_args = array(
							'page'         => 'atora-calendar',
							'atora_action' => 'delete',
							'event_id'     => $ev->id,
							'_wpnonce'     => $del_nonce,
						);
						if ( '' !== $scope_f ) {
							$delete_args['scope'] = $scope_f;
						}
						$del_url = add_query_arg( $delete_args, admin_url( 'admin.php' ) );
					}
				?>
			<div class="atora-cal-event">
				<div class="atora-cal-event-time">
					<?php echo esc_html( $time_str ); ?>
					<?php if ( $time_end ) : ?>
					<br><span style="color:#d1d5db;">│</span><br><?php echo esc_html( $time_end ); ?>
					<?php endif; ?>
				</div>
				<div class="atora-cal-event-body">
					<p class="atora-cal-event-title" title="<?php echo esc_attr( $ev->title ); ?>">
						<?php echo esc_html( $ev->title ); ?>
					</p>
					<div class="atora-cal-event-meta">
						<span class="atora-cal-event-badge"
						      style="background:<?php echo esc_attr( $ec['bg'] ); ?>;color:<?php echo esc_attr( $ec['color'] ); ?>">
							<?php echo esc_html( $et_label ); ?>
						</span>
						<?php if ( $ev->location ) : ?>
						<span class="atora-cal-event-loc">📍 <?php echo esc_html( $ev->location ); ?></span>
						<?php endif; ?>
						<?php if ( $ev->max_participants ) : ?>
						<span class="atora-cal-event-loc">👥 <?php echo esc_html( (string) $ev->max_participants ); ?></span>
						<?php endif; ?>
					</div>
				</div>
					<div class="atora-cal-event-actions">
						<?php if ( $del_url ) : ?>
							<a class="atora-cal-ev-btn atora-cal-ev-btn--del"
							   href="<?php echo esc_url( $del_url ); ?>"
							   onclick="return confirm('<?php esc_attr_e( '¿Eliminar este evento?', 'atora-lms' ); ?>')">
								🗑
							</a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
