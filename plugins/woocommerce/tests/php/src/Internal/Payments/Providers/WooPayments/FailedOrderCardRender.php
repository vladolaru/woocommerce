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
	 * @param int   $copies Number of emails to render.
	 * @param array $fallback Payment method returned when the failure carried no details.
	 * @return object{fetches:int,last4:string[],order_id:int}
	 */
	private function observe_failed_order_card( int $copies = 1, array $fallback = array() ): object {
		$observed = (object) array(
			'fetches'  => 0,
			'last4'    => array(),
			'order_id' => 0,
		);
		$service  = $this->createMock( WooPaymentsPaymentMethodDetailsService::class );
		$service->method( 'fetch_payment_method_details' )->willReturnCallback(
			static function () use ( $observed, $fallback ): array {
				++$observed->fetches;
				return $fallback;
			}
		);
		$card_info = new WooPaymentsOrderCardInfo();
		$card_info->init( $service );
		remove_all_filters( 'wc_order_payment_card_info' );
		$card_info->register();
		add_action(
			'woocommerce_order_status_failed',
			static function ( $order_id ) use ( $observed, $copies ) {
				$observed->order_id = $order_id;
				$email_order        = new \WC_Order( $order_id );
				$email_order->get_meta_data();
				for ( $i = 0; $i < $copies; ++$i ) {
					$observed->last4[] = PaymentInfo::get_card_info( clone $email_order )['last4'];
				}
			}
		);
		return $observed;
	}

	/**
	 * Both Failed emails start with the same stale order and share the first successful lookup.
	 *
	 * @param callable $fail Action that fails the renewal.
	 */
	private function assert_renewal_emails_share_one_card_lookup( callable $fail ): void {
		$render = $this->observe_failed_order_card(
			2,
			array(
				'type' => 'card',
				'card' => array(
					'brand' => 'visa',
					'last4' => '4242',
				),
			)
		);
		$fail();
		$this->assertSame( array( '4242', '4242' ), $render->last4 );
		$this->assertSame( 1, $render->fetches );
		$this->assertCount( 1, ( new \WC_Order( $render->order_id ) )->get_meta( '_wcpay_payment_method_details', false ) );
	}
}
