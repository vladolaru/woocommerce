<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use WC_Unit_Test_Case;

/**
 * Pins that a store the WooPayments extension owns, with the WooPayments feature on, reads the `available` tier in every
 * request type the payments bootstrap tells apart, without a tier write: the extension's account cache writes change nothing.
 */
class WooPaymentsExtensionOwnedSetupTierSyncTest extends WC_Unit_Test_Case {

	/**
	 * Drop the services resolved while the extension owned the store.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_all_resolved();
		parent::tearDown();
	}

	/**
	 * @testdox On a store the WooPayments extension owns, with the WooPayments feature on and the stored setup tier disabled, a $request_type request reads the available tier, and an account cache write leaves the stored tier.
	 * @dataProvider request_types
	 *
	 * @param string $request_type Request type: front, admin, ajax, rest, cron or cli.
	 */
	public function test_extension_owned_store_reads_available_without_a_tier_write( string $request_type ): void {
		update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'woocommerce_woopayments_setup_tier', 'disabled' );
		delete_option( 'wcpay_account_data' );
		remove_all_actions( 'add_option_wcpay_account_data' );
		remove_all_actions( 'update_option_wcpay_account_data' );
		wc_get_container()->reset_all_resolved();

		$this->register_payments_bootstrap_as( $request_type );

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, wc_get_container()->get( WooPaymentsSetupTier::class )->get_effective_tier() );

		update_option( 'wcpay_account_data', $this->account_cache( 'acct_first_write' ) );

		$this->assertSame( 'disabled', get_option( 'woocommerce_woopayments_setup_tier' ), 'No listener writes the stored tier.' );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, wc_get_container()->get( WooPaymentsSetupTier::class )->get_effective_tier() );
	}

	/**
	 * Request types the payments bootstrap tells apart.
	 *
	 * @return array<string,array{string}>
	 */
	public static function request_types(): array {
		return array(
			'front' => array( 'front' ),
			'admin' => array( 'admin' ),
			'ajax'  => array( 'ajax' ),
			'rest'  => array( 'rest' ),
			'cron'  => array( 'cron' ),
			'cli'   => array( 'cli' ),
		);
	}

	/**
	 * Build and register the payments bootstrap exactly as `WooCommerce::init_hooks()` does, in one request type.
	 *
	 * @param string $request_type Request type: front, admin, ajax, rest, cron or cli.
	 */
	private function register_payments_bootstrap_as( string $request_type ): void {
		$filter = array(
			'ajax' => 'wp_doing_ajax',
			'cron' => 'wp_doing_cron',
		)[ $request_type ] ?? null;
		if ( null !== $filter ) {
			add_filter( $filter, '__return_true' );
		}
		if ( 'cli' === $request_type ) {
			Constants::set_constant( 'WP_CLI', true );
		}
		if ( 'admin' === $request_type ) {
			set_current_screen( 'edit-page' );
		}

		$container = wc_get_container();
		try {
			( new \Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap(
				static fn( $container, string $request_type ): array => $container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
				static fn( $container ): bool => $container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter::class )->is_builtin_owner(),
				static fn(): array => \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::get_multi_currency_provider_roots()
			) )->register(
				$container,
				fn(): bool => 'rest' === $request_type
			);
			// Canary-only comparison of the built-in WooPayments with the WooPayments extension, on stores the extension owns.
			if ( $container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter::class )->is_extension_owner() ) {
				\Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Shadow\NativePaymentsShadowMode::register_when_enabled( $container );
			}
		} finally {
			if ( null !== $filter ) {
				remove_filter( $filter, '__return_true' );
			}
			Constants::clear_single_constant( 'WP_CLI' );
			set_current_screen( 'front' );
		}
	}

	/**
	 * An account cache the WooPayments extension writes.
	 *
	 * The subset of the cache client 11.1.0 writes that the test needs: the envelope's `data`, `fetched` and `errored` (`includes/class-database-cache.php:377-382`), and the account's `account_id` and `country` (read at `includes/class-wc-payments-account.php:171,2733`).
	 *
	 * @param string $account_id Account ID.
	 * @return array<string,mixed>
	 */
	private function account_cache( string $account_id ): array {
		return array(
			'data'    => array(
				'account_id' => $account_id,
				'country'    => 'US',
			),
			'fetched' => time(),
			'errored' => false,
		);
	}
}
