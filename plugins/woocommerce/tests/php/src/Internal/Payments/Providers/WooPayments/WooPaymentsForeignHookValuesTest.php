<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAddressProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerDataEraser;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenClassMapController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenizedCartSessionController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticWooPaymentsRuntimeArbiter;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * Tests that native WooPayments callbacks on shared checkout, gateway and site filters pass on a value another
 * plugin's callback left in the wrong shape, instead of failing with a TypeError.
 *
 * WordPress runs every callback on a filter with what the previous one returned, so a native callback cannot rely on
 * the type core documents; the client's callbacks on these filters take untyped parameters.
 */
class WooPaymentsForeignHookValuesTest extends WC_Unit_Test_Case {

	/**
	 * @testdox $hook passes on a foreign $_dataName value unchanged.
	 * @dataProvider foreign_filter_values
	 *
	 * @param string           $hook          Filter name.
	 * @param array<int,mixed> $extra_args    Arguments after the filtered value, as core passes them.
	 * @param mixed            $foreign_value Value an earlier callback returned.
	 */
	public function test_native_callback_passes_on_a_foreign_value( string $hook, array $extra_args, $foreign_value ): void {
		remove_all_filters( $hook );
		$this->register_native_callback( $hook );
		add_filter(
			$hook,
			static function () use ( $foreign_value ) {
				return $foreign_value;
			},
			0
		);

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the shared filter as its caller does.
		$filtered = apply_filters( $hook, null, ...$extra_args );

		$this->assertSame( $foreign_value, $filtered );
	}

	/**
	 * @testdox The token class callback passes on the class name when the token type an earlier caller passed is not a string.
	 *
	 * Reading the type map with an array or object key throws (review 36 F4), so the type is only looked up as a string.
	 */
	public function test_token_class_callback_passes_on_the_class_for_a_non_string_type(): void {
		remove_all_filters( 'woocommerce_payment_token_class' );
		$this->register_native_callback( 'woocommerce_payment_token_class' );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs the shared filter as its caller does.
		$filtered = apply_filters( 'woocommerce_payment_token_class', 'WC_Payment_Token_CC', array( 'CC' ) );

		$this->assertSame( 'WC_Payment_Token_CC', $filtered );
	}

	/**
	 * Shared filters with a native callback, each fed a null and a scalar where core documents another type.
	 *
	 * @return array<string,array{string,array<int,mixed>,mixed}>
	 */
	public function foreign_filter_values(): array {
		$hooks = array(
			'woocommerce_payment_token_class'         => array( 'CC' ),
			'woocommerce_get_customer_payment_tokens' => array( 1, 'woocommerce_payments' ),
			'woocommerce_payment_methods_list_item'   => array( new WC_Payment_Token_CC() ),
			'woocommerce_address_providers'           => array(),
			'woocommerce_session_handler'             => array(),
			'woocommerce_order_actions'               => array(),
			'wp_privacy_personal_data_erasers'        => array(),
			'user_has_cap'                            => array( array( 'toggle_shop_subscription_auto_renewal' ), array( 'toggle_shop_subscription_auto_renewal', 1, 5 ) ),
			'woocommerce_email_classes'               => array(),
		);

		$cases = array();
		foreach ( $hooks as $hook => $extra_args ) {
			$cases[ "$hook: null" ]   = array( $hook, $extra_args, null );
			$cases[ "$hook: scalar" ] = array( $hook, $extra_args, 'woocommerce_payment_token_class' === $hook ? 7 : 'unexpected' );
		}

		return $cases;
	}

	/**
	 * Attach the native callback for one filter, as its service registers it on a native-owned request.
	 *
	 * @param string $hook Filter name.
	 */
	private function register_native_callback( string $hook ): void {
		$arbiter   = new StaticWooPaymentsRuntimeArbiter( true );
		$container = wc_get_container();

		switch ( $hook ) {
			case 'woocommerce_payment_token_class':
				$controller = new WooPaymentsTokenClassMapController();
				$controller->init( $arbiter );
				$controller->register();
				break;
			case 'woocommerce_get_customer_payment_tokens':
			case 'woocommerce_payment_methods_list_item':
				( new WooPaymentsTokenService() )->init( $container->get( WooPaymentsPaymentMethodDetailsService::class ), $arbiter, $container->get( WooPaymentsApiClient::class ), $container->get( WooPaymentsCustomerService::class ), $container->get( WooPaymentsAccountService::class ), $container->get( WooPaymentsOrderDataService::class ) );
				break;
			case 'woocommerce_address_providers':
				$provider = new WooPaymentsAddressProvider();
				$provider->init( $arbiter, $container->get( WooPaymentsApiClient::class ), $container->get( WooPaymentsAccountService::class ) );
				$provider->register();
				break;
			case 'woocommerce_session_handler':
				$controller = new WooPaymentsTokenizedCartSessionController();
				$controller->init( $arbiter );
				$controller->register();
				break;
			case 'woocommerce_order_actions':
				$controller = new WooPaymentsOrderAdminActionsController();
				$controller->init( $arbiter, $container->get( PaymentProcessingService::class ), $container->get( WooPaymentsProvider::class ) );
				$controller->register();
				break;
			case 'wp_privacy_personal_data_erasers':
				// WooCommerce adds it on every request (includes/class-woocommerce.php), whatever the native payments tier.
				add_filter( 'wp_privacy_personal_data_erasers', array( WooPaymentsCustomerDataEraser::class, 'add_eraser' ) );
				break;
			case 'user_has_cap':
				wc_get_container()->get( WooPaymentsSubscriptionAdminPaymentMethodHandler::class )->register_hooks();
				break;
			case 'woocommerce_email_classes':
				// WooPaymentsSubscriptionsController attaches it at this priority when Subscriptions is active.
				add_filter( 'woocommerce_email_classes', array( WooPaymentsGateway::class, 'add_subscription_emails' ), 20 );
				break;
		}

		$this->assertNotFalse( has_filter( $hook ), "The native callback must be attached to $hook." );
	}
}
