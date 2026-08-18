<?php
/**
 * Partial: CRM lead capture section (reusable).
 *
 * Variables esperadas:
 * - string $crm_lead_title
 * - string $crm_lead_copy
 * - int    $crm_lead_form_id
 * - string $crm_lead_form_shortcode
 * - string $crm_lead_scope_class
 * - string $crm_lead_title_class
 * - string $crm_lead_context
 *
 * @package ATORA_LMS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$crm_lead_title          = isset( $crm_lead_title ) ? (string) $crm_lead_title : '';
$crm_lead_copy           = isset( $crm_lead_copy ) ? (string) $crm_lead_copy : '';
$crm_lead_form_id        = isset( $crm_lead_form_id ) ? absint( $crm_lead_form_id ) : 0;
$crm_lead_form_shortcode = isset( $crm_lead_form_shortcode ) ? (string) $crm_lead_form_shortcode : '';
$crm_lead_scope_class    = isset( $crm_lead_scope_class ) ? (string) $crm_lead_scope_class : '';
$crm_lead_title_class    = isset( $crm_lead_title_class ) ? (string) $crm_lead_title_class : '';
$crm_lead_context        = isset( $crm_lead_context ) ? (string) $crm_lead_context : '';

if ( ! $crm_lead_form_id || '' === trim( $crm_lead_form_shortcode ) ) {
	return;
}

$scope_classes = preg_split( '/\s+/', trim( $crm_lead_scope_class ) );
$scope_classes = is_array( $scope_classes ) ? array_filter( array_map( 'sanitize_html_class', $scope_classes ) ) : array();

$title_classes = preg_split( '/\s+/', trim( $crm_lead_title_class ) );
$title_classes = is_array( $title_classes ) ? array_filter( array_map( 'sanitize_html_class', $title_classes ) ) : array();

$section_classes = array_merge( $scope_classes, array( 'clms-crm-lead' ) );
$title_classes   = array_merge( $title_classes, array( 'clms-crm-lead__title' ) );
?>
<section class="<?php echo esc_attr( implode( ' ', array_unique( $section_classes ) ) ); ?>" data-clms-crm-lead-context="<?php echo esc_attr( sanitize_key( $crm_lead_context ) ); ?>">
	<div class="clms-crm-lead__card">
		<?php if ( '' !== trim( $crm_lead_title ) ) : ?>
			<h2 class="<?php echo esc_attr( implode( ' ', array_unique( $title_classes ) ) ); ?>">
				<?php echo esc_html( $crm_lead_title ); ?>
			</h2>
		<?php endif; ?>

		<?php if ( '' !== trim( $crm_lead_copy ) ) : ?>
			<p class="clms-crm-lead__copy"><?php echo esc_html( $crm_lead_copy ); ?></p>
		<?php endif; ?>

		<div class="clms-crm-lead__form">
			<?php echo do_shortcode( $crm_lead_form_shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
</section>

