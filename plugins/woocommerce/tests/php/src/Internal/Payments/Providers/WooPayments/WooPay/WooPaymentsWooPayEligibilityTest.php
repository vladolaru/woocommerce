<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use WC_Unit_Test_Case;

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
 * Tests for the WooPaymentsWooPayEligibility class.
 */
class WooPaymentsWooPayEligibilityTest extends WC_Unit_Test_Case {

	use WooPayServicesFixture;

	/**
	 * @testdox Should show the WooPay button for a supported plain cart when guest checkout is enabled.
	 */
	public function test_woopay_button_shows_for_plain_guest_cart(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$this->assertTrue( $this->create_eligibility()->should_show_woopay_button( 'cart' ) );
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'cart' ) );
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'cart' ) );
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

		$this->assertTrue( $this->create_eligibility()->is_woopay_enabled() );
		$this->assertSame( 0, $enabled_filter_calls, 'The button filter should not participate in global WooPay capability.' );
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'order_received' ) );
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

		$result = $this->create_eligibility(
			array(),
			array(),
			null,
			static function ( string $event ) use ( &$events ): void {
				$events[] = $event;
			}
		)->should_show_woopay_button( 'product' );

		// How often the cached account is read is not observable; where it is read among the filters is.
		$order = array();
		foreach ( $events as $event ) {
			if ( end( $order ) !== $event ) {
				$order[] = $event;
			}
		}

		$this->assertTrue( $result );
		$this->assertSame( array( 'gateway', 'account', 'enabled', 'location', 'product', 'guest' ), $order );
	}

	/**
	 * @testdox Should reject WooPay button contexts outside product, cart, checkout and order-pay.
	 */
	public function test_woopay_button_rejects_unsupported_context(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'order_received' ) );
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
			$this->create_eligibility(
				array( 'express_checkout_' . $context . '_methods' => array() )
			)->should_show_woopay_button( $context )
		);
	}

	/**
	 * @testdox Should hide the product WooPay button when no current product exists.
	 */
	public function test_woopay_product_button_requires_current_product(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		unset( $GLOBALS['product'] );

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the WooPay button for external products.
	 */
	public function test_woopay_product_button_rejects_external_product(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product( \WC_Product_External::class ) );

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the product WooPay button for pre-orders charged on release.
	 */
	public function test_woopay_product_button_rejects_preorder_charged_on_release(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product() );
		$sut                                      = $this->create_eligibility();
		$sut->preorder_product_charged_on_release = true;

		$this->assertFalse( $sut->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the product WooPay button for bookings requiring confirmation.
	 */
	public function test_woopay_product_button_rejects_booking_requiring_confirmation(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product() );
		$sut                                        = $this->create_eligibility();
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide the cart WooPay button for pre-orders charged on release.
	 */
	public function test_woopay_cart_button_rejects_preorder_charged_on_release(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$sut                                   = $this->create_eligibility();
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

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should hide product WooPay for guest subscription shoppers.
	 */
	public function test_woopay_product_button_rejects_guest_subscription(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$this->set_current_woopay_product( $this->create_woopay_product( FakeWooPaySubscriptionProduct::class ) );

		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should hide cart WooPay for guest subscription shoppers.
	 */
	public function test_woopay_cart_button_rejects_guest_subscription(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$sut                             = $this->create_eligibility();
		$sut->cart_contains_subscription = true;

		$this->assertFalse( $sut->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should hide WooPay for guests when guest checkout is disabled by default.
	 */
	public function test_woopay_button_requires_guest_checkout_for_guests(): void {
		$this->assertFalse( $this->create_eligibility()->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Should keep subscription products eligible for logged-in shoppers.
	 */
	public function test_woopay_product_button_allows_logged_in_subscription(): void {
		wp_set_current_user( 1 );
		$this->set_current_woopay_product( $this->create_woopay_product( FakeWooPaySubscriptionProduct::class ) );

		$this->assertTrue( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should resolve the WooPay product from a product_page ID shortcode.
	 */
	public function test_woopay_product_button_supports_product_page_shortcode(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$this->set_current_woopay_shortcode_page( '[product_page id="' . $product->get_id() . '"]' );

		$this->assertTrue( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should resolve flexible product_page shortcode attributes and quoting.
	 */
	public function test_woopay_product_button_supports_flexible_product_page_shortcode(): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$product = $this->create_woopay_product();
		$this->set_current_woopay_shortcode_page( "[product_page class='featured' columns='3' id='" . $product->get_id() . "']" );

		$this->assertTrue( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
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

		$this->assertTrue( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should keep subscription carts eligible for logged-in shoppers.
	 */
	public function test_woopay_cart_button_allows_logged_in_subscription(): void {
		wp_set_current_user( 1 );
		$sut                             = $this->create_eligibility();
		$sut->cart_contains_subscription = true;

		$this->assertTrue( $sut->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Direct checkout runs for $label.
	 * @dataProvider direct_checkout_guest_rule_provider
	 *
	 * Client 11.1.0 loads its direct-checkout script only when WooPay_Utilities::should_enable_woopay_on_guest_checkout()
	 * passes (class-wc-payments-woopay-direct-checkout.php:92-94, class-woopay-utilities.php:46-62).
	 *
	 * @param string $label              Case label.
	 * @param bool   $logged_in          Whether the shopper is logged in.
	 * @param bool   $has_subscription   Whether the cart contains a subscription.
	 * @param string $guest_checkout     The guest checkout setting.
	 * @param bool   $expected           Whether direct checkout runs.
	 */
	public function test_direct_checkout_applies_the_guest_rule( string $label, bool $logged_in, bool $has_subscription, string $guest_checkout, bool $expected ): void {
		unset( $label );
		update_option( 'woocommerce_enable_guest_checkout', $guest_checkout );
		if ( $logged_in ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		}
		$sut                             = $this->create_eligibility( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$sut->cart_contains_subscription = $has_subscription;

		$this->assertTrue( $sut->is_woopay_direct_checkout_enabled(), 'The store-level direct checkout flag stays shopper-independent.' );
		$this->assertSame( $expected, $sut->should_run_woopay_direct_checkout() );
	}

	/**
	 * @testdox Checkout uses the Stripe platform account for WooPay only for an eligible account with WooPay on: $label.
	 *
	 * Client 11.1.0 should_use_stripe_platform_on_checkout_page() (class-wc-payment-gateway-wcpay.php:1173-1191) with
	 * WC_Payments_Features::is_woopay_eligible() (class-wc-payments-features.php:193-209): the cached account must be
	 * platform_checkout_eligible and neither rejected nor under review, platform_checkout must be 'yes', the page must be
	 * checkout and not order-pay, and the cart must need payment. Ported from the client's
	 * test_should_use_stripe_platform_on_checkout_page_not_woopay_eligible and _not_woopay
	 * (tests/unit/test-class-wc-payment-gateway-wcpay.php:4789-4799).
	 *
	 * @testWith ["eligible, WooPay on, checkout with a cart", {"platform_checkout_eligible": true}, "yes", false, true]
	 *           ["not WooPay eligible", {"platform_checkout_eligible": false}, "yes", false, false]
	 *           ["WooPay off", {"platform_checkout_eligible": true}, "no", false, false]
	 *           ["rejected account", {"platform_checkout_eligible": true, "status": "rejected.fraud"}, "yes", false, false]
	 *           ["account under review", {"platform_checkout_eligible": true, "status": "under_review"}, "yes", false, false]
	 *           ["the order-pay page", {"platform_checkout_eligible": true}, "yes", true, false]
	 *
	 * @param string              $label             Case label.
	 * @param array<string,mixed> $account_data      Cached account data.
	 * @param string              $platform_checkout WooPay setting.
	 * @param bool                $is_order_pay      Whether the request is the order-pay endpoint.
	 * @param bool                $expected          Whether checkout uses the platform account.
	 */
	public function test_should_use_stripe_platform_on_checkout_page( string $label, array $account_data, string $platform_checkout, bool $is_order_pay, bool $expected ): void {
		global $wp;
		unset( $label );
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		if ( $is_order_pay ) {
			$wp->query_vars['order-pay'] = 1;
		}
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$sut = $this->create_eligibility( array( 'platform_checkout' => $platform_checkout ), $account_data );

		try {
			$this->assertSame( $expected, $sut->should_use_stripe_platform_on_checkout_page() );
		} finally {
			remove_filter( 'woocommerce_is_checkout', '__return_true' );
			unset( $wp->query_vars['order-pay'] );
		}
	}

	/**
	 * @testdox Checkout does not use the Stripe platform account off the checkout page, even for an eligible account with WooPay on.
	 *
	 * Client 11.1.0 class-wc-payment-gateway-wcpay.php:1181 requires is_checkout() || has_block( 'woocommerce/checkout' ). A
	 * separate process, because an earlier test can define WOOCOMMERCE_CHECKOUT for the rest of the run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_not_use_stripe_platform_off_the_checkout_page(): void {
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();

		$this->assertFalse( $this->create_eligibility()->should_use_stripe_platform_on_checkout_page() );
	}

	/**
	 * @testdox A page with the Checkout block uses the Stripe platform account for WooPay even when it is not the store's checkout page.
	 *
	 * Client 11.1.0 class-wc-payment-gateway-wcpay.php:1181: is_checkout() || has_block( 'woocommerce/checkout' ). A separate
	 * process, because an earlier test can define WOOCOMMERCE_CHECKOUT, which makes every page read as checkout.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_block_checkout_page_uses_the_stripe_platform_account(): void {
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$page_id = $this->factory->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:woocommerce/checkout /-->',
			)
		);
		$this->go_to( get_permalink( $page_id ) );

		$this->assertTrue( has_block( 'woocommerce/checkout' ) );
		$this->assertFalse( is_checkout(), 'The page is not the store\'s checkout page.' );
		$this->assertTrue( $this->create_eligibility()->should_use_stripe_platform_on_checkout_page() );
	}

	/**
	 * @testdox Should decide direct checkout from the gateway's enabled setting without resolving the available gateways, like client 11.1.0.
	 */
	public function test_direct_checkout_check_does_not_resolve_available_gateways(): void {
		$resolutions      = 0;
		$count_resolution = static function ( $gateways ) use ( &$resolutions ) {
			++$resolutions;
			return $gateways;
		};
		add_filter( 'woocommerce_available_payment_gateways', $count_resolution );

		try {
			$woopay_off             = $this->create_eligibility( array( 'platform_checkout' => 'no' ), array( 'platform_direct_checkout_eligible' => true ) )->is_woopay_direct_checkout_enabled();
			$resolutions_woopay_off = $resolutions;
			$woopay_on              = $this->create_eligibility( array(), array( 'platform_direct_checkout_eligible' => true ) )->is_woopay_direct_checkout_enabled();
		} finally {
			remove_filter( 'woocommerce_available_payment_gateways', $count_resolution );
		}

		$this->assertSame( 0, $resolutions_woopay_off, 'WooPay off' );
		$this->assertSame( 0, $resolutions, 'WooPay on' );
		$this->assertFalse( $woopay_off );
		$this->assertTrue( $woopay_on );
	}

	/**
	 * @testdox Should treat every country as WooPay-available in test mode.
	 */
	public function test_country_availability_short_circuits_in_test_mode(): void {
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', wp_json_encode( array( 'US' ) ) );

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$sut = $this->create_eligibility();

		$this->assertTrue( $sut->should_load_woopay_save_user_assets( 'checkout' ) );
	}

	/**
	 * @testdox Should geolocate the shopper against the platform-synced country list in live mode.
	 */
	public function test_country_availability_geolocates_against_synced_list_in_live_mode(): void {
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$sut = $this->create_eligibility( array(), array(), null, null, null, false );

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

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$sut = $this->create_eligibility( array(), array(), null, null, null, false );

		$this->assertTrue( $sut->should_load_woopay_save_user_assets( 'checkout' ) );
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
	 * Provide the client's guest-rule cases.
	 *
	 * @return array<string,array{string,bool,bool,string,bool}>
	 */
	public function direct_checkout_guest_rule_provider(): array {
		return array(
			'a guest with a plain cart'                    => array( 'a guest with a plain cart', false, false, 'yes', true ),
			'not a guest with a subscription in the cart'  => array( 'not a guest with a subscription in the cart', false, true, 'yes', false ),
			'not a guest when guest checkout is off'       => array( 'not a guest when guest checkout is off', false, false, 'no', false ),
			'a logged-in shopper with a subscription cart' => array( 'a logged-in shopper with a subscription cart', true, true, 'no', true ),
		);
	}
}
