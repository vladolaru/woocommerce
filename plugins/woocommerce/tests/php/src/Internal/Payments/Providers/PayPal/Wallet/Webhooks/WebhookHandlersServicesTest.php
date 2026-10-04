<?php
/**
 * Tests for the webhook handler list the Webhooks module builds, with a pin of the event types the handlers
 * register at PayPal.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\RefundFeesUpdater;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\RequestHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery\MockInterface;
use RuntimeException;

/**
 * The handler list resolves without the optional modules, holds no PayPal-hosted subscription handler, and registers
 * exactly the event types listed here. `WebhookRegistrar` registers the union of the handlers' event types at PayPal,
 * which is shared contract, so a change to the list must change this test on purpose.
 *
 * @group paypal-wallet
 */
class WebhookHandlersServicesTest extends WalletTestCase {

	/**
	 * The handlers the Webhooks module builds.
	 *
	 * @return RequestHandler[]
	 */
	private function handlers(): array {
		$services = array(
			'woocommerce.logger.woocommerce'          => $this->mock( LoggerInterface::class ),
			'api.prefix'                              => 'WC-',
			'api.endpoint.order'                      => $this->mock( OrderEndpoint::class ),
			'session.handler'                         => $this->mock( SessionHandler::class ),
			'wcgateway.funding-source.renderer'       => $this->mock( FundingSourceRenderer::class ),
			'wcgateway.helper.refund-fees-updater'    => $this->mock( RefundFeesUpdater::class ),
			'wcgateway.order-processor'               => $this->mock( OrderProcessor::class ),
			'wcgateway.processor.authorized-payments' => $this->mock( AuthorizedPaymentsProcessor::class ),
		);

		/**
		 * The container serving the services above.
		 *
		 * @var ContainerInterface&MockInterface $container
		 */
		$container = $this->mock( ContainerInterface::class );

		$container->shouldReceive( 'has' )->andReturnUsing(
			static function ( string $id ) use ( $services ): bool {
				return array_key_exists( $id, $services );
			}
		);
		$container->shouldReceive( 'get' )->andReturnUsing(
			static function ( string $id ) use ( $services ) {
				if ( ! array_key_exists( $id, $services ) ) {
					throw new RuntimeException( "Service $id not found" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}

				return $services[ $id ];
			}
		);

		$module_services = require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/Webhooks/services.php';

		return $module_services['webhook.endpoint.handler']( $container );
	}

	/**
	 * The event types the given handlers register, sorted.
	 *
	 * @param RequestHandler[] $handlers The handlers.
	 * @return string[]
	 */
	private function event_types( array $handlers ): array {
		$types = array();
		foreach ( $handlers as $handler ) {
			$types = array_merge( $types, $handler->event_types() );
		}
		$types = array_values( array_unique( $types ) );
		sort( $types );

		return $types;
	}

	/**
	 * @testdox Should build a handler list without the PayPal Subscriptions sale handler.
	 */
	public function test_webhook_handlers_omit_sale_completed(): void {
		$handlers = $this->handlers();

		$this->assertNotEmpty( $handlers );
		$class_names = array_map( 'get_class', $handlers );
		$this->assertNotContains(
			'Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Handler\PaymentSaleCompleted',
			$class_names
		);
		$this->assertNotContains( 'PAYMENT.SALE.COMPLETED', $this->event_types( $handlers ) );
	}

	/**
	 * @testdox Should register the wallet event types at PayPal.
	 */
	public function test_handlers_register_the_wallet_event_types(): void {
		$types = $this->event_types( $this->handlers() );

		foreach (
			array(
				'CHECKOUT.ORDER.APPROVED',
				'CHECKOUT.ORDER.COMPLETED',
				'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
				'PAYMENT.AUTHORIZATION.VOIDED',
				'PAYMENT.CAPTURE.COMPLETED',
				'PAYMENT.CAPTURE.DENIED',
				'PAYMENT.CAPTURE.PENDING',
				'PAYMENT.CAPTURE.REFUNDED',
				'PAYMENT.CAPTURE.REVERSED',
				'PAYMENT.ORDER.CANCELLED',
				'PAYMENT.SALE.REFUNDED',
				'VAULT.PAYMENT-TOKEN.DELETED',
			) as $expected
		) {
			$this->assertContains( $expected, $types );
		}
	}

	/**
	 * @testdox Should not register the four PayPal-hosted plan event types at PayPal.
	 */
	public function test_handlers_do_not_register_the_hosted_plan_event_types(): void {
		$types = $this->event_types( $this->handlers() );

		foreach (
			array(
				'BILLING.PLAN.PRICING-CHANGE.ACTIVATED',
				'BILLING.PLAN.UPDATED',
				'BILLING.SUBSCRIPTION.CANCELLED',
				'CATALOG.PRODUCT.UPDATED',
			) as $dropped
		) {
			$this->assertNotContains( $dropped, $types );
		}
	}

	/**
	 * Together the two cases above and this one pin the full list, so an event type nobody meant to add or drop shows
	 * up here.
	 *
	 * @testdox Should register no other event types than the twelve wallet ones.
	 */
	public function test_handlers_register_no_other_event_types(): void {
		$types = $this->event_types( $this->handlers() );

		$this->assertCount( 12, $types, 'The wallet event types: ' . implode( ', ', $types ) );
	}
}
