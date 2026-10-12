<?php
/**
 * CryptoCheckBody class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;

/**
 * The body of the Pay with Crypto check order: the body `PWCGateway::process_payment()` builds, without a WooCommerce order.
 *
 * USD 1.00, the same intent, processing instruction and payment source, and the experience context the gateway builds,
 * with the return and cancel URLs on the store's order-received path. The order and buyer details come from the store
 * instead: its base country and its name, with neutral fallbacks. The custom ID names the check, and the invoice ID adds
 * the time so a merchant whose account requires unique invoice IDs does not refuse a repeat check. The payee is not here:
 * seam 10 adds it when the order is created, as it does for the gateway's.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CryptoCheckBody {

	/**
	 * The custom ID of the check order, and the prefix of its invoice ID.
	 *
	 * @since 11.3.0
	 */
	public const CHECK_ID = 'wc-paypal-wallet-crypto-check';

	/**
	 * The country sent when the store has no base country. Pay with Crypto is a US product.
	 */
	private const FALLBACK_COUNTRY = 'US';

	/**
	 * The given name sent when the store has no name.
	 */
	private const FALLBACK_NAME = 'Store';

	/**
	 * The surname sent with the store's name.
	 */
	private const SURNAME = 'Check';

	/**
	 * PayPal's limit on a name part.
	 */
	private const NAME_MAX_LENGTH = 140;

	/**
	 * The wallet's experience context builder, as the gateway uses it.
	 *
	 * @var ExperienceContextBuilder
	 */
	private ExperienceContextBuilder $experience_context_builder;

	/**
	 * Constructor.
	 *
	 * @param ExperienceContextBuilder $experience_context_builder The wallet's experience context builder.
	 */
	public function __construct( ExperienceContextBuilder $experience_context_builder ) {
		$this->experience_context_builder = $experience_context_builder;
	}

	/**
	 * The check order's body.
	 *
	 * @since 11.3.0
	 *
	 * @param int $now The current Unix time, for the invoice ID.
	 * @return array
	 */
	public function build( int $now ): array {
		$return_url = wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() );

		$experience_context = $this->experience_context_builder
			->with_custom_return_url( $return_url )
			->with_custom_cancel_url( add_query_arg( 'cancelled', 'true', $return_url ) )
			->with_current_locale()
			->build()
			->to_array();

		return array(
			'processing_instruction' => 'ORDER_COMPLETE_ON_PAYMENT_APPROVAL',
			'intent'                 => 'CAPTURE',
			'purchase_units'         => array(
				array(
					'reference_id' => 'default',
					'amount'       => array(
						'currency_code' => 'USD',
						'value'         => '1.00',
					),
					'custom_id'    => self::CHECK_ID,
					'invoice_id'   => self::CHECK_ID . '-' . $now,
				),
			),
			'payment_source'         => array(
				'crypto' => array(
					'country_code'       => $this->country(),
					'name'               => array(
						'given_name' => $this->store_name(),
						'surname'    => self::SURNAME,
					),
					'experience_context' => $experience_context,
				),
			),
		);
	}

	/**
	 * The store's base country from the WooCommerce settings, or the fallback.
	 *
	 * @return string
	 */
	private function country(): string {
		$country = wc_get_base_location()['country'] ?? '';

		return is_string( $country ) && 2 === strlen( $country ) ? $country : self::FALLBACK_COUNTRY;
	}

	/**
	 * The store's name, cut to PayPal's limit, or the fallback.
	 *
	 * @return string
	 */
	private function store_name(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );

		return '' === $name ? self::FALLBACK_NAME : mb_substr( $name, 0, self::NAME_MAX_LENGTH );
	}
}
