<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\FakeWooPayMailchimpBlocksIntegration;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\FakeWooPayPointsRewardsBlocksIntegration;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay\FakeWooPayPointsRewardsManager;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- Focused test doubles live next to the tests they support.

/**
 * Subscription product fixture.
 */
class FakeWooPaySubscriptionProduct extends \WC_Product_Simple {
	/**
	 * Get the product type.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'subscription';
	}
}

/**
 * WooPay session service with controllable optional-extension predicates.
 */
class TestableWooPaySessionService extends WooPaymentsWooPaySessionService {
	/**
	 * Whether a product is a pre-order charged upon release.
	 *
	 * @var bool
	 */
	public bool $preorder_product_charged_on_release = false;

	/**
	 * Whether a booking product requires confirmation.
	 *
	 * @var bool
	 */
	public bool $booking_product_requires_confirmation = false;

	/**
	 * Whether the cart has a pre-order charged upon release.
	 *
	 * @var bool
	 */
	public bool $cart_preorder_charged_on_release = false;

	/**
	 * Whether the cart contains a subscription.
	 *
	 * @var bool
	 */
	public bool $cart_contains_subscription = false;

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Product|null $product Product being checked.
	 */
	protected function is_woopay_preorder_product_charged_on_release( ?\WC_Product $product ): bool {
		unset( $product );

		return $this->preorder_product_charged_on_release;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param \WC_Product $product Product being checked.
	 */
	protected function is_woopay_booking_product_requiring_confirmation( \WC_Product $product ): bool {
		unset( $product );

		return $this->booking_product_requires_confirmation;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_woopay_cart_preorder_charged_on_release(): bool {
		return $this->cart_preorder_charged_on_release;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function woopay_cart_contains_subscription(): bool {
		return $this->cart_contains_subscription;
	}
}

/**
 * Tests for the WooPaymentsWooPaySessionService class.
 */
class WooPaymentsWooPaySessionServiceTest extends WC_Unit_Test_Case {

	/**
	 * Original WooCommerce session object.
	 *
	 * @var object|null
	 */
	private $original_session;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_session = WC()->session;
		$this->reset_frontend_surface_state();
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( array $gateways ): array {
				$gateway = new class() extends \WC_Payment_Gateway {
					/**
					 * Build the available base-gateway fixture.
					 */
					public function __construct() {
						$this->id      = 'woocommerce_payments';
						$this->enabled = 'yes';
					}

					/**
					 * Process a fixture payment.
					 *
					 * @param int $order_id Order ID.
					 * @return array<string,mixed>
					 */
					public function process_payment( $order_id ) {
						unset( $order_id );

						return array();
					}
				};

				$gateways['woocommerce_payments'] = $gateway;

				return $gateways;
			}
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		WC()->session = $this->original_session;
		wc_empty_cart();
		delete_option( 'wcpay_woopay_checkout_appearance' );
		delete_option( 'wcpay_styles_cache_version' );
		delete_option( 'woopay_enabled_adapted_extensions' );
		delete_option( 'wc_points_rewards_redeem_points_ratio' );
		delete_option( 'woocommerce_enable_guest_checkout' );
		FakeWooPayPointsRewardsManager::$points = array();
		remove_theme_mod( 'custom_logo' );
		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::delete_option(
				array(
					'blog_token',
					'id',
					'time_diff',
				)
			);
		}
		remove_all_filters( 'woocommerce_woopayments_woopay_blog_id' );
		remove_all_filters( 'woocommerce_woopayments_woopay_blog_token' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'rest_pre_dispatch' );
		remove_all_filters( 'woocommerce_store_api_disable_nonce_check' );
		remove_all_filters( 'woocommerce_available_payment_gateways' );
		remove_all_filters( 'wcpay_woopay_enabled' );
		remove_all_filters( 'wcpay_woopay_button_is_product_supported' );
		remove_all_filters( 'wcpay_platform_checkout_button_are_cart_items_supported' );
		remove_all_filters( 'pre_option_woocommerce_enable_guest_checkout' );
		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_CART_TOKEN'], $_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'], $_REQUEST['rest_route'] );
		remove_filter( 'wcpay_is_woopay_store_api_request', '__return_true' );
		remove_filter( 'woocommerce_gc_account_session_timeout_minutes', '__return_false' );
		remove_all_filters( 'wcpay_woopay_is_signed_with_blog_token' );
		wp_clear_scheduled_hook( 'woopay_restore_order_customer_id' );
		remove_all_filters( 'woocommerce_geolocate_ip' );
		delete_option( 'woocommerce_woocommerce_payments_woopay_available_countries' );
		$this->reset_real_blog_token_signed();
		$this->reset_frontend_surface_state();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Reset shopper-surface globals that earlier broad-suite tests may leave behind.
	 */
	private function reset_frontend_surface_state(): void {
		remove_all_filters( 'woocommerce_is_checkout' );
		remove_all_filters( 'woocommerce_is_cart' );
		remove_all_filters( 'woocommerce_is_product' );
		delete_option( 'woocommerce_checkout_page_id' );
		delete_option( 'woocommerce_cart_page_id' );
		delete_option( 'woocommerce_checkout_company_field' );
		delete_option( 'woocommerce_checkout_address_2_field' );
		delete_option( 'woocommerce_checkout_phone_field' );
		delete_option( 'woocommerce_enable_guest_checkout' );
		$this->reset_cart_checkout_page_cache();
		unset( $GLOBALS['post'], $GLOBALS['product'] );
		wp_reset_postdata();
		$this->go_to( home_url( '/' ) );
	}

	/**
	 * Reset cached cart/checkout page checks between simulated requests.
	 */
	private function reset_cart_checkout_page_cache(): void {
		if ( ! class_exists( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class ) ) {
			return;
		}

		foreach ( array( 'is_cart_page', 'is_checkout_page' ) as $property_name ) {
			$property = new \ReflectionProperty( \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * @testdox Should build WooPay REST URLs from the default host.
	 */
	public function test_builds_woopay_rest_urls_from_default_host(): void {
		$sut = $this->create_service();

		$this->assertSame( 'https://pay.woo.com', $sut->get_woopay_url() );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/init', $sut->get_woopay_rest_url( 'init' ) );
	}

	/**
	 * @testdox Should require platform checkout to treat WooPay as enabled.
	 */
	public function test_woopay_requires_platform_checkout_to_be_enabled(): void {
		$sut = $this->create_service(
			array(
				'platform_checkout'                 => 'no',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array( 'woopay' ),
			)
		);

		$this->assertFalse( $sut->is_woopay_enabled() );

		$sut = $this->create_service(
			array(
				'platform_checkout'                 => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array(),
			)
		);

		$this->assertTrue( $sut->is_woopay_enabled() );

		$sut = $this->create_service(
			array(
				'platform_checkout' => 'yes',
			),
			array(
				'platform_checkout_eligible' => false,
			)
		);

		$this->assertFalse( $sut->is_woopay_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * @testdox Should not treat WooPay as enabled for restricted account status $status.
	 * @dataProvider restricted_account_statuses
	 *
	 * @param string $status Restricted account status.
	 */
	public function test_woopay_is_not_enabled_for_restricted_accounts( string $status ): void {
		$sut = $this->create_service(
			array( 'platform_checkout' => 'yes' ),
			array( 'status' => $status )
		);

		$this->assertFalse( $sut->is_woopay_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * Restricted account statuses.
	 *
	 * @return array<string,array{string}>
	 */
	public function restricted_account_statuses(): array {
		return array(
			'under review' => array( 'under_review' ),
			'rejected'     => array( 'rejected.fraud' ),
		);
	}

	/**
	 * @testdox Should not treat WooPay as enabled for invalid account data.
	 * @dataProvider invalid_woopay_accounts
	 *
	 * @param array<string,mixed> $account_data Invalid account data.
	 */
	public function test_woopay_is_not_enabled_for_invalid_accounts( array $account_data ): void {
		$sut = $this->create_service(
			array( 'platform_checkout' => 'yes' ),
			$account_data
		);

		$this->assertFalse( $sut->is_woopay_enabled() );
		$this->assertFalse( $sut->get_woopay_frontend_config( 'checkout' )['isWooPayEnabled'] );
	}

	/**
	 * Invalid WooPay accounts.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function invalid_woopay_accounts(): array {
		return array(
			'missing account'           => array( array( 'account_id' => '' ) ),
			'details not submitted'     => array( array( 'details_submitted' => false ) ),
			'missing card payments'     => array( array( 'capabilities' => array() ) ),
			'card payments unrequested' => array( array( 'capabilities' => array( 'card_payments' => 'unrequested' ) ) ),
		);
	}

	/**
	 * @testdox Should not show the checkout WooPay button unless the checkout express method is enabled.
	 */
	public function test_woopay_checkout_button_requires_checkout_express_method(): void {
		$sut = $this->create_service(
			array(
				'platform_checkout'                 => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array(),
				'express_checkout_checkout_methods' => array(),
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertFalse( $config['shouldShowWooPayButton'] );
	}

	/**
	 * @testdox Should show the WooPay button for a supported plain cart when guest checkout is enabled.
	 */
	public function test_woopay_button_shows_for_plain_guest_cart(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$this->assertTrue( $this->create_service()->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should hide the WooPay button when the base gateway is filtered out of available gateways.
	 */
	public function test_woopay_button_requires_available_base_gateway(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$enabled_filter_calls = 0;
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( array $gateways ): array {
				unset( $gateways['woocommerce_payments'] );

				return $gateways;
			},
			20
		);
		add_filter(
			'wcpay_woopay_enabled',
			static function ( bool $enabled ) use ( &$enabled_filter_calls ): bool {
				++$enabled_filter_calls;

				return $enabled;
			}
		);

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'cart' ) );
		$this->assertSame( 0, $enabled_filter_calls, 'Gateway availability should be checked before filtered WooPay enablement.' );
	}

	/**
	 * @testdox Should preserve the one-argument WooPay enabled filter and evaluate it once.
	 */
	public function test_woopay_button_applies_enabled_filter_once(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$captured_calls = array();
		add_filter(
			'wcpay_woopay_enabled',
			static function ( ...$args ) use ( &$captured_calls ): bool {
				$captured_calls[] = $args;

				return false;
			},
			10,
			99
		);

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'cart' ) );
		$this->assertCount( 1, $captured_calls );
		$this->assertCount( 1, $captured_calls[0], 'The preserved enabled filter should receive only the boolean enabled state.' );
		$this->assertTrue( $captured_calls[0][0] );
	}

	/**
	 * @testdox Should keep global WooPay capability enabled when the button filter hides the button.
	 */
	public function test_woopay_button_filter_does_not_disable_global_enablement(): void {
		$enabled_filter_calls = 0;
		add_filter(
			'wcpay_woopay_enabled',
			static function () use ( &$enabled_filter_calls ): bool {
				++$enabled_filter_calls;

				return false;
			}
		);

		$this->assertTrue( $this->create_service()->is_woopay_enabled() );
		$this->assertSame( 0, $enabled_filter_calls, 'The button filter should not participate in global WooPay capability.' );
	}

	/**
	 * @testdox Should evaluate the button filter once while keeping frontend capability fields internally consistent.
	 */
	public function test_woopay_frontend_config_applies_button_filter_once(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$enabled_filter_calls = 0;
		add_filter(
			'wcpay_woopay_enabled',
			static function () use ( &$enabled_filter_calls ): bool {
				++$enabled_filter_calls;

				return false;
			}
		);

		$config = $this->create_service()->get_woopay_frontend_config( 'cart' );

		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['shouldShowWooPayButton'] );
		$this->assertSame( 1, $enabled_filter_calls );
	}

	/**
	 * @testdox Should evaluate filtered enablement before rejecting an unsupported context.
	 */
	public function test_woopay_button_checks_filtered_enablement_before_context(): void {
		$enabled_filter_calls = 0;
		add_filter(
			'wcpay_woopay_enabled',
			static function ( bool $enabled ) use ( &$enabled_filter_calls ): bool {
				++$enabled_filter_calls;

				return $enabled;
			}
		);

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'pay_for_order' ) );
		$this->assertSame( 1, $enabled_filter_calls );
	}

	/**
	 * @testdox Should preserve the observable WooPay eligibility guard order.
	 */
	public function test_woopay_button_preserves_oracle_guard_order(): void {
		$events = array();
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( array $gateways ) use ( &$events ): array {
				$events[] = 'gateway';

				return $gateways;
			},
			20
		);
		add_filter(
			'wcpay_woopay_enabled',
			static function ( bool $enabled ) use ( &$events ): bool {
				$events[] = 'enabled';

				return $enabled;
			}
		);
		add_filter(
			'wcpay_woopay_button_is_product_supported',
			static function ( bool $supported ) use ( &$events ): bool {
				$events[] = 'product';

				return $supported;
			}
		);
		add_filter(
			'pre_option_woocommerce_enable_guest_checkout',
			static function () use ( &$events ): string {
				$events[] = 'guest';

				return 'yes';
			}
		);
		$this->set_current_woopay_product( $this->create_woopay_product() );

		$result = $this->create_service(
			array(),
			array(),
			null,
			static function ( string $event ) use ( &$events ): void {
				$events[] = $event;
			}
		)->should_show_woopay_button( 'product' );

		$this->assertTrue( $result );
		$this->assertSame( array( 'gateway', 'account', 'account', 'account', 'account', 'account', 'enabled', 'location', 'product', 'guest' ), $events );
	}

	/**
	 * @testdox Should reject WooPay button contexts outside product, cart, and checkout.
	 */
	public function test_woopay_button_rejects_unsupported_context(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'pay_for_order' ) );
	}

	/**
	 * @testdox Should honor per-location WooPay express checkout configuration.
	 * @dataProvider disabled_woopay_context_provider
	 *
	 * @param string $context Express checkout context.
	 */
	public function test_woopay_button_honors_context_configuration( string $context ): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$this->assertFalse(
			$this->create_service(
				array( 'express_checkout_' . $context . '_methods' => array() )
			)->should_show_woopay_button( $context )
		);
	}

	/**
	 * Provide WooPay button contexts.
	 *
	 * @return array<string,array{string}>
	 */
	public function disabled_woopay_context_provider(): array {
		return array(
			'product'  => array( 'product' ),
			'cart'     => array( 'cart' ),
			'checkout' => array( 'checkout' ),
		);
	}

	/**
	 * @testdox Should hide the product WooPay button when no current product exists.
	 */
	public function test_woopay_product_button_requires_current_product(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		unset( $GLOBALS['product'] );

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the WooPay button for external products.
	 */
	public function test_woopay_product_button_rejects_external_product(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product( \WC_Product_External::class ) );

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the product WooPay button for pre-orders charged on release.
	 */
	public function test_woopay_product_button_rejects_preorder_charged_on_release(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product() );
		$sut                                      = $this->create_service();
		$sut->preorder_product_charged_on_release = true;

		$this->assertFalse( $sut->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the product WooPay button for bookings requiring confirmation.
	 */
	public function test_woopay_product_button_rejects_booking_requiring_confirmation(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product() );
		$sut                                        = $this->create_service();
		$sut->booking_product_requires_confirmation = true;

		$this->assertFalse( $sut->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the product WooPay button when the product is not purchasable.
	 */
	public function test_woopay_product_button_requires_purchasable_product(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$product->set_price( '' );
		$product->save();
		$this->set_current_woopay_product( $product );

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the product WooPay button when the product is out of stock.
	 */
	public function test_woopay_product_button_requires_in_stock_product(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$product->set_stock_status( 'outofstock' );
		$product->save();
		$this->set_current_woopay_product( $product );

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should preserve the WooPay product support filter contract.
	 */
	public function test_woopay_product_button_honors_product_support_filter(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$this->set_current_woopay_product( $product );
		add_filter(
			'wcpay_woopay_button_is_product_supported',
			function ( $supported, $filtered_product ) use ( $product ): bool {
				$this->assertTrue( $supported );
				$this->assertSame( $product, $filtered_product );

				return false;
			},
			10,
			2
		);

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the cart WooPay button for pre-orders charged on release.
	 */
	public function test_woopay_cart_button_rejects_preorder_charged_on_release(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$sut                                   = $this->create_service();
		$sut->cart_preorder_charged_on_release = true;

		$this->assertFalse( $sut->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should preserve the WooPay cart-items support filter contract.
	 */
	public function test_woopay_cart_button_honors_cart_support_filter(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		add_filter(
			'wcpay_platform_checkout_button_are_cart_items_supported',
			function ( $supported ): bool {
				$this->assertTrue( $supported );

				return false;
			}
		);

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should hide product WooPay for guest subscription shoppers.
	 */
	public function test_woopay_product_button_rejects_guest_subscription(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product( FakeWooPaySubscriptionProduct::class ) );

		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide cart WooPay for guest subscription shoppers.
	 */
	public function test_woopay_cart_button_rejects_guest_subscription(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$sut                             = $this->create_service();
		$sut->cart_contains_subscription = true;

		$this->assertFalse( $sut->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should hide WooPay for guests when guest checkout is disabled by default.
	 */
	public function test_woopay_button_requires_guest_checkout_for_guests(): void {
		$this->assertFalse( $this->create_service()->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should keep subscription products eligible for logged-in shoppers.
	 */
	public function test_woopay_product_button_allows_logged_in_subscription(): void {
		wp_set_current_user( 1 );
		$this->set_current_woopay_product( $this->create_woopay_product( FakeWooPaySubscriptionProduct::class ) );

		$this->assertTrue( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should resolve the WooPay product from a product_page ID shortcode.
	 */
	public function test_woopay_product_button_supports_product_page_shortcode(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$this->set_current_woopay_shortcode_page( '[product_page id="' . $product->get_id() . '"]' );

		$this->assertTrue( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should resolve flexible product_page shortcode attributes and quoting.
	 */
	public function test_woopay_product_button_supports_flexible_product_page_shortcode(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$this->set_current_woopay_shortcode_page( "[product_page class='featured' columns='3' id='" . $product->get_id() . "']" );

		$this->assertTrue( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should resolve product_page shortcode products by SKU.
	 */
	public function test_woopay_product_button_supports_product_page_shortcode_sku(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$product->set_sku( 'woopay-shortcode-sku' );
		$product->save();
		$this->set_current_woopay_shortcode_page( "[product_page class='featured' sku='woopay-shortcode-sku']" );

		$this->assertTrue( $this->create_service()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should keep subscription carts eligible for logged-in shoppers.
	 */
	public function test_woopay_cart_button_allows_logged_in_subscription(): void {
		wp_set_current_user( 1 );
		$sut                             = $this->create_service();
		$sut->cart_contains_subscription = true;

		$this->assertTrue( $sut->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should not leak optional extension aliases to later tests.
	 *
	 * Runs isolated on purpose. The assertion is about what *this* test class
	 * defines, but class definitions are process-global and cannot be undone, so
	 * in a shared process it also sees classes other suites define — for example
	 * NativeWooPaymentsGatewayTest evals a WC_Subscriptions_Cart double. A fresh
	 * process is what makes the assertion mean what its name says.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_woopay_optional_extension_aliases_do_not_leak(): void {
		$this->assertFalse( class_exists( 'WC_Pre_Orders_Product', false ) );
		$this->assertFalse( class_exists( 'WC_Pre_Orders_Cart', false ) );
		$this->assertFalse( class_exists( 'WC_Product_Booking', false ) );
		$this->assertFalse( class_exists( 'WC_Subscriptions_Cart', false ) );
	}

	/**
	 * @testdox Should generate the reference WooPay request signature.
	 */
	public function test_generates_reference_request_signature(): void {
		add_filter( 'woocommerce_woopayments_woopay_blog_id', static fn() => '12345' );
		add_filter( 'woocommerce_woopayments_woopay_blog_token', static fn() => 'blog-token' );

		$sut      = $this->create_service();
		$expected = hash_hmac( 'sha512', '12345' . floor( time() / 30 ), 'blog-token' );

		$this->assertSame( $expected, $sut->get_woopay_request_signature() );
	}

	/**
	 * @testdox Should preserve the WooPay string user session in init payloads.
	 */
	public function test_preserves_string_user_session_in_init_payloads(): void {
		$sut    = $this->create_service();
		$result = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );

		$this->assertSame( 'qwerty123', $result['user_session'] );
	}

	/**
	 * @testdox Should fall back to the theme custom logo in WooPay init session store data.
	 */
	public function test_init_session_request_uses_theme_custom_logo_when_woopay_logo_is_not_configured(): void {
		$custom_logo_id = 123;
		$filter         = static function ( $downsize, $id, $size ) use ( $custom_logo_id ) {
			if ( $custom_logo_id === $id && 'full' === $size ) {
				return array( 'https://example.test/theme-logo.png', 128, 64, false );
			}

			return $downsize;
		};
		add_filter( 'image_downsize', $filter, 10, 3 );
		set_theme_mod( 'custom_logo', $custom_logo_id );

		try {
			$sut    = $this->create_service();
			$result = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );

			$this->assertSame( 'https://example.test/theme-logo.png', $result['store_data']['store_logo'] );
		} finally {
			remove_filter( 'image_downsize', $filter, 10 );
		}
	}

	/**
	 * @testdox Should send the WooPay store logo file URL when a custom logo is configured.
	 */
	public function test_init_session_request_uses_configured_woopay_store_logo_file_url(): void {
		$sut = $this->create_service(
			array(
				'platform_checkout_store_logo' => 'file_logo',
			)
		);

		$result = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );

		$this->assertSame( get_rest_url( null, 'wc/v3/payments/file/file_logo' ), $result['store_data']['store_logo'] );
	}

	/**
	 * @testdox Should preload current Store API cart and checkout data for WooPay init sessions.
	 */
	public function test_preloads_current_store_api_cart_and_checkout_data(): void {
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'name'    => 'WooPay Preload Product',
				'virtual' => true,
			)
		);
		wc_empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$sut     = $this->create_service();
		$result  = $sut->get_init_session_request( 'shopper@example.com', 'qwerty123' );
		$preload = $result['preloaded_requests'];

		$this->assertSame( 1, $preload['cart']['items_count'] );
		$this->assertSame( $product->get_id(), $preload['cart']['items'][0]['id'] );
		$this->assertArrayHasKey( 'billing_address', $preload['checkout'] );
		$this->assertArrayHasKey( 'order_id', $preload['checkout'] );
	}

	/**
	 * @testdox Should forward WooPay Cart-Token to Store API preload subrequests.
	 */
	public function test_forwards_woopay_cart_token_to_store_api_preload_subrequests(): void {
		$seen_headers = array();
		add_filter(
			'rest_pre_dispatch',
			static function ( $preempt, $server, WP_REST_Request $request ) use ( &$seen_headers ) {
				unset( $server );
				if ( ! in_array( $request->get_route(), array( '/wc/store/v1/cart', '/wc/store/v1/checkout' ), true ) ) {
					return $preempt;
				}

				$seen_headers[ $request->get_route() ] = $request->get_header( 'Cart-Token' );

				return new WP_REST_Response(
					'/wc/store/v1/cart' === $request->get_route()
						? array(
							'items_count' => 1,
							'items'       => array(
								array( 'id' => 123 ),
							),
						)
						: array(
							'order_id'        => 456,
							'payment_methods' => array( 'woocommerce_payments' ),
						)
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/payments/woopay/session' );
		$request->set_header( 'Cart-Token', 'cart-token-123' );

		$sut     = $this->create_service();
		$result  = $sut->get_session_data( 'shopper@example.com', $request );
		$preload = $result['preloaded_requests'];

		$this->assertSame( 'cart-token-123', $seen_headers['/wc/store/v1/cart'] );
		$this->assertSame( 'cart-token-123', $seen_headers['/wc/store/v1/checkout'] );
		$this->assertSame( 1, $preload['cart']['items_count'] );
		$this->assertSame( array( 'woocommerce_payments' ), $preload['checkout']['payment_methods'] );
	}

	/**
	 * @testdox Should encrypt minimum session data with the WooPay reference shape.
	 */
	public function test_encrypts_minimum_session_data_with_reference_shape(): void {
		add_filter( 'woocommerce_woopayments_woopay_blog_id', static fn() => '12345' );
		add_filter( 'woocommerce_woopayments_woopay_blog_token', static fn() => 'blog-token' );

		$sut    = $this->create_service();
		$result = $sut->get_encrypted_minimum_session_data();

		$this->assertSame( '12345', $result['blog_id'] );
		$this->assertArrayHasKey( 'session', $result['data'] );
		$this->assertArrayHasKey( 'iv', $result['data'] );
		$this->assertArrayHasKey( 'hash', $result['data'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies WooPay encrypted payload encoding.
		$this->assertNotSame( '', base64_decode( $result['data']['session'], true ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies WooPay encrypted payload encoding.
		$this->assertNotSame( false, base64_decode( $result['data']['iv'], true ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies WooPay encrypted payload encoding.
		$this->assertNotSame( false, base64_decode( $result['data']['hash'], true ) );
	}

	/**
	 * @testdox Should persist and clear WooPay phone session data.
	 */
	public function test_persists_and_clears_woopay_phone_session_data(): void {
		$this->original_session = WC()->session;
		WC()->session           = $this->create_session();
		$sut                    = $this->create_service();

		$sut->set_woopay_phone_session_data(
			array(
				'save_user_in_woopay'     => 'true',
				'woopay_source_url'       => 'https://example.test/checkout',
				'woopay_is_blocks'        => 'yes',
				'woopay_viewport'         => 'desktop',
				'woopay_user_phone_field' => array(
					'full' => '+15555550123',
				),
			)
		);

		$this->assertSame(
			array(
				'save_user_in_woopay'     => true,
				'woopay_source_url'       => 'https://example.test/checkout',
				'woopay_is_blocks'        => true,
				'woopay_viewport'         => 'desktop',
				'woopay_user_phone_field' => array(
					'full' => '+15555550123',
				),
			),
			WC()->session->get( 'woopay-user-data' )
		);

		$sut->clear_woopay_session_data();

		$this->assertNull( WC()->session->get( 'woopay-user-data' ) );
	}

	/**
	 * @testdox Should preserve legacy flat WooPay phone values while accepting the frontend payload shape.
	 */
	public function test_persists_legacy_flat_woopay_phone_session_data(): void {
		$this->original_session = WC()->session;
		WC()->session           = $this->create_session();
		$sut                    = $this->create_service();

		$sut->set_woopay_phone_session_data(
			array(
				'phone_number' => '+15555550124',
			)
		);

		$this->assertSame(
			'+15555550124',
			WC()->session->get( 'woopay-user-data' )['woopay_user_phone_field']['full']
		);
	}

	/**
	 * @testdox Should build the preserved WooPay checkout frontend config.
	 */
	public function test_builds_woopay_checkout_frontend_config(): void {
		add_filter( 'woocommerce_woopayments_woopay_blog_id', static fn() => '12345' );
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$sut = $this->create_service();
		$sut->save_woopay_appearance(
			array( 'theme' => 'stripe' ),
			array(
				array( 'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter' ),
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertTrue( $config['isWooPayEnabled'] );
		$this->assertTrue( $config['isWoopayExpressCheckoutEnabled'] );
		$this->assertTrue( $config['isWooPayEmailInputEnabled'] );
		$this->assertFalse( $config['isWooPayDirectCheckoutEnabled'] );
		$this->assertFalse( $config['isWooPayGlobalThemeSupportEnabled'] );
		$this->assertFalse( $config['forceNetworkSavedCards'] );
		$this->assertSame( 'https://pay.woo.com', $config['woopayHost'] );
		$this->assertSame( '12345', $config['woopayMerchantId'] );
		$this->assertSame( 'checkout', $config['woopayButton']['context'] );
		$this->assertSame( 'default', $config['woopayButton']['type'] );
		$this->assertSame( 'dark', $config['woopayButton']['theme'] );
		$this->assertSame( '48', $config['woopayButton']['height'] );
		$this->assertSame( '', $config['woopayButton']['radius'] );
		$this->assertArrayHasKey( 'woopayButtonNonce', $config );
		$this->assertArrayHasKey( 'addToCartNonce', $config );
		$this->assertTrue( $config['shouldShowWooPayButton'] );
		$this->assertTrue( $config['woopayIsCountryAvailable'] );
		$this->assertNull( $config['woopayAppearance'] );
		$this->assertSame( array(), $config['woopayFontRules'] );
		$this->assertSame( 'Securely save my information for 1-click checkout', $config['woopaySaveUserLabel'] );
		$this->assertSame( 'Mobile phone number', $config['woopayPhoneLabel'] );
		$this->assertArrayHasKey( 'platformTrackerNonce', $config );
		$this->assertSame( admin_url( 'admin-ajax.php' ), $config['ajaxUrl'] );
		$this->assertArrayHasKey( 'isShopperTrackingEnabled', $config );
		$this->assertArrayHasKey( 'is_shopper_tracking_enabled', $config );
	}

	/**
	 * @testdox Should include the current frontend style extractor schema in the cache version.
	 */
	public function test_frontend_styles_cache_version_includes_current_extractor_schema(): void {
		update_option( 'wcpay_styles_cache_version', 'version-one' );

		$service = new WooPaymentsFrontendStylesService();

		$this->assertSame( 'version-one|appearance-extractor-v3', $service->get_styles_cache_version() );
	}

	/**
	 * @testdox Should enable WooPay first-party auth only when WooPay express checkout is available.
	 */
	public function test_first_party_auth_flag_requires_available_woopay_express_checkout(): void {
		$this->assertTrue(
			$this->create_service()->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'US eligible WooPay checkout should enable first-party auth.'
		);

		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );
		$this->assertFalse(
			$this->create_service( array(), array(), null, null, null, false )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Live-mode shoppers outside the synced WooPay country list should not enable first-party auth.'
		);
		remove_all_filters( 'woocommerce_geolocate_ip' );

		$this->assertFalse(
			$this->create_service( array(), array( 'platform_checkout_eligible' => false ) )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Accounts that are not eligible for platform checkout should not enable first-party auth.'
		);

		$this->assertFalse(
			$this->create_service( array( 'platform_checkout' => 'no' ) )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Stores with WooPay disabled should not enable first-party auth.'
		);

		$this->assertFalse(
			$this->create_service( array( 'express_checkout_checkout_methods' => array() ) )->get_woopay_frontend_config( 'checkout' )['isWoopayFirstPartyAuthEnabled'],
			'Checkout contexts without WooPay express checkout should not enable first-party auth.'
		);
	}

	/**
	 * @testdox Should match the extension WooPay button style settings.
	 */
	public function test_uses_extension_woopay_button_style_settings(): void {
		$sut = $this->create_service(
			array(
				'payment_request_button_size'          => 'medium',
				'payment_request_button_height'        => '44',
				'payment_request_button_border_radius' => '',
				'payment_request_button_radius'        => '9',
			)
		);

		$config = $sut->get_woopay_frontend_config( 'checkout' );

		$this->assertSame( '48', $config['woopayButton']['height'] );
		$this->assertSame( '', $config['woopayButton']['radius'] );
	}

	/**
	 * @testdox Should build WooPay express checkout params with Core-owned account data.
	 */
	public function test_builds_woopay_express_checkout_params(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_currency', 'USD' );

		$sut    = $this->create_service();
		$params = $sut->get_express_checkout_params( 'checkout' );

		$this->assertSame( 'pk_test_123', $params['stripe']['publishableKey'] );
		$this->assertSame( 'acct_123', $params['stripe']['accountId'] );
		$this->assertSame( 'checkout', $params['button_context'] );
		$this->assertSame( array( 'woopay' ), $params['enabled_methods'] );
		$this->assertSame( get_bloginfo( 'name' ), $params['store_name'] );
		$this->assertSame( 'usd', $params['checkout']['currency_code'] );
		$this->assertSame( 2, $params['checkout']['stripe_minor_unit'] );
		$this->assertSame( 'US', $params['checkout']['country_code'] );
		$this->assertSame( 'default', $params['button']['type'] );
		$this->assertArrayHasKey( 'isEceUsingConfirmationTokens', $params['flags'] );
	}

	/**
	 * @testdox Should build WooPay express checkout params with Stripe zero-decimal currency units.
	 */
	public function test_woopay_express_checkout_params_use_stripe_zero_minor_unit_for_zero_decimal_currency(): void {
		update_option( 'woocommerce_default_country', 'JP' );
		update_option( 'woocommerce_currency', 'JPY' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertSame( 'jpy', $params['checkout']['currency_code'] );
		$this->assertSame( 0, $params['checkout']['stripe_minor_unit'] );
	}

	/**
	 * @testdox Should build WooPay express checkout params with Stripe two-decimal special-case currency units.
	 */
	public function test_woopay_express_checkout_params_keep_stripe_two_minor_unit_for_special_case_currency(): void {
		update_option( 'woocommerce_default_country', 'UG' );
		update_option( 'woocommerce_currency', 'UGX' );

		$params = $this->create_service()->get_express_checkout_params( 'checkout' );

		$this->assertSame( 'ugx', $params['checkout']['currency_code'] );
		$this->assertSame( 2, $params['checkout']['stripe_minor_unit'] );
	}

	/**
	 * @testdox Should build WooPay save-user checkout data from the account cache.
	 */
	public function test_builds_woopay_save_user_checkout_data(): void {
		$sut = $this->create_service(
			array(),
			array(
				'country'                    => 'US',
				'pre_check_save_my_info'     => true,
				'platform_checkout_eligible' => true,
			)
		);

		$this->assertSame( array( 'PRE_CHECK_SAVE_MY_INFO' => true ), $sut->get_save_user_checkout_data() );
	}

	/**
	 * @testdox Should add WooPay save-user session data to order metadata.
	 */
	public function test_adds_woopay_session_data_to_order_metadata(): void {
		$this->original_session = WC()->session;
		WC()->session           = $this->create_session();
		WC()->session->set(
			'woopay-user-data',
			array(
				'save_user_in_woopay'     => true,
				'woopay_source_url'       => 'https://example.test/checkout',
				'woopay_is_blocks'        => true,
				'woopay_viewport'         => 'desktop',
				'woopay_user_phone_field' => array(
					'full' => '+15555550123',
				),
			)
		);

		$order = \WC_Helper_Order::create_order();
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_phone( '+15555550000' );
		$order->set_billing_company( 'Analytical Engines' );
		$order->set_shipping_first_name( 'Grace' );
		$order->set_shipping_last_name( 'Hopper' );
		$order->set_shipping_phone( '+15555550001' );
		$order->set_shipping_company( 'Compilers Inc' );

		$sut      = $this->create_service();
		$metadata = $sut->maybe_add_woopay_user_metadata( array( 'existing' => 'kept' ), $order );

		$this->assertSame( 'kept', $metadata['existing'] );
		$this->assertSame( 'Ada', $metadata['platform_checkout_primary_first_name'] );
		$this->assertSame( 'Lovelace', $metadata['platform_checkout_primary_last_name'] );
		$this->assertSame( '+15555550000', $metadata['platform_checkout_primary_phone'] );
		$this->assertSame( 'Analytical Engines', $metadata['platform_checkout_primary_company'] );
		$this->assertSame( 'Grace', $metadata['platform_checkout_secondary_first_name'] );
		$this->assertSame( 'Hopper', $metadata['platform_checkout_secondary_last_name'] );
		$this->assertSame( '+15555550001', $metadata['platform_checkout_secondary_phone'] );
		$this->assertSame( 'Compilers Inc', $metadata['platform_checkout_secondary_company'] );
		$this->assertSame( '+15555550123', $metadata['platform_checkout_phone'] );
		$this->assertSame( 'https://example.test/checkout', $metadata['platform_checkout_source_url'] );
		$this->assertTrue( $metadata['platform_checkout_is_blocks'] );
		$this->assertSame( 'desktop', $metadata['platform_checkout_viewport'] );
	}

	/**
	 * @testdox Should store WooPay checkout appearance in the preserved option shape.
	 */
	public function test_stores_appearance_in_preserved_option_shape(): void {
		$sut        = $this->create_service();
		$appearance = $this->get_valid_appearance();
		$font_rules = array(
			array(
				'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
			),
		);

		$sut->save_woopay_appearance( $appearance, $font_rules );

		$stored = get_option( 'wcpay_woopay_checkout_appearance' );
		$this->assertIsArray( $stored );
		$this->assertSame( $appearance, $stored['appearance'] );
		$this->assertSame( $font_rules, $stored['font_rules'] );
		$this->assertNotEmpty( $stored['version'] );
		$this->assertSame( $appearance, $sut->get_woopay_appearance() );
		$this->assertSame( $font_rules, $sut->get_woopay_font_rules() );
	}

	/**
	 * @testdox Should ignore stale WooPay appearance data when the styles cache version changes.
	 */
	public function test_ignores_stale_appearance_when_styles_cache_version_changes(): void {
		$sut        = $this->create_service();
		$appearance = $this->get_valid_appearance();

		update_option( 'wcpay_styles_cache_version', 'version-one' );
		$sut->save_woopay_appearance(
			$appearance,
			array(
				array(
					'cssSrc' => 'https://fonts.googleapis.com/css2?family=Inter',
				),
			)
		);

		update_option( 'wcpay_styles_cache_version', 'version-two' );

		$this->assertSame( array(), $sut->get_woopay_appearance() );
		$this->assertSame( array(), $sut->get_woopay_font_rules() );
		$this->assertTrue(
			$sut->maybe_save_woopay_appearance(
				array(
					'theme'     => 'night',
					'variables' => array(
						'colorText' => '#ffffff',
					),
				)
			)
		);
	}

	/**
	 * @testdox Should ignore appearance stored before the current frontend style extractor schema.
	 */
	public function test_ignores_appearance_stored_before_current_frontend_style_extractor_schema(): void {
		$sut        = $this->create_service();
		$appearance = $this->get_valid_appearance();

		update_option( 'wcpay_styles_cache_version', 'version-one' );
		update_option(
			'wcpay_woopay_checkout_appearance',
			array(
				'appearance' => $appearance,
				'font_rules' => array(),
				'version'    => 'version-one',
			)
		);

		$this->assertSame( array(), $sut->get_woopay_appearance() );
		$this->assertSame( array(), $sut->get_woopay_font_rules() );
	}

	/**
	 * @testdox Should conditionally store shopper appearance only when no appearance exists.
	 */
	public function test_conditionally_stores_shopper_appearance_only_once(): void {
		$sut    = $this->create_service();
		$first  = $this->get_valid_appearance();
		$second = array(
			'theme'     => 'night',
			'variables' => array(
				'colorText' => '#ffffff',
			),
		);

		$this->assertTrue( $sut->maybe_save_woopay_appearance( $first ) );
		$this->assertFalse( $sut->maybe_save_woopay_appearance( $second ) );
		$this->assertSame( $first, $sut->get_woopay_appearance() );
	}

	/**
	 * @testdox Should reject invalid WooPay appearance schema.
	 */
	public function test_rejects_invalid_appearance_schema(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->validate_appearance_schema( array( 'buttonTheme' => 'dark' ) ) );
		$this->assertFalse( $sut->maybe_save_woopay_appearance( array( 'buttonTheme' => 'dark' ) ) );
		$this->assertFalse( get_option( 'wcpay_woopay_checkout_appearance' ) );
	}

	/**
	 * @testdox Should forward init session requests through filterable transport.
	 */
	public function test_forwards_init_session_request_through_filterable_transport(): void {
		add_filter( 'woocommerce_woopayments_woopay_blog_id', static fn() => '12345' );
		add_filter( 'woocommerce_woopayments_woopay_blog_token', static fn() => 'blog-token' );
		if ( class_exists( '\Jetpack_Options' ) ) {
			\Jetpack_Options::update_option( 'id', 12345 );
			\Jetpack_Options::update_option( 'blog_token', 'token-key.blog-token' );
			\Jetpack_Options::update_option( 'time_diff', 0 );
		}

		$captured_request = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, array $parsed_args, string $url ) use ( &$captured_request ) {
				$captured_request = array(
					'url'     => $url,
					'body'    => json_decode( (string) $parsed_args['body'], true ),
					'headers' => $parsed_args['headers'],
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'result' => 'success' ) ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$appearance = $this->get_valid_appearance();
		$font_rules = array(
			array(
				'cssSrc' => 'https://fonts.wp.com/font.css',
				'family' => 'Inter',
			),
		);

		$expected_font_rules = array(
			array(
				'cssSrc' => 'https://fonts.wp.com/font.css',
			),
		);

		$sut    = $this->create_service();
		$result = $sut->init_woopay_session(
			array(
				'email'        => 'shopper@example.com',
				'user_session' => 'qwerty123',
				'appearance'   => wp_json_encode( $appearance ),
				'font_rules'   => wp_json_encode( $font_rules ),
			)
		);

		$this->assertSame( array( 'result' => 'success' ), $result );
		$this->assertSame( 'https://pay.woo.com/wp-json/platform-checkout/v1/init', strtok( $captured_request['url'], '?' ) );
		$query = wp_parse_url( $captured_request['url'], PHP_URL_QUERY );
		parse_str( is_string( $query ) ? $query : '', $query_args );
		$this->assertArrayHasKey( 'token', $query_args );
		$this->assertArrayHasKey( 'body-hash', $query_args );
		$this->assertArrayHasKey( 'signature', $query_args );
		$this->assertStringStartsWith( 'X_JETPACK ', $captured_request['headers']['Authorization'] );
		$this->assertSame( 'acct_123', $captured_request['body']['store_data']['account_id'] );
		$this->assertTrue( $captured_request['body']['store_data']['test_mode'] );
		$this->assertSame( 'qwerty123', $captured_request['body']['user_session'] );
		$this->assertSame( $appearance, $captured_request['body']['appearance'] );
		$this->assertSame( $expected_font_rules, $captured_request['body']['font_rules'] );
		$this->assertArrayHasKey( 'session_nonce', $captured_request['body'] );
		$this->assertArrayHasKey( 'store_api_token', $captured_request['body'] );
	}

	/**
	 * @testdox Init session request includes checkout block extension data and optional field status.
	 */
	public function test_init_session_request_includes_blocks_data_and_optional_fields_status(): void {
		$this->register_fake_mailchimp_blocks_integration();
		delete_option( 'woocommerce_checkout_page_id' );

		$sut     = $this->create_service();
		$request = $sut->get_init_session_request( 'shopper@example.com' );

		$this->assertArrayHasKey( 'mailchimp-newsletter_data', $request['store_data']['blocks_data'] );
		$this->assertSame(
			array(
				'enabled' => true,
				'source'  => 'fake-mailchimp',
			),
			$request['store_data']['blocks_data']['mailchimp-newsletter_data']
		);
		$this->assertArrayHasKey( 'company', $request['store_data']['optional_fields_status'] );
		$this->assertSame( 'required', $request['store_data']['optional_fields_status']['phone'] );
		$this->assertIsArray( $request['store_data']['checkout_schema_namespaces'] );
	}

	/**
	 * @testdox Init session request includes adapted extension data and guest email verification nonce.
	 */
	public function test_init_session_request_includes_points_and_rewards_adapted_extension_data(): void {
		if ( ! class_exists( '\WC_Points_Rewards_Manager', false ) ) {
			class_alias( FakeWooPayPointsRewardsManager::class, 'WC_Points_Rewards_Manager' );
		}

		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'points-shopper@example.com',
			)
		);
		FakeWooPayPointsRewardsManager::$points[ $user_id ] = 250;
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-points-and-rewards' ) );
		update_option( 'wc_points_rewards_redeem_points_ratio', '10:1' );

		$adapted_extensions = new WooPaymentsWooPayAdaptedExtensions();
		$adapted_extensions->register( new FakeWooPayPointsRewardsBlocksIntegration() );

		$sut     = $this->create_service( array(), array(), $adapted_extensions );
		$request = $sut->get_init_session_request( 'points-shopper@example.com' );

		$this->assertSame( array(), $request['extension_data'] );
		$this->assertSame(
			array(
				'minimum_points_amount' => 100,
				'points_ratio'          => array(
					'points'         => 10.0,
					'monetary_value' => 1.0,
				),
				'should_verify_email'   => true,
				'points_available'      => 250,
			),
			$request['adapted_extensions']['points-and-rewards']
		);

		$nonce_tick = wp_nonce_tick( 'wc_store_api' );
		$this->assertSame(
			substr( wp_hash( $nonce_tick . '|wc_store_api|' . $user_id . '|', 'nonce' ), -12, 10 ),
			$request['email_verified_session_nonce']
		);
	}

	/**
	 * @testdox Should leave the resolved user untouched when the request does not carry the WooPay user agent.
	 */
	public function test_determine_current_user_passes_through_without_woopay_user_agent(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/checkout';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should leave the resolved user untouched outside Store API requests.
	 */
	public function test_determine_current_user_passes_through_outside_store_api_requests(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/checkout/';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should leave the resolved user untouched when WooPay is disabled.
	 */
	public function test_determine_current_user_passes_through_when_woopay_disabled(): void {
		$this->simulate_woopay_store_api_request();

		$sut = $this->create_service( array( 'platform_checkout' => 'no' ) );

		$this->assertFalse( $sut->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should die with a 401 when a WooPay Store API request is not signed with the blog token.
	 */
	public function test_determine_current_user_dies_401_when_request_is_not_signed(): void {
		$this->simulate_woopay_store_api_request();

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'WooPay request is not signed correctly.' );

		$this->create_service()->determine_current_user_for_woopay( false );
	}

	/**
	 * @testdox Should detect Store API requests routed through the rest_route query argument.
	 */
	public function test_determine_current_user_covers_rest_route_query_form(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/index.php?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout';
		$_REQUEST['rest_route']     = '/wc/store/v1/checkout';

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'WooPay request is not signed correctly.' );

		try {
			$this->create_service()->determine_current_user_for_woopay( false );
		} finally {
			unset( $_REQUEST['rest_route'] );
		}
	}

	/**
	 * @testdox Should flag the request through wcpay_is_woopay_store_api_request when signed, even without a resolvable cart token.
	 */
	public function test_determine_current_user_flags_woopay_store_api_request_when_signed(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();
		$_SERVER['HTTP_CART_TOKEN'] = 'garbage-token';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertTrue( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should resolve the shopper from an authenticated cart-token session.
	 */
	public function test_determine_current_user_resolves_user_from_authenticated_cart_token(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		$user_id = $this->factory->user->create( array( 'user_email' => 'cart-token-shopper@example.com' ) );
		$this->insert_store_api_session(
			(string) $user_id,
			array(
				'id'    => (string) $user_id,
				'email' => 'cart-token-shopper@example.com',
			)
		);
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( (string) $user_id );

		$this->assertSame( $user_id, $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should resolve a WooPay-verified email to the matching store account when an adapted extension is active.
	 */
	public function test_determine_current_user_resolves_verified_email_user_when_adapted_extension_active(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		$user_id = $this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$this->insert_store_api_session(
			't_guesthash',
			array(
				'id'    => '0',
				'email' => 'verified-shopper@example.com',
			)
		);
		$_SERVER['HTTP_CART_TOKEN']                      = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'verified-shopper@example.com';

		$this->assertSame( $user_id, $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should not resolve a WooPay-verified email without an active adapted extension.
	 */
	public function test_determine_current_user_ignores_verified_email_without_adapted_extension(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		$this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		$this->insert_store_api_session(
			't_guesthash',
			array(
				'id'    => '0',
				'email' => 'verified-shopper@example.com',
			)
		);
		$_SERVER['HTTP_CART_TOKEN']                      = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'verified-shopper@example.com';

		$this->assertFalse( $this->create_service()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should detach the customer id from a verified-email guest order and schedule its restoration.
	 */
	public function test_payment_status_change_detaches_customer_id_for_verified_email_guest_order(): void {
		$this->simulate_woopay_store_api_request();

		$user_id = $this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$_SERVER['HTTP_CART_TOKEN']                      = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = 'verified-shopper@example.com';

		$order = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'verified-shopper@example.com' );
		$order->save();

		$this->create_service()->woopay_order_payment_status_changed( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 0, $order->get_customer_id() );
		$this->assertSame( (string) $user_id, $order->get_meta( 'woopay_merchant_customer_id' ) );
		$this->assertNotFalse( wp_next_scheduled( 'woopay_restore_order_customer_id', array( $order->get_id() ) ) );
	}

	/**
	 * @testdox Should not detach the customer id when no verified-email header is present.
	 */
	public function test_payment_status_change_leaves_order_untouched_without_verified_email(): void {
		$this->simulate_woopay_store_api_request();

		$user_id = $this->factory->user->create( array( 'user_email' => 'verified-shopper@example.com' ) );
		update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );

		$order = wc_create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'verified-shopper@example.com' );
		$order->save();

		$this->create_service()->woopay_order_payment_status_changed( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $user_id, $order->get_customer_id() );
		$this->assertFalse( $order->meta_exists( 'woopay_merchant_customer_id' ) );
	}

	/**
	 * @testdox Should restore the stowed customer id and remove the bookkeeping meta.
	 */
	public function test_restore_order_customer_id_restores_stowed_customer(): void {
		$user_id = $this->factory->user->create();

		$order = wc_create_order();
		$order->set_customer_id( 0 );
		$order->add_meta_data( 'woopay_merchant_customer_id', $user_id, true );
		$order->save();

		$this->create_service()->restore_order_customer_id_from_requests_with_verified_email( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $user_id, $order->get_customer_id() );
		$this->assertFalse( $order->meta_exists( 'woopay_merchant_customer_id' ) );
	}

	/**
	 * @testdox Should fall back to the WooCommerce customer's billing email when no email is supplied.
	 */
	public function test_init_session_request_falls_back_to_customer_billing_email(): void {
		WC()->customer->set_billing_email( 'billing-fallback@example.com' );

		$request = $this->create_service()->get_init_session_request( null );

		$this->assertSame( 'billing-fallback@example.com', $request['email'] );
	}

	/**
	 * @testdox Should fall back to the logged-in user's email when neither a request email nor a customer email exists.
	 */
	public function test_init_session_request_falls_back_to_logged_in_user_email(): void {
		$user_id = $this->factory->user->create( array( 'user_email' => 'account-fallback@example.com' ) );
		wp_set_current_user( $user_id );
		WC()->customer->set_billing_email( '' );
		WC()->customer->set_email( '' );

		$request = $this->create_service()->get_init_session_request( null );

		$this->assertSame( 'account-fallback@example.com', $request['email'] );
	}

	/**
	 * @testdox Should prefer the supplied email over every fallback.
	 */
	public function test_init_session_request_prefers_supplied_email(): void {
		WC()->customer->set_billing_email( 'billing-fallback@example.com' );

		$request = $this->create_service()->get_init_session_request( 'supplied@example.com' );

		$this->assertSame( 'supplied@example.com', $request['email'] );
	}

	/**
	 * @testdox Should carry the platform customer id for the current user in the init-session request.
	 */
	public function test_init_session_request_carries_platform_customer_id(): void {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$captured_user_id = null;
		$customer_service = $this->create_recording_customer_service( 'cus_test_123', $captured_user_id );

		$request = $this->create_service( array(), array(), null, null, $customer_service )->get_init_session_request( 'shopper@example.com' );

		$this->assertSame( 'cus_test_123', $request['customer_id'] );
		$this->assertSame( $user_id, $captured_user_id );
	}

	/**
	 * @testdox Should create a guest platform customer for the init-session request when nobody is logged in.
	 */
	public function test_init_session_request_creates_guest_platform_customer(): void {
		$captured_user_id = null;
		$customer_service = $this->create_recording_customer_service( 'cus_guest_456', $captured_user_id );

		$request = $this->create_service( array(), array(), null, null, $customer_service )->get_init_session_request( 'guest@example.com' );

		$this->assertSame( 'cus_guest_456', $request['customer_id'] );
		$this->assertSame( 0, $captured_user_id );
	}

	/**
	 * Build a customer-service mock recording the get-or-create call.
	 *
	 * @param string   $customer_id       Customer id to return.
	 * @param int|null $captured_user_id  Captures the user id the service was asked about.
	 * @return \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService
	 */
	private function create_recording_customer_service( string $customer_id, ?int &$captured_user_id ): \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService {
		$customer_service = $this->getMockBuilder( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_customer_id_for_user' ) )
			->getMock();
		$customer_service->method( 'get_or_create_customer_id_for_user' )->willReturnCallback(
			function ( int $user_id ) use ( $customer_id, &$captured_user_id ): string {
				$captured_user_id = $user_id;

				return $customer_id;
			}
		);

		return $customer_service;
	}

	/**
	 * @testdox Should read the WooPay save-user opt-in from the posted field or the WooPay session.
	 */
	public function test_should_save_user_in_woopay_reads_posted_and_session_flags(): void {
		$sut = $this->create_service();

		$this->assertFalse( $sut->should_save_user_in_woopay() );

		$_POST['save_user_in_woopay'] = 'true';
		$this->assertTrue( $sut->should_save_user_in_woopay() );
		unset( $_POST['save_user_in_woopay'] );

		WC()->session->set( 'woopay-user-data', array( 'save_user_in_woopay' => 'true' ) );
		$this->assertTrue( $sut->should_save_user_in_woopay() );
		WC()->session->set( 'woopay-user-data', null );
	}

	/**
	 * @testdox Should treat every country as WooPay-available in test mode.
	 */
	public function test_country_availability_short_circuits_in_test_mode(): void {
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', wp_json_encode( array( 'US' ) ) );

		$sut = $this->create_service( array( 'force_network_saved_cards' => 'yes' ) );

		$this->assertTrue( $sut->should_load_woopay_save_user_assets( 'checkout' ) );
	}

	/**
	 * @testdox Should geolocate the shopper against the platform-synced country list in live mode.
	 */
	public function test_country_availability_geolocates_against_synced_list_in_live_mode(): void {
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );

		$sut = $this->create_service( array( 'force_network_saved_cards' => 'yes' ), array(), null, null, null, false );

		// The synced list defaults to US only, so a DE shopper is unavailable.
		$this->assertFalse( $sut->should_load_woopay_save_user_assets( 'checkout' ) );

		// Once the platform-synced list includes DE, the same shopper is available.
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', wp_json_encode( array( 'US', 'DE' ) ) );
		$this->assertTrue( $sut->should_load_woopay_save_user_assets( 'checkout' ) );
	}

	/**
	 * @testdox Should fall back to the US-only default when the synced country list is malformed.
	 */
	public function test_country_availability_falls_back_to_default_on_malformed_list(): void {
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'US' );
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', 'not-json' );

		$sut = $this->create_service( array( 'force_network_saved_cards' => 'yes' ), array(), null, null, null, false );

		$this->assertTrue( $sut->should_load_woopay_save_user_assets( 'checkout' ) );
	}

	/**
	 * Simulate an inbound WooPay Store API request.
	 */
	private function simulate_woopay_store_api_request(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = '/wp-json/wc/store/v1/checkout';
	}

	/**
	 * Insert a Store API session row the cart-token resolution can read.
	 *
	 * @param string               $session_key Session key (user id or guest hash).
	 * @param array<string,string> $customer    Customer session payload.
	 */
	private function insert_store_api_session( string $session_key, array $customer ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => $session_key,
				'session_value'  => maybe_serialize( array( 'customer' => maybe_serialize( $customer ) ) ),
				'session_expiry' => time() + HOUR_IN_SECONDS,
			)
		);
	}

	/**
	 * Force the Jetpack blog-token signed check to report true.
	 *
	 * Rest_Authentication::is_signed_with_blog_token() reads a private singleton
	 * state that is false in tests; this drives it to a signed blog-token result.
	 */
	private function force_real_blog_token_signed(): void {
		if ( ! class_exists( \Automattic\Jetpack\Connection\Rest_Authentication::class ) ) {
			$this->markTestSkipped( 'Jetpack Rest_Authentication is unavailable.' );
		}

		$instance   = \Automattic\Jetpack\Connection\Rest_Authentication::init();
		$reflection = new \ReflectionClass( \Automattic\Jetpack\Connection\Rest_Authentication::class );

		$status = $reflection->getProperty( 'rest_authentication_status' );
		$status->setAccessible( true );
		$status->setValue( $instance, true );

		$type = $reflection->getProperty( 'rest_authentication_type' );
		$type->setAccessible( true );
		$type->setValue( $instance, 'blog' );
	}

	/**
	 * Reset the Jetpack blog-token signed check state forced during a test.
	 */
	private function reset_real_blog_token_signed(): void {
		if ( ! class_exists( \Automattic\Jetpack\Connection\Rest_Authentication::class ) ) {
			return;
		}

		$instance   = \Automattic\Jetpack\Connection\Rest_Authentication::init();
		$reflection = new \ReflectionClass( \Automattic\Jetpack\Connection\Rest_Authentication::class );

		$status = $reflection->getProperty( 'rest_authentication_status' );
		$status->setAccessible( true );
		$status->setValue( $instance, null );

		$type = $reflection->getProperty( 'rest_authentication_type' );
		$type->setAccessible( true );
		$type->setValue( $instance, null );
	}

	/**
	 * Create the System Under Test.
	 *
	 * @param array<string,mixed>                                                                             $settings           Gateway settings.
	 * @param array<string,mixed>                                                                             $account_data       Account data.
	 * @param WooPaymentsWooPayAdaptedExtensions|null                                                         $adapted_extensions Adapted extensions registry.
	 * @param callable(string):void|null                                                                      $event_recorder     Optional account-service event recorder.
	 * @param \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService|null $customer_service Optional customer service.
	 * @param bool                                    $test_mode          Whether the account reports test mode.
	 * @return TestableWooPaySessionService
	 */
	private function create_service( array $settings = array(), array $account_data = array(), ?WooPaymentsWooPayAdaptedExtensions $adapted_extensions = null, ?callable $event_recorder = null, ?\Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService $customer_service = null, bool $test_mode = true ): TestableWooPaySessionService {
		$settings     = array_merge(
			array(
				'platform_checkout'                    => 'yes',
				'manual_capture'                       => 'no',
				'payment_request_button_type'          => 'default',
				'payment_request_button_theme'         => 'dark',
				'payment_request_button_size'          => 'medium',
				'payment_request_button_height'        => '44',
				'payment_request_button_border_radius' => '',
				'express_checkout_product_methods'     => array( 'woopay' ),
				'express_checkout_cart_methods'        => array( 'woopay' ),
				'express_checkout_checkout_methods'    => array( 'woopay' ),
			),
			$settings
		);
		$account_data = array_merge(
			array(
				'account_id'                 => 'acct_123',
				'details_submitted'          => true,
				'capabilities'               => array( 'card_payments' => 'active' ),
				'country'                    => 'US',
				'pre_check_save_my_info'     => false,
				'platform_checkout_eligible' => true,
			),
			$account_data
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_id', 'get_publishable_key', 'get_cached_account_data', 'is_test_mode_enabled', 'get_gateway_setting' ) )
			->getMock();

		$account_service->method( 'get_account_id' )->willReturnCallback(
			static function () use ( $account_data ): string {
				$account_id = $account_data['account_id'] ?? '';

				return is_scalar( $account_id ) ? (string) $account_id : '';
			}
		);
		$account_service->method( 'get_publishable_key' )->willReturn( 'pk_test_123' );
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			static function () use ( $account_data, $event_recorder ): array {
				if ( null !== $event_recorder ) {
					$event_recorder( 'account' );
				}

				return $account_data;
			}
		);
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static function ( string $key, $fallback = null ) use ( $settings, $event_recorder ) {
				if ( null !== $event_recorder && 0 === strpos( $key, 'express_checkout_' ) ) {
					$event_recorder( 'location' );
				}

				return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
			}
		);

		$tracking_controller = $this->getMockBuilder( WooPaymentsFrontendTrackingController::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_shopper_tracking_enabled' ) )
			->getMock();
		$tracking_controller->method( 'is_shopper_tracking_enabled' )->willReturn( true );

		$sut = new TestableWooPaySessionService();
		$sut->init( $account_service, new WooPaymentsFrontendStylesService(), $tracking_controller, null, $adapted_extensions, $customer_service );

		return $sut;
	}

	/**
	 * Create a persisted product for WooPay eligibility tests.
	 *
	 * @param string $class_name Product class name.
	 * @phpstan-param class-string<\WC_Product> $class_name
	 * @return \WC_Product
	 */
	private function create_woopay_product( string $class_name = \WC_Product_Simple::class ): \WC_Product {
		$product = new $class_name();
		$product->set_name( 'WooPay eligibility product' );
		$product->set_status( 'publish' );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );
		$product->set_stock_status( 'instock' );
		$product->save();

		return $product;
	}

	/**
	 * Set the current product used by the product-button eligibility checks.
	 *
	 * @param \WC_Product $product Product to expose globally.
	 */
	private function set_current_woopay_product( \WC_Product $product ): void {
		$GLOBALS['product'] = $product;
	}

	/**
	 * Set the current request to a product_page shortcode page.
	 *
	 * @param string $content Shortcode page content.
	 */
	private function set_current_woopay_shortcode_page( string $content ): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		$this->go_to( get_permalink( $page_id ) );
		global $post;
		$post = get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		unset( $GLOBALS['product'] );
	}

	/**
	 * Register fake Mailchimp blocks integration class.
	 */
	private function register_fake_mailchimp_blocks_integration(): void {
		if ( ! class_exists( '\Mailchimp_Woocommerce_Newsletter_Blocks_Integration', false ) ) {
			class_alias( FakeWooPayMailchimpBlocksIntegration::class, 'Mailchimp_Woocommerce_Newsletter_Blocks_Integration' );
		}
	}

	/**
	 * Get a valid WooPay appearance payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_valid_appearance(): array {
		return array(
			'theme'     => 'stripe',
			'variables' => array(
				'colorText' => '#111111',
			),
			'rules'     => array(
				'.Input' => array(
					'borderRadius' => '4px',
				),
			),
		);
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return object
	 */
	private function create_session(): object {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName
