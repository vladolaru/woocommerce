<?php
/**
 * NotReadyTransport class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * The transport while the platform credentials are not configured: every call that needs the platform throws.
 *
 * It throws the wallet's own RuntimeException, the one a failed PayPalBearer throws, so the wallet's callers handle it
 * as they handle any failed token. The environment is the collecting state's, which needs no credentials.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class NotReadyTransport implements PlatformTransport {

	/**
	 * The collecting state.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * Constructor.
	 *
	 * @param CollectingState $state The collecting state.
	 */
	public function __construct( CollectingState $state ) {
		$this->state = $state;
	}

	/**
	 * {@inheritDoc}
	 */
	public function environment(): string {
		return $this->state->environment();
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_ready(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $payee_email The payee buyers pay.
	 * @throws RuntimeException Always.
	 */
	public function pick_order_app( string $payee_email ): string {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 * @throws RuntimeException Always.
	 */
	public function sdk_client_id( string $app ): string {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 * @throws RuntimeException Always.
	 */
	public function bearer( string $app ): Bearer {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 * @throws RuntimeException Always.
	 */
	public function host( string $app ): string {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app The app.
	 * @throws RuntimeException Always.
	 */
	public function assertion_header( string $app ): array {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException Always.
	 */
	public function partner_merchant_id(): string {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tracking_id The tracking ID.
	 * @param string $return_url  The return URL.
	 * @param string $email       The payee email.
	 * @throws RuntimeException Always.
	 */
	public function referral_link( string $tracking_id, string $return_url, string $email = '' ): string {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tracking_id The tracking ID.
	 * @throws RuntimeException Always.
	 */
	public function seller_status( string $tracking_id ): SellerStatus {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException Always.
	 */
	public function webhook_subscriptions(): array {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $url The listener URL.
	 * @throws RuntimeException Always.
	 */
	public function subscribe_webhooks( string $url ): array {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws RuntimeException Always.
	 */
	public function unsubscribe_webhooks(): void {
		throw $this->not_configured();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $app     The app.
	 * @param array  $headers The request headers.
	 * @param string $body    The raw request body.
	 * @throws RuntimeException Always.
	 */
	public function verify_webhook( string $app, array $headers, string $body ): bool {
		throw $this->not_configured();
	}

	/**
	 * The exception every call that needs the platform throws.
	 *
	 * @return RuntimeException
	 */
	private function not_configured(): RuntimeException {
		return new RuntimeException( 'The PayPal wallet platform transport is not configured.' );
	}
}
