<?php
/**
 * The webhook module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Exception;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\WebhookFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Endpoint\ResubscribeEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Endpoint\SimulateEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Endpoint\SimulationStateEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\CheckoutOrderApproved;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\CheckoutOrderCompleted;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\CheckoutPaymentApprovalReversed;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentCaptureCompleted;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentCapturePending;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentCaptureRefunded;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentCaptureReversed;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentSaleRefunded;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\VaultPaymentTokenDeleted;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\OwnWebhookResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;

return array(

	'webhook.registrar'                       => static function ( ContainerInterface $container ): WebhookRegistrar {
		$factory      = $container->get( 'api.factory.webhook' );
		$endpoint     = $container->get( 'api.endpoint.webhook' );
		$rest_endpoint = $container->get( 'webhook.endpoint.controller' );
		$last_webhook_storage = $container->get( 'webhook.last-webhook-storage' );
		$logger = $container->get( 'woocommerce.logger.woocommerce' );

		return new WebhookRegistrar(
			$factory,
			$endpoint,
			$rest_endpoint,
			$last_webhook_storage,
			$container->get( 'webhook.status.simulation' ),
			$container->get( 'webhook.orchestration' ),
			$logger,
			$container->get( 'webhook.own-resolver' )
		);
	},
	'webhook.own-resolver'                    => static function ( ContainerInterface $container ): OwnWebhookResolver {
		return new OwnWebhookResolver(
			$container->get( 'webhook.endpoint.controller' )
		);
	},
	'webhook.orchestration'                   => static function ( ContainerInterface $container ): WebhookOrchestrator {
		return new WebhookOrchestrator(
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'webhook.endpoint.controller'             => static function ( ContainerInterface $container ): IncomingWebhookEndpoint {
		$webhook_endpoint = $container->get( 'api.endpoint.webhook' );
		$webhook  = $container->get( 'webhook.current' );
		$handler          = $container->get( 'webhook.endpoint.handler' );
		$logger           = $container->get( 'woocommerce.logger.woocommerce' );

		$verify_request   = ! defined( 'PAYPAL_WEBHOOK_REQUEST_VERIFICATION' ) || PAYPAL_WEBHOOK_REQUEST_VERIFICATION;
		$environment = $container->get( 'settings.environment' );
		// Ensures webhook signature verification always enabled in production.
		if ( ! $verify_request && $environment->is_production() ) {
			$verify_request = true;
		}

		$webhook_event_factory      = $container->get( 'api.factory.webhook-event' );
		$simulation      = $container->get( 'webhook.status.simulation' );
		$last_webhook_storage = $container->get( 'webhook.last-webhook-storage' );

		return new IncomingWebhookEndpoint(
			$webhook_endpoint,
			$webhook,
			$logger,
			$verify_request,
			$webhook_event_factory,
			$simulation,
			$last_webhook_storage,
			...$handler
		);
	},
	'webhook.endpoint.handler'                => static function ( ContainerInterface $container ): array {
		$logger         = $container->get( 'woocommerce.logger.woocommerce' );
		$prefix         = $container->get( 'api.prefix' );
		$order_endpoint = $container->get( 'api.endpoint.order' );
		$authorized_payments_processor = $container->get( 'wcgateway.processor.authorized-payments' );
		$refund_fees_updater = $container->get( 'wcgateway.helper.refund-fees-updater' );

		$handlers = array(
			new CheckoutOrderApproved(
				$logger,
				$order_endpoint,
				$container->get( 'session.handler' ),
				$container->get( 'wcgateway.funding-source.renderer' ),
				$container->get( 'wcgateway.order-processor' )
			),
			new CheckoutOrderCompleted( $logger ),
			new CheckoutPaymentApprovalReversed( $logger ),
			new PaymentCaptureRefunded( $logger, $refund_fees_updater ),
			new PaymentCaptureReversed( $logger ),
			new PaymentCaptureCompleted( $logger, $order_endpoint ),
			new VaultPaymentTokenDeleted( $logger ),
			new PaymentCapturePending( $logger ),
			new PaymentSaleRefunded( $logger, $refund_fees_updater ),
		);

		return $handlers;
	},

	'webhook.current'                         => static function ( ContainerInterface $container ): ?Webhook {
		$data = (array) get_option( WebhookRegistrar::KEY, array() );
		if ( empty( $data ) ) {
			return null;
		}

		$factory = $container->get( 'api.factory.webhook' );
		assert( $factory instanceof WebhookFactory );

		try {
			return $factory->from_array( $data );
		} catch ( Exception $exception ) {
			$logger = $container->get( 'woocommerce.logger.woocommerce' );
			assert( $logger instanceof LoggerInterface );
			$logger->error( 'Failed to parse the stored webhook data: ' . $exception->getMessage() );
			return null;
		}
	},

	'webhook.is-registered'                   => static function ( ContainerInterface $container ): bool {
		return $container->get( 'webhook.current' ) !== null;
	},

	'webhook.status.registered-webhooks-data' => static function ( ContainerInterface $container ): array {
		$empty_placeholder = __( 'No webhooks found.', 'woocommerce' );

		$webhooks = array();
		try {
			$webhooks = $container->get( 'webhook.status.registered-webhooks' );
		} catch ( Exception $exception ) {
			$empty_placeholder = sprintf(
				'<span class="error">%s</span>',
				__( 'Failed to load webhooks.', 'woocommerce' )
			);
		}

		return array(
			'headers'           => array(
				__( 'URL', 'woocommerce' ),
				__( 'Tracked events', 'woocommerce' ),
			),
			'data'              => array_map(
				function ( Webhook $webhook ): array {
					return array(
						esc_html( $webhook->url() ),
						implode(
							',<br/>',
							array_map(
								'esc_html',
								$webhook->humanfriendly_event_names()
							)
						),
					);
				},
				$webhooks
			),
			'empty_placeholder' => $empty_placeholder,
		);
	},

	'webhook.status.simulation'               => static function ( ContainerInterface $container ): WebhookSimulation {
		$webhook_endpoint = $container->get( 'api.endpoint.webhook' );
		$webhook  = $container->get( 'webhook.current' );
		return new WebhookSimulation(
			$webhook_endpoint,
			$webhook,
			'CHECKOUT.ORDER.APPROVED',
			'2.0'
		);
	},

	'webhook.endpoint.resubscribe'            => static function ( ContainerInterface $container ): ResubscribeEndpoint {
		$registrar = $container->get( 'webhook.registrar' );
		$request_data            = $container->get( 'button.request-data' );

		return new ResubscribeEndpoint(
			$registrar,
			$request_data
		);
	},

	'webhook.endpoint.simulate'               => static function ( ContainerInterface $container ): SimulateEndpoint {
		$simulation = $container->get( 'webhook.status.simulation' );
		$request_data = $container->get( 'button.request-data' );

		return new SimulateEndpoint(
			$simulation,
			$request_data
		);
	},
	'webhook.endpoint.simulation-state'       => static function ( ContainerInterface $container ): SimulationStateEndpoint {
		$simulation = $container->get( 'webhook.status.simulation' );

		return new SimulationStateEndpoint(
			$simulation
		);
	},

	'webhook.last-webhook-storage'            => static function ( ContainerInterface $container ): WebhookEventStorage {
		return new WebhookEventStorage( $container->get( 'webhook.last-webhook-storage.key' ) );
	},
	'webhook.last-webhook-storage.key'        => static function ( ContainerInterface $container ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return 'ppcp-last-webhook';
	},

	'webhook.asset_getter'                    => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-webhooks' );
	},
);
