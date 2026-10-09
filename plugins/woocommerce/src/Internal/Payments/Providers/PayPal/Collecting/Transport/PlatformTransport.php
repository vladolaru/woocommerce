<?php
/**
 * PlatformTransport interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;

/**
 * The calls the platform makes to PayPal for a store it serves, through one of its two apps.
 *
 * The platform app collects for the store and onboards the merchant; the merchant app serves orders for a known payee.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
interface PlatformTransport {

	/**
	 * The platform app.
	 *
	 * @since 11.3.0
	 */
	public const APP_PLATFORM = 'platform';

	/**
	 * The merchant app.
	 *
	 * @since 11.3.0
	 */
	public const APP_MERCHANT_APP = 'merchant_app';

	/**
	 * The PayPal environment the transport talks to.
	 *
	 * @since 11.3.0
	 *
	 * @return string `sandbox` or `production`.
	 */
	public function environment(): string;

	/**
	 * Whether the transport has the credentials it needs.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public function is_ready(): bool;

	/**
	 * The app a new order for a payee goes through.
	 *
	 * @since 11.3.0
	 *
	 * @param string $payee_email The payee buyers pay.
	 * @return string One of the APP_ constants.
	 */
	public function pick_order_app( string $payee_email ): string;

	/**
	 * The client ID the JS SDK loads with for an app.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app One of the APP_ constants.
	 * @return string
	 */
	public function sdk_client_id( string $app ): string;

	/**
	 * The bearer that signs calls through an app.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app One of the APP_ constants.
	 * @return Bearer
	 */
	public function bearer( string $app ): Bearer;

	/**
	 * The API host of an app.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app One of the APP_ constants.
	 * @return string
	 */
	public function host( string $app ): string;

	/**
	 * The PayPal-Auth-Assertion header for calls through an app, or an empty array while no seller is known.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app One of the APP_ constants.
	 * @return array<string, string>
	 */
	public function assertion_header( string $app ): array;

	/**
	 * The platform's partner merchant ID.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function partner_merchant_id(): string;

	/**
	 * The onboarding link for the merchant behind a tracking ID.
	 *
	 * @since 11.3.0
	 *
	 * @param string $tracking_id The tracking ID.
	 * @param string $return_url  Where PayPal sends the merchant back to.
	 * @return string
	 */
	public function referral_link( string $tracking_id, string $return_url ): string;

	/**
	 * The onboarding status of the seller behind a tracking ID.
	 *
	 * @since 11.3.0
	 *
	 * @param string $tracking_id The tracking ID.
	 * @return SellerStatus
	 */
	public function seller_status( string $tracking_id ): SellerStatus;

	/**
	 * The webhook subscriptions the transport holds.
	 *
	 * @since 11.3.0
	 *
	 * @return array<string, string> Webhook IDs by app.
	 */
	public function webhook_subscriptions(): array;

	/**
	 * Subscribe each app that has no subscription yet to a listener URL.
	 *
	 * @since 11.3.0
	 *
	 * @param string $url The listener URL.
	 * @return array<string, string> Webhook IDs by app.
	 */
	public function subscribe_webhooks( string $url ): array;

	/**
	 * Delete the webhook subscriptions the transport holds.
	 *
	 * @since 11.3.0
	 */
	public function unsubscribe_webhooks(): void;

	/**
	 * Whether PayPal signed a webhook delivered to an app's subscription.
	 *
	 * @since 11.3.0
	 *
	 * @param string $app     One of the APP_ constants.
	 * @param array  $headers The request headers.
	 * @param string $body    The raw request body.
	 * @return bool
	 */
	public function verify_webhook( string $app, array $headers, string $body ): bool;
}
