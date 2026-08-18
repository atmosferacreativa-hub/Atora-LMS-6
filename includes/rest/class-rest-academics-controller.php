<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/academics/trait-rest-academics-core-resources.php';
require_once __DIR__ . '/academics/trait-rest-academics-operations.php';
require_once __DIR__ . '/academics/trait-rest-academics-helpers.php';

class CLMS_REST_Academics_Controller {

	protected $permissions;

	use CLMS_REST_Academics_Core_Resources_Trait;
	use CLMS_REST_Academics_Operations_Trait;
	use CLMS_REST_Academics_Helpers_Trait;

	public function __construct( CLMS_REST_Permissions $permissions ) {
		$this->permissions = $permissions;
	}
}
