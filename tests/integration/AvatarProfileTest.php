<?php
/**
 * Integración: Perfil (bloque 1) — avatar propio sin Gravatar.
 */

declare( strict_types = 1 );

final class AvatarProfileTest extends WP_UnitTestCase {
	private int $student = 0;
	private int $admin = 0;
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		do_action( 'rest_api_init', rest_get_server() );
		$this->student = self::factory()->user->create( array( 'role' => 'subscriber', 'display_name' => 'Julia Rojas', 'user_email' => 'avatar.' . wp_generate_password( 6, false ) . '@escuela.test' ) );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->token = ATORA_Mobile_Token_Service::issue( $this->student, 'test' )['access_token'];
		delete_option( ATORA_Avatar_Service::OPTION_POLICY );
	}

	protected function tearDown(): void {
		delete_option( ATORA_Avatar_Service::OPTION_POLICY );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function make_jpeg_with_exif_marker(): string {
		$tmp = wp_tempnam( 'atora-avatar-test' );
		if ( ! $tmp ) {
			self::fail( 'No tmp file.' );
		}
		$img = imagecreatetruecolor( 800, 600 );
		$bg  = imagecolorallocate( $img, 10, 90, 140 );
		imagefilledrectangle( $img, 0, 0, 800, 600, $bg );
		imagejpeg( $img, $tmp, 92 );
		imagedestroy( $img );

		$jpg = file_get_contents( $tmp );
		$this->assertNotFalse( $jpg );
		$this->assertStringStartsWith( "\xFF\xD8", $jpg );
		$payload = "Exif\x00\x00" . str_repeat( "X", 24 );
		$len = strlen( $payload ) + 2;
		$segment = "\xFF\xE1" . pack( 'n', $len ) . $payload;
		$with = substr( $jpg, 0, 2 ) . $segment . substr( $jpg, 2 );
		file_put_contents( $tmp, $with );
		$this->assertStringContainsString( 'Exif', (string) file_get_contents( $tmp ) );
		return $tmp;
	}

	private function rest_upload( string $path, string $client_upload_id = '' ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/atora-mobile/v1/me/avatar' );
		$request->set_header( 'Authorization', 'Bearer ' . $this->token );
		if ( '' !== $client_upload_id ) {
			$request->set_param( 'client_upload_id', $client_upload_id );
		}
		$request->set_file_params( array(
			'file' => array(
				'name'     => basename( $path ) . '.jpg',
				'tmp_name' => $path,
				'size'     => filesize( $path ) ?: 0,
				'error'    => 0,
				'type'     => 'image/jpeg',
			),
		) );
		return rest_ensure_response( rest_get_server()->dispatch( $request ) );
	}

	public function test_valid_upload_is_stored_as_attachment_and_used_by_get_avatar(): void {
		$path = $this->make_jpeg_with_exif_marker();
		$response = $this->rest_upload( $path, 'c1' );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['avatar_id'] );
		$this->assertNotEmpty( $data['avatar_url'] );
		$this->assertSame( (int) $data['avatar_id'], ATORA_Avatar_Service::avatar_attachment_id( $this->student ) );

		$url = (string) get_avatar_url( $this->student, array( 'size' => 96 ) );
		$this->assertStringNotContainsString( 'gravatar.com', $url );
		$this->assertNotEmpty( $url );

		$me = rest_get_server()->dispatch( ( new WP_REST_Request( 'GET', '/atora-mobile/v1/me' ) )->set_header( 'Authorization', 'Bearer ' . $this->token ) );
		$payload = rest_ensure_response( $me )->get_data();
		$this->assertSame( (string) $data['avatar_url'], (string) $payload['user']['avatar_url'] );
		$this->assertArrayHasKey( 'avatar_initials', $payload['user'] );
		$this->assertArrayHasKey( 'avatar_color', $payload['user'] );
	}

	public function test_disguised_file_is_rejected_and_does_not_create_attachment(): void {
		$before = count( get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ) ) );
		$tmp = wp_tempnam( 'atora-avatar-fake' );
		$this->assertNotFalse( $tmp );
		file_put_contents( $tmp, 'not an image' );
		$response = $this->rest_upload( $tmp, 'bad1' );
		$this->assertSame( 400, $response->get_status() );
		$after = count( get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ) ) );
		$this->assertSame( $before, $after );
		$this->assertSame( 0, ATORA_Avatar_Service::avatar_attachment_id( $this->student ) );
	}

	public function test_exif_is_removed_by_processing(): void {
		$path = $this->make_jpeg_with_exif_marker();
		$response = $this->rest_upload( $path, 'exif1' );
		$this->assertSame( 200, $response->get_status() );
		$attach_id = (int) $response->get_data()['avatar_id'];
		$file = get_attached_file( $attach_id );
		$this->assertNotEmpty( $file );
		$bytes = file_get_contents( (string) $file );
		$this->assertNotFalse( $bytes );
		$this->assertStringNotContainsString( 'Exif', (string) $bytes );
	}

	public function test_retry_with_same_client_upload_id_does_not_duplicate(): void {
		$path = $this->make_jpeg_with_exif_marker();
		$r1 = $this->rest_upload( $path, 'same' );
		$this->assertSame( 200, $r1->get_status() );
		$id1 = (int) $r1->get_data()['avatar_id'];
		$this->assertGreaterThan( 0, $id1 );
		$before = count( get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ) ) );

		$r2 = $this->rest_upload( $path, 'same' );
		$this->assertSame( 200, $r2->get_status() );
		$this->assertSame( $id1, (int) $r2->get_data()['avatar_id'] );
		$after = count( get_posts( array( 'post_type' => 'attachment', 'fields' => 'ids', 'posts_per_page' => 500, 'no_found_rows' => true ) ) );
		$this->assertSame( $before, $after );
	}

	public function test_admin_only_policy_blocks_student_upload(): void {
		update_option( ATORA_Avatar_Service::OPTION_POLICY, ATORA_Avatar_Service::POLICY_ADMIN_ONLY, false );
		$path = $this->make_jpeg_with_exif_marker();
		$response = $this->rest_upload( $path, 'p1' );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_get_avatar_never_outputs_gravatar_urls(): void {
		$html = (string) get_avatar( $this->student, 96 );
		$this->assertStringNotContainsString( 'gravatar.com', $html );
		$url = (string) get_avatar_url( $this->student, array( 'size' => 96 ) );
		$this->assertStringNotContainsString( 'gravatar.com', $url );

		$me = rest_get_server()->dispatch( ( new WP_REST_Request( 'GET', '/atora-mobile/v1/me' ) )->set_header( 'Authorization', 'Bearer ' . $this->token ) );
		$payload = rest_ensure_response( $me )->get_data();
		$this->assertStringNotContainsString( 'gravatar.com', (string) $payload['user']['avatar_url'] );
	}

	public function test_avatar_is_deleted_on_account_deletion_process(): void {
		$path = $this->make_jpeg_with_exif_marker();
		$r = $this->rest_upload( $path, 'del1' );
		$this->assertSame( 200, $r->get_status() );
		$attach_id = (int) $r->get_data()['avatar_id'];
		$this->assertSame( 'attachment', get_post_type( $attach_id ) );

		$request = ATORA_Account_Deletion::request( $this->student, 'web' );
		$this->assertTrue( ATORA_Account_Deletion::process( (int) $request['request_id'], 'anonymize', $this->admin ) );
		$this->assertSame( 0, ATORA_Avatar_Service::avatar_attachment_id( $this->student ) );
		$this->assertFalse( get_post( $attach_id ) );
	}
}

