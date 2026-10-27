<?php
/**
 * Informe de solo lectura (6.38.0): qué haría la migración de suscriptores del boletín a contactos.
 *
 * Uso: wp eval-file scripts/newsletter-subscribers-report.php
 * No escribe nada. Después, para migrar: wp eval 'print_r( \ATORA\Newsletter\Newsletter_Subscribers::migrate() );'
 *
 * Regla (decisión del titular, 2026-10-27): consentimiento explícito → suscrito; sin marca → pendiente de
 * confirmar (no se le envía nada hasta que confirme); marca "no", baja general o dirección suprimida → no entra.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( '\\ATORA\\Newsletter\\Newsletter_Subscribers' ) ) {
	echo "Ejecutar con: wp eval-file scripts/newsletter-subscribers-report.php (plugin 6.38.0 o posterior activo)\n";
	return;
}

global $wpdb;
$plan = \ATORA\Newsletter\Newsletter_Subscribers::migrate( true );

$dupes = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM ( SELECT LOWER(TRIM(email)) e FROM {$wpdb->prefix}atora_contacts GROUP BY e HAVING COUNT(*) > 1 ) d"
);

printf( "Quedarían suscritos (consentimiento explícito): %d\n", $plan['subscribed'] );
printf( "Quedarían pendientes de confirmar (sin marca):    %d\n", $plan['pending'] );
printf( "Ya estaban en la lista (no se tocan):              %d\n", $plan['skipped_existing'] );
printf( "No entran (dijeron no, baja o suprimidos):         %d\n", $plan['excluded'] );
printf( "Contactos nuevos a crear:                          %d\n", $plan['contacts_created'] );
printf( "Contactos ya duplicados por correo (revisar):      %d\n", $dupes );
