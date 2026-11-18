<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\NoticeService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Order;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the native WooPayments settings notices.
 */
class NoticeServiceTest extends WC_Unit_Test_Case {
	/**
	 * @testdox The notice service should stay out of every setup tier's classes.
	 */
	public function test_notice_service_is_absent_from_the_setup_tier_classes(): void {
		foreach ( WooPaymentsProvider::get_classes_by_setup_tier() as $state => $request_groups ) {
			foreach ( $request_groups as $request => $roots ) {
				$this->assertNotContains( NoticeService::class, $roots, $state . ' ' . $request . ' requests must not bootstrap the notice service.' );
			}
		}
	}

	/**
	 * @testdox A store in test mode gets no notice a week after test mode was turned on, with a paid test order, and runs no order query.
	 *
	 * Client 11.3.0 retired the test-to-live notice and shows only one-and-done (`includes/admin/attach-rate/class-wc-payments-admin-notices.php`).
	 */
	public function test_test_mode_store_with_a_test_sale_gets_no_notice(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'wcpay_test_mode_enabled_date', $now - 8 * DAY_IN_SECONDS, false );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'test', $now - DAY_IN_SECONDS );
		$sut          = $this->create_notice_service( $now, array( 'is_test_mode_enabled' => true ) );
		$queries      = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		$this->assertNull( $sut->refresh_notice_for_current_user() );
		$this->assertSame( 0, $queries );
	}

	/**
	 * @testdox While the WooPayments extension owns payments, the notice neither shows nor records an action.
	 */
	public function test_notice_stays_off_while_the_extension_owns_payments(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_transient( 'wcpay_one_and_done_eligible', '1', HOUR_IN_SECONDS );
		$active_plugins = get_option( 'active_plugins', array() );
		update_option( 'active_plugins', array_merge( (array) $active_plugins, array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ) );
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();
		$account = $this->createMock( WooPaymentsAccountService::class );
		$account->expects( $this->never() )->method( $this->anything() );
		$sut = new NoticeService( static fn(): int => $now );
		$sut->init( $account, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		try {
			$this->assertTrue( $arbiter->is_extension_owner() );
			$this->assertNull( $sut->refresh_notice_for_current_user() );
			$this->assertInstanceOf( \WP_Error::class, $sut->record_action( 'one_and_done', 'dismiss' ) );
		} finally {
			update_option( 'active_plugins', $active_plugins );
			$arbiter->invalidate();
		}
	}

	/**
	 * @testdox Cheap guards return before any order query on a store whose one live sale is a week old: $_dataName.
	 * @dataProvider provide_cheap_guards
	 *
	 * @param bool               $logged_in Whether a user is current.
	 * @param array<string,bool> $account   Account service answers that differ from an eligible live store.
	 */
	public function test_cheap_guards_skip_order_query( bool $logged_in, array $account ): void {
		$now = 1700000000;
		wp_set_current_user( $logged_in ? self::factory()->user->create( array( 'role' => 'administrator' ) ) : 0 );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', $now - 7 * DAY_IN_SECONDS );
		$sut          = $this->create_notice_service( $now, $account );
		$queries      = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		$this->assertNull( $sut->refresh_notice_for_current_user() );
		$this->assertSame( 0, $queries );
	}

	/**
	 * Cheap conditions that must suppress the notice before any order query.
	 *
	 * The account checks follow client 11.3.0 `includes/admin/attach-rate/class-wc-payments-one-and-done-notice.php`
	 * compute_eligibility() and has_active_payments_account() in `class-wc-payments-abstract-admin-notice.php`.
	 *
	 * @return array<string,array{bool,array<string,bool>}>
	 */
	public static function provide_cheap_guards(): array {
		return array(
			'anonymous'               => array( false, array() ),
			'no working account'      => array( true, array( 'has_working_account' => false ) ),
			'test-drive account'      => array( true, array( 'has_test_account' => true ) ),
			'test mode'               => array( true, array( 'is_test_mode_enabled' => true ) ),
			'development mode'        => array( true, array( 'is_dev_mode_enabled' => true ) ),
			'cannot process payments' => array( true, array( 'can_process_payments' => false ) ),
			'not a live account'      => array( true, array( 'has_live_account' => false ) ),
		);
	}

	/**
	 * @testdox Invalid notice actions should not write user state: $_dataName.
	 * @dataProvider invalid_actions
	 *
	 * @param string $notice_id Notice identifier.
	 * @param string $action    Requested action.
	 */
	public function test_invalid_actions_do_not_write_user_state( string $notice_id, string $action ): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$before = get_user_meta( $user_id );
		$sut    = new NoticeService( static fn(): int => 1000 );
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		$this->assertInstanceOf( WP_Error::class, $sut->record_action( $notice_id, $action ) );
		$this->assertSame( $before, get_user_meta( $user_id ) );
	}

	/**
	 * Invalid notice and action combinations, the notices client 11.3.0 retired among them.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function invalid_actions(): array {
		return array(
			'unknown notice'             => array( 'unknown', 'shown' ),
			'unknown action'             => array( 'one_and_done', 'explode' ),
			'retired test-to-live shown' => array( 'test_to_live', 'shown' ),
			'retired post-KYC dismiss'   => array( 'post_kyc_activation', 'dismiss' ),
		);
	}

	/**
	 * @testdox A valid notice action should keep its first marker on replay: $_dataName.
	 * @dataProvider valid_actions
	 *
	 * @param string $action   Requested action.
	 * @param string $meta_key Preserved client state key.
	 */
	public function test_valid_actions_keep_the_first_user_marker( string $action, string $meta_key ): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$now = 1000;
		$sut = new NoticeService(
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
		set_transient( 'wcpay_one_and_done_eligible', '1', HOUR_IN_SECONDS );

		$this->assertTrue( $sut->record_action( 'one_and_done', $action ) );
		$this->assertFalse( get_transient( 'wcpay_one_and_done_eligible' ) );
		set_transient( 'wcpay_one_and_done_eligible', '1', HOUR_IN_SECONDS );
		$first_marker = get_user_meta( $user_id, $meta_key, true );
		$this->assertNotEmpty( $first_marker );
		$now = 2000;
		$this->assertTrue( $sut->record_action( 'one_and_done', $action ) );
		$this->assertSame( $first_marker, get_user_meta( $user_id, $meta_key, true ) );
		$this->assertCount( 1, get_user_meta( $user_id, $meta_key, false ) );
		$this->assertSame( '1', get_transient( 'wcpay_one_and_done_eligible' ), 'An idempotent replay should not invalidate eligibility again.' );
	}

	/**
	 * Valid one-and-done actions and their preserved client markers.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function valid_actions(): array {
		return array(
			'shown'   => array( 'shown', 'wcpay_one_and_done_notice_shown' ),
			'dismiss' => array( 'dismiss', 'wcpay_one_and_done_notice_dismissed_at' ),
			'snooze'  => array( 'snooze', 'wcpay_one_and_done_notice_snoozed_at' ),
			'cta'     => array( 'cta', 'wcpay_one_and_done_notice_dismissed_at' ),
		);
	}

	/**
	 * @testdox Notice actions send the WooPayments extension's attach-rate Tracks event once per state change: $action.
	 * @dataProvider tracks_actions
	 *
	 * @param string              $action     Requested action.
	 * @param string              $event_name Expected wire event name.
	 * @param array<string,mixed> $properties Expected notice properties.
	 */
	public function test_notice_actions_record_woopayments_extension_tracks_events( string $action, string $event_name, array $properties ): void {
		$sut = new NoticeService( static fn(): int => 1000 );
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		$pixels = $this->capture_notice_tracks_pixels(
			function () use ( $sut, $action ): void {
				$this->assertTrue( $sut->record_action( 'one_and_done', $action ) );
				$this->assertTrue( $sut->record_action( 'one_and_done', $action ) );
			}
		);

		$this->assertCount( 1, $pixels );
		$this->assertSame( $event_name, $pixels[0]['_en'] );
		foreach ( array( 'stage', 'destination', 'path' ) as $key ) {
			$this->assertSame( $properties[ $key ] ?? null, $pixels[0][ $key ] ?? null, $key );
		}
	}

	/**
	 * @testdox A notice action that lost a concurrent first write records no Tracks event, since the other request recorded it.
	 */
	public function test_notice_action_that_lost_a_concurrent_first_write_records_no_tracks_event(): void {
		$sut = new NoticeService( static fn(): int => 1000 );
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		$pixels = $this->capture_notice_tracks_pixels(
			function () use ( $sut ): void {
				$user_id       = get_current_user_id();
				$simulate_race = null;
				$simulate_race = static function ( $check, int $object_id, string $key, $value ) use ( &$simulate_race, $user_id ) {
					if ( $user_id !== $object_id || 'wcpay_one_and_done_notice_dismissed_at' !== $key ) {
						return $check;
					}
					remove_filter( 'add_user_metadata', $simulate_race, 10 );
					update_user_meta( $user_id, $key, $value );
					return false;
				};
				add_filter( 'add_user_metadata', $simulate_race, 10, 4 );
				$this->assertTrue( $sut->record_action( 'one_and_done', 'dismiss' ) );
			}
		);

		$this->assertSame( array(), $pixels );
	}

	/**
	 * Run notice actions as a logged-in user that WC_Tracks will record for, and capture the WooPayments Tracks pixels.
	 *
	 * @param callable $act Actions to run.
	 * @return array<int,array<string,mixed>> Captured pixel query arguments.
	 */
	private function capture_notice_tracks_pixels( callable $act ): array {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		// WC_Tracks skips test-suite users by their cap key; use a site-like key so the pixel is built.
		wp_get_current_user()->cap_key = 'wp_capabilities';
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$pixels  = array();
		$capture = static function ( $preempt, $parsed_args, $url ) use ( &$pixels ) {
			unset( $preempt, $parsed_args );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $pixel );
			if ( 0 === strpos( (string) ( $pixel['_en'] ?? '' ), 'wcadmin_wcpay_' ) ) {
				$pixels[] = $pixel;
			}
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $capture, 10, 3 );

		try {
			$act();
		} finally {
			remove_filter( 'pre_http_request', $capture, 10 );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return $pixels;
	}

	/**
	 * One-and-done actions with the client 11.3.0 Tracks event and properties each one sends.
	 *
	 * @return array<string,array{string,string,array<string,string>}>
	 */
	public static function tracks_actions(): array {
		return array(
			'shown'   => array( 'shown', 'wcadmin_wcpay_one_and_done_notice_shown', array() ),
			'dismiss' => array( 'dismiss', 'wcadmin_wcpay_one_and_done_notice_dismissed', array() ),
			'snooze'  => array( 'snooze', 'wcadmin_wcpay_one_and_done_notice_snoozed', array() ),
			'cta'     => array( 'cta', 'wcadmin_wcpay_one_and_done_notice_cta_clicked', array( 'destination' => 'marketing' ) ),
		);
	}

	/**
	 * @testdox A concurrent first write should succeed when the requested marker was persisted by the other request.
	 * @dataProvider concurrent_first_write_actions
	 *
	 * @param string $action   Requested action.
	 * @param string $meta_key Preserved client state key.
	 */
	public function test_concurrent_first_write_succeeds_when_marker_was_persisted( string $action, string $meta_key ): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$now = 1700000000;
		$sut = new NoticeService( static fn(): int => $now );
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
		$simulate_race = null;
		$simulate_race = static function ( $check, int $object_id, string $key, $value ) use ( &$simulate_race, $user_id, $meta_key ) {
			if ( $user_id !== $object_id || $meta_key !== $key ) {
				return $check;
			}
			remove_filter( 'add_user_metadata', $simulate_race, 10 );
			update_user_meta( $user_id, $meta_key, $value );
			return false;
		};
		add_filter( 'add_user_metadata', $simulate_race, 10, 4 );

		$this->assertTrue( $sut->record_action( 'one_and_done', $action ) );
		$this->assertSame( $now, (int) get_user_meta( $user_id, $meta_key, true ) );
		$this->assertCount( 1, get_user_meta( $user_id, $meta_key, false ) );
	}

	/**
	 * Concurrent first-write actions and their preserved keys.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function concurrent_first_write_actions(): array {
		return array(
			'shown'   => array( 'shown', 'wcpay_one_and_done_notice_shown' ),
			'dismiss' => array( 'dismiss', 'wcpay_one_and_done_notice_dismissed_at' ),
			'snooze'  => array( 'snooze', 'wcpay_one_and_done_notice_snoozed_at' ),
		);
	}

	/**
	 * @testdox A failed first write without a persisted marker should remain an error.
	 */
	public function test_failed_first_write_without_marker_returns_error(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$sut = new NoticeService( static fn(): int => 1700000000 );
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
		$fail_write = static function ( $check, int $object_id, string $key ) use ( $user_id ) {
			return $user_id === $object_id && 'wcpay_one_and_done_notice_shown' === $key ? false : $check;
		};
		add_filter( 'add_user_metadata', $fail_write, 10, 3 );

		$result = $sut->record_action( 'one_and_done', 'shown' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'woocommerce_woopayments_notice_action_failed', $result->get_error_code() );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'wcpay_one_and_done_notice_shown' ) );
	}

	/**
	 * @testdox An expired snooze can start a new window without letting request replays extend it, even when a concurrent refresh wrote the fresh marker.
	 * @dataProvider provide_snoozable_notices
	 *
	 * @param bool $race Whether a concurrent request writes the fresh marker and this request's update fails.
	 */
	public function test_expired_snooze_starts_one_fresh_window( bool $race ): void {
		$meta_key = 'wcpay_one_and_done_notice_snoozed_at';
		$user_id  = self::factory()->user->create();
		wp_set_current_user( $user_id );
		$now = 1700000000;
		update_user_meta( $user_id, $meta_key, $now - 7 * DAY_IN_SECONDS );
		$sut = new NoticeService(
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$sut->init( $this->createMock( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );
		if ( $race ) {
			$simulate_race = null;
			$simulate_race = static function ( $check, int $object_id, string $key, $value ) use ( &$simulate_race, $user_id, $meta_key ) {
				if ( $user_id !== $object_id || $meta_key !== $key ) {
					return $check;
				}
				remove_filter( 'update_user_metadata', $simulate_race, 10 );
				update_user_meta( $user_id, $meta_key, $value );
				return false;
			};
			add_filter( 'update_user_metadata', $simulate_race, 10, 4 );
		}

		$this->assertTrue( $sut->record_action( 'one_and_done', 'snooze' ) );
		$this->assertSame( $now, (int) get_user_meta( $user_id, $meta_key, true ) );
		$now += DAY_IN_SECONDS;
		$this->assertTrue( $sut->record_action( 'one_and_done', 'snooze' ) );
		$this->assertSame( 1700000000, (int) get_user_meta( $user_id, $meta_key, true ), $race ? 'An immediate replay must not extend the other request\'s fresh snooze.' : 'An immediate replay must not extend the fresh snooze.' );
		$this->assertCount( 1, get_user_meta( $user_id, $meta_key, false ) );
	}

	/**
	 * Whether a concurrent refresh wins the snooze write.
	 *
	 * @return array<string,array{bool}>
	 */
	public static function provide_snoozable_notices(): array {
		return array(
			'one request'        => array( false ),
			'concurrent refresh' => array( true ),
		);
	}

	/**
	 * @testdox One-and-done requires exactly one seven-day-old live WooPayments order.
	 * @dataProvider provide_one_and_done_order_cohorts
	 *
	 * @param array<int,array{string,string,int}> $orders          Payment method, mode, and age in days.
	 * @param bool                                $expected_notice Whether the notice should appear.
	 * @param string                              $expected_cache  Expected cached eligibility.
	 */
	public function test_one_and_done_order_cohort( array $orders, bool $expected_notice, string $expected_cache ): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'wcpay_has_live_sale' );
		delete_option( 'wcpay_one_and_done_permanently_ineligible' );
		delete_transient( 'wcpay_one_and_done_eligible' );
		foreach ( $orders as $order_data ) {
			list( $payment_method, $mode, $age_days ) = $order_data;
			$this->create_paid_order( $payment_method, $mode, $now - $age_days * DAY_IN_SECONDS );
		}
		$sut          = $this->create_live_notice_service( $now );
		$queries      = array();
		$record_query = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$notice = $sut->refresh_notice_for_current_user();
			$this->assertSame( $expected_notice, null !== $notice );
			$this->assertSame( $expected_cache, get_transient( 'wcpay_one_and_done_eligible' ) );
			$this->assertNotEmpty( $queries );
			$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $queries[0]['payment_method'] );
			$this->assertSame( 2, $queries[0]['limit'] );
			$this->assertSame( 'none', $queries[0]['orderby'] );
			$this->assertSame( 'ids', $queries[0]['return'] );
			$this->assertSame( array( 'wc-completed', 'wc-processing' ), $queries[0]['status'] );
			$this->assertSame( '_wcpay_mode', $queries[0]['meta_key'] );
			// Plugin 11.1.0 one-and-done Q1 matches `Order_Mode::PRODUCTION` (admin/attach-rate/class-wc-payments-one-and-done-notice.php:169-180).
			$this->assertSame( 'prod', $queries[0]['meta_value'] );
			if ( $expected_notice ) {
				$this->assertIsArray( $notice );
				$this->assertSame( 'one_and_done', $notice['id'] );
				$this->assertSame( "Your store made its first sale. Now bring more shoppers in with Woo's marketing tools.", $notice['message'] );
				$this->assertSame( 'navigate_and_dismiss', $notice['primary']['kind'] );
				$this->assertSame( 'Promote my store', $notice['primary']['label'] );
				$this->assertSame( admin_url( 'admin.php?page=wc-admin&path=/marketing' ), $notice['primary']['href'] );
				$this->assertSame( 'Maybe later', $notice['secondary']['label'] );
				$this->assertSame( rest_url( 'wc-admin/settings/payments/woopayments/admin-notices/one_and_done/shown' ), $notice['_links']['shown']['href'] );
				$this->assertSame( rest_url( 'wc-admin/settings/payments/woopayments/admin-notices/one_and_done/dismiss' ), $notice['_links']['dismiss']['href'] );
				$this->assertSame( rest_url( 'wc-admin/settings/payments/woopayments/admin-notices/one_and_done/snooze' ), $notice['_links']['snooze']['href'] );
				$this->assertCount( 2, $queries, 'One-and-done should use only the bounded WooPayments and alternate-gateway queries.' );
				$this->assertSame( 1, $queries[1]['limit'] );
				$this->assertSame( 'none', $queries[1]['orderby'] );
				$this->assertSame( 'ids', $queries[1]['return'] );
			}
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_option( 'wcpay_has_live_sale' );
			delete_option( 'wcpay_one_and_done_permanently_ineligible' );
			delete_transient( 'wcpay_one_and_done_eligible' );
		}
	}

	/**
	 * One-and-done order cohorts.
	 *
	 * @return array<string,array{array<int,array{string,string,int}>,bool,string}>
	 */
	public static function provide_one_and_done_order_cohorts(): array {
		return array(
			'zero orders'               => array( array(), false, '0' ),
			'one order before boundary' => array( array( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', 6 ) ), false, '0' ),
			'one order at boundary'     => array( array( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', 7 ) ), true, '1' ),
			'test order at boundary'    => array( array( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'test', 7 ) ), false, '0' ),
			'account-mode live value'   => array( array( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'live', 7 ) ), false, '0' ),
		);
	}

	/**
	 * @testdox A second live WooPayments order permanently suppresses one-and-done without future scans.
	 */
	public function test_one_and_done_two_live_orders_set_permanent_ineligibility(): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', $now - 8 * DAY_IN_SECONDS );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', $now - DAY_IN_SECONDS );
		$sut          = $this->create_live_notice_service( $now );
		$queries      = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$this->assertNull( $sut->refresh_notice_for_current_user() );
			$this->assertSame( '1', get_option( 'wcpay_one_and_done_permanently_ineligible' ) );
			$this->assertSame( 1, $queries );
			$this->assertNull( $sut->refresh_notice_for_current_user() );
			$this->assertSame( 1, $queries, 'Permanent ineligibility should suppress all future scans.' );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_option( 'wcpay_one_and_done_permanently_ineligible' );
			delete_transient( 'wcpay_one_and_done_eligible' );
		}
	}

	/**
	 * @testdox Only orders from currently registered alternate gateways permanently disqualify one-and-done.
	 * @dataProvider provide_alternate_gateway_cases
	 *
	 * @param string $gateway_id       Historical order gateway ID.
	 * @param bool   $expected_notice  Whether the notice should appear.
	 * @param bool   $expected_permanent Whether permanent ineligibility should be stored.
	 */
	public function test_one_and_done_registered_gateway_limitation( string $gateway_id, bool $expected_notice, bool $expected_permanent ): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', $now - 7 * DAY_IN_SECONDS );
		$this->create_paid_order( $gateway_id, '', $now - DAY_IN_SECONDS );
		$sut          = $this->create_live_notice_service( $now );
		$queries      = array();
		$record_query = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$this->assertSame( $expected_notice, null !== $sut->refresh_notice_for_current_user() );
			$this->assertSame( $expected_permanent, (bool) get_option( 'wcpay_one_and_done_permanently_ineligible' ) );
			$this->assertCount( 2, $queries );
			$this->assertIsArray( $queries[1]['payment_method'] );
			$this->assertNotContains( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $queries[1]['payment_method'] );
			$this->assertNotContains( 'removed_gateway', $queries[1]['payment_method'], 'Unregistered historical gateways must not broaden the query.' );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_option( 'wcpay_one_and_done_permanently_ineligible' );
			delete_transient( 'wcpay_one_and_done_eligible' );
		}
	}

	/**
	 * Registered and unregistered alternate gateway cases.
	 *
	 * @return array<string,array{string,bool,bool}>
	 */
	public static function provide_alternate_gateway_cases(): array {
		return array(
			'registered cash on delivery' => array( 'cod', false, true ),
			'unregistered old gateway'    => array( 'removed_gateway', true, false ),
		);
	}

	/**
	 * @testdox One-and-done dismissal and snooze guards run before order queries at exact boundaries.
	 * @dataProvider provide_one_and_done_user_guards
	 *
	 * @param string $meta_key      Preserved marker key.
	 * @param int    $marker_offset Marker age in seconds.
	 * @param bool   $expected      Whether the notice should appear.
	 */
	public function test_one_and_done_user_guards( string $meta_key, int $marker_offset, bool $expected ): void {
		$now     = 1700000000;
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, $meta_key, $now - $marker_offset );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', $now - 7 * DAY_IN_SECONDS );
		$sut          = $this->create_live_notice_service( $now );
		$queries      = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$this->assertSame( $expected, null !== $sut->refresh_notice_for_current_user() );
			$this->assertSame( $expected ? 2 : 0, $queries );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			delete_transient( 'wcpay_one_and_done_eligible' );
		}
	}

	/**
	 * Preserved one-and-done user markers.
	 *
	 * @return array<string,array{string,int,bool}>
	 */
	public static function provide_one_and_done_user_guards(): array {
		return array(
			'dismissal is terminal'        => array( 'wcpay_one_and_done_notice_dismissed_at', 30 * DAY_IN_SECONDS, false ),
			'snoozed six days ago'         => array( 'wcpay_one_and_done_notice_snoozed_at', 6 * DAY_IN_SECONDS, false ),
			'snooze expires at seven days' => array( 'wcpay_one_and_done_notice_snoozed_at', 7 * DAY_IN_SECONDS, true ),
		);
	}

	/**
	 * @testdox A live store a week past its KYC completion date gets no post-KYC notice, and only one-and-done's queries run: $_dataName.
	 * @dataProvider provide_live_stores_past_kyc
	 *
	 * Client 11.3.0 retired the post-KYC notice and its live-sale query (`includes/admin/attach-rate/class-wc-payments-admin-notices.php`).
	 *
	 * @param array<int,array{string,string,int}> $orders           Payment method, mode, and age in days.
	 * @param string|null                         $expected_notice  The notice ID shown, if any.
	 * @param int                                 $expected_queries Order queries the selection runs.
	 */
	public function test_live_store_past_kyc_gets_no_post_kyc_notice( array $orders, ?string $expected_notice, int $expected_queries ): void {
		$now = 1700000000;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'wcpay_kyc_completion_date', $now - 8 * DAY_IN_SECONDS, false );
		foreach ( $orders as $order_data ) {
			list( $payment_method, $mode, $age_days ) = $order_data;
			$this->create_paid_order( $payment_method, $mode, $now - $age_days * DAY_IN_SECONDS );
		}
		$sut          = $this->create_live_notice_service( $now );
		$queries      = 0;
		$record_query = static function ( array $args ) use ( &$queries ): array {
			++$queries;
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		$notice = $sut->refresh_notice_for_current_user();

		$this->assertSame( $expected_notice, $notice['id'] ?? null );
		$this->assertSame( $expected_queries, $queries );
		$this->assertFalse( get_option( 'wcpay_has_live_sale' ) );
	}

	/**
	 * Live stores past their KYC completion date, with and without the one-and-done cohort.
	 *
	 * @return array<string,array{array<int,array{string,string,int}>,string|null,int}>
	 */
	public static function provide_live_stores_past_kyc(): array {
		return array(
			'no live sale'           => array( array(), null, 1 ),
			'one week-old live sale' => array( array( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', 7 ) ), 'one_and_done', 2 ),
		);
	}

	/**
	 * @testdox One-and-done queries, transients and options stay on the current blog.
	 * @group multisite
	 * @ddlInTransaction Per-blog isolation is the subject: the second blog needs WooCommerce's tables, and WC_Install::create_tables() runs ALTER TABLE.
	 */
	public function test_one_and_done_state_and_queries_are_isolated_by_blog(): void {
		$this->skipWithoutMultisite();

		$now               = 1700000000;
		$main_blog_id      = get_current_blog_id();
		$secondary_blog_id = self::factory()->blog->create( array( 'path' => '/one-and-done-notice/' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( 'wcpay_one_and_done_eligible' );
		$this->create_paid_order( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'prod', $now - 7 * DAY_IN_SECONDS );
		$sut              = $this->create_live_notice_service( $now );
		$queried_blog_ids = array();
		$record_query     = static function ( array $args ) use ( &$queried_blog_ids ): array {
			$queried_blog_ids[] = get_current_blog_id();
			return $args;
		};
		add_filter( 'woocommerce_order_query_args', $record_query );

		try {
			$notice = $sut->refresh_notice_for_current_user();
			$this->assertIsArray( $notice );
			$this->assertSame( 'one_and_done', $notice['id'] );
			set_transient( 'wcpay_one_and_done_eligible', 'main-blog-cache', HOUR_IN_SECONDS );

			remove_filter( 'woocommerce_order_query_args', $record_query );
			switch_to_blog( $secondary_blog_id );
			\WC_Install::create_tables();
			add_filter( 'woocommerce_order_query_args', $record_query );
			$this->assertFalse( get_transient( 'wcpay_one_and_done_eligible' ), 'The main-blog transient must not leak to the secondary blog.' );
			$this->assertFalse( get_option( 'wcpay_one_and_done_permanently_ineligible' ), 'The main-blog option must not leak to the secondary blog.' );
			$this->assertNull( $sut->refresh_notice_for_current_user() );
			$this->assertSame( '0', get_transient( 'wcpay_one_and_done_eligible' ) );

			restore_current_blog();
			$this->assertSame( $main_blog_id, get_current_blog_id() );
			$this->assertSame( 'main-blog-cache', get_transient( 'wcpay_one_and_done_eligible' ) );
			$this->assertSame( array( $main_blog_id, $main_blog_id, $secondary_blog_id ), $queried_blog_ids );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $record_query );
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			delete_option( 'wcpay_one_and_done_permanently_ineligible' );
			delete_transient( 'wcpay_one_and_done_eligible' );
			wpmu_delete_blog( $secondary_blog_id, true );
		}
	}

	/**
	 * Create a service with an eligible live account.
	 *
	 * @param int $now Current timestamp.
	 * @return NoticeService
	 */
	private function create_live_notice_service( int $now ): NoticeService {
		return $this->create_notice_service( $now, array() );
	}

	/**
	 * Create a service whose account answers as an eligible live store's, except where overridden.
	 *
	 * @param int                $now       Current timestamp.
	 * @param array<string,bool> $overrides Account service answers that differ.
	 * @return NoticeService
	 */
	private function create_notice_service( int $now, array $overrides ): NoticeService {
		$answers = array_merge(
			array(
				'has_working_account'  => true,
				'can_process_payments' => true,
				'has_live_account'     => true,
				'has_test_account'     => false,
				'is_test_mode_enabled' => false,
				'is_dev_mode_enabled'  => false,
			),
			$overrides
		);
		$account = $this->createMock( WooPaymentsAccountService::class );
		foreach ( $answers as $method => $answer ) {
			$account->method( $method )->willReturn( $answer );
		}
		$sut = new NoticeService( static fn(): int => $now );
		$sut->init( $account, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ) );

		return $sut;
	}

	/**
	 * Create a paid order for notice eligibility tests.
	 *
	 * @param string $payment_method Payment gateway ID.
	 * @param string $mode           WooPayments mode.
	 * @param int    $created_at     Creation timestamp.
	 * @return WC_Order
	 */
	private function create_paid_order( string $payment_method, string $mode, int $created_at ): WC_Order {
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( $payment_method );
		$order->set_status( OrderStatus::COMPLETED );
		$order->set_date_created( $created_at );
		if ( '' !== $mode ) {
			$order->update_meta_data( '_wcpay_mode', $mode );
		}
		$order->save();

		return $order;
	}
}
