<?php
/**
 * DirectPlatformTransport class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\RequestTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use stdClass;

/**
 * The proof of concept's platform transport: it talks to PayPal directly with the credentials of two apps.
 *
 * The apps' credentials come from wp-config.php constants and never from an option or the codebase, so this transport
 * exists only where someone configured them. It stands in for the platform service that will hold the credentials in
 * production, where one app serves every call and the pick below is always the platform.
 *
 * Every method throws the wallet's RuntimeException, or a subclass, and nothing else.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class DirectPlatformTransport implements PlatformTransport {
	use RequestTrait;

	/**
	 * The sandbox API host, shared by both apps.
	 */
	private const HOST_SANDBOX = 'https://api-m.sandbox.paypal.com';

	/**
	 * The production API host, shared by both apps.
	 */
	private const HOST_PRODUCTION = 'https://api-m.paypal.com';

	/**
	 * The events the platform app's subscription listens to: onboarding, and captures to reconcile held orders. PayPal
	 * takes exact event names only; a prefix wildcard such as `PAYMENT.CAPTURE.*` is refused as not a valid event name.
	 */
	private const PLATFORM_EVENTS = array(
		'MERCHANT.ONBOARDING.COMPLETED',
		'MERCHANT.PARTNER-CONSENT.REVOKED',
		'PAYMENT.CAPTURE.COMPLETED',
		'PAYMENT.CAPTURE.PENDING',
		'PAYMENT.CAPTURE.REVERSED',
		'PAYMENT.CAPTURE.REFUNDED',
		'PAYMENT.CAPTURE.DENIED',
	);

	/**
	 * The events the merchant app's subscription listens to: its orders' captures and approvals.
	 */
	private const MERCHANT_APP_EVENTS = array(
		'PAYMENT.CAPTURE.COMPLETED',
		'PAYMENT.CAPTURE.PENDING',
		'PAYMENT.CAPTURE.REVERSED',
		'PAYMENT.CAPTURE.REFUNDED',
		'PAYMENT.CAPTURE.DENIED',
		'CHECKOUT.ORDER.APPROVED',
	);

	/**
	 * The features the third-party referral asks the merchant to grant the platform. No vaulting.
	 */
	private const REFERRAL_FEATURES = array(
		'PAYMENT',
		'REFUND',
		'PARTNER_FEE',
		'DELAY_FUNDS_DISBURSEMENT',
	);

	/**
	 * The credentials: platform_client_id, platform_client_secret, partner_merchant_id, merchant_app_client_id,
	 * merchant_app_client_secret (strings, empty when missing) and sandbox (bool, or null when missing).
	 *
	 * @var array
	 */
	private array $credentials;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The order app context, which a call enters for the app it goes through.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * The option reader.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * The bearers, built on first use, by app.
	 *
	 * @var array<string, PerAppBearer>
	 */
	private array $bearers = array();

	/**
	 * Constructor. The only production way to build one is from_constants(); this one lets a test hand in the values.
	 *
	 * @param array           $credentials The credentials; see the property.
	 * @param LoggerInterface $logger      The logger.
	 * @param OrderAppContext $context     The order app context.
	 * @param Options         $options     The option reader.
	 */
	public function __construct( array $credentials, LoggerInterface $logger, OrderAppContext $context, Options $options ) {
		$text = static function ( $value ): string {
			return is_string( $value ) ? $value : '';
		};

		$this->credentials = array(
			'platform_client_id'         => $text( $credentials['platform_client_id'] ?? '' ),
			'platform_client_secret'     => $text( $credentials['platform_client_secret'] ?? '' ),
			'partner_merchant_id'        => $text( $credentials['partner_merchant_id'] ?? '' ),
			'merchant_app_client_id'     => $text( $credentials['merchant_app_client_id'] ?? '' ),
			'merchant_app_client_secret' => $text( $credentials['merchant_app_client_secret'] ?? '' ),
			'sandbox'                    => isset( $credentials['sandbox'] ) ? (bool) $credentials['sandbox'] : null,
		);
		$this->logger      = $logger;
		$this->context     = $context;
		$this->options     = $options;
	}

	/**
	 * Build the transport from the wp-config.php constants. It is not ready when any of them is missing or empty.
	 *
	 * @since 11.3.0
	 *
	 * @param LoggerInterface $logger  The logger.
	 * @param OrderAppContext $context The order app context.
	 * @return PlatformTransport
	 */
	public static function from_constants( LoggerInterface $logger, OrderAppContext $context ): PlatformTransport {
		$read = static function ( string $name ) {
			return defined( $name ) ? constant( $name ) : '';
		};

		$sandbox = defined( 'WC_PAYPAL_WALLET_PLATFORM_SANDBOX' ) ? self::parse_flag( constant( 'WC_PAYPAL_WALLET_PLATFORM_SANDBOX' ) ) : null;

		return new self(
			array(
				'platform_client_id'         => $read( 'WC_PAYPAL_WALLET_PLATFORM_CLIENT_ID' ),
				'platform_client_secret'     => $read( 'WC_PAYPAL_WALLET_PLATFORM_CLIENT_SECRET' ),
				'partner_merchant_id'        => $read( 'WC_PAYPAL_WALLET_PLATFORM_PARTNER_MERCHANT_ID' ),
				'merchant_app_client_id'     => $read( 'WC_PAYPAL_WALLET_MERCHANT_APP_CLIENT_ID' ),
				'merchant_app_client_secret' => $read( 'WC_PAYPAL_WALLET_MERCHANT_APP_CLIENT_SECRET' ),
				'sandbox'                    => $sandbox,
			),
			$logger,
			$context,
			new Options()
		);
	}

	/**
	 * Read the sandbox flag, failing closed: a real bool, or the strings 1, 0, true and false in any case. Anything
	 * else, an empty string included, is no flag at all, so the transport is not ready rather than on production.
	 *
	 * @param mixed $value The constant's value.
	 * @return bool|null The flag, or null when the value is not one.
	 */
	private static function parse_flag( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = strtolower( $value );
		if ( in_array( $value, array( '1', 'true' ), true ) ) {
			return true;
		}

		return in_array( $value, array( '0', 'false' ), true ) ? false : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function environment(): string {
		return false === $this->credentials['sandbox'] ? 'production' : 'sandbox';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		foreach ( $this->credentials as $key => $value ) {
			if ( 'sandbox' === $key ? null === $value : '' === $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * The merchant app while the platform option has no merchant ID, the platform once it has. It reads the option and
	 * nothing else, so it is cheap and gives the same answer all request long.
	 *
	 * @param string $payee_email The payee buyers pay.
	 */
	public function pick_order_app( string $payee_email ): string {
		$merchant_id = $this->options->platform()['merchant_id'] ?? '';

		return is_string( $merchant_id ) && '' !== $merchant_id ? self::APP_PLATFORM : self::APP_MERCHANT_APP;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app One of the APP_ constants.
	 */
	public function sdk_client_id( string $app ): string {
		return $this->client_id( $app );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app One of the APP_ constants.
	 */
	public function bearer( string $app ): PerAppBearer {
		$this->require_app( $app );
		if ( ! isset( $this->bearers[ $app ] ) ) {
			$this->bearers[ $app ] = new PerAppBearer( $app, $this->host( $app ), $this->client_id( $app ), $this->client_secret( $app ), $this->logger );
		}

		return $this->bearers[ $app ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app One of the APP_ constants.
	 */
	public function host( string $app ): string {
		$this->require_app( $app );

		return 'production' === $this->environment() ? self::HOST_PRODUCTION : self::HOST_SANDBOX;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app One of the APP_ constants.
	 */
	public function assertion_header( string $app ): array {
		$client_id   = $this->client_id( $app );
		$merchant_id = $this->options->platform()['merchant_id'] ?? '';

		return is_string( $merchant_id ) && '' !== $merchant_id ? AuthAssertion::header( $client_id, $merchant_id ) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function partner_merchant_id(): string {
		return $this->credentials['partner_merchant_id'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * A third-party referral for the platform's products with no vaulting, so the merchant grants the platform a
	 * payments permission and nothing more. The payee email goes in the referral's top-level `email`, which PayPal
	 * prefills the sign-up form with.
	 *
	 * @param string $tracking_id The tracking ID.
	 * @param string $return_url  Where PayPal sends the merchant back to.
	 * @param string $email       The payee email; left out of the referral when empty.
	 *
	 * @throws RuntimeException When the request fails or the answer has no action link.
	 */
	public function referral_link( string $tracking_id, string $return_url, string $email = '' ): string {
		$prefill = '' !== $email ? array( 'email' => $email ) : array();

		list( , $json ) = $this->call(
			self::APP_PLATFORM,
			'POST',
			'v2/customer/partner-referrals',
			$prefill + array(
				'tracking_id'             => $tracking_id,
				'partner_config_override' => array( 'return_url' => $return_url ),
				'operations'              => array(
					array(
						'operation'                  => 'API_INTEGRATION',
						'api_integration_preference' => array(
							'rest_api_integration' => array(
								'integration_method'  => 'PAYPAL',
								'integration_type'    => 'THIRD_PARTY',
								'third_party_details' => array( 'features' => self::REFERRAL_FEATURES ),
							),
						),
					),
				),
				'products'                => array( 'EXPRESS_CHECKOUT' ),
				'legal_consents'          => array(
					array(
						'type'    => 'SHARE_DATA_CONSENT',
						'granted' => true,
					),
				),
			),
			array( 201 ),
			array( 'Prefer' => 'return=representation' )
		);

		foreach ( (array) ( $json->links ?? array() ) as $link ) {
			if ( $link instanceof stdClass && 'action_url' === ( $link->rel ?? '' ) && is_string( $link->href ?? null ) ) {
				return $link->href;
			}
		}

		throw new RuntimeException( 'The PayPal referral has no action link.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The lookup by tracking ID names the merchant once they signed up; the merchant's own record holds the status.
	 *
	 * @param string $tracking_id The tracking ID.
	 *
	 * @throws RuntimeException When a request fails or an answer is not a JSON object.
	 */
	public function seller_status( string $tracking_id ): SellerStatus {
		$base = 'v1/customer/partners/' . rawurlencode( $this->partner_merchant_id() ) . '/merchant-integrations';

		list( $status, $json ) = $this->call( self::APP_PLATFORM, 'GET', $base . '?tracking_id=' . rawurlencode( $tracking_id ), null, array( 200, 404 ) );
		if ( 404 === $status ) {
			$this->logger->debug( 'The PayPal seller lookup found no seller: ' . ( null !== $json && is_string( $json->name ?? null ) ? $json->name : 'no error name' ) );
			return new SellerStatus( '', false, false, false );
		}
		if ( null === $json ) {
			throw new RuntimeException( 'The PayPal seller status is not a JSON object.' );
		}

		$merchant_id = is_string( $json->merchant_id ?? null ) ? $json->merchant_id : '';
		if ( '' !== $merchant_id && ! isset( $json->payments_receivable ) ) {
			list( , $json ) = $this->call( self::APP_PLATFORM, 'GET', $base . '/' . rawurlencode( $merchant_id ), null, array( 200 ) );
			if ( null === $json ) {
				throw new RuntimeException( 'The PayPal seller status is not a JSON object.' );
			}
		}

		$consent = false;
		foreach ( (array) ( $json->oauth_integrations ?? array() ) as $integration ) {
			if ( $integration instanceof stdClass && ! empty( $integration->oauth_third_party ) ) {
				$consent = true;
			}
		}

		return new SellerStatus(
			$merchant_id,
			! empty( $json->payments_receivable ),
			! empty( $json->primary_email_confirmed ),
			$consent
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	public function webhook_subscriptions(): array {
		$stored = array();
		foreach ( array( self::APP_PLATFORM, self::APP_MERCHANT_APP ) as $app ) {
			$id = $this->options->webhooks()[ $app ] ?? '';
			if ( is_string( $id ) && '' !== $id ) {
				$stored[ $app ] = $id;
			}
		}

		return $stored;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every app is tried, whatever happened to the others: each subscription that was created is stored, and the first
	 * failure is thrown once all were tried. One PayPal already holds for the URL is adopted.
	 *
	 * @param string $url The listener URL.
	 * @return array<string, string>
	 *
	 * @throws RuntimeException When a subscription cannot be created.
	 */
	public function subscribe_webhooks( string $url ): array {
		$events = array(
			self::APP_PLATFORM     => self::PLATFORM_EVENTS,
			self::APP_MERCHANT_APP => self::MERCHANT_APP_EVENTS,
		);

		$stored  = $this->webhook_subscriptions();
		$failure = null;
		foreach ( $events as $app => $names ) {
			if ( isset( $stored[ $app ] ) ) {
				continue;
			}

			try {
				$id = $this->create_webhook( $app, $url, $names );
			} catch ( RuntimeException $exception ) {
				$failure = $failure ?? $exception;
				continue;
			}

			$stored[ $app ] = $id;
			update_option( Options::WEBHOOKS, $stored );
		}

		if ( null !== $failure ) {
			throw $failure;
		}

		return $stored;
	}

	/**
	 * Create an app's webhook, or adopt the one PayPal already holds for the URL.
	 *
	 * When a create succeeded at PayPal but its answer was lost, the next create is refused as a duplicate. The app's
	 * webhooks are then listed and the one with the URL is taken, so the app is not locked out of subscribing.
	 *
	 * @param string   $app   One of the APP_ constants.
	 * @param string   $url   The listener URL.
	 * @param string[] $names The event names.
	 * @return string The webhook ID.
	 *
	 * @throws RuntimeException When the webhook cannot be created.
	 * @throws PayPalApiException When PayPal refuses it and no existing webhook matches.
	 */
	private function create_webhook( string $app, string $url, array $names ): string {
		try {
			list( , $json ) = $this->call(
				$app,
				'POST',
				'v1/notifications/webhooks',
				array(
					'url'         => $url,
					'event_types' => array_map(
						static function ( string $name ): array {
							return array( 'name' => $name );
						},
						$names
					),
				),
				array( 201 )
			);
		} catch ( PayPalApiException $exception ) {
			if ( 'WEBHOOK_URL_ALREADY_EXISTS' !== $exception->name() && ! $exception->has_detail( 'WEBHOOK_URL_ALREADY_EXISTS' ) ) {
				throw $exception;
			}

			list( , $listed ) = $this->call( $app, 'GET', 'v1/notifications/webhooks', null, array( 200 ) );
			foreach ( (array) ( $listed->webhooks ?? array() ) as $webhook ) {
				if ( $webhook instanceof stdClass && ( $webhook->url ?? null ) === $url && is_string( $webhook->id ?? null ) && '' !== $webhook->id ) {
					return $webhook->id;
				}
			}

			throw $exception;
		}

		$id = null !== $json && is_string( $json->id ?? null ) ? $json->id : '';
		if ( '' === $id ) {
			throw new RuntimeException( 'PayPal created a webhook without an ID.' );
		}

		return $id;
	}

	/**
	 * {@inheritDoc}
	 *
	 * A subscription PayPal cannot delete stays stored, and the first such failure is thrown after the others were tried.
	 *
	 * @throws RuntimeException When a subscription cannot be deleted.
	 */
	public function unsubscribe_webhooks(): void {
		$remaining = $this->webhook_subscriptions();
		$failure   = null;
		foreach ( $remaining as $app => $id ) {
			try {
				$this->call( $app, 'DELETE', 'v1/notifications/webhooks/' . rawurlencode( $id ), null, array( 204, 404 ) );
				unset( $remaining[ $app ] );
			} catch ( RuntimeException $exception ) {
				$failure = $failure ?? $exception;
			}
		}

		if ( array() === $remaining ) {
			delete_option( Options::WEBHOOKS );
		} else {
			update_option( Options::WEBHOOKS, $remaining );
		}
		if ( null !== $failure ) {
			throw $failure;
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * The verification request is built here from the headers and body handed in, not through the wallet's webhook
	 * endpoint: that endpoint reads the current request's globals, and logs the failed request's Authorization header.
	 * A delivery that is incomplete, or for an app with no subscription, is not verified and sends nothing.
	 *
	 * @param string $app     One of the APP_ constants.
	 * @param array  $headers The request headers.
	 * @param string $body    The raw request body.
	 *
	 * @throws RuntimeException When the verification request fails.
	 */
	public function verify_webhook( string $app, array $headers, string $body ): bool {
		$this->require_app( $app );

		$webhook_id = $this->webhook_subscriptions()[ $app ] ?? '';
		$event      = json_decode( $body );
		if ( '' === $webhook_id || ! $event instanceof stdClass ) {
			return false;
		}

		$received = array();
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$received[ strtolower( $name ) ] = $value;
			}
		}
		$signed = array();
		foreach ( array( 'auth-algo', 'cert-url', 'transmission-id', 'transmission-sig', 'transmission-time' ) as $name ) {
			$signed[ $name ] = $received[ 'paypal-' . $name ] ?? '';
			if ( '' === $signed[ $name ] ) {
				return false;
			}
		}

		list( , $json ) = $this->call(
			$app,
			'POST',
			'v1/notifications/verify-webhook-signature',
			array(
				'transmission_id'   => $signed['transmission-id'],
				'transmission_time' => $signed['transmission-time'],
				'cert_url'          => $signed['cert-url'],
				'auth_algo'         => $signed['auth-algo'],
				'transmission_sig'  => $signed['transmission-sig'],
				'webhook_id'        => $webhook_id,
				'webhook_event'     => $event,
			),
			array( 200 ),
			array(),
			false
		);

		return 'SUCCESS' === ( $json->verification_status ?? '' );
	}

	/**
	 * Send a request through an app and read the answer.
	 *
	 * The call enters the app in the order app context while it runs, so the retry filter, which re-signs after an
	 * authentication failure with the token of the context's app, re-signs with this app's.
	 *
	 * @param string     $app          One of the APP_ constants.
	 * @param string     $method       The HTTP method.
	 * @param string     $path         The path under the API host.
	 * @param array|null $body         The JSON body, or null for none.
	 * @param int[]      $expected     The statuses that are an answer.
	 * @param array      $headers      Headers added to the authorization and content type.
	 * @param bool       $log_body     Whether the request logging may include the body.
	 * @return array{0: int, 1: stdClass|null} The status and the decoded JSON object, if the body is one.
	 *
	 * @throws RuntimeException When the request fails.
	 * @throws PayPalApiException When the status is not an expected one.
	 */
	private function call( string $app, string $method, string $path, ?array $body, array $expected, array $headers = array(), bool $log_body = true ): array {
		$this->require_app( $app );

		$was_entered = $this->context->is_entered();
		$previous    = $this->context->current();
		$this->context->enter( $app );
		$this->is_request_logging_enabled = $log_body;
		$own_retry                        = null;
		try {
			$token = $this->bearer( $app )->bearer()->token();
			$args  = array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				) + $headers,
			);
			if ( null !== $body ) {
				$args['body'] = wp_json_encode( $body );
			}
			// Last on the retry filter, for this call only: whatever other listeners did, the retry is signed by this app.
			$own_retry = function ( $retry_args ) use ( $app, $token ) {
				return $this->sign_retry( $retry_args, $app, $token );
			};
			add_filter( 'ppcp_retry_request_args', $own_retry, PHP_INT_MAX );
			$response = $this->request( trailingslashit( $this->host( $app ) ) . $path, $args );
		} finally {
			if ( null !== $own_retry ) {
				remove_filter( 'ppcp_retry_request_args', $own_retry, PHP_INT_MAX );
			}
			$this->is_request_logging_enabled = true;
			if ( $was_entered ) {
				$this->context->enter( $previous );
			} else {
				$this->context->reset();
			}
		}

		if ( is_wp_error( $response ) ) {
			$this->logger->warning( sprintf( 'The PayPal %1$s request to %2$s failed: %3$s', $method, $path, implode( ' ', $response->get_error_messages() ) ) );
			throw new RuntimeException( 'The PayPal request failed.' );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ) );
		$json    = $decoded instanceof stdClass ? $decoded : null;
		if ( ! in_array( $status, $expected, true ) ) {
			$this->logger->warning( sprintf( 'The PayPal %1$s request to %2$s answered %3$d.', $method, $path, $status ) );
			throw new PayPalApiException( $json, $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Carries the decoded PayPal response object, not text; the message is built in PayPalApiException::__construct().
		}

		return array( $status, $json );
	}

	/**
	 * Sign the retry of one of the transport's calls with a fresh token of the call's app.
	 *
	 * After a takeover the first party's retry listener signs a retry with the merchant's own token, and PayPal answers a
	 * webhook another app owns with a not-found, which would read as deleted. A token another listener already refreshed
	 * for the app is kept; the token that failed is replaced. When no token can be issued, the retry keeps the failed one,
	 * so its answer is still the app's.
	 *
	 * @param mixed  $args         The retry's request arguments.
	 * @param string $app          One of the APP_ constants.
	 * @param string $failed_token The token the failed request carried.
	 * @return mixed The arguments, signed by the app.
	 */
	private function sign_retry( $args, string $app, string $failed_token ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$token = $failed_token;
		try {
			$bearer = $this->bearer( $app );
			$token  = $bearer->bearer()->token();
			if ( $token === $failed_token ) {
				$bearer->forget();
				$token = $bearer->bearer()->token();
			}
		} catch ( RuntimeException $exception ) {
			$this->logger->warning( 'Could not refresh the platform access token for request retry: ' . $exception->getMessage() );
		}

		$args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		return $args;
	}

	/**
	 * The client ID of an app.
	 *
	 * @param string $app One of the APP_ constants.
	 * @return string
	 *
	 * @throws RuntimeException When the app is unknown.
	 */
	private function client_id( string $app ): string {
		$this->require_app( $app );

		return self::APP_PLATFORM === $app ? $this->credentials['platform_client_id'] : $this->credentials['merchant_app_client_id'];
	}

	/**
	 * The client secret of an app.
	 *
	 * @param string $app One of the APP_ constants.
	 * @return string
	 *
	 * @throws RuntimeException When the app is unknown.
	 */
	private function client_secret( string $app ): string {
		$this->require_app( $app );

		return self::APP_PLATFORM === $app ? $this->credentials['platform_client_secret'] : $this->credentials['merchant_app_client_secret'];
	}

	/**
	 * Refuse an app the transport does not know.
	 *
	 * @param string $app The app.
	 *
	 * @throws RuntimeException When the app is unknown.
	 */
	private function require_app( string $app ): void {
		if ( self::APP_PLATFORM !== $app && self::APP_MERCHANT_APP !== $app ) {
			throw new RuntimeException( 'Unknown PayPal wallet platform app.' );
		}
	}
}
