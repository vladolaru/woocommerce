<?php
/**
 * The collecting module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\CaptureReader;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\HeldSettlement;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\RefundLock;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\ForeignEventGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\Guards;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\HeldCaptureCompleted;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\HeldCapturePending;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\HeldCaptureReturned;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Webhook\MerchantOnboardingCompleted;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentCaptureCompleted;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'collecting.options'                      => static function (): Options {
		return new Options();
	},
	// Held orders are an order query, not stored state.
	'collecting.held-orders'                  => static function (): HeldOrders {
		return new HeldOrders();
	},
	'collecting.state'                        => static function ( ContainerInterface $container ): CollectingState {
		return new CollectingState( $container->get( 'collecting.options' ), $container->get( 'collecting.held-orders' ) );
	},
	'collecting.connection-state'             => static function ( ContainerInterface $container ): ConnectionState {
		return new ConnectionState( $container->get( 'collecting.options' ) );
	},
	// The one binding point for the transport: the POC's direct transport where the wp-config.php constants exist, else one that is not ready.
	'collecting.transport'                    => static function ( ContainerInterface $container ): PlatformTransport {
		$transport = DirectPlatformTransport::from_constants( $container->get( 'woocommerce.logger.woocommerce' ), $container->get( 'collecting.order-app-context' ) );

		return $transport->is_ready() ? $transport : new NotReadyTransport( $container->get( 'collecting.state' ) );
	},
	// One per request: the bearer, the host resolver and the code that enters an order share it.
	'collecting.order-app-context'            => static function (): OrderAppContext {
		return new OrderAppContext();
	},
	'collecting.context-bearer'               => static function ( ContainerInterface $container ): ContextBearer {
		return new ContextBearer(
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.state' )
		);
	},
	'collecting.context-host-resolver'        => static function ( ContainerInterface $container ): ContextHostResolver {
		return new ContextHostResolver(
			$container->get( 'settings.connection-state' ),
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.state' )
		);
	},
	'collecting.refund-lock'                  => static function ( ContainerInterface $container ): RefundLock {
		return new RefundLock( $container->get( 'collecting.connection-state' ) );
	},
	'collecting.held-capture'                 => static function ( ContainerInterface $container ): HeldCapture {
		return new HeldCapture( $container->get( 'collecting.state' ), $container->get( 'woocommerce.logger.woocommerce' ) );
	},
	'collecting.capture-reader'               => static function ( ContainerInterface $container ): CaptureReader {
		return new CaptureReader(
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'api.factory.capture' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	// One completion path: the wallet's own PAYMENT.CAPTURE.COMPLETED handler, for the webhooks and the reconcile alike.
	'collecting.held-settlement'              => static function ( ContainerInterface $container ): HeldSettlement {
		return new HeldSettlement(
			$container->get( 'collecting.held-orders' ),
			$container->get( 'collecting.held-capture' ),
			new PaymentCaptureCompleted( $container->get( 'woocommerce.logger.woocommerce' ), $container->get( 'api.endpoint.order' ) ),
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'collecting.webhook.guards'               => static function (): Guards {
		return new Guards();
	},
	'collecting.webhook.foreign-guard'        => static function ( ContainerInterface $container ): ForeignEventGuard {
		return new ForeignEventGuard( $container->get( 'collecting.webhook.guards' ), $container->get( 'woocommerce.logger.woocommerce' ) );
	},
	'collecting.webhook.held-completed'       => static function ( ContainerInterface $container ): HeldCaptureCompleted {
		return new HeldCaptureCompleted(
			$container->get( 'collecting.webhook.guards' ),
			$container->get( 'collecting.held-orders' ),
			$container->get( 'collecting.held-settlement' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'collecting.webhook.held-returned'        => static function ( ContainerInterface $container ): HeldCaptureReturned {
		return new HeldCaptureReturned(
			$container->get( 'collecting.webhook.guards' ),
			$container->get( 'collecting.held-orders' ),
			$container->get( 'collecting.held-settlement' ),
			$container->get( 'collecting.capture-reader' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'collecting.webhook.held-pending'         => static function ( ContainerInterface $container ): HeldCapturePending {
		return new HeldCapturePending(
			$container->get( 'collecting.webhook.guards' ),
			$container->get( 'collecting.held-capture' ),
			$container->get( 'api.factory.capture' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'collecting.webhook.onboarding-completed' => static function ( ContainerInterface $container ): MerchantOnboardingCompleted {
		return new MerchantOnboardingCompleted(
			$container->get( 'collecting.webhook.guards' ),
			$container->get( 'collecting.state' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'collecting.reconciler'                   => static function ( ContainerInterface $container ): Reconciler {
		return new Reconciler(
			$container->get( 'collecting.held-orders' ),
			$container->get( 'collecting.capture-reader' ),
			$container->get( 'collecting.held-settlement' ),
			$container->get( 'collecting.state' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
);
