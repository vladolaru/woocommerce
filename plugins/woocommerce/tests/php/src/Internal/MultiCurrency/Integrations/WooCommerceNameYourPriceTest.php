<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\WooCommerceNameYourPrice;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceCalculator;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RegisteredHooks;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RequestSignals;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\ServiceFactories;

/**
 * Tests for the WooCommerceNameYourPrice class.
 */
class WooCommerceNameYourPriceTest extends WC_Unit_Test_Case {

	/** @var MultiCurrencyState|null Currency selection for cart requests within a test. */
	private ?MultiCurrencyState $currency_state = null;

	/**
	 * Hooks touched by the Name Your Price compatibility controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'plugins_loaded',
		'wc_nyp_raw_minimum_price',
		'wc_nyp_raw_maximum_price',
		'wc_nyp_raw_suggested_price',
		'woocommerce_add_cart_item_data',
		'woocommerce_get_cart_item_from_session',
		'woocommerce_pre_remove_cart_item_from_session',
		'wcpay_multi_currency_should_convert_product_price',
		'wc_nyp_edit_in_cart_args',
		'wc_nyp_get_initial_price',
	);

	/**
	 * Original request data.
	 *
	 * @var array<mixed>
	 */
	private array $original_request = array();

	/**
	 * Original request cookies.
	 *
	 * @var array<mixed>
	 */
	private array $original_cookies = array();

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Captures request fixture state for restoration.
		$this->original_cookies = $_COOKIE;
		// Start each case as a fresh browser: an earlier test can leave another customer's session cookie.
		unset( $_COOKIE[ 'wp_woocommerce_session_' . COOKIEHASH ] );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		$_REQUEST = $this->original_request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Restores request fixture state.
		$_COOKIE  = $this->original_cookies;

		parent::tear_down();
	}

	/**
	 * @testdox Should register exactly the Name Your Price hooks the store allows, once: $_dataName.
	 * @dataProvider provide_name_your_price_stores
	 *
	 * @param string                            $owner                     Runtime owner.
	 * @param bool                              $name_your_price_available Whether Name Your Price is loaded.
	 * @param array<string,array<string,int[]>> $expected                  Every hook the controller registers.
	 */
	public function test_registers_the_name_your_price_hooks_the_store_allows( string $owner, bool $name_your_price_available, array $expected ): void {
		$sut = $this->create_controller( $owner, $name_your_price_available );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Stores and the Name Your Price hooks each registers: all of them under core with the extension loaded, none otherwise.
	 *
	 * @return array<string,array{0:string,1:bool,2:array<string,array<string,int[]>>}>
	 */
	public function provide_name_your_price_stores(): array {
		return array(
			'Name Your Price loaded under core' => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				true,
				array(
					'wc_nyp_edit_in_cart_args'       => array( 'edit_in_cart_args' => array( 10, 2 ) ),
					'wc_nyp_get_initial_price'       => array( 'get_initial_price' => array( 10, 3 ) ),
					'wc_nyp_raw_maximum_price'       => array( 'get_nyp_prices' => array( 10, 1 ) ),
					'wc_nyp_raw_minimum_price'       => array( 'get_nyp_prices' => array( 10, 1 ) ),
					'wc_nyp_raw_suggested_price'     => array( 'get_nyp_prices' => array( 10, 1 ) ),
					'wcpay_multi_currency_should_convert_product_price' => array( 'should_convert_product_price' => array( 50, 2 ) ),
					'woocommerce_add_cart_item_data' => array( 'add_initial_currency' => array( 20, 3 ) ),
					'woocommerce_get_cart_item_from_session' => array( 'convert_cart_currency' => array( 20, 2 ) ),
					'woocommerce_pre_remove_cart_item_from_session' => array( 'remove_unconvertible_cart_item' => array( 10, 4 ) ),
				),
			),
			'the extension owns'                => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true, array() ),
			'Name Your Price not loaded'        => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, array() ),
		);
	}

	/**
	 * @testdox Should defer Name Your Price hook registration until plugins load.
	 */
	public function test_defers_name_your_price_registration_until_plugins_load(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, true, false );

		$sut->register();

		$this->assertFalse( has_filter( 'wc_nyp_raw_minimum_price', array( $sut, 'get_nyp_prices' ) ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $sut, 'register_name_your_price_hooks' ) ) );

		$sut->set_name_your_price_available( true );
		$sut->register_name_your_price_hooks();

		$this->assertSame( 10, has_filter( 'wc_nyp_raw_minimum_price', array( $sut, 'get_nyp_prices' ) ) );
		$this->assertSame( 20, has_action( 'woocommerce_add_cart_item_data', array( $sut, 'add_initial_currency' ) ) );
	}

	/**
	 * @testdox Should convert raw NYP prices as product prices.
	 */
	public function test_converts_raw_nyp_prices_as_product_prices(): void {
		$sut = $this->create_controller();

		$this->assertSame( 8.4, $sut->get_nyp_prices( '10.00' ) );
		$this->assertSame( '0', $sut->get_nyp_prices( '0' ) );
		$this->assertSame( 0, $sut->get_nyp_prices( 0 ) );
		$this->assertSame( '', $sut->get_nyp_prices( '' ) );
	}

	/**
	 * @testdox Should store selected currency and original NYP price when a NYP item is added.
	 */
	public function test_stores_selected_currency_and_original_nyp_price_when_nyp_item_is_added(): void {
		$sut = $this->create_controller();
		$sut->set_name_your_price_product_ids( array( 10, 20 ) );

		$this->assertSame(
			array(
				'nyp'          => '12.00',
				'nyp_currency' => 'GBP',
				'nyp_original' => '12.00',
			),
			$sut->add_initial_currency( array( 'nyp' => '12.00' ), 10, 0 )
		);
		$this->assertSame(
			array(
				'nyp'          => '14.00',
				'nyp_currency' => 'GBP',
				'nyp_original' => '14.00',
			),
			$sut->add_initial_currency( array( 'nyp' => '14.00' ), 10, 20 )
		);
		$this->assertSame( array( 'nyp' => '12.00' ), $sut->add_initial_currency( array( 'nyp' => '12.00' ), 99, 0 ) );
		$this->assertSame( array(), $sut->add_initial_currency( array(), 10, 0 ) );
	}

	/**
	 * @testdox Should restore the original NYP amount in the cart's currency, converting it when that differs from the selected currency: $_dataName.
	 * @dataProvider provide_nyp_cart_currencies
	 *
	 * @param string       $original Amount the shopper entered.
	 * @param string       $currency Currency the shopper entered it in.
	 * @param string|float $expected The cart item's NYP amount.
	 */
	public function test_restores_the_original_nyp_amount_in_the_cart_currency( string $original, string $currency, $expected ): void {
		$sut     = $this->create_controller();
		$product = $this->create_product();

		$cart_item = $sut->convert_cart_currency(
			array(
				'data'         => $product,
				'nyp'          => '99.00',
				'nyp_original' => $original,
				'nyp_currency' => $currency,
			),
			array()
		);

		$this->assertSame( $expected, $cart_item['nyp'] );
		$this->assertSame( $currency, $product->get_meta( '_wcpay_multi_currency_nyp_currency' ) );
	}

	/**
	 * Entered amounts: in the selected currency (GBP), restored as entered, and in USD, converted.
	 *
	 * @return array<string,array{0:string,1:string,2:string|float}>
	 */
	public static function provide_nyp_cart_currencies(): array {
		return array(
			'entered in the selected currency' => array( '12.00', 'GBP', '12.00' ),
			'entered in another currency'      => array( '10.00', 'USD', 8.2 ),
		);
	}

	/**
	 * @testdox Should remove a cart item whose price was entered in a currency the store no longer offers.
	 */
	public function test_removes_a_nyp_item_entered_in_a_currency_the_store_no_longer_offers(): void {
		$sut     = $this->create_controller();
		$product = \WC_Helper_Product::create_simple_product();
		wc_clear_notices();

		// Client 11.1.0 converts with get_raw_conversion(), which throws for a disabled source currency
		// (includes/multi-currency/MultiCurrency.php:983-997) and nothing catches it while the cart loads.
		// The nyp_original and nyp_currency keys are the ones Name Your Price stores on the cart item
		// (includes/multi-currency/Compatibility/WooCommerceNameYourPrice.php:66-69).
		$remove = $sut->remove_unconvertible_cart_item(
			false,
			'cart-item-key',
			array(
				'nyp'          => '99.00',
				'nyp_original' => '10.00',
				'nyp_currency' => 'CAD',
			),
			$product
		);

		$this->assertTrue( $remove );
		$notices = wc_get_notices( 'error' );
		$this->assertCount( 1, $notices );
		$this->assertStringContainsString( $product->get_name(), $notices[0]['notice'] );
		wc_clear_notices();
	}

	/**
	 * @testdox Should report the removed item in the Store API cart errors when the cart loads from the session.
	 */
	public function test_reports_the_removed_nyp_item_in_store_api_cart_errors(): void {
		$sut = $this->create_controller();
		$sut->register();
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->empty_cart();
		wc_clear_notices();

		// Name Your Price keeps the entered amount and its currency on the cart item
		// (client 11.1.0 includes/multi-currency/Compatibility/WooCommerceNameYourPrice.php:66-69).
		WC()->session->set(
			'cart',
			array(
				'nyp-item' => array(
					'key'          => 'nyp-item',
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'variation'    => array(),
					'quantity'     => 1,
					'nyp'          => '99.00',
					'nyp_original' => '10.00',
					'nyp_currency' => 'CAD',
				),
			)
		);
		WC()->cart->get_cart_from_session();

		$this->assertSame( array(), WC()->cart->get_cart() );
		$messages = ( new CartController() )->get_cart_errors()->get_error_messages();
		$this->assertCount( 1, $messages );
		$this->assertStringContainsString( $product->get_name(), $messages[0] );
		wc_clear_notices();
	}

	/**
	 * @testdox Should keep cart items whose price can still be converted, and leave other items alone.
	 */
	public function test_keeps_nyp_items_that_can_still_be_converted(): void {
		$sut     = $this->create_controller();
		$product = \WC_Helper_Product::create_simple_product();

		foreach ( array( 'USD', 'GBP' ) as $currency ) {
			$this->assertFalse(
				$sut->remove_unconvertible_cart_item(
					false,
					'cart-item-key',
					array(
						'nyp'          => '10.00',
						'nyp_original' => '10.00',
						'nyp_currency' => $currency,
					),
					$product
				),
				$currency . ' is enabled, so the item converts.'
			);
		}
		$this->assertFalse( $sut->remove_unconvertible_cart_item( false, 'cart-item-key', array( 'quantity' => 1 ), $product ) );
		$this->assertTrue( $sut->remove_unconvertible_cart_item( true, 'cart-item-key', array( 'quantity' => 1 ), $product ), 'An earlier removal decision stands.' );
	}

	/**
	 * @testdox Should not swallow a failed conversion of a NYP amount.
	 */
	public function test_does_not_swallow_a_failed_nyp_conversion(): void {
		$sut = $this->create_controller();

		$this->expectException( InvalidCurrencyException::class );

		$sut->convert_cart_currency(
			array(
				'data'         => $this->create_product(),
				'nyp'          => '99.00',
				'nyp_original' => '10.00',
				'nyp_currency' => 'CAD',
			),
			array()
		);
	}

	/**
	 * @testdox Should preserve cart item when NYP runtime function is unavailable.
	 */
	public function test_preserves_cart_item_when_name_your_price_function_is_unavailable(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false );

		$cart_item = array(
			'nyp_original' => '10.00',
			'nyp_currency' => 'USD',
		);

		$this->assertSame( $cart_item, $sut->convert_cart_currency( $cart_item, array() ) );
	}

	/**
	 * @testdox Should leave a cart item without both the entered NYP price and its currency unchanged.
	 */
	public function test_leaves_cart_item_without_entered_price_and_currency_unchanged(): void {
		$sut = $this->create_controller();

		$this->assertSame( array( 'nyp_currency' => 'USD' ), $sut->convert_cart_currency( array( 'nyp_currency' => 'USD' ), array() ) );
		$this->assertSame( array( 'nyp_original' => '10.00' ), $sut->convert_cart_currency( array( 'nyp_original' => '10.00' ), array() ) );
	}

	/**
	 * @testdox Should suppress product price conversion for NYP product contexts.
	 */
	public function test_suppresses_product_price_conversion_for_nyp_product_contexts(): void {
		$sut               = $this->create_controller();
		$product_with_meta = $this->create_product( 'GBP' );
		$nyp_product       = $this->create_product( '', true );

		$this->assertFalse( $sut->should_convert_product_price( true, $product_with_meta ) );
		$this->assertFalse( $sut->should_convert_product_price( true, $nyp_product ) );
		$this->assertFalse( $sut->should_convert_product_price( false, $this->create_product() ) );
		$this->assertTrue( $sut->should_convert_product_price( true, $this->create_product() ) );
		$this->assertTrue( $sut->should_convert_product_price( true, $this->create_product( 'USD' ) ), 'An entry currency other than the selected one does not stop conversion.' );
	}

	/**
	 * @testdox Should add selected currency to cart edit args.
	 */
	public function test_adds_selected_currency_to_cart_edit_args(): void {
		$sut = $this->create_controller();

		$this->assertSame(
			array(
				'existing'     => 'value',
				'nyp_currency' => 'GBP',
			),
			$sut->edit_in_cart_args( array( 'existing' => 'value' ), array() )
		);
	}

	/**
	 * @testdox Should convert initial edit price from request currency.
	 */
	public function test_converts_initial_edit_price_from_request_currency(): void {
		$sut                        = $this->create_controller();
		$_REQUEST['nyp_raw_bundle'] = '10.00';
		$_REQUEST['nyp_currency']   = 'USD';

		$this->assertSame( 8.2, $sut->get_initial_price( '99.00', $this->create_product(), '_bundle' ) );

		$_REQUEST['nyp_currency'] = 'GBP';

		$this->assertSame( '99.00', $sut->get_initial_price( '99.00', $this->create_product(), '_bundle' ) );

		$_REQUEST['nyp_currency'] = 'USD';
		unset( $_REQUEST['nyp_raw_bundle'] );

		$this->assertSame( '99.00', $sut->get_initial_price( '99.00', $this->create_product(), '_bundle' ) );

		$_REQUEST['nyp_raw_bundle'] = '10.00';
		unset( $_REQUEST['nyp_currency'] );

		$this->assertSame( '99.00', $sut->get_initial_price( '99.00', $this->create_product(), '_bundle' ) );
	}

	/**
	 * @testdox NYP 4.0.1 keeps both inline edits through server cart reloads starting in $start.
	 * @testWith ["USD", "EUR", 12]
	 *           ["EUR", "USD", 18.75]
	 *
	 * @param string $start    Initial currency.
	 * @param string $other    Second currency.
	 * @param float  $switched First edit in the second currency.
	 */
	public function test_inline_edits_survive_reloads( string $start, string $other, float $switched ): void {
		$sut     = $this->register_version( '4.0.1' );
		$product = $this->cart_product();
		$sut->set_name_your_price_product_ids( array( $product->get_id() ) );
		$this->select_currency( $start );
		$untracked = array( 'nyp' => 10 );
		$this->assertSame( $untracked, apply_filters( 'wc_nyp_edited_cart_item', $untracked, 12 ) );
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'nyp' => 10 ) );
		$this->assert_reloaded_price( $key, 10 );

		foreach ( array(
			$start => 15,
			$other => 20,
		) as $currency => $amount ) {
			$this->select_currency( $currency );
			if ( $currency === $other ) {
				$this->assert_reloaded_price( $key, $switched );
			}

			// NYP validates the new amount, filters the line, then applies its price and recalculates totals.
			$line                             = WC()->cart->get_cart_item( $key );
			$line['nyp']                      = $amount;
			$edited                           = apply_filters( 'wc_nyp_edited_cart_item', $line, $amount, $key );
			WC()->cart->cart_contents[ $key ] = $edited;
			$edited['data']->set_price( $amount );
			WC()->cart->calculate_totals();
			$this->assert_reloaded_price( $key, $amount );
			$this->assertSame( $amount, $edited['nyp_original'] );
			$this->assertSame( $currency, $edited['nyp_currency'] );
		}
	}

	/**
	 * @testdox NYP 4.0.1 applies the converted cart price on the first session load after a currency switch.
	 *
	 * The old cart check missed the extension's magic getter and left the product at 15 after the switch to USD.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_currency_switch_updates_the_nyp_cart_product_price(): void {
		require_once __DIR__ . '/Fixtures/name-your-price-cart.inc';
		$GLOBALS['wc_name_your_price'] = new \WC_Name_Your_Price();
		$sut                           = new WooCommerceNameYourPrice();
		$this->init_controller( $sut, MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$sut->register();
		$this->select_currency( 'USD' );

		// NYP has already applied the saved EUR price when our session filter runs.
		$product = $this->create_product();
		$product->set_price( '15.00' );
		$values = array(
			'nyp'          => '15.00',
			'nyp_original' => '15.00',
			'nyp_currency' => 'EUR',
		);
		$line   = apply_filters( 'woocommerce_get_cart_item_from_session', array( 'data' => $product ) + $values, $values, 'nyp-item' );

		$this->assertSame( 18.75, $line['nyp'] );
		$this->assertSame( 18.75, (float) $line['data']->get_price() );
	}

	/**
	 * @testdox NYP 4.0.1 converts every suggested button once with product rounding and preserves its metadata.
	 */
	public function test_suggested_buttons_use_product_prices(): void {
		$this->register_version( '4.0.1' );
		$buttons = array(
			3         => array( 'price' => 10.0 ),
			'default' => array(
				'price'   => 20.0,
				'default' => true,
			),
			8         => array( 'price' => 50.0 ),
			'bad'     => array( 'price' => 'other' ),
			'empty'   => new \stdClass(),
		);
		$this->select_currency( 'USD' );
		$this->assertSame( $buttons, apply_filters( 'wc_nyp_suggested_prices', $buttons ) );
		$this->select_currency( 'EUR' );
		$expected                     = $buttons;
		$expected[3]['price']         = 8.0;
		$expected['default']['price'] = 16.0;
		$expected[8]['price']         = 40.0;
		$this->assertSame( $expected, apply_filters( 'wc_nyp_suggested_prices', $buttons ) );
		$this->select_currency( 'EUR', true, '5' );
		$expected[3]['price']         = 10.0;
		$expected['default']['price'] = 20.0;
		$this->assertSame( $expected, apply_filters( 'wc_nyp_suggested_prices', $buttons ) );
	}

	/**
	 * @testdox NYP 4.0.1 converts a EUR reorder before validation and preserves the existing cart if EUR is disabled.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $eur_enabled Whether the shopper can still use the order currency.
	 */
	public function test_reorder_uses_the_order_currency( bool $eur_enabled ): void {
		$this->register_version( '4.0.1' );
		$this->select_currency( 'USD', $eur_enabled );
		$user_id = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user_id );
		$product = $this->cart_product();
		$order   = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_currency( 'EUR' );
		$order->add_product( $this->cart_product() );
		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => 15,
				'total'    => 15,
			)
		);
		wc_add_order_item_meta( $item_id, '_wc_nyp_price', '15.00' );
		$order->calculate_totals();
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();

		// NYP supplies the order's entered price at priority 5, before WooCommerce validates each line.
		add_filter(
			'woocommerce_order_again_cart_item_data',
			static function ( $line, $item ) {
				$price = $item->get_meta( '_wc_nyp_price' );
				return is_numeric( $price ) ? array( 'nyp' => (float) $price ) + $line : $line;
			},
			5,
			2
		);
		$validated = array();
		add_filter(
			'woocommerce_add_to_cart_validation',
			static function ( $valid, $id, $quantity, $variation, $attributes, $line ) use ( &$validated ) {
				$validated[ $id ] = $line;
				return $valid;
			},
			10,
			6
		);

		$previous_session = WC()->session;
		try {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init_session_cookie();
			$existing_key = WC()->cart->add_to_cart( $this->cart_product()->get_id() );
			$session      = new \WC_Cart_Session( WC()->cart );
			$session->set_session();
			wc_add_notice( 'Existing message.', 'notice' );
			$before  = WC()->session->get( 'cart' );
			$notices = wc_get_notices();
			WC()->session->save_data();

			$redirect = new \RuntimeException();
			add_filter(
				'wp_redirect',
				function ( $url ) use ( $redirect ) {
					$this->assertSame( wc_get_cart_url(), $url );
					throw $redirect;
				}
			);
			$_GET['order_again'] = $order->get_id();
			$_GET['_wpnonce']    = wp_create_nonce( 'woocommerce-order_again' );
			try {
				$session->get_cart_from_session();
				$this->fail( 'The reorder should redirect to the clean cart.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( $redirect, $e );
			} finally {
				unset( $_GET['order_again'], $_GET['_wpnonce'] );
			}

			// Save as shutdown would, then load the next request from the shopper's persistent session.
			WC()->session->save_data();
			WC()->session = new \WC_Session_Handler();
			WC()->session->init_session_cookie();
			WC()->cart->set_cart_contents( array() );
			$session->get_cart_from_session();
			if ( ! $eur_enabled ) {
				$this->assertArrayNotHasKey( $product->get_id(), $validated );
				$this->assertSame( $before, WC()->session->get( 'cart' ) );
				$this->assertSame( array( $existing_key ), array_keys( WC()->cart->get_cart() ) );
				$this->assertSame( $notices, wc_get_notices() );
				return;
			}
			$line = $validated[ $product->get_id() ];
			$this->assertSame( 18.75, $line['nyp'] );
			$this->assertSame( 15.0, $line['nyp_original'] );
			$this->assertSame( 'EUR', $line['nyp_currency'] );
			$this->select_currency( 'EUR' );
			$session->get_cart_from_session();
			$lines = array_column( WC()->cart->get_cart(), null, 'product_id' );
			$this->assertSame( 15.0, $lines[ $product->get_id() ]['nyp'] );
			$this->assertSame( '15', $lines[ $product->get_id() ]['data']->get_price() );
		} finally {
			WC()->session = $previous_session;
		}
	}

	/**
	 * @testdox NYP $version keeps its existing hooks and reorder behavior, with the three additional hooks on 4.x.
	 * @testWith ["3.8.3", false]
	 *           ["4.0.1", true]
	 *
	 * @param string $version   NYP version.
	 * @param bool   $has_edits Whether NYP supports inline cart edits.
	 */
	public function test_hooks_and_legacy_replacement_edit( string $version, bool $has_edits ): void {
		$sut    = $this->register_version( $version );
		$legacy = $this->provide_name_your_price_stores()['Name Your Price loaded under core'][2];
		foreach ( $legacy as $hook => $callbacks ) {
			foreach ( $callbacks as $method => $registration ) {
				$this->assertSame( $registration[0], has_filter( $hook, array( $sut, $method ) ) );
			}
		}
		foreach ( array( 'wc_nyp_edited_cart_item', 'wc_nyp_suggested_prices', 'woocommerce_order_again_cart_item_data' ) as $hook ) {
			$this->assertSame( $has_edits, has_filter( $hook ), $hook );
		}
		if ( $has_edits ) {
			return;
		}

		$product = $this->cart_product();
		$sut->set_name_your_price_product_ids( array( $product->get_id() ) );
		$this->select_currency( 'EUR' );
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'nyp' => 10 ) );
		WC()->cart->remove_cart_item( $key );
		$key = WC()->cart->add_to_cart( $product->get_id(), 1, 0, array(), array( 'nyp' => 15 ) );
		$this->assert_reloaded_price( $key, 15 );
		$this->select_currency( 'USD' );
		WC()->cart->get_cart_from_session();
		$this->assertSame( 18.75, WC()->cart->get_cart_item( $key )['nyp'] );

		$order = wc_create_order();
		$order->set_currency( 'EUR' );
		$item_id = $order->add_product(
			$product,
			1,
			array(
				'subtotal' => 15,
				'total'    => 15,
			)
		);
		$order->set_status( OrderStatus::COMPLETED );
		$order->save();
		add_filter( 'woocommerce_order_again_cart_item_data', static fn( $line, $item ) => array( 'nyp' => $item->get_subtotal() / $item->get_quantity() ) + $line, 5, 2 );
		$this->assertEquals( array( 'nyp' => 15 ), apply_filters( 'woocommerce_order_again_cart_item_data', array(), $order->get_item( $item_id ), $order ) );
	}

	/**
	 * Register twice, as when called before and after plugins load.
	 *
	 * @param string $version Version exposed by the NYP singleton.
	 */
	private function register_version( string $version ): WooCommerceNameYourPrice {
		$sut      = $this->create_controller();
		$previous = $GLOBALS['wc_name_your_price'] ?? null;
		try {
			$GLOBALS['wc_name_your_price'] = (object) array( 'version' => $version );
			$sut->register();
			$sut->register();
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['wc_name_your_price'] );
			} else {
				$GLOBALS['wc_name_your_price'] = $previous;
			}
		}
		return $sut;
	}

	/** Create a virtual untaxed cart product. */
	private function cart_product(): \WC_Product {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->set_tax_status( 'none' );
		$product->save();
		return $product;
	}

	/**
	 * Select a currency at the fixed USD to EUR rate.
	 *
	 * @param string $code        Selected currency.
	 * @param bool   $eur_enabled Whether the store accepts EUR.
	 * @param string $rounding    Product price rounding step in EUR.
	 */
	private function select_currency( string $code, bool $eur_enabled = true, string $rounding = '0' ): void {
		$usd                  = $this->create_currency( 'USD', 1, true );
		$eur                  = $this->create_currency( 'EUR', 0.8, false, $rounding );
		$available            = array(
			'USD' => $usd,
			'EUR' => $eur,
		);
		$enabled              = $eur_enabled ? $available : array( 'USD' => $usd );
		$this->currency_state = new MultiCurrencyState( $available, $enabled, $usd, $enabled[ $code ] );
	}

	/**
	 * Reload from the saved cart, then read the product and the chargeable total.
	 *
	 * @param string $key    Cart line key.
	 * @param float  $amount Expected unit price and total for one item.
	 */
	private function assert_reloaded_price( string $key, float $amount ): void {
		$session = new \WC_Cart_Session( WC()->cart );
		$session->set_session();
		WC()->cart->set_cart_contents( array() );
		$session->get_cart_from_session();
		WC()->cart->calculate_totals();
		$this->assertSame( $amount, (float) WC()->cart->get_cart_item( $key )['data']->get_price() );
		$this->assertSame( $amount, (float) WC()->cart->get_total( 'edit' ) );
	}

	/**
	 * Create a Name Your Price compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner                              Runtime owner.
	 * @param bool   $name_your_price_available          Whether Name Your Price runtime is available.
	 * @param bool   $name_your_price_function_available Whether the Name Your Price function is available.
	 * @param bool   $plugins_loaded                     Whether plugins have loaded.
	 * @return WooCommerceNameYourPrice
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $name_your_price_available = true,
		bool $name_your_price_function_available = true,
		bool $plugins_loaded = true
	): WooCommerceNameYourPrice {
		$controller = new class( $name_your_price_available, $name_your_price_function_available, $plugins_loaded ) extends WooCommerceNameYourPrice {
			/**
			 * Whether Name Your Price runtime is available.
			 *
			 * @var bool
			 */
			private bool $name_your_price_available;

			/**
			 * Whether the Name Your Price function is available.
			 *
			 * @var bool
			 */
			private bool $name_your_price_function_available;

			/**
			 * Whether plugins have loaded.
			 *
			 * @var bool
			 */
			private bool $plugins_loaded;

			/**
			 * NYP-enabled product IDs.
			 *
			 * @var int[]
			 */
			private array $name_your_price_product_ids = array();

			/**
			 * Constructor.
			 *
			 * @param bool $name_your_price_available          Whether Name Your Price runtime is available.
			 * @param bool $name_your_price_function_available Whether the Name Your Price function is available.
			 * @param bool $plugins_loaded                     Whether plugins have loaded.
			 */
			public function __construct( bool $name_your_price_available, bool $name_your_price_function_available, bool $plugins_loaded ) {
				$this->name_your_price_available          = $name_your_price_available;
				$this->name_your_price_function_available = $name_your_price_function_available;
				$this->plugins_loaded                     = $plugins_loaded;
			}

			/**
			 * Set whether Name Your Price runtime is available.
			 *
			 * @param bool $name_your_price_available Whether Name Your Price runtime is available.
			 */
			public function set_name_your_price_available( bool $name_your_price_available ): void {
				$this->name_your_price_available = $name_your_price_available;
			}

			/**
			 * Set NYP-enabled product IDs.
			 *
			 * @param int[] $product_ids Product IDs.
			 */
			public function set_name_your_price_product_ids( array $product_ids ): void {
				$this->name_your_price_product_ids = $product_ids;
			}

			/**
			 * Check if Name Your Price runtime is available.
			 *
			 * @return bool
			 */
			protected function is_name_your_price_runtime_available(): bool {
				return $this->name_your_price_available;
			}

			/**
			 * Check if the Name Your Price function is available.
			 *
			 * @return bool
			 */
			protected function is_name_your_price_function_available(): bool {
				return $this->name_your_price_function_available;
			}

			/**
			 * Tell whether a product is NYP-enabled.
			 *
			 * @param mixed $product Product or product ID.
			 * @return bool
			 */
			protected function is_name_your_price_product( $product ): bool {
				if ( is_int( $product ) ) {
					return in_array( $product, $this->name_your_price_product_ids, true );
				}

				return is_object( $product ) && ! empty( $product->name_your_price_enabled );
			}

			/**
			 * Run the Name Your Price cart item normalizer.
			 *
			 * @param array<mixed> $cart_item Cart item.
			 * @return array<mixed>
			 */
			protected function set_name_your_price_cart_item( array $cart_item ): array {
				if ( isset( $cart_item['nyp'], $cart_item['data'] ) ) {
					$cart_item['data']->set_price( $cart_item['nyp'] );
				}
				return $cart_item;
			}

			/**
			 * The request signals this double's case sets.
			 *
			 * @return array<string,bool>
			 */
			private function request_signals(): array {
				return array( 'plugins_loaded' => $this->plugins_loaded );
			}

			/** Register with this case's request signals set. */
			public function register() {
				RequestSignals::run( $this->request_signals(), fn() => parent::register() );
			}
		};

		$this->init_controller( $controller, $owner );
		return $controller;
	}

	/**
	 * Initialize a controller with the test currencies and pricing services.
	 *
	 * @param WooCommerceNameYourPrice $controller Controller under test.
	 * @param string                   $owner      Runtime owner.
	 */
	private function init_controller( WooCommerceNameYourPrice $controller, string $owner ): void {
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::state_builder( $this, $this->create_state_builder( $this->create_state() ) ),
			ServiceFactories::with( $this, array( 'create_price_converter' => $this->create_price_converter() ) )
		);
	}

	/**
	 * Create a runtime arbiter test double.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Get the multi-currency runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}
		};
	}

	/**
	 * Create a price projection service.
	 *
	 * @return PriceConverter
	 */
	private function create_price_converter(): PriceConverter {
		$localization = $this->create_localization();

		return new PriceConverter(
			$this->create_state_builder( $this->create_state() ),
			new PriceCalculator( $localization )
		);
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return StateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): StateBuilder {
		$builder = $this->createMock( StateBuilder::class );
		$builder->method( 'build' )->willReturnCallback( fn() => $this->currency_state ?? $state );
		return $builder;
	}

	/**
	 * Create multi-currency state.
	 *
	 * @return MultiCurrencyState
	 */
	private function create_state(): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', 1.0, true );
		$gbp = $this->create_currency( 'GBP', 0.82, false, '0.50', -0.10 );

		$enabled = array(
			'USD' => $usd,
			'GBP' => $gbp,
		);

		return new MultiCurrencyState( $enabled, $enabled, $usd, $gbp );
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param float  $rate       Currency rate.
	 * @param bool   $is_default Whether this is the default currency.
	 * @param string $rounding   Rounding amount.
	 * @param float  $charm      Charm amount.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default, string $rounding = '0', float $charm = 0.0 ): MultiCurrencyCurrency {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
		$currency->set_rounding( $rounding );
		$currency->set_charm( $charm );

		return $currency;
	}

	/**
	 * Create a localization test double.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				unset( $currency_code );

				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}

	/**
	 * Create a product, flagged for the controller double's NYP check.
	 *
	 * @param string $nyp_currency             Stored NYP currency.
	 * @param bool   $name_your_price_enabled Whether the product is NYP-enabled.
	 * @return \WC_Product
	 */
	private function create_product( string $nyp_currency = '', bool $name_your_price_enabled = false ): \WC_Product {
		$product = new class() extends \WC_Product_Simple {
			/**
			 * Whether the product is NYP-enabled.
			 *
			 * @var bool
			 */
			public bool $name_your_price_enabled = false;
		};

		$product->name_your_price_enabled = $name_your_price_enabled;
		if ( '' !== $nyp_currency ) {
			$product->update_meta_data( '_wcpay_multi_currency_nyp_currency', $nyp_currency );
		}

		return $product;
	}
}
