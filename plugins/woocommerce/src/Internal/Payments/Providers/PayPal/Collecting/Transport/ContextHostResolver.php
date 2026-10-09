<?php
/**
 * ContextHostResolver class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;

/**
 * The wallet's host resolver while the platform serves the store: the host of the app the call goes through.
 *
 * It extends ApiHostResolver because the wallet's consumers type-hint that class. Until the transport is ready it
 * answers the wallet's own host, since the wallet's callers do not expect host() to throw.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class ContextHostResolver extends ApiHostResolver {

	/**
	 * The order app context.
	 *
	 * @var OrderAppContext
	 */
	private OrderAppContext $context;

	/**
	 * The platform transport.
	 *
	 * @var PlatformTransport
	 */
	private PlatformTransport $transport;

	/**
	 * The collecting state, for the store payee.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState   $connection_state The wallet's connection state, for the host before the transport is ready.
	 * @param OrderAppContext   $context          The order app context.
	 * @param PlatformTransport $transport        The platform transport.
	 * @param CollectingState   $state            The collecting state.
	 */
	public function __construct( ConnectionState $connection_state, OrderAppContext $context, PlatformTransport $transport, CollectingState $state ) {
		parent::__construct( $connection_state );
		$this->context   = $context;
		$this->transport = $transport;
		$this->state     = $state;
	}

	/**
	 * The host of the app the call goes through, or the wallet's own host until the transport is ready.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function host(): string {
		if ( ! $this->transport->is_ready() ) {
			return parent::host();
		}

		return $this->transport->host( $this->context->for_call( $this->transport, $this->state->payee_email() ) );
	}
}
