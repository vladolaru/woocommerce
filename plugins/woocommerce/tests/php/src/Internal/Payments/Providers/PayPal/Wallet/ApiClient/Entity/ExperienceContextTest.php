<?php
/**
 * Tests for the experience context entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CallbackConfig;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The experience context of an order: how PayPal presents the payment to the buyer.
 *
 * @group paypal-wallet
 */
class ExperienceContextTest extends WalletTestCase {

	/**
	 * @testdox Should write every property it is given and leave the context it started from empty.
	 */
	public function test_all_props(): void {
		$empty = new ExperienceContext();

		$result = $empty
			->with_return_url( 'example.com' )
			->with_cancel_url( 'example.com?cancelled' )
			->with_brand_name( 'company' )
			->with_locale( 'de_DE' )
			->with_landing_page( 'NO_PREFERENCE' )
			->with_shipping_preference( 'NO_SHIPPING' )
			->with_user_action( 'CONTINUE' )
			->with_payment_method_preference( 'UNRESTRICTED' )
			->with_contact_preference( 'NO_CONTACT_INFO' )
			->with_order_update_callback_config(
				new CallbackConfig(
					array( CallbackConfig::EVENT_SHIPPING_ADDRESS, CallbackConfig::EVENT_SHIPPING_OPTIONS ),
					'example.com/callback'
				)
			);

		$this->assertEmpty( $empty->to_array() );

		$this->assertSame(
			array(
				'return_url'                   => 'example.com',
				'cancel_url'                   => 'example.com?cancelled',
				'brand_name'                   => 'company',
				'locale'                       => 'de_DE',
				'landing_page'                 => 'NO_PREFERENCE',
				'shipping_preference'          => 'NO_SHIPPING',
				'user_action'                  => 'CONTINUE',
				'payment_method_preference'    => 'UNRESTRICTED',
				'contact_preference'           => 'NO_CONTACT_INFO',
				'order_update_callback_config' => array(
					'callback_events' => array( CallbackConfig::EVENT_SHIPPING_ADDRESS, CallbackConfig::EVENT_SHIPPING_OPTIONS ),
					'callback_url'    => 'example.com/callback',
				),
			),
			$result->to_array()
		);
	}
}
