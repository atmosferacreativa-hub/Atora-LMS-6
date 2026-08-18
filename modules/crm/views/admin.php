<?php
/**
 * CRM Admin View — Contactos, correos y bandeja unificada.
 *
 * @package ATORA_LMS\CRM
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user_id = get_current_user_id();
$crm_class       = '\\ATORA\\CRM\\CRM';
$is_admin_user   = current_user_can( 'manage_options' ) || ( function_exists( 'is_super_admin' ) && is_super_admin() );
$crm_can_access  = $is_admin_user || (
	class_exists( $crm_class ) && method_exists( $crm_class, 'can_access_crm' )
		? (bool) $crm_class::can_access_crm( $current_user_id )
		: false
);
$crm_can_manage  = $is_admin_user || (
	class_exists( $crm_class ) && method_exists( $crm_class, 'can_manage_crm' )
		? (bool) $crm_class::can_manage_crm( $current_user_id )
		: false
);
$crm_has_global_scope = $crm_can_manage || (
	class_exists( $crm_class ) && method_exists( $crm_class, 'has_global_contact_scope' )
		? (bool) $crm_class::has_global_contact_scope( $current_user_id )
		: false
);
$scope_user_ids = ( ! $crm_has_global_scope && class_exists( $crm_class ) && method_exists( $crm_class, 'get_accessible_contact_user_ids' ) )
	? array_values( array_filter( array_map( 'absint', (array) $crm_class::get_accessible_contact_user_ids( $current_user_id ) ) ) )
	: array();

if ( ! $crm_can_access ) {
	wp_die( esc_html__( 'No tienes permisos para acceder al CRM.', 'atora-lms' ) );
}

$role_context = $crm_can_manage
	? 'admin'
	: ( ( current_user_can( 'clms_view_teacher_dashboard' ) || current_user_can( 'clms_grade_submissions' ) ) ? 'teacher' : 'operator' );
$role_labels  = array(
	'admin'    => __( 'Administrador', 'atora-lms' ),
	'teacher'  => __( 'Docente', 'atora-lms' ),
	'operator' => __( 'Operador', 'atora-lms' ),
);
$role_copy    = array(
	'admin'    => __( 'Vista ejecutiva: docentes, estudiantes y clientes activos/potenciales.', 'atora-lms' ),
	'teacher'  => __( 'Vista docente: solo estudiantes inscritos en tus cursos y su historial de comunicación.', 'atora-lms' ),
	'operator' => __( 'Vista operativa: seguimiento de contactos y comunicación interna.', 'atora-lms' ),
);

$role_label = $role_labels[ $role_context ] ?? __( 'Usuario', 'atora-lms' );
$role_desc  = $role_copy[ $role_context ] ?? '';

$scope_size = count( $scope_user_ids );

global $wpdb;

$table_exists = static function ( string $table_name ) use ( $wpdb ): bool {
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$apply_scope_filter = static function ( string $where, array &$params, string $column, bool $has_global_scope, array $visible_user_ids ): string {
	if ( $has_global_scope ) {
		return $where;
	}

	if ( empty( $visible_user_ids ) ) {
		return $where . ' AND 1=0';
	}

	$in_clause = implode( ',', array_fill( 0, count( $visible_user_ids ), '%d' ) );
	$where    .= " AND {$column} IN ({$in_clause})";
	foreach ( $visible_user_ids as $visible_user_id ) {
		$params[] = absint( $visible_user_id );
	}

	return $where;
};

$base_url      = admin_url( 'admin.php?page=atora-crm' );
$allowed_tabs  = array( 'inbox', 'contacts', 'emails' );
$default_tab   = $crm_can_manage ? 'contacts' : 'inbox';
$current_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $default_tab;
$current_tab   = in_array( $current_tab, $allowed_tabs, true ) ? $current_tab : $default_tab;
$search        = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$status_f      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$channel_f     = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
$tag_f         = isset( $_GET['tag'] ) ? sanitize_text_field( wp_unslash( $_GET['tag'] ) ) : '';
$course_f      = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
$email_identity_raw = isset( $_GET['email_identity'] ) ? sanitize_key( wp_unslash( $_GET['email_identity'] ) ) : '';
$email_identity_map = array(
	'academia'      => 'academia',
	'academy'       => 'academia',
	'academic'      => 'academia',
	'teacher'       => 'teacher',
	'comercio'      => 'teacher',
	'commercial'    => 'teacher',
	'docencia'      => 'teacher',
	'tienda'        => 'teacher',
	'store'         => 'teacher',
	'sales'         => 'teacher',
	'venta'         => 'teacher',
	'admin'         => 'admin',
	'administration'=> 'admin',
	'administracion'=> 'admin',
);
$email_identity_f = $email_identity_map[ $email_identity_raw ] ?? '';
$per_page      = 20;
$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
$offset        = ( $paged - 1 ) * $per_page;

// Acciones destructivas: solo admin.
$action  = isset( $_GET['atora_action'] ) ? sanitize_key( wp_unslash( $_GET['atora_action'] ) ) : '';
$item_id = isset( $_GET['item_id'] ) ? absint( wp_unslash( $_GET['item_id'] ) ) : 0;
$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

if ( $crm_can_manage && $action && $item_id && $nonce ) {
	if ( 'delete_contact' === $action && wp_verify_nonce( $nonce, 'atora_crm_delete_contact_' . $item_id ) ) {
		$wpdb->delete( "{$wpdb->prefix}atora_contacts", array( 'id' => $item_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}atora_contact_notes", array( 'contact_id' => $item_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}atora_contact_activities", array( 'contact_id' => $item_id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}atora_contact_tags", array( 'contact_id' => $item_id ), array( '%d' ) );
		wp_safe_redirect( add_query_arg( array( 'tab' => 'contacts', 'deleted' => 1 ), $base_url ) );
		exit;
	}

	if ( 'delete_email' === $action && wp_verify_nonce( $nonce, 'atora_crm_delete_email_' . $item_id ) ) {
		$wpdb->delete( "{$wpdb->prefix}atora_email_queue", array( 'id' => $item_id ), array( '%d' ) );
		wp_safe_redirect( add_query_arg( array( 'tab' => 'emails', 'deleted' => 1 ), $base_url ) );
		exit;
	}

	if ( 'mark_email_opened' === $action && wp_verify_nonce( $nonce, 'atora_crm_mark_email_opened_' . $item_id ) ) {
		$updated = class_exists( $crm_class ) && method_exists( $crm_class, 'mark_email_opened' )
			? (bool) $crm_class::mark_email_opened( $item_id )
			: false;
		wp_safe_redirect( add_query_arg( array( 'tab' => $current_tab, 'updated' => $updated ? 1 : 0 ), $base_url ) );
		exit;
	}

	if ( 'mark_message_read' === $action && wp_verify_nonce( $nonce, 'atora_crm_mark_message_read_' . $item_id ) ) {
		$updated = class_exists( $crm_class ) && method_exists( $crm_class, 'mark_message_read' )
			? (bool) $crm_class::mark_message_read( $item_id )
			: false;
		wp_safe_redirect( add_query_arg( array( 'tab' => $current_tab, 'updated' => $updated ? 1 : 0 ), $base_url ) );
		exit;
	}
}

$has_contacts_table = $table_exists( "{$wpdb->prefix}atora_contacts" );
$has_email_table    = $table_exists( "{$wpdb->prefix}atora_email_queue" );
$has_message_table  = $table_exists( "{$wpdb->prefix}atora_message_queue" );
$has_contact_tags_table = $table_exists( "{$wpdb->prefix}atora_contact_tags" );
$email_queue_has_identity_key_column = false;
if ( $has_email_table ) {
	$column_name = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$wpdb->prefix}atora_email_queue LIKE %s", 'identity_key' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$email_queue_has_identity_key_column = ( 'identity_key' === $column_name );
}

$status_labels = array(
	'lead'    => array( 'label' => 'Lead', 'color' => '#1d4ed8', 'bg' => '#dbeafe' ),
	'prospect'=> array( 'label' => 'Prospecto', 'color' => '#7c3aed', 'bg' => '#ede9fe' ),
	'student' => array( 'label' => 'Estudiante', 'color' => '#16a34a', 'bg' => '#dcfce7' ),
	'alumni'  => array( 'label' => 'Alumni', 'color' => '#0f766e', 'bg' => '#ccfbf1' ),
	'blocked' => array( 'label' => 'Bloqueado', 'color' => '#dc2626', 'bg' => '#fee2e2' ),
);

$delivery_status_labels = array(
	'pending'   => array( 'label' => 'Pendiente', 'color' => '#b45309', 'bg' => '#fef3c7' ),
	'sending'   => array( 'label' => 'En envío', 'color' => '#1d4ed8', 'bg' => '#dbeafe' ),
	'sent'      => array( 'label' => 'Enviado', 'color' => '#16a34a', 'bg' => '#dcfce7' ),
	'delivered' => array( 'label' => 'Entregado', 'color' => '#0f766e', 'bg' => '#ccfbf1' ),
	'read'      => array( 'label' => 'Leído', 'color' => '#4338ca', 'bg' => '#e0e7ff' ),
	'failed'    => array( 'label' => 'Error', 'color' => '#dc2626', 'bg' => '#fee2e2' ),
);

$channel_labels = array(
	'email'    => __( 'Email', 'atora-lms' ),
	'whatsapp' => __( 'WhatsApp', 'atora-lms' ),
	'telegram' => __( 'Telegram', 'atora-lms' ),
	'sms'      => __( 'SMS', 'atora-lms' ),
);
$email_identity_labels = array(
	'academia' => __( 'Academia (admin operativo)', 'atora-lms' ),
	'teacher'  => __( 'Docencia (estudiantes inscritos)', 'atora-lms' ),
	'admin'    => __( 'Comercial (leads y prospectos)', 'atora-lms' ),
);
$course_options = array();
if ( post_type_exists( 'lm_course' ) ) {
	$course_query_args = array(
		'post_type'              => 'lm_course',
		'post_status'            => array( 'publish', 'private', 'draft' ),
		'posts_per_page'         => 300,
		'fields'                 => 'ids',
		'orderby'                => 'title',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	);
	if ( ! $crm_can_manage ) {
		$course_query_args['author'] = $current_user_id;
	}

	$course_ids = array_values( array_filter( array_map( 'absint', (array) get_posts( $course_query_args ) ) ) );
	foreach ( $course_ids as $course_id ) {
		$course_title = sanitize_text_field( (string) get_the_title( $course_id ) );
		if ( '' === $course_title ) {
			$course_title = sprintf( __( 'Curso #%d', 'atora-lms' ), $course_id );
		}
		$course_options[ $course_id ] = $course_title;
	}
}
if ( $course_f > 0 && 'lm_course' === get_post_type( $course_f ) && ! isset( $course_options[ $course_f ] ) ) {
	$course_title = sanitize_text_field( (string) get_the_title( $course_f ) );
	if ( '' === $course_title ) {
		$course_title = sprintf( __( 'Curso #%d', 'atora-lms' ), $course_f );
	}
	$course_options[ $course_f ] = $course_title;
}
$tag_options = array();
if ( $has_contacts_table && $has_contact_tags_table ) {
	$tag_sql = "SELECT DISTINCT ct.tag_name FROM {$wpdb->prefix}atora_contact_tags ct INNER JOIN {$wpdb->prefix}atora_contacts c ON c.id = ct.contact_id";
	if ( ! $crm_can_manage ) {
		if ( empty( $scope_user_ids ) ) {
			$tag_rows = array();
		} else {
			$in_clause = implode( ',', array_fill( 0, count( $scope_user_ids ), '%d' ) );
			$tag_sql  .= " WHERE c.user_id IN ({$in_clause})";
			$tag_sql  .= ' ORDER BY ct.tag_name ASC LIMIT 300';
			$tag_rows  = (array) $wpdb->get_col( $wpdb->prepare( $tag_sql, ...$scope_user_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	} else {
		$tag_sql .= ' ORDER BY ct.tag_name ASC LIMIT 300';
		$tag_rows = (array) $wpdb->get_col( $tag_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	$tag_options = array_values(
		array_unique(
			array_filter(
				array_map(
					static function ( $tag_value ): string {
						return sanitize_text_field( (string) $tag_value );
					},
					$tag_rows
				),
				static function ( string $tag_value ): bool {
					return '' !== $tag_value;
				}
			)
		)
	);
}
if ( '' !== $tag_f && ! in_array( $tag_f, $tag_options, true ) ) {
	$tag_options[] = $tag_f;
}
$tag_user_ids = array();
if ( '' !== $tag_f ) {
	$tag_user_ids = class_exists( $crm_class ) && method_exists( $crm_class, 'get_contact_user_ids_by_tag' )
		? array_values( array_filter( array_map( 'absint', (array) $crm_class::get_contact_user_ids_by_tag( $tag_f, $current_user_id ) ) ) )
		: array();
}
$course_user_ids = array();
if ( $course_f > 0 ) {
	$course_user_ids = class_exists( $crm_class ) && method_exists( $crm_class, 'get_contact_user_ids_by_course' )
		? array_values( array_filter( array_map( 'absint', (array) $crm_class::get_contact_user_ids_by_course( $course_f, $current_user_id ) ) ) )
		: array();
}

$apply_tag_user_filter = static function ( string $where, array &$params, string $column, string $tag_filter, array $tag_user_ids ): string {
	if ( '' === $tag_filter ) {
		return $where;
	}
	if ( empty( $tag_user_ids ) ) {
		return $where . ' AND 1=0';
	}

	$in_clause = implode( ',', array_fill( 0, count( $tag_user_ids ), '%d' ) );
	$where    .= " AND {$column} IN ({$in_clause})";
	foreach ( $tag_user_ids as $tag_user_id ) {
		$params[] = absint( $tag_user_id );
	}

	return $where;
};
$apply_course_user_filter = static function ( string $where, array &$params, string $column, int $course_filter, array $course_user_ids ): string {
	if ( $course_filter <= 0 ) {
		return $where;
	}
	if ( empty( $course_user_ids ) ) {
		return $where . ' AND 1=0';
	}

	$in_clause = implode( ',', array_fill( 0, count( $course_user_ids ), '%d' ) );
	$where    .= " AND {$column} IN ({$in_clause})";
	foreach ( $course_user_ids as $course_user_id ) {
		$params[] = absint( $course_user_id );
	}

	return $where;
};

$export_datasets = array(
	'contacts'    => __( 'Exportar contactos', 'atora-lms' ),
	'email_queue' => __( 'Exportar cola de correos', 'atora-lms' ),
	'message_log' => __( 'Exportar log de mensajes', 'atora-lms' ),
);
$export_nonce = wp_create_nonce( 'atora_crm_export' );
$export_url   = static function ( string $dataset ) use ( $export_nonce ): string {
	return add_query_arg(
		array(
			'action'   => 'atora_crm_export',
			'dataset'  => sanitize_key( $dataset ),
			'_wpnonce' => $export_nonce,
		),
		admin_url( 'admin-ajax.php' )
	);
};

$contacts_scope_total = 0;
$leads_scope_total    = 0;
$students_scope_total = 0;
$contacts_total       = 0;
$contacts             = array();

if ( $has_contacts_table ) {
	$build_contacts_count = static function ( string $extra_where, array $extra_params ) use ( $wpdb, $apply_scope_filter, $apply_tag_user_filter, $apply_course_user_filter, $crm_has_global_scope, $scope_user_ids, $tag_f, $tag_user_ids, $course_f, $course_user_ids ): int {
		$where  = '1=1';
		$params = array();
		$where  = $apply_scope_filter( $where, $params, 'user_id', $crm_has_global_scope, $scope_user_ids );
		$where  = $apply_tag_user_filter( $where, $params, 'user_id', $tag_f, $tag_user_ids );
		$where  = $apply_course_user_filter( $where, $params, 'user_id', $course_f, $course_user_ids );
		if ( '' !== $extra_where ) {
			$where .= ' ' . $extra_where;
			$params = array_merge( $params, $extra_params );
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE {$where}";
		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	};

	$contacts_scope_total = $build_contacts_count( '', array() );
	$leads_scope_total    = $build_contacts_count( 'AND status = %s', array( 'lead' ) );
	$students_scope_total = $build_contacts_count( 'AND status = %s', array( 'student' ) );

	$where  = '1=1';
	$params = array();
	$where  = $apply_scope_filter( $where, $params, 'user_id', $crm_has_global_scope, $scope_user_ids );
	$where  = $apply_course_user_filter( $where, $params, 'user_id', $course_f, $course_user_ids );

	if ( $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where  .= ' AND (name LIKE %s OR email LIKE %s)';
		$params[] = $like;
		$params[] = $like;
	}
	if ( $status_f ) {
		$where   .= ' AND status = %s';
		$params[] = $status_f;
	}
	$where = $apply_tag_user_filter( $where, $params, 'user_id', $tag_f, $tag_user_ids );

	$count_sql      = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_contacts WHERE {$where}";
	$contacts_total = ! empty( $params )
		? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

	$list_params = array_merge( $params, array( $per_page, $offset ) );
	$list_sql    = "SELECT * FROM {$wpdb->prefix}atora_contacts WHERE {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
	$contacts    = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$apply_email_identity_filter = static function ( string $where, array &$params, string $column, string $email_identity ) use ( $wpdb, $email_queue_has_identity_key_column ): string {
	if ( '' === $email_identity ) {
		return $where;
	}

	$identity_variants_map = array(
		'academia' => array( 'academia', 'academy', 'academic' ),
		'teacher'  => array( 'teacher', 'comercio', 'commercial', 'docencia', 'tienda', 'store', 'sales', 'venta' ),
		'admin'    => array( 'admin', 'administracion', 'administration' ),
	);
	$variants = $identity_variants_map[ $email_identity ] ?? array( $email_identity );
	$variants = array_values( array_unique( array_filter( array_map( 'sanitize_key', $variants ) ) ) );
	if ( empty( $variants ) ) {
		return $where;
	}

	$clauses = array();
	if ( $email_queue_has_identity_key_column ) {
		$identity_placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
		$clauses[] = "eq.identity_key IN ({$identity_placeholders})";
		foreach ( $variants as $variant ) {
			$params[] = $variant;
		}
	}

	$json_fields = array( '$.identity_key', '$.email_identity', '$.identity' );
	foreach ( $json_fields as $json_field ) {
		$json_placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
		$clauses[] = "JSON_UNQUOTE(JSON_EXTRACT({$column}, '{$json_field}')) IN ({$json_placeholders})";
		foreach ( $variants as $variant ) {
			$params[] = $variant;
		}
	}

	foreach ( $variants as $variant ) {
		$params[] = '%"email_identity":"' . $wpdb->esc_like( $variant ) . '"%';
		$params[] = '%"identity_key":"' . $wpdb->esc_like( $variant ) . '"%';
		$params[] = '%"identity":"' . $wpdb->esc_like( $variant ) . '"%';
		$clauses[] = "{$column} LIKE %s";
		$clauses[] = "{$column} LIKE %s";
		$clauses[] = "{$column} LIKE %s";
	}

	$where .= ' AND (' . implode( ' OR ', $clauses ) . ')';

	return $where;
};

$emails_scope_total  = 0;
$pending_email_total = 0;
$sent_email_total    = 0;
$failed_email_total  = 0;
$opened_email_total  = 0;
$emails_total        = 0;
$emails              = array();

if ( $has_email_table ) {
	$build_emails_count = static function ( string $extra_where, array $extra_params ) use ( $wpdb, $apply_scope_filter, $crm_has_global_scope, $scope_user_ids ): int {
		$where  = '1=1';
		$params = array();
		$where  = $apply_scope_filter( $where, $params, 'user_id', $crm_has_global_scope, $scope_user_ids );
		if ( '' !== $extra_where ) {
			$where .= ' ' . $extra_where;
			$params = array_merge( $params, $extra_params );
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_queue WHERE {$where}";
		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	};

	$emails_scope_total  = $build_emails_count( '', array() );
	$pending_email_total = $build_emails_count( 'AND status = %s', array( 'pending' ) );
	$sent_email_total    = $build_emails_count( 'AND status = %s', array( 'sent' ) );
	$failed_email_total  = $build_emails_count( 'AND status = %s', array( 'failed' ) );
	$opened_email_total  = $build_emails_count( 'AND opened_at IS NOT NULL', array() );

	$email_where  = '1=1';
	$email_params = array();
	$email_where  = $apply_scope_filter( $email_where, $email_params, 'eq.user_id', $crm_has_global_scope, $scope_user_ids );
	$email_where  = $apply_course_user_filter( $email_where, $email_params, 'eq.user_id', $course_f, $course_user_ids );

	if ( $search ) {
		$like          = '%' . $wpdb->esc_like( $search ) . '%';
		$email_where  .= ' AND (eq.subject LIKE %s OR u.user_email LIKE %s)';
		$email_params[] = $like;
		$email_params[] = $like;
	}
		if ( $status_f ) {
			$email_where  .= ' AND eq.status = %s';
			$email_params[] = $status_f;
		}
		$email_where = $apply_tag_user_filter( $email_where, $email_params, 'eq.user_id', $tag_f, $tag_user_ids );
		$email_where = $apply_email_identity_filter( $email_where, $email_params, 'eq.metadata', $email_identity_f );

		$count_sql    = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_email_queue eq LEFT JOIN {$wpdb->users} u ON u.ID = eq.user_id WHERE {$email_where}";
		$emails_total = ! empty( $email_params )
		? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$email_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

	$list_sql     = "SELECT eq.*, u.user_email FROM {$wpdb->prefix}atora_email_queue eq LEFT JOIN {$wpdb->users} u ON u.ID = eq.user_id WHERE {$email_where} ORDER BY eq.created_at DESC LIMIT %d OFFSET %d";
	$list_params  = array_merge( $email_params, array( $per_page, $offset ) );
	$emails       = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$message_scope_total = 0;
if ( $has_message_table ) {
	$message_where  = '1=1';
	$message_params = array();
	$message_where  = $apply_scope_filter( $message_where, $message_params, 'user_id', $crm_has_global_scope, $scope_user_ids );
	$message_count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}atora_message_queue WHERE {$message_where}";
	$message_scope_total = ! empty( $message_params )
		? (int) $wpdb->get_var( $wpdb->prepare( $message_count_sql, ...$message_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		: (int) $wpdb->get_var( $message_count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

$inbox_total = 0;
$inbox_rows  = array();
if ( 'inbox' === $current_tab ) {
	$inbox_items = array();

	if ( $has_email_table && ( '' === $channel_f || 'email' === $channel_f ) ) {
		$email_where  = '1=1';
		$email_params = array();
		$email_where  = $apply_scope_filter( $email_where, $email_params, 'eq.user_id', $crm_has_global_scope, $scope_user_ids );
		$email_where  = $apply_course_user_filter( $email_where, $email_params, 'eq.user_id', $course_f, $course_user_ids );

		if ( $search ) {
			$like           = '%' . $wpdb->esc_like( $search ) . '%';
			$email_where   .= ' AND (eq.subject LIKE %s OR u.user_email LIKE %s)';
			$email_params[] = $like;
			$email_params[] = $like;
		}
			if ( $status_f ) {
				$email_where   .= ' AND eq.status = %s';
				$email_params[] = $status_f;
			}
			$email_where = $apply_tag_user_filter( $email_where, $email_params, 'eq.user_id', $tag_f, $tag_user_ids );
			$email_where = $apply_email_identity_filter( $email_where, $email_params, 'eq.metadata', $email_identity_f );

			$email_sql    = "SELECT eq.id, eq.user_id, eq.subject, eq.status, eq.created_at, eq.sent_at, eq.opened_at, eq.identity_key, eq.metadata, u.user_email FROM {$wpdb->prefix}atora_email_queue eq LEFT JOIN {$wpdb->users} u ON u.ID = eq.user_id WHERE {$email_where} ORDER BY eq.created_at DESC LIMIT 260";
		$email_rows   = ! empty( $email_params )
			? (array) $wpdb->get_results( $wpdb->prepare( $email_sql, ...$email_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $email_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $email_rows as $row ) {
			$email_metadata = is_string( $row->metadata ?? '' ) ? json_decode( (string) $row->metadata, true ) : array();
			$email_metadata = is_array( $email_metadata ) ? $email_metadata : array();
			$email_identity = sanitize_key( (string) ( $row->identity_key ?? $email_metadata['identity_key'] ?? $email_metadata['email_identity'] ?? '' ) );
			$email_identity = $email_identity_map[ $email_identity ] ?? $email_identity;
			$identity_label = $email_identity_labels[ $email_identity ] ?? ( '' !== $email_identity ? ucfirst( $email_identity ) : __( 'Sin identidad', 'atora-lms' ) );

			$inbox_items[] = array(
				'id'         => 'email-' . absint( $row->id ),
				'item_id'    => absint( $row->id ),
				'channel'    => 'email',
				'status'     => sanitize_key( (string) $row->status ),
				'title'      => sanitize_text_field( (string) ( $row->subject ?: __( 'Sin asunto', 'atora-lms' ) ) ),
				'recipient'  => sanitize_text_field( (string) ( $row->user_email ?: __( 'Sin usuario', 'atora-lms' ) ) ),
				'created_at' => sanitize_text_field( (string) $row->created_at ),
				'opened_at'  => sanitize_text_field( (string) $row->opened_at ),
				'identity_label' => sanitize_text_field( (string) $identity_label ),
				'source'     => 'email_queue',
			);
		}
	}

	if ( $has_message_table && ( '' === $channel_f || 'email' !== $channel_f ) ) {
		$message_where  = '1=1';
		$message_params = array();
		$message_where  = $apply_scope_filter( $message_where, $message_params, 'mq.user_id', $crm_has_global_scope, $scope_user_ids );
		$message_where  = $apply_course_user_filter( $message_where, $message_params, 'mq.user_id', $course_f, $course_user_ids );

		if ( $search ) {
			$like             = '%' . $wpdb->esc_like( $search ) . '%';
			$message_where   .= ' AND (mq.template_key LIKE %s OR mq.recipient_name LIKE %s OR mq.recipient_phone LIKE %s)';
			$message_params[] = $like;
			$message_params[] = $like;
			$message_params[] = $like;
		}
		if ( $status_f ) {
			$message_where   .= ' AND mq.status = %s';
			$message_params[] = $status_f;
		}
		if ( $channel_f ) {
			$message_where   .= ' AND mq.channel = %s';
			$message_params[] = $channel_f;
		}
		$message_where = $apply_tag_user_filter( $message_where, $message_params, 'mq.user_id', $tag_f, $tag_user_ids );

		$message_sql = "SELECT mq.id, mq.user_id, mq.channel, mq.template_key, mq.status, mq.recipient_name, mq.recipient_phone, mq.scheduled_at, mq.sent_at FROM {$wpdb->prefix}atora_message_queue mq WHERE {$message_where} ORDER BY COALESCE(mq.sent_at, mq.scheduled_at) DESC LIMIT 260";
		$message_rows = ! empty( $message_params )
			? (array) $wpdb->get_results( $wpdb->prepare( $message_sql, ...$message_params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (array) $wpdb->get_results( $message_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $message_rows as $row ) {
			$created_at = ! empty( $row->sent_at ) ? (string) $row->sent_at : (string) $row->scheduled_at;
			$recipient  = trim( (string) $row->recipient_name );
			if ( '' === $recipient ) {
				$recipient = (string) $row->recipient_phone;
			}
			if ( '' === $recipient ) {
				$recipient = __( 'Sin destinatario', 'atora-lms' );
			}

			$inbox_items[] = array(
				'id'         => sanitize_key( (string) $row->channel ) . '-' . absint( $row->id ),
				'item_id'    => absint( $row->id ),
				'channel'    => sanitize_key( (string) $row->channel ),
				'status'     => sanitize_key( (string) $row->status ),
				'title'      => sanitize_text_field( (string) ( $row->template_key ?: __( 'Mensaje directo', 'atora-lms' ) ) ),
				'recipient'  => sanitize_text_field( $recipient ),
				'created_at' => sanitize_text_field( $created_at ),
				'source'     => 'message_queue',
			);
		}
	}

	usort(
		$inbox_items,
		static function ( $a, $b ): int {
			$at = ! empty( $a['created_at'] ) ? strtotime( (string) $a['created_at'] ) : 0;
			$bt = ! empty( $b['created_at'] ) ? strtotime( (string) $b['created_at'] ) : 0;
			return $bt <=> $at;
		}
	);

	$inbox_total = count( $inbox_items );
	$inbox_rows  = array_slice( $inbox_items, $offset, $per_page );
}

$academy_settings = (array) get_option( 'clms_academy_settings', array() );
$email_engine_settings = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_email_engine_settings' )
	? (array) CLMS_Settings::get_email_engine_settings()
	: (array) get_option( 'atora_email_engine_options', array() );
$whatsapp_settings = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_whatsapp_settings' )
	? (array) CLMS_Settings::get_whatsapp_settings()
	: (array) get_option( 'atora_whatsapp_options', array() );
$telegram_settings = class_exists( 'CLMS_Settings' ) && method_exists( 'CLMS_Settings', 'get_telegram_settings' )
	? (array) CLMS_Settings::get_telegram_settings()
	: (array) get_option( 'atora_telegram_options', array() );

$channel_state_cards = array(
	array(
		'label' => __( 'Email', 'atora-lms' ),
		'ready' => ! empty( $email_engine_settings['from_email'] ) && ! empty( $email_engine_settings['provider'] ),
		'note'  => ! empty( $email_engine_settings['provider'] )
			? sprintf( __( 'Provider: %s', 'atora-lms' ), strtoupper( sanitize_text_field( (string) $email_engine_settings['provider'] ) ) )
			: __( 'Sin provider activo', 'atora-lms' ),
	),
	array(
		'label' => __( 'WhatsApp', 'atora-lms' ),
		'ready' => ! empty( $whatsapp_settings['access_token'] ) && ! empty( $whatsapp_settings['phone_number_id'] ),
		'note'  => ! empty( $whatsapp_settings['phone_number_id'] )
			? sprintf( __( 'Phone ID: %s', 'atora-lms' ), sanitize_text_field( (string) $whatsapp_settings['phone_number_id'] ) )
			: __( 'Sin Phone Number ID', 'atora-lms' ),
	),
	array(
		'label' => __( 'Telegram', 'atora-lms' ),
		'ready' => ! empty( $telegram_settings['bot_token'] ) && ! empty( $telegram_settings['webhook_secret'] ),
		'note'  => ! empty( $telegram_settings['bot_username'] )
			? '@' . sanitize_text_field( (string) $telegram_settings['bot_username'] )
			: __( 'Bot sin username', 'atora-lms' ),
	),
);

$email_identity_cards = array(
	array(
		'label' => __( 'ACADEMIA / ADMIN', 'atora-lms' ),
		'email' => sanitize_email( (string) ( $email_engine_settings['identity_academia_from_email'] ?? $academy_settings['contact_email'] ?? '' ) ),
		'note'  => __( 'Operación de plataforma, WooCommerce, afiliados, seguridad y notificaciones institucionales.', 'atora-lms' ),
	),
	array(
		'label' => __( 'DOCENCIA', 'atora-lms' ),
		'email' => sanitize_email( (string) ( $email_engine_settings['identity_teacher_from_email'] ?? $academy_settings['teacher_contact_email'] ?? '' ) ),
		'note'  => __( 'Seguimiento académico, bienvenida, orientación y recordatorios para estudiantes activos.', 'atora-lms' ),
	),
	array(
		'label' => __( 'COMERCIAL', 'atora-lms' ),
		'email' => sanitize_email( (string) ( $email_engine_settings['identity_admin_from_email'] ?? $academy_settings['admin_contact_email'] ?? get_option( 'admin_email' ) ) ),
		'note'  => __( 'Captación y nurturing de leads/prospectos, campañas y cierre comercial.', 'atora-lms' ),
	),
);

$tab_count_map = array(
	'inbox'    => absint( $emails_scope_total + $message_scope_total ),
	'contacts' => absint( $contacts_scope_total ),
	'emails'   => absint( $emails_scope_total ),
);

$message_log_summary_query = array();
if ( in_array( $current_tab, array( 'inbox', 'emails' ), true ) ) {
	if ( '' !== $channel_f ) {
		$message_log_summary_query['channel'] = sanitize_key( $channel_f );
	}
	if ( '' !== $status_f ) {
		$message_log_summary_query['status'] = sanitize_key( $status_f );
	}
	if ( '' !== $search ) {
		$message_log_summary_query['s'] = sanitize_text_field( $search );
	}
	if ( $course_f > 0 ) {
		$message_log_summary_query['course_id'] = absint( $course_f );
	}
}

$message_log_summary_bootstrap = class_exists( $crm_class ) && method_exists( $crm_class, 'get_message_log_summary_bootstrap' )
	? (array) $crm_class::get_message_log_summary_bootstrap(
		array(
			'query' => $message_log_summary_query,
		)
	)
	: array(
		'endpoint' => esc_url_raw( rest_url( 'atora/v1/crm/messages/log/summary' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'labels'   => array(
			'channel'    => array_map( 'sanitize_text_field', $channel_labels ),
			'status'     => array(),
			'event_type' => array(),
		),
		'empty'    => array(
			'channel'    => __( 'Sin actividad de canales.', 'atora-lms' ),
			'status'     => __( 'Sin estados registrados.', 'atora-lms' ),
			'event_type' => __( 'Sin eventos de trazabilidad.', 'atora-lms' ),
		),
		'error'    => __( 'No fue posible cargar la trazabilidad de mensajes.', 'atora-lms' ),
	);

$tab_url = static function ( string $tab ) use ( $base_url, $search, $status_f, $channel_f, $tag_f, $course_f, $email_identity_f ): string {
	return add_query_arg(
		array_filter(
			array(
				'tab'     => $tab,
				's'       => $search,
				'status'  => $status_f,
				'channel' => $channel_f,
				'tag'     => $tag_f,
				'course_id' => $course_f,
				'email_identity' => $email_identity_f,
			),
			static fn( $value ) => '' !== (string) $value
		),
		$base_url
	);
};
$pages_url = static function ( int $page ) use ( $base_url, $current_tab, $search, $status_f, $channel_f, $tag_f, $course_f, $email_identity_f ): string {
	return add_query_arg(
		array_filter(
			array(
				'tab'     => $current_tab,
				's'       => $search,
				'status'  => $status_f,
				'channel' => $channel_f,
				'tag'     => $tag_f,
				'course_id' => $course_f,
				'email_identity' => $email_identity_f,
				'paged'   => $page,
			),
			static fn( $value ) => '' !== (string) $value
		),
		$base_url
	);
};

$active_total = 'inbox' === $current_tab
	? $inbox_total
	: ( 'emails' === $current_tab ? $emails_total : $contacts_total );
$pages = (int) ceil( max( 1, $active_total ) / $per_page );
?>
<div class="wrap atora-crm-wrap">
	<style>
	.atora-crm-wrap{max-width:1280px;padding-bottom:40px}
	.atora-crm-hero{display:grid;gap:14px;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;padding:24px;border-radius:18px;margin-bottom:20px}
	.atora-crm-kicker{display:inline-flex;width:fit-content;padding:5px 10px;border-radius:999px;background:rgba(255,255,255,.18);font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
	.atora-crm-title{margin:0;font-size:30px;line-height:1.1;color:#fff}
	.atora-crm-subtitle{margin:0;color:rgba(255,255,255,.82);font-size:14px;line-height:1.6;max-width:860px}
	.atora-crm-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
	.atora-crm-summary-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;display:grid;gap:4px;box-shadow:0 1px 4px rgba(15,23,42,.06)}
	.atora-crm-summary-card strong{font-size:24px;line-height:1;color:#1d4ed8}
	.atora-crm-summary-card span{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
	.atora-crm-stack{display:grid;grid-template-columns:2fr 1fr;gap:14px;margin-bottom:18px}
	.atora-crm-panel{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px}
		.atora-crm-panel h2{margin:0 0 6px;font-size:16px;color:#0f172a}
		.atora-crm-panel p{margin:0;color:#64748b;font-size:13px;line-height:1.5}
		.atora-crm-log-insights{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(15,23,42,.05)}
		.atora-crm-log-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px}
		.atora-crm-log-title{margin:0;font-size:15px;color:#0f172a}
		.atora-crm-log-copy{margin:2px 0 0;color:#64748b;font-size:12px}
		.atora-crm-log-total{display:grid;text-align:right}
		.atora-crm-log-total strong{font-size:22px;line-height:1;color:#1d4ed8}
		.atora-crm-log-total span{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:700}
		.atora-crm-log-state{display:block;font-size:12px;color:#64748b}
		.atora-crm-log-state[hidden]{display:none}
		.atora-crm-log-state--error{color:#b91c1c}
		.atora-crm-log-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
		.atora-crm-log-card{padding:11px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc}
		.atora-crm-log-card h3{margin:0 0 7px;font-size:12px;color:#475569;text-transform:uppercase;letter-spacing:.04em}
		.atora-crm-log-list{list-style:none;margin:0;padding:0;display:grid;gap:6px}
		.atora-crm-log-item{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px}
		.atora-crm-log-key{color:#334155}
		.atora-crm-log-val{font-weight:800;color:#0f172a}
		.atora-crm-log-item.is-empty{justify-content:flex-start;color:#94a3b8}
		.atora-crm-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
		.atora-crm-link{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#f8fafc;text-decoration:none;color:#1e293b;font-size:12px;font-weight:700}
		.atora-crm-link:hover{border-color:#1d4ed8;color:#1d4ed8;background:#eff6ff}
	.atora-crm-identities{display:grid;gap:10px}
	.atora-crm-identity{display:grid;gap:2px;padding:10px 12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0}
	.atora-crm-identity strong{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#475569}
	.atora-crm-identity span{font-size:14px;color:#0f172a;font-weight:700}
	.atora-crm-identity small{font-size:12px;color:#64748b}
	.atora-crm-channels{display:grid;gap:8px;margin-top:12px}
	.atora-crm-channel{display:grid;gap:2px;padding:9px 10px;border-radius:10px;border:1px solid #e2e8f0;background:#f8fafc}
	.atora-crm-channel strong{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#334155;display:flex;align-items:center;gap:6px}
	.atora-crm-channel span{font-size:12px;color:#64748b}
	.atora-crm-dot{display:inline-block;width:8px;height:8px;border-radius:999px}
	.atora-crm-dot--ok{background:#16a34a}
	.atora-crm-dot--off{background:#d97706}
	.atora-crm-scope-note{margin:0 0 14px;padding:10px 12px;border-radius:10px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:13px}
	.atora-crm-tabs{display:flex;gap:6px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:6px}
	.atora-crm-tab{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:10px;text-decoration:none;color:#64748b;font-weight:700;font-size:13px;border:1px solid transparent}
	.atora-crm-tab:hover{color:#1d4ed8;background:#eff6ff}
	.atora-crm-tab.is-active{color:#1d4ed8;border-color:#bfdbfe;background:#eff6ff}
	.atora-crm-tab-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 7px;border-radius:999px;background:#dbeafe;color:#1d4ed8;font-size:11px}
	.atora-crm-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap}
	.atora-crm-search{display:flex;gap:8px;flex:1;min-width:260px}
	.atora-crm-search input{flex:1;padding:9px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px}
	.atora-crm-search button{padding:9px 14px;border:none;border-radius:10px;background:#1d4ed8;color:#fff;font-weight:700;cursor:pointer}
	.atora-crm-filter select{padding:9px 11px;border:1px solid #cbd5e1;border-radius:10px;font-size:13px;color:#0f172a;background:#fff}
	.atora-crm-count{margin-left:auto;font-size:12px;color:#64748b;font-weight:700}
	.atora-crm-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(15,23,42,.05)}
	.atora-crm-table thead th{background:#f8fafc;padding:12px 14px;text-align:left;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#64748b;border-bottom:1px solid #e5e7eb}
	.atora-crm-table tbody tr{border-bottom:1px solid #f1f5f9}
	.atora-crm-table tbody tr:hover{background:#f8fafc}
	.atora-crm-table tbody tr:last-child{border-bottom:none}
	.atora-crm-table td{padding:12px 14px;font-size:13px;color:#334155;vertical-align:middle}
	.atora-crm-table td:last-child{white-space:nowrap}
	.atora-crm-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
	.atora-crm-actions-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
	.atora-crm-btn{display:inline-flex;align-items:center;padding:6px 11px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid transparent}
	.atora-crm-btn--view{background:#dbeafe;color:#1d4ed8}
	.atora-crm-btn--view:hover{background:#bfdbfe;color:#1d4ed8}
	.atora-crm-btn--del{background:#fee2e2;color:#dc2626}
	.atora-crm-btn--del:hover{background:#fecaca;color:#dc2626}
	.atora-crm-muted{color:#94a3b8;font-size:12px}
	.atora-crm-contact-cell{display:flex;align-items:center;gap:10px}
	.atora-crm-avatar{width:34px;height:34px;border-radius:50%;background:#1d4ed8;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;overflow:hidden;flex-shrink:0}
	.atora-crm-contact-info strong{display:block;font-size:13px;color:#0f172a}
	.atora-crm-contact-info span{font-size:12px;color:#64748b}
	.atora-crm-empty{padding:48px 24px;text-align:center;color:#94a3b8;font-size:15px;border:1px dashed #cbd5e1;border-radius:14px;background:#fff}
	.atora-crm-pagination{display:flex;gap:6px;align-items:center;justify-content:flex-end;margin-top:16px}
	.atora-crm-pagination a,.atora-crm-pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;color:#334155}
	.atora-crm-pagination a:hover{border-color:#1d4ed8;color:#1d4ed8;background:#eff6ff}
	.atora-crm-pagination span.current{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
	.atora-crm-notice{padding:12px 14px;border-radius:10px;margin-bottom:12px;font-size:13px;font-weight:700}
	.atora-crm-notice--success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
		@media (max-width:1100px){.atora-crm-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.atora-crm-stack{grid-template-columns:1fr}.atora-crm-log-grid{grid-template-columns:1fr 1fr}}
		@media (max-width:782px){.atora-crm-summary{grid-template-columns:1fr}.atora-crm-search{min-width:100%}.atora-crm-count{margin-left:0}.atora-crm-log-grid{grid-template-columns:1fr}.atora-crm-log-head{display:grid}}
		</style>

	<section class="atora-crm-hero">
		<span class="atora-crm-kicker"><?php echo esc_html( $role_label ); ?></span>
		<h1 class="atora-crm-title"><?php esc_html_e( 'CRM ATORA', 'atora-lms' ); ?></h1>
		<p class="atora-crm-subtitle"><?php echo esc_html( $role_desc ); ?></p>
	</section>

	<div class="atora-crm-summary">
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $contacts_scope_total ); ?></strong><span><?php esc_html_e( 'Contactos visibles', 'atora-lms' ); ?></span></div>
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $leads_scope_total ); ?></strong><span><?php esc_html_e( 'Leads en alcance', 'atora-lms' ); ?></span></div>
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $students_scope_total ); ?></strong><span><?php esc_html_e( 'Estudiantes en CRM', 'atora-lms' ); ?></span></div>
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $pending_email_total ); ?></strong><span><?php esc_html_e( 'Correos pendientes', 'atora-lms' ); ?></span></div>
	</div>

	<?php if ( $has_email_table ) : ?>
	<div class="atora-crm-summary">
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $sent_email_total ); ?></strong><span><?php esc_html_e( 'Correos enviados', 'atora-lms' ); ?></span></div>
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $pending_email_total ); ?></strong><span><?php esc_html_e( 'Correos por enviar', 'atora-lms' ); ?></span></div>
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $failed_email_total ); ?></strong><span><?php esc_html_e( 'Correos con error', 'atora-lms' ); ?></span></div>
		<div class="atora-crm-summary-card"><strong><?php echo esc_html( (string) $opened_email_total ); ?></strong><span><?php esc_html_e( 'Correos abiertos', 'atora-lms' ); ?></span></div>
	</div>
	<?php endif; ?>

	<section
		class="atora-crm-log-insights"
		id="atora-crm-log-summary"
		data-atora-log-summary="1"
		data-summary="<?php echo esc_attr( wp_json_encode( $message_log_summary_bootstrap ) ); ?>"
		data-summary-item-class="atora-crm-log-item"
		data-summary-empty-class="atora-crm-log-item is-empty"
		data-summary-key-class="atora-crm-log-key"
		data-summary-value-class="atora-crm-log-val"
	>
		<div class="atora-crm-log-head">
			<div>
				<h2 class="atora-crm-log-title"><?php esc_html_e( 'Resumen de trazabilidad', 'atora-lms' ); ?></h2>
				<p class="atora-crm-log-copy"><?php esc_html_e( 'Agregado desde atora_message_log para monitorear ejecución por canal, estado y tipo de evento.', 'atora-lms' ); ?></p>
				<span class="atora-crm-log-state" data-summary-loading><?php esc_html_e( 'Actualizando resumen…', 'atora-lms' ); ?></span>
				<span class="atora-crm-log-state atora-crm-log-state--error" data-summary-error hidden></span>
			</div>
			<div class="atora-crm-log-total">
				<strong data-summary-total>0</strong>
				<span><?php esc_html_e( 'Eventos de log', 'atora-lms' ); ?></span>
			</div>
		</div>
		<div class="atora-crm-log-grid">
			<div class="atora-crm-log-card">
				<h3><?php esc_html_e( 'Por canal', 'atora-lms' ); ?></h3>
				<ul class="atora-crm-log-list" data-summary-list="channel">
					<li class="atora-crm-log-item is-empty"><?php esc_html_e( 'Sin actividad de canales en este alcance.', 'atora-lms' ); ?></li>
				</ul>
			</div>
			<div class="atora-crm-log-card">
				<h3><?php esc_html_e( 'Por estado', 'atora-lms' ); ?></h3>
				<ul class="atora-crm-log-list" data-summary-list="status">
					<li class="atora-crm-log-item is-empty"><?php esc_html_e( 'Sin estados registrados en logs.', 'atora-lms' ); ?></li>
				</ul>
			</div>
			<div class="atora-crm-log-card">
				<h3><?php esc_html_e( 'Por evento', 'atora-lms' ); ?></h3>
				<ul class="atora-crm-log-list" data-summary-list="event_type">
					<li class="atora-crm-log-item is-empty"><?php esc_html_e( 'Sin eventos de trazabilidad aún.', 'atora-lms' ); ?></li>
				</ul>
			</div>
		</div>
	</section>

	<div class="atora-crm-stack">
		<div class="atora-crm-panel">
			<h2><?php esc_html_e( 'Centro de operación', 'atora-lms' ); ?></h2>
			<p><?php esc_html_e( 'Combina CRM, mensajería interna y contexto académico para actuar rápido por tipo de usuario.', 'atora-lms' ); ?></p>
			<div class="atora-crm-actions">
				<a class="atora-crm-link" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-messages' ) ); ?>"><?php esc_html_e( 'Bandeja interna', 'atora-lms' ); ?></a>
				<a class="atora-crm-link" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-academic-hub' ) ); ?>"><?php esc_html_e( 'Hub académico', 'atora-lms' ); ?></a>
				<?php if ( $crm_can_manage ) : ?>
				<a class="atora-crm-link" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-settings&tab=channels' ) ); ?>"><?php esc_html_e( 'Configurar canales', 'atora-lms' ); ?></a>
				<?php foreach ( $export_datasets as $dataset_key => $dataset_label ) : ?>
				<a class="atora-crm-link" href="<?php echo esc_url( $export_url( (string) $dataset_key ) ); ?>"><?php echo esc_html( (string) $dataset_label ); ?></a>
				<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<div class="atora-crm-panel">
			<h2><?php esc_html_e( 'Identidades de correo', 'atora-lms' ); ?></h2>
			<div class="atora-crm-identities">
				<?php foreach ( $email_identity_cards as $identity ) : ?>
				<div class="atora-crm-identity">
					<strong><?php echo esc_html( (string) $identity['label'] ); ?></strong>
					<span><?php echo esc_html( (string) ( $identity['email'] ?: '—' ) ); ?></span>
					<small><?php echo esc_html( (string) $identity['note'] ); ?></small>
				</div>
				<?php endforeach; ?>
			</div>
			<div class="atora-crm-channels">
				<?php foreach ( $channel_state_cards as $state_card ) : ?>
					<div class="atora-crm-channel">
						<strong>
							<span class="atora-crm-dot <?php echo ! empty( $state_card['ready'] ) ? 'atora-crm-dot--ok' : 'atora-crm-dot--off'; ?>"></span>
							<?php echo esc_html( (string) $state_card['label'] ); ?>
						</strong>
						<span><?php echo esc_html( (string) $state_card['note'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $crm_can_manage ) : ?>
				<div class="atora-crm-actions" style="margin-top:10px">
					<a class="atora-crm-link" href="<?php echo esc_url( admin_url( 'admin.php?page=clms-settings&tab=channels' ) ); ?>"><?php esc_html_e( 'Editar canales', 'atora-lms' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! $crm_can_manage ) : ?>
		<p class="atora-crm-scope-note">
			<?php
			printf(
				esc_html__( 'Vista restringida por enrollments: %d estudiante(s) dentro de tus cursos asignados.', 'atora-lms' ),
				esc_html( (string) $scope_size )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
	<div class="atora-crm-notice atora-crm-notice--success">✓ <?php esc_html_e( 'Elemento eliminado correctamente.', 'atora-lms' ); ?></div>
	<?php endif; ?>
	<?php if ( ! empty( $_GET['updated'] ) ) : ?>
	<div class="atora-crm-notice atora-crm-notice--success">✓ <?php esc_html_e( 'Estado actualizado correctamente.', 'atora-lms' ); ?></div>
	<?php endif; ?>

	<div class="atora-crm-tabs">
		<?php foreach ( $allowed_tabs as $tab_key ) : ?>
			<?php
			$tab_label = 'contacts' === $tab_key
				? __( 'Contactos', 'atora-lms' )
				: ( 'emails' === $tab_key ? __( 'Correos', 'atora-lms' ) : __( 'Bandeja unificada', 'atora-lms' ) );
			?>
			<a class="atora-crm-tab <?php echo $current_tab === $tab_key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $tab_url( $tab_key ) ); ?>">
				<?php echo esc_html( $tab_label ); ?>
				<span class="atora-crm-tab-count"><?php echo esc_html( (string) absint( $tab_count_map[ $tab_key ] ?? 0 ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>

	<form method="get" action="">
		<input type="hidden" name="page" value="atora-crm">
		<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>">
		<div class="atora-crm-toolbar">
			<div class="atora-crm-search">
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php echo esc_attr( 'contacts' === $current_tab ? __( 'Buscar por nombre o email…', 'atora-lms' ) : __( 'Buscar por asunto, template o destinatario…', 'atora-lms' ) ); ?>">
				<button type="submit"><?php esc_html_e( 'Buscar', 'atora-lms' ); ?></button>
			</div>

			<div class="atora-crm-filter">
				<select name="status" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Todos los estados', 'atora-lms' ); ?></option>
					<?php
					$status_map = 'contacts' === $current_tab ? $status_labels : $delivery_status_labels;
					foreach ( $status_map as $status_key => $status_info ) :
					?>
						<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status_f, $status_key ); ?>><?php echo esc_html( (string) $status_info['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<?php if ( 'inbox' === $current_tab ) : ?>
			<div class="atora-crm-filter">
				<select name="channel" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Todos los canales', 'atora-lms' ); ?></option>
					<?php foreach ( $channel_labels as $channel_key => $channel_label ) : ?>
						<option value="<?php echo esc_attr( $channel_key ); ?>" <?php selected( $channel_f, $channel_key ); ?>><?php echo esc_html( (string) $channel_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $tag_options ) ) : ?>
			<div class="atora-crm-filter">
				<select name="tag" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Todos los tags', 'atora-lms' ); ?></option>
					<?php foreach ( $tag_options as $tag_option ) : ?>
						<option value="<?php echo esc_attr( (string) $tag_option ); ?>" <?php selected( $tag_f, (string) $tag_option ); ?>><?php echo esc_html( (string) $tag_option ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $course_options ) ) : ?>
			<div class="atora-crm-filter">
				<select name="course_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( 'Todos los cursos', 'atora-lms' ); ?></option>
					<?php foreach ( $course_options as $course_option_id => $course_option_label ) : ?>
						<option value="<?php echo esc_attr( (string) absint( $course_option_id ) ); ?>" <?php selected( $course_f, absint( $course_option_id ) ); ?>><?php echo esc_html( (string) $course_option_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<?php if ( in_array( $current_tab, array( 'inbox', 'emails' ), true ) ) : ?>
			<div class="atora-crm-filter">
				<select name="email_identity" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Todas las identidades', 'atora-lms' ); ?></option>
					<?php foreach ( $email_identity_labels as $identity_key => $identity_label ) : ?>
						<option value="<?php echo esc_attr( $identity_key ); ?>" <?php selected( $email_identity_f, $identity_key ); ?>><?php echo esc_html( (string) $identity_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php endif; ?>

			<span class="atora-crm-count">
				<?php
				if ( 'contacts' === $current_tab ) {
					printf( esc_html( _n( '%d contacto', '%d contactos', $contacts_total, 'atora-lms' ) ), esc_html( (string) $contacts_total ) );
				} elseif ( 'emails' === $current_tab ) {
					printf( esc_html( _n( '%d correo', '%d correos', $emails_total, 'atora-lms' ) ), esc_html( (string) $emails_total ) );
				} else {
					printf( esc_html( _n( '%d mensaje', '%d mensajes', $inbox_total, 'atora-lms' ) ), esc_html( (string) $inbox_total ) );
				}
				?>
			</span>
		</div>
	</form>

	<?php if ( 'contacts' === $current_tab ) : ?>
		<?php if ( ! $has_contacts_table ) : ?>
			<p class="atora-crm-empty"><?php esc_html_e( 'La tabla de contactos aún no existe. Ejecuta el instalador del módulo CRM.', 'atora-lms' ); ?></p>
		<?php elseif ( empty( $contacts ) ) : ?>
			<p class="atora-crm-empty"><?php esc_html_e( 'No se encontraron contactos para este alcance.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<table class="atora-crm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Contacto', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Matrículas', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Fuente', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Actualizado', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $contacts as $contact ) : ?>
					<?php
					$initials  = strtoupper( substr( (string) ( $contact->name ?? '' ), 0, 1 ) ?: substr( (string) ( $contact->email ?? '' ), 0, 1 ) );
					$s_info    = $status_labels[ $contact->status ?? '' ] ?? array( 'label' => $contact->status ?? '—', 'color' => '#64748b', 'bg' => '#f1f5f9' );
					$profile_url = add_query_arg(
						array(
							'page'    => 'clms-my-profile',
							'user_id' => absint( $contact->user_id ?? 0 ),
						),
						admin_url( 'admin.php' )
					);
					$del_url = add_query_arg(
						array(
							'page'         => 'atora-crm',
							'tab'          => 'contacts',
							'atora_action' => 'delete_contact',
							'item_id'      => absint( $contact->id ),
							'_wpnonce'     => wp_create_nonce( 'atora_crm_delete_contact_' . absint( $contact->id ) ),
						),
						admin_url( 'admin.php' )
					);
					$enrollment_count = 0;
					if ( ! empty( $contact->user_id ) ) {
						$_cuid = absint( $contact->user_id );
						if ( class_exists( '\ATORA\LMS\LMS_Enrollment_Service' ) ) {
							$enrollment_count = count( (array) \ATORA\LMS\LMS_Enrollment_Service::get_user_enrollments( $_cuid, 'active' ) );
						} elseif ( class_exists( 'CLMS_Helper' ) && method_exists( 'CLMS_Helper', 'get_user_enrolled_courses' ) ) {
							$enrollment_count = count( (array) \CLMS_Helper::get_user_enrolled_courses( $_cuid ) );
						}
					}
					?>
					<tr>
						<td>
							<div class="atora-crm-contact-cell">
								<div class="atora-crm-avatar"><?php echo esc_html( $initials ); ?></div>
								<div class="atora-crm-contact-info">
									<strong><?php echo esc_html( (string) ( $contact->name ?: '—' ) ); ?></strong>
									<span><?php echo esc_html( (string) ( $contact->email ?: '—' ) ); ?></span>
								</div>
							</div>
						</td>
						<td><span class="atora-crm-badge" style="background:<?php echo esc_attr( (string) $s_info['bg'] ); ?>;color:<?php echo esc_attr( (string) $s_info['color'] ); ?>"><?php echo esc_html( (string) $s_info['label'] ); ?></span></td>
						<td><?php echo esc_html( (string) $enrollment_count ); ?></td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $contact->source ?? '—' ) ) ); ?></td>
						<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $contact->updated_at ) ) ); ?></td>
						<td>
							<div class="atora-crm-actions-row">
								<?php if ( ! empty( $contact->user_id ) ) : ?>
								<a class="atora-crm-btn atora-crm-btn--view" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Ver perfil', 'atora-lms' ); ?></a>
								<?php endif; ?>
								<?php if ( $crm_can_manage ) : ?>
								<a class="atora-crm-btn atora-crm-btn--del" href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('<?php esc_attr_e( '¿Eliminar este contacto y su historial?', 'atora-lms' ); ?>')"><?php esc_html_e( 'Eliminar', 'atora-lms' ); ?></a>
								<?php else : ?>
								<span class="atora-crm-muted"><?php esc_html_e( 'Solo lectura', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php elseif ( 'emails' === $current_tab ) : ?>
		<?php if ( ! $has_email_table ) : ?>
			<p class="atora-crm-empty"><?php esc_html_e( 'La tabla de correos aún no existe. Activa Email Engine.', 'atora-lms' ); ?></p>
		<?php elseif ( empty( $emails ) ) : ?>
			<p class="atora-crm-empty"><?php esc_html_e( 'No se encontraron correos para este alcance.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<table class="atora-crm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Asunto', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Destinatario', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $emails as $email_row ) : ?>
					<?php
					$es_info = $delivery_status_labels[ $email_row->status ?? '' ] ?? array( 'label' => $email_row->status ?? '—', 'color' => '#64748b', 'bg' => '#f1f5f9' );
					$email_metadata = is_string( $email_row->metadata ?? '' ) ? json_decode( (string) $email_row->metadata, true ) : array();
					$email_metadata = is_array( $email_metadata ) ? $email_metadata : array();
					$email_identity = sanitize_key( (string) ( $email_row->identity_key ?? $email_metadata['identity_key'] ?? $email_metadata['email_identity'] ?? '' ) );
					$email_identity = $email_identity_map[ $email_identity ] ?? $email_identity;
					$email_identity_label = $email_identity_labels[ $email_identity ] ?? ( '' !== $email_identity ? ucfirst( $email_identity ) : __( 'Sin identidad', 'atora-lms' ) );
					$mark_read_url = add_query_arg(
						array(
							'page'         => 'atora-crm',
							'tab'          => 'emails',
							'atora_action' => 'mark_email_opened',
							'item_id'      => absint( $email_row->id ),
							'_wpnonce'     => wp_create_nonce( 'atora_crm_mark_email_opened_' . absint( $email_row->id ) ),
						),
						admin_url( 'admin.php' )
					);
					$del_url = add_query_arg(
						array(
							'page'         => 'atora-crm',
							'tab'          => 'emails',
							'atora_action' => 'delete_email',
							'item_id'      => absint( $email_row->id ),
							'_wpnonce'     => wp_create_nonce( 'atora_crm_delete_email_' . absint( $email_row->id ) ),
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td>
							<strong style="display:block;color:#0f172a"><?php echo esc_html( (string) ( $email_row->subject ?: __( '(sin asunto)', 'atora-lms' ) ) ); ?></strong>
							<span class="atora-crm-muted">#<?php echo esc_html( (string) absint( $email_row->id ) ); ?> · <?php echo esc_html( (string) $email_identity_label ); ?></span>
						</td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $email_row->user_email ?: '—' ) ) ); ?></td>
						<td><span class="atora-crm-badge" style="background:<?php echo esc_attr( (string) $es_info['bg'] ); ?>;color:<?php echo esc_attr( (string) $es_info['color'] ); ?>"><?php echo esc_html( (string) $es_info['label'] ); ?></span></td>
						<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) $email_row->created_at ) ) ); ?></td>
						<td>
							<div class="atora-crm-actions-row">
								<?php if ( $crm_can_manage ) : ?>
								<?php if ( empty( $email_row->opened_at ) ) : ?>
								<a class="atora-crm-btn atora-crm-btn--view" href="<?php echo esc_url( $mark_read_url ); ?>"><?php esc_html_e( 'Marcar leído', 'atora-lms' ); ?></a>
								<?php else : ?>
								<span class="atora-crm-muted"><?php echo esc_html( sprintf( __( 'Leído %s', 'atora-lms' ), date_i18n( 'd/m/Y H:i', strtotime( (string) $email_row->opened_at ) ) ) ); ?></span>
								<?php endif; ?>
								<a class="atora-crm-btn atora-crm-btn--del" href="<?php echo esc_url( $del_url ); ?>" onclick="return confirm('<?php esc_attr_e( '¿Eliminar este correo de la cola?', 'atora-lms' ); ?>')"><?php esc_html_e( 'Eliminar', 'atora-lms' ); ?></a>
								<?php else : ?>
								<span class="atora-crm-muted"><?php esc_html_e( 'Solo lectura', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php else : ?>
		<?php if ( empty( $inbox_rows ) ) : ?>
			<p class="atora-crm-empty"><?php esc_html_e( 'No hay mensajes para los filtros actuales.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<table class="atora-crm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Canal', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Mensaje / Plantilla', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Destinatario', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Estado', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Fecha', 'atora-lms' ); ?></th>
						<th><?php esc_html_e( 'Acciones', 'atora-lms' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $inbox_rows as $row ) : ?>
					<?php
					$channel_key = sanitize_key( (string) ( $row['channel'] ?? '' ) );
					$channel_lbl = $channel_labels[ $channel_key ] ?? ucfirst( $channel_key ?: '—' );
					$st_key      = sanitize_key( (string) ( $row['status'] ?? '' ) );
					$st_info     = $delivery_status_labels[ $st_key ] ?? array( 'label' => $st_key ?: '—', 'color' => '#64748b', 'bg' => '#f1f5f9' );
					$mark_read_url = '';
					$is_read       = false;
					if ( 'email_queue' === ( $row['source'] ?? '' ) ) {
						$mark_read_url = add_query_arg(
							array(
								'page'         => 'atora-crm',
								'tab'          => 'inbox',
								'atora_action' => 'mark_email_opened',
								'item_id'      => absint( $row['item_id'] ?? 0 ),
								'_wpnonce'     => wp_create_nonce( 'atora_crm_mark_email_opened_' . absint( $row['item_id'] ?? 0 ) ),
							),
							admin_url( 'admin.php' )
						);
						$is_read = ! empty( $row['opened_at'] );
					} elseif ( 'message_queue' === ( $row['source'] ?? '' ) ) {
						$mark_read_url = add_query_arg(
							array(
								'page'         => 'atora-crm',
								'tab'          => 'inbox',
								'atora_action' => 'mark_message_read',
								'item_id'      => absint( $row['item_id'] ?? 0 ),
								'_wpnonce'     => wp_create_nonce( 'atora_crm_mark_message_read_' . absint( $row['item_id'] ?? 0 ) ),
							),
							admin_url( 'admin.php' )
						);
						$is_read = 'read' === $st_key;
					}
					?>
					<tr>
						<td><?php echo esc_html( $channel_lbl ); ?></td>
						<td>
							<strong style="display:block;color:#0f172a"><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></strong>
							<span class="atora-crm-muted"><?php echo esc_html( sanitize_text_field( (string) ( $row['source'] ?? '' ) ) ); ?> · #<?php echo esc_html( (string) absint( $row['item_id'] ?? 0 ) ); ?><?php if ( ! empty( $row['identity_label'] ) ) : ?> · <?php echo esc_html( sanitize_text_field( (string) $row['identity_label'] ) ); ?><?php endif; ?></span>
						</td>
						<td><?php echo esc_html( sanitize_text_field( (string) ( $row['recipient'] ?? '' ) ) ); ?></td>
						<td><span class="atora-crm-badge" style="background:<?php echo esc_attr( (string) $st_info['bg'] ); ?>;color:<?php echo esc_attr( (string) $st_info['color'] ); ?>"><?php echo esc_html( (string) $st_info['label'] ); ?></span></td>
						<td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( (string) ( $row['created_at'] ?? '' ) ) ) ); ?></td>
						<td>
							<div class="atora-crm-actions-row">
								<?php if ( $crm_can_manage ) : ?>
								<?php if ( $is_read ) : ?>
								<span class="atora-crm-muted"><?php esc_html_e( 'Leído', 'atora-lms' ); ?></span>
								<?php elseif ( '' !== $mark_read_url ) : ?>
								<a class="atora-crm-btn atora-crm-btn--view" href="<?php echo esc_url( $mark_read_url ); ?>"><?php esc_html_e( 'Marcar leído', 'atora-lms' ); ?></a>
								<?php else : ?>
								<span class="atora-crm-muted">—</span>
								<?php endif; ?>
								<?php else : ?>
								<span class="atora-crm-muted"><?php esc_html_e( 'Solo lectura', 'atora-lms' ); ?></span>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

		<?php if ( $active_total > $per_page && $pages > 1 ) : ?>
		<div class="atora-crm-pagination">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?php echo esc_url( $pages_url( $paged - 1 ) ); ?>">‹</a>
		<?php endif; ?>
		<?php for ( $page_n = max( 1, $paged - 2 ); $page_n <= min( $pages, $paged + 2 ); $page_n++ ) : ?>
			<?php if ( $page_n === $paged ) : ?>
				<span class="current"><?php echo esc_html( (string) $page_n ); ?></span>
			<?php else : ?>
				<a href="<?php echo esc_url( $pages_url( $page_n ) ); ?>"><?php echo esc_html( (string) $page_n ); ?></a>
			<?php endif; ?>
		<?php endfor; ?>
		<?php if ( $paged < $pages ) : ?>
			<a href="<?php echo esc_url( $pages_url( $paged + 1 ) ); ?>">›</a>
		<?php endif; ?>
		</div>
		<?php endif; ?>

	</div>
