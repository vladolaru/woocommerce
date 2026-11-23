<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Orders\PaymentInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderCardInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentMethodDetailsService;

/**
 * Exercise the card display while the order is transitioning to Failed, as the failure emails do.
 */
trait FailedOrderCardRender {

	/**
	 * Observe the card shown by each render and the number of calls to the payment method service.
	 *
	 * @param int $copies Number of emails to render.
	 * @return object{fetches:int,last4:string[]}
	 */
	private function observe_failed_order_card( int $copies = 1 ): object {
		$observed = (object) array(
			'fetches' => 0,
			'last4'   => array(),
		);
		$service  = $this->createMock( WooPaymentsPaymentMethodDetailsService::class );
		$service->method( 'fetch_payment_method_details' )->willReturnCallback(
			static function () use ( $observed ): array {
				++$observed->fetches;
				return array();
			}
		);
		$card_info = new WooPaymentsOrderCardInfo();
		$card_info->init( $service );
		remove_all_filters( 'wc_order_payment_card_info' );
		$card_info->register();
		add_action(
			'woocommerce_order_status_failed',
			static function ( $order_id ) use ( $observed, $copies ) {
				for ( $i = 0; $i < $copies; ++$i ) {
					$observed->last4[] = PaymentInfo::get_card_info( new \WC_Order( $order_id ) )['last4'];
				}
			}
		);
		return $observed;
	}
}
