<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWooPayStoreApiRequestHandler class: WooPay's signed Store API requests and the order hooks they reach.
 */
class WooPaymentsWooPayStoreApiRequestHandlerTest extends WC_Unit_Test_Case {

	use WooPayServicesFixture;

	/**
	 * @testdox The WooPay draft order is left alone outside a checkout request and while another order awaits payment.
	 */
	public function test_woopay_draft_order_is_left_alone_outside_its_case(): void {
		$sut   = $this->create_handler( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );

		// An earlier test in the process can define WOOCOMMERCE_CHECKOUT; the override wins over it.
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', false );
		try {
			$this->assertNull( $sut->maybe_use_store_api_draft_order_id( null ), 'Outside a checkout request.' );
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}
		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );

		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );
		WC()->session->set( 'order_awaiting_payment', 123 );
		try {
			$this->assertNull( $sut->maybe_use_store_api_draft_order_id( null ), 'With an order awaiting payment.' );
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertSame( OrderStatus::CHECKOUT_DRAFT, wc_get_order( $draft->get_id() )->get_status() );
	}

	/**
	 * @testdox Should leave the resolved user untouched and the request unflagged for $_dataName.
	 * @dataProvider passthrough_request_provider
	 *
	 * @param string|null         $user_agent  The request user agent, or null for none.
	 * @param string              $request_uri The request URI.
	 * @param array<string,mixed> $settings    Gateway settings overrides.
	 */
	public function test_determine_current_user_passes_through( ?string $user_agent, string $request_uri, array $settings ): void {
		if ( null !== $user_agent ) {
			$_SERVER['HTTP_USER_AGENT'] = $user_agent;
		}
		$_SERVER['REQUEST_URI'] = $request_uri;

		$this->assertFalse( $this->create_handler( $settings )->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertFalse( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * Requests the WooPay identity chain leaves alone.
	 *
	 * @return array<string,array{0:string|null,1:string,2:array<string,mixed>}>
	 */
	public function passthrough_request_provider(): array {
		return array(
			'a request without the WooPay user agent' => array( null, '/wp-json/wc/store/v1/checkout', array() ),
			'a request outside the Store API'         => array( 'WooPay', '/checkout/', array() ),
			'a WooPay Store API request while WooPay is disabled' => array( 'WooPay', '/wp-json/wc/store/v1/checkout', array( 'platform_checkout' => 'no' ) ),
			'a Store API route outside the WooPay allowlist' => array( 'WooPay', '/wp-json/wc/store/v1/products', array() ),
		);
	}

	/**
	 * @testdox Should die with a 401 when a WooPay request is not signed with the blog token: $_dataName.
	 * @dataProvider unsigned_request_provider
	 *
	 * @param string      $request_uri The request URI.
	 * @param string|null $rest_route  The rest_route query argument, or null for none.
	 */
	public function test_determine_current_user_dies_401_when_request_is_not_signed( string $request_uri, ?string $rest_route ): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = $request_uri;
		if ( null !== $rest_route ) {
			$_REQUEST['rest_route'] = $rest_route;
		}

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'WooPay request is not signed correctly.' );

		try {
			$this->create_handler()->determine_current_user_for_woopay( false );
		} finally {
			unset( $_REQUEST['rest_route'] );
		}
	}

	/**
	 * Unsigned WooPay requests the identity chain refuses.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public function unsigned_request_provider(): array {
		return array(
			'a Store API checkout request' => array( '/wp-json/wc/store/v1/checkout', null ),
			'a Store API request in the rest_route query form' => array( '/index.php?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout', '/wc/store/v1/checkout' ),
			'the WooPay session route'     => array( '/wp-json/payments/woopay/session', null ),
		);
	}

	/**
	 * @testdox Should flag the request through wcpay_is_woopay_store_api_request when signed, even without a resolvable cart token.
	 */
	public function test_determine_current_user_flags_woopay_store_api_request_when_signed(): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();
		$_SERVER['HTTP_CART_TOKEN'] = 'garbage-token';

		$this->assertFalse( $this->create_handler()->determine_current_user_for_woopay( false ) );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		$this->assertTrue( apply_filters( 'wcpay_is_woopay_store_api_request', false ) );
	}

	/**
	 * @testdox Should resolve the shopper from an authenticated cart-token session: $label.
	 *
	 * Client 11.1.0 honours the WooPay identity whenever WooPay is on for an eligible account, onboarded or not
	 * (includes/woopay/class-woopay-session.php:101-108, :883-890).
	 *
	 * @testWith ["onboarded account", {}]
	 *           ["account still finishing onboarding", {"details_submitted": false}]
	 *
	 * @param string              $label        Case label.
	 * @param array<string,mixed> $account_data Account data overrides.
	 */
	public function test_determine_current_user_resolves_user_from_authenticated_cart_token( string $label, array $account_data ): void {
		unset( $label );
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

		$this->assertSame( $user_id, $this->create_handler( array(), $account_data )->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should resolve no shopper from a cart token another issuer signed with the store's cart token secret.
	 *
	 * Client 11.1.0 accepts only Store API namespaces as the issuer (includes/woopay/class-woopay-session.php:341-344).
	 */
	public function test_determine_current_user_ignores_a_cart_token_from_another_issuer(): void {
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
		$secret = new \ReflectionMethod( \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::class, 'get_cart_token_secret' );
		$secret->setAccessible( true );
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken::create(
			array(
				'user_id' => (string) $user_id,
				'exp'     => \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token_expiration(),
				'iss'     => 'another-api',
			),
			$secret->invoke( null )
		);

		$this->assertFalse( $this->create_handler()->determine_current_user_for_woopay( false ) );
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

		$this->assertSame( $user_id, $this->create_handler()->determine_current_user_for_woopay( false ) );
	}

	/**
	 * @testdox Should not resolve a WooPay-verified email when $_dataName.
	 *
	 * @dataProvider unmatched_verified_email_provider
	 *
	 * @param string   $session_email     The email the cart-token session holds.
	 * @param string   $header_email      The X-WooPay-Verified-Email-Address value.
	 * @param string[] $accounts          Emails of the store accounts that exist.
	 * @param bool     $adapted_extension Whether an adapted extension is active.
	 */
	public function test_determine_current_user_ignores_an_unmatched_verified_email( string $session_email, string $header_email, array $accounts, bool $adapted_extension ): void {
		$this->simulate_woopay_store_api_request();
		$this->force_real_blog_token_signed();

		foreach ( $accounts as $account_email ) {
			$this->factory->user->create( array( 'user_email' => $account_email ) );
		}
		if ( $adapted_extension ) {
			update_option( 'woopay_enabled_adapted_extensions', array( 'woocommerce-gift-cards' ) );
		}
		$this->insert_store_api_session(
			't_guesthash',
			array(
				'id'    => '0',
				'email' => $session_email,
			)
		);
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( 't_guesthash' );
		// The header WooPay sends with the email it verified (client 11.1.0 reads it at class-woopay-session.php:872-875).
		$_SERVER['HTTP_X_WOOPAY_VERIFIED_EMAIL_ADDRESS'] = $header_email;

		$this->assertFalse( $this->create_handler()->determine_current_user_for_woopay( false ) );
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

		$this->create_handler()->woopay_order_payment_status_changed( $order->get_id() );

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

		$this->create_handler()->woopay_order_payment_status_changed( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $user_id, $order->get_customer_id() );
		$this->assertFalse( $order->meta_exists( 'woopay_merchant_customer_id' ) );
	}

	/**
	 * @testdox Should update adapted-extension order data for every WooPay Store API order, verified email or not, and only for those: $_dataName.
	 * @dataProvider extension_order_data_provider
	 *
	 * @param bool $from_woopay      Whether the request is a WooPay Store API request.
	 * @param int  $expected_updates How many times the order's extension data is updated.
	 */
	public function test_payment_status_change_updates_extension_order_data_only_for_woopay_requests( bool $from_woopay, int $expected_updates ): void {
		if ( $from_woopay ) {
			$this->simulate_woopay_store_api_request();
		}

		$order = wc_create_order();
		$order->save();

		$adapted_extensions = $this->createMock( WooPaymentsWooPayAdaptedExtensions::class );
		$adapted_extensions->expects( $this->exactly( $expected_updates ) )
			->method( 'update_order_extension_data' )
			->with( $order->get_id() );

		$this->create_handler( array(), array(), $adapted_extensions )->woopay_order_payment_status_changed( $order->get_id() );
	}

	/**
	 * Requests whose orders do or do not get their adapted-extension data updated.
	 *
	 * @return array<string,array{0:bool,1:int}>
	 */
	public function extension_order_data_provider(): array {
		return array(
			'a WooPay Store API request without a verified email' => array( true, 1 ),
			'a request from outside WooPay' => array( false, 0 ),
		);
	}

	/**
	 * @testdox Should record a WooPay checkout fatal as an order note with the error's first line.
	 */
	public function test_woopay_checkout_fatal_is_recorded_as_an_order_note(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$order                      = wc_create_order();

		$sut = $this->create_handler();
		$sut->catch_woopay_checkout_errors( $order );
		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_ERROR,
				'message' => "Allowed memory size exhausted\nStack trace: #0",
				'file'    => 'checkout.php',
				'line'    => 42,
			)
		);

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertNotEmpty( $notes );
		$this->assertSame( 'WooPay checkout encountered a fatal error: Allowed memory size exhausted', $notes[0]->content );
	}

	/**
	 * @testdox Should log a WooPay checkout fatal with its type and place, never its message.
	 */
	public function test_woopay_checkout_fatal_log_line_leaves_out_the_message(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$order                      = wc_create_order();
		$logged                     = array();
		$record                     = static function ( $message, $level, $context ) use ( &$logged ) {
			$logged[] = array(
				'message' => (string) $message,
				'level'   => (string) $level,
				'context' => is_array( $context ) ? $context : array(),
			);

			return $message;
		};
		add_filter( 'woocommerce_logger_log_message', $record, 10, 3 );

		$sut = $this->create_handler();
		$sut->catch_woopay_checkout_errors( $order );
		try {
			// The array error_get_last() returns, with its type, message, file and line keys (PHP manual, error_get_last).
			$sut->maybe_record_woopay_checkout_fatal(
				array(
					'type'    => E_ERROR,
					'message' => "Uncaught Exception: card for shopper@example.com declined\nStack trace: #0",
					'file'    => 'checkout.php',
					'line'    => 42,
				)
			);
		} finally {
			remove_filter( 'woocommerce_logger_log_message', $record, 10 );
		}

		$lines = array_values( array_filter( $logged, static fn( array $line ): bool => 'woopayments' === ( $line['context']['source'] ?? null ) ) );
		$this->assertNotEmpty( $lines );
		$this->assertSame( 'error', $lines[0]['level'] );
		$this->assertSame( 'WooPay checkout fatal error.', $lines[0]['message'] );
		$this->assertSame( E_ERROR, $lines[0]['context']['error_type'] );
		$this->assertSame( 'checkout.php', $lines[0]['context']['file'] );
		$this->assertSame( 42, $lines[0]['context']['line'] );
		$this->assertSame( $order->get_id(), $lines[0]['context']['order_id'] );
		foreach ( $logged as $line ) {
			$this->assertStringNotContainsString( 'shopper@example.com', $line['message'] . wp_json_encode( $line['context'] ) );
		}
	}

	/**
	 * @testdox Should ignore non-fatal errors and non-WooPay requests when recording checkout fatals.
	 */
	public function test_woopay_checkout_fatal_capture_ignores_non_fatals_and_non_woopay_requests(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$order                      = wc_create_order();

		$sut = $this->create_handler();
		$sut->catch_woopay_checkout_errors( $order );

		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_WARNING,
				'message' => 'Just a warning',
				'file'    => 'checkout.php',
				'line'    => 7,
			)
		);
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		unset( $_SERVER['HTTP_USER_AGENT'] );
		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'Fatal after UA vanished',
				'file'    => 'checkout.php',
				'line'    => 9,
			)
		);
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox Should not track checkout orders for fatal capture outside WooPay requests.
	 */
	public function test_woopay_checkout_fatal_capture_requires_a_woopay_request_at_registration(): void {
		$order = wc_create_order();

		$sut = $this->create_handler();
		$sut->catch_woopay_checkout_errors( $order );

		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$sut->maybe_record_woopay_checkout_fatal(
			array(
				'type'    => E_ERROR,
				'message' => 'Fatal without registration',
				'file'    => 'checkout.php',
				'line'    => 3,
			)
		);
		$this->assertSame( array(), wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * @testdox A classic checkout after an unfinished WooPay checkout places the WooPay draft order instead of a new one.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-direct-checkout.php:56-79 moves store_api_draft_order to the pending
	 * order_awaiting_payment that WC_Checkout::create_order() resumes, so the draft's own stock hold cannot block the order.
	 */
	public function test_classic_checkout_resumes_the_woopay_draft_order(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		// The draft the Store API saves when WooPay places the order (Checkout::create_or_update_draft_order()).
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertSame( $draft->get_id(), $order_id );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $order_id )->get_status() );
		$this->assertNull( WC()->session->get( 'store_api_draft_order' ) );
		// The takeover released the order payment lock, so the checkout that follows can pay the order.
		$store = wc_get_container()->get( OrderPaymentLock::class );
		$token = $store->claim( $draft, new WooPaymentsPersistenceVocabulary(), 'attempt-key', 'checkout' );
		$this->assertIsString( $token );
		$store->release( $draft, new WooPaymentsPersistenceVocabulary(), $token );
	}

	/**
	 * @testdox A classic checkout resumes a $status WooPay draft order after an earlier order in the same session was paid.
	 *
	 * WC_Order::payment_complete() and the unpaid-order cancellation write false to order_awaiting_payment
	 * (class-wc-order.php:147, class-wc-form-handler.php:918), and WC_Session::__isset() reports a stored false as set, so
	 * core reads that key with get() and treats a falsy value as no order (class-wc-cart.php:886-891). Client 11.1.0 uses
	 * isset() (class-wc-payments-woopay-direct-checkout.php:62) and skips the draft here. The Store API keeps a failed order
	 * (a failed WooPay payment) under the draft key for a retry (DraftOrderTrait::is_valid_draft_order()).
	 *
	 * @testWith ["checkout-draft"]
	 *           ["failed"]
	 *
	 * @param string $status Status of the order the Store API draft key names.
	 */
	public function test_classic_checkout_resumes_the_woopay_draft_order_after_an_earlier_paid_order( string $status ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( $status );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', false );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertSame( $draft->get_id(), $order_id );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $order_id )->get_status() );
	}

	/**
	 * @testdox A classic checkout never resumes a $status order the Store API draft key names, nor stops for its lock; it places a new order.
	 *
	 * Core resumes only a checkout draft, or a pending or failed order (DraftOrderTrait::is_valid_draft_order(),
	 * WC_Checkout::create_order() at class-wc-checkout.php:424). Client 11.1.0 sets whatever order the key names to pending
	 * (class-wc-payments-woopay-direct-checkout.php:71-73). A refund holds the settled order's payment lock throughout: only
	 * an order that can still be paid stops the checkout while its lock is held.
	 *
	 * @testWith ["processing"]
	 *           ["completed"]
	 *           ["on-hold"]
	 *           ["cancelled"]
	 *           ["refunded"]
	 *
	 * @param string $status Status of the order the Store API draft key names.
	 */
	public function test_classic_checkout_never_resumes_a_settled_order_named_as_the_woopay_draft( string $status ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$settled = new \WC_Order();
		$settled->set_status( $status );
		$settled->set_cart_hash( WC()->cart->get_cart_hash() );
		$settled->save();
		WC()->session->set( 'store_api_draft_order', $settled->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $settled, $vocabulary, 'refund-key', 'refund' );
		$this->assertIsString( $token );

		try {
			$order_id = $this->create_classic_checkout_order();
		} finally {
			$store->release( $settled, $vocabulary, $token );
		}

		$this->assertIsInt( $order_id );
		$this->assertNotSame( $settled->get_id(), $order_id, 'A new order is placed.' );
		$this->assertSame( $status, wc_get_order( $settled->get_id() )->get_status() );
		$this->assertSame( $settled->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
	}

	/**
	 * @testdox A classic checkout never resumes a pending WooPay order whose payment lock is free; it places a new order.
	 *
	 * The Store API checkout sets the order to pending just before it calls the gateway (Routes/V1/Checkout.php, "Set initial
	 * status to 'pending'"), and the payment holds the order payment lock only from there on. Client 11.1.0 resumes it
	 * (class-wc-payments-woopay-direct-checkout.php:71-73); core's Store API resumes a pending order only while the cart is
	 * unchanged (DraftOrderTrait::is_valid_draft_order()). An abandoned pending order is left to core's unpaid-order cleanup;
	 * while a payment holds its lock, checkout stops instead (test_classic_checkout_stops_while_the_woopay_order_payment_lock_is_held).
	 */
	public function test_classic_checkout_never_resumes_a_pending_woopay_order(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$pending = new \WC_Order();
		$pending->set_status( OrderStatus::PENDING );
		$pending->set_cart_hash( WC()->cart->get_cart_hash() );
		$pending->save();
		WC()->session->set( 'store_api_draft_order', $pending->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );

		$order_id = $this->create_classic_checkout_order();

		$this->assertIsInt( $order_id );
		$this->assertNotSame( $pending->get_id(), $order_id, 'A new order is placed.' );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $pending->get_id() )->get_status() );
		$this->assertSame( $pending->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
		$this->assert_order_payment_lock_is_free( $pending, 'The status check under the lock returned early; the lock must be released.' );
	}

	/**
	 * @testdox A classic checkout stops with a notice, places no order and leaves the $status WooPay order alone while another request holds its payment lock (refusal log line throws: $log_throws).
	 *
	 * Native checkout holds the order payment lock across the platform charge, with its attempt key as the lock value
	 * (PaymentProcessingService::process_checkout_outcome()). Placing a new order instead would let this checkout charge a
	 * second order while the first payment is unresolved. Client 11.1.0 takes the draft over without any lock
	 * (class-wc-payments-woopay-direct-checkout.php:56-79). The refusal's log line runs the woocommerce_logger_log_message
	 * filter (WC_Logger::log()); a filter that throws there must not change the refusal.
	 *
	 * @testWith ["checkout-draft", false]
	 *           ["pending", false]
	 *           ["failed", false]
	 *           ["pending", true]
	 *
	 * @param string $status     Status of the order the Store API draft key names.
	 * @param bool   $log_throws Whether the log filter throws on the lock refusal line.
	 */
	public function test_classic_checkout_stops_while_the_woopay_order_payment_lock_is_held( string $status, bool $log_throws ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( $status );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $draft, $vocabulary, 'attempt-key-of-another-request', 'checkout' );
		$new_orders = array();
		$record     = static function ( $order_id ) use ( &$new_orders ): void {
			$new_orders[] = $order_id;
		};
		$this->assertIsString( $token );
		add_action( 'woocommerce_new_order', $record );
		$throws = 0;
		if ( $log_throws ) {
			add_filter(
				'woocommerce_logger_log_message',
				static function ( $message ) use ( &$throws ) {
					if ( 0 === strpos( (string) $message, 'order payment lock refused: ' ) ) {
						++$throws;
						throw new \Error( 'Log write failed.' );
					}

					return $message;
				}
			);
		}

		try {
			$result = $this->create_classic_checkout_order();
		} finally {
			remove_action( 'woocommerce_new_order', $record );
			$store->release( $draft, $vocabulary, $token );
		}

		$this->assertSame( $log_throws ? 1 : 0, $throws );
		$this->assertWPError( $result );
		$this->assertSame( 'woocommerce_woopay_payment_in_progress', $result->get_error_code() );
		$this->assertSame( array(), $new_orders, 'No order is placed.' );
		$this->assertSame( $status, wc_get_order( $draft->get_id() )->get_status() );
		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
	}

	/**
	 * @testdox A full classic checkout retried while WooPay pays the pending order stops with a notice, places and pays no order, and keeps the order's stock hold (stock $stock).
	 *
	 * WC_Checkout::process_checkout() turns the WP_Error that create_order() returns from the woocommerce_create_order filter
	 * into its checkout error notice (class-wc-checkout.php:407-410, :1425-1430, :1464-1468). With one unit left, a second
	 * order could not reserve it while the first holds it (ReserveStock::reserve_stock_for_order()); client 11.1.0 names that
	 * last-unit case (class-wc-payments-woopay-direct-checkout.php:46-51). Runs in a separate process because
	 * process_checkout() defines WOOCOMMERCE_CHECKOUT for the rest of the process.
	 *
	 * @testWith [5]
	 *           [1]
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @param int $stock Stock of the product in the cart.
	 */
	public function test_full_classic_checkout_stops_while_woopay_pays_the_pending_order( int $stock ): void {
		global $wpdb;

		// A guest checkout: with registration required, process_customer() logs the new account in and the test session,
		// never saved, would migrate empty (WC_Session_Handler::migrate_guest_session_to_user_session()).
		update_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_virtual( true );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();
		// The order WooPay's Store API checkout placed: its draft held the stock (Checkout::create_or_update_draft_order() through
		// wc_reserve_stock_for_order()), then the route set it pending before calling the gateway.
		$paying = new \WC_Order();
		$paying->add_product( $product, 1 );
		$paying->set_cart_hash( WC()->cart->get_cart_hash() );
		$paying->calculate_totals();
		$paying->set_status( OrderStatus::CHECKOUT_DRAFT );
		$paying->save();
		wc_reserve_stock_for_order( $paying );
		$paying->set_status( OrderStatus::PENDING );
		$paying->save();
		WC()->session->set( 'store_api_draft_order', $paying->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$reserved = static function () use ( $wpdb, $paying ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(stock_quantity) FROM {$wpdb->wc_reserved_stock} WHERE order_id = %d", $paying->get_id() ) );
		};
		$this->assertSame( 1, $reserved() );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $store->claim( $paying, $vocabulary, 'attempt-key-of-the-woopay-charge', 'checkout' );
		$new_orders = array();
		$record     = static function ( $order_id ) use ( &$new_orders ): void {
			$new_orders[] = $order_id;
		};
		$this->assertIsString( $token );
		add_action( 'woocommerce_new_order', $record );
		wc_clear_notices();
		// The classic checkout form's fields and nonce (WC_Checkout::process_checkout() reads $_REQUEST, get_posted_data() $_POST).
		$posted   = array(
			'billing_first_name'                 => 'Jane',
			'billing_last_name'                  => 'Doe',
			'billing_address_1'                  => '1 Main Street',
			'billing_city'                       => 'San Francisco',
			'billing_state'                      => 'CA',
			'billing_postcode'                   => '94110',
			'billing_country'                    => 'US',
			'billing_phone'                      => '5555555555',
			'billing_email'                      => 'guest@example.com',
			'payment_method'                     => 'woocommerce_payments',
			'woocommerce-process-checkout-nonce' => wp_create_nonce( 'woocommerce-process_checkout' ),
		);
		$_POST    = $posted;
		$_REQUEST = $posted;

		try {
			WC()->checkout()->process_checkout();
		} finally {
			remove_action( 'woocommerce_new_order', $record );
			$store->release( $paying, $vocabulary, $token );
			$_POST    = array();
			$_REQUEST = array();
		}

		$this->assertSame(
			array( 'Your previous payment attempt is still being processed, so please wait a moment and check for an order confirmation before trying again.' ),
			array_column( wc_get_notices( 'error' ), 'notice' )
		);
		$this->assertSame( array(), $new_orders, 'No order is placed.' );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ), 'No payment ran: WC_Checkout::process_order_payment() records the order it pays.' );
		$this->assertSame( OrderStatus::PENDING, wc_get_order( $paying->get_id() )->get_status() );
		$this->assertSame( $paying->get_id(), WC()->session->get( 'store_api_draft_order' ) );
		$this->assertSame( 1, $reserved(), 'The paying order keeps its stock hold.' );
	}

	/**
	 * @testdox A classic checkout reads the WooPay draft again under the payment lock and leaves it alone once it was paid.
	 *
	 * Another request pays the failed draft after this checkout read it and before the takeover claims the lock. The core
	 * `query` filter (wpdb::query()) runs before each statement, so the payment is written right before the claim's
	 * INSERT IGNORE of the lock row (TransientRowLock::claim()).
	 */
	public function test_classic_checkout_leaves_a_woopay_draft_paid_before_the_takeover(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::FAILED );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$draft_id = $draft->get_id();
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		$lock_row = '_transient_' . WooPaymentsPersistenceVocabulary::LOCK_TRANSIENT_PREFIX . $draft_id;
		$paid     = false;
		$pay      = static function ( $query ) use ( $lock_row, $draft_id, &$paid ) {
			if ( ! $paid && is_string( $query ) && false !== strpos( $query, 'INSERT IGNORE' ) && false !== strpos( $query, "'" . $lock_row . "'" ) ) {
				$paid  = true;
				$order = wc_get_order( $draft_id );
				$order->set_status( OrderStatus::PROCESSING );
				$order->save();
			}

			return $query;
		};
		add_filter( 'query', $pay );

		try {
			$order_id = $this->create_classic_checkout_order();
		} finally {
			remove_filter( 'query', $pay );
		}

		$this->assertTrue( $paid, 'The payment landed before the claim.' );
		$this->assertIsInt( $order_id );
		$this->assertNotSame( $draft_id, $order_id, 'A new order is placed.' );
		$this->assertSame( OrderStatus::PROCESSING, wc_get_order( $draft_id )->get_status() );
		$this->assertSame( $draft_id, WC()->session->get( 'store_api_draft_order' ) );
		$this->assert_order_payment_lock_is_free( $draft, 'The status check under the lock returned early; the lock must be released.' );
	}

	/**
	 * @testdox The WooPay draft takeover holds the order payment lock while it writes the order.
	 *
	 * A payment request that starts while the takeover saves the order (WC_Abstract_Order::save() fires
	 * woocommerce_before_order_object_save before the data store write) is refused by the lock.
	 */
	public function test_woopay_draft_takeover_holds_the_payment_lock_while_it_writes(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$draft_id = $draft->get_id();
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		$store      = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$claims     = array();
		$pay        = static function ( $order ) use ( $store, $vocabulary, $draft_id, &$claims ): void {
			// The first save of the order is the takeover's; WC_Checkout::create_order() saves it again after the release.
			if ( array() === $claims && $order instanceof \WC_Order && $draft_id === $order->get_id() && OrderStatus::PENDING === $order->get_status() ) {
				$token    = $store->claim( $order, $vocabulary, 'attempt-key-of-another-request', 'checkout' );
				$claims[] = $token;
				if ( null !== $token ) {
					$store->release( $order, $vocabulary, $token );
				}
			}
		};
		add_action( 'woocommerce_before_order_object_save', $pay );

		try {
			$order_id = $this->create_classic_checkout_order();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $pay );
		}

		$this->assertSame( array( null ), $claims, 'The payment that started during the write was refused.' );
		$this->assertSame( $draft_id, $order_id );
		$this->assert_order_payment_lock_is_free( $draft, 'The takeover released the lock.' );
	}

	/**
	 * @testdox The WooPay draft takeover releases the order payment lock when the $step under it throws, and leaves the order and session alone.
	 *
	 * The fresh read fails through a lifecycle service whose read throws (a native class, replaced in the container). The
	 * save fails through woocommerce_before_order_object_save, which WC_Abstract_Order::save() fires before the data store
	 * write; save() catches an Exception and only logs it (abstract-wc-order.php:278-317), so the failure that reaches the
	 * takeover is an Error.
	 *
	 * @testWith ["read"]
	 *           ["save"]
	 *
	 * @param string $step The step that throws.
	 */
	public function test_woopay_draft_takeover_releases_the_payment_lock_when_it_fails( string $step ): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$draft_id = $draft->get_id();
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		$fail = static function ( $order ) use ( $draft_id ): void {
			if ( $order instanceof \WC_Order && $draft_id === $order->get_id() ) {
				throw new \Error( 'The save failed.' );
			}
		};
		if ( 'read' === $step ) {
			wc_get_container()->replace(
				OrderPaymentLifecycleService::class,
				new class() extends OrderPaymentLifecycleService {
					/**
					 * Fail the fresh read.
					 *
					 * @param \WC_Order $order Order.
					 * @throws \RuntimeException Always.
					 */
					public function get_fresh_order_from_data_store( \WC_Order $order ): \WC_Order {
						throw new \RuntimeException( 'The read failed.' );
					}
				}
			);
		} else {
			add_action( 'woocommerce_before_order_object_save', $fail );
		}

		$thrown = null;
		try {
			$this->create_classic_checkout_order();
		} catch ( \Throwable $failure ) {
			$thrown = $failure->getMessage();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $fail );
		}

		$this->assertSame( 'read' === $step ? 'The read failed.' : 'The save failed.', $thrown );
		$this->assertSame( OrderStatus::CHECKOUT_DRAFT, wc_get_order( $draft_id )->get_status() );
		$this->assertSame( $draft_id, WC()->session->get( 'store_api_draft_order' ) );
		$this->assertNull( WC()->session->get( 'order_awaiting_payment' ) );
		$this->assert_order_payment_lock_is_free( $draft, 'The takeover released the lock in finally.' );
	}

	/**
	 * @testdox A WooPay order credits the AutomateWoo advocate WooPay sends back, and only on WooPay requests.
	 *
	 * Client 11.1.0 class-woopay-session.php:67, :273-289: on a WooPay Store API request the advocate is the
	 * automatewoo_referral_id query parameter, or none; other requests keep AutomateWoo's own value.
	 */
	public function test_woopay_order_credits_the_automatewoo_advocate_from_the_woopay_request(): void {
		$this->register_controller( $this->create_service() );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter; its argument is the advocate ID it found.
		$this->assertSame( 7, apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ), 'A store request keeps AutomateWoo\'s advocate.' );

		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$this->simulate_woopay_store_api_request();
		$_GET['automatewoo_referral_id'] = '42';
		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter.
			$this->assertSame( 42, apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ) );
			unset( $_GET['automatewoo_referral_id'] );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter.
			$this->assertFalse( apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ), 'A WooPay order without a referral credits nobody.' );
		} finally {
			unset( $_GET['automatewoo_referral_id'] );
			$_SERVER['REQUEST_URI'] = $request_uri;
		}
	}

	/**
	 * @testdox The browser sees the session a WooPay Store API request saved, even with a persistent object cache.
	 *
	 * Client 11.1.0 class-woopay-store-api-session-handler.php:108-125 refreshes the cache entry the browser's
	 * WC_Session_Handler::get_session() reads first.
	 */
	public function test_woopay_store_api_save_reaches_the_browser_session(): void {
		$customer_id = 't_' . wp_generate_password( 30, false );
		$browser     = new \WC_Session_Handler();
		// The browser's handler cached the session before the shopper went to WooPay.
		wp_cache_set( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $customer_id, array( 'coupon' => 'before-woopay' ), WC_SESSION_CACHE_GROUP );
		$sut = $this->create_service();
		$this->register_controller( $sut );
		$this->simulate_woopay_store_api_request();
		$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( $customer_id );
		$original_session           = WC()->session;
		WC()->session               = new \Automattic\WooCommerce\StoreApi\SessionHandler();
		WC()->session->init();

		try {
			WC()->session->set( 'coupon', 'added-in-woopay' );
			// What the Store API handler's own shutdown callback (priority 20) does, then the WooPay refresh after it.
			WC()->session->save_data();
			$this->assertSame( 21, has_action( 'shutdown', array( $this->store_api_request_handler, 'refresh_woopay_browser_session_cache' ) ) );
			$this->store_api_request_handler->refresh_woopay_browser_session_cache();
		} finally {
			remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
			WC()->session = $original_session;
		}

		$this->assertSame( 'added-in-woopay', maybe_unserialize( $browser->get_session( $customer_id ) )['coupon'] );
	}

	/**
	 * @testdox A WooPay $0 subscription order needs payment even when WooCommerce Subscriptions says it does not.
	 *
	 * Client 11.1.0 class-woopay-session.php:62, :298-316: on a WooPay Store API request, a $0 order with a subscription in
	 * the cart needs payment; other requests, other totals and carts keep the value.
	 */
	public function test_woopay_zero_total_subscription_order_needs_payment(): void {
		$sut = $this->create_service();
		$this->register_controller( $sut );
		$this->woopay_eligibility->cart_contains_subscription = true;
		$free_order = new \WC_Order();
		$free_order->set_total( 0 );
		$paid_order = new \WC_Order();
		$paid_order->set_total( 10 );

		// phpcs:disable WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core filter WC_Order::needs_payment() applies.
		$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ), 'A store request keeps the value.' );

		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$this->simulate_woopay_store_api_request();
		try {
			$this->assertTrue( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ) );
			$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $paid_order, array() ), 'A paid order keeps the value.' );
			$this->woopay_eligibility->cart_contains_subscription = false;
			$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ), 'A cart without a subscription keeps the value.' );
		} finally {
			$_SERVER['REQUEST_URI'] = $request_uri;
		}
		// phpcs:enable WooCommerce.Commenting.CommentHooks.MissingHookComment
	}

	/**
	 * @testdox A classic checkout places a new order, without a fatal, when the WooPay draft no longer exists.
	 *
	 * Client 11.1.0 calls set_status() on the wc_get_order() result unchecked (class-wc-payments-woopay-direct-checkout.php:71-72).
	 */
	public function test_classic_checkout_places_a_new_order_when_the_woopay_draft_is_gone(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->save();
		$draft_id = $draft->get_id();
		$draft->delete( true );
		WC()->session->set( 'store_api_draft_order', $draft_id );
		WC()->session->set( 'order_awaiting_payment', null );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order(
				array(
					'payment_method' => 'bacs',
					'billing_email'  => 'guest@example.com',
				)
			);
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
		}

		$this->assertIsInt( $order_id );
		$this->assertNotSame( $draft_id, $order_id );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
		$this->assertSame( $draft_id, WC()->session->get( 'store_api_draft_order' ), 'The session is left alone.' );
	}

	/**
	 * @testdox A classic checkout keeps the order another woocommerce_create_order callback supplied and leaves the WooPay draft alone.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-direct-checkout.php:60: only when no order ID is defined yet.
	 */
	public function test_classic_checkout_keeps_an_order_another_callback_supplied(): void {
		$sut = $this->create_service( array(), array( 'platform_direct_checkout_eligible' => true ) );
		$this->register_controller( $sut );
		WC()->cart->add_to_cart( \WC_Helper_Product::create_simple_product()->get_id(), 1 );
		WC()->cart->calculate_totals();
		$draft = new \WC_Order();
		$draft->set_status( OrderStatus::CHECKOUT_DRAFT );
		$draft->set_cart_hash( WC()->cart->get_cart_hash() );
		$draft->save();
		$other = wc_create_order();
		WC()->session->set( 'store_api_draft_order', $draft->get_id() );
		WC()->session->set( 'order_awaiting_payment', null );
		$supply_order = static fn() => $other->get_id();
		add_filter( 'woocommerce_create_order', $supply_order, 5 );
		Constants::set_constant( 'WOOCOMMERCE_CHECKOUT', true );

		try {
			$order_id = WC()->checkout()->create_order( array( 'payment_method' => 'bacs' ) );
		} finally {
			Constants::clear_single_constant( 'WOOCOMMERCE_CHECKOUT' );
			remove_filter( 'woocommerce_create_order', $supply_order, 5 );
		}

		$this->assertSame( $other->get_id(), $order_id );
		$this->assertSame( OrderStatus::CHECKOUT_DRAFT, wc_get_order( $draft->get_id() )->get_status() );
		$this->assertSame( $draft->get_id(), WC()->session->get( 'store_api_draft_order' ) );
	}

	/**
	 * @testdox With WooPay off, a WooPay request leaves the AutomateWoo advocate and the $0 subscription needs-payment value alone.
	 *
	 * Client 11.1.0 class-woopay-session.php:278-280 and :303-305 check that WooPay is enabled; both callbacks are registered
	 * on every request, so the check is what keeps them out.
	 */
	public function test_woopay_order_filters_pass_through_with_woopay_off(): void {
		$sut = $this->create_service( array( 'platform_checkout' => 'no' ) );
		$this->register_controller( $sut );
		$this->woopay_eligibility->cart_contains_subscription = true;
		$free_order = new \WC_Order();
		$free_order->set_total( 0 );

		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$this->simulate_woopay_store_api_request();
		$_GET['automatewoo_referral_id'] = '42';
		try {
			$this->assertFalse( $this->woopay_eligibility->is_woopay_enabled() );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- AutomateWoo Refer a Friend filter; its argument is the advocate ID it found.
			$this->assertSame( 7, apply_filters( 'automatewoo/referrals/referred_order_advocate', 7 ) );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Core filter WC_Order::needs_payment() applies.
			$this->assertFalse( apply_filters( 'woocommerce_order_needs_payment', false, $free_order, array() ) );
		} finally {
			unset( $_GET['automatewoo_referral_id'] );
			$_SERVER['REQUEST_URI'] = $request_uri;
		}
	}

	/**
	 * @testdox The WooPay session refresh keeps the browser's cached session when the request did not save through the Store API: $label.
	 *
	 * Client 11.1.0 swaps its handler only for WooPay Store API requests (class-woopay-session.php:79-91), and only that
	 * handler writes the browser's cache entry (class-woopay-store-api-session-handler.php:108-125).
	 *
	 * @testWith ["a WooPay Store API request on the browser's own session handler", "/wp-json/wc/store/v1/checkout", false]
	 *           ["a WooPay request to a route outside the Store API", "/wp-json/wc/v3/orders", true]
	 *
	 * @param string $label                Case label.
	 * @param string $request_uri          Request URI WooPay calls.
	 * @param bool   $store_api_session    Whether the request runs on core's Store API session handler.
	 */
	public function test_woopay_session_refresh_keeps_the_browser_cache_outside_its_case( string $label, string $request_uri, bool $store_api_session ): void {
		unset( $label );
		$browser = new \WC_Session_Handler();
		$sut     = $this->create_service();
		$this->register_controller( $sut );
		$original_uri               = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Saved to restore it unchanged.
		$_SERVER['HTTP_USER_AGENT'] = 'WooPay';
		$_SERVER['REQUEST_URI']     = $request_uri;
		$original_session           = WC()->session;
		if ( $store_api_session ) {
			$customer_id                = 't_' . wp_generate_password( 30, false );
			$_SERVER['HTTP_CART_TOKEN'] = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( $customer_id );
			WC()->session               = new \Automattic\WooCommerce\StoreApi\SessionHandler();
			WC()->session->init();
		} else {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
			$customer_id = (string) WC()->session->get_customer_id();
		}
		// The browser's handler cached the session; nothing in the database backs it.
		wp_cache_set( \WC_Cache_Helper::get_cache_prefix( WC_SESSION_CACHE_GROUP ) . $customer_id, array( 'coupon' => 'cached-by-the-browser' ), WC_SESSION_CACHE_GROUP );

		try {
			$this->store_api_request_handler->refresh_woopay_browser_session_cache();
		} finally {
			remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
			WC()->session           = $original_session;
			$_SERVER['REQUEST_URI'] = $original_uri;
			unset( $_SERVER['HTTP_CART_TOKEN'] );
		}

		$this->assertSame( array( 'coupon' => 'cached-by-the-browser' ), $browser->get_session( $customer_id ) );
	}

	/**
	 * Verified emails that must not log the WooPay request in.
	 *
	 * @return array<string,array{0:string,1:string,2:string[],3:bool}>
	 */
	public function unmatched_verified_email_provider(): array {
		return array(
			'the header names another account than the session' => array( 'shopper-a@example.com', 'shopper-b@example.com', array( 'shopper-a@example.com', 'shopper-b@example.com' ), true ),
			'no account owns the header email' => array( 'guest-shopper@example.com', 'guest-shopper@example.com', array( 'shopper-a@example.com' ), true ),
			'no adapted extension is active'   => array( 'verified-shopper@example.com', 'verified-shopper@example.com', array( 'verified-shopper@example.com' ), false ),
		);
	}
}
