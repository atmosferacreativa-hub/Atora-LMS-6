<?php
/**
 * Onboarding Wizard — ATORA LMS (Sprint S18), + perfil de
 * instalación como paso 1 desde 6.3.0 (PT-3.2).
 *
 * 5 pasos en admin para configuración inicial:
 *   Paso 1: Perfil de instalación (docente / institución / creadores / academia)
 *   Paso 2: Tu academia (nombre, logo, zona horaria)
 *   Paso 3: Email (provider SMTP/SES/SendGrid/Brevo)
 *   Paso 4: Primer contacto (importación manual)
 *   Paso 5: Primera campaña (asunto + mensaje + lanzar)
 *
 * Se muestra solo si get_option('atora_onboarding_complete') !== '1'.
 *
 * @package ATORA_LMS
 * @since   6.0.0
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ATORA_Onboarding_Wizard {

	const OPTION_COMPLETE = 'atora_onboarding_complete';
	const OPTION_STEP     = 'atora_onboarding_step';
	const OPTION_ACADEMY  = 'atora_academy_settings';
	const PAGE_SLUG       = 'atora-onboarding';

	public static function init(): void {
		if ( get_option( self::OPTION_COMPLETE ) === '1' ) { return; }

		// El menú padre clms-dashboard se registra en prioridad 10. Registrar después
		// mantiene estable el hook interno que WordPress usa para autorizar la página.
		add_action( 'admin_menu',            array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_init',            array( __CLASS__, 'handle_form' ) );
		add_action( 'admin_notices',         array( __CLASS__, 'show_notice' ) );
		add_action( 'wp_ajax_atora_onboarding_skip', array( __CLASS__, 'ajax_skip' ) );
	}

	/** Registra la página admin del wizard. */
	public static function register_page(): void {
		add_submenu_page(
			'clms-dashboard',
			__( 'Configuración inicial', 'atora-lms' ),
			__( '🚀 Configuración inicial', 'atora-lms' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/** Notice que aparece en todas las páginas admin mientras no se completa. */
	public static function show_notice(): void {
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, self::PAGE_SLUG ) ) { return; }
		?>
		<div class="notice notice-info" style="display:flex;align-items:center;gap:12px;padding:12px 16px">
			<span style="font-size:20px">🚀</span>
			<div>
				<strong><?php printf( esc_html__( '¡Bienvenido a ATORA LMS %s!', 'atora-lms' ), esc_html( ATORA_LMS_VERSION ) ); ?></strong>
				<?php esc_html_e( 'Completa la configuración inicial para sacar el máximo partido.', 'atora-lms' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"
				   style="margin-left:10px;font-weight:600">
					<?php esc_html_e( 'Iniciar setup →', 'atora-lms' ); ?>
				</a>
			</div>
			<button type="button" id="atora-dismiss-onboarding"
				style="margin-left:auto;background:none;border:.5px solid #e2e8f0;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;color:#64748b">
				<?php esc_html_e( 'Lo haré después', 'atora-lms' ); ?>
			</button>
		</div>
		<script>
		document.getElementById('atora-dismiss-onboarding')?.addEventListener('click',function(){
			this.closest('.notice').style.display='none';
			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',{
				method:'POST',
				body:new URLSearchParams({action:'atora_onboarding_skip',nonce:'<?php echo esc_js( wp_create_nonce( 'atora_onboarding_skip' ) ); ?>'}),
				credentials:'same-origin'
			});
		});
		</script>
		<?php
	}

	/** AJAX: marcar como completo (skip). */
	public static function ajax_skip(): void {
		check_ajax_referer( 'atora_onboarding_skip', 'nonce' );
		if ( current_user_can( 'manage_options' ) ) {
			update_option( self::OPTION_COMPLETE, '1' );
		}
		wp_send_json_success();
	}

	/** Maneja los POSTs de cada paso. */
	public static function handle_form(): void {
		if ( ! isset( $_POST['atora_onboarding_step'], $_POST['_wpnonce'] ) ) { return; }
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'atora_onboarding' ) ) { return; }

		$step = absint( $_POST['atora_onboarding_step'] );

		switch ( $step ) {
			case 1:
				self::save_step_profile();
				break;
			case 2:
				self::save_step1();
				break;
			case 3:
				self::save_step2();
				break;
			case 4:
				self::save_step3();
				break;
			case 5:
				self::save_step4();
				return; // redirect handled inside
		}

		$next = min( 5, $step + 1 );
		update_option( self::OPTION_STEP, $next );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . $next ) );
		exit;
	}

	/** Paso 1: perfil de instalación (PT-3.2). */
	private static function save_step_profile(): void {
		if ( ! class_exists( 'CLMS_Install_Profiles' ) ) { return; }
		$profile = sanitize_key( (string) wp_unslash( $_POST['install_profile'] ?? 'academia' ) );
		if ( ! isset( CLMS_Install_Profiles::get_profiles()[ $profile ] ) ) {
			$profile = 'academia';
		}
		CLMS_Install_Profiles::apply( $profile );

		// P3 (6.12.0): el perfil elegido acá puede activar módulos que la
		// activación (que ya aplicó 'docente' como default) no había
		// creado todavía — dbDelta() es idempotente, así que esto no hace
		// nada si el perfil elegido es 'docente'.
		if ( class_exists( '\ATORA\V5_Installer' ) ) {
			\ATORA\V5_Installer::ensure_active_module_tables();
		}
	}

	/** Paso 2: academia. */
	private static function save_step1(): void {
		$academy = array(
			'name'     => sanitize_text_field( (string) wp_unslash( $_POST['academy_name'] ?? '' ) ),
			'timezone' => sanitize_text_field( (string) wp_unslash( $_POST['academy_timezone'] ?? 'UTC' ) ),
			'logo_url' => esc_url_raw( (string) wp_unslash( $_POST['academy_logo'] ?? '' ) ),
		);
		update_option( self::OPTION_ACADEMY, $academy );
	}

	/** Paso 2: email provider. */
	private static function save_step2(): void {
		$provider = sanitize_key( (string) wp_unslash( $_POST['email_provider'] ?? 'smtp' ) );
		$host     = sanitize_text_field( (string) wp_unslash( $_POST['smtp_host']   ?? '' ) );
		$port     = absint( $_POST['smtp_port'] ?? 587 );
		$user     = sanitize_text_field( (string) wp_unslash( $_POST['smtp_user']   ?? '' ) );
		$from     = sanitize_email( (string) wp_unslash( $_POST['from_email'] ?? '' ) );

		update_option( 'atora_email_provider', $provider );
		update_option( 'atora_email_smtp_host', $host );
		update_option( 'atora_email_smtp_port', $port );
		update_option( 'atora_email_smtp_user', $user );
		update_option( 'atora_email_from',      $from );
	}

	/** Paso 3: primer contacto. */
	private static function save_step3(): void {
		$name    = sanitize_text_field( (string) wp_unslash( $_POST['contact_name']    ?? '' ) );
		$email   = sanitize_email(      (string) wp_unslash( $_POST['contact_email']   ?? '' ) );
		$company = sanitize_text_field( (string) wp_unslash( $_POST['contact_company'] ?? '' ) );

		if ( ! $email ) {
			return;
		}

		$contact_id     = 0;
		$contact_service = '\\ATORA\\CRM_V2\\Services\\Contact_Service';

		if ( class_exists( $contact_service ) && method_exists( $contact_service, 'create_lead_quick' ) ) {
			$contact_id = $contact_service::create_lead_quick(
				array(
					'name'     => $name,
					'email'    => $email,
					'interest' => '',
					'source'   => 'onboarding',
				)
			);
		} elseif ( class_exists( '\\ATORA\\CRM\\CRM' ) && method_exists( '\\ATORA\\CRM\\CRM', 'upsert_contact' ) ) {
			$contact_id = \\ATORA\\CRM\\CRM::upsert_contact(
				array(
					'name'   => $name,
					'email'  => $email,
					'status' => 'lead',
					'source' => 'onboarding',
				)
			);
		}

		$company_service = '\\ATORA\\CRM_V2\\Services\\Company_Service';
		if (
			$contact_id > 0
			&& '' !== $company
			&& class_exists( $company_service )
			&& method_exists( $company_service, 'create' )
			&& method_exists( $company_service, 'assign_contact' )
		) {
			$company_id = $company_service::create( array( 'name' => $company ) );
			if ( $company_id > 0 ) {
				$company_service::assign_contact( $company_id, $contact_id );
			}
		}
	}

	/** Paso 4: primera campaña. */
	private static function save_step4(): void {
		$subject = sanitize_text_field( (string) wp_unslash( $_POST['campaign_subject'] ?? '' ) );
		$message = sanitize_textarea_field( (string) wp_unslash( $_POST['campaign_message'] ?? '' ) );
		$launch  = ! empty( $_POST['campaign_launch'] );

		if ( $subject && class_exists( '\ATORA\CRM_V2\Services\Campaign_Service' ) ) {
			$id = \ATORA\CRM_V2\Services\Campaign_Service::create_campaign( array(
				'name'    => $subject,
				'subject' => $subject,
				'message' => $message,
				'channel' => 'email',
				'status'  => 'draft',
			) );

			if ( $id && $launch ) {
				\ATORA\CRM_V2\Services\Campaign_Service::launch_campaign( $id, 'immediate' );
			}
		}

		update_option( self::OPTION_COMPLETE, '1' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=done' ) );
		exit;
	}

	/** Renderiza el wizard completo. */
	public static function render(): void {
		$current_step = absint( $_GET['step'] ?? get_option( self::OPTION_STEP, 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_done      = isset( $_GET['step'] ) && 'done' === $_GET['step']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$academy      = (array) get_option( self::OPTION_ACADEMY, array() );

		$steps = array(
			1 => array( 'icon' => '🧩', 'label' => __( 'Perfil', 'atora-lms' ) ),
			2 => array( 'icon' => '🏫', 'label' => __( 'Tu academia', 'atora-lms' ) ),
			3 => array( 'icon' => '📧', 'label' => __( 'Email', 'atora-lms' ) ),
			4 => array( 'icon' => '👤', 'label' => __( 'Primer contacto', 'atora-lms' ) ),
			5 => array( 'icon' => '📣', 'label' => __( 'Primera campaña', 'atora-lms' ) ),
		);
		?>
		<div class="wrap" style="max-width:680px;font-family:sans-serif">

			<h1 style="font-size:22px;font-weight:800;margin-bottom:4px">
				🚀 <?php printf( esc_html__( 'Configuración inicial — ATORA LMS %s', 'atora-lms' ), esc_html( ATORA_LMS_VERSION ) ); ?>
			</h1>
			<p style="color:#64748b;margin-top:0;font-size:13px">
				<?php esc_html_e( 'Completa los 5 pasos para dejar tu academia lista en minutos.', 'atora-lms' ); ?>
			</p>

			<!-- Indicador de pasos -->
			<div style="display:flex;gap:0;margin-bottom:28px;border-radius:10px;overflow:hidden;border:.5px solid #e2e8f0">
			<?php foreach ( $steps as $n => $info ) :
				$active  = ( ! $is_done && $n === $current_step );
				$done    = ( $is_done || $n < $current_step );
				$bg      = $active ? '#1d4ed8' : ( $done ? '#1d9e75' : '#f8fafc' );
				$color   = ( $active || $done ) ? '#fff' : '#94a3b8';
			?>
				<div style="flex:1;padding:10px;text-align:center;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $color ); ?>;font-size:12px;font-weight:600;border-right:.5px solid #e2e8f0">
					<?php echo esc_html( $info['icon'] . ' ' . $info['label'] ); ?>
					<?php if ( $done && ! $active ) : ?> ✓<?php endif; ?>
				</div>
			<?php endforeach; ?>
			</div>

			<!-- Contenido del paso -->
			<div style="background:#fff;border:.5px solid #e2e8f0;border-radius:14px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.05)">

			<?php if ( $is_done ) : ?>

				<div style="text-align:center;padding:2rem 0">
					<div style="font-size:48px;margin-bottom:12px">🎉</div>
					<h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0 0 8px">
						<?php esc_html_e( '¡Tu academia está lista!', 'atora-lms' ); ?>
					</h2>
					<p style="color:#64748b;font-size:14px;margin:0 0 24px">
						<?php printf( esc_html__( 'Has completado la configuración inicial de ATORA LMS %s.', 'atora-lms' ), esc_html( ATORA_LMS_VERSION ) ); ?>
					</p>
					<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=atora-crm-v2' ) ); ?>"
						   class="button button-primary" style="padding:10px 20px;font-size:14px">
							<?php esc_html_e( '→ Ir al CRM', 'atora-lms' ); ?>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=clms-academic-hub' ) ); ?>"
						   class="button" style="padding:10px 20px;font-size:14px">
							<?php esc_html_e( '→ Academia', 'atora-lms' ); ?>
						</a>
					</div>
				</div>

			<?php else :
				$nonce_field = wp_nonce_field( 'atora_onboarding', '_wpnonce', true, false );
			?>

			<form method="post" action="">
				<?php echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="atora_onboarding_step" value="<?php echo esc_attr( (string) $current_step ); ?>">

				<?php if ( 1 === $current_step ) : ?>
				<!-- PASO 1: Perfil de instalación (PT-3.2) -->
				<h2 style="font-size:18px;font-weight:700;margin:0 0 8px">🧩 <?php esc_html_e( '¿Qué tipo de instalación es esta?', 'atora-lms' ); ?></h2>
				<p style="color:#64748b;font-size:13px;margin:0 0 20px"><?php esc_html_e( 'Puedes cambiarlo después desde ATORA → Módulos. Elegir un perfil solo activa/desactiva módulos — no borra datos.', 'atora-lms' ); ?></p>
				<div style="display:grid;gap:12px">
					<?php
					$profiles     = class_exists( 'CLMS_Install_Profiles' ) ? CLMS_Install_Profiles::get_profiles() : array();
					$saved_profile = class_exists( 'CLMS_Install_Profiles' ) ? CLMS_Install_Profiles::current() : 'academia';
					foreach ( $profiles as $key => $def ) :
					?>
					<label style="display:flex;gap:12px;align-items:flex-start;padding:14px;border:.5px solid #e2e8f0;border-radius:10px;cursor:pointer">
						<input type="radio" name="install_profile" value="<?php echo esc_attr( $key ); ?>" <?php checked( $saved_profile, $key ); ?> style="margin-top:3px">
						<span>
							<strong style="display:block;font-size:14px;color:#0f172a"><?php echo esc_html( $def['label'] ); ?></strong>
							<span style="display:block;font-size:12px;color:#64748b;margin-top:2px"><?php echo esc_html( $def['description'] ); ?></span>
						</span>
					</label>
					<?php endforeach; ?>
				</div>

				<?php elseif ( 2 === $current_step ) : ?>
				<!-- PASO 2: Tu academia -->
				<h2 style="font-size:18px;font-weight:700;margin:0 0 20px">🏫 <?php esc_html_e( 'Tu academia', 'atora-lms' ); ?></h2>
				<div style="display:grid;gap:16px">
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Nombre de la academia *', 'atora-lms' ); ?>
						<input type="text" name="academy_name" required
							value="<?php echo esc_attr( (string) ( $academy['name'] ?? '' ) ); ?>"
							placeholder="<?php esc_attr_e( 'Ej: Academia Digital Pro', 'atora-lms' ); ?>"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'URL del logo (opcional)', 'atora-lms' ); ?>
						<input type="url" name="academy_logo"
							value="<?php echo esc_attr( (string) ( $academy['logo_url'] ?? '' ) ); ?>"
							placeholder="https://..."
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Zona horaria', 'atora-lms' ); ?>
						<select name="academy_timezone" style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
							<?php
							$tz_saved = (string) ( $academy['timezone'] ?? 'America/Mexico_City' );
							$timezones = array(
								'America/Mexico_City', 'America/Bogota', 'America/Lima',
								'America/Santiago', 'America/Buenos_Aires', 'America/Caracas',
								'America/New_York', 'America/Los_Angeles', 'Europe/Madrid', 'UTC',
							);
							foreach ( $timezones as $tz ) {
								printf( '<option value="%s"%s>%s</option>',
									esc_attr( $tz ),
									selected( $tz_saved, $tz, false ),
									esc_html( $tz )
								);
							}
							?>
						</select>
					</label>
				</div>

				<?php elseif ( 3 === $current_step ) : ?>
				<!-- PASO 3: Email provider -->
				<h2 style="font-size:18px;font-weight:700;margin:0 0 20px">📧 <?php esc_html_e( 'Configuración de Email', 'atora-lms' ); ?></h2>
				<div style="display:grid;gap:16px">
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Provider de email', 'atora-lms' ); ?>
						<select name="email_provider" id="atora-email-provider"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
							<option value="smtp"><?php esc_html_e( 'SMTP propio', 'atora-lms' ); ?></option>
							<option value="ses"><?php esc_html_e( 'Amazon SES', 'atora-lms' ); ?></option>
							<option value="sendgrid"><?php esc_html_e( 'SendGrid', 'atora-lms' ); ?></option>
							<option value="brevo"><?php esc_html_e( 'Brevo (ex-Sendinblue)', 'atora-lms' ); ?></option>
						</select>
					</label>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Host SMTP / API endpoint', 'atora-lms' ); ?>
						<input type="text" name="smtp_host"
							placeholder="smtp.gmail.com"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
					<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
						<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
							<?php esc_html_e( 'Puerto', 'atora-lms' ); ?>
							<input type="number" name="smtp_port" value="587" min="25" max="65535"
								style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
						</label>
						<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
							<?php esc_html_e( 'Usuario / API Key', 'atora-lms' ); ?>
							<input type="text" name="smtp_user"
								style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
						</label>
					</div>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Email remitente (From)', 'atora-lms' ); ?>
						<input type="email" name="from_email"
							placeholder="hola@tuacademia.com"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
				</div>

				<?php elseif ( 4 === $current_step ) : ?>
				<!-- PASO 4: Primer contacto -->
				<h2 style="font-size:18px;font-weight:700;margin:0 0 8px">👤 <?php esc_html_e( 'Añade tu primer contacto', 'atora-lms' ); ?></h2>
				<p style="color:#64748b;font-size:13px;margin:0 0 20px"><?php esc_html_e( 'Importa un lead o estudiante para probar el sistema.', 'atora-lms' ); ?></p>
				<div style="display:grid;gap:16px">
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Nombre completo *', 'atora-lms' ); ?>
						<input type="text" name="contact_name" required
							placeholder="<?php esc_attr_e( 'Ej: María García', 'atora-lms' ); ?>"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Email *', 'atora-lms' ); ?>
						<input type="email" name="contact_email" required
							placeholder="maria@empresa.com"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Empresa (opcional)', 'atora-lms' ); ?>
						<input type="text" name="contact_company"
							placeholder="<?php esc_attr_e( 'Nombre de la empresa', 'atora-lms' ); ?>"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
				</div>

				<?php elseif ( 5 === $current_step ) : ?>
				<!-- PASO 5: Primera campaña -->
				<h2 style="font-size:18px;font-weight:700;margin:0 0 8px">📣 <?php esc_html_e( 'Lanza tu primera campaña', 'atora-lms' ); ?></h2>
				<p style="color:#64748b;font-size:13px;margin:0 0 20px"><?php esc_html_e( 'Crea un email rápido y envíalo a tus contactos actuales.', 'atora-lms' ); ?></p>
				<div style="display:grid;gap:16px">
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Asunto del email *', 'atora-lms' ); ?>
						<input type="text" name="campaign_subject" required
							placeholder="<?php esc_attr_e( 'Bienvenido a nuestra academia', 'atora-lms' ); ?>"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px">
					</label>
					<label style="font-size:13px;font-weight:600;color:#0f172a;display:block">
						<?php esc_html_e( 'Mensaje', 'atora-lms' ); ?>
						<textarea name="campaign_message" rows="5"
							placeholder="<?php esc_attr_e( 'Escribe el cuerpo del email aquí...', 'atora-lms' ); ?>"
							style="display:block;width:100%;margin-top:6px;padding:9px 12px;border-radius:8px;border:.5px solid #e2e8f0;font-size:14px;resize:vertical"></textarea>
					</label>
					<label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;cursor:pointer">
						<input type="checkbox" name="campaign_launch" value="1" style="width:16px;height:16px">
						<?php esc_html_e( 'Lanzar ahora (envío inmediato a todos los contactos)', 'atora-lms' ); ?>
					</label>
				</div>
				<?php endif; ?>

				<!-- Navegación -->
				<div style="display:flex;justify-content:space-between;align-items:center;margin-top:24px;padding-top:16px;border-top:.5px solid #e2e8f0">
					<?php if ( $current_step > 1 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . ( $current_step - 1 ) ) ); ?>"
					   style="color:#64748b;font-size:13px;text-decoration:none">← <?php esc_html_e( 'Anterior', 'atora-lms' ); ?></a>
					<?php else : ?>
					<div></div>
					<?php endif; ?>

					<div style="display:flex;gap:10px">
						<?php if ( $current_step < 5 && 1 !== $current_step ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . ( $current_step + 1 ) ) ); ?>"
						   style="color:#64748b;font-size:13px;text-decoration:none;padding:8px 14px">
							<?php esc_html_e( 'Omitir paso', 'atora-lms' ); ?>
						</a>
						<?php endif; ?>
						<button type="submit" class="button button-primary"
							style="padding:9px 24px;font-size:14px;font-weight:600;border-radius:8px;height:auto">
							<?php echo 5 === $current_step
								? esc_html__( '🎉 Finalizar setup', 'atora-lms' )
								: esc_html__( 'Continuar →', 'atora-lms' );
							?>
						</button>
					</div>
				</div>
			</form>

			<?php endif; ?>
			</div><!-- /card -->
		</div>
		<?php
	}
}
