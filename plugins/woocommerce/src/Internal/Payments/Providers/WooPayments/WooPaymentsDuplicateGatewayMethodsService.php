<?php
/**
 * WooPaymentsDuplicateGatewayMethodsService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Throwable;

/**
 * Finds the payment methods that WooPayments and another enabled gateway both offer, so the settings page can warn
 * about them and the store setup report can send them to the platform, and reads which of those warnings the merchant
 * dismissed. The gateway keywords and the Apple Pay / Google Pay cluster follow client 11.1.0
 * `includes/class-duplicates-detection-service.php`.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsDuplicateGatewayMethodsService {

	/**
	 * Gateway IDs that belong to the card cluster only on an exact match, so their sub-gateways
	 * (stripe_alipay, stripe_link, ...) are classified by payment method keyword instead.
	 */
	private const DUPLICATE_CARD_GATEWAY_IDS = array( 'woocommerce_payments', 'stripe' );

	/**
	 * Gateway ID keywords per payment method, checked in order; the first match wins.
	 * Keywords and order follow the client's payment method definitions and their registry order.
	 */
	private const DUPLICATE_PAYMENT_METHOD_KEYWORDS = array(
		'card'              => array( 'credit_card', 'creditcard', 'cc', 'card' ),
		'affirm'            => array( 'affirm' ),
		'afterpay_clearpay' => array( 'afterpay', 'clearpay' ),
		'alipay'            => array( 'alipay' ),
		'bancontact'        => array( 'bancontact' ),
		'au_becs_debit'     => array( 'becs' ),
		'eps'               => array( 'eps' ),
		'grabpay'           => array( 'grabpay', 'grab_pay', 'grab' ),
		'ideal'             => array( 'ideal' ),
		'link'              => array( 'link' ),
		'multibanco'        => array( 'multibanco' ),
		'klarna'            => array( 'klarna' ),
		'p24'               => array( 'p24', 'przelewy24' ),
		'sepa_debit'        => array( 'sepa' ),
		'wechat_pay'        => array( 'wechat_pay', 'wechatpay' ),
		'apple_pay'         => array( 'apple_pay', 'applepay' ),
		'google_pay'        => array( 'google_pay', 'googlepay', 'gpay' ),
		'amazon_pay'        => array( 'amazon_pay', 'amazonpay', 'amazon' ),
	);

	private const PAYMENT_REQUEST_DUPLICATE_METHOD_ID = 'apple_pay_google_pay';

	private const PAYMENT_REQUEST_DUPLICATE_GATEWAY_KEYWORDS = array(
		'apple_pay',
		'applepay',
		'google_pay',
		'googlepay',
	);

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param WooPaymentsLogger         $logger          WooPayments logger.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsLogger $logger ): void {
		$this->account_service = $account_service;
		$this->logger          = $logger;
	}

	/**
	 * Get dismissed duplicate payment method notice state.
	 *
	 * @return array<string,string[]>
	 */
	public function get_dismissed_duplicate_payment_method_notices(): array {
		$dismissed_notices = get_option( 'wcpay_duplicate_payment_method_notices_dismissed', array() );

		return $this->sanitize_duplicate_gateway_map( is_array( $dismissed_notices ) ? $dismissed_notices : array(), false );
	}

	/**
	 * Get payment methods enabled by WooPayments and another gateway.
	 *
	 * Public so the operational store-setup snapshot can report real duplicates to the platform.
	 *
	 * @return array<string,string[]>
	 */
	public function get_duplicated_payment_method_ids(): array {
		try {
			$duplicate_candidates = array();
			$settings             = $this->account_service->get_gateway_settings();

			foreach ( $this->get_registered_payment_gateways() as $gateway ) {
				if ( ! $this->is_gateway_enabled( $gateway ) ) {
					continue;
				}

				$gateway_id = $this->get_gateway_id( $gateway );
				if ( '' === $gateway_id ) {
					continue;
				}

				if ( $this->is_payment_request_duplicate_gateway( $gateway, $gateway_id ) ) {
					$duplicate_candidates[ self::PAYMENT_REQUEST_DUPLICATE_METHOD_ID ][] = $gateway_id;
				}

				$payment_method_id = $this->get_duplicate_payment_method_id_for_gateway( $gateway_id );
				if ( '' !== $payment_method_id ) {
					$duplicate_candidates[ $payment_method_id ][] = $gateway_id;
				}
			}

			return $this->keep_woopayments_duplicate_clusters_only( $duplicate_candidates );
		} catch ( Throwable $e ) {
			$this->logger->log_throwable( 'Native WooPayments duplicate payment method detection failed.', $e, array(), 'warning' );

			return array();
		}
	}

	/**
	 * Keep only duplicate clusters that include WooPayments.
	 *
	 * @param array<string,string[]> $duplicate_candidates Duplicate candidates.
	 * @return array<string,string[]>
	 */
	private function keep_woopayments_duplicate_clusters_only( array $duplicate_candidates ): array {
		$duplicates = array();

		foreach ( $duplicate_candidates as $payment_method_id => $gateway_ids ) {
			$gateway_ids = array_values( array_unique( $gateway_ids ) );
			if ( count( $gateway_ids ) < 2 ) {
				continue;
			}

			$has_woopayments_gateway = array_filter(
				$gateway_ids,
				fn( string $gateway_id ): bool => WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $gateway_id )
			);
			if ( empty( $has_woopayments_gateway ) ) {
				continue;
			}

			$duplicates[ $payment_method_id ] = $gateway_ids;
		}

		return $duplicates;
	}

	/**
	 * Get registered WooCommerce payment gateways.
	 *
	 * @return object[]
	 */
	private function get_registered_payment_gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}

		$gateways = WC()->payment_gateways()->payment_gateways();

		return is_array( $gateways ) ? array_values( $gateways ) : array();
	}

	/**
	 * Get a gateway ID.
	 *
	 * @param object $gateway Payment gateway.
	 * @return string
	 */
	private function get_gateway_id( object $gateway ): string {
		return isset( $gateway->id ) && is_scalar( $gateway->id ) ? (string) $gateway->id : '';
	}

	/**
	 * Tell whether a gateway is enabled.
	 *
	 * @param object $gateway Payment gateway.
	 * @return bool
	 */
	private function is_gateway_enabled( object $gateway ): bool {
		return isset( $gateway->enabled ) && 'yes' === (string) $gateway->enabled;
	}

	/**
	 * Get the duplicate-detection payment method ID for a gateway ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	private function get_duplicate_payment_method_id_for_gateway( string $gateway_id ): string {
		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $gateway_id ) {
			return 'card';
		}

		if ( 0 === strpos( $gateway_id, WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX ) ) {
			$payment_method_id = substr( $gateway_id, strlen( WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX ) );

			return in_array( $payment_method_id, WooPaymentsPaymentMethodRegistry::SETTINGS_PAYMENT_METHOD_IDS, true ) ? $payment_method_id : '';
		}

		if ( in_array( $gateway_id, self::DUPLICATE_CARD_GATEWAY_IDS, true ) ) {
			return 'card';
		}

		foreach ( self::DUPLICATE_PAYMENT_METHOD_KEYWORDS as $payment_method_id => $keywords ) {
			if ( $this->gateway_id_contains_keyword( $gateway_id, $keywords ) ) {
				return $payment_method_id;
			}
		}

		return '';
	}

	/**
	 * Tell whether a gateway participates in the Apple Pay / Google Pay duplicate cluster.
	 *
	 * @param object $gateway    Payment gateway.
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 */
	private function is_payment_request_duplicate_gateway( object $gateway, string $gateway_id ): bool {
		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $gateway_id ) {
			return $this->account_service->is_payment_request_enabled();
		}

		if ( WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( $gateway_id ) ) {
			return false;
		}

		if ( $this->gateway_id_contains_keyword( $gateway_id, self::PAYMENT_REQUEST_DUPLICATE_GATEWAY_KEYWORDS ) ) {
			return true;
		}

		// The Stripe gateway moved this setting from `payment_request` to `express_checkout` in version 9.1.0.
		if ( 'stripe' === $gateway_id && ( $this->is_gateway_option_enabled( $gateway, 'payment_request' ) || $this->is_gateway_option_enabled( $gateway, 'express_checkout' ) ) ) {
			return true;
		}

		// Any other gateway with express checkout on offers the same buttons (client 11.1.0 `includes/class-duplicates-detection-service.php:141`).
		return $this->is_gateway_option_enabled( $gateway, 'express_checkout_enabled' );
	}

	/**
	 * Tell whether a gateway option is enabled.
	 *
	 * @param object $gateway Payment gateway.
	 * @param string $key     Option key.
	 * @return bool
	 */
	private function is_gateway_option_enabled( object $gateway, string $key ): bool {
		if ( ! method_exists( $gateway, 'get_option' ) ) {
			return false;
		}

		$value = $gateway->get_option( $key );

		return is_scalar( $value ) && $this->is_yes( $value );
	}

	/**
	 * Tell whether a gateway ID contains one of the given keywords.
	 *
	 * @param string   $gateway_id Gateway ID.
	 * @param string[] $keywords   Keywords.
	 * @return bool
	 */
	private function gateway_id_contains_keyword( string $gateway_id, array $keywords ): bool {
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $gateway_id, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sanitize a payment-method to gateway IDs map.
	 *
	 * @param array<int|string,mixed> $gateway_map              Raw gateway map.
	 * @param bool                    $supported_methods_only  Whether to remove unsupported payment method IDs.
	 * @return array<string,string[]>
	 */
	private function sanitize_duplicate_gateway_map( array $gateway_map, bool $supported_methods_only = true ): array {
		$sanitized = array();

		foreach ( $gateway_map as $payment_method_id => $gateway_ids ) {
			if ( ! is_string( $payment_method_id ) || ! is_array( $gateway_ids ) ) {
				continue;
			}

			if ( $supported_methods_only && ! in_array( $payment_method_id, WooPaymentsPaymentMethodRegistry::SETTINGS_PAYMENT_METHOD_IDS, true ) ) {
				continue;
			}

			$gateway_ids = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( $gateway_id ): string => is_scalar( $gateway_id ) ? (string) $gateway_id : '',
							$gateway_ids
						)
					)
				)
			);

			if ( empty( $gateway_ids ) ) {
				continue;
			}

			$sanitized[ $payment_method_id ] = $gateway_ids;
		}

		return $sanitized;
	}

	/**
	 * Normalize a yes/no value.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function is_yes( $value ): bool {
		return 'yes' === $value || true === $value || '1' === $value || 1 === $value;
	}
}
