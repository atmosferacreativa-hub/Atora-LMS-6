<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/certificates/trait-certificates-settings.php';
require_once __DIR__ . '/certificates/trait-certificates-events-email.php';
require_once __DIR__ . '/certificates/trait-certificates-render.php';
require_once __DIR__ . '/certificates/trait-certificates-issuance-verification.php';
require_once __DIR__ . '/certificates/trait-certificates-helpers-assets.php';

class CLMS_Certificates {

	const PASSING_GRADE_OPTION = 'clms_certificate_passing_grade';
	const MIN_PROGRESS_OPTION  = 'clms_certificate_min_progress';
	const ENABLED_OPTION       = 'clms_certificates_enabled';
	const PUBLIC_VERIFY_OPTION = 'clms_certificate_public_verify_enabled';
	const VERIFY_PAGE_OPTION   = 'clms_certificate_verify_page_id';
	const ALLOW_REISSUE_OPTION = 'clms_certificate_allow_reissue';
	const PROGRAMS_OPTION      = 'clms_certificate_enable_programs';
	const SIGNATURE_NAME_OPTION = 'clms_certificate_signature_name';
	const SIGNATURE_ROLE_OPTION = 'clms_certificate_signature_role';
	const SEAL_TEXT_OPTION      = 'clms_certificate_seal_text';
	const EMAIL_AUTO_OPTION     = 'clms_certificate_auto_email_enabled';
	const EMAIL_SUBJECT_OPTION  = 'clms_certificate_email_subject';
	const EMAIL_HEADLINE_OPTION = 'clms_certificate_email_headline';
	const EMAIL_BUTTON_OPTION   = 'clms_certificate_email_button_text';
	const EMAIL_FOOTER_OPTION   = 'clms_certificate_email_footer_note';
	const EMAIL_BODY_OPTION     = 'clms_certificate_email_body';
	const CODE_COUNTER_OPTION   = 'clms_certificate_code_counter';
	const CERT_META_PREFIX     = '_clms_certificate_record_';
	const VERIFY_INDEX_OPTION  = 'clms_certificate_verify_index';

	use CLMS_Certificates_Settings_Trait;
	use CLMS_Certificates_Events_Email_Trait;
	use CLMS_Certificates_Render_Trait;
	use CLMS_Certificates_Issuance_Verification_Trait;
	use CLMS_Certificates_Helpers_Assets_Trait;

	public function __construct() {
		add_shortcode( 'clms_certificate', array( $this, 'render_certificate_shortcode' ) );
		add_shortcode( 'clms_my_certificates', array( $this, 'render_my_certificates_shortcode' ) );
		add_shortcode( 'clms_verify_certificate', array( $this, 'render_verify_certificate_shortcode' ) );

		add_action( 'admin_post_clms_view_certificate', array( $this, 'handle_view_certificate' ) );
		add_action( 'admin_post_nopriv_clms_view_certificate', array( $this, 'handle_view_certificate' ) );
		add_action( 'admin_post_clms_verify_certificate', array( $this, 'handle_verify_certificate' ) );
		add_action( 'admin_post_nopriv_clms_verify_certificate', array( $this, 'handle_verify_certificate' ) );
		add_action( 'admin_post_clms_revoke_certificate', array( $this, 'handle_revoke_certificate' ) );
		add_action( 'admin_post_clms_reissue_certificate', array( $this, 'handle_reissue_certificate' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'clms_grade_published', array( $this, 'handle_grade_published' ), 10, 1 );
		add_action( 'clms_course_completed', array( $this, 'handle_course_completed' ), 10, 2 );
		add_action( 'clms_program_completed', array( $this, 'handle_program_completed' ), 10, 2 );
		add_action( 'clms_program_certificate_issued', array( $this, 'handle_program_certificate_issued' ), 10, 3 );
		add_action( 'clms_certificate_issued', array( $this, 'maybe_send_certificate_email' ), 20, 3 );
	}
}
