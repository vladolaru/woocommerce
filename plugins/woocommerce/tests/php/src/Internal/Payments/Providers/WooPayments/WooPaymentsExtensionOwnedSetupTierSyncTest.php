<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use WC_Unit_Test_Case;

/**
 * Pins how a store the WooPayments extension owns leaves the `disabled` setup tier: the extension writes its account
 * cache, and the listener the payments bootstrap registers for every request type re-derives the stored tier.
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
	 * @testdox On a store the WooPayments extension owns, with the built-in WooPayments enabled and the stored setup tier disabled, an eligible account cache write in a $request_type request stores the available tier.
	 * @dataProvider request_types
	 *
	 * @param string $request_type Request type: front, admin, ajax, rest, cron or cli.
	 */
	public function test_extension_account_cache_write_moves_a_disabled_store_to_available( string $request_type ): void {
		update_option( 'active_plugins', array( 'woocommerce-payments/woocommerce-payments.php' ) );
		update_option( 'woocommerce_woopayments_builtin_enabled', 'yes' );
		delete_option( 'woocommerce_woopayments_builtin_kill_switch' );
		update_option( 'woocommerce_woopayments_setup_tier', 'disabled' );
		delete_option( 'wcpay_account_data' );
		remove_all_actions( 'add_option_wcpay_account_data' );
		remove_all_actions( 'update_option_wcpay_account_data' );
		wc_get_container()->reset_all_resolved();

		$this->register_payments_bootstrap_as( $request_type );

		$this->assertSame( 'disabled', get_option( 'woocommerce_woopayments_setup_tier' ), 'Registering the bootstrap must not change the stored tier.' );

		update_option( 'wcpay_account_data', $this->eligible_account_cache( 'acct_first_write' ) );

		$this->assertSame( 'available', get_option( 'woocommerce_woopayments_setup_tier' ), 'The first account cache write must store the available tier.' );

		update_option( 'woocommerce_woopayments_setup_tier', 'disabled' );
		update_option( 'wcpay_account_data', $this->eligible_account_cache( 'acct_later_write' ) );

		$this->assertSame( 'available', get_option( 'woocommerce_woopayments_setup_tier' ), 'A later account cache write must store the available tier.' );
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
			( new \Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap(
				static fn( $container, string $request_type ): array => $container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
				static fn( $container ): bool => $container->get( \Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter::class )->should_native_register(),
				static fn(): array => \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::get_multi_currency_provider_roots()
			) )->register(
				$container,
				fn(): bool => 'rest' === $request_type
			);
			// Canary-only comparison of the built-in WooPayments with the WooPayments extension, on stores the extension owns.
			if ( $container->get( \Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter::class )->is_plugin_runtime_active() ) {
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
	 * An account cache the WooPayments extension writes for an account the built-in WooPayments can serve.
	 *
	 * The subset of the cache client 11.1.0 writes that the test needs: the envelope's `data`, `fetched` and `errored` (`includes/class-database-cache.php:377-382`), and the account's `account_id` and `country` (read at `includes/class-wc-payments-account.php:171,2733`).
	 *
	 * @param string $account_id Account ID.
	 * @return array<string,mixed>
	 */
	private function eligible_account_cache( string $account_id ): array {
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
