<?php
/**
 * Handles migration of payment settings from legacy format to new structure.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\Migration;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\OXXOGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceGateway;

/**
 * Class PaymentSettingsMigration
 *
 * Handles migration of payment settings.
 */
class PaymentSettingsMigration implements SettingsMigrationInterface {

	/**
	 * @var array<string, mixed>
	 */
	protected array $settings;
	protected PaymentSettings $payment_settings;

	/**
	 * The list of local apm methods.
	 *
	 * @var array<string, array>
	 */
	protected array $local_apms;

	protected bool $legacy_pui_enabled;

	protected bool $legacy_oxxo_enabled;

	public function __construct(
		array $settings,
		PaymentSettings $payment_settings,
		array $local_apms
	) {
		$this->settings         = $settings;
		$this->payment_settings = $payment_settings;
		$this->local_apms       = $local_apms;

		$pui_option               = get_option( 'woocommerce_' . PayUponInvoiceGateway::ID . '_settings', array() );
		$this->legacy_pui_enabled = is_array( $pui_option ) && ( $pui_option['enabled'] ?? 'no' ) === 'yes';

		$oxxo_option               = get_option( 'woocommerce_' . OXXOGateway::ID . '_settings', array() );
		$this->legacy_oxxo_enabled = is_array( $oxxo_option ) && ( $oxxo_option['enabled'] ?? 'no' ) === 'yes';
	}

	public function migrate(): void {
		$disable_funding = (array) ( $this->settings['disable_funding'] ?? array() );
		if ( ! in_array( 'venmo', $disable_funding, true ) ) {
			$this->payment_settings->toggle_method_state( 'venmo', true );
		}

		foreach ( $this->local_apms as $apm ) {
			if ( ! in_array( $apm['id'], $disable_funding, true ) ) {
				$this->payment_settings->toggle_method_state( $apm['id'], true );
			}
		}

		foreach ( $this->map() as $old_key => $method_name ) {
			if ( ! empty( $this->settings[ $old_key ] ) ) {
				$this->payment_settings->toggle_method_state( $method_name, true );
			}
		}

		$pui_settings = get_option( 'woocommerce_ppcp-pay-upon-invoice-gateway_settings', array() );
		if ( is_array( $pui_settings ) ) {
			if ( ! empty( $pui_settings['brand_name'] ) ) {
				$this->payment_settings->set_pui_brand_name( $pui_settings['brand_name'] );
			}
			if ( ! empty( $pui_settings['logo_url'] ) ) {
				$this->payment_settings->set_pui_logo_url( $pui_settings['logo_url'] );
			}
			if ( ! empty( $pui_settings['customer_service_instructions'] ) ) {
				$this->payment_settings->set_pui_customer_service_instructions( $pui_settings['customer_service_instructions'] );
			}
		}

		if ( $this->legacy_pui_enabled ) {
			$this->payment_settings->toggle_method_state( PayUponInvoiceGateway::ID, true );
		}

		if ( $this->legacy_oxxo_enabled ) {
			$this->payment_settings->toggle_method_state( OXXOGateway::ID, true );
		}

		if ( isset( $this->settings['dcc_name_on_card'] ) ) {
			$this->payment_settings->set_cardholder_name( $this->settings['dcc_name_on_card'] === 'yes' );
		}

		if ( ! empty( $this->settings['title'] ) ) {
			$this->payment_settings->set_method_title( 'ppcp-gateway', $this->settings['title'] );
		}
		if ( ! empty( $this->settings['description'] ) ) {
			$this->payment_settings->set_method_description( 'ppcp-gateway', $this->settings['description'] );
		}
		if ( ! empty( $this->settings['dcc_gateway_title'] ) ) {
			$this->payment_settings->set_method_title( GatewayIds::CREDIT_CARD, $this->settings['dcc_gateway_title'] );
		}
		if ( ! empty( $this->settings['dcc_gateway_description'] ) ) {
			$this->payment_settings->set_method_description( GatewayIds::CREDIT_CARD, $this->settings['dcc_gateway_description'] );
		}

		$this->payment_settings->save();
	}

	/**
	 * Maps old setting keys to new payment method names.
	 *
	 * @return array<string, string>
	 */
	protected function map(): array {
		return array(
			'dcc_enabled'              => GatewayIds::CREDIT_CARD,
			'axo_enabled'              => AxoGateway::ID,
			'applepay_button_enabled'  => GatewayIds::APPLE_PAY,
			'googlepay_button_enabled' => GatewayIds::GOOGLE_PAY,
			'pay_later_button_enabled' => 'pay-later',
		);
	}
}
