<?php
/**
 * CollectingWebhookEndpoint class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookEventFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookEventStorage;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Throwable;
use WP_REST_Request;

/**
 * The wallet's webhook endpoint while the platform serves the store: each delivery is verified with a platform app.
 *
 * The wallet verifies against its own stored webhook, which a store the platform serves does not have. Here the
 * transport verifies against the subscription of the app the delivery came through, and PayPal delivers an event through
 * the subscription of the app that created its resource. So the app is chosen from the event: an event for an order
 * pinned to an app is verified with that app only; onboarding and consent events, and events with no pinned order, try
 * the platform app first and then every other app that holds a subscription. Handling is the wallet's own.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CollectingWebhookEndpoint extends IncomingWebhookEndpoint {

	/**
	 * The headers PayPal signs a delivery with; verification needs all five.
	 */
	private const SIGNATURE_HEADERS = array( 'paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time' );

	/**
	 * The hosts PayPal serves its signing certificates from, live and sandbox.
	 */
	private const CERT_HOSTS = array( 'api.paypal.com', 'api-m.paypal.com', 'api.sandbox.paypal.com', 'api-m.sandbox.paypal.com' );

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $collecting_logger;

	/**
	 * Whether requests are verified.
	 *
	 * @var bool
	 */
	private bool $verifies;

	/**
	 * The webhook event factory.
	 *
	 * @var WebhookEventFactory
	 */
	private WebhookEventFactory $event_factory;

	/**
	 * The simulation handler.
	 *
	 * @var WebhookSimulation
	 */
	private WebhookSimulation $webhook_simulation;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The guards, for the order an event names.
	 *
	 * @var Guards
	 */
	private Guards $guards;

	/**
	 * The verification results by event ID and body hash: the permission callback can run more than once for a request.
	 *
	 * @var array<string, bool>
	 */
	private array $verified = array();

	/**
	 * Constructor.
	 *
	 * @param WebhookEndpoint     $webhook_endpoint           The wallet's webhook API endpoint.
	 * @param Webhook|null        $webhook                    The wallet's stored webhook, unused here.
	 * @param LoggerInterface     $logger                     The logger.
	 * @param bool                $verify_request             Whether requests are verified.
	 * @param WebhookEventFactory $webhook_event_factory      The webhook event factory.
	 * @param WebhookSimulation   $simulation                 The simulation handler.
	 * @param WebhookEventStorage $last_webhook_event_storage The last webhook event storage.
	 * @param PlatformTransport   $transport                  The platform transport.
	 * @param Guards              $guards                     The guards.
	 * @param RequestHandler      ...$handlers                The handlers.
	 */
	public function __construct(
		WebhookEndpoint $webhook_endpoint,
		?Webhook $webhook,
		LoggerInterface $logger,
		bool $verify_request,
		WebhookEventFactory $webhook_event_factory,
		WebhookSimulation $simulation,
		WebhookEventStorage $last_webhook_event_storage,
		PlatformTransport $transport,
		Guards $guards,
		RequestHandler ...$handlers
	) {
		parent::__construct( $webhook_endpoint, $webhook, $logger, $verify_request, $webhook_event_factory, $simulation, $last_webhook_event_storage, ...$handlers );

		$this->collecting_logger  = $logger;
		$this->verifies           = $verify_request;
		$this->event_factory      = $webhook_event_factory;
		$this->webhook_simulation = $simulation;
		$this->transport          = $transport;
		$this->guards             = $guards;
	}

	/**
	 * Verify the request with the platform app it was delivered through.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 *
	 * @return bool
	 */
	public function verify_request( WP_REST_Request $request ): bool {
		$content_type = $request->get_content_type();
		if ( ! isset( $content_type['value'] ) || 'application/json' !== $content_type['value'] ) {
			$this->collecting_logger->error( 'Webhook request rejected: expected application/json content type.' );
			return false;
		}

		// Only the body carries the webhook parameters; never fall back to the query or the URL.
		$request->set_query_params( array() );
		$request->set_url_params( array() );

		if ( ! $this->verifies ) {
			return true;
		}

		try {
			$event = $this->event_factory->from_array( $request->get_params() );
		} catch ( Throwable $throwable ) {
			$this->collecting_logger->error( 'Webhook parsing failed: ' . $throwable->getMessage() );
			return false;
		}

		// Keyed on the body too, so a second request reusing a verified event ID with another body is verified again.
		$cache_key = $event->id() . ':' . sha1( $request->get_body() );
		if ( isset( $this->verified[ $cache_key ] ) ) {
			return $this->verified[ $cache_key ];
		}
		if ( $this->webhook_simulation->is_simulation_event( $event ) ) {
			return true;
		}

		$this->verified[ $cache_key ] = $this->verify_with_candidates( $request );

		return $this->verified[ $cache_key ];
	}

	/**
	 * Try each candidate app in turn; accept the first that verifies the delivery.
	 *
	 * A delivery without PayPal's five signature headers, or with headers that are not PayPal's shape, cannot verify, so it
	 * is refused before any order lookup or call to PayPal: anyone can post to the route.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return bool
	 */
	private function verify_with_candidates( WP_REST_Request $request ): bool {
		$headers = $this->paypal_headers( $request );
		foreach ( self::SIGNATURE_HEADERS as $name ) {
			if ( '' === ( $headers[ $name ] ?? '' ) ) {
				$this->collecting_logger->error( 'Webhook request rejected: a PayPal signature header is missing.' );
				return false;
			}
		}
		if ( ! $this->has_paypal_signature_shape( $headers ) ) {
			$this->collecting_logger->error( 'Webhook request rejected: the PayPal signature headers are not PayPal\'s.' );
			return false;
		}
		$body = $request->get_body();

		foreach ( $this->candidate_apps( $request ) as $app ) {
			try {
				if ( $this->transport->verify_webhook( $app, $headers, $body ) ) {
					return true;
				}
			} catch ( Throwable $throwable ) {
				$this->collecting_logger->warning( sprintf( 'Webhook verification through the %s app failed: %s', $app, $throwable->getMessage() ) );
			}
		}

		$this->collecting_logger->error( 'Webhook verification failed.' );

		return false;
	}

	/**
	 * Whether the signature headers have the shape PayPal gives them: the certificate on one of PayPal's API hosts over
	 * HTTPS, the SHA256withRSA algorithm and a transmission time that parses. A cheap check before any lookup.
	 *
	 * @param array<string, string> $headers The request headers by their PayPal names.
	 * @return bool
	 */
	private function has_paypal_signature_shape( array $headers ): bool {
		$cert = wp_parse_url( $headers['paypal-cert-url'] ?? '' );

		return is_array( $cert )
			&& 'https' === strtolower( (string) ( $cert['scheme'] ?? '' ) )
			&& in_array( strtolower( (string) ( $cert['host'] ?? '' ) ), self::CERT_HOSTS, true )
			&& 'sha256withrsa' === strtolower( $headers['paypal-auth-algo'] ?? '' )
			&& false !== strtotime( $headers['paypal-transmission-time'] ?? '' );
	}

	/**
	 * The apps to verify a delivery with, in order, among those that hold a subscription.
	 *
	 * An event for a pinned order came through its app's subscription, so only that app may verify it. Any other event
	 * tries the platform app first.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return string[]
	 */
	private function candidate_apps( WP_REST_Request $request ): array {
		try {
			$subscribed = array_keys( $this->transport->webhook_subscriptions() );
		} catch ( Throwable $throwable ) {
			$this->collecting_logger->warning( 'Could not read the platform webhook subscriptions: ' . $throwable->getMessage() );
			return array();
		}

		$event_type = $request['event_type'];
		$resource   = $request['resource'];
		if ( ! in_array( $event_type, Guards::ONBOARDING_EVENTS, true ) && is_array( $resource ) ) {
			$order = $this->guards->order_for_event( $resource );
			if ( null !== $order && OrderPin::is_pinned( $order ) ) {
				return array_values( array_intersect( array( OrderPin::app( $order ) ), $subscribed ) );
			}
		}

		return array_values( array_intersect( array( PlatformTransport::APP_PLATFORM, PlatformTransport::APP_MERCHANT_APP ), $subscribed ) );
	}

	/**
	 * The request headers by their PayPal names, one string value each.
	 *
	 * WordPress keys the headers lowercase with underscores and keeps a list of values; PayPal's names use dashes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return array<string, string>
	 */
	private function paypal_headers( WP_REST_Request $request ): array {
		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$value = is_array( $values ) ? reset( $values ) : $values;
			if ( is_string( $value ) ) {
				$headers[ str_replace( '_', '-', (string) $name ) ] = $value;
			}
		}

		return $headers;
	}
}
