<?php
/** Learning navigation must respect roles and published destinations. */
declare( strict_types = 1 );
final class LearningNavigationTest extends WP_UnitTestCase {
 public function test_guest_student_and_teacher_receive_only_available_destinations(): void {
  $student = self::factory()->user->create( array( 'role' => 'subscriber' ) );
  $teacher = self::factory()->user->create( array( 'role' => 'subscriber' ) );
  get_user_by( 'id', $teacher )->add_cap( 'clms_view_teacher_dashboard' );
  $dashboard = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[clms_dashboard]' ) );
  $teacher_page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[clms_teacher_dashboard]' ) );
  $draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_content' => '[atora_calendar]' ) );
  update_option( 'clms_frontend_pages', array( 'dashboard' => $dashboard ) );
  wp_set_current_user( 0 );
  $this->assertSame( array(), CLMS_Frontend_URLs::learning_navigation() );
  $student_links = CLMS_Frontend_URLs::learning_navigation( $student );
  $teacher_links = CLMS_Frontend_URLs::learning_navigation( $teacher );
  $this->assertSame( get_permalink( $dashboard ), $student_links[0]['url'] );
  $this->assertSame( get_permalink( $teacher_page ), $teacher_links[0]['url'] );
  $this->assertNotContains( get_permalink( $teacher_page ), array_column( $student_links, 'url' ) );
  $this->assertNotContains( get_permalink( $draft ), array_column( $student_links, 'url' ) );
  foreach ( array_merge( $student_links, $teacher_links ) as $link ) {
   $this->assertStringNotContainsString( '/wp-admin/', $link['url'] );
  }
 }
}
