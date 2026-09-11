<?php

use PHPUnit\Framework\TestCase;

final class SpeedGradeModerationPolicyTest extends TestCase {
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__, 2 ) . '/includes/speedgrade/class-speedgrade-moderation-policy.php';
	}

	public function test_teacher_can_submit_and_resubmit_only_after_changes_requested(): void {
		$this->assertTrue( CLMS_SpeedGrade_Moderation_Policy::can_transition( 'none', 'pending' ) );
		$this->assertTrue( CLMS_SpeedGrade_Moderation_Policy::can_transition( 'changes_requested', 'pending' ) );
		$this->assertFalse( CLMS_SpeedGrade_Moderation_Policy::can_transition( 'approved', 'pending' ) );
	}

	public function test_approval_requires_separation_of_duties(): void {
		$this->assertFalse( CLMS_SpeedGrade_Moderation_Policy::can_moderate( 17, 17 ) );
		$this->assertTrue( CLMS_SpeedGrade_Moderation_Policy::can_moderate( 17, 22 ) );
	}

	public function test_institutional_publish_requires_approval(): void {
		$this->assertTrue( CLMS_SpeedGrade_Moderation_Policy::direct_publish_allowed( false, 'none' ) );
		$this->assertFalse( CLMS_SpeedGrade_Moderation_Policy::direct_publish_allowed( true, 'pending' ) );
		$this->assertTrue( CLMS_SpeedGrade_Moderation_Policy::direct_publish_allowed( true, 'approved' ) );
	}
}
