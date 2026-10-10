<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\AuthAssertion;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\SellerStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * A platform transport that answers canned values tagged by app and records every call.
 *
 * Options: `ready` (default true), `pick` (the app pick_order_app() answers; default null, which derives it from the platform option as the direct transport does: the platform app once the option holds a `merchant_id`, else the merchant app; a test about something other than the pick passes it explicitly), `environment`
 * (default `sandbox`), `partner_merchant_id` (default `PARTNER-FAKE`), `seller_status` (a SellerStatus, default an
 * incomplete one, or a RuntimeException to throw), `webhooks` (the subscription map, default empty), `verify` (default
 * true; a bool for every app, or a map of bools by app, where a missing app does not verify). The assertion header is
 * derived from the platform option, as the direct transport derives it.
 */
final class FakePlatformTransport implements PlatformTransport {

	/**
	 * The calls, by method name: one list of arguments per call.
	 *
	 * @var array<string, array<int, array>>
	 */
	public array $calls = array();

	/**
	 * The options.
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * One bearer per app, so a test can compare instances.
	 *
	 * @var array<string, Bearer>
	 */
	private array $bearers = array();

	/**
	 * Constructor.
	 *
	 * @param array $options The options; see the class docblock.
	 */
	public function __construct( array $options = array() ) {
		$this->options = $options + array(
			'ready'               => true,
			'pick'                => null,
			'environment'         => 'sandbox',
			'partner_merchant_id' => 'PARTNER-FAKE',
			'seller_status'       => new SellerStatus( '', false, false, false ),
			'webhooks'            => array(),
			'verify'              => true,
		);
	}

	/**
	 * The calls made to one method.
	 *
	 * @param string $method The method name.
	 * @return array<int, array>
	 */
	public function calls_to( string $method ): array {
		return $this->calls[ $method ] ?? array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function environment(): string {
		$this->record( __FUNCTION__ );
		return $this->options['environment'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		$this->record( __FUNCTION__ );
		return $this->options['ready'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $payee_email The payee.
	 */
	public function pick_order_app( string $payee_email ): string {
		$this->record( __FUNCTION__, $payee_email );
		if ( null !== $this->options['pick'] ) {
			return $this->options['pick'];
		}

		return '' !== (string) ( ( new Options() )->platform()['merchant_id'] ?? '' ) ? self::APP_PLATFORM : self::APP_MERCHANT_APP;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 */
	public function sdk_client_id( string $app ): string {
		$this->record( __FUNCTION__, $app );
		return 'client-' . $app;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 */
	public function bearer( string $app ): Bearer {
		$this->record( __FUNCTION__, $app );
		if ( ! isset( $this->bearers[ $app ] ) ) {
			$this->bearers[ $app ] = new class( 'token-' . $app ) implements Bearer {
				/**
				 * The token value.
				 *
				 * @var string
				 */
				private string $value;

				/**
				 * Constructor.
				 *
				 * @param string $value The token value.
				 */
				public function __construct( string $value ) {
					$this->value = $value;
				}

				/**
				 * A token that expires in an hour.
				 *
				 * @return Token
				 */
				public function bearer(): Token {
					return new Token(
						(object) array(
							'token'      => $this->value,
							'expires_in' => 3600,
						)
					);
				}
			};
		}

		return $this->bearers[ $app ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 */
	public function host( string $app ): string {
		$this->record( __FUNCTION__, $app );
		return 'https://api.' . str_replace( '_', '-', $app ) . '.fake.test';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 */
	public function assertion_header( string $app ): array {
		$this->record( __FUNCTION__, $app );
		$merchant_id = ( new Options() )->platform()['merchant_id'] ?? '';

		return is_string( $merchant_id ) && '' !== $merchant_id ? AuthAssertion::header( 'client-' . $app, $merchant_id ) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function partner_merchant_id(): string {
		$this->record( __FUNCTION__ );
		return $this->options['partner_merchant_id'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tracking_id The tracking ID.
	 * @param string $return_url  The return URL.
	 * @param string $email       The payee email.
	 */
	public function referral_link( string $tracking_id, string $return_url, string $email = '' ): string {
		$this->record( __FUNCTION__, $tracking_id, $return_url, $email );
		return 'https://www.sandbox.paypal.com/fake-referral?tracking_id=' . rawurlencode( $tracking_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tracking_id The tracking ID.
	 */
	public function seller_status( string $tracking_id ): SellerStatus {
		$this->record( __FUNCTION__, $tracking_id );
		if ( $this->options['seller_status'] instanceof RuntimeException ) {
			throw $this->options['seller_status'];
		}

		return $this->options['seller_status'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function webhook_subscriptions(): array {
		$this->record( __FUNCTION__ );
		return $this->options['webhooks'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url The listener URL.
	 */
	public function subscribe_webhooks( string $url ): array {
		$this->record( __FUNCTION__, $url );
		foreach ( array( self::APP_PLATFORM, self::APP_MERCHANT_APP ) as $app ) {
			if ( ! isset( $this->options['webhooks'][ $app ] ) ) {
				$this->options['webhooks'][ $app ] = 'WH-' . $app;
			}
		}

		return $this->options['webhooks'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function unsubscribe_webhooks(): void {
		$this->record( __FUNCTION__ );
		$this->options['webhooks'] = array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app     The app.
	 * @param array  $headers The request headers.
	 * @param string $body    The raw body.
	 */
	public function verify_webhook( string $app, array $headers, string $body ): bool {
		$this->record( __FUNCTION__, $app, $headers, $body );
		$verify = $this->options['verify'];

		return is_array( $verify ) ? ! empty( $verify[ $app ] ) : (bool) $verify;
	}

	/**
	 * Record a call.
	 *
	 * @param string $method The method name.
	 * @param mixed  ...$args The arguments.
	 */
	private function record( string $method, ...$args ): void {
		$this->calls[ $method ][] = $args;
	}
}
