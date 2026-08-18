<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/submission/trait-submission-core.php';
require_once __DIR__ . '/submission/trait-submission-storage-review.php';
require_once __DIR__ . '/submission/trait-submission-files-assets.php';

class CLMS_Submission {

	const CPT = 'clms_submission';

	/**
	 * Máximo de archivos por entrega.
	 *
	 * @var int
	 */
	protected $max_files = 5;

	/**
	 * Máximo por archivo en bytes.
	 *
	 * @var int
	 */
	protected $max_file_size = 10485760; // 10 MB.

	/**
	 * Evita encolar assets múltiples veces.
	 *
	 * @var bool
	 */
	protected static $assets_enqueued = false;

	/**
	 * Tipos permitidos.
	 *
	 * @var array<string,string>
	 */
	protected $allowed_mimes = array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'txt'  => 'text/plain',
		'rtf'  => 'application/rtf',
		'zip'  => 'application/zip',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	use CLMS_Submission_Core_Trait;
	use CLMS_Submission_Storage_Review_Trait;
	use CLMS_Submission_Files_Assets_Trait;

	/**
	 * Inicializa hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'handle_submission_request' ) );
		add_shortcode( 'clms_submission_form', array( $this, 'render_submission_form_shortcode' ) );
	}
}
