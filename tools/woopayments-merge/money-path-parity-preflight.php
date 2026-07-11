<?php
/**
 * Read-only readiness probe for the refund/manual-capture parity gate.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

( static function ( array $arguments ) {
	$role   = isset( $arguments[0] ) ? (string) $arguments[0] : '';
	$sku    = isset( $arguments[1] ) ? trim( (string) $arguments[1] ) : 'test-lab-beaker-001';
	$errors = array();

	if ( ! in_array( $role, array( 'reference', 'target' ), true ) ) {
		$errors[] = 'Role must be reference or target.';
	}
	if ( '' === $sku ) {
		$errors[] = 'Deterministic product SKU must not be empty.';
	}

	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_file         = 'woocommerce-payments/woocommerce-payments.php';
	$wcpay_plugin_active = function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
	$gateway             = null;
	$gateway_class       = '';
	$gateway_id          = '';
	$test_mode           = false;
	$account_ready       = false;
	$runtime_owner       = 'unknown';
	$site_url            = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';

	if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
		$errors[] = 'WooCommerce payment gateways are unavailable.';
	} else {
		$gateways      = WC()->payment_gateways()->payment_gateways();
		$gateway       = $gateways['woocommerce_payments'] ?? null;
		$gateway_class = is_object( $gateway ) ? get_class( $gateway ) : '';
		$gateway_id    = is_object( $gateway ) && isset( $gateway->id ) ? (string) $gateway->id : '';
		if ( ! is_object( $gateway ) ) {
			$errors[] = 'WooPayments gateway woocommerce_payments is not registered.';
		} elseif ( ! is_callable( array( $gateway, 'process_payment' ) ) || ! is_callable( array( $gateway, 'process_refund' ) ) ) {
			$errors[] = 'WooPayments gateway does not expose the required charge/refund methods.';
		}
	}

	try {
		if ( 'reference' === $role ) {
			if ( ! $wcpay_plugin_active ) {
				$errors[] = 'Reference store must have the standalone WooPayments plugin active.';
			}
			if ( ! class_exists( 'WC_Payments' ) || ! method_exists( 'WC_Payments', 'get_account_service' ) || ! method_exists( 'WC_Payments', 'mode' ) ) {
				$errors[] = 'Reference WooPayments account/mode services are unavailable.';
			} else {
				$account_service = WC_Payments::get_account_service();
				$mode            = WC_Payments::mode();
				$account_ready   = is_object( $account_service ) && method_exists( $account_service, 'is_stripe_connected' ) && $account_service->is_stripe_connected();
				$test_mode       = is_object( $mode ) && method_exists( $mode, 'is_test' ) && $mode->is_test();
			}
			if ( ! is_object( $gateway ) || ! is_a( $gateway, 'WC_Payment_Gateway_WCPay' ) ) {
				$errors[] = 'Reference WooPayments gateway is not owned by the standalone plugin.';
			} else {
				$runtime_owner = 'plugin';
			}
		} elseif ( 'target' === $role ) {
			if ( $wcpay_plugin_active ) {
				$errors[] = 'Target store must keep the standalone WooPayments plugin inactive.';
			}

			$arbiter_class = 'Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter';
			$account_class = 'Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAccountService';
			if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $arbiter_class ) || ! class_exists( $account_class ) ) {
				$errors[] = 'Target native WooPayments runtime services are unavailable.';
			} else {
				$container       = wc_get_container();
				$arbiter         = $container->get( $arbiter_class );
				$account_service = $container->get( $account_class );
				$runtime_owner   = is_object( $arbiter ) && method_exists( $arbiter, 'get_runtime_owner' ) ? (string) $arbiter->get_runtime_owner() : 'unknown';
				$account_ready   = is_object( $account_service ) && method_exists( $account_service, 'can_process_payments' ) && $account_service->can_process_payments();
				$test_mode       = is_object( $account_service ) && method_exists( $account_service, 'is_test_mode_enabled' ) && $account_service->is_test_mode_enabled();
				if ( 'native' !== $runtime_owner ) {
					$errors[] = 'Target native runtime does not own payments.';
				}
			}
			$native_gateway_class = 'Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway';
			if ( ! is_object( $gateway ) || ! is_a( $gateway, $native_gateway_class ) ) {
				$errors[] = 'Target WooPayments gateway is not owned by the native runtime.';
			}
		}
	} catch ( Throwable $throwable ) {
		$errors[] = 'WooPayments readiness probe threw ' . get_class( $throwable ) . ': ' . $throwable->getMessage();
	}

	if ( ! $account_ready ) {
		$errors[] = 'WooPayments account is not ready to process payments.';
	}
	if ( ! $test_mode ) {
		$errors[] = 'WooPayments is not in test mode; refusing to drive money-path evidence.';
	}
	$deterministic_product_ready = '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) && (bool) wc_get_product_id_by_sku( $sku );
	if ( ! $deterministic_product_ready ) {
		$errors[] = sprintf( 'Deterministic product SKU %s is unavailable.', $sku );
	}

	WP_CLI::line(
		wp_json_encode(
			array(
				'success'                     => empty( $errors ),
				'mode'                        => 'preflight',
				'role'                        => $role,
				'errors'                      => $errors,
				'runtime_owner'               => $runtime_owner,
				'wcpay_plugin_active'         => $wcpay_plugin_active,
				'gateway_id'                  => $gateway_id,
				'gateway_class'               => $gateway_class,
				'account_ready'               => $account_ready,
				'test_mode'                   => $test_mode,
				'deterministic_product_sku'   => $sku,
				'deterministic_product_ready' => $deterministic_product_ready,
				'site_url'                    => $site_url,
			)
		)
	);
} )( $args );
