<?php
/**
 * The collecting module extensions.
 *
 * The collecting module is added only for a store the platform serves; each extension still checks, so a wallet value
 * passes through unchanged for any other store.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\MerchantlessPartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedOnboardingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedSettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedSettingsRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Logging\RedactingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\LockingRefundProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\CollectingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\PlatformServedWebhookRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\PlatformServedWebhookSettingsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication\Bearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnerReferrals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PartnersEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ApiHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\OnboardingRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\SettingsRestEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\WebhookSettingsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\IncomingWebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookRegistrar;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

// The transport, when it serves the store and is ready. Values the wallet reads once, when it builds a service, keep
// the wallet's own until then: a transport that is not ready would throw while the container builds the service.
$ready_transport = static function ( ContainerInterface $c ): ?PlatformTransport {
	if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
		return null;
	}
	$transport = $c->get( 'collecting.transport' );

	return $transport->is_ready() ? $transport : null;
};

// Onboarding goes through the platform app, whatever the order context.
$platform_referrals = static function ( PartnerReferrals $previous, ContainerInterface $c ) use ( $ready_transport ): PartnerReferrals {
	$transport = $ready_transport( $c );
	if ( null === $transport ) {
		return $previous;
	}

	return new PartnerReferrals(
		$transport->host( PlatformTransport::APP_PLATFORM ),
		$transport->bearer( PlatformTransport::APP_PLATFORM ),
		$c->get( 'woocommerce.logger.woocommerce' )
	);
};

return array(
	// The wallet's endpoints log a failed request's arguments, Authorization header included: keep the apps' tokens out.
	'woocommerce.logger.woocommerce'                   => static function ( LoggerInterface $previous, ContainerInterface $c ): LoggerInterface {
		return $c->get( 'collecting.connection-state' )->is_served_by_platform() ? new RedactingLogger( $previous ) : $previous;
	},
	// Connected: SmartButton builds real buttons, EarlyOrderHandler accepts early orders and the gateway reports onboarded.
	'settings.flag.is-connected'                       => static function ( bool $previous, ContainerInterface $c ): bool {
		return $previous || $c->get( 'collecting.connection-state' )->is_served_by_platform();
	},
	'settings.environment'                             => static function ( Environment $previous, ContainerInterface $c ): Environment {
		$state = $c->get( 'collecting.connection-state' );

		return $state->is_served_by_platform() ? new Environment( 'sandbox' === $c->get( 'collecting.state' )->environment() ) : $previous;
	},
	// Registered: WebhookModule's admin_init auto-registration would otherwise write ppcp-webhook with the merchant bearer.
	'webhook.is-registered'                            => static function ( bool $previous, ContainerInterface $c ): bool {
		return $previous || $c->get( 'collecting.connection-state' )->is_served_by_platform();
	},
	// The connected flag above would turn the SDK v6 buttons on; they stay off until the merchant connects first-party.
	'sdk-v6.buttons-available'                         => static function ( bool $previous, ContainerInterface $c ): bool {
		return $previous && ! $c->get( 'collecting.connection-state' )->is_served_by_platform();
	},
	// The Pay Later task points at messaging the platform-served store cannot configure yet.
	'wcgateway.settings.wc-tasks.task-config-services' => static function ( array $previous, ContainerInterface $c ): array {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
			return $previous;
		}

		return array_values( array_diff( $previous, array( 'wcgateway.settings.wc-tasks.pay-later-task-config' ) ) );
	},
	// Saved PayPal and Venmo reads as off for every checkout reader, and the merchant email is the payee's, so the wallet's
	// gateway disabler keeps the gateway available; built from the same settings models as the wallet's.
	'settings.settings-provider'                       => static function ( SettingsProvider $previous, ContainerInterface $c ): SettingsProvider {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
			return $previous;
		}

		return new PlatformServedSettingsProvider(
			$c->get( 'settings.data.general' ),
			$c->get( 'settings.data.onboarding' ),
			$c->get( 'settings.data.payment' ),
			$c->get( 'settings.data.settings' ),
			$c->get( 'settings.data.styling' ),
			$c->get( 'settings.data.paylater-messaging-settings' ),
			$c->get( 'collecting.state' )
		);
	},
	// The settings app's settings report the capture intent while served, and a save keeps the merchant's stored intent.
	'settings.rest.settings'                           => static function ( SettingsRestEndpoint $previous, ContainerInterface $c ): SettingsRestEndpoint {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
			return $previous;
		}

		return new PlatformServedSettingsRestEndpoint( $c->get( 'settings.data.settings' ), $c->get( 'collecting.connection-state' ) );
	},
	// The settings app opens on its settings, not the wallet's onboarding wizard, while served; its saves write nothing.
	'settings.rest.onboarding'                         => static function ( OnboardingRestEndpoint $previous, ContainerInterface $c ): OnboardingRestEndpoint {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
			return $previous;
		}

		return new PlatformServedOnboardingRestEndpoint( $c->get( 'settings.data.onboarding' ), $c->get( 'collecting.connection-state' ) );
	},
	// The bearer resolves the app on every token, so a not-ready transport fails the call, as a failed token does.
	'api.bearer'                                       => static function ( Bearer $previous, ContainerInterface $c ): Bearer {
		return $c->get( 'collecting.connection-state' )->is_served_by_platform() ? $c->get( 'collecting.context-bearer' ) : $previous;
	},
	'api.host-resolver'                                => static function ( ApiHostResolver $previous, ContainerInterface $c ): ApiHostResolver {
		return $c->get( 'collecting.connection-state' )->is_served_by_platform() ? $c->get( 'collecting.context-host-resolver' ) : $previous;
	},
	// Endpoints keep the host they were built with for the request; both apps of an environment share one host.
	'api.host'                                         => static function ( string $previous, ContainerInterface $c ) use ( $ready_transport ): string {
		$transport = $ready_transport( $c );
		if ( null === $transport ) {
			return $previous;
		}

		return $transport->host( PlatformTransport::APP_PLATFORM );
	},
	// The store payee and, once platform connected, its merchant ID, which the onboarded check and the seller status read.
	'api.merchant_email'                               => static function ( string $previous, ContainerInterface $c ): string {
		return $c->get( 'collecting.connection-state' )->is_served_by_platform() ? $c->get( 'collecting.state' )->payee_email() : $previous;
	},
	'api.merchant_id'                                  => static function ( string $previous, ContainerInterface $c ): string {
		return $c->get( 'collecting.connection-state' )->is_served_by_platform() ? $c->get( 'collecting.state' )->merchant_id() : $previous;
	},
	'api.partner_merchant_id'                          => static function ( string $previous, ContainerInterface $c ) use ( $ready_transport ): string {
		$transport = $ready_transport( $c );

		return null === $transport ? $previous : $transport->partner_merchant_id();
	},
	// The SDK loads with the platform's client ID; the order's app only signs the server-side calls.
	'button.client_id'                                 => static function ( string $previous, ContainerInterface $c ) use ( $ready_transport ): string {
		$transport = $ready_transport( $c );

		return null === $transport ? $previous : $transport->sdk_client_id( PlatformTransport::APP_PLATFORM );
	},
	// A collecting store has no merchant ID, so its seller status cannot be looked up: refuse it without a request.
	'api.endpoint.partners'                            => static function ( PartnersEndpoint $previous, ContainerInterface $c ): PartnersEndpoint {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() || '' !== $c->get( 'api.merchant_id' ) ) {
			return $previous;
		}

		return new MerchantlessPartnersEndpoint(
			$c->get( 'api.host' ),
			$c->get( 'api.bearer' ),
			$c->get( 'woocommerce.logger.woocommerce' ),
			$c->get( 'api.factory.sellerstatus' ),
			$c->get( 'api.partner_merchant_id' ),
			'',
			$c->get( 'api.helper.failure-registry' ),
			$c->get( 'api.partners-seller-status-cache' )
		);
	},
	'api.endpoint.partner-referrals'                   => $platform_referrals,
	'api.endpoint.partner-referrals-sandbox'           => $platform_referrals,
	'api.endpoint.partner-referrals-production'        => $platform_referrals,
	// The endpoint stores the last event it received; under a core-owned name while the platform serves the store.
	'webhook.last-webhook-storage.key'                 => static function ( string $previous, ContainerInterface $c ): string {
		return $c->get( 'collecting.connection-state' )->is_served_by_platform() ? 'wc_paypal_wallet_last_webhook' : $previous;
	},
	// The wallet verifies against its own stored webhook, which a store the platform serves does not have: verify with the apps.
	'webhook.endpoint.controller'                      => static function ( IncomingWebhookEndpoint $previous, ContainerInterface $c ): IncomingWebhookEndpoint {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
			return $previous;
		}

		// As the wallet's factory decides it: verification can be turned off by a constant, never in production.
		$verify_request = ! defined( 'PAYPAL_WEBHOOK_REQUEST_VERIFICATION' ) || PAYPAL_WEBHOOK_REQUEST_VERIFICATION || $c->get( 'settings.environment' )->is_production();

		return new CollectingWebhookEndpoint(
			$c->get( 'api.endpoint.webhook' ),
			$c->get( 'webhook.current' ),
			$c->get( 'woocommerce.logger.woocommerce' ),
			$verify_request,
			$c->get( 'api.factory.webhook-event' ),
			$c->get( 'webhook.status.simulation' ),
			$c->get( 'webhook.last-webhook-storage' ),
			$c->get( 'collecting.transport' ),
			$c->get( 'collecting.webhook.guards' ),
			...$c->get( 'webhook.endpoint.handler' )
		);
	},
	// The wallet's registrar would delete the platform's subscriptions and write ppcp-webhook: it does nothing while served.
	'webhook.registrar'                                => static function ( WebhookRegistrar $previous, ContainerInterface $c ): WebhookRegistrar {
		return new PlatformServedWebhookRegistrar(
			$previous,
			$c->get( 'collecting.connection-state' ),
			$c->get( 'api.factory.webhook' ),
			$c->get( 'api.endpoint.webhook' ),
			$c->get( 'webhook.endpoint.controller' ),
			$c->get( 'webhook.last-webhook-storage' ),
			$c->get( 'webhook.status.simulation' ),
			$c->get( 'webhook.orchestration' ),
			$c->get( 'woocommerce.logger.woocommerce' ),
			$c->get( 'webhook.own-resolver' )
		);
	},
	// The settings app's webhook status reports the platform's subscriptions while served, without a request to PayPal.
	'settings.rest.webhooks'                           => static function ( WebhookSettingsEndpoint $previous, ContainerInterface $c ): WebhookSettingsEndpoint {
		return new PlatformServedWebhookSettingsEndpoint(
			$c->get( 'api.endpoint.webhook' ),
			$c->get( 'webhook.registrar' ),
			$c->get( 'webhook.status.simulation' ),
			$c->get( 'webhook.own-resolver' ),
			$c->get( 'collecting.connection-state' ),
			$c->get( 'collecting.transport' ),
			$c->get( 'webhook.endpoint.controller' )
		);
	},
	// The endpoint runs the first responsible handler: another store's events, the held orders and onboarding come first.
	'webhook.endpoint.handler'                         => static function ( array $previous, ContainerInterface $c ): array {
		if ( ! $c->get( 'collecting.connection-state' )->is_served_by_platform() ) {
			return $previous;
		}

		return array_merge(
			array(
				$c->get( 'collecting.webhook.foreign-guard' ),
				$c->get( 'collecting.webhook.held-completed' ),
				$c->get( 'collecting.webhook.held-returned' ),
				$c->get( 'collecting.webhook.onboarding-completed' ),
			),
			$previous
		);
	},
	// Refunds the lock refuses never reach PayPal. The lock reads the order, so a held order stays locked in either served
	// state; after a takeover this module is not booted, the decorator is absent and the extension's own refund runs.
	'wcgateway.processor.refunds'                      => static function ( RefundProcessor $previous, ContainerInterface $c ): RefundProcessor {
		return new LockingRefundProcessor( $previous, $c->get( 'collecting.refund-lock' ) );
	},
);
