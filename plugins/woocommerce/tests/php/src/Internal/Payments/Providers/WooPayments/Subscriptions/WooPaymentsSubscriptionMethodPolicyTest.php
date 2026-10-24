<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments subscription payment-method policy.
 */
class WooPaymentsSubscriptionMethodPolicyTest extends WC_Unit_Test_Case {

	/**
	 * Clear the cart state the tests set.
	 */
	public function tearDown(): void {
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ], $GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] );
		parent::tearDown();
	}

	/**
	 * @testdox Should expose the reusable gateway IDs supported by WooPayments 10.8 subscriptions.
	 */
	public function test_get_reusable_gateway_ids_matches_woopayments_contract(): void {
		$this->assertSame(
			array(
				WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay',
			),
			WooPaymentsSubscriptionMethodPolicy::get_reusable_gateway_ids()
		);
	}

	/**
	 * @testdox Should distinguish reusable subscription methods from non-reusable split gateways.
	 * @dataProvider provider_reusable_gateway_ids
	 *
	 * @param string $gateway_id  Gateway ID.
	 * @param bool   $is_reusable Whether the gateway is reusable.
	 */
	public function test_is_reusable_gateway_id_classifies_subscription_methods( string $gateway_id, bool $is_reusable ): void {
		$this->assertSame( $is_reusable, WooPaymentsSubscriptionMethodPolicy::is_reusable_gateway_id( $gateway_id ) );
	}

	/**
	 * @testdox With $label, the cart counts as a subscription cart: $expected; subscriptions support is available: $available.
	 *
	 * Like the extension: a renewal-only cart counts, and nothing counts while WooCommerce Subscriptions' cart class is not
	 * loaded or subscriptions support is not available (client 11.1.0 `trait-wc-payments-subscriptions-utilities.php:97`).
	 *
	 * @dataProvider provider_subscription_carts
	 *
	 * @param string   $label                Case label.
	 * @param string[] $loaded               Classes reported as loaded.
	 * @param string[] $hidden               Classes reported as not loaded.
	 * @param bool     $subscription_in_cart Whether the cart holds a subscription.
	 * @param bool     $renewal_in_cart      Whether the cart holds a renewal.
	 * @param bool     $available            Whether subscriptions support is available.
	 * @param bool     $expected             Whether the cart counts as a subscription cart.
	 */
	public function test_cart_contains_subscription_or_renewal( string $label, array $loaded, array $hidden, bool $subscription_in_cart, bool $renewal_in_cart, bool $available, bool $expected ): void {
		unset( $label );
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => in_array( $class_name, $loaded, true )
					|| ( ! in_array( $class_name, $hidden, true ) && class_exists( $class_name, ...$args ) ),
			)
		);
		WooCommerceSubscriptionsDoubles::load_cart();
		if ( $subscription_in_cart ) {
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_SUBSCRIPTION ] = true;
		}
		if ( $renewal_in_cart ) {
			$GLOBALS[ WooCommerceSubscriptionsDoubles::CART_CONTAINS_RENEWAL ] = true;
		}

		$this->assertSame( $available, WooPaymentsSubscriptionMethodPolicy::is_subscriptions_available() );
		$this->assertSame( $expected, WooPaymentsSubscriptionMethodPolicy::cart_contains_subscription_or_renewal() );
	}

	/**
	 * Carts and the WooCommerce Subscriptions classes the policy finds.
	 *
	 * @return array<string,array{0:string,1:string[],2:string[],3:bool,4:bool,5:bool,6:bool}>
	 */
	public function provider_subscription_carts(): array {
		return array(
			'renewal-only cart'                   => array( 'a renewal-only cart', array( 'WC_Subscriptions_Core_Plugin' ), array(), false, true, true, true ),
			'neither'                             => array( 'neither a subscription nor a renewal in the cart', array( 'WC_Subscriptions_Core_Plugin' ), array(), false, false, true, false ),
			'cart class not loaded'               => array( "WooCommerce Subscriptions' cart class not loaded", array( 'WC_Subscriptions_Core_Plugin' ), array( 'WC_Subscriptions_Cart' ), true, true, true, false ),
			'subscriptions support not available' => array( 'subscriptions support not available', array(), array(), true, true, false, false ),
		);
	}

	/**
	 * Reusable gateway ID scenarios.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provider_reusable_gateway_ids(): array {
		return array(
			'base card gateway'          => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, true ),
			'Amazon Pay gateway'         => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'amazon_pay', true ),
			'non-reusable split gateway' => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . 'bancontact', false ),
			'unrelated gateway'          => array( 'other_gateway', false ),
		);
	}
}
