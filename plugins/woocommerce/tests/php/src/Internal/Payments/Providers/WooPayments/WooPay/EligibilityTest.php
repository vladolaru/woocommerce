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
 * Tests for the Eligibility class.
 */
class EligibilityTest extends WC_Unit_Test_Case {

	use WooPayServicesFixture;

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
	 * @testdox Should load the WooPay save-user assets on checkout only while the base gateway is available, checked before the visitor's country: $label.
	 *
	 * Client 11.1.0 loads its save-user script only when WooPayments is an available gateway, and returns before
	 * geolocating the visitor when it is not (includes/woopay-user/class-woopay-save-user.php:49-57). Live mode, where
	 * the country check geolocates.
	 *
	 * @testWith ["base gateway available", true, true, 1]
	 *           ["base gateway filtered out", false, false, 0]
	 *
	 * @param string $label                   Case label.
	 * @param bool   $gateway_available       Whether the base gateway stays among the available gateways.
	 * @param bool   $expected                Whether the save-user assets load.
	 * @param int    $expected_country_checks How many times the visitor's country is looked up.
	 */
	public function test_save_user_assets_require_the_available_base_gateway( string $label, bool $gateway_available, bool $expected, int $expected_country_checks ): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		unset( $label );
		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$country_checks = 0;
		add_filter(
			'woocommerce_geolocate_ip',
			static function () use ( &$country_checks ): string {
				++$country_checks;

				return 'US';
			}
		);
		if ( ! $gateway_available ) {
			add_filter(
				'woocommerce_available_payment_gateways',
				static function ( array $gateways ): array {
					unset( $gateways['woocommerce_payments'] );

					return $gateways;
				},
				20
			);
		}

		$this->assertSame( $expected, $this->create_eligibility( array(), array(), null, null, null, false )->should_load_woopay_save_user_assets() );
		$this->assertSame( $expected_country_checks, $country_checks, 'The gateway check runs before the country check.' );
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
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
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
	public function test_woopay_button_keeps_the_guard_order(): void {
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
	 * @testdox Should hide the product WooPay button for $_dataName.
	 * @dataProvider hidden_product_button_provider
	 *
	 * @param string|null          $product_class The current product's class, or null for no current product.
	 * @param array<string,string> $props         Product props changed after the product is created.
	 * @param string|null          $sut_flag      The optional-extension predicate that answers true, if any.
	 * @phpstan-param class-string<\WC_Product>|null $product_class
	 */
	public function test_woopay_product_button_hides_for_unsupported_products( ?string $product_class, array $props, ?string $sut_flag ): void {
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		if ( null === $product_class ) {
			unset( $GLOBALS['product'] );
		} else {
			$product = $this->create_woopay_product( $product_class );
			if ( array() !== $props ) {
				$product->set_props( $props );
				$product->save();
			}
			$this->set_current_woopay_product( $product );
		}
		$sut = $this->create_eligibility();
		if ( null !== $sut_flag ) {
			$sut->{$sut_flag} = true;
		}

		$this->assertFalse( $sut->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox Should show the product WooPay button for $_dataName.
	 * @dataProvider shown_product_button_provider
	 *
	 * @param bool                 $logged_in     Whether the shopper is logged in; a guest gets guest checkout on.
	 * @param string               $product_class The product's class.
	 * @param string|null          $shortcode     The product_page shortcode the current page holds, with %d for the product ID, or null for the current product.
	 * @param array<string,string> $props         Product props changed after the product is created.
	 * @phpstan-param class-string<\WC_Product> $product_class
	 */
	public function test_woopay_product_button_shows_for_supported_products( bool $logged_in, string $product_class, ?string $shortcode, array $props ): void {
		if ( $logged_in ) {
			wp_set_current_user( 1 );
		} else {
			update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		}
		$product = $this->create_woopay_product( $product_class );
		if ( array() !== $props ) {
			$product->set_props( $props );
			$product->save();
		}
		if ( null === $shortcode ) {
			$this->set_current_woopay_product( $product );
		} else {
			$this->set_current_woopay_shortcode_page( sprintf( $shortcode, $product->get_id() ) );
		}

		$this->assertTrue( $this->create_eligibility()->should_show_woopay_button( 'product' ) );
	}

	/**
	 * @testdox The cart WooPay button follows the cart and the shopper: $_dataName.
	 * @dataProvider cart_button_provider
	 *
	 * @param bool        $logged_in      Whether the shopper is logged in.
	 * @param string|null $guest_checkout The guest checkout setting, or null to leave the stored value.
	 * @param string|null $cart_flag      The cart predicate that answers true, if any.
	 * @param bool        $expected       Whether the button shows.
	 */
	public function test_woopay_cart_button_follows_the_cart_and_the_shopper( bool $logged_in, ?string $guest_checkout, ?string $cart_flag, bool $expected ): void {
		if ( $logged_in ) {
			wp_set_current_user( 1 );
		}
		if ( null !== $guest_checkout ) {
			update_option( 'woocommerce_enable_guest_checkout', $guest_checkout );
		}
		$sut = $this->create_eligibility();
		if ( null !== $cart_flag ) {
			$sut->{$cart_flag} = true;
		}

		$this->assertSame( $expected, $sut->should_show_woopay_button( 'cart' ) );
	}

	/**
	 * @testdox Direct checkout runs for $label.
	 * @dataProvider direct_checkout_run_provider
	 *
	 * Client 11.1.0 loads its direct-checkout script only when WooPay_Utilities::should_enable_woopay_on_guest_checkout()
	 * passes (class-wc-payments-woopay-direct-checkout.php:92-94, class-woopay-utilities.php:46-62). It does not check that
	 * WooPayments is an available gateway (class-wc-payments-features.php:237-243), so its cart sends the shopper to WooPay
	 * while WooPayments is not offered, for example before card payments are active. Here availability is also required,
	 * read at page render as the client's WooPay button reads it (class-wc-payments-woopay-button-handler.php:245-250).
	 *
	 * @param string $label             Case label.
	 * @param bool   $logged_in         Whether the shopper is logged in.
	 * @param bool   $has_subscription  Whether the cart contains a subscription.
	 * @param string $guest_checkout    The guest checkout setting.
	 * @param bool   $gateway_available Whether the base gateway stays among the available gateways.
	 * @param bool   $expected          Whether direct checkout runs.
	 */
	public function test_direct_checkout_runs_for_the_shopper_while_the_gateway_is_available( string $label, bool $logged_in, bool $has_subscription, string $guest_checkout, bool $gateway_available, bool $expected ): void {
		unset( $label );
		update_option( 'woocommerce_enable_guest_checkout', $guest_checkout );
		if ( $logged_in ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		}
		if ( ! $gateway_available ) {
			add_filter(
				'woocommerce_available_payment_gateways',
				static function ( array $gateways ): array {
					unset( $gateways['woocommerce_payments'] );

					return $gateways;
				},
				20
			);
		}
		$sut                             = $this->create_eligibility( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$sut->cart_contains_subscription = $has_subscription;

		$this->assertTrue( $sut->is_woopay_direct_checkout_enabled(), 'The store-level direct checkout flag stays shopper- and availability-independent.' );
		$this->assertSame( $expected, $sut->should_run_woopay_direct_checkout() );
	}

	/**
	 * @testdox Direct checkout resolves the available gateways only after the store and guest checks pass: $_dataName.
	 * @dataProvider direct_checkout_gateway_lookup_provider
	 *
	 * The store-level check also runs from the draft-order hook at init, where resolving the available gateways is too
	 * early, so the availability read comes last.
	 *
	 * @param array<string,mixed> $account_data     Cached account data.
	 * @param string              $feature_option   The direct checkout feature option.
	 * @param bool                $has_subscription Whether the guest's cart contains a subscription.
	 * @param string              $guest_checkout   The guest checkout setting.
	 * @param bool                $expected         Whether direct checkout runs.
	 */
	public function test_direct_checkout_resolves_no_available_gateways_until_the_earlier_checks_pass( array $account_data, string $feature_option, bool $has_subscription, string $guest_checkout, bool $expected ): void {
		update_option( '_wcpay_feature_woopay_direct_checkout', $feature_option );
		update_option( 'woocommerce_enable_guest_checkout', $guest_checkout );
		$lookups = 0;
		add_filter(
			'woocommerce_available_payment_gateways',
			static function ( $gateways ) use ( &$lookups ) {
				++$lookups;

				return $gateways;
			}
		);
		$sut                             = $this->create_eligibility( array(), $account_data );
		$sut->cart_contains_subscription = $has_subscription;

		$this->assertSame( $expected, $sut->should_run_woopay_direct_checkout() );
		if ( $expected ) {
			$this->assertGreaterThan( 0, $lookups, 'The availability read runs once the earlier checks pass.' );
		} else {
			$this->assertSame( 0, $lookups, 'No available gateways are resolved while an earlier check fails.' );
		}
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
	 * @testdox Should decide direct checkout from the gateway's enabled setting without resolving the available gateways.
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
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', wp_json_encode( array( 'US' ) ) );

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$sut = $this->create_eligibility();

		$this->assertTrue( $sut->should_load_woopay_save_user_assets() );
	}

	/**
	 * @testdox Should geolocate the shopper against the platform-synced country list in live mode.
	 */
	public function test_country_availability_geolocates_against_synced_list_in_live_mode(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'DE' );

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$sut = $this->create_eligibility( array(), array(), null, null, null, false );

		// The synced list defaults to US only, so a DE shopper is unavailable.
		$this->assertFalse( $sut->should_load_woopay_save_user_assets() );

		// Once the platform-synced list includes DE, the same shopper is available.
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', wp_json_encode( array( 'US', 'DE' ) ) );
		$this->assertTrue( $sut->should_load_woopay_save_user_assets() );
	}

	/**
	 * @testdox Should fall back to the US-only default when the synced country list is malformed.
	 */
	public function test_country_availability_falls_back_to_default_on_malformed_list(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		add_filter( 'woocommerce_geolocate_ip', static fn() => 'US' );
		update_option( 'woocommerce_woocommerce_payments_woopay_available_countries', 'not-json' );

		add_filter( 'wcpay_force_network_saved_cards', '__return_true' );
		$sut = $this->create_eligibility( array(), array(), null, null, null, false );

		$this->assertTrue( $sut->should_load_woopay_save_user_assets() );
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
	 * Provide the products the product WooPay button hides for.
	 *
	 * @return array<string,array{0:string|null,1:array<string,string>,2:string|null}>
	 */
	public function hidden_product_button_provider(): array {
		return array(
			'no current product'                => array( null, array(), null ),
			'an external product'               => array( \WC_Product_External::class, array(), null ),
			'a pre-order charged on release'    => array( \WC_Product_Simple::class, array(), 'preorder_product_charged_on_release' ),
			'a booking requiring confirmation'  => array( \WC_Product_Simple::class, array(), 'booking_product_requires_confirmation' ),
			'a product that is not purchasable' => array( \WC_Product_Simple::class, array( 'price' => '' ), null ),
			'an out-of-stock product'           => array( \WC_Product_Simple::class, array( 'stock_status' => 'outofstock' ), null ),
			'a guest shopper of a subscription' => array( FakeWooPaySubscriptionProduct::class, array(), null ),
		);
	}

	/**
	 * Provide the shoppers and pages the product WooPay button shows for.
	 *
	 * @return array<string,array{0:bool,1:string,2:string|null,3:array<string,string>}>
	 */
	public function shown_product_button_provider(): array {
		return array(
			'a logged-in shopper of a subscription'        => array( true, FakeWooPaySubscriptionProduct::class, null, array() ),
			'a product_page ID shortcode'                  => array( false, \WC_Product_Simple::class, '[product_page id="%d"]', array() ),
			'flexible product_page attributes and quoting' => array( false, \WC_Product_Simple::class, "[product_page class='featured' columns='3' id='%d']", array() ),
			'a product_page shortcode by SKU'              => array( false, \WC_Product_Simple::class, "[product_page class='featured' sku='woopay-shortcode-sku']", array( 'sku' => 'woopay-shortcode-sku' ) ),
		);
	}

	/**
	 * Provide the carts and shoppers of the cart WooPay button.
	 *
	 * @return array<string,array{0:bool,1:string|null,2:string|null,3:bool}>
	 */
	public function cart_button_provider(): array {
		return array(
			'a supported plain guest cart with guest checkout on' => array( false, 'yes', null, true ),
			'a guest cart with a pre-order charged on release' => array( false, 'yes', 'cart_preorder_charged_on_release', false ),
			'a guest cart with a subscription'             => array( false, 'yes', 'cart_contains_subscription', false ),
			'a guest while guest checkout is off by default' => array( false, null, null, false ),
			'a logged-in shopper with a subscription cart' => array( true, null, 'cart_contains_subscription', true ),
		);
	}

	/**
	 * Provide the guest-rule cases of client 11.1.0 and the gateway availability cases.
	 *
	 * @return array<string,array{string,bool,bool,string,bool,bool}>
	 */
	public function direct_checkout_run_provider(): array {
		return array(
			'a guest with a plain cart'                    => array( 'a guest with a plain cart', false, false, 'yes', true, true ),
			'not a guest with a subscription in the cart'  => array( 'not a guest with a subscription in the cart', false, true, 'yes', true, false ),
			'not a guest when guest checkout is off'       => array( 'not a guest when guest checkout is off', false, false, 'no', true, false ),
			'a logged-in shopper with a subscription cart' => array( 'a logged-in shopper with a subscription cart', true, true, 'no', true, true ),
			'not a guest with a plain cart when WooPayments is not offered' => array( 'not a guest with a plain cart when WooPayments is not offered', false, false, 'yes', false, false ),
			'not a logged-in shopper when WooPayments is not offered' => array( 'not a logged-in shopper when WooPayments is not offered', true, false, 'yes', false, false ),
		);
	}

	/**
	 * Provide the cases where an earlier direct checkout check fails, and one where every check passes.
	 *
	 * @return array<string,array{array<string,mixed>,string,bool,string,bool}>
	 */
	public function direct_checkout_gateway_lookup_provider(): array {
		$eligible = array( 'platform_direct_checkout_eligible' => true );

		return array(
			'the account is not eligible'      => array( array(), '1', false, 'yes', false ),
			'the feature option is off'        => array( $eligible, '0', false, 'yes', false ),
			'a guest with a subscription cart' => array( $eligible, '1', true, 'yes', false ),
			'a guest with guest checkout off'  => array( $eligible, '1', false, 'no', false ),
			'every check passes'               => array( $eligible, '1', false, 'yes', true ),
		);
	}
}
