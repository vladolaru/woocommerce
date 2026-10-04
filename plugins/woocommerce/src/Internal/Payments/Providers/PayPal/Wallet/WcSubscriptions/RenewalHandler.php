<?php
/**
 * Handles subscription renewals.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use WC_Order;
use WC_Payment_Tokens;
use WC_Subscription;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenApplePay;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenPayPal;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\PaymentTokenVenmo;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WooCommercePaymentTokens;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception\NotFoundException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderMetaTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\PaymentsStatusHandlingTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\TransactionIdHandlingTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;

/**
 * Class RenewalHandler
 */
class RenewalHandler {

	use OrderMetaTrait;
	use TransactionIdHandlingTrait;
	use PaymentsStatusHandlingTrait;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * The order endpoint.
	 *
	 * @var OrderEndpoint
	 */
	private $order_endpoint;

	/**
	 * The purchase unit factory.
	 *
	 * @var PurchaseUnitFactory
	 */
	private $purchase_unit_factory;

	/**
	 * The shipping_preference factory.
	 *
	 * @var ShippingPreferenceFactory
	 */
	private $shipping_preference_factory;

	/**
	 * The payer factory.
	 *
	 * @var PayerFactory
	 */
	private $payer_factory;

	/**
	 * The environment.
	 *
	 * @var Environment
	 */
	protected $environment;

	/**
	 * The settings provider
	 *
	 * @var SettingsProvider
	 */
	protected $settings_provider;

	/**
	 * The processor for authorized payments.
	 *
	 * @var AuthorizedPaymentsProcessor
	 */
	protected $authorized_payments_processor;

	/**
	 * The funding source renderer.
	 *
	 * @var FundingSourceRenderer
	 */
	protected $funding_source_renderer;

	/**
	 * WooCommerce payments tokens factory.
	 *
	 * @var WooCommercePaymentTokens
	 */
	private $wc_payment_tokens;

	/**
	 * @param LoggerInterface              $logger The logger.
	 * @param OrderEndpoint                $order_endpoint The order endpoint.
	 * @param PurchaseUnitFactory          $purchase_unit_factory The purchase unit factory.
	 * @param ShippingPreferenceFactory    $shipping_preference_factory The shipping_preference factory.
	 * @param PayerFactory                 $payer_factory The payer factory.
	 * @param Environment                  $environment The environment.
	 * @param SettingsProvider             $settings_provider The Settings Provider.
	 * @param AuthorizedPaymentsProcessor  $authorized_payments_processor The Authorized Payments Processor.
	 * @param FundingSourceRenderer        $funding_source_renderer The funding source renderer.
	 * @param WooCommercePaymentTokens     $wc_payment_tokens WooCommerce payments tokens factory.
	 */
	public function __construct(
		LoggerInterface $logger,
		OrderEndpoint $order_endpoint,
		PurchaseUnitFactory $purchase_unit_factory,
		ShippingPreferenceFactory $shipping_preference_factory,
		PayerFactory $payer_factory,
		Environment $environment,
		SettingsProvider $settings_provider,
		AuthorizedPaymentsProcessor $authorized_payments_processor,
		FundingSourceRenderer $funding_source_renderer,
		WooCommercePaymentTokens $wc_payment_tokens
	) {

		$this->logger                           = $logger;
		$this->order_endpoint                   = $order_endpoint;
		$this->purchase_unit_factory            = $purchase_unit_factory;
		$this->shipping_preference_factory      = $shipping_preference_factory;
		$this->payer_factory                    = $payer_factory;
		$this->environment                      = $environment;
		$this->settings_provider                = $settings_provider;
		$this->authorized_payments_processor    = $authorized_payments_processor;
		$this->funding_source_renderer          = $funding_source_renderer;
		$this->wc_payment_tokens                = $wc_payment_tokens;
	}

	/**
	 * Renew an order.
	 *
	 * @param \WC_Order $wc_order The WooCommerce order.
	 */
	public function renew( \WC_Order $wc_order ): void {
		try {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $wc_order );
			$subscription  = end( $subscriptions );
			if ( $subscription instanceof WC_Subscription ) {
				$subscription_id = $subscription->get_meta( 'ppcp_subscription' ) ?? '';
				if ( $subscription_id ) {
					return;
				}
			}

			$this->process_order( $wc_order );
		} catch ( \Exception $exception ) {
			$error = $exception->getMessage();
			if ( $exception instanceof PayPalApiException ) {
				$error = $exception->get_details( $error );
			}

			$wc_order->update_status(
				'failed',
				$error
			);

			$error_message = sprintf(
				'An error occurred while trying to renew the subscription for order %1$d: %2$s',
				$wc_order->get_id(),
				$error
			);
			$this->logger->error( $error_message );

			return;
		}
	}

	/**
	 * Process a WooCommerce order.
	 *
	 * @param \WC_Order $wc_order The WooCommerce order.
	 *
	 * @throws RuntimeException When no saved payment method is available to process the renewal.
	 */
	private function process_order( \WC_Order $wc_order ): void {
		$user_id  = (int) $wc_order->get_customer_id();
		$customer = new \WC_Customer( $user_id );

		$purchase_unit       = $this->purchase_unit_factory->from_wc_order( $wc_order );
		$payer               = $this->payer_factory->from_customer( $customer );
		$shipping_preference = $this->shipping_preference_factory->from_state(
			$purchase_unit,
			'renewal'
		);

		// Vault v3.
		$payment_source = null;
		$payment_method = $wc_order->get_payment_method();
		if ( $payment_method === PayPalGateway::ID ) {
			$customer_tokens = $this->wc_payment_tokens->customer_tokens( $user_id );

			$wc_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, PayPalGateway::ID );

			if ( $customer_tokens && empty( $wc_tokens ) ) {
				$this->wc_payment_tokens->create_wc_tokens( $customer_tokens, $user_id );
			}

			$customer_token_ids = array();
			foreach ( $customer_tokens as $customer_token ) {
				$customer_token_ids[] = $customer_token['id'];
			}

			$wc_tokens = WC_Payment_Tokens::get_customer_tokens( $user_id, PayPalGateway::ID );
			foreach ( $wc_tokens as $token ) {
				if ( ! in_array( $token->get_token(), $customer_token_ids, true ) ) {
					$token->delete();
					continue;
				}

				$name       = 'paypal';
				$properties = array(
					'vault_id' => $token->get_token(),
				);

				if ( $token instanceof PaymentTokenPayPal ) {
					$name = 'paypal';
				}

				if ( $token instanceof PaymentTokenVenmo ) {
					$name = 'venmo';
				}

				if ( $token instanceof PaymentTokenApplePay ) {
					$name                            = 'apple_pay';
					$properties['stored_credential'] = array(
						'payment_initiator' => 'MERCHANT',
						'payment_type'      => 'RECURRING',
						'usage'             => 'SUBSEQUENT',
					);
				}

				$payment_source = new PaymentSource(
					$name,
					(object) $properties
				);

				break;
			}
		}

		if ( $payment_source ) {
			$order = $this->order_endpoint->create(
				array( $purchase_unit ),
				$shipping_preference,
				$payer,
				'',
				array(),
				$payment_source
			);

			$this->handle_paypal_order( $wc_order, $order );

			$this->logger->info(
				sprintf(
					'Renewal for order %d is completed.',
					$wc_order->get_id()
				)
			);

			return;
		}

		// PPEC compat: allow filters to provide a token for legacy billing agreement renewals.
		$token = $this->get_token_for_customer( $customer, $wc_order );
		if ( $token ) {
			if ( $payment_method === PayPalGateway::ID || $payment_method === 'ppec_paypal' ) {
				$order = $this->order_endpoint->create(
					array( $purchase_unit ),
					$shipping_preference,
					$payer,
					'',
					array(),
					$token->to_payment_source()
				);

				$this->handle_paypal_order( $wc_order, $order );

				$this->logger->info(
					sprintf(
						'Renewal for order %d is completed.',
						$wc_order->get_id()
					)
				);

				return;
			}
		}

		// Reaching this point means no saved payment method (vaulted token or legacy
		// billing agreement) could be used. This commonly happens when the PayPal
		// access token lacks the vault scope and the token lookup returned nothing.
		// Throw so renew() marks the order failed with an actionable message instead
		// of silently completing without taking a payment.
		throw new RuntimeException(
			'No saved payment method is available to process this renewal. The PayPal account may need to be reconnected with vaulting enabled.'
		);
	}

	/**
	 * Returns a payment token for a customer via filter (used by PPEC compat).
	 *
	 * @param \WC_Customer $customer The customer.
	 * @param \WC_Order    $wc_order The current WooCommerce order we want to process.
	 *
	 * @return \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentToken|false
	 */
	private function get_token_for_customer( \WC_Customer $customer, \WC_Order $wc_order ) {
		/**
		 * Returns a payment token for a customer, or null.
		 */
		$token = apply_filters( 'woocommerce_paypal_payments_subscriptions_get_token_for_customer', null, $customer, $wc_order );
		if ( null !== $token ) {
			return $token;
		}

		return false;
	}

	/**
	 * Returns if an order should be captured immediately.
	 *
	 * @param Order $order The PayPal order.
	 *
	 * @return bool
	 */
	protected function capture_authorized_downloads( Order $order ): bool {
		if ( ! $this->settings_provider->capture_virtual_orders() ) {
			return false;
		}

		if ( $order->intent() === 'CAPTURE' ) {
			return false;
		}

		/**
		 * We fetch the order again as the authorize endpoint (from which the Order derives)
		 * drops the item's category, making it impossible to check, if purchase units contain
		 * physical goods.
		 */
		$order = $this->order_endpoint->order( $order->id() );

		foreach ( $order->purchase_units() as $unit ) {
			if ( $unit->contains_physical_goods() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Handles PayPal order creation and updates WC order accordingly.
	 *
	 * @param \WC_Order $wc_order WC order.
	 * @param Order     $order PayPal order.
	 * @return void
	 * @throws NotFoundException When something goes wrong while handling the order.
	 */
	private function handle_paypal_order( \WC_Order $wc_order, Order $order ): void {
		$this->add_paypal_meta( $wc_order, $order, $this->environment );

		if ( $order->intent() === 'AUTHORIZE' ) {
			// No authorize call needed - vault tokens auto-authorize during order creation.
			$wc_order->update_meta_data( AuthorizedPaymentsProcessor::CAPTURED_META_KEY, 'false' );
		}

		$transaction_id = $this->get_paypal_order_transaction_id( $order );
		if ( $transaction_id ) {
			$this->update_transaction_id( $transaction_id, $wc_order );

			$payment_source = $order->payment_source();
			if ( $payment_source instanceof PaymentSource ) {
				$this->update_payment_source( $payment_source, $wc_order );
			}
		}

		$this->handle_new_order_status( $order, $wc_order );

		if ( $this->capture_authorized_downloads( $order ) ) {
			$this->authorized_payments_processor->capture_authorized_payment( $wc_order );
		}
	}

	/**
	 * Updates the payment source name to the one really used for the payment.
	 *
	 * @param PaymentSource $payment_source The Payment Source.
	 * @param \WC_Order     $wc_order WC order.
	 * @return void
	 */
	private function update_payment_source( PaymentSource $payment_source, \WC_Order $wc_order ): void {
		if ( ! $payment_source->name() ) {
			return;
		}
		try {
			$wc_order->set_payment_method_title( $this->funding_source_renderer->render_name( $payment_source->name() ) );
			$wc_order->save();
		} catch ( \Exception $e ) {
			$this->logger->error(
				sprintf(
					'Failed to update payment source to "%1$s" on order %2$d',
					$payment_source->name(),
					$wc_order->get_id()
				)
			);
		}
	}
}
