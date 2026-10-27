<?php
/**
 * Página pública de baja (6.38.0): confirmar (GET), hecho (POST) o enlace no válido.
 *
 * @package ATORA_LMS\EmailEngine
 *
 * @var string $unsub_state confirm|done|invalid
 * @var string $unsub_token Token firmado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$unsub_state = isset( $unsub_state ) ? (string) $unsub_state : 'invalid';
$unsub_token = isset( $unsub_token ) ? (string) $unsub_token : '';
$titles      = array(
	'confirm' => __( '¿Dejar de recibir correos promocionales?', 'atora-lms' ),
	'done'    => __( 'Listo: ya no recibirás correos promocionales', 'atora-lms' ),
	'invalid' => __( 'Este enlace no es válido', 'atora-lms' ),
);
$title = $titles[ $unsub_state ] ?? $titles['invalid'];
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
		<?php if ( 'confirm' === $unsub_state ) : ?>
			<p><?php esc_html_e( 'Dejarás de recibir el boletín, ofertas y novedades comerciales. Los avisos de tu cuenta y de tus cursos (tareas, notas, certificados) seguirán llegando.', 'atora-lms' ); ?></p>
			<form method="post" action="<?php echo esc_url( add_query_arg( array( 'atora_email_action' => 'unsubscribe', 'atora_email_token' => $unsub_token ), home_url( '/' ) ) ); ?>">
				<button type="submit" style="min-height:44px;padding:10px 20px;border:0;border-radius:8px;background:#0f172a;color:#fff;font-size:16px;cursor:pointer;"><?php esc_html_e( 'Confirmar baja', 'atora-lms' ); ?></button>
			</form>
		<?php elseif ( 'done' === $unsub_state ) : ?>
			<p><?php esc_html_e( 'Tu preferencia quedó guardada. Los avisos de tu cuenta y de tus cursos seguirán llegando.', 'atora-lms' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'El enlace está incompleto o es de una versión anterior. Usa el enlace del correo más reciente o inicia sesión para cambiar tus preferencias.', 'atora-lms' ); ?></p>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Volver al sitio', 'atora-lms' ); ?></a></p>
	</main>
</body>
</html>
