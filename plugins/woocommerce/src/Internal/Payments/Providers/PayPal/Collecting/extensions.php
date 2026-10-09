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

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedSettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
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
	// Saved PayPal and Venmo reads as off for every checkout reader; built from the same settings models as the wallet's.
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
			$c->get( 'settings.data.paylater-messaging-settings' )
		);
	},
);
