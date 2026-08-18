<?php
/**
 * Vista: campos extendidos de registro
 *
 * Incluida en register_form para ampliar el formulario nativo de WP.
 *
 * @package ATORA_LMS\Security
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$countries  = ATORA\Security\Extended_Registration::get_countries();
$timezones  = ATORA\Security\Extended_Registration::get_timezones();
$tz_detect  = wp_timezone_string(); // Zona horaria del sitio como valor inicial.
$languages  = array(
	'es' => __( 'Español', 'atora-lms' ),
	'en' => __( 'English', 'atora-lms' ),
	'pt' => __( 'Português', 'atora-lms' ),
);
$sex_options = array(
	'femenino'          => __( 'Femenino', 'atora-lms' ),
	'masculino'         => __( 'Masculino', 'atora-lms' ),
	'no_binario'        => __( 'No binario', 'atora-lms' ),
	'prefiero_no_decir' => __( 'Prefiero no decir', 'atora-lms' ),
);

// Valores previos (si hay error de validación WP rellena el form).
$old = array(
	'country_code' => strtoupper( sanitize_text_field( wp_unslash( $_POST['atora_country_code'] ?? '' ) ) ),
	'city'         => sanitize_text_field( wp_unslash( $_POST['atora_city'] ?? '' ) ),
	'state'        => sanitize_text_field( wp_unslash( $_POST['atora_state'] ?? '' ) ),
	'sex'          => sanitize_key( wp_unslash( $_POST['atora_sex'] ?? '' ) ),
	'age'          => absint( wp_unslash( $_POST['atora_age'] ?? '' ) ),
	'phone'        => sanitize_text_field( wp_unslash( $_POST['atora_phone'] ?? '' ) ),
	'whatsapp'     => sanitize_text_field( wp_unslash( $_POST['atora_whatsapp'] ?? '' ) ),
	'telegram'     => sanitize_text_field( wp_unslash( $_POST['atora_telegram'] ?? '' ) ),
	'timezone'     => sanitize_text_field( wp_unslash( $_POST['atora_timezone'] ?? $tz_detect ) ),
	'language'     => sanitize_text_field( wp_unslash( $_POST['atora_language'] ?? 'es' ) ),
);
?>

<div class="atora-registration-extended">

	<!-- País -->
	<p>
		<label for="atora_country_code">
			<?php esc_html_e( 'País', 'atora-lms' ); ?>
			<span class="required">*</span>
		</label>
		<select name="atora_country_code" id="atora_country_code" required>
			<option value=""><?php esc_html_e( '— Selecciona tu país —', 'atora-lms' ); ?></option>
			<?php foreach ( $countries as $code => $name ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $old['country_code'], $code ); ?>>
					<?php echo esc_html( $name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<!-- Ciudad -->
	<p>
		<label for="atora_city"><?php esc_html_e( 'Ciudad', 'atora-lms' ); ?></label>
		<input type="text"
		       id="atora_city"
		       name="atora_city"
		       class="input"
		       value="<?php echo esc_attr( $old['city'] ); ?>"
		       autocomplete="address-level2"
		       placeholder="<?php esc_attr_e( 'Escribe tu ciudad…', 'atora-lms' ); ?>">
		<span id="atora_city_suggestions" class="atora-autocomplete"></span>
	</p>

	<!-- Estado/Provincia -->
	<p>
		<label for="atora_state"><?php esc_html_e( 'Estado / Provincia', 'atora-lms' ); ?></label>
		<input type="text"
		       id="atora_state"
		       name="atora_state"
		       class="input"
		       value="<?php echo esc_attr( $old['state'] ); ?>"
		       autocomplete="address-level1"
		       placeholder="<?php esc_attr_e( 'Estado o provincia', 'atora-lms' ); ?>">
	</p>

	<!-- Sexo (opcional) -->
	<p>
		<label for="atora_sex"><?php esc_html_e( 'Sexo', 'atora-lms' ); ?></label>
		<select name="atora_sex" id="atora_sex">
			<option value=""><?php esc_html_e( '— Selecciona una opción —', 'atora-lms' ); ?></option>
			<?php foreach ( $sex_options as $sex_code => $sex_label ) : ?>
				<option value="<?php echo esc_attr( $sex_code ); ?>"<?php selected( $old['sex'], $sex_code ); ?>>
					<?php echo esc_html( $sex_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<!-- Edad (opcional) -->
	<p>
		<label for="atora_age"><?php esc_html_e( 'Edad', 'atora-lms' ); ?></label>
		<input type="number"
		       id="atora_age"
		       name="atora_age"
		       class="input"
		       min="1"
		       max="120"
		       value="<?php echo esc_attr( $old['age'] > 0 ? (string) $old['age'] : '' ); ?>"
		       placeholder="<?php esc_attr_e( 'Edad', 'atora-lms' ); ?>">
	</p>

	<!-- Teléfono -->
	<p>
		<label for="atora_phone">
			<?php esc_html_e( 'Teléfono', 'atora-lms' ); ?>
			<span class="required">*</span>
		</label>
		<input type="tel"
		       id="atora_phone"
		       name="atora_phone"
		       class="input"
		       value="<?php echo esc_attr( $old['phone'] ); ?>"
		       placeholder="+52 555 123 4567"
		       pattern="^\+[\d\s\-\(\)]{7,20}$">
		<span class="atora-field-hint">
			<?php esc_html_e( 'Formato internacional: +52 555 123 4567', 'atora-lms' ); ?>
		</span>
	</p>

	<!-- WhatsApp (opcional) -->
	<p>
		<label for="atora_whatsapp">
			<?php esc_html_e( 'WhatsApp', 'atora-lms' ); ?>
			<em><?php esc_html_e( '(opcional, si es diferente al teléfono)', 'atora-lms' ); ?></em>
		</label>
		<input type="tel"
		       id="atora_whatsapp"
		       name="atora_whatsapp"
		       class="input"
		       value="<?php echo esc_attr( $old['whatsapp'] ); ?>"
		       placeholder="+52 555 123 4567">
	</p>

	<!-- Telegram (opcional) -->
	<p>
		<label for="atora_telegram">
			<?php esc_html_e( 'Telegram', 'atora-lms' ); ?>
			<em><?php esc_html_e( '(opcional, usuario o @handle)', 'atora-lms' ); ?></em>
		</label>
		<input type="text"
		       id="atora_telegram"
		       name="atora_telegram"
		       class="input"
		       value="<?php echo esc_attr( $old['telegram'] ); ?>"
		       placeholder="@usuario">
	</p>

	<!-- Zona horaria -->
	<p>
		<label for="atora_timezone"><?php esc_html_e( 'Zona horaria', 'atora-lms' ); ?></label>
		<select name="atora_timezone" id="atora_timezone">
			<?php foreach ( $timezones as $tz_id => $tz_label ) : ?>
				<option value="<?php echo esc_attr( $tz_id ); ?>"<?php selected( $old['timezone'], $tz_id ); ?>>
					<?php echo esc_html( $tz_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

	<!-- Idioma preferido -->
	<p>
		<label for="atora_language"><?php esc_html_e( 'Idioma preferido', 'atora-lms' ); ?></label>
		<select name="atora_language" id="atora_language">
			<?php foreach ( $languages as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>"<?php selected( $old['language'], $code ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
	</p>

</div><!-- .atora-registration-extended -->

<!-- Consentimientos GDPR -->
<div class="atora-gdpr-consents">
	<h4><?php esc_html_e( 'Consentimientos', 'atora-lms' ); ?></h4>

	<!-- Obligatorios -->
	<p>
		<label>
			<input type="checkbox" name="atora_consent_terms" value="1"
			       <?php checked( ! empty( $_POST['atora_consent_terms'] ) ); ?> required>
			<?php
			printf(
				/* translators: %s: link a términos */
				esc_html__( 'Acepto los %s (obligatorio)', 'atora-lms' ),
				'<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank">'
				. esc_html__( 'Términos y Condiciones', 'atora-lms' )
				. '</a>'
			);
			?>
		</label>
	</p>

	<p>
		<label>
			<input type="checkbox" name="atora_consent_privacy" value="1"
			       <?php checked( ! empty( $_POST['atora_consent_privacy'] ) ); ?> required>
			<?php
			printf(
				/* translators: %s: link a política */
				esc_html__( 'Acepto la %s (obligatorio)', 'atora-lms' ),
				'<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank">'
				. esc_html__( 'Política de Privacidad', 'atora-lms' )
				. '</a>'
			);
			?>
		</label>
	</p>

	<p>
		<label>
			<input type="checkbox" name="atora_consent_academic_emails" value="1"
			       <?php checked( ! empty( $_POST['atora_consent_academic_emails'] ) ); ?> required>
			<?php esc_html_e( 'Acepto recibir emails académicos relacionados con mis cursos (obligatorio)', 'atora-lms' ); ?>
		</label>
	</p>

	<!-- Opcionales -->
	<p>
		<label>
			<input type="checkbox" name="atora_consent_marketing" value="1"
			       <?php checked( ! empty( $_POST['atora_consent_marketing'] ) ); ?>>
			<?php esc_html_e( 'Deseo recibir ofertas y promociones por email (opcional)', 'atora-lms' ); ?>
		</label>
	</p>

	<p>
		<label>
			<input type="checkbox" name="atora_consent_whatsapp" value="1"
			       <?php checked( ! empty( $_POST['atora_consent_whatsapp'] ) ); ?>>
			<?php esc_html_e( 'Acepto recibir notificaciones por WhatsApp (opcional)', 'atora-lms' ); ?>
		</label>
	</p>

	<p>
		<label>
			<input type="checkbox" name="atora_consent_telegram" value="1"
			       <?php checked( ! empty( $_POST['atora_consent_telegram'] ) ); ?>>
			<?php esc_html_e( 'Acepto recibir notificaciones por Telegram (opcional)', 'atora-lms' ); ?>
		</label>
	</p>
</div><!-- .atora-gdpr-consents -->
