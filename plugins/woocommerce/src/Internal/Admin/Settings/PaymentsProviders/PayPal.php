<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders;

use Automattic\WooCommerce\Internal\Logging\SafeGlobalFunctionProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\DormantPayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use WC_Payment_Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * PayPal payment gateway provider class.
 *
 * This class handles all the custom logic for the PayPal payment gateway provider.
 */
class PayPal extends PaymentGateway {

	/**
	 * Get the provider details, with the setup notice the PayPal Wallet row shows once a customer paid with it.
	 *
	 * The notice is the core-owned `woocommerce_paypal_wallet_provider_notice` filter's answer, added as `_notice` only when
	 * it is an array. The NOX list item renders it under the row.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $gateway      The payment gateway object.
	 * @param int                $order        Optional. The order of the gateway in the list.
	 * @param string             $country_code Optional. The country code for which the details are being gathered.
	 *
	 * @return array The provider details.
	 */
	public function get_details( WC_Payment_Gateway $gateway, int $order = 0, string $country_code = '' ): array {
		$details = parent::get_details( $gateway, $order, $country_code );

		/**
		 * Filters the setup notice shown under a payment gateway row of the Payments settings list.
		 *
		 * @since 11.3.0
		 *
		 * @param array|null $notice     The notice, with the keys `title`, `text`, `action_label`, `action_url` and `dismissible`, or null for none.
		 * @param string     $gateway_id The ID of the payment gateway.
		 */
		$notice = apply_filters( 'woocommerce_paypal_wallet_provider_notice', null, $gateway->id );
		if ( is_array( $notice ) ) {
			$details['_notice'] = $notice;
		}

		return $details;
	}

	/**
	 * Whether the store sells with PayPal Wallet in the collecting state.
	 *
	 * Reads the state only when a collecting or platform option exists in the autoloaded set, so a store with no wallet
	 * history runs no added query.
	 *
	 * @return bool
	 */
	private function is_collecting_wallet(): bool {
		$options = new Options();
		if ( ! $options->has_autoloaded( Options::COLLECTING ) && ! $options->has_autoloaded( Options::PLATFORM ) ) {
			return false;
		}

		return ConnectionState::COLLECTING === ( new ConnectionState( $options ) )->resolve();
	}

	/**
	 * Get the provider title, naming the row "PayPal Wallet" when core provides the gateway.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return string
	 */
	public function get_title( WC_Payment_Gateway $payment_gateway ): string {
		if ( $this->is_core_provided( $payment_gateway ) ) {
			return __( 'PayPal Wallet', 'woocommerce' );
		}

		return parent::get_title( $payment_gateway );
	}

	/**
	 * Get the provider description, naming the wallet's payment methods when core provides the gateway.
	 *
	 * The wallet gateway gives its merchant-facing description only on admin page loads,
	 * so the Payments list, which loads over REST, would otherwise show the shopper-facing one.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return string
	 */
	public function get_description( WC_Payment_Gateway $payment_gateway ): string {
		if ( $this->is_core_provided( $payment_gateway ) ) {
			return __( 'Offer PayPal, Pay Later, and Venmo (US only) at checkout.', 'woocommerce' );
		}

		return parent::get_description( $payment_gateway );
	}

	/**
	 * Get the provider icon URL, using the PayPal icon when core provides the gateway.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return string The provider icon URL of the payment gateway.
	 */
	public function get_icon( WC_Payment_Gateway $payment_gateway ): string {
		if ( $this->is_core_provided( $payment_gateway ) ) {
			return plugins_url( 'assets/images/onboarding/icons/paypal.svg', WC_PLUGIN_FILE );
		}

		return parent::get_icon( $payment_gateway );
	}

	/**
	 * Get the plugin details, with no plugin file when core provides the gateway (so it cannot be deactivated).
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return array
	 */
	public function get_plugin_details( WC_Payment_Gateway $payment_gateway ): array {
		$plugin_details = parent::get_plugin_details( $payment_gateway );
		if ( $this->is_core_provided( $payment_gateway ) ) {
			$plugin_details['file'] = '';
		}

		return $plugin_details;
	}

	/**
	 * Get the onboarding URL, pointing the dormant placeholder at the wallet's settings, where the merchant connects.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 * @param string             $return_url      Optional. The URL to return to after onboarding.
	 *
	 * @return string
	 */
	public function get_onboarding_url( WC_Payment_Gateway $payment_gateway, string $return_url = '' ): string {
		if ( $payment_gateway instanceof DormantPayPalGateway ) {
			return $this->get_wallet_settings_url();
		}

		return parent::get_onboarding_url( $payment_gateway, $return_url );
	}

	/**
	 * Get the settings URL, pointing the dormant placeholder at the wallet's settings.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return string
	 */
	public function get_settings_url( WC_Payment_Gateway $payment_gateway ): string {
		if ( $payment_gateway instanceof DormantPayPalGateway ) {
			return $this->get_wallet_settings_url();
		}

		return parent::get_settings_url( $payment_gateway );
	}

	/**
	 * The wallet's settings route, where a merchant connects their PayPal account.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	private function get_wallet_settings_url(): string {
		return PayPalWalletBootstrap::get_settings_url();
	}

	/**
	 * Whether this gateway is the extension's PayPal gateway served by the core-native wallet runtime.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return bool
	 */
	private function is_core_provided( WC_Payment_Gateway $payment_gateway ): bool {
		if ( 'ppcp-gateway' !== $payment_gateway->id ) {
			return false;
		}

		return wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->should_native_register();
	}

	/**
	 * Get the booted PayPal container of the copy that runs in this request: core's wallet when it registers natively, the extension's otherwise.
	 *
	 * @since 11.3.0
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return \Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface|null The container (the extension's container offers the same has() and get()), or null when no copy is booted.
	 */
	private function get_paypal_container( WC_Payment_Gateway $payment_gateway ) {
		// Core owning the site does not mean its wallet booted in this request (it skips update.php, the extension's activation request and a copy loaded from another folder), so a failure here falls through to the extension.
		try {
			if ( $this->is_core_provided( $payment_gateway ) ) {
				return \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP::container();
			}
		} catch ( \Throwable $e ) {
			// The wallet container is not available; try the extension's below.
			unset( $e );
		}

		if ( class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) ) {
			try {
				return \WooCommerce\PayPalCommerce\PPCP::container();
			} catch ( \Throwable $e ) {
				// The extension container is not available either.
				unset( $e );
			}
		}

		return null;
	}

	/**
	 * Try to determine if the payment gateway is in test mode.
	 *
	 * This is a best-effort attempt, as there is no standard way to determine this.
	 * Trust the true value, but don't consider a false value as definitive.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return bool True if the payment gateway is in test mode, false otherwise.
	 */
	public function is_in_test_mode( WC_Payment_Gateway $payment_gateway ): bool {
		return $this->is_paypal_in_sandbox_mode( $payment_gateway ) ?? parent::is_in_test_mode( $payment_gateway );
	}

	/**
	 * Try to determine if the payment gateway is in dev mode.
	 *
	 * This is a best-effort attempt, as there is no standard way to determine this.
	 * Trust the true value, but don't consider a false value as definitive.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return bool True if the payment gateway is in dev mode, false otherwise.
	 */
	public function is_in_dev_mode( WC_Payment_Gateway $payment_gateway ): bool {
		return $this->is_paypal_in_sandbox_mode( $payment_gateway ) ?? parent::is_in_dev_mode( $payment_gateway );
	}

	/**
	 * Check if the payment gateway has a payments processor account connected.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return bool True if the payment gateway account is connected, false otherwise.
	 *              If the payment gateway does not provide the information, it will return true.
	 */
	public function is_account_connected( WC_Payment_Gateway $payment_gateway ): bool {
		// A store that sells with PayPal Wallet before it has a PayPal account is not connected, so the row reads "Action needed" from the first request.
		if ( $this->is_core_provided( $payment_gateway ) && $this->is_collecting_wallet() ) {
			return false;
		}

		return $this->is_paypal_onboarded( $payment_gateway ) ?? parent::is_account_connected( $payment_gateway );
	}

	/**
	 * Check if the payment gateway has completed the onboarding process.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return bool True if the payment gateway has completed the onboarding process, false otherwise.
	 *              If the payment gateway does not provide the information,
	 *              it will infer it from having a connected account.
	 */
	public function is_onboarding_completed( WC_Payment_Gateway $payment_gateway ): bool {
		return $this->is_paypal_onboarded( $payment_gateway ) ?? parent::is_onboarding_completed( $payment_gateway );
	}

	/**
	 * Try to determine if the payment gateway is in test mode onboarding (aka sandbox or test-drive).
	 *
	 * This is a best-effort attempt, as there is no standard way to determine this.
	 * Trust the true value, but don't consider a false value as definitive.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return bool True if the payment gateway is in test mode onboarding, false otherwise.
	 */
	public function is_in_test_mode_onboarding( WC_Payment_Gateway $payment_gateway ): bool {
		return $this->is_paypal_in_sandbox_mode( $payment_gateway ) ?? parent::is_in_test_mode_onboarding( $payment_gateway );
	}

	/**
	 * Check if the PayPal payment gateway is in sandbox mode.
	 *
	 * For PayPal, there are two different environments: sandbox and production.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return ?bool True if the payment gateway is in sandbox mode, false otherwise.
	 *               Null if the environment could not be determined.
	 */
	private function is_paypal_in_sandbox_mode( WC_Payment_Gateway $payment_gateway ): ?bool {
		$container = $this->get_paypal_container( $payment_gateway );
		if ( null !== $container ) {
			try {
				if ( $container->has( 'settings.connection-state' ) ) {
					$state = $container->get( 'settings.connection-state' );

					return $state->is_sandbox();
				}

				// Backwards compatibility with pre 3.0.0 (deprecated).
				if ( $container->has( 'onboarding.environment' ) &&
					defined( '\WooCommerce\PayPalCommerce\Onboarding\Environment::SANDBOX' ) ) {
					$environment         = $container->get( 'onboarding.environment' );
					$current_environment = $environment->current_environment();

					return \WooCommerce\PayPalCommerce\Onboarding\Environment::SANDBOX === $current_environment;
				}
			} catch ( \Throwable $e ) {
				// Do nothing but log so we can investigate.
				SafeGlobalFunctionProxy::wc_get_logger()->debug(
					'Failed to determine if gateway is in sandbox mode: ' . $e->getMessage(),
					array(
						'gateway'   => $payment_gateway->id,
						'source'    => 'settings-payments',
						'exception' => $e,
					)
				);
			}
		}

		// The wallet did not boot, which is how a dormant wallet looks; read the shared settings option instead.
		if ( $this->is_core_provided( $payment_gateway ) ) {
			return GeneralSettings::read_connection_from_options()['sandbox'];
		}

		// Let the caller know that we couldn't determine the environment.
		return null;
	}

	/**
	 * Check if the PayPal payment gateway is onboarded.
	 *
	 * @param WC_Payment_Gateway $payment_gateway The payment gateway object.
	 *
	 * @return ?bool True if the payment gateway is onboarded, false otherwise.
	 *               Null if we failed to determine the onboarding status.
	 */
	private function is_paypal_onboarded( WC_Payment_Gateway $payment_gateway ): ?bool {
		$container = $this->get_paypal_container( $payment_gateway );
		if ( null !== $container ) {
			try {
				if ( $container->has( 'settings.connection-state' ) ) {
					$state = $container->get( 'settings.connection-state' );

					return $state->is_connected();
				}

				// Backwards compatibility with pre 3.0.0 (deprecated).
				if ( $container->has( 'onboarding.state' ) &&
					defined( '\WooCommerce\PayPalCommerce\Onboarding\State::STATE_ONBOARDED' ) ) {
					$state = $container->get( 'onboarding.state' );

					return $state->current_state() >= \WooCommerce\PayPalCommerce\Onboarding\State::STATE_ONBOARDED;
				}
			} catch ( \Throwable $e ) {
				// Do nothing but log so we can investigate.
				SafeGlobalFunctionProxy::wc_get_logger()->debug(
					'Failed to determine if gateway is onboarded: ' . $e->getMessage(),
					array(
						'gateway'   => $payment_gateway->id,
						'source'    => 'settings-payments',
						'exception' => $e,
					)
				);
			}
		}

		// The wallet did not boot, which is how a dormant wallet looks; read the shared settings option instead.
		if ( $this->is_core_provided( $payment_gateway ) ) {
			return GeneralSettings::read_connection_from_options()['connected'];
		}

		// Let the caller know that we couldn't determine the onboarding status.
		return null;
	}
}
