<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Admin\Suggestions\Incentives;

use Automattic\WooCommerce\Internal\Admin\Suggestions\Incentives\Incentive;
use Automattic\WooCommerce\Internal\Admin\Suggestions\Incentives\WooPayments;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\LegacyRuntimeProxy;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * WooPayments incentive provider test.
 *
 * @class WooPayments
 */
class WooPaymentsTest extends WC_Unit_Test_Case {
	/**
	 * The option storing whether the store had WooPayments in use.
	 *
	 * @var string
	 */
	private const HAD_WOOPAYMENTS_OPTION = 'woocommerce_admin_pes_incentive_suggestion1_store_had_woopayments';

	/**
	 * The option storing the logic version that determined the store had WooPayments value.
	 *
	 * @var string
	 */
	private const HAD_WOOPAYMENTS_VERSION_OPTION = self::HAD_WOOPAYMENTS_OPTION . '_version';

	/**
	 * The system under test.
	 *
	 * @var WooPayments
	 */
	protected $sut;

	/**
	 * WooPayments legacy runtime mock.
	 *
	 * @var WooPaymentsLegacyRuntime|MockObject
	 */
	protected $legacy_runtime;

	/**
	 * The incentive's suggestion ID.
	 *
	 * @var string
	 */
	protected string $suggestion_id;

	/**
	 * The ID of the store admin user.
	 *
	 * @var int
	 */
	protected $store_admin_id;

	/**
	 * Response mock.
	 *
	 * @var callable
	 */
	private $response_mock_ref;

	/**
	 * Error response mock.
	 *
	 * @var callable
	 */
	private $error_response_mock_ref;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->store_admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->store_admin_id );

		$this->suggestion_id = 'suggestion1';

		$this->legacy_runtime = $this->getMockBuilder( WooPaymentsLegacyRuntime::class )
			->onlyMethods( array( 'is_loaded', 'has_live_cached_account_data' ) )
			->getMock();
		$this->sut            = new WooPayments( $this->suggestion_id, $this->legacy_runtime );

		// Mock the response from the API.
		$this->response_mock_ref = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'https://public-api.wordpress.com/wpcom/v2/wcpay/incentives' ) ) {
				return array(
					'success'  => true,
					'body'     => wp_json_encode(
						array(
							array(
								'id'        => 'incentive1',
								'promo_id'  => 'promo_id',
								'type'      => 'type1',
								'something' => 'else',
							),
							array(), // Invalid empty incentive.
							array(
								'id' => 'id', // Invalid incentive that is missing promo ID and type.
							),
							array(
								'id'        => 'incentive2',
								'promo_id'  => 'promo_id',
								'type'      => 'type2',
								'something' => 'else',
							),
							array(
								'type' => 'type', // Invalid incentive that is missing ID and promo ID.
							),
							array(
								'id'       => 'incentive3',
								'promo_id' => 'promo_id', // Invalid incentive that is missing type.
							),
						)
					),
					'response' => array(
						'code' => 200,
					),
				);
			}

			return $preempt;
		};

		$this->error_response_mock_ref = function ( $preempt, $parsed_args, $url ) {
			if ( str_contains( $url, 'https://public-api.wordpress.com/wpcom/v2/wcpay/incentives' ) ) {
				return new \WP_Error( 'http_request_failed', 'Error.' );
			}

			return $preempt;
		};

		delete_user_meta( $this->store_admin_id, Incentive::PREFIX . 'dismissed' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );

		delete_option( self::HAD_WOOPAYMENTS_OPTION );
		delete_option( self::HAD_WOOPAYMENTS_VERSION_OPTION );
		delete_option( 'wcpay_account_data' );

		$this->sut->clear_cache();

		parent::tearDown();
	}

	/**
	 * Configure the runtime result used while deriving the incentives context.
	 *
	 * @param bool $has_live_account_data Whether the runtime has meaningful live account data.
	 */
	private function mock_runtime_for_incentives_context( bool $has_live_account_data ): void {
		$this->legacy_runtime->method( 'is_loaded' )->willReturn( false );
		$this->legacy_runtime->method( 'has_live_cached_account_data' )->willReturn( $has_live_account_data );
	}

	/**
	 * Test getting all incentives caches remote response.
	 */
	public function test_get_all_caches_remote_response() {
		// Arrange.
		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		// Act.
		$result = $this->sut->get_all( 'US' );

		// Assert.
		$this->assertCount( 2, $result );
		$this->assertSame( 'incentive1', $result[0]['id'] );
		$this->assertSame( 'incentive2', $result[1]['id'] );

		// Test that the memo is used.
		// Arrange.
		// No further requests should be made.
		add_filter( 'pre_http_request', fn() => $this->fail( 'wp_remote_get should not be called' ), 99, 3 );

		// Act.
		$result = $this->sut->get_all( 'US' );

		// Assert.
		$this->assertCount( 2, $result );
		$this->assertSame( 'incentive1', $result[0]['id'] );
		$this->assertSame( 'incentive2', $result[1]['id'] );

		// Test that the DB cache is used.
		// Arrange.
		// Remove the request filter to test that a new request should return the cached data.
		remove_filter( 'pre_http_request', $this->response_mock_ref );
		$this->sut->reset_memo();

		// Act.
		$result = $this->sut->get_all( 'US' );

		// Assert.
		$this->assertCount( 2, $result );
	}

	/**
	 * Test getting all incentives caches remote response error.
	 */
	public function test_get_all_caches_error() {
		// Arrange.
		remove_filter( 'pre_http_request', $this->response_mock_ref );
		add_filter( 'pre_http_request', $this->error_response_mock_ref, 10, 3 );

		// Act.
		$result = $this->sut->get_all( 'US' );

		// Assert.
		$this->assertCount( 0, $result );

		// Test that the memo is used.
		// Arrange.
		// No further requests should be made.
		add_filter( 'pre_http_request', fn() => $this->fail( 'wp_remote_get should not be called' ), 99, 3 );

		// Act.
		$result = $this->sut->get_all( 'US' );

		// Assert.
		$this->assertCount( 0, $result );

		// Test that the DB cache is used, even for errors.
		// Arrange.
		// Remove the request filter to test that a new request should return the cached data.
		remove_filter( 'pre_http_request', $this->error_response_mock_ref );
		$this->sut->reset_memo();

		// Act.
		$result = $this->sut->get_all( 'US' );

		// Assert.
		$this->assertCount( 0, $result );
	}

	/**
	 * Test is_visible skips extension active check.
	 */
	public function test_is_visible_skips_extension_active_check() {
		// Arrange.
		$this->legacy_runtime
			->expects( $this->never() )
			->method( 'is_loaded' );
		$this->legacy_runtime
			->method( 'has_live_cached_account_data' )
			->willReturn( false );

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$filter_callback = fn( $caps ) => array( 'manage_woocommerce' => true );
		add_filter( 'user_has_cap', $filter_callback );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		// Act.
		$result = $this->sut->is_visible( 'incentive1', 'US', true );

		// Assert.
		$this->assertTrue( $result );

		// Clean up.
		remove_filter( 'user_has_cap', $filter_callback );
	}

	/**
	 * Test is_visible when WooPayments is active and has no account data.
	 */
	public function test_is_visible_with_extension_active_and_no_account_data() {
		// Arrange.
		$this->legacy_runtime->method( 'is_loaded' )->willReturn( true );
		$this->legacy_runtime->method( 'has_live_cached_account_data' )->willReturn( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		// Act.
		$result = $this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertTrue( $result );
	}

	/**
	 * Test is_visible when WooPayments is active and has account data.
	 */
	public function test_is_visible_with_extension_active_and_has_account_data() {
		// Arrange.
		$this->legacy_runtime->method( 'is_loaded' )->willReturn( true );
		$this->legacy_runtime->method( 'has_live_cached_account_data' )->willReturn( true );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		// Act.
		$result = $this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertFalse( $result );
	}

	/**
	 * @testdox Should read WooPayments runtime state through WooPaymentsLegacyRuntime.
	 */
	public function test_runtime_access_is_centralized(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a local source file for a boundary assertion.
		$source = (string) file_get_contents( WC()->plugin_path() . '/src/Internal/Admin/Suggestions/Incentives/WooPayments.php' );

		$this->assertStringNotContainsString( "class_exists( '\\WC_Payments' )", $source, 'WooPayments incentives should ask WooPaymentsLegacyRuntime whether WooPayments is loaded.' );
		$this->assertStringNotContainsString( "'wcpay_account_data'", $source, 'WooPayments incentives should read WooPayments account cache through WooPaymentsLegacyRuntime.' );
	}

	/**
	 * Test that test-mode WooPayments orders don't mark the store as having had WooPayments.
	 */
	public function test_incentives_context_ignores_test_mode_woopayments_orders() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'no', get_option( self::HAD_WOOPAYMENTS_OPTION ) );

		// Clean up.
		$order->delete( true );
	}

	/**
	 * Test that live-mode WooPayments orders mark the store as having had WooPayments.
	 */
	public function test_incentives_context_counts_live_mode_woopayments_orders() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_mode', 'prod' );
		$order->save();

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'yes', get_option( self::HAD_WOOPAYMENTS_OPTION ) );

		// Clean up.
		$order->delete( true );
	}

	/**
	 * Test that WooPayments orders without the order mode meta don't mark the store as having had WooPayments.
	 *
	 * Such orders predate WooPayments saving the meta, or were created outside the checkout flow
	 * that saves it. Erring towards incentive eligibility is intentional.
	 */
	public function test_incentives_context_ignores_woopayments_orders_without_mode_meta() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->save();

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'no', get_option( self::HAD_WOOPAYMENTS_OPTION ) );

		// Clean up.
		$order->delete( true );
	}

	/**
	 * Test that test-drive account data doesn't mark the store as having had WooPayments.
	 */
	public function test_incentives_context_ignores_test_drive_account_data() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'    => '123',
					'is_live'       => false,
					'is_test_drive' => true,
				),
			)
		);

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'no', get_option( self::HAD_WOOPAYMENTS_OPTION ) );
	}

	/**
	 * Test that sandbox account data doesn't mark the store as having had WooPayments.
	 */
	public function test_incentives_context_ignores_sandbox_account_data() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id' => '123',
					'is_live'    => false,
				),
			)
		);

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'no', get_option( self::HAD_WOOPAYMENTS_OPTION ) );
	}

	/**
	 * Test that live account data marks the store as having had WooPayments.
	 */
	public function test_incentives_context_counts_live_account_data() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( true );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		update_option(
			'wcpay_account_data',
			array(
				'data' => array(
					'account_id'    => '123',
					'is_live'       => true,
					'is_test_drive' => false,
				),
			)
		);

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'yes', get_option( self::HAD_WOOPAYMENTS_OPTION ) );
	}

	/**
	 * Test that account data written before WooPayments saved the mode flags still counts.
	 */
	public function test_incentives_context_counts_account_data_without_mode_flags() {
		// Arrange.
		$proxy = new LegacyRuntimeProxy( false );
		$proxy->set_account_data( array( 'data' => array( 'account_id' => '123' ) ) );
		$runtime = new WooPaymentsLegacyRuntime();
		$runtime->init( $proxy );
		$this->sut = new WooPayments( $this->suggestion_id, $runtime );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		delete_option( self::HAD_WOOPAYMENTS_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'yes', get_option( self::HAD_WOOPAYMENTS_OPTION ) );
	}

	/**
	 * Test that a stored positive determined by an earlier logic version is re-determined.
	 *
	 * This is what keeps a value frozen by an earlier revision of this logic - or by an older
	 * WooPayments version writing the same option - from disqualifying the store forever.
	 */
	public function test_incentives_context_redetermines_stale_positive() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		// A positive stored without a logic version, as an earlier revision would have left it.
		update_option( self::HAD_WOOPAYMENTS_OPTION, 'yes' );
		delete_option( self::HAD_WOOPAYMENTS_VERSION_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'no', get_option( self::HAD_WOOPAYMENTS_OPTION ) );
		$this->assertSame( 2, (int) get_option( self::HAD_WOOPAYMENTS_VERSION_OPTION ) );
	}

	/**
	 * Test that a stored positive determined by the current logic version is trusted.
	 */
	public function test_incentives_context_trusts_current_positive() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		update_option( self::HAD_WOOPAYMENTS_OPTION, 'yes' );
		update_option( self::HAD_WOOPAYMENTS_VERSION_OPTION, 2 );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'yes', get_option( self::HAD_WOOPAYMENTS_OPTION ) );
	}

	/**
	 * Test that a stored negative is trusted regardless of the logic version that determined it.
	 *
	 * Each revision of the logic is stricter than the one before it, so a store that didn't
	 * qualify under an earlier revision can't start qualifying under the current one.
	 */
	public function test_incentives_context_trusts_stale_negative() {
		// Arrange.
		$this->mock_runtime_for_incentives_context( false );

		add_filter( 'pre_http_request', $this->response_mock_ref, 10, 3 );

		// A live-mode order that would determine a positive if the stored value were re-determined.
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( '_wcpay_mode', 'prod' );
		$order->save();

		// A negative stored without a logic version, as an earlier revision would have left it.
		update_option( self::HAD_WOOPAYMENTS_OPTION, 'no' );
		delete_option( self::HAD_WOOPAYMENTS_VERSION_OPTION );

		// Act.
		$this->sut->is_visible( 'incentive1', 'US' );

		// Assert.
		$this->assertSame( 'no', get_option( self::HAD_WOOPAYMENTS_OPTION ) );

		// Clean up.
		$order->delete( true );
	}
}
