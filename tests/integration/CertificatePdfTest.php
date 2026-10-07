<?php
/**
 * Integración 6.33.0: certificado institucional en PDF y verificación pública.
 *
 * - El PDF se genera con el logo y las tres firmas de la plantilla.
 * - El QR lleva a la verificación correcta (`/verificar/{codigo}`).
 * - Un código inventado da "No encontrado"; uno revocado, solo "Revocado".
 * - La verificación muestra el nombre del certificado y nunca el correo.
 * - Un certificado anterior se regenera en PDF conservando su fecha de emisión.
 * - La API móvil entrega el PDF con `format=pdf` y lo declara en /discovery.
 */

declare( strict_types = 1 );

final class CertificatePdfTest extends WP_UnitTestCase {

	private int $student = 0;
	private int $course = 0;
	private string $email = '';
	private array $images = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		$this->email   = 'cert.' . wp_generate_password( 6, false ) . '@escuela.test';
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'María Fernández', 'user_email' => $this->email ) );
		$this->course  = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_title' => 'Química General' ) );
		// Cuatro imágenes distintas con transparencia: logo y tres firmas.
		foreach ( array( array( 30, 60, 200 ), array( 200, 40, 40 ), array( 20, 140, 60 ), array( 90, 90, 90 ) ) as $i => $rgb ) {
			$image = imagecreatetruecolor( 160, 60 );
			imagesavealpha( $image, true );
			imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
			imagefilledellipse( $image, 80, 30, 140, 40, imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] ) );
			ob_start();
			imagepng( $image );
			$upload         = wp_upload_bits( 'cert-test-' . $i . '-' . wp_generate_password( 4, false ) . '.png', null, (string) ob_get_clean() );
			$this->images[] = (int) wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'img' . $i ), $upload['file'] );
		}
		ATORA_Certificate_Template::save( array(
			'logo_id'    => $this->images[3],
			'title'      => 'Certificado',
			'show_hours' => 1,
			'show_date'  => 1,
			'signatures' => array(
				array( 'image_id' => $this->images[0], 'name' => 'Ana Ruiz', 'role' => 'Directora' ),
				array( 'image_id' => $this->images[1], 'name' => 'Luis Paz', 'role' => 'Coordinador' ),
				array( 'image_id' => $this->images[2], 'name' => 'Eva Sol', 'role' => 'Secretaria' ),
			),
		) );
	}

	protected function tearDown(): void {
		delete_option( ATORA_Certificate_Template::OPTION );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_credential_revocations" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_credentials" );
		parent::tearDown();
	}

	private function resolved( string $issued_at = '2025-03-01 10:00:00' ): array {
		return array(
			'target_type' => 'course',
			'course_id'   => $this->course,
			'program_id'  => 0,
			'record'      => array(
				'certificate_code' => 'ATORA-2025-000123',
				'student_name'     => 'María Fernández',
				'target_title'     => 'Química General',
				'academic_hours'   => 40,
				'issued_at'        => $issued_at,
				'academy'          => 'Academia de Prueba',
				'status'           => 'valid',
			),
		);
	}

	private function credential_uuid(): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT credential_uuid FROM {$wpdb->prefix}atora_credentials WHERE user_id = %d", $this->student ) );
	}

	public function test_pdf_has_logo_and_three_signatures_and_qr_points_to_verification(): void {
		$this->assertTrue( ATORA_Certificate_PDF::available() );
		$pdf = ATORA_Certificate_PDF::for_user( $this->student, $this->resolved() );
		$this->assertIsString( $pdf );
		$this->assertStringStartsWith( '%PDF-', $pdf );
		$this->assertSame( 4, preg_match_all( '#/Subtype\s*/Image#', $pdf ), 'Logo y tres firmas.' );

		$uuid = $this->credential_uuid();
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $uuid );
		$this->set_permalink_structure( '/%postname%/' );
		$data = ATORA_Certificate_PDF::data( $this->student, 'course', $this->course, $this->resolved()['record'], $uuid );
		$html = ATORA_Certificate_PDF::html( $data );
		foreach ( array( 'Ana Ruiz', 'Luis Paz', 'Eva Sol', 'Directora', 'María Fernández', 'Química General', '40 horas académicas', 'ATORA-2025-000123' ) as $text ) {
			$this->assertStringContainsString( $text, $html );
		}
		$this->assertStringNotContainsString( $this->email, $html );

		// El QR embebido codifica la URL de verificación: se lee el mismo QR en PNG.
		$this->set_permalink_structure( '/%postname%/' );
		$url = ATORA_Certificate_Verify::url( $uuid );
		$this->assertSame( home_url( '/verificar/' . $uuid . '/' ), $url );
		$this->go_to( $url );
		$this->assertSame( $uuid, get_query_var( ATORA_Certificate_Verify::QUERY_VAR ), 'La URL del QR lleva a la página de verificación.' );
		$this->assertStringContainsString( ATORA_Certificate_PDF::qr_data_uri( $url ), $html );
		$png = new \chillerlan\QRCode\QRCode( new \chillerlan\QRCode\QROptions( array( 'outputType' => \chillerlan\QRCode\Output\QROutputInterface::GDIMAGE_PNG, 'outputBase64' => false, 'scale' => 6, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M ) ) );
		$this->assertSame( $url, (string) ( new \chillerlan\QRCode\QRCode() )->readFromBlob( $png->render( $url ) ) );

		// Y esa URL verifica como válido.
		$result = ATORA_Certificate_Verify::lookup( $uuid );
		$this->assertSame( 'valid', $result['state'] );
		$this->assertSame( 'María Fernández', $result['holder'] );
		$this->assertSame( 'Química General', $result['achievement'] );
	}

	public function test_verification_shows_frozen_name_never_email_and_original_date(): void {
		ATORA_Certificate_PDF::for_user( $this->student, $this->resolved( '2024-11-20 09:00:00' ) );
		// El estudiante cambia su nombre después: el certificado y la verificación conservan el original.
		wp_update_user( array( 'ID' => $this->student, 'display_name' => 'Otro Nombre' ) );
		$result  = ATORA_Certificate_Verify::lookup( $this->credential_uuid() );
		$content = ATORA_Certificate_Verify::content( $result );
		$this->assertStringContainsString( 'Válido', $content );
		$this->assertStringContainsString( 'María Fernández', $content );
		$this->assertStringNotContainsString( 'Otro Nombre', $content );
		$this->assertStringNotContainsString( $this->email, $content );
		$this->assertStringNotContainsString( '@', $content, 'Ningún correo en la verificación.' );
		$this->assertSame( '2024-11-20', substr( $result['issued_at'], 0, 10 ), 'Conserva la fecha de emisión original.' );
	}

	public function test_invented_code_is_not_found(): void {
		foreach ( array( wp_generate_uuid4(), 'ABCDEFGHIJ1234567890', 'x' ) as $code ) {
			$result = ATORA_Certificate_Verify::lookup( $code );
			$this->assertSame( 'not_found', $result['state'], $code );
			$this->assertStringContainsString( 'No encontrado', ATORA_Certificate_Verify::content( $result ) );
		}
	}

	public function test_revoked_certificate_shows_only_revoked(): void {
		ATORA_Certificate_PDF::for_user( $this->student, $this->resolved() );
		global $wpdb;
		$credential_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}atora_credentials WHERE user_id = %d", $this->student ) );
		$requester     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$approver      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$service       = new CLMS_Credential_Service();
		$request       = $service->request_revocation( $credential_id, 'administrative', 'Error administrativo en la emisión del certificado.', $requester );
		$this->assertIsArray( $request );
		$this->assertSame( 'valid', ATORA_Certificate_Verify::lookup( $this->credential_uuid() )['state'], 'Solicitada y sin decidir: sigue válido.' );
		$service->decide_revocation( $request['request_id'], CLMS_Credential_Policy::DECISION_APPROVED, $approver );

		$result  = ATORA_Certificate_Verify::lookup( $this->credential_uuid() );
		$content = ATORA_Certificate_Verify::content( $result );
		$this->assertSame( array( 'state' => 'revoked' ), $result, 'Sin datos adicionales.' );
		$this->assertStringContainsString( 'Revocado', $content );
		$this->assertStringNotContainsString( 'María', $content );
		$this->assertStringNotContainsString( 'Química', $content );
		$this->assertWPError( ATORA_Certificate_PDF::for_user( $this->student, $this->resolved() ), 'Un certificado revocado no se descarga.' );
	}

	public function test_template_change_regenerates_the_pdf(): void {
		$first = ATORA_Certificate_PDF::for_user( $this->student, $this->resolved() );
		$this->assertSame( $first, ATORA_Certificate_PDF::for_user( $this->student, $this->resolved() ), 'Guardado: la segunda vez no se genera de nuevo.' );
		ATORA_Certificate_Template::save( array_merge( ATORA_Certificate_Template::get(), array( 'title' => 'Diploma', 'signatures' => array( array( 'image_id' => $this->images[0], 'name' => 'Ana Ruiz', 'role' => 'Directora' ) ) ) ) );
		$second = ATORA_Certificate_PDF::for_user( $this->student, $this->resolved() );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, preg_match_all( '#/Subtype\s*/Image#', $second ), 'Logo y una firma.' );
		$uploads = wp_upload_dir( null, false );
		$this->assertCount( 1, glob( $uploads['basedir'] . '/atora-private/certificates/' . $this->credential_uuid() . '-*.pdf' ), 'La versión anterior se borra.' );
		$this->assertFileExists( $uploads['basedir'] . '/atora-private/.htaccess', 'Carpeta privada.' );
	}

	public function test_discovery_declares_certificate_pdf(): void {
		$this->assertTrue( ATORA_Mobile_REST_Controller::discovery()->get_data()['capabilities']['certificate_pdf'] );
	}
}
