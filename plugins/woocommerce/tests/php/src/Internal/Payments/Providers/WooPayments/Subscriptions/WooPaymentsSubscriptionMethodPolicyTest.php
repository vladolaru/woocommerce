<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments subscription payment-method policy.
 */
class WooPaymentsSubscriptionMethodPolicyTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should expose the reusable gateway IDs supported by WooPayments 10.8 subscriptions.
	 */
	public function test_get_reusable_gateway_ids_matches_woopayments_contract(): void {
		if ( ! class_exists( WooPaymentsSubscriptionMethodPolicy::class ) ) {
			$this->fail( 'The shared WooPayments subscription method policy does not exist.' );
		}

		$this->assertSame(
			array(
				OrderPaymentStore::GATEWAY_ID,
				OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay',
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
		if ( ! class_exists( WooPaymentsSubscriptionMethodPolicy::class ) ) {
			$this->fail( 'The shared WooPayments subscription method policy does not exist.' );
		}

		$this->assertSame( $is_reusable, WooPaymentsSubscriptionMethodPolicy::is_reusable_gateway_id( $gateway_id ) );
	}

	/**
	 * @testdox Should treat a renewal-only cart as a subscription cart, like the extension.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cart_contains_subscription_or_renewal_includes_renewal_carts(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public cart contract.
		eval( 'namespace { class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return false; } } }' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public renewal detector.
		eval( 'namespace { function wcs_cart_contains_renewal() { return true; } }' );

		$this->assertTrue( WooPaymentsSubscriptionMethodPolicy::cart_contains_subscription_or_renewal() );
	}

	/**
	 * @testdox Should report no subscription cart when neither a subscription nor a renewal is present.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cart_contains_subscription_or_renewal_false_without_either(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public cart contract.
		eval( 'namespace { class WC_Subscriptions_Cart { public static function cart_contains_subscription() { return false; } } }' );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce Subscriptions is optional; this isolated test needs its public renewal detector.
		eval( 'namespace { function wcs_cart_contains_renewal() { return false; } }' );

		$this->assertFalse( WooPaymentsSubscriptionMethodPolicy::cart_contains_subscription_or_renewal() );
	}

	/**
	 * Reusable gateway ID scenarios.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provider_reusable_gateway_ids(): array {
		return array(
			'base card gateway'          => array( OrderPaymentStore::GATEWAY_ID, true ),
			'Amazon Pay gateway'         => array( OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay', true ),
			'non-reusable split gateway' => array( OrderPaymentStore::GATEWAY_ID_PREFIX . 'bancontact', false ),
			'unrelated gateway'          => array( 'other_gateway', false ),
		);
	}

	/**
	 * @testdox Should recognize native WooPayments split gateways without matching unrelated prefixes.
	 * @dataProvider provider_native_gateway_ids
	 *
	 * @param string $gateway_id Gateway ID.
	 * @param bool   $is_native  Whether the gateway belongs to native WooPayments.
	 */
	public function test_is_native_gateway_id_classifies_woopayments_methods( string $gateway_id, bool $is_native ): void {
		if ( ! class_exists( WooPaymentsSubscriptionMethodPolicy::class ) ) {
			$this->fail( 'The shared WooPayments subscription method policy does not exist.' );
		}

		$this->assertSame( $is_native, WooPaymentsSubscriptionMethodPolicy::is_native_gateway_id( $gateway_id ) );
	}

	/**
	 * Native gateway ID scenarios.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provider_native_gateway_ids(): array {
		return array(
			'base card gateway'  => array( OrderPaymentStore::GATEWAY_ID, true ),
			'Amazon Pay gateway' => array( OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay', true ),
			'split gateway'      => array( OrderPaymentStore::GATEWAY_ID_PREFIX . 'ideal', true ),
			'unrelated prefix'   => array( OrderPaymentStore::GATEWAY_ID . 'ish', false ),
			'unrelated gateway'  => array( 'other_gateway', false ),
		);
	}
}
