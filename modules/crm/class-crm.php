<?php
/**
 * ATORA LMS v5 — CRM Completo
 *
 * Gestiona contactos (leads + estudiantes), tagging automático y manual,
 * timeline de actividad, notas, búsqueda avanzada y segmentación visual.
 *
 * @package ATORA_LMS\CRM
 * @since   5.0.0
 */

namespace ATORA\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-crm-access.php';
require_once __DIR__ . '/trait-crm-events-messaging.php';
require_once __DIR__ . '/trait-crm-contacts.php';
require_once __DIR__ . '/trait-crm-rest.php';
require_once __DIR__ . '/trait-crm-admin-sync.php';
require_once __DIR__ . '/trait-crm-export-admin.php';

/**
 * Class CRM
 *
 * @since 5.0.0
 */
class CRM {
	/**
	 * Flag interno para detectar resolución ambigua de usuario por teléfono.
	 *
	 * @var bool
	 */
	private static bool $last_phone_user_match_ambiguous = false;

	/**
	 * Flag interno para detectar resolución ambigua de contacto por teléfono.
	 *
	 * @var bool
	 */
	private static bool $last_phone_contact_match_ambiguous = false;

	use CRM_Access_Trait;
	use CRM_Events_Messaging_Trait;
	use CRM_Contacts_Trait;
	use CRM_Rest_Trait;
	use CRM_Admin_Sync_Trait;
	use CRM_Export_Admin_Trait;
}
