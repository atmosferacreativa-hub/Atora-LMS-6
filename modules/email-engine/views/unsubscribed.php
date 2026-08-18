<?php
/**
 * Página pública de desuscripción.
 *
 * @package ATORA_LMS\EmailEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title><?php esc_html_e( 'Desuscripción confirmada', 'atora-lms' ); ?></title>
</head>
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;color:#0f172a;">
	<div style="max-width:680px;margin:48px auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:22px;">
		<h1 style="margin-top:0;"><?php esc_html_e( 'Desuscripción confirmada', 'atora-lms' ); ?></h1>
		<p><?php esc_html_e( 'Tu preferencia fue actualizada correctamente.', 'atora-lms' ); ?></p>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Volver al sitio', 'atora-lms' ); ?></a></p>
	</div>
</body>
</html>
