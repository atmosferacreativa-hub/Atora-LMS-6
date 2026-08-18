<?php
/**
 * CRM Unified Inbox standalone view.
 *
 * @package ATORA_LMS\CRM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Bandeja unificada CRM', 'atora-lms' ); ?></h1>
	<p><?php esc_html_e( 'La bandeja unificada operativa se encuentra en CRM → pestaña Bandeja.', 'atora-lms' ); ?></p>
	<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm&tab=inbox' ) ); ?>"><?php esc_html_e( 'Abrir bandeja', 'atora-lms' ); ?></a></p>
</div>
