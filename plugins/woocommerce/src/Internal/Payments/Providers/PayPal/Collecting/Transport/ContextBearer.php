<?php
/**
 * ContextBearer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;

/**
 * The wallet's bearer while the platform serves the store: each call is signed with the token of the app it goes through.
 *
 * The app is resolved when a token is asked for, not when the bearer is built, so the wallet's endpoints, which hold one
 * bearer for the request, follow an order context entered after they were built.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class ContextBearer implements Bearer {

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
	 * @param OrderAppContext   $context   The order app context.
	 * @param PlatformTransport $transport The platform transport.
	 * @param CollectingState   $state     The collecting state.
	 */
	public function __construct( OrderAppContext $context, PlatformTransport $transport, CollectingState $state ) {
		$this->context   = $context;
		$this->transport = $transport;
		$this->state     = $state;
	}

	/**
	 * The token of the app the call goes through.
	 *
	 * @since 11.3.0
	 *
	 * @return Token
	 */
	public function bearer(): Token {
		return $this->transport->bearer( $this->context->for_call( $this->transport, $this->state->payee_email() ) )->bearer();
	}
}
