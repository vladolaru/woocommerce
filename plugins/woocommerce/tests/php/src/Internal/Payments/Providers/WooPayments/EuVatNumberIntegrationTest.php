<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\EuVatNumberIntegration;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\EuVatNumber;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use WC_Unit_Test_Case;

/**
 * Plugin versions expose different classes for the lifetime of a request.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EuVatNumberIntegrationTest extends WC_Unit_Test_Case {
	/**
	 * The legacy integration suppresses only WooPay, and only when both options are on.
	 *
	 * @testWith ["legacy opt-in", false, true, "yes", "yes", false, false, true]
	 *           ["legacy default", false, true, "yes", null, false, false, false]
	 *           ["modern default", true, true, "yes", null, false, true, true]
	 *           ["inactive dependencies", true, false, "yes", null, false, false, false]
	 *           ["B2B off", true, true, "no", null, false, false, false]
	 *           ["prevention off", true, true, "yes", "no", false, false, false]
	 *           ["admin AJAX", true, true, "yes", null, true, false, false]
	 *           ["plugin filter opts out", true, true, "yes", "filtered", false, false, false]
	 *
	 * @param string      $configuration           Plugin configuration.
	 * @param bool        $modern         Whether the plugin has the gateway API.
	 * @param bool        $ready          Whether the plugin's dependencies loaded.
	 * @param string      $b2b            B2B option.
	 * @param string|null $prevent        Prevention option or filter override.
	 * @param bool        $admin          Whether this is an admin request.
	 * @param bool        $hide_express   Expected express restriction.
	 * @param bool        $hide_woopay    Expected WooPay restriction.
	 */
	public function test_eu_vat_shopper_choices( string $configuration, bool $modern, bool $ready, string $b2b, ?string $prevent, bool $admin, bool $hide_express, bool $hide_woopay ): void {
		EuVatNumber::load( $modern, $ready );
		update_option( 'woocommerce_eu_vat_number_b2b', $b2b );
		if ( 'filtered' === $prevent ) {
			add_filter( 'wc_eu_vat_prevent_incompatible_payment_methods', '__return_false' );
		} elseif ( null !== $prevent ) {
			update_option( 'woocommerce_eu_vat_number_prevent_incompatible_payment_methods', $prevent );
		}
		if ( $admin ) {
			set_current_screen( 'dashboard' );
		}
		update_option( '_wcpay_feature_dynamic_checkout_place_order_button', '1' );
		update_option( 'woocommerce_woocommerce_payments_settings', EuVatNumber::wallet_settings() );
		$client = $this->createMock( Client::class );
		$client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $client );
		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		$account->cache_account_data(
			array(
				'account_id'                 => 'acct_wallet',
				'is_live'                    => true,
				'platform_checkout_eligible' => true,
			)
		);
		$this->assertSame( ! $hide_woopay, wc_get_container()->get( Eligibility::class )->is_woopay_enabled(), $configuration );
		$this->assertSame( ! $hide_express, WooPaymentsExpressPaymentMethodTypes::is_express_checkout_in_payment_methods_enabled( $account ), $configuration );
		$this->assertSame( $hide_express ? array() : EuVatNumber::wallet_settings()['express_checkout_cart_methods'], WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, 'cart' ), $configuration );
	}

	/**
	 * Each request consults EU VAT once. Turning B2B off restores the saved choices on the next request.
	 */
	public function test_eu_vat_next_request_restores_stored_choices(): void {
		EuVatNumber::load();
		$choices = EuVatNumber::wallet_settings();
		update_option( 'woocommerce_woocommerce_payments_settings', $choices );
		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		$calls   = 0;
		add_filter(
			'wc_eu_vat_prevent_incompatible_payment_methods',
			static function ( $prevent ) use ( &$calls, $account ) {
				++$calls;
				return $prevent && 1 === $calls && in_array( 'woopay', WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, 'cart' ), true );
			}
		);
		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$this->assertSame( array(), WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, $location ) );
		}
		$this->assertSame( 1, $calls );
		update_option( 'woocommerce_eu_vat_number_b2b', 'no' );
		wc_get_container()->reset_all_resolved();
		foreach ( array( 'product', 'cart', 'checkout' ) as $location ) {
			$this->assertSame( $choices[ 'express_checkout_' . $location . '_methods' ], WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, $location ) );
		}
	}

	/**
	 * A gateway read inside EU VAT's own decision sees the configured wallets and cannot fix the later answer.
	 *
	 * @testWith [true]
	 *           [false]
	 * @param bool $prevent Final decision after inspecting the location.
	 */
	public function test_plugin_initiated_decision_reads_configured_wallets( bool $prevent ): void {
		EuVatNumber::load();
		$choices = EuVatNumber::wallet_settings();
		update_option( 'woocommerce_woocommerce_payments_settings', $choices );
		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		$calls   = 0;
		add_filter(
			'wc_eu_vat_prevent_incompatible_payment_methods',
			function () use ( $account, $choices, $prevent, &$calls ) {
				++$calls;
				$this->assertSame( $choices['express_checkout_cart_methods'], WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, 'cart' ) );
				return $prevent;
			}
		);

		$this->assertSame( $prevent, EuVatNumber::should_prevent_incompatible_payment_methods() );
		$this->assertSame( 1, $calls );
		$this->assertSame( $prevent ? array() : $choices['express_checkout_cart_methods'], WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, 'cart' ) );
	}

	/**
	 * The request keeps its wallet restriction when switching to a site with different settings.
	 *
	 * @group multisite
	 */
	public function test_switched_site_keeps_request_restriction(): void {
		$this->skipWithoutMultisite();
		EuVatNumber::load();
		$integration = wc_get_container()->get( EuVatNumberIntegration::class );
		$this->assertTrue( $integration->suppresses_express() );

		$site = self::factory()->blog->create();
		$this->assertIsInt( $site );
		switch_to_blog( $site );
		try {
			$this->assertTrue( $integration->suppresses_express() );
		} finally {
			restore_current_blog();
		}
		$this->assertTrue( $integration->suppresses_express() );
	}

	/**
	 * The integration registers only the supported-gateway answer, and only on a set-up built-in store.
	 *
	 * @testWith ["connected", true]
	 *           ["active", true]
	 *           ["available", false]
	 *           ["disabled", false]
	 *           ["active", false, false]
	 *
	 * @param string $tier     Effective tier, including available for extension ownership.
	 * @param bool   $expected Whether EU VAT should show its admin setting.
	 * @param bool   $plugin_loaded Whether EU VAT is active.
	 */
	public function test_eu_vat_advertises_support_without_filtering_the_settings( string $tier, bool $expected, bool $plugin_loaded = true ): void {
		if ( $plugin_loaded ) {
			EuVatNumber::load();
		}
		$roots = WooPaymentsProvider::get_classes_by_setup_tier()[ $tier ]['admin'] ?? array();
		if ( in_array( EuVatNumberIntegration::class, $roots, true ) ) {
			wc_get_container()->get( EuVatNumberIntegration::class )->register();
		}
		$this->assertSame( $expected, apply_filters( 'wc_eu_vat_has_supported_express_payment_gateway', false ) );
		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		WooPaymentsExpressPaymentMethodTypes::get_configured_methods_for_context( $account, 'cart' );
		$this->assertFalse( has_filter( 'option_woocommerce_woocommerce_payments_settings' ) );
		$this->assertFalse( has_filter( 'pre_update_option_woocommerce_woocommerce_payments_settings' ) );
	}
}
