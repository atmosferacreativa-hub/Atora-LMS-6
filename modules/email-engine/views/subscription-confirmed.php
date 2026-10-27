<?php
/**
 * Página pública de confirmación del boletín (doble opt-in, 6.38.0).
 *
 * @package ATORA_LMS\EmailEngine
 *
 * @var string $confirm_state confirmed|invalid
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ok    = isset( $confirm_state ) && 'confirmed' === $confirm_state;
$title = $ok ? __( 'Suscripción confirmada', 'atora-lms' ) : __( 'Este enlace no es válido', 'atora-lms' );
?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( $title ); ?></title>
</head>
<body style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:16px;">
	<main style="max-width:600px;margin:32px auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;">
		<h1 style="margin-top:0;font-size:22px;"><?php echo esc_html( $title ); ?></h1>
		<p><?php echo $ok ? esc_html__( 'Desde ahora recibirás el boletín. Cada correo trae un enlace para darte de baja.', 'atora-lms' ) : esc_html__( 'El enlace está incompleto o ya no corresponde a este correo.', 'atora-lms' ); ?></p>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Ir al sitio', 'atora-lms' ); ?></a></p>
	</main>
</body>
</html>
