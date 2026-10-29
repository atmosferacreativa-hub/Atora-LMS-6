<?php
/**
 * Integración 6.41.0: pedidos de WooCommerce por institución (varias instituciones en un mismo WordPress).
 *
 * CI no instala WooCommerce: los pedidos son objetos simulados con la misma interfaz (`get_items`, `get_meta`,
 * `update_meta_data`…). La prueba con WooCommerce real queda como validación real.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/LmsRowsCleanup.php';

final class Atora_Fake_Order_Item {
	public function __construct( private int $product, private string $name = 'Producto' ) {}
	public function get_product_id() { return $this->product; }
	public function get_variation_id() { return 0; }
	public function get_name() { return $this->name; }
}

final class Atora_Fake_Order {
	public array $meta = array();
	public int $saves  = 0;
	public function __construct( private int $id, private array $items ) {}
	public function get_id() { return $this->id; }
	public function get_items() { return $this->items; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() { ++$this->saves; }
	public function get_billing_first_name() { return 'Ana'; }
	public function get_billing_last_name() { return 'Pérez'; }
	public function get_billing_email() { return 'ana@example.test'; }
	public function get_total() { return '100.00'; }
	public function get_status() { return 'completed'; }
	public function get_date_created() { return null; }
}

final class OrderInstitutionTest extends WP_UnitTestCase {

	use LmsRowsCleanup;

	private int $inst_a = 0;
	private int $inst_b = 0;
	private int $course_a = 0;
	private int $course_b = 0;
	private int $product_a = 0;
	private int $product_b = 0;
	private int $product_plain = 0;
	private int $finance = 0;
	private int $shop_admin = 0;
	/** @var array<int,Atora_Fake_Order> */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\ATORA\\V5_Installer' ) ) {
			require_once dirname( __DIR__, 2 ) . '/modules/class-v5-installer.php';
		}
		\ATORA\V5_Installer::force_install();
		( new CLMS_DB_Migration() )->run();
		CLMS_Access::add_roles_and_caps();
		ATORA_Teacher_Scope::reset_cache();
		\ATORA\LMS\Tenant_Context::reset_cache();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('ord-a','ord-b')" );
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'ord-a', 'name' => 'A', 'status' => 'active' ) );
		$this->inst_a = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'atora_institutions', array( 'slug' => 'ord-b', 'name' => 'B', 'status' => 'active' ) );
		$this->inst_b = (int) $wpdb->insert_id;

		$teacher        = self::factory()->user->create( array( 'role' => 'lms_instructor' ) );
		$this->course_a = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $teacher ) );
		$this->course_b = self::factory()->post->create( array( 'post_type' => 'lm_course', 'post_status' => 'publish', 'post_author' => $teacher ) );
		\ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->course_a );
		\ATORA\LMS\LMS_Course_Service::get_by_wp_post( $this->course_b );
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $this->inst_a ), array( 'wp_post_id' => $this->course_a ) );
		$wpdb->update( $wpdb->prefix . 'atora_courses', array( 'institution_id' => $this->inst_b ), array( 'wp_post_id' => $this->course_b ) );

		$this->product_a     = self::factory()->post->create( array( 'post_type' => 'product', 'meta_input' => array( '_clms_linked_course_id' => $this->course_a ) ) );
		$this->product_b     = self::factory()->post->create( array( 'post_type' => 'product', 'meta_input' => array( '_clms_linked_course_id' => $this->course_b ) ) );
		$this->product_plain = self::factory()->post->create( array( 'post_type' => 'product' ) );

		$this->finance    = self::factory()->user->create( array( 'role' => 'lms_admin_assistant' ) );
		$this->shop_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_a, 'user_id' => $this->finance, 'role' => 'member', 'status' => 'active' ) );
		\ATORA\LMS\Tenant_Context::reset_cache();

		$this->orders = array(
			9001 => new Atora_Fake_Order( 9001, array( new Atora_Fake_Order_Item( $this->product_a, 'Curso A' ) ) ),
			9002 => new Atora_Fake_Order( 9002, array( new Atora_Fake_Order_Item( $this->product_b, 'Curso B' ) ) ),
			9003 => new Atora_Fake_Order( 9003, array( new Atora_Fake_Order_Item( $this->product_a ), new Atora_Fake_Order_Item( $this->product_b ) ) ),
			9004 => new Atora_Fake_Order( 9004, array( new Atora_Fake_Order_Item( $this->product_plain, 'Camiseta' ) ) ),
		);
		$orders                          = $this->orders;
		ATORA_Order_Institution::$loader = static fn( $id ) => $orders[ $id ] ?? false;
	}

	protected function tearDown(): void {
		global $wpdb;
		ATORA_Order_Institution::$loader = null;
		$wpdb->delete( $wpdb->prefix . 'atora_institution_members', array( 'user_id' => $this->finance ) );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}atora_institutions WHERE slug IN ('ord-a','ord-b')" );
		$this->cleanup_lms_rows( array( $this->course_a, $this->course_b ), array() );
		\ATORA\LMS\Tenant_Context::reset_cache();
		ATORA_Teacher_Scope::reset_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_order_takes_the_institution_of_its_course_products(): void {
		$this->assertSame( $this->inst_a, ATORA_Order_Institution::assign( $this->orders[9001] ) );
		$this->assertSame( (string) $this->inst_a, $this->orders[9001]->meta['_atora_institution_id'] );
		$this->assertSame( 1, $this->orders[9001]->saves );
		$this->assertSame( $this->inst_b, ATORA_Order_Institution::resolve( $this->orders[9002] ) );
		$this->assertSame( 0, ATORA_Order_Institution::resolve( $this->orders[9003] ), 'Mezcla de instituciones: sin institución.' );
		$this->assertSame( 0, ATORA_Order_Institution::resolve( $this->orders[9004] ), 'Sin productos ATORA: sin institución.' );

		ATORA_Order_Institution::assign_by_id( 9002 );
		$this->assertSame( (string) $this->inst_b, $this->orders[9002]->meta['_atora_institution_id'], 'Al crear/pagar por id.' );
	}

	public function test_program_product_uses_program_institution(): void {
		global $wpdb;
		$program = self::factory()->post->create( array( 'post_type' => 'lm_program', 'post_status' => 'publish' ) );
		$wpdb->insert( $wpdb->prefix . 'atora_programs', array( 'wp_post_id' => $program, 'institution_id' => $this->inst_b, 'title' => 'Prog' ) );
		$row     = (int) $wpdb->insert_id;
		$product = self::factory()->post->create( array( 'post_type' => 'product', 'meta_input' => array( '_clms_linked_program_id' => $program ) ) );
		$order   = new Atora_Fake_Order( 9010, array( new Atora_Fake_Order_Item( $product ) ) );
		$this->assertSame( $this->inst_b, ATORA_Order_Institution::resolve( $order ) );
		$wpdb->delete( $wpdb->prefix . 'atora_programs', array( 'id' => $row ) );
	}

	public function test_admin_assistant_only_opens_orders_of_its_institution(): void {
		$this->assertTrue( ATORA_Order_Institution::restricted( $this->finance ) );
		$this->assertTrue( ATORA_Order_Institution::can_access_id( $this->finance, 9001 ) );
		$this->assertFalse( ATORA_Order_Institution::can_access_id( $this->finance, 9002 ), 'Otra institución.' );
		$this->assertFalse( ATORA_Order_Institution::can_access_id( $this->finance, 9003 ), 'Mezcla: solo administración.' );
		$this->assertFalse( ATORA_Order_Institution::can_access_id( $this->finance, 9004 ), 'Sin ATORA: solo administración.' );
		$this->assertFalse( ATORA_Order_Institution::can_access_id( $this->finance, 9999 ), 'Inexistente.' );
		$this->assertSame( (string) $this->inst_b, $this->orders[9002]->meta['_atora_institution_id'], 'Pedido anterior: se calcula y guarda al consultarlo.' );

		$this->assertFalse( ATORA_Order_Institution::restricted( $this->shop_admin ) );
		$this->assertTrue( ATORA_Order_Institution::can_access_id( $this->shop_admin, 9002 ) );
		$this->assertTrue( ATORA_Order_Institution::can_access_id( $this->shop_admin, 9003 ) );
	}

	public function test_meta_caps_rest_and_lists_are_filtered(): void {
		$this->assertSame( array( 'do_not_allow' ), ATORA_Order_Institution::map_meta_cap( array( 'edit_shop_orders' ), 'edit_shop_order', $this->finance, array( 9002 ) ) );
		$this->assertSame( array( 'edit_shop_orders' ), ATORA_Order_Institution::map_meta_cap( array( 'edit_shop_orders' ), 'edit_shop_order', $this->finance, array( 9001 ) ) );
		$this->assertSame( array( 'edit_shop_orders' ), ATORA_Order_Institution::map_meta_cap( array( 'edit_shop_orders' ), 'edit_shop_order', $this->shop_admin, array( 9002 ) ) );
		$this->assertSame( array( 'edit_posts' ), ATORA_Order_Institution::map_meta_cap( array( 'edit_posts' ), 'edit_post', $this->finance, array( $this->course_b ) ), 'Otros tipos de post: intactos.' );

		wp_set_current_user( $this->finance );
		$this->assertFalse( ATORA_Order_Institution::rest_permissions( true, 'read', 9002, 'shop_order' ), 'API REST de WooCommerce.' );
		$this->assertTrue( ATORA_Order_Institution::rest_permissions( true, 'read', 9001, 'shop_order' ) );

		$args = ATORA_Order_Institution::filter_list_args( array( 'status' => 'any' ) );
		$this->assertSame( array( array( 'key' => '_atora_institution_id', 'value' => (string) $this->inst_a, 'compare' => '=' ) ), $args['meta_query'] );

		wp_set_current_user( $this->shop_admin );
		$this->assertSame( array( 'status' => 'any' ), ATORA_Order_Institution::filter_list_args( array( 'status' => 'any' ) ), 'Administración ve todo.' );
	}

	public function test_ambiguous_membership_sees_nothing(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'atora_institution_members', array( 'institution_id' => $this->inst_b, 'user_id' => $this->finance, 'role' => 'member', 'status' => 'active' ) );
		\ATORA\LMS\Tenant_Context::reset_cache();
		$this->assertSame( 0, ATORA_Order_Institution::user_institution( $this->finance ) );
		$this->assertFalse( ATORA_Order_Institution::can_access_id( $this->finance, 9001 ) );
		wp_set_current_user( $this->finance );
		$this->assertSame( '-1', ATORA_Order_Institution::filter_list_args( array() )['meta_query'][0]['value'], 'No muestra los pedidos «0» (mezclas).' );
	}

	public function test_payments_page_rows_and_role_without_global_reports(): void {
		$rows = ATORA_Order_Institution_Admin::rows( $this->finance, array_values( $this->orders ) );
		$this->assertSame( array( 9001 ), array_column( $rows, 'id' ), 'Solo los de su institución, aunque la consulta trajera otros.' );
		$this->assertSame( 'Curso A', $rows[0]['items'] );
		$this->assertSame( 'Ana Pérez', $rows[0]['customer'] );
		$this->assertSame( array(), ATORA_Order_Institution_Admin::rows( self::factory()->user->create( array( 'role' => 'subscriber' ) ), array_values( $this->orders ) ) );

		$this->assertFalse( user_can( $this->finance, 'view_woocommerce_reports' ), 'Los informes globales mezclan instituciones.' );
		$this->assertTrue( user_can( $this->finance, 'edit_shop_orders' ) );
	}
}
