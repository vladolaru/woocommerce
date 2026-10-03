<?php

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\NotFoundException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Applepay\ApplePayGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Googlepay\GooglePayGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\CardButtonGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\CreditCardGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BancontactGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BlikGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\EPSGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\IDealGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MultibancoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MyBankGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\P24Gateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\TrustlyGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\OXXOGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceGateway;

/**
 * Defines dependency relationships between payment methods and settings.
 */
class PaymentMethodsDependenciesDefinition {

	/**
	 * Get payment method to payment method dependencies
	 *
	 * Maps dependent method ID => array of parent method IDs.
	 * A dependent method is disabled if ANY of its required parents is disabled.
	 *
	 * @return array The dependency relationships between payment methods
	 */
	public function get_payment_method_dependencies(): array {
		$dependencies = array(
			CardButtonGateway::ID     => array( PayPalGateway::ID ),
			CreditCardGateway::ID     => array( PayPalGateway::ID ),
			AxoGateway::ID            => array( PayPalGateway::ID, CreditCardGateway::ID ),
			ApplePayGateway::ID       => array( PayPalGateway::ID ),
			GooglePayGateway::ID      => array( PayPalGateway::ID ),
			BancontactGateway::ID     => array( PayPalGateway::ID ),
			BlikGateway::ID           => array( PayPalGateway::ID ),
			EPSGateway::ID            => array( PayPalGateway::ID ),
			IDealGateway::ID          => array( PayPalGateway::ID ),
			MultibancoGateway::ID     => array( PayPalGateway::ID ),
			MyBankGateway::ID         => array( PayPalGateway::ID ),
			P24Gateway::ID            => array( PayPalGateway::ID ),
			TrustlyGateway::ID        => array( PayPalGateway::ID ),
			PayUponInvoiceGateway::ID => array( PayPalGateway::ID ),
			OXXOGateway::ID           => array( PayPalGateway::ID ),
			PWCGateway::ID            => array( PayPalGateway::ID ),
			'venmo'                   => array( PayPalGateway::ID ),
			'pay-later'               => array( PayPalGateway::ID ),
		);

		return apply_filters(
			'woocommerce_paypal_payments_payment_method_dependencies',
			$dependencies
		);
	}

	/**
	 * Get setting to payment method dependencies.
	 *
	 * Maps method ID => array of required settings with their values.
	 * A method is disabled if ANY of its required settings doesn't match the required value.
	 *
	 * @return array The dependency relationships between settings and payment methods
	 */
	public function get_setting_dependencies(): array {
		return apply_filters(
			'woocommerce_paypal_payments_setting_dependencies',
			array()
		);
	}

	/**
	 * Get payment method value dependencies for a specific method
	 *
	 * @return array Value dependencies for the method or empty array if none exist
	 */
	public function get_payment_method_value_dependencies(): array {
		$dependencies = array(
			CardButtonGateway::ID => array(
				CreditCardGateway::ID => false,
			),
			CreditCardGateway::ID => array(
				CardButtonGateway::ID => false,
			),
		);

		return apply_filters(
			'woocommerce_paypal_payments_method_value_dependencies',
			$dependencies
		);
	}

	/**
	 * Get method setting dependencies
	 *
	 * Returns the setting dependencies for a specific method ID.
	 *
	 * @param string $method_id Method ID to check.
	 * @return array Setting dependencies for the method or empty array if none exist
	 */
	public function get_method_setting_dependencies( string $method_id ): array {
		$setting_dependencies = $this->get_setting_dependencies();
		return $setting_dependencies[ $method_id ] ?? array();
	}

	/**
	 * Add dependency information to the payment method definitions
	 *
	 * @param array $methods Payment method definitions.
	 * @return array Payment method definitions with dependency information
	 */
	public function add_dependency_info_to_methods( array $methods ): array {
		foreach ( $methods as $method_id => &$method ) {
			// Skip the __meta key.
			if ( $method_id === '__meta' ) {
				continue;
			}

			// Add payment method dependency info if applicable.
			$payment_method_dependencies = $this->get_method_payment_method_dependencies( $method_id );
			if ( ! empty( $payment_method_dependencies ) ) {
				$method['depends_on_payment_methods'] = $payment_method_dependencies;
			}

			// Add payment method value dependency info if applicable.
			$all_payment_method_value_dependencies = $this->get_payment_method_value_dependencies();

			// Only add dependencies that directly apply to this method.
			if ( isset( $all_payment_method_value_dependencies[ $method_id ] ) ) {
				// Direct dependencies on other method values.
				$method['depends_on_payment_methods_values'] = $all_payment_method_value_dependencies[ $method_id ];
			}

			// Check if this method has setting dependencies.
			$method_setting_dependencies = $this->get_method_setting_dependencies( $method_id );
			if ( ! empty( $method_setting_dependencies ) ) {
				$settings = array();
				foreach ( $method_setting_dependencies as $setting_id => $required_value ) {
					$settings[ $setting_id ] = array(
						'id'    => $setting_id,
						'value' => $required_value,
					);
				}

				$method['depends_on_settings'] = array(
					'settings' => $settings,
				);
			}
		}

		// Add global metadata about settings that affect dependencies.
		if ( ! isset( $methods['__meta'] ) ) {
			$methods['__meta'] = array();
		}

		$methods['__meta']['settings_affecting_methods'] = $this->get_all_dependent_settings();

		return $methods;
	}

	/**
	 * Get payment method dependencies for a specific method
	 *
	 * @param string $method_id Method ID to check.
	 * @return array Array of parent method IDs
	 */
	public function get_method_payment_method_dependencies( string $method_id ): array {
		return $this->get_payment_method_dependencies()[ $method_id ] ?? array();
	}

	/**
	 * Get all settings that affect payment methods
	 *
	 * @return array Array of unique setting keys that affect payment methods
	 */
	public function get_all_dependent_settings(): array {
		$settings     = array();
		$dependencies = $this->get_setting_dependencies();

		foreach ( $dependencies as $method_settings ) {
			if ( isset( $method_settings['settings'] ) ) {
				foreach ( $method_settings['settings'] as $setting_data ) {
					if ( ! in_array( $setting_data['id'], $settings, true ) ) {
						$settings[] = $setting_data['id'];
					}
				}
			}
		}

		return $settings;
	}
}
