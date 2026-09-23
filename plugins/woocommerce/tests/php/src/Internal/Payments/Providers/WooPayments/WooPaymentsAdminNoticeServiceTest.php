<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
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
	 * @testdox A completed test sale should offer live payments at the exact seven-day boundary.
	 */
	public function test_test_to_live_notice_at_seven_day_boundary(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( 'wcpay_test_to_live_eligible' );
		update_option( 'wcpay_test_mode_enabled_date', $now - 7 * DAY_IN_SECONDS, false );
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();

		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'has_working_account' )->willReturn( true );
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$account->method( 'is_dev_mode_enabled' )->willReturn( false );
		$account->method( 'has_live_account' )->willReturn( true );
		$sut = new WooPaymentsAdminNoticeService( static fn(): int => $now );
		$sut->init( $account );
		$queries = array();
		$record_query = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$notice = $sut->get_notice_for_current_user();
			$this->assertIsArray( $notice );
			$this->assertSame( 'test_to_live', $notice['id'] );
			$this->assertSame( "You're ready to take real payments. Switch from test mode to start charging customers.", $notice['message'] );
			$this->assertSame( 'disable_test_mode', $notice['primary']['kind'] );
			$this->assertSame( 'Turn on live payments', $notice['primary']['label'] );
			$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/settings' ), $notice['primary']['href'] );
			$this->assertSame( 'Maybe later', $notice['secondary']['label'] );
			$this->assertSame( rest_url( 'wc-admin/settings/payments/woopayments/admin-notices/test_to_live/shown' ), $notice['_links']['shown']['href'] );
			$this->assertCount( 1, $queries );
			$this->assertSame( 'woocommerce_payments', $queries[0]['payment_method'] );
			$this->assertSame( 1, $queries[0]['limit'] );
			$this->assertSame( 'none', $queries[0]['orderby'] );
			$this->assertSame( 'ids', $queries[0]['return'] );
			$this->assertSame( array( 'wc-completed', 'wc-processing' ), $queries[0]['status'] );
			$this->assertSame( '_wcpay_mode', $queries[0]['meta_key'] );
			$this->assertSame( 'test', $queries[0]['meta_value'] );
			$this->assertSame( $notice, $sut->get_notice_for_current_user() );
			$this->assertCount( 1, $queries, 'The one-hour site transient should prevent another existence query.' );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_option( 'wcpay_test_mode_enabled_date' );
			delete_transient( 'wcpay_test_to_live_eligible' );
		}
	}

	/**
	 * @testdox An account that cannot switch live in one click should link to native onboarding.
	 */
	public function test_test_to_live_uses_onboarding_fallback_for_non_live_account(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'wcpay_test_mode_enabled_date', $now - 7 * DAY_IN_SECONDS, false );
		set_transient( 'wcpay_test_to_live_eligible', '1', HOUR_IN_SECONDS );
		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'has_working_account' )->willReturn( true );
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$account->method( 'is_dev_mode_enabled' )->willReturn( false );
		$account->method( 'has_live_account' )->willReturn( false );
		$sut = new WooPaymentsAdminNoticeService( static fn(): int => $now );
		$sut->init( $account );

		try {
			$notice = $sut->get_notice_for_current_user();
			$this->assertIsArray( $notice );
			$this->assertSame( 'onboard', $notice['primary']['kind'] );
			$this->assertSame( Utils::wc_payments_settings_url( '/woopayments/onboarding' ), $notice['primary']['href'] );
		} finally {
			delete_option( 'wcpay_test_mode_enabled_date' );
			delete_transient( 'wcpay_test_to_live_eligible' );
		}
	}

	/**
	 * @testdox A settings notice should remove only the obsolete test-to-live inbox notes.
	 */
	public function test_test_to_live_cleans_only_legacy_inbox_notes(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'wcpay_test_mode_enabled_date', $now - 7 * DAY_IN_SECONDS, false );
		set_transient( 'wcpay_test_to_live_eligible', '1', HOUR_IN_SECONDS );
		foreach ( array( 'wc-payments-notes-test-to-live', 'wc-payments-notes-test-to-live', 'unrelated-note' ) as $name ) {
			$note = new Note();
			$note->set_name( $name );
			$note->set_title( 'Existing note' );
			$note->set_content( 'Existing note content' );
			$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
			$note->save();
		}
		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'has_working_account' )->willReturn( true );
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$account->method( 'is_dev_mode_enabled' )->willReturn( false );
		$sut = new WooPaymentsAdminNoticeService( static fn(): int => $now );
		$sut->init( $account );

		try {
			$this->assertNotNull( $sut->get_notice_for_current_user() );
			$data_store = Notes::load_data_store();
			$this->assertEmpty( $data_store->get_notes_with_name( 'wc-payments-notes-test-to-live' ) );
			$this->assertCount( 1, $data_store->get_notes_with_name( 'unrelated-note' ) );
		} finally {
			Notes::delete_notes_with_name( array( 'wc-payments-notes-test-to-live', 'unrelated-note' ) );
			delete_option( 'wcpay_test_mode_enabled_date' );
			delete_transient( 'wcpay_test_to_live_eligible' );
		}
	}

	/**
	 * @testdox Cheap test-to-live guards should return before querying orders.
	 * @dataProvider provide_test_to_live_cheap_guards
	 *
	 * @param bool $logged_in Whether a user is current.
	 * @param bool $working   Whether the account works.
	 * @param bool $test_mode Whether test mode is enabled.
	 * @param bool $dev_mode  Whether development mode is enabled.
	 * @param int  $age_days  Days since enabling test mode.
	 */
	public function test_test_to_live_cheap_guards_skip_order_query( bool $logged_in, bool $working, bool $test_mode, bool $dev_mode, int $age_days ): void {
		$now = 1700000000;
		wp_set_current_user( $logged_in ? self::factory()->user->create( array( 'role' => 'administrator' ) ) : 0 );
		update_option( 'wcpay_test_mode_enabled_date', $now - $age_days * DAY_IN_SECONDS, false );
		delete_transient( 'wcpay_test_to_live_eligible' );
		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'has_working_account' )->willReturn( $working );
		$account->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account->method( 'is_dev_mode_enabled' )->willReturn( $dev_mode );
		$sut = new WooPaymentsAdminNoticeService( static fn(): int => $now );
		$sut->init( $account );
		$queries = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$this->assertNull( $sut->get_notice_for_current_user() );
			$this->assertSame( 0, $queries );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_option( 'wcpay_test_mode_enabled_date' );
			delete_transient( 'wcpay_test_to_live_eligible' );
		}
	}

	/**
	 * Cheap conditions that must suppress notice queries.
	 *
	 * @return array<string,array{bool,bool,bool,bool,int}>
	 */
	public static function provide_test_to_live_cheap_guards(): array {
		return array(
			'anonymous'            => array( false, true, true, false, 7 ),
			'no working account'   => array( true, false, true, false, 7 ),
			'live mode'            => array( true, true, false, false, 7 ),
			'development mode'     => array( true, true, true, true, 7 ),
			'before seven days'    => array( true, true, true, false, 6 ),
		);
	}

	/**
	 * @testdox A store without a completed or processing test sale should not show test-to-live.
	 */
	public function test_test_to_live_requires_test_sale(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'wcpay_test_mode_enabled_date', $now - 7 * DAY_IN_SECONDS, false );
		delete_transient( 'wcpay_test_to_live_eligible' );
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_status( 'completed' );
		$order->update_meta_data( '_wcpay_mode', 'live' );
		$order->save();
		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'has_working_account' )->willReturn( true );
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$account->method( 'is_dev_mode_enabled' )->willReturn( false );
		$sut = new WooPaymentsAdminNoticeService( static fn(): int => $now );
		$sut->init( $account );

		try {
			$this->assertNull( $sut->get_notice_for_current_user() );
			$this->assertFalse( get_transient( 'wcpay_test_to_live_eligible' ) );
		} finally {
			delete_option( 'wcpay_test_mode_enabled_date' );
			delete_transient( 'wcpay_test_to_live_eligible' );
		}
	}

	/**
	 * @testdox Preserved test-to-live user markers should suppress or allow the notice at the correct boundary.
	 * @dataProvider provide_test_to_live_user_markers
	 *
	 * @param string $meta_key      Preserved user-meta key.
	 * @param int    $marker_offset Seconds before the test clock.
	 * @param bool   $expected      Whether the notice should appear.
	 */
	public function test_test_to_live_preserved_user_markers( string $meta_key, int $marker_offset, bool $expected ): void {
		$now     = 1700000000;
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		add_user_meta( $user_id, $meta_key, $now - $marker_offset, true );
		update_option( 'wcpay_test_mode_enabled_date', $now - 7 * DAY_IN_SECONDS, false );
		delete_transient( 'wcpay_test_to_live_eligible' );
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();
		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->method( 'has_working_account' )->willReturn( true );
		$account->method( 'is_test_mode_enabled' )->willReturn( true );
		$account->method( 'is_dev_mode_enabled' )->willReturn( false );
		$sut = new WooPaymentsAdminNoticeService( static fn(): int => $now );
		$sut->init( $account );
		$queries = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$notice = $sut->get_notice_for_current_user();
			$this->assertSame( $expected, null !== $notice );
			$this->assertSame( $expected ? 1 : 0, $queries );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_option( 'wcpay_test_mode_enabled_date' );
			delete_transient( 'wcpay_test_to_live_eligible' );
		}
	}

	/**
	 * Preserved user markers and their expected notice state.
	 *
	 * @return array<string,array{string,int,bool}>
	 */
	public static function provide_test_to_live_user_markers(): array {
		return array(
			'dismissed'               => array( 'wcpay_test_to_live_notice_dismissed', 30 * DAY_IN_SECONDS, false ),
			'snoozed six days ago'    => array( 'wcpay_test_to_live_notice_snoozed', 6 * DAY_IN_SECONDS, false ),
			'snoozed seven days ago'  => array( 'wcpay_test_to_live_notice_snoozed', 7 * DAY_IN_SECONDS, true ),
			'shown seven days ago'    => array( 'wcpay_test_to_live_notice_shown', 7 * DAY_IN_SECONDS, true ),
		);
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
