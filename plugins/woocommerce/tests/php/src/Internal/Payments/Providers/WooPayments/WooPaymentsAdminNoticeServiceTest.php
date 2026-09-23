<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminNoticeService;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the native WooPayments settings notices.
 */
class WooPaymentsAdminNoticeServiceTest extends WC_Unit_Test_Case {
	/**
	 * @testdox The notice service should resolve through the WooCommerce container.
	 */
	public function test_container_resolves_notice_service(): void {
		$this->assertInstanceOf( WooPaymentsAdminNoticeService::class, wc_get_container()->get( WooPaymentsAdminNoticeService::class ) );
	}

	/**
	 * @testdox Invalid notice actions should not write user state.
	 * @dataProvider invalid_actions
	 *
	 * @param string   $notice_id Notice identifier.
	 * @param string   $action    Requested action.
	 * @param int|null $stage     Optional post-KYC stage.
	 */
	public function test_invalid_actions_do_not_write_user_state( string $notice_id, string $action, ?int $stage ): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$before = get_user_meta( $user_id );
		$sut    = new WooPaymentsAdminNoticeService( static fn(): int => 1000 );
		$sut->init( $this->createMock( WooPaymentsAccountService::class ) );

		$this->assertInstanceOf( WP_Error::class, $sut->record_action( $notice_id, $action, $stage ) );
		$this->assertSame( $before, get_user_meta( $user_id ) );
	}

	/**
	 * Invalid notice and action combinations.
	 *
	 * @return array<string,array{string,string,int|null}>
	 */
	public static function invalid_actions(): array {
		return array(
			'unknown notice'       => array( 'unknown', 'shown', null ),
			'unknown action'       => array( 'test_to_live', 'explode', null ),
			'non-snoozable notice' => array( 'post_kyc_activation', 'snooze', 7 ),
			'invalid KYC stage'    => array( 'post_kyc_activation', 'shown', 8 ),
			'unexpected stage'     => array( 'one_and_done', 'shown', 7 ),
		);
	}

	/**
	 * @testdox A valid notice action should keep its first marker on replay.
	 * @dataProvider valid_actions
	 *
	 * @param string   $notice_id Notice identifier.
	 * @param string   $action    Requested action.
	 * @param int|null $stage     Optional post-KYC stage.
	 * @param string   $meta_key  Preserved client state key.
	 */
	public function test_valid_actions_keep_the_first_user_marker( string $notice_id, string $action, ?int $stage, string $meta_key ): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$now = 1000;
		$sut = new WooPaymentsAdminNoticeService(
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$sut->init( $this->createMock( WooPaymentsAccountService::class ) );

		$this->assertTrue( $sut->record_action( $notice_id, $action, $stage ) );
		$first_marker = get_user_meta( $user_id, $meta_key, true );
		$this->assertNotEmpty( $first_marker );
		$now = 2000;
		$this->assertTrue( $sut->record_action( $notice_id, $action, $stage ) );
		$this->assertSame( $first_marker, get_user_meta( $user_id, $meta_key, true ) );
		$this->assertCount( 1, get_user_meta( $user_id, $meta_key, false ) );
	}

	/**
	 * Valid action and preserved client marker combinations.
	 *
	 * @return array<string,array{string,string,int|null,string}>
	 */
	public static function valid_actions(): array {
		return array(
			'test shown'        => array( 'test_to_live', 'shown', null, 'wcpay_test_to_live_notice_shown' ),
			'test dismiss'      => array( 'test_to_live', 'dismiss', null, 'wcpay_test_to_live_notice_dismissed' ),
			'test snooze'       => array( 'test_to_live', 'snooze', null, 'wcpay_test_to_live_notice_snoozed' ),
			'post-KYC shown'    => array( 'post_kyc_activation', 'shown', 7, 'wcpay_post_kyc_activation_7_shown' ),
			'post-KYC dismiss'  => array( 'post_kyc_activation', 'dismiss', 14, 'wcpay_post_kyc_activation_14_dismissed' ),
			'first sale shown'  => array( 'one_and_done', 'shown', null, 'wcpay_one_and_done_notice_shown' ),
			'first sale snooze' => array( 'one_and_done', 'snooze', null, 'wcpay_one_and_done_notice_snoozed_at' ),
		);
	}
}
